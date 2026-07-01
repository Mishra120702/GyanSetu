<?php
// file: attendance.php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Check if batch_id is provided in URL
$preselected_batch = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
$course_id = isset($_GET['course_id']) ? $_GET['course_id'] : '';
$preselected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if (empty($course_id)) {
    die("Error: Course ID is required to view course attendance.");
}

// Get course details
$course_stmt = $db->prepare('SELECT name FROM courses WHERE id = ?');
$course_stmt->execute([$course_id]);
$course_name = $course_stmt->fetchColumn() ?: 'Unknown Course';

// Get batch name
$batch_stmt = $db->prepare('SELECT batch_name FROM batches WHERE batch_id = ?');
$batch_stmt->execute([$preselected_batch]);
$batch_name_display = $batch_stmt->fetchColumn() ?: $preselected_batch;

// Get courses for this batch for the dropdown
try {
    $stmt = $db->prepare("SELECT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id WHERE bc.batch_id = ? ORDER BY c.name");
    $stmt->execute([$preselected_batch]);
    $batch_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $batch_courses = [];
}
$batches = []; // Keep defined for backward compatibility

// Handle file upload if submitted
if (isset($_POST['import'])) {
    if (isset($_FILES['excel_file'])) {
        require_once 'attendance_upload.php'; // Include the processing script
        header("Location: course_attendance.php?batch_id=" . urlencode($batch_id) . "&course_id=" . urlencode($_POST['course_id'] ?? $course_id)); // Redirect back to prevent form resubmission
        exit();
    }
}

// Handle new attendance creation
if (isset($_POST['create_attendance'])) {
    $batch_id = $_POST['batch_id'];
    $date = $_POST['date'];
    
    try {
        // Check if attendance already exists for this batch and date
        $stmt = $db->prepare("SELECT COUNT(*) FROM course_attendance WHERE batch_id = ? AND date = ? AND course_id = ?");
        $stmt->execute([$batch_id, $date, $_POST['course_id'] ?? $course_id]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $_SESSION['error_message'] = "Attendance already exists for batch $batch_id on $date";
        } else {
            // Get all ACTIVE students in this batch with their student_id
            // Updated to include batch_name_2, batch_name_3, and batch_name_4
            $stmt = $db->prepare("SELECT student_id, CONCAT(first_name, ' ', last_name) as student_name 
                                 FROM students 
                                 WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) 
                                 AND current_status = 'active'");
            $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($students)) {
                $_SESSION['error_message'] = "No active students found in batch $batch_id";
            } else {
                // Insert attendance records for each student with student_id
                $insertCount = 0;
                foreach ($students as $student) {
                    $stmt = $db->prepare("INSERT INTO course_attendance (course_id, date, batch_id, student_id, student_name, status, camera_status) 
                                         VALUES (?, ?, ?, ?, ?, 'Absent', 'Off')");
                    if ($stmt->execute([$_POST['course_id'] ?? $course_id, $date, $batch_id, $student['student_id'], $student['student_name']])) {
                        $insertCount++;
                    }
                }
                
                $_SESSION['success_message'] = "New attendance created for batch $batch_id on $date with $insertCount active students marked as Absent";
            }
        }
    } catch (PDOException $e) {
        error_log("Database error creating attendance: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error occurred while creating attendance: " . $e->getMessage();
    }
    
    // Redirect to prevent form resubmission
    header("Location: course_attendance.php?batch_id=" . urlencode($batch_id) . "&course_id=" . urlencode($_POST['course_id'] ?? $course_id) . "&date=" . urlencode($date));
    exit();
}

// Handle attendance deletion
if (isset($_POST['delete_attendance'])) {
    $batch_id = $_POST['delete_batch_id'];
    $date = $_POST['delete_date'];
    
    if (empty($batch_id) || empty($date)) {
        $_SESSION['error_message'] = "Batch ID and Date are required for deletion";
    } else {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Delete attendance records
            $stmt = $db->prepare("DELETE FROM course_attendance WHERE batch_id = ? AND date = ? AND course_id = ? AND course_id = ?");
            $stmt->execute([$batch_id, $date, $_POST['course_id'] ?? $course_id]);
            $deletedCount = $stmt->rowCount();
            
            $db->commit();
            
            $_SESSION['success_message'] = "Successfully deleted $deletedCount attendance records for batch $batch_id on $date";
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Database error deleting attendance: " . $e->getMessage());
            $_SESSION['error_message'] = "Database error occurred while deleting attendance: " . $e->getMessage();
        }
    }
    
    header("Location: course_attendance.php?batch_id=" . urlencode($batch_id) . "&course_id=" . urlencode($_POST['course_id'] ?? $course_id));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Tracking - ASD Academy</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        /* Your existing CSS styles remain the same */
        :root {
            --primary: #456882;
            --primary-hover: #234C6A;
            --primary-light: #6A8FA8;
            --navy-dark: #1B3C53;
            --navy-mid: #234C6A;
            --sand: #D2C1B6;
            --sand-dark: #B8A99A;
            --sand-light: #E8DDD8;
            --steel: #456882;
            --steel-light: #6A8FA8;
            --success: #4a9e6e;
            --danger: #c0544a;
            --warning: #c48a3a;
            --gray-100: #f2eeec;
            --gray-200: #e3dcd9;
            --gray-300: #cfc5c0;
            --gray-700: #3a3330;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #eae4e0;
            transition: all 0.3s ease;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 1.75rem;
            margin-bottom: 1.75rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(0, 0, 0, 0.03);
        }
        
        .card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }
        
        .course-header {
            background: linear-gradient(120deg, #1B3C53 0%, #234C6A 45%, #2e5f82 100%);
            color: white;
            box-shadow: 0 6px 28px rgba(27,60,83,0.45);
            position: relative;
            overflow: hidden;
        }
        .course-header::after {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            pointer-events: none;
        }
        .course-header > * { position: relative; z-index: 1; }
        .course-header .icon-wrap {
            background: rgba(255,255,255,0.18);
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .course-header .menu-toggle { color: rgba(255,255,255,0.85); }
        .course-header .menu-toggle:hover { color: white; }
        .course-header .header-sub { color: rgba(255,255,255,0.65); font-size: 0.78rem; font-weight: 500; margin-top: 0.15rem; }
        .btn-back-dark {
            background: rgba(255,255,255,0.16);
            border: 1.5px solid rgba(255,255,255,0.35);
            color: white;
            padding: 0.5rem 1.1rem;
            border-radius: 2rem;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            transition: all 0.2s;
            text-decoration: none;
            backdrop-filter: blur(8px);
        }
        .btn-back-dark:hover { background: rgba(255,255,255,0.28); transform: translateX(-2px); }
        
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            min-width: 70px;
            justify-content: center;
            transition: all 0.2s ease;
            transform-origin: center;
        }
        
        .status-present {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .status-absent {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-late {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #e2e8f0;
            transition: .4s;
            border-radius: 24px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }
        
        input:checked + .slider {
            background-color: var(--success);
        }
        
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        
        .camera-slider input:checked + .slider {
            background-color: var(--primary);
        }

        /* New styles for status slider */
        .status-slider input:checked + .slider {
            background-color: var(--success);
        }
        
        .status-slider input:not(:checked) + .slider {
            background-color: var(--danger);
        }

        .status-label {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            font-weight: 600;
            color: white;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .status-present-label {
            left: 6px;
            opacity: 0;
        }

        .status-absent-label {
            right: 6px;
            opacity: 1;
        }

        .status-slider input:checked + .slider .status-present-label {
            opacity: 1;
        }

        .status-slider input:checked + .slider .status-absent-label {
            opacity: 0;
        }

        .status-slider input:not(:checked) + .slider .status-present-label {
            opacity: 0;
        }

        .status-slider input:not(:checked) + .slider .status-absent-label {
            opacity: 1;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #6A8FA8, #456882);
            color: white;
            border: none;
            border-radius: 0.75rem;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 14px rgba(69,104,130,0.38);
            cursor: pointer;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(69,104,130,0.5);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #D2C1B6, #B8A99A);
            color: #1B3C53;
            border: none;
            border-radius: 0.75rem;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 14px rgba(210,193,182,0.45);
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(210,193,182,0.55);
            background: linear-gradient(135deg, #B8A99A, #a3908a);
        }

        .btn-reports {
            background: linear-gradient(135deg, #234C6A, #1B3C53);
            color: white;
            border: none;
            border-radius: 0.75rem;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 14px rgba(35,76,106,0.4);
            text-decoration: none;
        }

        .btn-reports:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(35,76,106,0.5);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f87171, #ef4444);
            color: white;
            border: none;
            border-radius: 0.75rem;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 14px rgba(239,68,68,0.4);
            cursor: pointer;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239,68,68,0.5);
        }
        
        .btn-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
            border: none;
            border-radius: 0.75rem;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-secondary:hover {
            background-color: var(--gray-300);
            transform: translateY(-1px);
        }
        
        .toggle-buttons {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .toggle-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .toggle-btn.active {
            background-color: var(--primary);
            color: white;
            box-shadow: 0 4px 8px rgba(13, 148, 136, 0.3);
        }
        
        .toggle-btn:not(.active) {
            background-color: var(--gray-100);
            color: var(--gray-700);
        }
        
        .minimal-input {
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            padding: 0.6rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background-color: white;
            width: 100%;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .minimal-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(69, 104, 130, 0.2);
            outline: none;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            animation: fadeIn 0.5s ease-out;
            border-left: 4px solid transparent;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-color: #10b981;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #b91c1b;
            border-color: #ef4444;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(69, 104, 130, 0.2);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            padding: 2rem;
            transform: translateY(-20px);
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }
        
        #successToast {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            background-color: #10b981;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        #successToast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toggle-status-btn {
            display: flex;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--gray-300);
            width: fit-content;
        }

        .toggle-status-btn button {
            padding: 0.5rem 1rem;
            border: none;
            background-color: var(--gray-100);
            color: var(--gray-700);
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .toggle-status-btn button.active {
            background-color: var(--primary);
            color: white;
        }

        .toggle-status-btn button:first-child {
            border-right: 1px solid var(--gray-300);
        }

        .template-info {
            background-color: #f0ebe8;
            border: 1px solid #D2C1B6;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .template-info h4 {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1B3C53;
        }

        .template-info ul {
            list-style-type: disc;
            margin-left: 1.5rem;
            color: #456882;
        }

        .template-info li {
            margin-bottom: 0.25rem;
        }

        /* New styles for disabled camera slider */
        .camera-slider.disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .camera-slider.disabled .slider {
            background-color: #9ca3af !important;
        }

        /* New styles for batch transfer indicator */
        .batch-history {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            color: #92400e;
            margin-top: 0.25rem;
        }

        /* Batch info badge */
        .batch-info-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
        }

        .primary-batch {
            background-color: #d6e4ef;
            color: #1B3C53;
            border: 1px solid #a8c4d8;
        }

        .secondary-batch {
            background-color: #dce7f0;
            color: #234C6A;
            border: 1px solid #b0c8d8;
        }

        .tertiary-batch {
            background-color: #E8DDD8;
            color: #5a3e35;
            border: 1px solid #D2C1B6;
        }

        .quaternary-batch {
            background-color: #d0dce8;
            color: #2e5f82;
            border: 1px solid #9ab5cc;
        }

        /* Delete confirmation modal styles */
        .delete-confirmation {
            text-align: center;
        }

        .delete-confirmation i {
            font-size: 3rem;
            color: #ef4444;
            margin-bottom: 1rem;
        }

        .delete-confirmation h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
        }

        .delete-confirmation p {
            color: #6b7280;
            margin-bottom: 1.5rem;
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        /* Export dropdown styles */
        .relative {
            position: relative;
        }
        
        .inline-block {
            display: inline-block;
        }
        
        .hidden {
            display: none;
        }
        
        .export-dropdown {
            position: absolute;
            right: 0;
            margin-top: 0.5rem;
            width: 16rem;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            z-index: 50;
            border: 1px solid var(--gray-200);
        }
        
        .export-dropdown a, .export-dropdown button {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.875rem;
            color: var(--gray-700);
            transition: all 0.2s ease;
            border: none;
            background: none;
            cursor: pointer;
        }
        
        .export-dropdown a:hover, .export-dropdown button:hover {
            background-color: #E8DDD8;
            color: #1B3C53;
        }
        
        .export-dropdown i {
            margin-right: 0.5rem;
            width: 1.25rem;
        }
        
        .export-dropdown hr {
            margin: 0.5rem 0;
            border: 0;
            border-top: 1px solid #D2C1B6;
        }
        
        /* DataTable export button */
        #dtExportBtn {
            margin-left: 0.5rem;
            padding: 0.4rem 1rem;
            font-size: 0.875rem;
        }
        
        /* Grid layout for filters */
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 1rem;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .export-dropdown {
                width: 100%;
                position: static;
                margin-top: 0.5rem;
                box-shadow: none;
                border: 1px solid var(--gray-200);
            }
        }
        /* Context banner (batch / course summary strip) */
        .context-banner {
            background: linear-gradient(135deg, #e8ddd8, #dfd4ce);
            border: 1px solid #D2C1B6;
            border-radius: 0.75rem;
            box-shadow: 0 2px 10px rgba(69,104,130,0.1);
        }
        .context-banner h2 { color: #1B3C53; }
        .context-banner p { color: #234C6A; }

        /* ─── FILTER CARD (matches attendance_reports.php) ─── */
        .filter-card {
            background: white;
            border-radius: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 24px rgba(27,60,83,0.12);
            border: 1px solid #D2C1B6;
        }
        .filter-card-header {
            background: linear-gradient(135deg, #234C6A, #1B3C53);
            padding: 0.85rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border-radius: 1.25rem 1.25rem 0 0;
        }
        .filter-card-body { padding: 1.25rem 1.5rem 1.5rem; }

        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: #234C6A;
            margin-bottom: 0.4rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
        }

        /* ─── TABLE CARD HEADER (matches attendance_reports.php) ─── */
        .table-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #D2C1B6;
        }
        .table-card-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #1B3C53;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .title-pill {
            background: linear-gradient(135deg, #E8DDD8, #D2C1B6);
            color: #234C6A;
            width: 2rem; height: 2rem;
            border-radius: 0.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* ─── STAT CARDS (matches attendance_reports.php) ─── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            border-radius: 1.25rem;
            padding: 1.4rem 1.3rem 1.2rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.22s, box-shadow 0.22s;
            color: white;
        }
        .stat-card:hover { transform: translateY(-5px) scale(1.02); box-shadow: 0 16px 40px rgba(0,0,0,0.18); }
        .stat-card::after {
            content: '';
            position: absolute;
            right: -20px; bottom: -20px;
            width: 100px; height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.12);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            right: 20px; bottom: 30px;
            width: 60px; height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }
        .stat-card.blue   { background: linear-gradient(135deg, #456882 0%, #234C6A 100%); box-shadow: 0 8px 24px rgba(69,104,130,0.28); }
        .stat-card.green  { background: linear-gradient(135deg, #6A8FA8 0%, #456882 100%); box-shadow: 0 8px 24px rgba(106,143,168,0.28); }
        .stat-card.amber  { background: linear-gradient(135deg, #D2C1B6 0%, #B8A99A 100%); box-shadow: 0 8px 24px rgba(210,193,182,0.35); color: #1B3C53; }
        .stat-card.red    { background: linear-gradient(135deg, #2e5f82 0%, #1B3C53 100%); box-shadow: 0 8px 24px rgba(27,60,83,0.32); }
        .stat-icon {
            width: 2.8rem;
            height: 2.8rem;
            border-radius: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            background: rgba(255,255,255,0.22);
            backdrop-filter: blur(4px);
        }
        .stat-label { font-size: 0.72rem; font-weight: 600; opacity: 0.9; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.06em; text-shadow: 0 1px 3px rgba(0,0,0,0.15); position: relative; z-index: 1; }
        .stat-value { font-size: 2rem; font-weight: 900; line-height: 1; position: relative; z-index: 1; text-shadow: 0 2px 6px rgba(0,0,0,0.12); }

        /* DataTables theming to match dashboard tables */
        table.dataTable thead tr th,
        #attendanceTable.dataTable thead th {
            background: linear-gradient(135deg, #234C6A, #1B3C53) !important;
            color: #ffffff !important;
            font-weight: 700;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            padding: 0.85rem 1rem;
            border-bottom: none !important;
        }
        table.dataTable thead tr th:first-child { border-radius: 0.6rem 0 0 0; }
        table.dataTable thead tr th:last-child  { border-radius: 0 0.6rem 0 0; }
        table.dataTable thead .sorting:before,
        table.dataTable thead .sorting:after,
        table.dataTable thead .sorting_asc:before,
        table.dataTable thead .sorting_asc:after,
        table.dataTable thead .sorting_desc:before,
        table.dataTable thead .sorting_desc:after {
            color: #ffffff !important;
            opacity: 0.5;
        }
        table.dataTable tbody tr:nth-child(even) td { background: #f0ebe8; }
        table.dataTable tbody tr td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #D2C1B6;
            font-size: 0.87rem;
            vertical-align: middle;
        }
        table.dataTable tbody tr:hover td {
            background: linear-gradient(135deg, #e8ddd8, #dfd4ce) !important;
        }
        .dataTables_wrapper .dataTables_filter input {
            border: 2px solid #D2C1B6;
            border-radius: 2rem;
            padding: 0.35rem 0.9rem;
            font-size: 0.83rem;
            outline: none;
            margin-left: 0.4rem;
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #456882;
            box-shadow: 0 0 0 3px rgba(69,104,130,0.18);
        }
        .dataTables_wrapper .dataTables_length select {
            border: 2px solid #D2C1B6;
            border-radius: 0.5rem;
            padding: 0.25rem 0.5rem;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: linear-gradient(135deg, #456882, #234C6A) !important;
            border-color: #456882 !important;
            color: white !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #E8DDD8 !important;
            color: #234C6A !important;
            border-color: #D2C1B6 !important;
        }
    </style>
</head>
<body class="text-gray-800">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300">
                <!-- Header -->
        <header class="course-header px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center">
                <button class="menu-toggle md:hidden text-xl transition-colors mr-4" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="icon-wrap mr-3"><i class="fas fa-clipboard-check"></i></span>
                <div>
                    <h1 class="text-xl font-bold text-white">
                        <?= htmlspecialchars($batch_name_display) ?> <span class="font-normal text-white/70">|</span> Course Attendance
                    </h1>
                    <p class="header-sub">Marking attendance for <?= htmlspecialchars($course_name) ?></p>
                </div>
            </div>
            <div>
                <a href="../batch/batch_course_view.php?batch_id=<?= urlencode($preselected_batch) ?>&course_id=<?= urlencode($course_id) ?>" class="btn-back-dark">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Course
                </a>
            </div>
        </header>

        <div class="p-4 md:p-6">
            <!-- Display error/success messages -->
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error mb-4">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($_SESSION['error_message']); ?>
                    <?php unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success mb-4">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($_SESSION['success_message']); ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

                        

            <!-- Context Banner -->
            <div class="context-banner p-4 mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold"><i class="fas fa-users mr-2"></i><?= htmlspecialchars($batch_name_display) ?></h2>
                    <p class="text-sm mt-1">Marking attendance for course: <span class="font-semibold"><?= htmlspecialchars($course_name) ?></span></p>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-label">Total Students</div>
                    <div class="stat-value" id="statTotalStudents">—</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-label">Present</div>
                    <div class="stat-value" id="statPresentCount">—</div>
                </div>
                <div class="stat-card red">
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-label">Absent</div>
                    <div class="stat-value" id="statAbsentCount">—</div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-icon"><i class="fas fa-percent"></i></div>
                    <div class="stat-label">Attendance %</div>
                    <div class="stat-value" id="statAttendancePercent">—</div>
                </div>
            </div>
            
            <!-- Manual Attendance Section -->
            <div id="manualAttendanceSection">
                <!-- Filters Card -->
                <div class="filter-card" style="overflow: visible; position: relative; z-index: 50;">
                    <div class="filter-card-header">
                        <i class="fas fa-sliders-h"></i> Attendance Filters
                    </div>
                    <div class="filter-card-body" style="overflow: visible;">
                    <div class="filters-grid" style="overflow: visible !important;">
                                                <input type="hidden" id="batchFilter" value="<?= htmlspecialchars($preselected_batch) ?>">
                        <div>
                            <label class="field-label">Course</label>
                            <select id="courseFilter" class="minimal-input" onchange="window.location.href='course_attendance.php?batch_id=<?= urlencode($preselected_batch) ?>&course_id=' + this.value + '&date=' + document.getElementById('dateFilter').value">
                                <option value="">-- Select Course --</option>
                                <?php foreach ($batch_courses as $bc): ?>
                                <option value="<?= htmlspecialchars($bc['id']) ?>" 
                                    <?= ($course_id == $bc['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($bc['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="field-label">Date</label>
                            <input type="text" id="dateFilter" class="minimal-input date-picker" placeholder="Select date" value="<?= $preselected_date ?>">
                        </div>
                        
                        <button id="markAllPresent" class="btn-primary">
                            <i class="fas fa-check-circle mr-2"></i> Mark All Present
                        </button>
                        
                        <button id="loadAttendance" class="btn-primary">
                            <i class="fas fa-sync-alt mr-2"></i> Load Attendance
                        </button>
                        
                        <!-- Export Dropdown Button -->
                        <div class="relative inline-block">
                            <button id="exportDropdownBtn" class="btn-success w-full" onclick="toggleExportDropdown()">
                                <i class="fas fa-download mr-2"></i> Export <i class="fas fa-caret-down ml-1"></i>
                            </button>
                            <div id="exportDropdown" class="export-dropdown hidden" style="position: absolute; top: calc(100% + 5px); right: 0; z-index: 9999; min-width: 260px; background: white; border: 1px solid #cbd5e1; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);">
                                <a href="daily_attendance_export.php">
                                    <i class="fas fa-calendar-day" style="color:#456882;"></i> Daily Export Page
                                </a>
                                <button onclick="quickExportCurrent()">
                                    <i class="fas fa-file-excel" style="color:#456882;"></i> Export Current View (Excel)
                                </button>
                                <button onclick="exportToCSV()">
                                    <i class="fas fa-file-csv" style="color:#234C6A;"></i> Export Current View (CSV)
                                </button>
                                <hr>
                                <button onclick="exportAllBatchesToday()">
                                    <i class="fas fa-calendar-check" style="color:#1B3C53;"></i> All Batches - Today
                                </button>
                                <button onclick="exportWithDetails()">
                                    <i class="fas fa-info-circle" style="color:#B8A99A;"></i> Export with Student Details
                                </button>
                            </div>
                        </div>

                        <!-- Reports Button -->
                        <a href="attendance_reports.php?batch_id=<?= urlencode($preselected_batch) ?>&course_id=<?= urlencode($course_id) ?>" class="btn-reports">
                            <i class="fas fa-chart-bar mr-2"></i> Reports
                        </a>
                    </div>
                    </div>
                </div>
                
                <!-- Attendance Table Card -->
                <div class="card">
                    <div class="table-card-header">
                        <div class="table-card-title">
                            <span class="title-pill"><i class="fas fa-clipboard-list"></i></span> Daily Attendance Records
                        </div>
                    </div>
                    <div id="attendanceError" class="alert alert-error mb-4" style="display: none;">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span id="errorMessage">Error loading attendance data</span>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table id="attendanceTable" class="display" style="width:100%">
                            <thead>
                                <tr>
                                                                        <th>Date</th>
                                    <th>Batch Name</th>
                                    <th>Course Name</th>
                                    <th>Student Name</th>
                                    <th>Status</th>
                                    <th>Camera</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data will be loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="flex justify-end mt-4">
                        <button id="saveAttendance" class="btn-primary">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Upload Excel Section (initially hidden) -->
            <div id="uploadExcelSection" style="display: none;">
                <div class="card">
                    <h2 class="text-xl font-bold mb-4">Upload Excel File</h2>
                    <form action="course_attendance.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label for="excel_file" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Excel File
                            </label>
                            <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls" class="minimal-input" required>
                        </div>
                        
                        <!-- Template Information -->
                        <div class="template-info">
                            <h4><i class="fas fa-info-circle mr-2" style="color:#0d9488;"></i>Excel Template Requirements</h4>
                            <ul>
                                <li>File format must be .xlsx or .xls</li>
                                <li>Required columns: student_id, date, status</li>
                                <li>Optional columns: batch_id, student_name, camera_status, remarks</li>
                                <li>Date format: YYYY-MM-DD (e.g., 2024-01-15)</li>
                                <li>Status values: 'Present' or 'Absent' only</li>
                                <li>Camera status values: 'On' or 'Off'</li>
                                <li>First row should contain column headers</li>
                                <li><strong>Note:</strong> Camera status will be automatically set to 'Off' if status is not 'Present'</li>
                            </ul>
                            <div class="mt-3">
                                <a href="javascript:void(0)" onclick="downloadTemplate()" class="text-sm font-medium" style="color:#0d9488;">
                                    <i class="fas fa-download mr-1"></i> Download Excel Template
                                </a>
                            </div>
                        </div>
                        
                        <button type="submit" name="import" class="btn-primary mt-4">
                            <i class="fas fa-upload mr-2"></i> Upload Attendance
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmationModal" class="modal-overlay">
        <div class="modal-content">
            <div class="delete-confirmation">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Confirm Deletion</h3>
                <p>Are you sure you want to delete attendance records for batch <span id="confirmBatch"></span> on <span id="confirmDate"></span>?</p>
                <p class="text-red-600 font-medium">This action cannot be undone!</p>
                <div class="modal-buttons">
                    <button type="button" id="cancelDeleteBtn" class="btn-primary">
                        <i class="fas fa-times mr-2"></i> Cancel
                    </button>
                    <button type="button" id="confirmDeleteBtn" class="btn-danger">
                        <i class="fas fa-trash-alt mr-2"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Toast -->
    <div id="successToast" class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg">
        <div class="flex items-center">
            <i class="fas fa-check-circle mr-2"></i>
            <span id="toastMessage">Operation completed successfully!</span>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    $(document).ready(function() {
        let currentDate = "<?= $preselected_date ?>";
        
        // Initialize date pickers
        flatpickr("#dateFilter", {
            dateFormat: "Y-m-d",
            defaultDate: currentDate,
            maxDate: "today",
            disable: [function(date) { return (date.getDay() === 0); }],
            onChange: function(selectedDates, dateStr, instance) {
                if (dateStr !== currentDate) {
                    currentDate = dateStr;
                    // Update URL without reloading the page
                    window.history.pushState('', '', 'course_attendance.php?batch_id=' + $('#batchFilter').val() + '&course_id=' + $('#courseFilter').val() + '&date=' + dateStr);
                    loadAttendanceData();
                }
            }
        });
        
        flatpickr("#createDate", {
            dateFormat: "Y-m-d",
            defaultDate: "today",
            maxDate: "today"
        , disable: [function(date) { return (date.getDay() === 0); }]});

        flatpickr("#deleteDate", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        , disable: [function(date) { return (date.getDay() === 0); }]});

        // Function to update camera slider state based on status
        function updateCameraState(statusToggle, cameraToggle) {
            const isPresent = statusToggle.is(':checked');
            const cameraSlider = cameraToggle.closest('.camera-slider');
            
            if (!isPresent) {
                // If status is not present, force camera to off and disable it
                cameraToggle.prop('checked', false);
                cameraToggle.prop('disabled', true);
                cameraSlider.addClass('disabled');
            } else {
                // If status is present, enable camera slider
                cameraToggle.prop('disabled', false);
                cameraSlider.removeClass('disabled');
            }
        }

        // Show/hide loading modal
        function showLoading() {
            $('#loadingModal').addClass('active');
        }
        
        function hideLoading() {
            $('#loadingModal').removeClass('active');
        }

        // Show/hide delete confirmation modal
        function showDeleteConfirmation(batchId, date) {
            $('#confirmBatch').text(batchId);
            $('#confirmDate').text(date);
            $('#deleteConfirmationModal').addClass('active');
        }
        
        function hideDeleteConfirmation() {
            $('#deleteConfirmationModal').removeClass('active');
        }

        // Show toast message
        function showToast(message, isSuccess = true) {
            const toast = $('#successToast');
            const icon = toast.find('i');
            const messageSpan = $('#toastMessage');
            
            if (isSuccess) {
                toast.removeClass('bg-red-500').addClass('bg-green-500');
                icon.removeClass('fa-exclamation-circle').addClass('fa-check-circle');
            } else {
                toast.removeClass('bg-green-500').addClass('bg-red-500');
                icon.removeClass('fa-check-circle').addClass('fa-exclamation-circle');
            }
            
            messageSpan.text(message);
            toast.addClass('show');
            
            setTimeout(() => {
                toast.removeClass('show');
            }, 3000);
        }

        // Show error message
        function showError(message) {
            $('#errorMessage').text(message);
            $('#attendanceError').show();
        }

        function hideError() {
            $('#attendanceError').hide();
        }

        // Section toggle functionality
        $('#showManualBtn').click(function() {
            $('.toggle-btn').removeClass('active');
            $(this).addClass('active');
            $('#uploadExcelSection').hide();
            $('#manualAttendanceSection').show();
        });
        
        $('#showUploadBtn').click(function() {
            $('.toggle-btn').removeClass('active');
            $(this).addClass('active');
            $('#manualAttendanceSection').hide();
            $('#uploadExcelSection').show();
        });

        // Initialize DataTable for attendance
        const attendanceTable = $('#attendanceTable').DataTable({
            responsive: true,
            searching: true,
            ordering: true,
            lengthChange: true,
            pageLength: 10,
                        columns: [
                { data: 'date' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return '<?= htmlspecialchars($batch_name_display) ?>';
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return '<?= htmlspecialchars($course_name) ?>';
                    }
                },
                { data: 'student_name' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        const isPresent = row.status === 'Present';
                        return `
                            <label class="switch status-slider">
                                <input type="checkbox" class="status-toggle" data-id="${row.id || ''}" 
                                    data-student-id="${row.student_id}" data-student-name="${row.student_name}"
                                    ${isPresent ? 'checked' : ''}>
                                <span class="slider">
                                    <span class="status-label status-present-label">P</span>
                                    <span class="status-label status-absent-label">A</span>
                                </span>
                            </label>
                        `;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        const isPresent = row.status === 'Present';
                        const isCameraOn = row.camera_status === 'On';
                        const disabledClass = !isPresent ? 'disabled' : '';
                        
                        return `
                            <label class="switch camera-slider ${disabledClass}">
                                <input type="checkbox" class="camera-toggle" data-id="${row.id}" 
                                    ${isCameraOn ? 'checked' : ''} 
                                    ${!isPresent ? 'disabled' : ''}>
                                <span class="slider"></span>
                            </label>
                        `;
                    }
                },
                {
                    data: null,
                    render: function(data, type, row) {
                        return `<input type="text" class="remarks-input minimal-input" style="min-width: 150px; padding: 0.3rem; font-size: 0.85rem;" placeholder="Remarks" value="${row.remarks || ''}">`;
                    }
                }
            ],
            drawCallback: function() { }
        });

        // Event delegation for status toggle
        $(document).on('change', '.status-toggle', function() {
            const toggle = $(this);
            const isPresent = toggle.is(':checked');
            const row = toggle.closest('tr');
            const cameraToggle = row.find('.camera-toggle');
            const cameraSlider = cameraToggle.closest('.camera-slider');
            
            if (!isPresent) {
                cameraToggle.prop('checked', false);
                cameraToggle.prop('disabled', true);
                cameraSlider.addClass('disabled');
            } else {
                cameraToggle.prop('disabled', false);
                cameraSlider.removeClass('disabled');
            }
        });

        // Initialize camera states when table is loaded
        $(document).on('draw.dt', function() {
            $('.status-toggle').each(function() {
                const toggle = $(this);
                const row = toggle.closest('tr');
                const cameraToggle = row.find('.camera-toggle');
                
                const isPresent = toggle.is(':checked');
                const cameraSlider = cameraToggle.closest('.camera-slider');
                
                if (!isPresent) {
                    cameraToggle.prop('checked', false);
                    cameraToggle.prop('disabled', true);
                    cameraSlider.addClass('disabled');
                } else {
                    cameraToggle.prop('disabled', false);
                    cameraSlider.removeClass('disabled');
                }
            });
        });

        // Update the summary stat cards from loaded attendance data
        function updateAttendanceStats(data) {
            const total = data.length;
            const present = data.filter(row => row.status === 'Present').length;
            const absent = total - present;
            const pct = total > 0 ? ((present / total) * 100).toFixed(1) : '0.0';

            $('#statTotalStudents').text(total);
            $('#statPresentCount').text(present);
            $('#statAbsentCount').text(absent);
            $('#statAttendancePercent').text(pct + '%');
        }

        // Load attendance data
        function loadAttendanceData() {
            const batchId = $('#batchFilter').val();
            const courseId = $('#courseFilter').val();
            const date = $('#dateFilter').val();
            
            if (!date) {
                showError('Please select a date');
                return;
            }
            
            showLoading();
            hideError();
            
            $.ajax({
                url: 'course_attendance_api.php',
                type: 'GET',
                data: { 
                    action: 'fetch',
                    batch_id: batchId,
                    date: date,
                    course_id: courseId
                },
                success: function(response) {
                    hideLoading();
                    
                    try {
                        if (response.success) {
                            attendanceTable.clear().rows.add(response.data).draw();
                            updateAttendanceStats(response.data);
                            showToast('Attendance data loaded successfully');
                        } else {
                            showError(response.message || 'Failed to load attendance data');
                        }
                    } catch (e) {
                        console.error('Error processing response:', e);
                        showError('Error processing attendance data');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    console.error('AJAX Error:', error);
                    showError('Network error occurred while loading attendance data');
                }
            });
        }

        // Load attendance on page load if date is preselected
        if ($('#dateFilter').val()) {
            loadAttendanceData();
        }

        // Load attendance button click
        $('#loadAttendance').click(loadAttendanceData);

        // Mark all present button
        $('#markAllPresent').click(function() {
            // Mark all as present using the new toggle UI
            $('.status-toggle').prop('checked', true).trigger('change');
            const allToggles = attendanceTable.rows().nodes().to$().find('.status-toggle');
            const total = allToggles.length;
            const present = allToggles.filter(':checked').length;
            const absent = total - present;
            const pct = total > 0 ? ((present / total) * 100).toFixed(1) : '0.0';
            $('#statTotalStudents').text(total);
            $('#statPresentCount').text(present);
            $('#statAbsentCount').text(absent);
            $('#statAttendancePercent').text(pct + '%');
            showToast('All students marked as present with camera on');
        });

        function saveAttendanceData(callback = null) {
            const changes = [];
            const batchId = $('#batchFilter').val();
            const courseId = $('#courseFilter').val();
            const date = currentDate; // Use currentDate to save the state of the table before date switch!
            
            $('#attendanceTable tbody tr').each(function() {
                const row = $(this);
                const statusToggle = row.find('.status-toggle');
                if (statusToggle.length === 0) return;
                
                const id = statusToggle.data('id');
                const studentId = statusToggle.data('student-id');
                const studentName = statusToggle.data('student-name');
                const status = statusToggle.is(':checked') ? 'Present' : 'Absent';
                const cameraStatus = row.find('.camera-toggle').is(':checked') ? 'On' : 'Off';
                const remarks = row.find('.remarks-input').val() || '';
                
                changes.push({
                    id: id,
                    student_id: studentId,
                    student_name: studentName,
                    batch_id: batchId,
                    course_id: courseId,
                    date: date,
                    status: status,
                    camera_status: cameraStatus,
                    remarks: remarks
                });
            });
            
            if (changes.length === 0) {
                if (callback) callback();
                return;
            }
            
            showLoading();
            
            $.ajax({
                url: 'course_attendance_api.php',
                type: 'POST',
                data: {
                    action: 'update',
                    changes: JSON.stringify(changes)
                },
                success: function(response) {
                    if (response.success) {
                        showToast(callback ? 'Attendance auto-saved' : 'Attendance updated successfully');
                    } else {
                        showToast(response.message || 'Failed to update attendance', false);
                    }
                    if (callback) {
                        callback();
                    } else {
                        hideLoading();
                        loadAttendanceData(); // Refresh to update row IDs
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    showToast('Network error occurred while saving attendance', false);
                    if (callback) {
                        callback();
                    } else {
                        hideLoading();
                    }
                }
            });
        }

        // Save attendance changes
        $('#saveAttendance').click(function() {
            saveAttendanceData();
        });

        // Delete attendance confirmation
        $('#deleteConfirmBtn').click(function() {
            const batchId = $('#deleteBatch').val();
            const date = $('#deleteDate').val();
            
            if (!batchId || !date) {
                showToast('Please select both batch and date', false);
                return;
            }
            
            showDeleteConfirmation(batchId, date);
        });

        // Cancel delete
        $('#cancelDeleteBtn').click(function() {
            hideDeleteConfirmation();
        });

        // Confirm delete
        $('#confirmDeleteBtn').click(function() {
            hideDeleteConfirmation();
            showLoading();
            
            // Submit the form
            $('#deleteAttendanceForm').find('input[type="submit"]').click();
        });

        // Close export dropdown when clicking outside
        $(document).click(function(event) {
            if (!$(event.target).closest('#exportDropdownBtn, #exportDropdown').length) {
                $('#exportDropdown').addClass('hidden');
            }
        });
    });

    // Export dropdown toggle
    function toggleExportDropdown() {
        $('#exportDropdown').toggleClass('hidden');
    }

    // Quick export current view to Excel
    function quickExportCurrent() {
        const batchId = $('#batchFilter').val();
            const courseId = $('#courseFilter').val();
            const date = $('#dateFilter').val();
        
        if (!date) {
            showToast('Please select a date first', false);
            return;
        }
        
        window.location.href = 'daily_attendance_export.php?date=' + encodeURIComponent(date) + 
                              '&batch_id=' + encodeURIComponent(batchId) + 
                              '&format=excel&export=true';
    }

    // Export current view as CSV
    function exportToCSV() {
        const batchId = $('#batchFilter').val();
            const courseId = $('#courseFilter').val();
            const date = $('#dateFilter').val();
        
        if (!date) {
            showToast('Please select a date first', false);
            return;
        }
        
        window.location.href = 'daily_attendance_export.php?date=' + encodeURIComponent(date) + 
                              '&batch_id=' + encodeURIComponent(batchId) + 
                              '&format=csv&export=true';
    }

    // Export all batches for today
    function exportAllBatchesToday() {
        const today = new Date().toISOString().split('T')[0];
        window.location.href = 'daily_attendance_export.php?date=' + today + 
                              '&batch_id=&format=excel&export=true';
    }

    // Export with student details (Excel format)
    function exportWithDetails() {
        const batchId = $('#batchFilter').val();
            const courseId = $('#courseFilter').val();
            const date = $('#dateFilter').val();
        
        if (!date) {
            showToast('Please select a date first', false);
            return;
        }
        
        window.location.href = 'daily_attendance_export.php?date=' + encodeURIComponent(date) + 
                              '&batch_id=' + encodeURIComponent(batchId) + 
                              '&format=excel&export=true';
    }

    // Export current DataTable data as CSV
    function exportCurrentDataTable() {
        const table = $('#attendanceTable').DataTable();
        const data = table.rows().data().toArray();
        
        if (data.length === 0) {
            showToast('No data to export', false);
            return;
        }
        
        // Create CSV from DataTable data
        let csv = 'Student ID,Student Name,Batch ID,Date,Status,Camera Status,Remarks\n';
        
        data.forEach(row => {
            // Escape commas in text fields
            const studentName = row.student_name ? row.student_name.replace(/,/g, ' ') : '';
            const remarks = row.remarks ? row.remarks.replace(/,/g, ' ') : '';
            
            csv += `${row.student_id},${studentName},${row.batch_id},${row.date},${row.status},${row.camera_status},${remarks}\n`;
        });
        
        // Download CSV
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `attendance_${$('#dateFilter').val()}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        showToast('Table exported successfully');
    }

    // Download Excel template
    function downloadTemplate() {
        // Create a simple Excel template download
        const templateData = [
            ['student_id', 'date', 'status', 'batch_id', 'student_name', 'camera_status', 'remarks'],
            ['STU001', '2024-01-15', 'Present', 'BATCH001', 'John Doe', 'On', 'On time'],
            ['STU002', '2024-01-15', 'Absent', 'BATCH001', 'Jane Smith', 'Off', 'Sick leave'],
            ['STU003', '2024-01-15', 'Present', 'BATCH001', 'Bob Johnson', 'On', '']
        ];
        
        let csvContent = "data:text/csv;charset=utf-8,";
        templateData.forEach(row => {
            csvContent += row.join(",") + "\r\n";
        });
        
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "attendance_template.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Show toast message (define if not already defined)
    window.showToast = function(message, isSuccess = true) {
        const toast = $('#successToast');
        const icon = toast.find('i');
        const messageSpan = $('#toastMessage');
        
        if (isSuccess) {
            toast.removeClass('bg-red-500').addClass('bg-green-500');
            icon.removeClass('fa-exclamation-circle').addClass('fa-check-circle');
        } else {
            toast.removeClass('bg-green-500').addClass('bg-red-500');
            icon.removeClass('fa-check-circle').addClass('fa-exclamation-circle');
        }
        
        messageSpan.text(message);
        toast.addClass('show');
        
        setTimeout(() => {
            toast.removeClass('show');
        }, 3000);
    };
    </script>
</body>
</html>