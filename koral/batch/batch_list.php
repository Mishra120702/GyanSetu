<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle delete action with proper checks
if (isset($_POST['delete_batch'])) {
    $batch_id = $_POST['batch_id'];
    
    // Check if batch has students in ANY batch field including batch_name_4
    $checkStudents = $db->prepare("SELECT COUNT(*) FROM students WHERE batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?");
    $checkStudents->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
    $studentCount = $checkStudents->fetchColumn();
    
    // Check if batch has attendance records
    $checkAttendance = $db->prepare("SELECT COUNT(*) FROM attendance WHERE batch_id = ?");
    $checkAttendance->execute([$batch_id]);
    $attendanceCount = $checkAttendance->fetchColumn();
    
    // Check if batch has schedule records
    $checkSchedule = $db->prepare("SELECT COUNT(*) FROM schedule WHERE batch_id = ?");
    $checkSchedule->execute([$batch_id]);
    $scheduleCount = $checkSchedule->fetchColumn();
    
    if ($studentCount > 0 || $attendanceCount > 0 || $scheduleCount > 0) {
        $_SESSION['error_message'] = "Cannot delete batch. It has associated students, attendance records, or scheduled classes.";
    } else {
        $stmt = $db->prepare("DELETE FROM batches WHERE batch_id = ?");
        if ($stmt->execute([$batch_id])) {
            $_SESSION['success_message'] = "Batch deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Error deleting batch.";
        }
    }
    
    // Redirect to avoid form resubmission
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
    
    // Validate dates
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
    
    // Check if batch is already assigned to this semester
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
$lastBatch = $db->query("SELECT batch_id FROM batches ORDER BY batch_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$nextBatchId = 'B001'; // Default if no batches exist

if ($lastBatch) {
    // Extract the numeric part and increment
    $lastNumber = (int) substr($lastBatch['batch_id'], 1);
    $nextNumber = $lastNumber + 1;
    $nextBatchId = 'B' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
}

// Handle add new batch action
if (isset($_POST['add_batch'])) {
    // Calculate current_enrollment from actual student count (set to 0 for new batch)
    $current_enrollment = 0;
    
    // Handle thumbnail upload
    $thumbnail_path = null;
    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/batch_thumbnails/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
        $file_name = 'batch_' . $_POST['batch_id'] . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_path)) {
            $thumbnail_path = $upload_path;
        }
    }
    
    $stmt = $db->prepare("INSERT INTO batches (
        batch_id, batch_name, course_description, start_date, end_date, time_slot, platform, 
        meeting_link, thumbnail_path, max_students, current_enrollment, academic_year,
        batch_mentor_id, mode, status, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $success = $stmt->execute([
        $_POST['batch_id'],
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
        $_POST['batch_mentor_id'],
        $_POST['mode'],
        $_POST['status'],
        $_SESSION['user_id']
    ]);
    
    if ($success) {
        $_SESSION['success_message'] = "Batch created successfully!";
    } else {
        $_SESSION['error_message'] = "Error creating batch. Please try again.";
    }
    
    // Refresh the page to show the new batch
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

// Execute the query
$stmt = $db->prepare($query);
$stmt->execute($params);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --info: #3a86ff;
            --warning: #ff9e00;
            --danger: #f72585;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --glass-bg: rgba(255, 255, 255, 0.9);
            --glass-border: rgba(255, 255, 255, 0.18);
            --shadow: 0 8px 32px rgba(31, 38, 135, 0.15);
            --shadow-light: 0 4px 16px rgba(31, 38, 135, 0.1);
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #3a86ff 100%);
            --gradient-warning: linear-gradient(135deg, #ff9e00 0%, #ff5400 100%);
            --gradient-danger: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: 0;
            padding: 20px;
            animation: fadeIn 0.8s ease-out;
        }
        
        @media (min-width: 768px) {
            .main-content {
                margin-left: 250px;
            }
        }
        
        .page-header {
            border-bottom: none;
            margin-bottom: 32px;
            padding-bottom: 16px;
            position: relative;
        }
        
        .page-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }
        
        .gradient-text {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }
        
        /* Action Bar Styles */
        .action-bar {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-light);
            animation: fadeIn 0.6s ease-out;
        }
        
        .action-bar .btn {
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }
        
        .action-bar .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        
        .action-bar .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.4);
        }
        
        .action-bar .btn-success {
            background: var(--gradient-success);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 201, 240, 0.3);
        }
        
        .action-bar .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 201, 240, 0.4);
        }
        
        .action-bar .btn-purple {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }
        
        .action-bar .btn-purple:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }
        
        .action-bar .btn-info {
            background: linear-gradient(135deg, #3a86ff 0%, #4361ee 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(58, 134, 255, 0.3);
        }
        
        .action-bar .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(58, 134, 255, 0.4);
        }
        
        .action-bar .btn-warning {
            background: var(--gradient-warning);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 158, 0, 0.3);
        }
        
        .action-bar .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 158, 0, 0.4);
        }
        
        /* Card Styles */
        .card-batch {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow);
            height: 100%;
            position: relative;
        }
        
        .card-batch:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(31, 38, 135, 0.2);
        }
        
        .card-batch::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }
        
        .card-batch:hover::before {
            transform: scaleX(1);
        }
        
        .batch-thumbnail {
            height: 200px;
            width: 100%;
            object-fit: cover;
            border-bottom: 1px solid var(--glass-border);
        }
        
        .batch-content {
            padding: 24px;
        }
        
        .batch-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
            font-size: 1.25rem;
        }
        
        .batch-id {
            font-size: 0.85rem;
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .batch-meta {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 20px 0;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        .meta-item i {
            width: 20px;
            color: var(--primary);
        }
        
        .batch-stats {
            background: rgba(67, 97, 238, 0.05);
            border-radius: 12px;
            padding: 16px;
            margin: 20px 0;
        }
        
        .student-count {
            text-align: center;
        }
        
        .count-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
        }
        
        .count-label {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 4px;
        }
        
        .enrollment-progress {
            height: 8px;
            background: rgba(67, 97, 238, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 8px;
        }
        
        .enrollment-bar {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 4px;
            transition: width 1s ease;
        }
        
        .batch-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-ongoing {
            background: linear-gradient(135deg, #4cc9f0 0%, #3a86ff 100%);
            color: white;
        }
        
        .status-upcoming {
            background: linear-gradient(135deg, #ff9e00 0%, #ff5400 100%);
            color: white;
        }
        
        .status-completed {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
        }
        
        .status-cancelled {
            background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            color: white;
        }
        
        .batch-mode {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .mode-online {
            background: rgba(76, 201, 240, 0.1);
            color: #3a86ff;
            border: 1px solid rgba(76, 201, 240, 0.3);
        }
        
        .mode-offline {
            background: rgba(255, 193, 7, 0.1);
            color: #ff9e00;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        
        .batch-actions {
            display: flex;
            gap: 8px;
            margin-top: 20px;
        }
        
        .action-btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
        }
        
        .btn-view {
            background: linear-gradient(135deg, #4cc9f0 0%, #3a86ff 100%);
            color: white;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #ff9e00 0%, #ff5400 100%);
            color: white;
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            color: white;
        }
        
        .btn-semester {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
        }
        
        /* Filter Section */
        .filter-section {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-light);
            animation: fadeIn 0.6s ease-out;
        }
        
        .search-bar {
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(67, 97, 238, 0.1);
            border-radius: 12px;
            padding: 8px 16px;
            transition: all 0.3s ease;
        }
        
        .search-bar:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15);
        }
        
        /* Floating Button */
        .floating-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 100;
            background: var(--gradient-primary);
            border: none;
            border-radius: 50%;
            width: 64px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.4);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: float 3s ease-in-out infinite;
        }
        
        .floating-btn:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 15px 35px rgba(67, 97, 238, 0.5);
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        /* Notifications */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
            animation: slideInRight 0.5s ease-out;
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Grid View */
        .grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
            margin-top: 24px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Badge Styles */
        .badge-mentor {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(67, 97, 238, 0); }
            100% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0); }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: rgba(67, 97, 238, 0.2);
            margin-bottom: 20px;
        }
        
        .thumbnail-placeholder {
            height: 200px;
            width: 100%;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.1) 0%, rgba(114, 9, 183, 0.1) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 2rem;
        }
        
        /* Student Distribution */
        .student-distribution {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 0.75rem;
            color: var(--gray);
        }
        
        .distribution-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .distribution-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .dot-primary { background-color: var(--primary); }
        .dot-success { background-color: var(--success); }
        .dot-warning { background-color: var(--warning); }
        .dot-info { background-color: var(--info); }
        
        /* Modal Styles */
        .modal-content {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(31, 38, 135, 0.25);
        }
        
        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(67, 97, 238, 0.1);
            border-radius: 12px;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            background: white;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15);
        }
        
        .thumbnail-preview {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 12px;
            margin-top: 10px;
            display: none;
        }
        
        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 8px;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 4px;
            box-shadow: var(--shadow-light);
        }
        
        .view-toggle-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--gray);
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .view-toggle-btn.active {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        
        .view-toggle-btn:hover:not(.active) {
            background: rgba(67, 97, 238, 0.05);
            color: var(--primary);
        }
        
        /* Table View Styles */
        .table-container {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .table {
            --bs-table-bg: transparent;
            margin-bottom: 0;
        }
        
        .table th {
            background: rgba(67, 97, 238, 0.05);
            border: none;
            font-weight: 600;
            color: var(--dark);
            padding: 1rem;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid var(--glass-border);
        }
        
        .table tbody tr:hover {
            background: rgba(67, 97, 238, 0.03);
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
            border: none;
        }
        
        .table-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Batch Cards Specific */
        .batch-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .batch-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .batch-card-id {
            font-size: 0.8rem;
            color: var(--primary);
            font-weight: 500;
        }
        
        .batch-card-dates {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 10px;
        }
        
        .batch-card-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 15px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: rgba(67, 97, 238, 0.03);
            border-radius: 10px;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 5px;
        }
        
        /* Trainer Avatar Styles - Updated Position */
        .trainer-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(67, 97, 238, 0.1);
        }
        
        .trainer-info-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .trainer-avatar-large {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
            box-shadow: 0 6px 12px rgba(67, 97, 238, 0.25);
        }
        
        .trainer-details {
            display: flex;
            flex-direction: column;
        }
        
        .trainer-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dark);
            line-height: 1.2;
        }
        
        .trainer-role {
            font-size: 0.75rem;
            color: var(--gray);
        }
        
        .avatar-placeholder-large {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            border: 3px solid white;
            box-shadow: 0 6px 12px rgba(67, 97, 238, 0.25);
        }
        
        .batch-status-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        /* Semester Badge Styles */
        .semester-badge {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            margin: 2px;
            display: inline-block;
        }
        
        .semester-info {
            margin-top: 5px;
        }
        
        /* Semester Modal Header */
        .semester-modal-header {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%) !important;
        }
        
        .assign-modal-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h1 class="h2 mb-1 gradient-text">Batch Management</h1>
                        <p class="text-muted mb-0">Manage training batches, view details, and track enrollment</p>
                    </div>
                    <div class="col-auto">
                        <div class="d-flex gap-2">
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
            </div>

            <!-- Notifications -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="notification">
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle fa-lg text-success me-3"></i>
                            <div><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="notification">
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-circle fa-lg text-danger me-3"></i>
                            <div><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Action Bar with All Buttons -->
            <div class="action-bar">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="d-flex flex-wrap gap-3">
                            <button type="button" class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#createBatchModal">
                                <i class="fas fa-plus-circle me-2"></i> Add New Batch
                            </button>
                            <button type="button" class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#createSemesterModal">
                                <i class="fas fa-calendar-plus me-2"></i> Create Semester
                            </button>
                            <a href="upload_batch.php" class="btn btn-success">
                                <i class="fas fa-file-excel me-2"></i> Upload Excel
                            </a>
                            <a href="batch_transfers.php" class="btn btn-info">
                                <i class="fas fa-exchange-alt me-2"></i> Batch Transfers
                            </a>
                            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#viewAssignmentsModal">
                                <i class="fas fa-link me-2"></i> View Assignments
                            </button>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkActionsModal">
                                <i class="fas fa-cogs me-2"></i> Bulk Actions
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="input-group search-bar">
                            <span class="input-group-text border-0 bg-transparent">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-0" id="searchInput" placeholder="Search batches...">
                            <button class="btn btn-outline-secondary border-0" type="button" id="clearSearch">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Course Name</label>
                        <input type="text" name="course" class="form-control" placeholder="Search batches..." value="<?= htmlspecialchars($course_filter) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="ongoing" <?= $status_filter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="upcoming" <?= $status_filter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Mode</label>
                        <select name="mode" class="form-select">
                            <option value="">All Modes</option>
                            <option value="online" <?= $mode_filter === 'online' ? 'selected' : '' ?>>Online</option>
                            <option value="offline" <?= $mode_filter === 'offline' ? 'selected' : '' ?>>Offline</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Semester</label>
                        <select name="semester" class="form-select">
                            <option value="">All Semesters</option>
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?= $semester['id'] ?>" <?= $semester_filter == $semester['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($semester['name']) ?> (<?= $semester['academic_year'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Date Range</label>
                        <input type="text" name="date_range" class="form-control date-picker" placeholder="Select date range" value="<?= htmlspecialchars($date_filter) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i> Filter
                        </button>
                    </div>
                </form>
            </div>

            <!-- Grid View (Default) -->
            <div id="gridView" class="grid-view">
                <?php if (empty($batches)): ?>
                    <div class="col-12">
                        <div class="empty-state">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <h4 class="text-muted mb-3">No batches found</h4>
                            <p class="text-muted mb-4">Create your first batch to get started</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBatchModal">
                                <i class="fas fa-plus me-2"></i> Create Batch
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($batches as $batch): 
                        $enrollmentPercent = $batch['max_students'] > 0 ? 
                            ($batch['actual_enrollment'] / $batch['max_students']) * 100 : 0;
                        
                        // Status badge
                        $status_badge = '';
                        switch($batch['status']) {
                            case 'ongoing': $status_badge = 'status-ongoing'; break;
                            case 'upcoming': $status_badge = 'status-upcoming'; break;
                            case 'completed': $status_badge = 'status-completed'; break;
                            case 'cancelled': $status_badge = 'status-cancelled'; break;
                        }
                        
                        // Mode badge
                        $mode_badge = $batch['mode'] === 'online' ? 'mode-online' : 'mode-offline';
                    ?>
                    <div class="card-batch animate__animated animate__fadeIn" 
                         data-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                         data-name="<?= htmlspecialchars(strtolower($batch['batch_name'])) ?>"
                         data-status="<?= htmlspecialchars($batch['status']) ?>"
                         data-mode="<?= htmlspecialchars($batch['mode']) ?>">
                        
                        <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>"><!-- Thumbnail -->
                        <?php if (!empty($batch['thumbnail_path'])): ?>
                            <img src="../<?= htmlspecialchars($batch['thumbnail_path']) ?>" 
                                 alt="<?= htmlspecialchars($batch['batch_name']) ?>" 
                                 class="batch-thumbnail">
                        <?php else: ?>
                            <div class="thumbnail-placeholder">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                        <?php endif; ?>
                        </a>
                        <!-- Content -->
                        <div class="batch-content">
                            <!-- Header -->
                            <div class="batch-card-header">
                                <div>
                                    <div class="batch-card-title"><?= htmlspecialchars($batch['batch_name']) ?></div>
                                    <div class="batch-card-id">
                                        <i class="fas fa-hashtag"></i>
                                        <?= htmlspecialchars($batch['batch_id']) ?>
                                    </div>
                                </div>
                                <div class="batch-status-section">
                                    <span class="batch-status <?= $status_badge ?>">
                                        <i class="fas fa-circle"></i>
                                        <?= ucfirst($batch['status']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Semester Information -->
                            <?php if (!empty($batch['semester_info'])): ?>
                                <div class="semester-info">
                                    <?php 
                                    $semesterInfos = explode(', ', $batch['semester_info']);
                                    foreach ($semesterInfos as $info): 
                                    ?>
                                        <span class="semester-badge"><?= htmlspecialchars($info) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Dates -->
                            <div class="batch-card-dates">
                                <i class="fas fa-calendar-alt text-primary me-2"></i>
                                <?= date('d M Y', strtotime($batch['start_date'])) ?> - 
                                <?= date('d M Y', strtotime($batch['end_date'])) ?>
                            </div>
                            
                            <!-- Meta Information -->
                            <div class="batch-meta">
                                <?php if (!empty($batch['time_slot'])): ?>
                                <div class="meta-item">
                                    <i class="far fa-clock"></i>
                                    <span><?= htmlspecialchars($batch['time_slot']) ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="meta-item">
                                    <i class="fas fa-chalkboard"></i>
                                    <span class="<?= $mode_badge ?>">
                                        <i class="fas fa-<?= $batch['mode'] === 'online' ? 'wifi' : 'building' ?>"></i>
                                        <?= ucfirst($batch['mode']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Stats -->
                            <div class="batch-card-stats">
                                <div class="stat-item">
                                    <div class="stat-value"><?= $batch['actual_enrollment'] ?></div>
                                    <div class="stat-label">Students</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?= $batch['max_students'] ?></div>
                                    <div class="stat-label">Capacity</div>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div class="enrollment-progress">
                                <div class="enrollment-bar" style="width: <?= min($enrollmentPercent, 100) ?>%"></div>
                            </div>
                            <div class="text-center text-muted small mt-2">
                                <?= round($enrollmentPercent, 1) ?>% filled
                            </div>
                            
                            
                            <!-- Trainer Section - Positioned below status -->
                            <?php if (!empty($batch['mentor_name'])): ?>
                            <div class="trainer-section">
                                <div class="trainer-info-container">
                                    <?php if (!empty($batch['mentor_avatar'])): ?>
                                        <img src="<?= htmlspecialchars($batch['mentor_avatar']) ?>" 
                                             alt="<?= htmlspecialchars($batch['mentor_name']) ?>"
                                             class="trainer-avatar-large"
                                             >
                                    <?php else: ?>
                                        <div class="avatar-placeholder-large">
                                            <?= substr($batch['mentor_name'], 0, 1) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="trainer-details">
                                        <span class="trainer-name"><?= htmlspecialchars($batch['mentor_name']) ?></span>
                                        <span class="trainer-role">Batch Trainer</span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Actions -->
                            <div class="batch-actions">
                                <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>" 
                                   class="action-btn btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                
                                <a href="../batch/edit_batch.php?id=<?= htmlspecialchars($batch['batch_id']) ?>" 
                                   class="action-btn btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                
                                <button type="button" onclick="assignToSemester('<?= $batch['batch_id'] ?>', '<?= htmlspecialchars(addslashes($batch['batch_name'])) ?>')" 
                                        class="action-btn btn-semester">
                                    <i class="fas fa-link"></i> Assign
                                </button>
                                
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this batch? This action cannot be undone.')">
                                    <input type="hidden" name="batch_id" value="<?= $batch['batch_id'] ?>">
                                    <button type="submit" name="delete_batch" class="action-btn btn-delete">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Table View (Hidden by Default) -->
            <div id="tableView" class="d-none">
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-hover" id="batchTable">
                            <thead>
                                <tr>
                                    <th>Batch ID</th>
                                    <th>Course</th>
                                    <th>Semester</th>
                                    <th>Thumbnail</th>
                                    <th>Dates</th>
                                    <th>Students</th>
                                    <th>Mentor</th>
                                    <th>Mode</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batches as $batch): 
                                    $enrollmentPercent = $batch['max_students'] > 0 ? 
                                        ($batch['actual_enrollment'] / $batch['max_students']) * 100 : 0;
                                ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold text-primary">
                                            <i class="fas fa-hashtag me-1"></i>
                                            <?= htmlspecialchars($batch['batch_id']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($batch['batch_name']) ?></div>
                                        <?php if (!empty($batch['course_description'])): ?>
                                            <small class="text-muted"><?= substr(htmlspecialchars($batch['course_description']), 0, 50) ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($batch['semester_info'])): ?>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php 
                                                $semesterInfos = explode(', ', $batch['semester_info']);
                                                foreach ($semesterInfos as $info): 
                                                ?>
                                                    <span class="semester-badge"><?= htmlspecialchars($info) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($batch['thumbnail_path'])): ?>
                                            <img src="<?= htmlspecialchars($batch['thumbnail_path']) ?>" 
                                                 alt="Thumbnail" 
                                                 style="width: 60px; height: 40px; object-fit: cover; border-radius: 6px;">
                                        <?php else: ?>
                                            <div class="text-muted">
                                                <i class="fas fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div><?= date('d M Y', strtotime($batch['start_date'])) ?></div>
                                            <div class="text-muted">to</div>
                                            <div><?= date('d M Y', strtotime($batch['end_date'])) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= $batch['actual_enrollment'] ?>/<?= $batch['max_students'] ?></div>
                                        <div class="progress" style="height: 6px; width: 100px;">
                                            <div class="progress-bar bg-primary" style="width: <?= min($enrollmentPercent, 100) ?>%"></div>
                                        </div>
                                        <small class="text-muted"><?= round($enrollmentPercent, 1) ?>%</small>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($batch['mentor_avatar'])): ?>
                                                <img src="<?= htmlspecialchars($batch['mentor_avatar']) ?>" 
                                                     alt="<?= htmlspecialchars($batch['mentor_name']) ?>"
                                                     style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;"
                                                     onerror="this.onerror=null; this.style.display='none'">
                                            <?php endif; ?>
                                            <span><?= !empty($batch['mentor_name']) ? htmlspecialchars($batch['mentor_name']) : 'Not assigned' ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $batch['mode'] === 'online' ? 'bg-info' : 'bg-secondary' ?> table-badge">
                                            <i class="fas fa-<?= $batch['mode'] === 'online' ? 'wifi' : 'building' ?> me-1"></i>
                                            <?= ucfirst($batch['mode']) ?>
                                    </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $status_class = '';
                                        switch($batch['status']) {
                                            case 'ongoing': $status_class = 'bg-success'; break;
                                            case 'upcoming': $status_class = 'bg-warning'; break;
                                            case 'completed': $status_class = 'bg-secondary'; break;
                                            case 'cancelled': $status_class = 'bg-danger'; break;
                                        }
                                        ?>
                                        <span class="badge <?= $status_class ?> table-badge">
                                            <?= ucfirst($batch['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>" 
                                               class="btn btn-outline-primary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="../batch/edit_batch.php?id=<?= htmlspecialchars($batch['batch_id']) ?>" 
                                               class="btn btn-outline-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" onclick="assignToSemester('<?= $batch['batch_id'] ?>', '<?= htmlspecialchars(addslashes($batch['batch_name'])) ?>')" 
                                                    class="btn btn-outline-purple" title="Assign to Semester">
                                                <i class="fas fa-link"></i>
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="batch_id" value="<?= $batch['batch_id'] ?>">
                                                <button type="submit" name="delete_batch" 
                                                        class="btn btn-outline-danger" title="Delete"
                                                        onclick="return confirm('Are you sure?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Batch Modal -->
    <div class="modal fade" id="createBatchModal" tabindex="-1" aria-labelledby="createBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title gradient-text" id="createBatchModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Create New Batch
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="batch_id" class="form-label fw-semibold">Batch ID *</label>
                                <input type="text" class="form-control" id="batch_id" name="batch_id" 
                                       value="<?= htmlspecialchars($nextBatchId) ?>" readonly required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="batch_name" class="form-label fw-semibold">Course Name *</label>
                                <input type="text" class="form-control" id="batch_name" name="batch_name" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="course_description" class="form-label fw-semibold">Course Description</label>
                                <textarea class="form-control" id="course_description" name="course_description" 
                                          rows="2" placeholder="Brief description of the course"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="start_date" class="form-label fw-semibold">Start Date *</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="end_date" class="form-label fw-semibold">End Date *</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="time_slot" class="form-label fw-semibold">Time Slot</label>
                                <input type="text" class="form-control" id="time_slot" name="time_slot" 
                                       placeholder="e.g., 10:00 AM - 12:00 PM">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="max_students" class="form-label fw-semibold">Max Students *</label>
                                <input type="number" class="form-control" id="max_students" name="max_students" 
                                       min="1" required placeholder="e.g., 30">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="academic_year" class="form-label fw-semibold">Academic Year</label>
                                <input type="text" class="form-control" id="academic_year" name="academic_year" 
                                       placeholder="e.g., 2024-2025">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="batch_mentor_id" class="form-label fw-semibold">Batch Mentor</label>
                                <select class="form-select" id="batch_mentor_id" name="batch_mentor_id">
                                    <option value="">Select Mentor</option>
                                    <?php foreach ($mentors as $mentor): ?>
                                        <option value="<?= $mentor['id'] ?>"><?= htmlspecialchars($mentor['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Mode *</label>
                                <div class="d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="mode" 
                                               id="mode_online" value="online" checked>
                                        <label class="form-check-label" for="mode_online">
                                            <i class="fas fa-wifi me-1"></i> Online
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="mode" 
                                               id="mode_offline" value="offline">
                                        <label class="form-check-label" for="mode_offline">
                                            <i class="fas fa-building me-1"></i> Offline
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="status" class="form-label fw-semibold">Status *</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="upcoming">Upcoming</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6" id="platformField">
                                <label for="platform" class="form-label fw-semibold">Platform</label>
                                <select class="form-select" id="platform" name="platform">
                                    <option value="">Select Platform</option>
                                    <option value="Google Meet">Google Meet</option>
                                    <option value="Zoom">Zoom</option>
                                    <option value="Microsoft Teams">Microsoft Teams</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6" id="meetingLinkField">
                                <label for="meeting_link" class="form-label fw-semibold">Meeting Link</label>
                                <input type="url" class="form-control" id="meeting_link" name="meeting_link" 
                                       placeholder="https://meet.google.com/abc-xyz">
                            </div>
                            
                            <div class="col-12">
                                <label for="thumbnail" class="form-label fw-semibold">Thumbnail Image</label>
                                <input type="file" class="form-control" id="thumbnail" name="thumbnail" 
                                       accept="image/*" onchange="previewImage(this)">
                                <img id="thumbnailPreview" class="thumbnail-preview" alt="Thumbnail Preview">
                                <div class="form-text">Upload a thumbnail image for the batch (optional)</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_batch" class="btn btn-primary">
                            <i class="fas fa-check-circle me-2"></i>Create Batch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Semester Modal -->
    <div class="modal fade" id="createSemesterModal" tabindex="-1" aria-labelledby="createSemesterModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header semester-modal-header text-white">
                    <h5 class="modal-title" id="createSemesterModalLabel">
                        <i class="fas fa-calendar-plus me-2"></i>Create New Semester
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="semester_name" class="form-label fw-semibold">Semester Name *</label>
                                <input type="text" class="form-control" id="semester_name" name="semester_name" 
                                       placeholder="e.g., Fall 2024, Spring 2025" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="academic_year" class="form-label fw-semibold">Academic Year *</label>
                                <input type="text" class="form-control" id="academic_year" name="academic_year" 
                                       placeholder="e.g., 2024-25" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="semester_start_date" class="form-label fw-semibold">Start Date *</label>
                                <input type="date" class="form-control" id="semester_start_date" name="start_date" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="semester_end_date" class="form-label fw-semibold">End Date *</label>
                                <input type="date" class="form-control" id="semester_end_date" name="end_date" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="description" class="form-label fw-semibold">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="Optional description for the semester..."></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="semester_status" class="form-label fw-semibold">Status *</label>
                                <select class="form-select" id="semester_status" name="status" required>
                                    <option value="planning">Planning</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_semester" class="btn btn-purple">
                            <i class="fas fa-calendar-plus me-2"></i>Create Semester
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Batch to Semester Modal -->
    <div class="modal fade" id="assignBatchModal" tabindex="-1" aria-labelledby="assignBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header assign-modal-header text-white">
                    <h5 class="modal-title" id="assignBatchModalLabel">
                        <i class="fas fa-link me-2"></i>Assign Batch to Semester
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="assign_batch_to_semester" value="1">
                    <input type="hidden" id="assign_batch_id" name="batch_id" value="">
                    
                    <div class="modal-body">
                        <div class="mb-4 p-4 bg-blue-50 rounded-lg">
                            <h6 class="fw-semibold text-blue-800">Selected Batch:</h6>
                            <p id="selectedBatchInfo" class="text-blue-600 mb-0"></p>
                        </div>
                        
                        <div class="mb-3">
                            <label for="semester_id" class="form-label fw-semibold">Select Semester *</label>
                            <select class="form-select" id="semester_id" name="semester_id" required>
                                <option value="">Select a semester</option>
                                <?php foreach ($semesters as $semester): ?>
                                    <option value="<?= $semester['id'] ?>">
                                        <?= htmlspecialchars($semester['name']) ?> (<?= $semester['academic_year'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="semester_number" class="form-label fw-semibold">Semester Number *</label>
                            <select class="form-select" id="semester_number" name="semester_number" required>
                                <option value="">Select semester number</option>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                                <option value="3">Semester 3</option>
                                <option value="4">Semester 4</option>
                                <option value="5">Semester 5</option>
                                <option value="6">Semester 6</option>
                            </select>
                            <div class="form-text">Select the semester number (1-6) for this batch</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-link me-2"></i>Assign to Semester
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Assignments Modal -->
    <div class="modal fade" id="viewAssignmentsModal" tabindex="-1" aria-labelledby="viewAssignmentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title gradient-text" id="viewAssignmentsModalLabel">
                        <i class="fas fa-link me-2"></i>Batch-Semester Assignments
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (count($assignments) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Semester</th>
                                        <th>Batch</th>
                                        <th>Semester #</th>
                                        <th>Assigned On</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($assignment['semester_name']) ?></strong>
                                            <br>
                                            <span class="text-muted"><?= $assignment['academic_year'] ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($assignment['batch_id']) ?> - 
                                            <?= htmlspecialchars($assignment['batch_name']) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-purple">Semester <?= $assignment['semester_number'] ?></span>
                                        </td>
                                        <td>
                                            <?= date('d-M-Y H:i', strtotime($assignment['assigned_at'])) ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="remove_id" value="<?= $assignment['id'] ?>">
                                                <button type="submit" name="remove_batch_from_semester" 
                                                        class="btn btn-sm btn-outline-danger" 
                                                        onclick="return confirm('Remove this batch from semester?')">
                                                    <i class="fas fa-unlink"></i> Remove
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 text-muted">
                            <i class="fas fa-link fa-4x mb-4 opacity-50"></i>
                            <h5>No batch-semester assignments yet.</h5>
                            <p class="text-muted">Assign batches to semesters using the "Assign" button.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Modal -->
    <div class="modal fade" id="bulkActionsModal" tabindex="-1" aria-labelledby="bulkActionsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title gradient-text" id="bulkActionsModalLabel">
                        <i class="fas fa-cogs me-2"></i>Bulk Actions
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-grid gap-2">
                        <a href="batch_export.php" class="btn btn-outline-primary">
                            <i class="fas fa-file-export me-2"></i> Export to Excel
                        </a>
                        <a href="batch_import.php" class="btn btn-outline-success">
                            <i class="fas fa-file-import me-2"></i> Import from Excel
                        </a>
                        <a href="batch_archive.php" class="btn btn-outline-warning">
                            <i class="fas fa-archive me-2"></i> Archive Old Batches
                        </a>
                        <a href="batch_reports.php" class="btn btn-outline-info">
                            <i class="fas fa-chart-bar me-2"></i> Generate Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button type="button" class="btn btn-primary btn-lg rounded-circle floating-btn" 
            data-bs-toggle="modal" data-bs-target="#createBatchModal">
        <i class="fas fa-plus"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        $(document).ready(function() {
            // Initialize date picker
            flatpickr(".date-picker", {
                mode: "range",
                dateFormat: "Y-m-d",
                placeholder: "Select date range"
            });
            
            // View toggle functionality
            $('.view-toggle-btn').click(function() {
                const view = $(this).data('view');
                $('.view-toggle-btn').removeClass('active');
                $(this).addClass('active');
                
                if (view === 'grid') {
                    $('#gridView').removeClass('d-none').addClass('animate__animated animate__fadeIn');
                    $('#tableView').addClass('d-none');
                } else {
                    $('#tableView').removeClass('d-none').addClass('animate__animated animate__fadeIn');
                    $('#gridView').addClass('d-none');
                }
            });
            
            // Show/hide online fields based on mode selection
            $('input[name="mode"]').change(function() {
                if ($(this).val() === 'online') {
                    $('#platformField, #meetingLinkField').show();
                } else {
                    $('#platformField, #meetingLinkField').hide();
                    $('#platform, #meeting_link').val('');
                }
            });
            
            // Initialize the display
            if ($('input[name="mode"]:checked').val() === 'online') {
                $('#platformField, #meetingLinkField').show();
            } else {
                $('#platformField, #meetingLinkField').hide();
            }
            
            // Form validation
            $('form').on('submit', function(e) {
                const startDate = new Date($('#start_date').val());
                const endDate = new Date($('#end_date').val());
                
                if (startDate >= endDate) {
                    e.preventDefault();
                    alert('End date must be after start date.');
                    return false;
                }
                
                const maxStudents = parseInt($('#max_students').val());
                if (maxStudents <= 0) {
                    e.preventDefault();
                    alert('Maximum students must be greater than 0.');
                    return false;
                }
                
                // Semester form validation
                const semesterStartDate = new Date($('#semester_start_date').val());
                const semesterEndDate = new Date($('#semester_end_date').val());
                
                if (semesterStartDate && semesterEndDate && semesterStartDate >= semesterEndDate) {
                    e.preventDefault();
                    alert('Semester end date must be after start date.');
                    return false;
                }
                
                return true;
            });
            
            // Set default dates for batch
            const today = new Date();
            const startDate = new Date(today);
            const endDate = new Date(today);
            endDate.setMonth(endDate.getMonth() + 3); // Default 3-month course
            
            $('#start_date').val(today.toISOString().split('T')[0]);
            $('#end_date').val(endDate.toISOString().split('T')[0]);
            
            // Set default dates for semester
            const semesterStart = new Date(today);
            const semesterEnd = new Date(today);
            semesterEnd.setMonth(semesterEnd.getMonth() + 4); // Default 4-month semester
            
            $('#semester_start_date').val(semesterStart.toISOString().split('T')[0]);
            $('#semester_end_date').val(semesterEnd.toISOString().split('T')[0]);
            
            // Search functionality
            $('#searchInput').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                
                if (searchTerm.length > 0) {
                    // Search in grid view
                    $('.card-batch').each(function() {
                        const card = $(this);
                        const batchName = card.data('name');
                        const batchId = card.data('id');
                        
                        if (batchName.includes(searchTerm) || batchId.includes(searchTerm)) {
                            card.show();
                        } else {
                            card.hide();
                        }
                    });
                    
                    // Search in table view
                    $('#batchTable tbody tr').each(function() {
                        const row = $(this);
                        const rowText = row.text().toLowerCase();
                        
                        if (rowText.includes(searchTerm)) {
                            row.show();
                        } else {
                            row.hide();
                        }
                    });
                } else {
                    // Show all
                    $('.card-batch').show();
                    $('#batchTable tbody tr').show();
                }
            });
            
            $('#clearSearch').click(function() {
                $('#searchInput').val('');
                $('.card-batch').show();
                $('#batchTable tbody tr').show();
            });
            
            // Auto-hide notifications after 5 seconds
            setTimeout(() => {
                $('.notification').fadeOut();
            }, 5000);
            
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Add animation to cards on scroll
            function animateCardsOnScroll() {
                $('.card-batch').each(function() {
                    const elementTop = $(this).offset().top;
                    const elementBottom = elementTop + $(this).outerHeight();
                    const viewportTop = $(window).scrollTop();
                    const viewportBottom = viewportTop + $(window).height();
                    
                    if (elementBottom > viewportTop && elementTop < viewportBottom) {
                        $(this).addClass('animate__animated animate__fadeInUp');
                    }
                });
            }
            
            $(window).scroll(animateCardsOnScroll);
            animateCardsOnScroll(); // Initial check
            
            // Filter functionality for table view
            const filterStatus = '<?= $status_filter ?>';
            const filterMode = '<?= $mode_filter ?>';
            const filterSemester = '<?= $semester_filter ?>';
            
            if (filterStatus || filterMode || filterSemester) {
                $('.card-batch').each(function() {
                    const card = $(this);
                    const status = card.data('status');
                    const mode = card.data('mode');
                    
                    if ((filterStatus && status !== filterStatus) || (filterMode && mode !== filterMode)) {
                        card.hide();
                    }
                });
            }
        });
        
        // Image preview function
        function previewImage(input) {
            const preview = document.getElementById('thumbnailPreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }
        
        // Function to assign batch to semester
        function assignToSemester(batchId, batchName) {
            document.getElementById('assign_batch_id').value = batchId;
            document.getElementById('selectedBatchInfo').textContent = batchId + ' - ' + batchName;
            
            // Show the modal
            const assignModal = new bootstrap.Modal(document.getElementById('assignBatchModal'));
            assignModal.show();
        }
        
        // Confirm delete function
        function confirmDelete(batchId, batchName) {
            if (confirm(`Are you sure you want to delete batch "${batchName}" (${batchId})? This action cannot be undone.`)) {
                return true;
            }
            return false;
        }
    </script>
</body>
</html>