<?php
// Database connection
require_once '../db_connection.php';
require_once 'sync_curriculum.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle delete action with proper checks
if (isset($_POST['delete_batch'])) {
    $batch_id = $_POST['batch_id'];

    $db->prepare("UPDATE students SET batch_name = NULL WHERE batch_name = ?")->execute([$batch_id]);
    $db->prepare("UPDATE students SET batch_name_2 = NULL WHERE batch_name_2 = ?")->execute([$batch_id]);
    $db->prepare("UPDATE students SET batch_name_3 = NULL WHERE batch_name_3 = ?")->execute([$batch_id]);
    $db->prepare("UPDATE students SET batch_name_4 = NULL WHERE batch_name_4 = ?")->execute([$batch_id]);

    $db->prepare("DELETE FROM attendance WHERE batch_id = ?")->execute([$batch_id]);
    $db->prepare("DELETE FROM schedule WHERE batch_id = ?")->execute([$batch_id]);

    $stmt = $db->prepare("DELETE FROM batches WHERE batch_id = ?");

    if ($stmt->execute([$batch_id])) {
        $_SESSION['success_message'] = "Batch deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Error deleting batch.";
    }

    header("Location: batch_list.php");
    exit;
}

// Handle semester creation
if (isset($_POST['create_semester'])) {
    $name = $_POST['semester_name'];
    $description = $_POST['description'];
    $academic_year = $_POST['academic_year'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $status = $_POST['status'];
    
    if (strtotime($start_date) >= strtotime($end_date)) {
        $_SESSION['error_message'] = "End date must be after start date.";
        header("Location: batch_list.php");
        exit;
    }
    
    $stmt = $db->prepare("INSERT INTO semesters (name, description, academic_year, start_date, end_date, status, created_by) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$name, $description, $academic_year, $start_date, $end_date, $status, $_SESSION['user_id']])) {
        $_SESSION['success_message'] = "Semester created successfully!";
    } else {
        $_SESSION['error_message'] = "Error creating semester.";
    }
    
    header("Location: batch_list.php");
    exit;
}

// Handle semester batch assignment
if (isset($_POST['assign_batch_to_semester'])) {
    $semester_id = $_POST['semester_id'];
    $batch_id = $_POST['batch_id'];
    $semester_number = $_POST['semester_number'];
    
    $check = $db->prepare("SELECT COUNT(*) FROM semester_batches WHERE semester_id = ? AND batch_id = ?");
    $check->execute([$semester_id, $batch_id]);
    
    if ($check->fetchColumn() > 0) {
        $_SESSION['error_message'] = "This batch is already assigned to this semester.";
    } else {
        $stmt = $db->prepare("INSERT INTO semester_batches (semester_id, batch_id, semester_number, assigned_by) 
                              VALUES (?, ?, ?, ?)");
        
        if ($stmt->execute([$semester_id, $batch_id, $semester_number, $_SESSION['user_id']])) {
            $_SESSION['success_message'] = "Batch assigned to semester successfully!";
        } else {
            $_SESSION['error_message'] = "Error assigning batch to semester.";
        }
    }
    
    header("Location: batch_list.php");
    exit;
}

// Handle remove batch from semester
if (isset($_POST['remove_batch_from_semester'])) {
    $id = $_POST['remove_id'];
    
    $stmt = $db->prepare("DELETE FROM semester_batches WHERE id = ?");
    
    if ($stmt->execute([$id])) {
        $_SESSION['success_message'] = "Batch removed from semester successfully!";
    } else {
        $_SESSION['error_message'] = "Error removing batch from semester.";
    }
    
    header("Location: batch_list.php");
    exit;
}

// Get the last batch ID from the database
$lastBatch = $db->query("
    SELECT batch_id
    FROM batches
    ORDER BY CAST(SUBSTRING(batch_id,2) AS UNSIGNED) DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$nextBatchId = 'B001';

if ($lastBatch) {
    $lastNumber = (int) substr($lastBatch['batch_id'], 1);
    $nextNumber = $lastNumber + 1;
    $nextBatchId = 'B' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
}

// Handle add new batch action
if (isset($_POST['add_batch'])) {
    $current_enrollment = 0;

    $freshLast = $db->query("SELECT batch_id FROM batches ORDER BY CAST(SUBSTRING(batch_id, 2) AS UNSIGNED) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($freshLast) {
        $freshNumber = (int) substr($freshLast['batch_id'], 1) + 1;
        $safe_batch_id = 'B' . str_pad($freshNumber, 3, '0', STR_PAD_LEFT);
    } else {
        $safe_batch_id = 'B001';
    }

    $thumbnail_path = null;
    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/batch_thumbnails/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
        $file_name = 'batch_' . $safe_batch_id . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_path)) {
            $thumbnail_path = 'uploads/batch_thumbnails/' . $file_name;
        }
    }
    
    $stmt = $db->prepare("INSERT INTO batches (
        batch_id, batch_name, course_description, start_date, end_date, time_slot, platform, 
        meeting_link, thumbnail_path, max_students, current_enrollment, academic_year,
batch_mentor_id, mode, status, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $success = $stmt->execute([
        $safe_batch_id,
        $_POST['batch_name'],
        $_POST['course_description'] ?? '',
        $_POST['start_date'],
        $_POST['end_date'],
        $_POST['time_slot'],
        $_POST['platform'],
        $_POST['meeting_link'],
        $thumbnail_path,
        $_POST['max_students'],
        $current_enrollment,
        $_POST['academic_year'],
        null, // Mentors are now assigned at course level
        $_POST['mode'],
$_POST['status'],
$_SESSION['user_id']
    ]);
    
    if ($success) {
        logSystemActivity($db, $_SESSION['user_id'], 'BATCH_CREATED', "Batch '{$_POST['batch_name']}' ($safe_batch_id) created.");
        
        if (isset($_POST['courses']) && is_array($_POST['courses'])) {
            $courseStmt = $db->prepare("INSERT INTO batch_courses (batch_id, course_id) VALUES (?, ?)");
            foreach ($_POST['courses'] as $course_id) {
                $courseStmt->execute([$safe_batch_id, $course_id]);
                sync_course_curriculum_to_batch($db, $safe_batch_id, $course_id);
                logSystemActivity($db, $_SESSION['user_id'], 'COURSE_ASSIGNED', "Course ID $course_id assigned to batch $safe_batch_id.");
            }
        }
        $_SESSION['success_message'] = "Batch created successfully!";
    } else {
        $_SESSION['error_message'] = "Error creating batch. Please try again.";
    }
    
    header("Location: batch_list.php");
    exit();
}

// Get filter values from GET parameters
$course_filter = $_GET['course'] ?? '';
$status_filter = $_GET['status'] ?? '';
$mode_filter = $_GET['mode'] ?? '';
$date_filter = $_GET['date_range'] ?? '';
$semester_filter = $_GET['semester'] ?? '';

// Fetch all semesters
$semesters = $db->query("SELECT * FROM semesters ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all courses
$all_courses = $db->query("SELECT * FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Build the query with filters - Sort by batch_id descending
$query = "SELECT 
            b.*, 
            t.name as mentor_name,
            t.profile_picture as mentor_avatar,
            (SELECT COUNT(*) FROM students s 
             WHERE (s.batch_name = b.batch_id 
                 OR s.batch_name_2 = b.batch_id 
                 OR s.batch_name_3 = b.batch_id
                 OR s.batch_name_4 = b.batch_id)
               AND s.current_status = 'active') as actual_enrollment,
            (SELECT COUNT(*) FROM students s 
             WHERE s.batch_name = b.batch_id 
               AND s.current_status = 'active') as batch_name_count,
            (SELECT COUNT(*) FROM students s 
             WHERE s.batch_name_2 = b.batch_id 
               AND s.current_status = 'active') as batch_name_2_count,
            (SELECT COUNT(*) FROM students s 
             WHERE s.batch_name_3 = b.batch_id 
               AND s.current_status = 'active') as batch_name_3_count,
            (SELECT COUNT(*) FROM students s 
             WHERE s.batch_name_4 = b.batch_id 
               AND s.current_status = 'active') as batch_name_4_count,
            GROUP_CONCAT(DISTINCT CONCAT(sm.name, ' (Sem ', sb.semester_number, ')') SEPARATOR ', ') as semester_info
          FROM batches b
          LEFT JOIN trainers t ON b.batch_mentor_id = t.id
          LEFT JOIN semester_batches sb ON b.batch_id = sb.batch_id
          LEFT JOIN semesters sm ON sb.semester_id = sm.id
          WHERE 1=1";

$params = [];

if (!empty($course_filter)) {
    $query .= " AND b.batch_name LIKE ?";
    $params[] = "%$course_filter%";
}

if (!empty($status_filter)) {
    $query .= " AND b.status = ?";
    $params[] = $status_filter;
}

if (!empty($mode_filter)) {
    $query .= " AND b.mode = ?";
    $params[] = $mode_filter;
}

if (!empty($date_filter)) {
    $dates = explode(' to ', $date_filter);
    if (count($dates) === 2) {
        $query .= " AND b.start_date >= ? AND b.end_date <= ?";
        $params[] = $dates[0];
        $params[] = $dates[1];
    }
}

if (!empty($semester_filter)) {
    $query .= " AND sb.semester_id = ?";
    $params[] = $semester_filter;
}

$query .= " GROUP BY b.batch_id ORDER BY b.batch_id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch courses for each batch and maintain order
if (!empty($batches)) {
    $batchIds = array_column($batches, 'batch_id');
    $placeholders = str_repeat('?,', count($batchIds) - 1) . '?';
    $coursesQuery = "SELECT bc.batch_id, c.name as course_name 
                     FROM batch_courses bc 
                     JOIN courses c ON bc.course_id = c.id 
                     WHERE bc.batch_id IN ($placeholders) 
                     ORDER BY bc.id ASC";
    $coursesStmt = $db->prepare($coursesQuery);
    $coursesStmt->execute($batchIds);
    $coursesResult = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $batchCoursesMap = [];
    foreach ($coursesResult as $row) {
        if (!isset($batchCoursesMap[$row['batch_id']]) || !in_array($row['course_name'], $batchCoursesMap[$row['batch_id']])) {
            $batchCoursesMap[$row['batch_id']][] = $row['course_name'];
        }
    }
    
    foreach ($batches as &$batchRef) {
        $batchRef['courses'] = $batchCoursesMap[$batchRef['batch_id']] ?? [];
    }
    unset($batchRef);
}

// Get mentor list for batch creation
$mentors = $db->query("SELECT id, name, profile_picture FROM trainers")->fetchAll(PDO::FETCH_ASSOC);

// Get semester assignments for modal
$assignmentsStmt = $db->query("
    SELECT sb.*, b.batch_name, s.name as semester_name, s.academic_year
    FROM semester_batches sb
    JOIN batches b ON sb.batch_id = b.batch_id
    JOIN semesters s ON sb.semester_id = s.id
    ORDER BY s.start_date DESC, sb.semester_number
");
$assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Management - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <style>
        /* =========================================================
           DESIGN TOKENS
           ========================================================= */
        :root {
            --navy-900:  #1B3C53;
            --navy-700:  #234C6A;
            --navy-500:  #456882;
            --sand-300:  #D2C1B6;
            --surface:   #F2EDE9;

            --navy-900-10: rgba(27,60,83,0.10);
            --navy-900-06: rgba(27,60,83,0.06);
            --navy-700-15: rgba(35,76,106,0.15);
            --navy-700-25: rgba(35,76,106,0.25);
            --sand-300-40: rgba(210,193,182,0.55);

            --status-ongoing:    #1a7f5a;
            --status-ongoing-bg: rgba(26,127,90,0.10);
            --status-upcoming:   #b45309;
            --status-upcoming-bg:rgba(180,83,9,0.10);
            --status-completed:  #456882;
            --status-completed-bg:rgba(69,104,130,0.10);
            --status-cancelled:  #991b1b;
            --status-cancelled-bg:rgba(153,27,27,0.10);

            --mode-online:    #1d4ed8;
            --mode-online-bg: rgba(29,78,216,0.09);
            --mode-offline:   #92400e;
            --mode-offline-bg:rgba(146,64,14,0.09);

            --radius-sm:   8px;
            --radius-md:   12px;
            --radius-lg:   16px;
            --radius-xl:   20px;
            --radius-pill: 999px;

            --shadow-card:       0 2px 12px rgba(27,60,83,0.10), 0 1px 4px rgba(27,60,83,0.06);
            --shadow-card-hover: 0 12px 32px rgba(27,60,83,0.18), 0 4px 12px rgba(27,60,83,0.10);
            --shadow-btn:        0 2px 8px rgba(35,76,106,0.22);
            --shadow-btn-hover:  0 6px 18px rgba(35,76,106,0.32);

            --font-body:    'Inter', sans-serif;
            --font-display: 'Plus Jakarta Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            background: linear-gradient(160deg, #F2EDE9 0%, #F5F0EC 100%);
            font-family: var(--font-body);
            color: var(--navy-900);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* =========================================================
           LAYOUT
           ========================================================= */
        .main-content {
            margin-left: 0;
            padding: 28px 24px;
        }

        @media (min-width: 768px) { .main-content { margin-left: 250px; } }

        /* =========================================================
           PAGE HEADER
           ========================================================= */
        .page-header {
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--sand-300-40);
        }

        .page-header h1 {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 1.65rem;
            color: var(--navy-900);
            letter-spacing: -0.3px;
            margin-bottom: 4px;
        }

        .page-header p {
            font-size: 0.875rem;
            color: var(--navy-500);
            margin: 0;
        }

        /* =========================================================
           NOTIFICATIONS
           ========================================================= */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
            min-width: 320px;
            animation: slideInRight 0.4s cubic-bezier(.16,1,.3,1);
        }

        @keyframes slideInRight {
            from { transform: translateX(110%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }

        .notification .alert {
            border: none;
            border-radius: var(--radius-lg);
            box-shadow: 0 8px 30px rgba(27,60,83,0.18);
            padding: 14px 18px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* =========================================================
           ACTION BAR
           ========================================================= */
        .action-bar {
            background: #fff;
            border: 2px solid var(--navy-900);
            border-radius: var(--radius-xl);
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-card);
        }

        /* =========================================================
           BUTTONS
           ========================================================= */
        .btn {
            font-family: var(--font-body);
            font-weight: 500;
            font-size: 0.8375rem;
            border: none;
            border-radius: var(--radius-md);
            padding: 9px 18px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.22s ease;
            line-height: 1.4;
            cursor: pointer;
        }

        .btn:hover  { transform: translateY(-1px); box-shadow: var(--shadow-btn-hover); }
        .btn:active { transform: translateY(0); }

        .btn-primary { background: var(--navy-900); color: #fff; box-shadow: var(--shadow-btn); }
        .btn-primary:hover { background: #152e40; color: #fff; }

        .btn-navy { background: var(--navy-700); color: #fff; box-shadow: var(--shadow-btn); }
        .btn-navy:hover { background: #1b3c53; color: #fff; }

        .btn-purple { background: #4c3575; color: #fff; box-shadow: 0 2px 8px rgba(76,53,117,0.28); }
        .btn-purple:hover { background: #3a2759; color: #fff; }

        .btn-success { background: #1a7f5a; color: #fff; box-shadow: 0 2px 8px rgba(26,127,90,0.28); }
        .btn-success:hover { background: #146346; color: #fff; }

        .btn-info { background: var(--navy-500); color: #fff; box-shadow: 0 2px 8px rgba(69,104,130,0.28); }
        .btn-info:hover { background: #375469; color: #fff; }

        .btn-warning { background: #b45309; color: #fff; box-shadow: 0 2px 8px rgba(180,83,9,0.28); }
        .btn-warning:hover { background: #8f4107; color: #fff; }

        .btn-danger { background: #991b1b; color: #fff; box-shadow: 0 2px 8px rgba(153,27,27,0.28); }
        .btn-danger:hover { background: #7a1515; color: #fff; }

        .btn-ghost {
            background: transparent;
            color: var(--navy-700);
            border: 1.5px solid var(--sand-300);
            box-shadow: none;
        }
        .btn-ghost:hover { background: var(--navy-900-06); border-color: var(--navy-500); color: var(--navy-900); box-shadow: none; }

        /* =========================================================
           VIEW TOGGLE
           ========================================================= */
        .view-toggle {
            display: flex;
            gap: 4px;
            background: rgba(255,255,255,0.85);
            border: 2px solid #1B3C53 !important;
            border-radius: var(--radius-md);
            padding: 4px;
            box-shadow: var(--shadow-card);
        }

        .view-toggle-btn {
            padding: 7px 16px;
            border: none;
            border-radius: var(--radius-sm);
            background: transparent;
            color: var(--navy-500);
            font-size: 0.8125rem;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .view-toggle-btn.active { background: var(--navy-900); color: #fff; box-shadow: 0 2px 8px var(--navy-700-25); }
        .view-toggle-btn:hover:not(.active) { background: var(--navy-900-06); color: var(--navy-900); }

        /* =========================================================
           SEARCH BAR
           ========================================================= */
        .search-bar {
            background: rgba(255,255,255,0.88);
            border: 2px solid #1B3C53 !important;
            border-radius: var(--radius-md);
            padding: 4px 12px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .search-bar:focus-within { border-color: var(--navy-700); box-shadow: 0 0 0 3px var(--navy-700-15); }

        .search-bar .form-control {
            border: none; background: transparent; padding: 6px 4px;
            font-size: 0.875rem; color: var(--navy-900); box-shadow: none !important;
        }

        .search-bar .form-control::placeholder { color: var(--navy-500); }
        .search-bar .input-group-text,
        .search-bar .btn { background: transparent; border: none; box-shadow: none; color: var(--navy-500); padding: 4px 6px; }
        .search-bar .btn:hover { transform: none; }

        /* =========================================================
           FILTER SECTION
           ========================================================= */
        .filter-section {
            background: linear-gradient(160deg, #FFFFFF 60%, rgba(210,193,182,0.14) 100%);
            border: 2px solid #1B3C53 !important;
            border-radius: var(--radius-xl);
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-card);
        }

        .filter-section .form-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            background: rgba(242,237,233,0.60);
            border: 1.5px solid rgba(210,193,182,0.65);
            border-radius: var(--radius-md);
            padding: 9px 14px;
            font-size: 0.875rem;
            color: var(--navy-900);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--navy-700);
            box-shadow: 0 0 0 3px var(--navy-700-15);
            background: #fff;
            outline: none;
            color: var(--navy-900);
        }

        .form-control::placeholder { color: var(--navy-500); }

        /* =========================================================
           GRID
           ========================================================= */
        .grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            margin-top: 8px;
        }

        /* =========================================================
           BATCH CARD
           ========================================================= */
        .card-batch {
            background: linear-gradient(160deg, #FFFFFF 55%, rgba(210,193,182,0.16) 100%);
            border: 2px solid #1B3C53;
            border-radius: var(--radius-xl);
            overflow: hidden;
            transition: transform 0.26s ease, box-shadow 0.26s ease, border-color 0.26s ease;
            box-shadow: var(--shadow-card);
            height: 100%;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .card-batch:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-card-hover);
            border-color: var(--sand-300);
        }

        .card-batch::after {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 3px;
            background: linear-gradient(90deg, var(--navy-900), var(--navy-500));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
            z-index: 1;
        }

        .card-batch:hover::after { transform: scaleX(1); }

        .batch-thumbnail {
            height: 185px; width: 100%;
            object-fit: cover; display: block;
            border-bottom: 1px solid var(--sand-300-40);
        }

        .thumbnail-placeholder {
            height: 185px; width: 100%;
            background: linear-gradient(135deg, rgba(210,193,182,0.35) 0%, rgba(210,193,182,0.18) 100%);
            display: flex; align-items: center; justify-content: center;
            color: var(--navy-500); font-size: 2.4rem;
            border-bottom: 1px solid var(--sand-300-40);
        }

        .batch-content {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .batch-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
        }

        .batch-card-title {
            font-family: var(--font-display);
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--navy-900);
            line-height: 1.3;
            margin-bottom: 4px;
        }

        .batch-card-id {
            font-size: 0.78rem;
            color: var(--navy-500);
            font-weight: 600;
            letter-spacing: 0.4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Status badges */
        .batch-status {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 11px;
            border-radius: var(--radius-pill);
            font-size: 0.72rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .status-ongoing   { background: var(--status-ongoing-bg);    color: var(--status-ongoing);   }
        .status-upcoming  { background: var(--status-upcoming-bg);   color: var(--status-upcoming);  }
        .status-completed { background: var(--status-completed-bg);  color: var(--status-completed); }
        .status-cancelled { background: var(--status-cancelled-bg);  color: var(--status-cancelled); }

        /* Mode badges */
        .batch-mode {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 11px;
            border-radius: var(--radius-pill);
            font-size: 0.72rem; font-weight: 600;
        }

        .mode-online  { background: var(--mode-online-bg);  color: var(--mode-online);  border: 1px solid rgba(29,78,216,0.15); }
        .mode-offline { background: var(--mode-offline-bg); color: var(--mode-offline); border: 1px solid rgba(146,64,14,0.15); }

        /* Semester badges */
        .semester-info { margin-bottom: 10px; }

        .semester-badge {
            display: inline-block;
            background: rgba(210,193,182,0.28);
            color: var(--navy-700);
            padding: 3px 9px;
            border-radius: var(--radius-pill);
            font-size: 0.7rem; font-weight: 600;
            margin: 2px;
            border: 1px solid rgba(210,193,182,0.55);
        }

        .batch-card-dates {
            font-size: 0.82rem;
            color: var(--navy-500);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .batch-meta { display: flex; flex-direction: column; gap: 7px; margin-bottom: 16px; }

        .meta-item {
            display: flex; align-items: center; gap: 9px;
            font-size: 0.84rem; color: var(--navy-500);
        }

        .meta-item i { width: 18px; text-align: center; color: var(--navy-500); font-size: 0.8rem; }

        /* Stats */
        .batch-stats {
            background: rgba(210,193,182,0.15);
            border: 1px solid rgba(210,193,182,0.55);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            margin-bottom: 16px;
        }
.card-batch{
    display:flex;
    flex-direction:column;
    height:100%;
}
.batch-meta{
    min-height:auto;
}

.batch-content{
    padding:20px;
    display:flex;
    flex-direction:column;
    flex:1;
}

.course-tags-wrapper{
    margin-bottom:16px;
}

.batch-stats{
    margin-top:auto;
    margin-bottom:12px;
}

.batch-actions{
    margin-top:0;
}

        .batch-card-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .stat-item {
            text-align: center; padding: 10px 6px;
            background: rgba(255,255,255,0.80);
            border: 1px solid rgba(210,193,182,0.55);
            border-radius: var(--radius-md);
        }

        .stat-value {
            font-family: var(--font-display);
            font-size: 1.5rem; font-weight: 700;
            color: var(--navy-900); line-height: 1;
            display: block;
        }

        .stat-label {
            font-size: 0.72rem; color: var(--navy-500);
            margin-top: 4px; display: block;
            text-transform: uppercase; letter-spacing: 0.4px; font-weight: 500;
        }

        .enrollment-progress {
            height: 6px; background: var(--navy-900-10);
            border-radius: var(--radius-pill); overflow: hidden; margin-top: 8px;
        }

        .enrollment-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--navy-700), var(--navy-500));
            border-radius: var(--radius-pill);
            transition: width 1s ease;
        }

        .student-distribution {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-top: 8px; font-size: 0.73rem; color: var(--navy-500);
        }

        .distribution-item { display: flex; align-items: center; gap: 4px; }

        .distribution-dot {
            width: 8px; height: 8px; border-radius: 50%;
            display: inline-block; flex-shrink: 0;
        }

        .dot-primary { background: var(--navy-900); }
        .dot-success { background: var(--status-ongoing); }
        .dot-warning { background: var(--status-upcoming); }
        .dot-info    { background: var(--navy-500); }

        /* Course tags */
        .course-tag {
            display: inline-block;
            background: rgba(210,193,182,0.22);
            color: var(--navy-700);
            border: 1px solid rgba(210,193,182,0.55);
            border-radius: var(--radius-sm);
            padding: 3px 9px;
            font-size: 0.72rem; font-weight: 500;
            margin: 2px;
        }

        /* Trainer section */
        .trainer-section {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: auto; padding-top: 14px;
            border-top: 1px solid var(--sand-300-40);
        }

        .trainer-info-container { display: flex; align-items: center; gap: 10px; }

        .trainer-avatar-large {
            width: 40px; height: 40px; border-radius: 50%;
            object-fit: cover; border: 2.5px solid var(--sand-300);
        }

        .avatar-placeholder-large {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, var(--navy-900) 0%, var(--navy-500) 100%);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1rem; font-weight: 700;
            border: 2.5px solid var(--sand-300); flex-shrink: 0;
        }

        .trainer-name { font-size: 0.85rem; font-weight: 600; color: var(--navy-900); line-height: 1.2; }
        .trainer-role { font-size: 0.72rem; color: var(--navy-500); }

        .batch-status-section { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; }

        /* Card action buttons */
        .batch-actions {
    display: flex;
    gap: 6px;
    margin-top: auto;
    padding-top: 14px;
}

        .action-btn {
            flex: 1; padding: 8px 10px; border: none;
            border-radius: var(--radius-md);
            font-size: 0.8rem; font-weight: 500; cursor: pointer;
            transition: all 0.22s ease;
            display: flex; align-items: center; justify-content: center; gap: 5px;
            text-decoration: none;
        }

        .action-btn:hover { transform: translateY(-1px); filter: brightness(1.08); }

        .btn-view     { background: var(--navy-700); color: #fff; }
        .btn-edit     { background: #b45309; color: #fff; }
        .btn-delete   { background: #991b1b; color: #fff; }
        .btn-semester { background: #4c3575; color: #fff; }

        /* =========================================================
           TABLE VIEW
           ========================================================= */
        .table-container {
            background: linear-gradient(160deg, #FFFFFF 60%, rgba(210,193,182,0.12) 100%);
            border:2px solid var(--navy-900);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }

        .table { --bs-table-bg: transparent; margin-bottom: 0; }

        .table thead th {
            background: rgba(210,193,182,0.18);
            border-bottom: 2px solid rgba(210,193,182,0.65);
            border-top: none;
            font-size: 0.72rem; font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase; letter-spacing: 0.6px;
            padding: 13px 16px; white-space: nowrap;
        }

        .table tbody tr {
            border-bottom: 1px solid var(--sand-300-40);
            transition: background 0.15s ease;
        }

        .table tbody tr:last-child { border-bottom: none; }
        .table tbody tr:hover { background: rgba(210,193,182,0.16); }

        .table td {
            padding: 13px 16px; vertical-align: middle; border: none;
            font-size: 0.875rem; color: var(--navy-900);
        }

        .table-thumbnail {
            width: 52px; height: 36px; object-fit: cover;
            border-radius: var(--radius-sm); border: 1px solid var(--sand-300-40);
        }

        .table-thumb-placeholder {
            width: 52px; height: 36px;
            background: rgba(210,193,182,0.28);
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            color: var(--navy-500); font-size: 0.9rem;
            border: 1px solid var(--sand-300-40);
        }

        /* =========================================================
           EMPTY STATE
           ========================================================= */
        .empty-state { text-align: center; padding: 72px 24px; grid-column: 1 / -1; }

        .empty-state-icon {
            width: 72px; height: 72px;
            background: rgba(210,193,182,0.28); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px; font-size: 2rem; color: var(--navy-500);
        }

        .empty-state h4 { font-family: var(--font-display); font-weight: 700; color: var(--navy-900); margin-bottom: 8px; }
        .empty-state p  { color: var(--navy-500); font-size: 0.9rem; }

        /* =========================================================
           FLOATING BUTTON
           ========================================================= */
        .floating-btn {
            position: fixed; bottom: 28px; right: 28px; z-index: 100;
            background: var(--navy-900); border: none;
            border-radius: 50%; width: 56px; height: 56px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.3rem;
            box-shadow: 0 6px 24px var(--navy-700-25);
            transition: all 0.3s ease; cursor: pointer;
        }

        .floating-btn:hover {
            background: var(--navy-700);
            transform: scale(1.08) rotate(90deg);
            box-shadow: 0 10px 32px var(--navy-700-25);
        }

        /* =========================================================
           MODALS
           ========================================================= */
        .modal-content {
            background: linear-gradient(160deg, #FFFFFF 60%, rgba(210,193,182,0.12) 100%);
            border: 1px solid rgba(210,193,182,0.55);
            border-radius: var(--radius-xl);
            box-shadow: 0 24px 64px rgba(27,60,83,0.22);
            overflow: hidden;
        }

        .modal-header {
            background: var(--navy-900); color: #fff;
            border-bottom: none; padding: 18px 24px;
        }

        .modal-header .modal-title {
            font-family: var(--font-display); font-weight: 700; font-size: 1rem;
        }

        .modal-header .btn-close { filter: invert(1); opacity: 0.7; }
        .modal-header .btn-close:hover { opacity: 1; }

        .modal-header.semester-modal-header { background: #4c3575 !important; }
        .modal-header.assign-modal-header   { background: #1a7f5a !important; }

        .modal-body { padding: 24px; }

        .modal-footer { border-top: 1px solid rgba(210,193,182,0.60); padding: 16px 24px; gap: 10px; }

        .modal-body .form-label {
            font-size: 0.8rem; font-weight: 600;
            color: var(--navy-500);
            text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
        }

        .thumbnail-preview {
            width: 100%; height: 140px; object-fit: cover;
            border-radius: var(--radius-md); margin-top: 10px;
            display: none; border: 1px solid var(--sand-300-40);
        }

        /* =========================================================
           UTILITIES
           ========================================================= */
        .fw-600 { font-weight: 600 !important; }

        @keyframes ring-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(27,60,83,0.35); }
            70%  { box-shadow: 0 0 0 10px rgba(27,60,83,0); }
            100% { box-shadow: 0 0 0 0 rgba(27,60,83,0); }
        }
        .pulse { animation: ring-pulse 2.2s ease-out infinite; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .card-animate { animation: fadeInUp 0.45s ease both; }

        .grid-view > *:nth-child(1)   { animation-delay: 0.05s; }
        .grid-view > *:nth-child(2)   { animation-delay: 0.10s; }
        .grid-view > *:nth-child(3)   { animation-delay: 0.15s; }
        .grid-view > *:nth-child(4)   { animation-delay: 0.20s; }
        .grid-view > *:nth-child(5)   { animation-delay: 0.25s; }
        .grid-view > *:nth-child(6)   { animation-delay: 0.30s; }
        .grid-view > *:nth-child(n+7) { animation-delay: 0.35s; }
        /* Fix Add New Batch Modal Scroll */
#createBatchModal .modal-dialog {
    max-width: 900px;
}

#createBatchModal .modal-content {
    max-height: 90vh;
}

#createBatchModal .modal-body {
    max-height: calc(90vh - 140px);
    overflow-y: auto;
    overflow-x: hidden;
}

        /* ═══════════════════════════════════════════════
           PALETTE THEME ENHANCEMENTS — Sand/Cream/Navy
           ═══════════════════════════════════════════════ */

        /* Page header section border */
        .page-header {
            border-bottom: 1px solid rgba(210,193,182,0.60);
        }

        /* Action bar separator */
        .action-bar {
            background: linear-gradient(160deg, rgba(255,255,255,0.70), rgba(210,193,182,0.10));
            border: 2px solid #1B3C53 !important;
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(27,60,83,0.06);
        }

        /* Batch card: sand accent bar on hover */
        .card-batch::after {
            background: linear-gradient(90deg, #1B3C53, #D2C1B6, #456882);
        }

        /* Batch card hover: sand border */
        .card-batch:hover{
    border-color:#234C6A !important;
    box-shadow:
        0 12px 30px rgba(27,60,83,.18),
        0 6px 14px rgba(27,60,83,.12);
}

        /* Trainer section top border: cream */
        .trainer-section {
            border-top: 1px solid rgba(210,193,182,0.55) !important;
        }

        /* Batch-stats bottom accent strip */
        .batch-stats {
            border-bottom: 2px solid rgba(210,193,182,0.55);
        }

        /* course-tag hover */
        .course-tag {
            transition: background .15s, border-color .15s;
        }
        .course-tag:hover {
            background: rgba(210,193,182,0.40);
            border-color: rgba(210,193,182,0.80);
        }

        /* Table rows: subtle cream separator */
        .table tbody tr {
            border-bottom: 1px solid rgba(210,193,182,0.40);
        }

        /* Table thead bottom accent */
        .table thead {
            border-bottom: 2px solid rgba(210,193,182,0.65);
        }

        /* Stat-item inside batch card: hover */
        .stat-item {
            transition: background .15s, border-color .15s;
        }
        .stat-item:hover {
            background: rgba(210,193,182,0.22);
            border-color: rgba(210,193,182,0.70);
        }

        /* Modal body: very subtle cream */
        .modal-body {
            background: rgba(242,237,233,0.30);
        }

        /* Form focus: sand ring */
        .form-control:focus, .form-select:focus {
            background: #fff !important;
            border-color: rgba(210,193,182,0.90) !important;
            box-shadow: 0 0 0 3px rgba(210,193,182,0.25) !important;
        }

        /* Semester badge hover */
        .semester-badge {
            transition: background .15s;
        }
        .semester-badge:hover {
            background: rgba(210,193,182,0.45);
        }

        /* Enrollment progress track: cream */
        .enrollment-progress {
            background: rgba(210,193,182,0.35);
        }

        /* Empty state icon: cream bg */
        .empty-state-icon {
            background: rgba(210,193,182,0.28) !important;
            border: 1px solid rgba(210,193,182,0.45);
        }

        /* Scrollbar: sand thumb */
        ::-webkit-scrollbar-thumb { background: #456882; }
        ::-webkit-scrollbar-track { background: rgba(210,193,182,0.20); }

        /* Filter section form selects */
        .filter-section .form-control,
        .filter-section .form-select {
            background: rgba(255,255,255,0.75);
            border-color: rgba(210,193,182,0.60);
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid px-0">

            <!-- Page Header -->
            <div class="page-header">
                <div class="row align-items-center g-3">
                    <div class="col">
                        <h1 class="mb-0">Batch Management</h1>
                        <p>Manage training batches, view details, and track enrollment</p>
                    </div>
                    <div class="col-auto">
                        <div class="view-toggle">
                            <button class="view-toggle-btn active" data-view="grid">
                                <i class="fas fa-th-large me-1"></i> Grid
                            </button>
                            <button class="view-toggle-btn" data-view="table">
                                <i class="fas fa-list me-1"></i> Table
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notifications -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="notification">
                    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-3" role="alert">
                        <i class="fas fa-check-circle fa-lg"></i>
                        <div class="flex-grow-1"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="notification">
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-3" role="alert">
                        <i class="fas fa-exclamation-circle fa-lg"></i>
                        <div class="flex-grow-1"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Action Bar -->
            <div class="action-bar">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-8">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#createBatchModal">
                                <i class="fas fa-plus-circle"></i> Add New Batch
                            </button>
                            <button type="button" class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#createSemesterModal">
                                <i class="fas fa-calendar-plus"></i> Create Semester
                            </button>
                            <a href="upload_batch.php" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Upload Excel
                            </a>
                            <a href="batch_transfers.php" class="btn btn-info">
                                <i class="fas fa-exchange-alt"></i> Batch Transfers
                            </a>
                            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#viewAssignmentsModal">
                                <i class="fas fa-link"></i> View Assignments
                            </button>
                            <button class="btn btn-ghost" data-bs-toggle="modal" data-bs-target="#bulkActionsModal">
                                <i class="fas fa-cogs"></i> Bulk Actions
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="input-group search-bar">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search batches...">
                            <button class="btn" type="button" id="clearSearch"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Course Name</label>
                        <input type="text" name="course" class="form-control" placeholder="Search batches..."
                               value="<?= htmlspecialchars($course_filter) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="ongoing"   <?= $status_filter === 'ongoing'   ? 'selected' : '' ?>>Ongoing</option>
                            <option value="upcoming"  <?= $status_filter === 'upcoming'  ? 'selected' : '' ?>>Upcoming</option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Mode</label>
                        <select name="mode" class="form-select">
                            <option value="">All Modes</option>
                            <option value="online"  <?= $mode_filter === 'online'  ? 'selected' : '' ?>>Online</option>
                            <option value="offline" <?= $mode_filter === 'offline' ? 'selected' : '' ?>>Offline</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <option value="">All Semesters</option>
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?= $semester['id'] ?>"
                                        <?= $semester_filter == $semester['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($semester['name']) ?> (<?= $semester['academic_year'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date Range</label>
                        <input type="text" name="date_range" class="form-control date-picker"
                               placeholder="Select date range"
                               value="<?= htmlspecialchars($date_filter) ?>">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100">
    <i class="fas fa-filter me-2"></i>
    Filter
</button>
                    </div>
                </form>
            </div>

            <!-- GRID VIEW -->
            <div id="gridView" class="grid-view">
                <?php if (empty($batches)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                        <h4>No batches found</h4>
                        <p class="mb-4">Create your first batch to get started</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBatchModal">
                            <i class="fas fa-plus me-1"></i> Create Batch
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($batches as $batch):
                        $enrollmentPercent = $batch['max_students'] > 0
                            ? ($batch['actual_enrollment'] / $batch['max_students']) * 100 : 0;

                        $status_badge = match($batch['status']) {
                            'ongoing'   => 'status-ongoing',
                            'upcoming'  => 'status-upcoming',
                            'completed' => 'status-completed',
                            'cancelled' => 'status-cancelled',
                            default     => '',
                        };

                        $mode_badge = $batch['mode'] === 'online' ? 'mode-online' : 'mode-offline';
                    ?>
                    <div class="card-batch card-animate"
                         data-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                         data-name="<?= htmlspecialchars(strtolower($batch['batch_name'])) ?>"
                         data-status="<?= htmlspecialchars($batch['status']) ?>"
                         data-mode="<?= htmlspecialchars($batch['mode']) ?>">

                        <?php
                        $batch_thumb = !empty($batch['thumbnail_path'])
                            ? '../' . $batch['thumbnail_path']
                            : '../uploads/batch_thumbnails/default_batch.svg';
                        ?>
                        <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>">
                            <img src="<?= htmlspecialchars($batch_thumb) ?>"
                                 alt="<?= htmlspecialchars($batch['batch_name']) ?>"
                                 class="batch-thumbnail"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="thumbnail-placeholder" style="display:none;">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                        </a>

                        <div class="batch-content">

                            <!-- Header -->
                            <div class="batch-card-header">
                                <div>
                                    <div class="batch-card-title"><?= htmlspecialchars($batch['batch_name']) ?></div>
                                    <div class="batch-card-id">
                                        <i class="fas fa-hashtag" style="font-size:.65rem;"></i>
                                        <?= htmlspecialchars($batch['batch_id']) ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="batch-status <?= $status_badge ?>">
                                        <i class="fas fa-circle" style="font-size:.45rem;"></i>
                                        <?= ucfirst($batch['status']) ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Semester info -->
                            <?php if (!empty($batch['semester_info'])): ?>
                                <div class="semester-info">
                                    <?php foreach (explode(', ', $batch['semester_info']) as $info): ?>
                                        <span class="semester-badge"><?= htmlspecialchars($info) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Dates -->
                            <div class="batch-card-dates">
                                <i class="fas fa-calendar-alt"></i>
                                <?= date('d M Y', strtotime($batch['start_date'])) ?> &ndash;
                                <?= date('d M Y', strtotime($batch['end_date'])) ?>
                            </div>

                            <!-- Meta -->
                            <div class="batch-meta">
                                <?php if (!empty($batch['time_slot'])): ?>
                                    <div class="meta-item">
                                        <i class="far fa-clock"></i>
                                        <span><?= htmlspecialchars($batch['time_slot']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($batch['platform'])): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-video"></i>
                                        <span><?= htmlspecialchars($batch['platform']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="meta-item">
                                    <i class="fas fa-laptop-code"></i>
                                    <span class="batch-mode <?= $mode_badge ?>">
                                        <i class="fas <?= $batch['mode'] === 'online' ? 'fa-wifi' : 'fa-map-marker-alt' ?>"
                                           style="font-size:.65rem;"></i>
                                        <?= ucfirst($batch['mode']) ?>
                                    </span>
                                </div>
                                <?php if (!empty($batch['academic_year'])): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-graduation-cap"></i>
                                        <span><?= htmlspecialchars($batch['academic_year']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Courses -->
                            <div class="course-tags-wrapper">
<?php if (!empty($batch['courses'])): ?>
                                    <?php foreach ($batch['courses'] as $cname): ?>
                                        <span class="course-tag"><?= htmlspecialchars($cname) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
</div>

<div style="flex:1;"></div>
                            <!-- Stats -->
                            <div class="batch-stats">
                                <div class="batch-card-stats">
                                    <div class="stat-item">
                                        <span class="stat-value"><?= (int)$batch['actual_enrollment'] ?></span>
                                        <span class="stat-label">Enrolled</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-value"><?= (int)$batch['max_students'] ?></span>
                                        <span class="stat-label">Capacity</span>
                                    </div>
                                </div>
                                <div class="enrollment-progress">
                                    <div class="enrollment-bar"
                                         style="width:<?= min(100, round($enrollmentPercent)) ?>%"></div>
                                </div>
                                <div class="student-distribution">
                                    <div class="distribution-item">
                                        <span class="distribution-dot dot-primary"></span>
                                        B1: <?= (int)($batch['batch_name_count'] ?? 0) ?>
                                    </div>
                                    <div class="distribution-item">
                                        <span class="distribution-dot dot-success"></span>
                                        B2: <?= (int)($batch['batch_name_2_count'] ?? 0) ?>
                                    </div>
                                    <div class="distribution-item">
                                        <span class="distribution-dot dot-warning"></span>
                                        B3: <?= (int)($batch['batch_name_3_count'] ?? 0) ?>
                                    </div>
                                    <div class="distribution-item">
                                        <span class="distribution-dot dot-info"></span>
                                        B4: <?= (int)($batch['batch_name_4_count'] ?? 0) ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Mentor section removed -->

                            <!-- Action buttons -->
                            <div class="batch-actions">
                                <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>"
                                   class="action-btn btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="edit_batch.php?id=<?= urlencode($batch['batch_id']) ?>"
                                   class="action-btn btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <button type="button"
                                        class="action-btn btn-semester"
                                        data-bs-toggle="modal"
                                        data-bs-target="#assignSemesterModal"
                                        data-batch-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                                        data-batch-name="<?= htmlspecialchars($batch['batch_name']) ?>">
                                    <i class="fas fa-link"></i> Sem
                                </button>
                                <button type="button"
                                        class="action-btn btn-delete"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteBatchModal"
                                        data-batch-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                                        data-batch-name="<?= htmlspecialchars($batch['batch_name']) ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>

                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- TABLE VIEW -->
            <div id="tableView" style="display:none; margin-top:8px;">
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table" id="batchTable">
                            <thead>
                                <tr>
                                    <th>Batch</th>
                                    <th>Name</th>
                                    <th>Courses</th>
                                    <th>Dates</th>
                                    <th>Mode</th>
                                    <th>Enrollment</th>
                                    <th>Status</th>
                                    <th>Mentor</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($batches)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5" style="color:var(--navy-500);">
                                            <i class="fas fa-inbox fa-2x mb-3 d-block" style="opacity:.3;"></i>
                                            No batches found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($batches as $batch):
                                        $status_badge = match($batch['status']) {
                                            'ongoing'   => 'status-ongoing',
                                            'upcoming'  => 'status-upcoming',
                                            'completed' => 'status-completed',
                                            'cancelled' => 'status-cancelled',
                                            default     => '',
                                        };
                                        $mode_badge = $batch['mode'] === 'online' ? 'mode-online' : 'mode-offline';
                                    ?>
                                    <tr data-name="<?= htmlspecialchars(strtolower($batch['batch_name'])) ?>"
                                        data-id="<?= htmlspecialchars(strtolower($batch['batch_id'])) ?>"
                                        data-status="<?= htmlspecialchars($batch['status']) ?>"
                                        data-mode="<?= htmlspecialchars($batch['mode']) ?>">

                                        <td>
                                            <?php $batch_thumb = !empty($batch['thumbnail_path']) ? '../' . $batch['thumbnail_path'] : null; ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if ($batch_thumb): ?>
                                                    <img src="<?= htmlspecialchars($batch_thumb) ?>" class="table-thumbnail" alt="">
                                                <?php else: ?>
                                                    <div class="table-thumb-placeholder"><i class="fas fa-chalkboard-teacher"></i></div>
                                                <?php endif; ?>
                                                <span class="fw-600" style="font-size:.8rem;color:var(--navy-500);">
                                                    <?= htmlspecialchars($batch['batch_id']) ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="fw-600"><?= htmlspecialchars($batch['batch_name']) ?></div>
                                            <?php if (!empty($batch['academic_year'])): ?>
                                                <div style="font-size:.75rem;color:var(--navy-500);">
                                                    <?= htmlspecialchars($batch['academic_year']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if (!empty($batch['courses'])): ?>
                                                <?php foreach ($batch['courses'] as $cname): ?>
                                                    <span class="course-tag"><?= htmlspecialchars($cname) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span style="color:var(--navy-500);font-size:.8rem;">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td style="font-size:.82rem;color:var(--navy-500);">
                                            <?= date('d M Y', strtotime($batch['start_date'])) ?><br>
                                            <span style="color:var(--sand-300);">to</span>
                                            <?= date('d M Y', strtotime($batch['end_date'])) ?>
                                        </td>

                                        <td>
                                            <span class="batch-mode <?= $mode_badge ?>">
                                                <?= ucfirst($batch['mode']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div style="font-size:.82rem;font-weight:600;">
                                                <?= (int)$batch['actual_enrollment'] ?> / <?= (int)$batch['max_students'] ?>
                                            </div>
                                            <div class="enrollment-progress" style="width:100px;margin-top:6px;">
                                                <?php $ep = $batch['max_students'] > 0
                                                    ? ($batch['actual_enrollment'] / $batch['max_students']) * 100 : 0; ?>
                                                <div class="enrollment-bar" style="width:<?= min(100,round($ep)) ?>%"></div>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="batch-status <?= $status_badge ?>">
                                                <i class="fas fa-circle" style="font-size:.4rem;"></i>
                                                <?= ucfirst($batch['status']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if (!empty($batch['mentor_avatar'])): ?>
                                                    <img src="../<?= htmlspecialchars($batch['mentor_avatar']) ?>"
                                                         style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:2px solid var(--sand-300);"
                                                         alt="">
                                                <?php else: ?>
                                                    <div style="width:30px;height:30px;border-radius:50%;background:var(--navy-900);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;">
                                                        <?= strtoupper(substr($batch['mentor_name'] ?? 'M', 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span style="font-size:.82rem;">
                                                    <?= htmlspecialchars($batch['mentor_name'] ?? '—') ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-1">
                                                <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>"
                                                   class="btn btn-navy" style="padding:6px 12px;font-size:.78rem;" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="batch_edit.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>"
                                                   class="btn btn-warning" style="padding:6px 12px;font-size:.78rem;" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-purple"
                                                        style="padding:6px 12px;font-size:.78rem;" title="Assign Semester"
                                                        data-bs-toggle="modal" data-bs-target="#assignSemesterModal"
                                                        data-batch-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                                                        data-batch-name="<?= htmlspecialchars($batch['batch_name']) ?>">
                                                    <i class="fas fa-link"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger"
                                                        style="padding:6px 12px;font-size:.78rem;" title="Delete"
                                                        data-bs-toggle="modal" data-bs-target="#deleteBatchModal"
                                                        data-batch-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                                                        data-batch-name="<?= htmlspecialchars($batch['batch_name']) ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Floating Add Button -->
    <button class="floating-btn" data-bs-toggle="modal" data-bs-target="#createBatchModal" title="Add New Batch">
        <i class="fas fa-plus"></i>
    </button>

    <!-- ================================================================
         MODALS
         ================================================================ -->

    <!-- Create Batch Modal -->
    <div class="modal fade"
     id="createBatchModal"
     tabindex="-1"
     aria-labelledby="createBatchModalLabel"
     aria-hidden="true"
     data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createBatchModalLabel">
                        <i class="fas fa-plus-circle me-2"></i> Add New Batch
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="batch_list.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Batch ID</label>
                                <input type="text" class="form-control" name="batch_id"
                                       value="<?= htmlspecialchars($nextBatchId) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Batch Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="batch_name" required
                                       placeholder="e.g. Python Batch 2025">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Course Description</label>
                                <textarea class="form-control" name="course_description" rows="3"
                                          placeholder="Brief description of the batch / course"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="end_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Time Slot</label>
                                <input type="text" class="form-control" name="time_slot"
                                       placeholder="e.g. Mon-Fri 9:00 AM – 11:00 AM">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Max Students</label>
                                <input type="number" class="form-control" name="max_students" min="1" value="30">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Platform</label>
                                <input type="text" class="form-control" name="platform"
                                       placeholder="e.g. Zoom, Google Meet">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Meeting Link</label>
                                <input type="url" class="form-control" name="meeting_link" placeholder="https://...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Academic Year</label>
                                <input type="text" class="form-control" name="academic_year" placeholder="e.g. 2024-25">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Mode <span class="text-danger">*</span></label>
                                <select class="form-select" name="mode" required>
                                    <option value="online">Online</option>
                                    <option value="offline">Offline</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="upcoming">Upcoming</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Courses</label>
                                <div class="row g-2">
                                    <?php foreach ($all_courses as $course): ?>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                       name="courses[]" value="<?= $course['id'] ?>"
                                                       id="course_<?= $course['id'] ?>">
                                                <label class="form-check-label" for="course_<?= $course['id'] ?>"
                                                       style="font-size:.875rem;text-transform:none;color:var(--navy-900);">
                                                    <?= htmlspecialchars($course['name']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Batch Thumbnail</label>
                                <input type="file" class="form-control" name="thumbnail"
                                       id="thumbnailInput" accept="image/*">
                                <img id="thumbnailPreview" class="thumbnail-preview" src="#" alt="Preview">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_batch" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-1"></i> Create Batch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Semester Modal -->
    <div class="modal fade" id="createSemesterModal" tabindex="-1" aria-labelledby="createSemesterModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header semester-modal-header">
                    <h5 class="modal-title" id="createSemesterModalLabel">
                        <i class="fas fa-calendar-plus me-2"></i> Create New Semester
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="batch_list.php" method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Semester Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="semester_name" required
                                       placeholder="e.g. Semester 1 (2024-25)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="academic_year" required
                                       placeholder="e.g. 2024-25">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2"
                                          placeholder="Optional description"></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="end_date" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="upcoming">Upcoming</option>
                                    <option value="active">Active</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_semester" class="btn btn-purple">
                            <i class="fas fa-calendar-check me-1"></i> Create Semester
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Semester Modal -->
    <div class="modal fade" id="assignSemesterModal" tabindex="-1" aria-labelledby="assignSemesterModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header assign-modal-header">
                    <h5 class="modal-title" id="assignSemesterModalLabel">
                        <i class="fas fa-link me-2"></i> Assign Batch to Semester
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="batch_list.php" method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Batch</label>
                                <input type="text" class="form-control" id="assignBatchName" readonly>
                                <input type="hidden" name="batch_id" id="assignBatchId">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Semester <span class="text-danger">*</span></label>
                                <select class="form-select" name="semester_id" required>
                                    <option value="">— Select Semester —</option>
                                    <?php foreach ($semesters as $semester): ?>
                                        <option value="<?= $semester['id'] ?>">
                                            <?= htmlspecialchars($semester['name']) ?> (<?= $semester['academic_year'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Semester Number</label>
                                <select class="form-select" name="semester_number">
                                    <option value="1">Semester 1</option>
                                    <option value="2">Semester 2</option>
                                    <option value="3">Semester 3</option>
                                    <option value="4">Semester 4</option>
                                    <option value="5">Semester 5</option>
                                    <option value="6">Semester 6</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="assign_batch_to_semester" class="btn btn-success">
                            <i class="fas fa-check me-1"></i> Assign
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Assignments Modal -->
    <div class="modal fade" id="viewAssignmentsModal" tabindex="-1" aria-labelledby="viewAssignmentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewAssignmentsModalLabel">
                        <i class="fas fa-link me-2"></i> Semester–Batch Assignments
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($assignments)): ?>
                        <div class="text-center py-5" style="color:var(--navy-500);">
                            <i class="fas fa-inbox fa-2x mb-3 d-block" style="opacity:.3;"></i>
                            No assignments found
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Semester</th>
                                        <th>Academic Year</th>
                                        <th>Sem No.</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-600"><?= htmlspecialchars($assignment['batch_name']) ?></span>
                                            <div style="font-size:.75rem;color:var(--navy-500);">
                                                <?= htmlspecialchars($assignment['batch_id']) ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($assignment['semester_name']) ?></td>
                                        <td><?= htmlspecialchars($assignment['academic_year']) ?></td>
                                        <td>
                                            <span class="semester-badge">Sem <?= (int)$assignment['semester_number'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <form action="batch_list.php" method="POST"
                                                  onsubmit="return confirm('Remove this assignment?');">
                                                <input type="hidden" name="remove_id" value="<?= $assignment['id'] ?>">
                                                <button type="submit" name="remove_batch_from_semester"
                                                        class="btn btn-danger" style="padding:5px 12px;font-size:.78rem;">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Batch Modal -->
    <div class="modal fade" id="deleteBatchModal" tabindex="-1" aria-labelledby="deleteBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background:#991b1b;">
                    <h5 class="modal-title" id="deleteBatchModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i> Delete Batch
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1" style="font-size:.875rem;">Are you sure you want to delete:</p>
                    <p class="fw-600" id="deleteBatchName" style="color:var(--navy-900);font-size:.9rem;"></p>
                    <p style="font-size:.8rem;color:var(--navy-500);">
                        This action cannot be undone. Batches with students, attendance, or schedule records cannot be deleted.
                    </p>
                </div>
                <div class="modal-footer">
                    <form action="batch_list.php" method="POST" class="d-flex gap-2 w-100 justify-content-end">
                        <input type="hidden" name="batch_id" id="deleteBatchId">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_batch" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i> Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Modal -->
    <div class="modal fade" id="bulkActionsModal" tabindex="-1" aria-labelledby="bulkActionsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkActionsModalLabel">
                        <i class="fas fa-cogs me-2"></i> Bulk Actions
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p style="color:var(--navy-500);font-size:.875rem;margin-bottom:16px;">
                        Select batches from the list and choose an action to apply to all selected batches.
                    </p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-navy" onclick="bulkStatusUpdate('ongoing')">
                            <i class="fas fa-play-circle me-2"></i> Mark Selected as Ongoing
                        </button>
                        <button class="btn btn-warning" onclick="bulkStatusUpdate('upcoming')">
                            <i class="fas fa-clock me-2"></i> Mark Selected as Upcoming
                        </button>
                        <button class="btn btn-ghost" onclick="bulkStatusUpdate('completed')">
                            <i class="fas fa-check-circle me-2"></i> Mark Selected as Completed
                        </button>
                        <button class="btn btn-danger" onclick="bulkStatusUpdate('cancelled')">
                            <i class="fas fa-ban me-2"></i> Mark Selected as Cancelled
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
    // Flatpickr date range
    flatpickr('.date-picker', {
        mode: 'range',
        dateFormat: 'Y-m-d',
        allowInput: true
    });

    // Thumbnail preview
    document.getElementById('thumbnailInput')?.addEventListener('change', function() {
        const file = this.files[0];
        const preview = document.getElementById('thumbnailPreview');
        if (file) {
            const reader = new FileReader();
            reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(file);
        } else {
            preview.style.display = 'none';
        }
    });

    // View toggle
    document.querySelectorAll('.view-toggle-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.view-toggle-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const view = this.dataset.view;
            document.getElementById('gridView').style.display  = view === 'grid'  ? 'grid'  : 'none';
            document.getElementById('tableView').style.display = view === 'table' ? 'block' : 'none';
        });
    });

    // Live search
    document.getElementById('searchInput').addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();

        document.querySelectorAll('#gridView .card-batch').forEach(card => {
            const name = card.dataset.name || '';
            const id   = card.dataset.id   || '';
            card.style.display = (name.includes(query) || id.includes(query)) ? '' : 'none';
        });

        document.querySelectorAll('#batchTable tbody tr').forEach(row => {
            const name = row.dataset.name || '';
            const id   = row.dataset.id   || '';
            row.style.display = (name.includes(query) || id.includes(query)) ? '' : 'none';
        });
    });

    document.getElementById('clearSearch').addEventListener('click', function() {
        const input = document.getElementById('searchInput');
        input.value = '';
        input.dispatchEvent(new Event('input'));
    });

    // Delete modal populate
    document.getElementById('deleteBatchModal')?.addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        document.getElementById('deleteBatchId').value = btn.dataset.batchId;
        document.getElementById('deleteBatchName').textContent =
            btn.dataset.batchName + ' (' + btn.dataset.batchId + ')';
    });

    // Assign semester modal populate
    document.getElementById('assignSemesterModal')?.addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        document.getElementById('assignBatchId').value   = btn.dataset.batchId;
        document.getElementById('assignBatchName').value = btn.dataset.batchName + ' (' + btn.dataset.batchId + ')';
    });

    // Bulk actions stub
    function bulkStatusUpdate(status) {
        alert('Bulk action "' + status + '" — wire up form submission as needed.');
    }

    // Auto-dismiss notifications after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.notification .alert').forEach(alert => {
            bootstrap.Alert.getOrCreateInstance(alert).close();
        });
    }, 5000);
    </script>
</body>
</html>