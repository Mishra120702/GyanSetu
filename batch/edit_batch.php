<?php
// Database connection
require_once '../db_connection.php';
require_once 'sync_curriculum.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get batch ID from URL
$batch_id = $_GET['id'] ?? null;

// Fetch batch data if ID is provided
$batch = null;
if ($batch_id) {
    $stmt = $db->prepare("SELECT b.*, t.name as mentor_name 
                         FROM batches b
                         LEFT JOIN trainers t ON b.batch_mentor_id = t.id
                         WHERE b.batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch all courses
    $all_courses_raw = $db->query("SELECT * FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch assigned courses
    $stmt = $db->prepare("SELECT course_id FROM batch_courses WHERE batch_id = ? ORDER BY id ASC");
    $stmt->execute([$batch_id]);
    $assigned_courses_raw = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $assigned_courses = array_map('intval', $assigned_courses_raw);

    // Sort courses: assigned first (in their assigned order), then unassigned
    $assigned_courses_details = [];
    $unassigned_courses = [];
    
    foreach ($all_courses_raw as $course) {
        $index = array_search($course['id'], $assigned_courses);
        if ($index !== false) {
            $assigned_courses_details[$index] = $course;
        } else {
            $unassigned_courses[] = $course;
        }
    }
    ksort($assigned_courses_details);
    $all_courses = array_merge($assigned_courses_details, $unassigned_courses);
}

// Handle form submission for updating batch
if (isset($_POST['update_batch'])) {
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // Get current batch status before update
        $stmt = $db->prepare("SELECT status FROM batches WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $old_status = $stmt->fetchColumn();
        $new_status = $_POST['status'];
        
        // Handle thumbnail upload
        $thumbnail_path = $batch['thumbnail_path']; // Keep existing by default
        
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == 0) {
            $upload_dir = '../uploads/batch_thumbnails/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['thumbnail']['name']);
            $target_file = $upload_dir . $file_name;
            
            // Check file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file_type = mime_content_type($_FILES['thumbnail']['tmp_name']);
            
            if (in_array($file_type, $allowed_types)) {
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $target_file)) {
                    // Delete old thumbnail if it exists and is not default
                    if ($thumbnail_path && file_exists('../' . $thumbnail_path) && 
                        !str_contains($thumbnail_path, 'default_thumbnail')) {
                        unlink('../' . $thumbnail_path);
                    }
                    $thumbnail_path = 'uploads/batch_thumbnails/' . $file_name;
                }
            }
        }
        
        // Prepare the update statement for batch
        $stmt = $db->prepare("UPDATE batches SET 
            batch_name = ?, 
            start_date = ?, 
            end_date = ?, 
            time_slot = ?, 
            platform = ?, 
            meeting_link = ?, 
            max_students = ?, 
            current_enrollment = ?, 
            academic_year = ?,
            mode = ?, 
            status = ?,
            thumbnail_path = ?,
            course_description = ?
            WHERE batch_id = ?");
        
        // Execute batch update
        $success = $stmt->execute([
            $_POST['batch_name'],
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['time_slot'],
            $_POST['platform'],
            $_POST['meeting_link'],
            $_POST['max_students'],
            $_POST['current_enrollment'],
            $_POST['academic_year'],
            $_POST['mode'],
            $new_status,
            $thumbnail_path,
            $_POST['course_description'],
            $batch_id
        ]);
        
        if (!$success) {
            throw new Exception("Error updating batch details.");
        }
        
        // Check if batch status changed to "completed"
        if ($old_status !== 'completed' && $new_status === 'completed') {
            $stmt = $db->prepare("SELECT student_id FROM students 
                                 WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) 
                                 AND current_status = 'active'");
            $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
            $active_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($active_students) > 0) {
                $placeholders = implode(',', array_fill(0, count($active_students), '?'));
                $stmt = $db->prepare("UPDATE students SET current_status = 'completed' WHERE student_id IN ($placeholders)");
                $stmt->execute($active_students);
                
                $logStmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by, processed_at) VALUES (?, 'completed', 'Batch marked as completed', ?, NOW())");
                foreach ($active_students as $student_id) {
                    $logStmt->execute([$student_id, $_SESSION['user_id']]);
                }
                $_SESSION['success_message'] = "Batch updated successfully! " . count($active_students) . " students marked as completed.";
            } else {
                $_SESSION['success_message'] = "Batch updated successfully! No active students to mark as completed.";
            }
        } elseif ($old_status === 'completed' && $new_status !== 'completed') {
            $stmt = $db->prepare("SELECT student_id FROM students 
                                 WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) 
                                 AND current_status = 'completed'");
            $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
            $completed_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($completed_students) > 0) {
                $placeholders = implode(',', array_fill(0, count($completed_students), '?'));
                $stmt = $db->prepare("UPDATE students SET current_status = 'active' WHERE student_id IN ($placeholders)");
                $stmt->execute($completed_students);
                
                $logStmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by, processed_at) VALUES (?, 'reactivated', 'Batch status changed from completed', ?, NOW())");
                foreach ($completed_students as $student_id) {
                    $logStmt->execute([$student_id, $_SESSION['user_id']]);
                }
                $_SESSION['success_message'] = "Batch updated successfully! " . count($completed_students) . " students reverted to active status.";
            } else {
                $_SESSION['success_message'] = "Batch updated successfully!";
            }
        } else {
            $_SESSION['success_message'] = "Batch updated successfully!";
        }
        
        // Handle course updates
        if (isset($_POST['courses'])) {
            $selected_courses = is_array($_POST['courses']) ? array_unique($_POST['courses']) : [];
            
            $delStmt = $db->prepare("DELETE FROM batch_courses WHERE batch_id = ?");
            $delStmt->execute([$batch_id]);
            
            if (!empty($selected_courses)) {
                $insStmt = $db->prepare("INSERT INTO batch_courses (batch_id, course_id) VALUES (?, ?)");
                foreach ($selected_courses as $course_id) {
                    $insStmt->execute([$batch_id, $course_id]);
                    sync_course_curriculum_to_batch($db, $batch_id, $course_id);
                }
                logSystemActivity($db, $_SESSION['user_id'], 'COURSE_ASSIGNED', "Courses updated for batch $batch_id: " . implode(', ', $selected_courses));
            }
        } else {
            $delStmt = $db->prepare("DELETE FROM batch_courses WHERE batch_id = ?");
            $delStmt->execute([$batch_id]);
            logSystemActivity($db, $_SESSION['user_id'], 'COURSE_ASSIGNED', "All courses removed from batch $batch_id.");
        }
        
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error_message'] = "Error updating batch: " . $e->getMessage();
    }
    
    header("Location: batch_list.php");
    exit();
}

// If batch not found, redirect to list
if (!$batch) {
    header("Location: batch_list.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Batch - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <style>
        /* =========================================================
           DESIGN TOKENS
           ========================================================= */
        :root {
            --navy-900:   #1B3C53;
            --navy-700:   #234C6A;
            --navy-500:   #456882;
            --sand-300:   #D2C1B6;
            --surface:    #F5F7FA;

            --navy-900-10: rgba(27,60,83,0.10);
            --navy-900-06: rgba(27,60,83,0.06);
            --navy-700-15: rgba(35,76,106,0.15);
            --sand-300-40: rgba(210,193,182,0.40);

            --clr-green:    #1a7f5a;
            --clr-green-bg: rgba(26,127,90,0.09);
            --clr-purple:   #4c3575;
            --clr-purple-bg:rgba(76,53,117,0.08);
            --clr-amber:    #b45309;
            --clr-amber-bg: rgba(180,83,9,0.08);
            --clr-crimson:  #991b1b;
            --clr-crimson-bg:rgba(153,27,27,0.08);

            --radius-sm:   8px;
            --radius-md:   12px;
            --radius-lg:   16px;
            --radius-xl:   20px;
            --radius-pill: 999px;

            --shadow-card:       0 2px 14px rgba(27,60,83,0.09), 0 1px 4px rgba(27,60,83,0.05);
            --shadow-card-hover: 0 10px 28px rgba(27,60,83,0.14), 0 3px 10px rgba(27,60,83,0.07);
            --shadow-btn:        0 2px 8px rgba(35,76,106,0.22);
            --shadow-btn-hover:  0 5px 16px rgba(35,76,106,0.30);

            --font-body:    'Inter', sans-serif;
            --font-display: 'Plus Jakarta Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--surface);
            font-family: var(--font-body);
            color: var(--navy-900);
            min-height: 100vh;
        }

        /* =========================================================
           LAYOUT (sidebar offset)
           ========================================================= */
        .edit-main {
            margin-left: 0;
            min-height: 100vh;
        }

        @media (min-width: 768px) { .edit-main { margin-left: 256px; } }

        /* =========================================================
           STICKY PAGE HEADER
           ========================================================= */
        .page-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: var(--navy-900);
            padding: 16px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 2px 12px rgba(27,60,83,0.22);
        }

        .page-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-header-title {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 1.1rem;
            color: #fff;
            letter-spacing: -0.2px;
        }

        .page-header-sub {
            font-size: 0.75rem;
            color: rgba(210,193,182,0.80);
            margin-top: 2px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 0.8125rem;
            font-weight: 500;
            color: rgba(210,193,182,0.85);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .back-link:hover { color: #fff; }

        /* =========================================================
           CONTENT AREA
           ========================================================= */
        .content-wrap {
            padding: 28px 24px 56px;
            max-width: 1080px;
            margin: 0 auto;
            animation: fadeInUp 0.4s ease both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* =========================================================
           NOTIFICATIONS
           ========================================================= */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }

        .alert-success {
            background: var(--clr-green-bg);
            color: var(--clr-green);
            border-color: rgba(26,127,90,0.20);
        }

        .alert-error {
            background: var(--clr-crimson-bg);
            color: var(--clr-crimson);
            border-color: rgba(153,27,27,0.20);
        }

        /* =========================================================
           FORM CARD
           ========================================================= */
        .form-card {
            background: #fff;
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            padding: 28px;
            margin-bottom: 24px;
            transition: box-shadow 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .form-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 3px;
            background: linear-gradient(90deg, var(--navy-900), var(--navy-500));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }

        .form-card:hover { box-shadow: var(--shadow-card-hover); }
        .form-card:hover::after { transform: scaleX(1); }

        /* =========================================================
           SECTION TITLES
           ========================================================= */
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: var(--font-display);
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 22px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--sand-300-40);
        }

        .section-title::before {
            content: '';
            display: inline-block;
            width: 4px; height: 16px;
            background: var(--navy-700);
            border-radius: var(--radius-pill);
            flex-shrink: 0;
        }

        /* =========================================================
           GRID LAYOUTS
           ========================================================= */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        @media (min-width: 640px) { .grid-2 { grid-template-columns: 1fr 1fr; } }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        @media (min-width: 640px) { .grid-3 { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 900px) { .grid-3 { grid-template-columns: 1fr 1fr 1fr; } }

        /* =========================================================
           FORM FIELDS
           ========================================================= */
        .form-group { display: flex; flex-direction: column; gap: 6px; }

        label, .field-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        label i, .field-label i { font-size: 0.75rem; }

        input[type="text"],
        input[type="number"],
        input[type="url"],
        input[type="date"],
        input[type="file"],
        select,
        textarea {
            width: 100%;
            background: var(--surface);
            border: 1.5px solid var(--sand-300);
            border-radius: var(--radius-md);
            padding: 10px 14px;
            font-family: var(--font-body);
            font-size: 0.875rem;
            color: var(--navy-900);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--navy-700);
            box-shadow: 0 0 0 3px var(--navy-700-15);
            background: #fff;
        }

        input[readonly] {
            background: var(--navy-900-06);
            color: var(--navy-500);
            cursor: not-allowed;
        }

        input::placeholder, textarea::placeholder { color: var(--navy-500); opacity: 0.6; }

        select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23456882' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
        }

        textarea { resize: vertical; min-height: 90px; }

        .field-hint {
            font-size: 0.7rem;
            color: var(--navy-500);
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 2px;
        }

        /* =========================================================
           COURSES LIST
           ========================================================= */
        .courses-panel {
            background: var(--surface);
            border: 1.5px solid var(--sand-300);
            border-radius: var(--radius-md);
            max-height: 240px;
            overflow-y: auto;
            padding: 10px;
        }

        .courses-panel:focus-within {
            border-color: var(--navy-700);
            box-shadow: 0 0 0 3px var(--navy-700-15);
        }

        /* Custom scrollbar */
        .courses-panel::-webkit-scrollbar { width: 6px; }
        .courses-panel::-webkit-scrollbar-track { background: transparent; }
        .courses-panel::-webkit-scrollbar-thumb {
            background: var(--sand-300);
            border-radius: var(--radius-pill);
        }
        .courses-panel::-webkit-scrollbar-thumb:hover { background: var(--navy-500); }

        .course-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            background: #fff;
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-sm);
            margin-bottom: 7px;
            cursor: move;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .course-row:last-child { margin-bottom: 0; }
        .course-row:hover { background: var(--navy-900-06); border-color: var(--sand-300); }

        .course-row .drag-handle {
            color: var(--sand-300);
            font-size: 0.8rem;
            flex-shrink: 0;
            cursor: grab;
        }

        .course-row input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--navy-700);
            cursor: pointer;
            flex-shrink: 0;
            border: none;
            box-shadow: none;
            padding: 0;
            margin: 0;
            background: none;
        }

        .course-row label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--navy-900);
            text-transform: none;
            letter-spacing: 0;
            cursor: pointer;
            flex: 1;
        }

        /* =========================================================
           MODE RADIO BUTTONS
           ========================================================= */
        .radio-group {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: var(--surface);
            border: 1.5px solid var(--sand-300);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.875rem;
            color: var(--navy-700);
            font-weight: 500;
        }

        .radio-option:has(input:checked) {
            background: var(--navy-900-10);
            border-color: var(--navy-700);
            color: var(--navy-900);
            font-weight: 600;
        }

        .radio-option input[type="radio"] {
            width: auto;
            height: auto;
            accent-color: var(--navy-700);
            padding: 0; margin: 0;
            background: none;
            border: none;
            box-shadow: none;
        }

        /* =========================================================
           ONLINE FIELDS PANEL
           ========================================================= */
        .online-panel {
            background: var(--navy-900-06);
            border: 1.5px solid var(--navy-900-10);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 24px;
        }

        .online-panel-title {
            font-family: var(--font-display);
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--navy-700);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* =========================================================
           THUMBNAIL
           ========================================================= */
        .thumbnail-wrap {
            display: flex;
            flex-direction: column;
            gap: 16px;
            align-items: flex-start;
        }

        @media (min-width: 640px) { .thumbnail-wrap { flex-direction: row; align-items: center; } }

        .thumbnail-img-wrap { position: relative; flex-shrink: 0; }

        .thumbnail-preview {
            width: 180px;
            height: 130px;
            border-radius: var(--radius-md);
            object-fit: cover;
            border: 2px solid var(--sand-300);
            transition: border-color 0.2s ease, transform 0.2s ease;
            display: block;
        }

        .thumbnail-preview:hover {
            border-color: var(--navy-700);
            transform: scale(1.03);
        }

        .thumbnail-ext-link {
            position: absolute;
            bottom: 8px; right: 8px;
            width: 26px; height: 26px;
            background: var(--navy-700);
            color: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.65rem;
            text-decoration: none;
            transition: background 0.2s ease;
        }

        .thumbnail-ext-link:hover { background: var(--navy-900); }

        .thumbnail-controls { flex: 1; display: flex; flex-direction: column; gap: 10px; }

        .checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.84rem;
            color: var(--navy-700);
            font-weight: 500;
            cursor: pointer;
            text-transform: none;
            letter-spacing: 0;
        }

        .checkbox-label input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--clr-crimson);
            cursor: pointer;
            padding: 0; margin: 0;
            background: none; border: none; box-shadow: none;
        }

        /* =========================================================
           STATUS WARNING
           ========================================================= */
        .status-warning {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            background: var(--clr-amber-bg);
            border: 1px solid rgba(180,83,9,0.18);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 0.78rem;
            color: var(--clr-amber);
            margin-top: 8px;
        }

        /* =========================================================
           FORM ACTIONS
           ========================================================= */
        .form-divider {
            height: 1px;
            background: var(--sand-300-40);
            margin: 28px 0 22px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: var(--font-body);
            font-size: 0.8375rem;
            font-weight: 600;
            border-radius: var(--radius-md);
            padding: 10px 22px;
            cursor: pointer;
            transition: all 0.22s ease;
            text-decoration: none;
            border: none;
            line-height: 1.4;
        }

        .btn:hover  { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        .btn-cancel {
            background: transparent;
            color: var(--navy-700);
            border: 1.5px solid var(--sand-300);
        }

        .btn-cancel:hover {
            background: var(--navy-900-06);
            border-color: var(--navy-500);
            color: var(--navy-900);
        }

        .btn-save {
            background: var(--navy-900);
            color: #fff;
            box-shadow: var(--shadow-btn);
        }

        .btn-save:hover {
            background: var(--navy-700);
            box-shadow: var(--shadow-btn-hover);
        }

        /* =========================================================
           BATCH INFO CARDS (bottom)
           ========================================================= */
        .info-card {
            background: #fff;
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            padding: 24px 28px;
        }

        .info-card-title {
            font-family: var(--font-display);
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--navy-900);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-stat-panel {
            padding: 18px;
            border-radius: var(--radius-lg);
            border: 1px solid transparent;
        }

        .info-stat-panel.navy {
            background: var(--navy-900-06);
            border-color: var(--navy-900-10);
        }

        .info-stat-panel.green {
            background: var(--clr-green-bg);
            border-color: rgba(26,127,90,0.15);
        }

        .info-stat-panel.purple {
            background: var(--clr-purple-bg);
            border-color: rgba(76,53,117,0.12);
        }

        .stat-panel-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .info-stat-panel.navy  .stat-panel-title { color: var(--navy-700); }
        .info-stat-panel.green .stat-panel-title { color: var(--clr-green); }
        .info-stat-panel.purple .stat-panel-title { color: var(--clr-purple); }

        .stat-big-number {
            font-family: var(--font-display);
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }

        .info-stat-panel.navy   .stat-big-number { color: var(--navy-900); }
        .info-stat-panel.green  .stat-big-number { color: var(--clr-green); }
        .info-stat-panel.purple .stat-big-number { color: var(--clr-purple); }

        .stat-sub {
            font-size: 0.78rem;
            color: var(--navy-500);
            margin-top: 4px;
        }

        /* enrollment progress bar */
        .progress-track {
            height: 6px;
            background: var(--navy-900-10);
            border-radius: var(--radius-pill);
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--clr-purple), var(--navy-500));
            border-radius: var(--radius-pill);
            transition: width 1s ease;
        }

        .stat-seats {
            font-size: 0.78rem;
            color: var(--navy-500);
            margin-top: 6px;
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="edit-main">

        <!-- Sticky Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <div>
                    <div class="page-header-title">
                        <i class="fas fa-edit" style="opacity:.8;margin-right:8px;"></i>Edit Batch
                    </div>
                    <div class="page-header-sub">
                        <?= htmlspecialchars($batch['batch_id']) ?>
                        <?php if (!empty($batch['batch_name'])): ?>&mdash; <?= htmlspecialchars($batch['batch_name']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="batch_list.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <!-- Content -->
        <div class="content-wrap">

            <!-- Notifications -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle fa-lg"></i>
                    <?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle fa-lg"></i>
                    <?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- ── Main Form Card ── -->
            <div class="form-card">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="update_batch" value="1">

                    <!-- Batch ID + Name -->
                    <div class="grid-2">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Batch ID</label>
                            <input type="text" value="<?= htmlspecialchars($batch['batch_id']) ?>" readonly>
                            <span class="field-hint"><i class="fas fa-lock"></i> Batch ID cannot be changed</span>
                        </div>
                        <div class="form-group">
                            <label for="batch_name"><i class="fas fa-book"></i> Batch Name <span style="color:#991b1b;">*</span></label>
                            <input type="text" id="batch_name" name="batch_name"
                                   value="<?= htmlspecialchars($batch['batch_name']) ?>"
                                   placeholder="e.g. Python Batch 2025" required>
                        </div>
                    </div>

                    <!-- Courses Selection -->
                    <div class="form-group" style="margin-bottom:24px;">
                        <label><i class="fas fa-layer-group"></i> Select Courses</label>
                        <div id="courses-list" class="courses-panel">
                            <?php if (isset($all_courses) && count($all_courses) > 0): ?>
                                <?php foreach ($all_courses as $course): ?>
                                    <?php $is_checked = in_array($course['id'], $assigned_courses) ? 'checked' : ''; ?>
                                    <div class="course-row cursor-move">
                                        <i class="fas fa-grip-vertical drag-handle"></i>
                                        <input type="checkbox"
                                               id="course_<?= $course['id'] ?>"
                                               name="courses[]"
                                               value="<?= $course['id'] ?>"
                                               <?= $is_checked ?>>
                                        <label for="course_<?= $course['id'] ?>">
                                            <?= htmlspecialchars($course['name']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="font-size:.875rem;color:var(--navy-500);padding:8px;">No courses available.</p>
                            <?php endif; ?>
                        </div>
                        <span class="field-hint">
                            <i class="fas fa-info-circle"></i>
                            Check all courses for this batch. <strong>Drag rows to set display order.</strong>
                        </span>
                    </div>

                    <!-- Course Description -->
                    <div class="form-group" style="margin-bottom:24px;">
                        <label for="course_description"><i class="fas fa-align-left"></i> Course Description</label>
                        <textarea id="course_description" name="course_description"
                                  placeholder="Enter a brief description of the course..."><?= htmlspecialchars($batch['course_description'] ?? '') ?></textarea>
                    </div>

                    <!-- Start + End Date -->
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="start_date"><i class="fas fa-calendar-plus"></i> Start Date <span style="color:#991b1b;">*</span></label>
                            <input type="date" id="start_date" name="start_date"
                                   value="<?= htmlspecialchars($batch['start_date']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="end_date"><i class="fas fa-calendar-minus"></i> End Date <span style="color:#991b1b;">*</span></label>
                            <input type="date" id="end_date" name="end_date"
                                   value="<?= htmlspecialchars($batch['end_date']) ?>" required>
                        </div>
                    </div>

                    <!-- Time Slot + Max Students -->
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="time_slot"><i class="far fa-clock"></i> Time Slot</label>
                            <input type="text" id="time_slot" name="time_slot"
                                   value="<?= htmlspecialchars($batch['time_slot']) ?>"
                                   placeholder="e.g. Mon–Fri 10:00 AM – 12:00 PM">
                        </div>
                        <div class="form-group">
                            <label for="max_students"><i class="fas fa-users"></i> Max Students <span style="color:#991b1b;">*</span></label>
                            <input type="number" id="max_students" name="max_students" min="1"
                                   value="<?= htmlspecialchars($batch['max_students']) ?>" required>
                        </div>
                    </div>

                    <!-- Current Enrollment + Academic Year -->
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="current_enrollment"><i class="fas fa-user-check"></i> Current Enrollment</label>
                            <input type="number" id="current_enrollment" name="current_enrollment" min="0"
                                   value="<?= htmlspecialchars($batch['current_enrollment']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="academic_year"><i class="fas fa-graduation-cap"></i> Academic Year</label>
                            <input type="text" id="academic_year" name="academic_year"
                                   value="<?= htmlspecialchars($batch['academic_year']) ?>"
                                   placeholder="e.g. 2024-25">
                        </div>
                    </div>

                    <!-- Thumbnail Upload -->
                    <div class="form-group" style="margin-bottom:24px;">
                        <label><i class="fas fa-image"></i> Batch Thumbnail</label>
                        <div class="thumbnail-wrap">
                            <div class="thumbnail-img-wrap">
                                <img id="thumbnailPreview"
                                     src="<?= !empty($batch['thumbnail_path']) ? '../' . htmlspecialchars($batch['thumbnail_path']) : '../uploads/batch_thumbnails/default_batch.svg' ?>"
                                     alt="Thumbnail Preview"
                                     class="thumbnail-preview">
                                <?php if (!empty($batch['thumbnail_path'])): ?>
                                    <a href="../<?= htmlspecialchars($batch['thumbnail_path']) ?>"
                                       target="_blank" class="thumbnail-ext-link">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="thumbnail-controls">
                                <input type="file" id="thumbnail" name="thumbnail"
                                       accept="image/*"
                                       onchange="previewThumbnail(this)">
                                <span class="field-hint">
                                    <i class="fas fa-info-circle"></i> Max 2 MB &mdash; JPG, PNG, GIF, WebP
                                </span>
                                <?php if (!empty($batch['thumbnail_path'])): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="remove_thumbnail" value="1">
                                        Remove current thumbnail
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Mode Selection -->
                    <div class="form-group" style="margin-bottom:24px;">
                        <label><i class="fas fa-laptop-house"></i> Mode <span style="color:#991b1b;">*</span></label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="mode" value="online"
                                       <?= $batch['mode'] === 'online' ? 'checked' : '' ?>>
                                <i class="fas fa-wifi"></i> Online
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="mode" value="offline"
                                       <?= $batch['mode'] === 'offline' ? 'checked' : '' ?>>
                                <i class="fas fa-building"></i> Offline
                            </label>
                        </div>
                    </div>

                    <!-- Online Fields -->
                    <div id="onlineFields" style="<?= $batch['mode'] === 'online' ? '' : 'display:none;' ?>">
                        <div class="online-panel" style="margin-bottom:24px;">
                            <div class="online-panel-title">
                                <i class="fas fa-link"></i> Online Class Details
                            </div>
                            <div class="grid-2" style="margin-bottom:0;">
                                <div class="form-group">
                                    <label for="platform"><i class="fas fa-desktop"></i> Platform</label>
                                    <select id="platform" name="platform">
                                        <option value="">Select Platform</option>
                                        <option value="Google Meet"      <?= $batch['platform'] === 'Google Meet'      ? 'selected' : '' ?>>Google Meet</option>
                                        <option value="Zoom"             <?= $batch['platform'] === 'Zoom'             ? 'selected' : '' ?>>Zoom</option>
                                        <option value="Microsoft Teams"  <?= $batch['platform'] === 'Microsoft Teams'  ? 'selected' : '' ?>>Microsoft Teams</option>
                                        <option value="Other"            <?= $batch['platform'] === 'Other'            ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="meeting_link"><i class="fas fa-external-link-alt"></i> Meeting Link</label>
                                    <input type="url" id="meeting_link" name="meeting_link"
                                           value="<?= htmlspecialchars($batch['meeting_link']) ?>"
                                           placeholder="https://meet.google.com/abc-xyz">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="form-group" style="margin-bottom:8px;">
                        <label for="status"><i class="fas fa-info-circle"></i> Status <span style="color:#991b1b;">*</span></label>
                        <select id="status" name="status" required>
                            <option value="upcoming"  <?= $batch['status'] === 'upcoming'  ? 'selected' : '' ?>>Upcoming</option>
                            <option value="ongoing"   <?= $batch['status'] === 'ongoing'   ? 'selected' : '' ?>>Ongoing</option>
                            <option value="completed" <?= $batch['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $batch['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="status-warning">
                        <i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i>
                        Changing status to <strong>"Completed"</strong> will mark all active students in this batch as Completed.
                    </div>

                    <!-- Actions -->
                    <div class="form-divider"></div>
                    <div class="form-actions">
                        <a href="batch_list.php" class="btn btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-save">
                            <i class="fas fa-save"></i> Update Batch
                        </button>
                    </div>

                </form>
            </div><!-- /.form-card -->

            <!-- ── Batch Info Summary ── -->
            <?php
            $counts = $db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM students WHERE batch_name   = ? AND current_status = 'active') as batch_name,
                    (SELECT COUNT(*) FROM students WHERE batch_name_2 = ? AND current_status = 'active') as batch_name_2,
                    (SELECT COUNT(*) FROM students WHERE batch_name_3 = ? AND current_status = 'active') as batch_name_3,
                    (SELECT COUNT(*) FROM students WHERE batch_name_4 = ? AND current_status = 'active') as batch_name_4
            ");
            $counts->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
            $student_counts = $counts->fetch(PDO::FETCH_ASSOC);
            $total_active = array_sum($student_counts);
            $enrollmentPercent = $batch['max_students'] > 0
                ? ($total_active / $batch['max_students']) * 100 : 0;
            ?>
            

        </div><!-- /.content-wrap -->
    </div><!-- /.edit-main -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize SortableJS for courses
            const coursesList = document.getElementById('courses-list');
            if (coursesList) {
                new Sortable(coursesList, {
                    animation: 150,
                    ghostClass: 'bg-blue-50',
                    handle: '.cursor-move'
                });
            }

            // Show/hide online fields based on mode selection
            $('input[name="mode"]').change(function() {
                if ($(this).val() === 'online') {
                    $('#onlineFields').slideDown();
                } else {
                    $('#onlineFields').slideUp();
                }
            });

            // Form validation
            $('form').on('submit', function(e) {
                let valid = true;
                
                const startDate = new Date($('#start_date').val());
                const endDate = new Date($('#end_date').val());
                
                if (endDate <= startDate) {
                    alert('⚠️ End date must be after start date');
                    valid = false;
                }

                const currentEnrollment = parseInt($('#current_enrollment').val()) || 0;
                const maxStudents = parseInt($('#max_students').val()) || 0;
                
                if (currentEnrollment > maxStudents) {
                    alert('⚠️ Current enrollment cannot exceed maximum students');
                    valid = false;
                }
                
                if (maxStudents <= 0) {
                    alert('⚠️ Maximum students must be greater than 0');
                    valid = false;
                }
                
                if (!valid) {
                    e.preventDefault();
                } else {
                    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i> Updating...');
                    $('button[type="submit"]').prop('disabled', true);
                }
            });
            
            // Remove thumbnail checkbox
            $('input[name="remove_thumbnail"]').change(function() {
                if ($(this).is(':checked')) {
                    $('#thumbnail').prop('disabled', true);
                    $('#thumbnailPreview').attr('src', '../uploads/batch_thumbnails/default_batch.svg');
                } else {
                    $('#thumbnail').prop('disabled', false);
                    $('#thumbnailPreview').attr('src', '<?= !empty($batch['thumbnail_path']) ? '../' . htmlspecialchars($batch['thumbnail_path']) : '../uploads/batch_thumbnails/default_batch.svg' ?>');
                }
            });
        });
        
        function previewThumbnail(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                if (file.size > 2 * 1024 * 1024) {
                    alert('File size must be less than 2MB');
                    input.value = '';
                    return;
                }
                
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, PNG, GIF, or WebP)');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#thumbnailPreview').attr('src', e.target.result);
                    $('input[name="remove_thumbnail"]').prop('checked', false);
                };
                reader.readAsDataURL(file);
            }
        }
    </script>
</body>
</html>