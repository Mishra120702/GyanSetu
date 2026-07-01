<?php
require_once '../db_connection.php';
session_start();

// Check if user is logged in and is a trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit();
}

$exam_id = isset($_GET['exam_id']) ? $_GET['exam_id'] : '';
$student_id_filter = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$message = '';
$error = '';

// Get trainer details
$trainer_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT t.* FROM trainers t WHERE t.user_id = ?");
$stmt->execute([$trainer_id]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

// Get exam details
$exam = null;
if (!empty($exam_id)) {
    $stmt = $db->prepare("
        SELECT e.*, b.batch_name, b.batch_mentor_id,
               (SELECT COUNT(*) FROM batch_courses bc WHERE bc.batch_id = b.batch_id AND bc.trainer_id IN (?, ?)) as is_course_trainer
        FROM exams e 
        JOIN batches b ON e.batch_id = b.batch_id 
        WHERE e.exam_id = ?
    ");
    $stmt->execute([$trainer['id'], $trainer_id, $exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if trainer is assigned to this batch
    if ($exam && $exam['batch_mentor_id'] != $trainer['id'] && $exam['batch_mentor_id'] != $trainer_id && $exam['is_course_trainer'] == 0) {
        $error = "You are not authorized to upload results for this exam.";
        $exam = null;
    }
}

if (!$exam) {
    header("Location: trainer_exams.php");
    exit();
}

// Get exam components
$exam_components = !empty($exam['exam_components']) ? explode(',', $exam['exam_components']) : [];

// Get ALL students in this batch with their existing results
$students = $db->prepare("
    SELECT 
        s.student_id, 
        s.first_name, 
        s.last_name,
        s.current_status,
        er.obtained_marks as existing_marks,
        er.grade as existing_grade,
        er.remarks as existing_remarks,
        er.mcq_marks as existing_mcq,
        er.project_marks as existing_project,
        er.viva_marks as existing_viva,
        er.uploaded_at as last_uploaded
    FROM students s
    LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
    WHERE s.batch_name = ? OR s.batch_name_2 = ? OR s.batch_name_3 = ? OR s.batch_name_4 = ?
    ORDER BY s.current_status DESC, s.first_name, s.last_name
");
$students->execute([$exam_id, $exam['batch_id'], $exam['batch_id'], $exam['batch_id'], $exam['batch_id']]);
$students = $students->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_students = count($students);
$active_students = 0;
$results_uploaded = 0;
$inactive_students = 0;

foreach ($students as $student) {
    if ($student['current_status'] == 'active') {
        $active_students++;
        if (!is_null($student['existing_marks'])) {
            $results_uploaded++;
        }
    } else {
        $inactive_students++;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Single student upload
    if (isset($_POST['upload_single'])) {
        $student_id = $_POST['student_id'];
        $obtained_marks = floatval($_POST['obtained_marks']);
        $remarks = trim($_POST['remarks']);
        
        // Validate marks
        if ($obtained_marks < 0 || $obtained_marks > $exam['total_marks']) {
            $error = "Marks must be between 0 and " . $exam['total_marks'];
        } else {
            
            // Get component marks
            $mcq_marks = isset($_POST['mcq_marks']) && $_POST['mcq_marks'] !== '' ? floatval($_POST['mcq_marks']) : null;
            $project_marks = isset($_POST['project_marks']) && $_POST['project_marks'] !== '' ? floatval($_POST['project_marks']) : null;
            $viva_marks = isset($_POST['viva_marks']) && $_POST['viva_marks'] !== '' ? floatval($_POST['viva_marks']) : null;
            
            // Validate component marks if provided
            $component_valid = true;
            if ($mcq_marks !== null && ($mcq_marks < 0 || $mcq_marks > ($exam['mcq_marks'] ?? 0))) {
                $error = "MCQ marks must be between 0 and " . ($exam['mcq_marks'] ?? 0);
                $component_valid = false;
            } elseif ($project_marks !== null && ($project_marks < 0 || $project_marks > ($exam['project_marks'] ?? 0))) {
                $error = "Project marks must be between 0 and " . ($exam['project_marks'] ?? 0);
                $component_valid = false;
            } elseif ($viva_marks !== null && ($viva_marks < 0 || $viva_marks > ($exam['viva_marks'] ?? 0))) {
                $error = "Viva marks must be between 0 and " . ($exam['viva_marks'] ?? 0);
                $component_valid = false;
            }
            
            // Verify total matches components if all components provided
            if ($component_valid && !empty($exam_components)) {
                $component_total = 0;
                $components_provided = 0;
                
                if (in_array('mcq', $exam_components) && $mcq_marks !== null) {
                    $component_total += $mcq_marks;
                    $components_provided++;
                }
                if (in_array('project', $exam_components) && $project_marks !== null) {
                    $component_total += $project_marks;
                    $components_provided++;
                }
                if (in_array('viva', $exam_components) && $viva_marks !== null) {
                    $component_total += $viva_marks;
                    $components_provided++;
                }
                
                // If all components are provided, check if they match total
                if ($components_provided == count($exam_components) && abs($component_total - $obtained_marks) > 0.01) {
                    $error = "Component marks total ($component_total) does not match obtained marks ($obtained_marks)";
                    $component_valid = false;
                }
            }
            
            if ($component_valid) {
                // Calculate grade
                $grade = calculateGrade($obtained_marks, $exam['total_marks']);
                
                // Check if result already exists
                $check_stmt = $db->prepare("SELECT result_id FROM exam_results WHERE exam_id = ? AND student_id = ?");
                $check_stmt->execute([$exam_id, $student_id]);
                $existing_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                try {
                    $db->beginTransaction();
                    
                    if ($existing_result) {
                        // Update existing result
                        $stmt = $db->prepare("
                            UPDATE exam_results 
                            SET obtained_marks = ?, grade = ?, remarks = ?, uploaded_by = ?, 
                                mcq_marks = ?, project_marks = ?, viva_marks = ?, uploaded_at = NOW() 
                            WHERE exam_id = ? AND student_id = ?
                        ");
                        $success = $stmt->execute([
                            $obtained_marks, $grade, $remarks, $trainer['id'], 
                            $mcq_marks, $project_marks, $viva_marks, 
                            $exam_id, $student_id
                        ]);
                        $action = "updated";
                    } else {
                        // Insert new result
                        $stmt = $db->prepare("
                            INSERT INTO exam_results 
                            (exam_id, student_id, obtained_marks, grade, remarks, uploaded_by, mcq_marks, project_marks, viva_marks) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $success = $stmt->execute([
                            $exam_id, $student_id, $obtained_marks, $grade, $remarks, 
                            $trainer['id'], $mcq_marks, $project_marks, $viva_marks
                        ]);
                        $action = "uploaded";
                    }
                    
                    if ($success) {
                        $db->commit();
                        
                        // Get student name for message
                        $student_name = "";
                        foreach ($students as $s) {
                            if ($s['student_id'] == $student_id) {
                                $student_name = $s['first_name'] . ' ' . $s['last_name'];
                                break;
                            }
                        }
                        
                        $message = "Results $action successfully for <strong>$student_name</strong> (Grade: $grade)";
                        
                        // Refresh student data
                        $students = $db->prepare("
                            SELECT 
                                s.student_id, 
                                s.first_name, 
                                s.last_name,
                                s.current_status,
                                er.obtained_marks as existing_marks,
                                er.grade as existing_grade,
                                er.remarks as existing_remarks,
                                er.mcq_marks as existing_mcq,
                                er.project_marks as existing_project,
                                er.viva_marks as existing_viva,
                                er.uploaded_at as last_uploaded
                            FROM students s
                            LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
                            WHERE s.batch_name = ?
                            ORDER BY s.current_status DESC, s.first_name, s.last_name
                        ");
                        $students->execute([$exam_id, $exam['batch_id']]);
                        $students = $students->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Recalculate stats
                        $results_uploaded = 0;
                        foreach ($students as $student) {
                            if ($student['current_status'] == 'active' && !is_null($student['existing_marks'])) {
                                $results_uploaded++;
                            }
                        }
                        
                    } else {
                        $db->rollBack();
                        $error = "Failed to upload results. Please try again.";
                    }
                } catch (PDOException $e) {
                    $db->rollBack();
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
    
    // Bulk upload via JSON
    elseif (isset($_POST['upload_bulk']) && isset($_POST['results_data'])) {
        $results_data = json_decode($_POST['results_data'], true);
        
        if (is_array($results_data) && count($results_data) > 0) {
            $success_count = 0;
            $error_count = 0;
            $errors = [];
            
            try {
                $db->beginTransaction();
                
                foreach ($results_data as $data) {
                    $student_id = $data['student_id'];
                    $obtained_marks = floatval($data['obtained_marks']);
                    
                    // Validate marks
                    if ($obtained_marks < 0 || $obtained_marks > $exam['total_marks']) {
                        $error_count++;
                        $errors[] = "Student $student_id: Invalid marks";
                        continue;
                    }
                    
                    $remarks = isset($data['remarks']) ? trim($data['remarks']) : '';
                    $mcq_marks = isset($data['mcq_marks']) && $data['mcq_marks'] !== '' ? floatval($data['mcq_marks']) : null;
                    $project_marks = isset($data['project_marks']) && $data['project_marks'] !== '' ? floatval($data['project_marks']) : null;
                    $viva_marks = isset($data['viva_marks']) && $data['viva_marks'] !== '' ? floatval($data['viva_marks']) : null;
                    
                    // Calculate grade
                    $grade = calculateGrade($obtained_marks, $exam['total_marks']);
                    
                    // Check if result exists
                    $check_stmt = $db->prepare("SELECT result_id FROM exam_results WHERE exam_id = ? AND student_id = ?");
                    $check_stmt->execute([$exam_id, $student_id]);
                    $exists = $check_stmt->fetch();
                    
                    if ($exists) {
                        // Update
                        $stmt = $db->prepare("
                            UPDATE exam_results 
                            SET obtained_marks = ?, grade = ?, remarks = ?, uploaded_by = ?,
                                mcq_marks = ?, project_marks = ?, viva_marks = ?, uploaded_at = NOW()
                            WHERE exam_id = ? AND student_id = ?
                        ");
                        $stmt->execute([
                            $obtained_marks, $grade, $remarks, $trainer['id'],
                            $mcq_marks, $project_marks, $viva_marks,
                            $exam_id, $student_id
                        ]);
                    } else {
                        // Insert
                        $stmt = $db->prepare("
                            INSERT INTO exam_results 
                            (exam_id, student_id, obtained_marks, grade, remarks, uploaded_by, mcq_marks, project_marks, viva_marks) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $exam_id, $student_id, $obtained_marks, $grade, $remarks,
                            $trainer['id'], $mcq_marks, $project_marks, $viva_marks
                        ]);
                    }
                    
                    $success_count++;
                }
                
                $db->commit();
                $message = "Bulk upload completed: $success_count records processed successfully.";
                if ($error_count > 0) {
                    $message .= " $error_count errors occurred.";
                }
                
                // Refresh page data
                header("Location: upload_results.php?exam_id=$exam_id&success=1");
                exit();
                
            } catch (PDOException $e) {
                $db->rollBack();
                $error = "Bulk upload failed: " . $e->getMessage();
            }
        } else {
            $error = "Invalid bulk data format";
        }
    }
    
    // Clear all results for this exam
    elseif (isset($_POST['clear_all']) && isset($_POST['confirm_clear']) && $_POST['confirm_clear'] == 'yes') {
        try {
            $delete_stmt = $db->prepare("DELETE FROM exam_results WHERE exam_id = ?");
            $delete_stmt->execute([$exam_id]);
            $message = "All results for this exam have been cleared.";
            
            // Refresh student data
            $students = $db->prepare("
                SELECT 
                    s.student_id, 
                    s.first_name, 
                    s.last_name,
                    s.current_status,
                    NULL as existing_marks,
                    NULL as existing_grade,
                    NULL as existing_remarks,
                    NULL as existing_mcq,
                    NULL as existing_project,
                    NULL as existing_viva,
                    NULL as last_uploaded
                FROM students s
                WHERE s.batch_name = ?
                ORDER BY s.current_status DESC, s.first_name, s.last_name
            ");
            $students->execute([$exam['batch_id']]);
            $students = $students->fetchAll(PDO::FETCH_ASSOC);
            $results_uploaded = 0;
            
        } catch (PDOException $e) {
            $error = "Failed to clear results: " . $e->getMessage();
        }
    }
}

// Get success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Bulk upload completed successfully!";
}

// Function to calculate grade
function calculateGrade($obtained, $total) {
    if ($total == 0) return 'N/A';
    
    $percentage = ($obtained / $total) * 100;
    
    if ($percentage >= 90) return 'A+';
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C';
    if ($percentage >= 40) return 'D';
    return 'F';
}

// Get the selected student data if student_id filter is set
$selected_student = null;
if (!empty($student_id_filter)) {
    foreach ($students as $student) {
        if ($student['student_id'] == $student_id_filter) {
            $selected_student = $student;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Upload Results - <?php echo htmlspecialchars($exam['exam_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --trainer-primary: #1B3C53;
            --trainer-success: #10b981;
            --trainer-danger: #ef4444;
            --trainer-warning: #f59e0b;
        }
        
        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83,.10), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(35,76,106,.10), transparent 30%),
                linear-gradient(135deg, #f8fafc 0%, #F6F1ED 48%, #f8fbff 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: 0;
            padding: 15px;
            transition: all 0.3s;
            min-height: 100vh;
        }
        
        @media (min-width: 768px) {
            .main-content {
                margin-left: 250px;
                padding: 20px;
            }
        }

        @media (min-width: 1024px) {
            .main-content {
                margin-left: 16rem !important;
            }
        }
        
        .upload-card {
            background: rgba(255,255,255,.95);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 14px 32px rgba(15,23,42,.08);
            border-left: 0;
            border-top: 4px solid transparent;
            border-image: linear-gradient(90deg, #1B3C53, #234C6A) 1;
            transition: all 0.3s ease;
        }
        
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            box-shadow: 0 5px 18px rgba(15,23,42,.07);
            height: 100%;
            border-bottom: 3px solid transparent;
            transition: all .2s ease;
        }

        .stats-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(15,23,42,.12);
        }
        
        .stats-card.primary { border-bottom-color: var(--trainer-primary); }
        .stats-card.success { border-bottom-color: var(--trainer-success); }
        .stats-card.warning { border-bottom-color: var(--trainer-warning); }
        .stats-card.danger { border-bottom-color: var(--trainer-danger); }
        
        .stats-number {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1.2;
        }
        
        .student-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 6px 14px rgba(27,60,83,.25);
        }
        
        .student-row {
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            border-radius: 12px;
        }
        
        .student-row:hover {
            background: linear-gradient(90deg, rgba(245,243,255,.9), rgba(255,241,248,.7));
            transform: translateX(5px);
        }
        
        .student-row.has-result {
            border-left-color: var(--trainer-success);
            background-color: rgba(16, 185, 129, 0.05);
        }
        
        .student-row.inactive {
            opacity: 0.7;
            background-color: #f8fafc;
        }
        
        .component-section {
            background: linear-gradient(135deg, #f8fafc, #F6F1ED);
            border: 1px solid #e0e7ff;
            border-radius: 16px;
            padding: 20px;
            margin: 15px 0;
        }
        
        .progress-sm {
            height: 6px;
            border-radius: 10px;
        }
        
        .upload-progress {
            height: 10px;
            border-radius: 10px;
            background-color: #e2e8f0;
        }
        
        .upload-progress-bar {
            transition: width 0.3s ease;
            background: linear-gradient(90deg, #1B3C53, #234C6A) !important;
        }
        
        .badge-result {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .quick-filter {
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(15,23,42,.1);
        }

        .card {
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 20px !important;
            box-shadow: 0 14px 32px rgba(15,23,42,.07) !important;
        }

        .text-primary { color: #234C6A !important; }
        .btn-primary {
            background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
            border: none !important;
            box-shadow: 0 10px 22px rgba(35,76,106,.18);
            font-weight: 700;
        }
        .btn-outline-primary {
            color: #234C6A !important;
            border-color: rgba(35,76,106,.36) !important;
            font-weight: 700;
        }
        .btn-outline-primary:hover {
            background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
            border-color: transparent !important;
            color: white !important;
        }
        .bg-primary { background: linear-gradient(135deg, #1B3C53, #234C6A) !important; }
        .bg-success { background: linear-gradient(135deg, #10b981, #22c55e) !important; }
        .bg-info { background: linear-gradient(135deg, #234C6A, #456882) !important; }
        
        /* Mobile menu toggle */
        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1040;
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: white;
            border: none;
            border-radius: 10px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: 0 8px 18px rgba(35,76,106,.28);
        }
        
        @media (min-width: 768px) {
            .mobile-menu-toggle {
                display: none;
            }
        }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1039;
            display: none;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        @media (max-width: 768px) {
            .stats-number {
                font-size: 1.25rem;
            }
            
            .btn-group {
                width: 100%;
            }
            
            .btn-group .btn {
                flex: 1;
            }
        }
        
        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        /* Bulk upload modal */
        .bulk-preview {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .bulk-preview table {
            font-size: 0.85rem;
        }
        
        .bulk-preview .badge {
            font-size: 0.7rem;
        }
    
        /* ===== Full same trainer purple/pink dashboard theme ===== */
        :root {
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 46%, #456882 100%);
            --dash-blue: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --dash-green: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
            --dash-orange: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            --dash-red: linear-gradient(135deg, #ef4444 0%, #f43f5e 100%);
            --dash-ink: #101827;
        }

        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83,.15), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130,.15), transparent 30%),
                linear-gradient(135deg, #eef4ff 0%, #f7f3ff 48%, #f8fbff 100%) !important;
            color: var(--dash-ink);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(27,60,83,.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(69,104,130,.045) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.82), transparent 84%);
            z-index: -2;
        }

        .main-content {
            background: transparent !important;
        }

        @media (min-width: 1024px) {
            .main-content {
                margin-left: 16rem !important;
            }
        }

        @media (min-width: 768px) and (max-width: 1023.98px) {
            .main-content {
                margin-left: 0 !important;
            }
        }

        aside {
            z-index: 1041;
        }

        .result-hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: clamp(1.15rem, 2.5vw, 1.8rem);
            margin-bottom: 1.5rem;
            color: white;
            background: var(--dash-main);
            box-shadow: 0 24px 58px rgba(27,60,83,.25);
            border: 1px solid rgba(255,255,255,.22);
        }

        .result-hero::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 430px;
            right: -135px;
            top: -145px;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            filter: blur(2px);
        }

        .result-hero::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .result-hero > * {
            position: relative;
            z-index: 1;
        }

        .result-hero h1 {
            color: white !important;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .result-hero p,
        .result-hero .text-muted {
            color: rgba(255,255,255,.84) !important;
            font-weight: 600;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .5rem .78rem;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.25);
            color: white;
            font-size: .76rem;
            font-weight: 900;
            backdrop-filter: blur(12px);
        }

        .upload-card, .stats-card, .card {
            position: relative;
            overflow: hidden;
            background: rgba(255,255,255,.90) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }

        .upload-card::before, .card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--dash-main));
        }

        .upload-card::after, .card::after {
            content: "";
            position: absolute;
            width: 190px;
            height: 190px;
            right: -65px;
            top: -65px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(8px);
            opacity: .74;
            pointer-events: none;
        }

        .upload-card > *, .card > * {
            position: relative;
            z-index: 1;
        }

        .upload-card:nth-of-type(1) {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-5 .upload-card:nth-child(1) {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-5 .upload-card:nth-child(2) {
            --feature-accent: linear-gradient(90deg, #f59e0b, #f97316, #456882);
            --feature-glow: radial-gradient(circle, rgba(249,115,22,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-7 .upload-card {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .36rem .72rem;
            border-radius: 999px;
            margin-bottom: .85rem;
            background: rgba(255,255,255,.84);
            border: 1px solid rgba(226,232,240,.9);
            color: #1B3C53;
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            box-shadow: 0 6px 18px rgba(15,23,42,.05);
        }

        .stats-card {
            border-bottom: 0 !important;
            transition: all .22s ease;
        }

        .stats-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--dash-main);
        }

        .stats-card.primary::before { background: var(--dash-blue); }
        .stats-card.success::before { background: var(--dash-green); }
        .stats-card.warning::before { background: var(--dash-orange); }
        .stats-card.danger::before { background: var(--dash-red); }

        .stats-card:hover, .upload-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 22px 48px rgba(15,23,42,.11) !important;
        }

        .stats-number {
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .student-avatar {
            background: var(--dash-main) !important;
            box-shadow: 0 14px 28px rgba(35,76,106,.20);
        }

        .student-row {
            margin-bottom: .55rem;
            border: 1px solid rgba(226,232,240,.78);
            background: linear-gradient(135deg, rgba(255,255,255,.94), rgba(248,250,255,.92));
            box-shadow: 0 10px 24px rgba(15,23,42,.045);
        }

        .student-row:hover {
            background: linear-gradient(90deg, rgba(245,243,255,.95), rgba(255,241,248,.82)) !important;
            transform: translateX(4px) translateY(-1px);
            box-shadow: 0 16px 32px rgba(15,23,42,.085);
        }

        .student-row.has-result {
            background: linear-gradient(135deg, rgba(236,253,245,.96), rgba(240,253,250,.90)) !important;
            border-left-color: #10b981;
        }

        .component-section {
            background: linear-gradient(135deg, rgba(248,250,252,.95), rgba(245,243,255,.92)) !important;
            border: 1px solid rgba(196,181,253,.40) !important;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.6);
        }

        .form-control, .form-select,
        .select2-container--default .select2-selection--single {
            border-radius: 14px !important;
            border-color: rgba(148,163,184,.40) !important;
        }

        .form-control:focus, .form-select:focus,
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 4px rgba(139,92,246,.12) !important;
        }

        .upload-progress {
            background: #e2e8f0 !important;
            overflow: hidden;
        }

        .upload-progress-bar,
        .progress-bar {
            background: var(--dash-main) !important;
        }

        .badge {
            border-radius: 999px !important;
            font-weight: 800 !important;
            letter-spacing: .02em;
        }

        .btn {
            border-radius: 14px !important;
            font-weight: 800 !important;
        }

        .btn-primary {
            background: var(--dash-main) !important;
            border: none !important;
            box-shadow: 0 10px 22px rgba(35,76,106,.18);
        }

        .btn-outline-primary {
            color: #234C6A !important;
            border-color: rgba(35,76,106,.36) !important;
        }

        .btn-outline-primary:hover {
            background: var(--dash-main) !important;
            border-color: transparent !important;
            color: white !important;
            box-shadow: 0 10px 22px rgba(35,76,106,.18);
        }

        .btn-outline-secondary:hover,
        .btn-outline-success:hover,
        .btn-outline-info:hover,
        .btn-outline-warning:hover,
        .btn-outline-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(15,23,42,.12);
        }

        .student-list-container {
            padding-right: .25rem;
        }

        .student-list-container::-webkit-scrollbar {
            width: 7px;
        }

        .student-list-container::-webkit-scrollbar-track {
            background: rgba(226,232,240,.7);
            border-radius: 999px;
        }

        .student-list-container::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            border-radius: 999px;
        }

        .alert {
            border-radius: 18px !important;
            border: 1px solid rgba(226,232,240,.8) !important;
            box-shadow: 0 12px 26px rgba(15,23,42,.06);
        }

        .modal-content {
            border-radius: 26px !important;
            border: 1px solid rgba(226,232,240,.85);
            box-shadow: 0 30px 80px rgba(15,23,42,.26);
            overflow: hidden;
        }

        .modal-header {
            border-bottom: 1px solid rgba(226,232,240,.85);
        }

        .modal-header.bg-success,
        .modal-header.bg-primary,
        .modal-header.bg-danger {
            background: var(--dash-main) !important;
        }

        .mobile-menu-toggle {
            background: var(--dash-main) !important;
            border-radius: 14px !important;
            box-shadow: 0 12px 28px rgba(35,76,106,.25) !important;
        }

        .text-primary { color: #234C6A !important; }
        .bg-primary { background: var(--dash-main) !important; }
        .bg-success { background: var(--dash-green) !important; }
        .bg-info { background: var(--dash-blue) !important; }

        @media (max-width: 768px) {
            .result-hero { border-radius: 22px; }
            .upload-card, .stats-card, .card { border-radius: 20px !important; }
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
</head>
<body>
    <button class="mobile-menu-toggle d-lg-none" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <?php include '../t_sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid px-3 px-md-4">
            
            <!-- Header -->
            <div class="result-hero d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between">
                <div>
                    <h1 class="h3 mb-1 text-gray-800">
                        <i class="fas fa-upload me-2" style="color: var(--trainer-primary);"></i>
                        Upload Exam Results
                    </h1>
                    <p class="text-muted mb-3">Enter and manage student marks for exams</p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="hero-chip"><i class="fas fa-users"></i> <?php echo $active_students; ?> active</span>
                        <span class="hero-chip"><i class="fas fa-check-circle"></i> <?php echo $results_uploaded; ?> uploaded</span>
                        <span class="hero-chip"><i class="fas fa-clock"></i> <?php echo $active_students - $results_uploaded; ?> pending</span>
                    </div>
                </div>
                <div class="mt-3 mt-md-0 d-flex gap-2">
                    <a href="trainer_exam_details.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-eye me-1"></i> View Details
                    </a>
                    <a href="trainer_exams.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Alerts -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Exam Info & Progress -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="upload-card">
                        <div class="section-kicker"><i class="fas fa-file-signature"></i> Exam Overview</div>
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h4 class="text-primary mb-0"><?php echo htmlspecialchars($exam['exam_name']); ?></h4>
                            <span class="badge bg-info"><?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?></span>
                        </div>
                        
                        <div class="row">
                            <div class="col-sm-6">
                                <p class="mb-2"><i class="fas fa-users text-muted me-2"></i> <strong>Batch:</strong> <?php echo htmlspecialchars($exam['batch_name']); ?></p>
                                <p class="mb-2"><i class="fas fa-book text-muted me-2"></i> <strong>Subject:</strong> <?php echo htmlspecialchars($exam['subject']); ?></p>
                                <p class="mb-2"><i class="fas fa-calendar text-muted me-2"></i> <strong>Date:</strong> <?php echo date('F j, Y', strtotime($exam['exam_date'])); ?></p>
                            </div>
                            <div class="col-sm-6">
                                <p class="mb-2"><i class="fas fa-star text-muted me-2"></i> <strong>Total Marks:</strong> <?php echo $exam['total_marks']; ?></p>
                                <p class="mb-2"><i class="fas fa-check-circle text-muted me-2"></i> <strong>Passing Marks:</strong> <?php echo $exam['passing_marks']; ?></p>
                                <?php if (!empty($exam_components)): ?>
                                    <p class="mb-0"><i class="fas fa-puzzle-piece text-muted me-2"></i> <strong>Components:</strong> 
                                        <?php 
                                        $component_names = [];
                                        foreach ($exam_components as $comp) {
                                            switch($comp) {
                                                case 'mcq': $component_names[] = 'MCQ'; break;
                                                case 'project': $component_names[] = 'Project'; break;
                                                case 'viva': $component_names[] = 'Viva'; break;
                                            }
                                        }
                                        echo implode(' • ', $component_names);
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Upload Progress -->
                        <div class="mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Upload Progress</span>
                                <span class="text-muted small">
                                    <strong class="text-primary"><?php echo $results_uploaded; ?></strong> / 
                                    <strong><?php echo $active_students; ?></strong> students
                                    <?php if ($inactive_students > 0): ?>
                                        <span class="text-muted">(<?php echo $inactive_students; ?> inactive)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="upload-progress">
                                <?php 
                                $progress_percent = $active_students > 0 ? ($results_uploaded / $active_students) * 100 : 0;
                                $progress_class = $progress_percent == 100 ? 'bg-success' : ($progress_percent > 50 ? 'bg-info' : 'bg-primary');
                                ?>
                                <div class="upload-progress-bar <?php echo $progress_class; ?>" 
                                     style="width: <?php echo $progress_percent; ?>%; height: 100%; border-radius: 10px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="col-md-4">
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="stats-card primary">
                                <div class="stats-number"><?php echo $active_students; ?></div>
                                <div class="text-muted small">Active Students</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stats-card success">
                                <div class="stats-number"><?php echo $results_uploaded; ?></div>
                                <div class="text-muted small">Results Uploaded</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stats-card warning">
                                <div class="stats-number"><?php echo $active_students - $results_uploaded; ?></div>
                                <div class="text-muted small">Pending</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stats-card" style="border-bottom-color: #858796;">
                                <div class="stats-number"><?php echo $inactive_students; ?></div>
                                <div class="text-muted small">Inactive</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Upload Section -->
            <div class="row">
                <!-- Left Column - Upload Form -->
                <div class="col-lg-5 mb-4 mb-lg-0">
                    <div class="upload-card">
                        <div class="section-kicker"><i class="fas fa-user-edit"></i> Marks Entry</div>
                        <h5 class="mb-3"><i class="fas fa-user-edit me-2" style="color: var(--trainer-primary);"></i>Enter Student Marks</h5>
                        
                        <form method="POST" action="" id="uploadForm">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Student <span class="text-danger">*</span></label>
                                <select class="form-select select2-student" name="student_id" id="studentSelect" required>
                                    <option value="">-- Choose Student --</option>
                                    <?php foreach ($students as $student): 
                                        $has_result = !is_null($student['existing_marks']);
                                        $status_text = $student['current_status'] == 'active' ? '' : ' (Inactive)';
                                        $selected = ($selected_student && $selected_student['student_id'] == $student['student_id']) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $student['student_id']; ?>" 
                                                data-has-result="<?php echo $has_result ? 'true' : 'false'; ?>"
                                                data-marks="<?php echo $student['existing_marks']; ?>"
                                                data-grade="<?php echo $student['existing_grade']; ?>"
                                                data-remarks="<?php echo htmlspecialchars($student['existing_remarks'] ?? ''); ?>"
                                                data-mcq="<?php echo $student['existing_mcq']; ?>"
                                                data-project="<?php echo $student['existing_project']; ?>"
                                                data-viva="<?php echo $student['existing_viva']; ?>"
                                                <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['student_id'] . ')' . $status_text); ?>
                                            <?php if ($has_result): ?>[Uploaded: <?php echo $student['existing_marks']; ?> marks]<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Student Status Alert -->
                            <div id="studentStatusAlert" class="alert d-none mb-3 small"></div>
                            
                            <!-- Existing Result Info -->
                            <div id="existingResultInfo" class="alert alert-info d-none mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <span id="existingResultText"></span>
                                <button type="button" class="btn btn-sm btn-outline-info ms-2" id="editExistingResult">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-7 mb-3">
                                    <label class="form-label fw-bold">Obtained Marks <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-lg" id="obtainedMarks" 
                                           name="obtained_marks" step="0.01" min="0" max="<?php echo $exam['total_marks']; ?>" 
                                           required placeholder="Enter marks">
                                    <div class="form-text">Max: <?php echo $exam['total_marks']; ?> marks</div>
                                </div>
                                <div class="col-md-5 mb-3">
                                    <label class="form-label fw-bold">Grade (Auto)</label>
                                    <input type="text" class="form-control form-control-lg" id="gradePreview" 
                                           readonly placeholder="A+, A, B+..." style="background-color: #f8f9fc;">
                                </div>
                            </div>
                            
                            <!-- Component-wise Marks -->
                            <?php if (!empty($exam_components)): ?>
                                <div class="component-section">
                                    <h6 class="text-primary mb-3"><i class="fas fa-puzzle-piece me-2"></i>Component-wise Marks</h6>
                                    <div class="row">
                                        <?php foreach ($exam_components as $component): 
                                            $field_name = $component . '_marks';
                                            $max_marks = 0;
                                            $label = '';
                                            $icon = '';
                                            
                                            switch($component) {
                                                case 'mcq':
                                                    $max_marks = $exam['mcq_marks'] ?? 0;
                                                    $label = 'MCQ Marks';
                                                    $icon = 'fa-check-square';
                                                    break;
                                                case 'project':
                                                    $max_marks = $exam['project_marks'] ?? 0;
                                                    $label = 'Project Marks';
                                                    $icon = 'fa-project-diagram';
                                                    break;
                                                case 'viva':
                                                    $max_marks = $exam['viva_marks'] ?? 0;
                                                    $label = 'Viva Marks';
                                                    $icon = 'fa-microphone-alt';
                                                    break;
                                            }
                                            
                                            if ($max_marks > 0):
                                        ?>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label small">
                                                <i class="fas <?php echo $icon; ?> me-1 text-muted"></i> <?php echo $label; ?>
                                            </label>
                                            <input type="number" class="form-control component-input" 
                                                   name="<?php echo $field_name; ?>" 
                                                   data-component="<?php echo $component; ?>"
                                                   step="0.01" min="0" max="<?php echo $max_marks; ?>" 
                                                   placeholder="0-<?php echo $max_marks; ?>">
                                            <div class="form-text">Max: <?php echo $max_marks; ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="alert alert-light small mb-0" id="componentTotalMsg">
                                        <i class="fas fa-calculator me-1"></i> Component total: <span id="componentTotal">0.00</span> / <?php echo $exam['total_marks']; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold">Remarks / Comments</label>
                                <textarea class="form-control" name="remarks" id="remarks" rows="2" 
                                          placeholder="Optional: Add any comments about the student's performance"></textarea>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" name="upload_single" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Save Results
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="clearFormBtn">
                                    <i class="fas fa-undo me-2"></i> Clear Form
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Quick Actions Card -->
                    <div class="upload-card mt-4">
                        <div class="section-kicker"><i class="fas fa-bolt"></i> Result Actions</div>
                        <h5 class="mb-3"><i class="fas fa-bolt me-2" style="color: var(--trainer-warning);"></i>Quick Actions</h5>
                        
                        <!-- Bulk Upload -->
                        <button type="button" class="btn btn-outline-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#bulkUploadModal">
                            <i class="fas fa-upload me-2"></i> Bulk Upload Results
                        </button>
                        
                        <!-- Download Template -->
                        <a href="download_template.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-outline-info w-100 mb-2">
                            <i class="fas fa-download me-2"></i> Download CSV Template
                        </a>
                        
                        <!-- Mark Attendance (New Feature) -->
                        <button type="button" class="btn btn-outline-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#attendanceModal">
                            <i class="fas fa-check-circle me-2"></i> Mark Student Attendance
                        </button>
                        
                        <!-- Clear Results (with confirmation) -->
                        <?php if ($results_uploaded > 0): ?>
                            <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#clearResultsModal">
                                <i class="fas fa-trash-alt me-2"></i> Clear All Results
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Right Column - Student List -->
                <div class="col-lg-7">
                    <div class="upload-card">
                        <div class="section-kicker"><i class="fas fa-list-check"></i> Student Result List</div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-list me-2" style="color: var(--trainer-primary);"></i>Student List</h5>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary filter-btn active" data-filter="all">All</button>
                                <button type="button" class="btn btn-outline-success filter-btn" data-filter="uploaded">Uploaded</button>
                                <button type="button" class="btn btn-outline-warning filter-btn" data-filter="pending">Pending</button>
                                <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="inactive">Inactive</button>
                            </div>
                        </div>
                        
                        <!-- Search -->
                        <div class="mb-3">
                            <input type="text" class="form-control" id="studentSearch" placeholder="Search by name or ID...">
                        </div>
                        
                        <!-- Students List -->
                        <div class="student-list-container" style="max-height: 500px; overflow-y: auto;">
                            <?php foreach ($students as $student): 
                                $has_result = !is_null($student['existing_marks']);
                                $is_active = $student['current_status'] == 'active';
                                $status_class = !$is_active ? 'inactive' : ($has_result ? 'has-result' : '');
                            ?>
                                <div class="student-row p-2 border-bottom <?php echo $status_class; ?>" 
                                     data-status="<?php echo $is_active ? ($has_result ? 'uploaded' : 'pending') : 'inactive'; ?>"
                                     data-name="<?php echo strtolower($student['first_name'] . ' ' . $student['last_name']); ?>"
                                     data-id="<?php echo strtolower($student['student_id']); ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="student-avatar me-3">
                                            <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-0">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                        <?php if (!$is_active): ?>
                                                            <span class="badge bg-secondary ms-2">Inactive</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <small class="text-muted"><?php echo $student['student_id']; ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <?php if ($has_result): ?>
                                                        <span class="badge bg-success mb-1"><?php echo $student['existing_marks']; ?> marks</span>
                                                        <small class="d-block text-muted">Grade: <?php echo $student['existing_grade']; ?></small>
                                                    <?php else: ?>
                                                        <?php if ($is_active): ?>
                                                            <span class="badge bg-warning text-dark">Pending</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">N/A</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($has_result && !empty($student['existing_remarks'])): ?>
                                                <small class="text-muted d-block text-truncate" style="max-width: 250px;">
                                                    <i class="fas fa-comment me-1"></i> <?php echo htmlspecialchars($student['existing_remarks']); ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($has_result): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i> <?php echo date('M j, g:i A', strtotime($student['last_uploaded'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($is_active): ?>
                                            <button class="btn btn-sm btn-outline-primary ms-2 quick-fill" 
                                                    data-student-id="<?php echo $student['student_id']; ?>"
                                                    data-has-result="<?php echo $has_result ? 'true' : 'false'; ?>"
                                                    data-marks="<?php echo $student['existing_marks']; ?>"
                                                    data-mcq="<?php echo $student['existing_mcq']; ?>"
                                                    data-project="<?php echo $student['existing_project']; ?>"
                                                    data-viva="<?php echo $student['existing_viva']; ?>"
                                                    data-remarks="<?php echo htmlspecialchars($student['existing_remarks'] ?? ''); ?>"
                                                    title="Quick fill this student">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Summary -->
                        <div class="mt-3 pt-2 border-top d-flex justify-content-between">
                            <small class="text-muted">
                                <span class="text-primary"><?php echo $results_uploaded; ?></span> uploaded, 
                                <span class="text-warning"><?php echo $active_students - $results_uploaded; ?></span> pending
                            </small>
                            <small class="text-muted">
                                <i class="fas fa-check-circle text-success me-1"></i> Total: <?php echo $total_students; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bulk Upload Modal -->
    <div class="modal fade" id="bulkUploadModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Bulk Upload Results</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data" id="bulkUploadForm">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Upload a CSV file with columns: student_id, obtained_marks, remarks (optional)
                            <?php if (!empty($exam_components)): ?>
                                <br>Add component columns: mcq_marks, project_marks, viva_marks as needed.
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select CSV File</label>
                            <input type="file" class="form-control" id="csvFile" accept=".csv" required>
                        </div>
                        
                        <!-- Preview Section -->
                        <div id="bulkPreview" class="d-none">
                            <h6 class="mt-3">Preview Data</h6>
                            <div class="bulk-preview border rounded p-2">
                                <table class="table table-sm table-bordered" id="previewTable">
                                    <thead>
                                        <tr id="previewHeader"></tr>
                                    </thead>
                                    <tbody id="previewBody"></tbody>
                                </table>
                            </div>
                        </div>
                        
                        <input type="hidden" name="results_data" id="resultsData">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload_bulk" class="btn btn-success" id="processBulkBtn" disabled>
                            <i class="fas fa-check me-2"></i> Process Bulk Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Attendance Modal (New Feature) -->
    <div class="modal fade" id="attendanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Mark Attendance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="mark_attendance.php">
                    <div class="modal-body">
                        <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
                        
                        <p class="mb-3">Mark attendance for <strong><?php echo $exam['exam_name']; ?></strong></p>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Attendance Date</label>
                            <input type="date" class="form-control" name="attendance_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Quick Mark</label>
                            <div class="btn-group w-100 mb-2">
                                <button type="button" class="btn btn-outline-success" id="markAllPresent">
                                    <i class="fas fa-check me-1"></i> All Present
                                </button>
                                <button type="button" class="btn btn-outline-danger" id="markAllAbsent">
                                    <i class="fas fa-times me-1"></i> All Absent
                                </button>
                            </div>
                        </div>
                        
                        <div class="student-attendance-list" style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($students as $student): 
                                if ($student['current_status'] != 'active') continue;
                            ?>
                                <div class="form-check mb-2 attendance-item">
                                    <input class="form-check-input attendance-check" type="checkbox" 
                                           name="attendance[<?php echo $student['student_id']; ?>]" 
                                           value="present" id="attendance_<?php echo $student['student_id']; ?>" checked>
                                    <label class="form-check-label" for="attendance_<?php echo $student['student_id']; ?>">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                        <small class="text-muted">(<?php echo $student['student_id']; ?>)</small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i> Save Attendance
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Clear Results Modal -->
    <div class="modal fade" id="clearResultsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Clear All Results</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <p>Are you sure you want to clear <strong>ALL</strong> uploaded results for this exam?</p>
                        <p class="text-danger"><i class="fas fa-warning me-1"></i> This action cannot be undone!</p>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirm_clear" value="yes" id="confirmClear" required>
                            <label class="form-check-label" for="confirmClear">
                                Yes, I understand and want to clear all results
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="clear_all" class="btn btn-danger" id="clearResultsBtn" disabled>
                            <i class="fas fa-trash me-2"></i> Clear All Results
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.0/papaparse.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // Initialize Select2
            $('.select2-student').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: '-- Choose Student --'
            });
            
            // Sidebar toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const sidebar = document.querySelector('aside');
            
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('-translate-x-full');
                    sidebarOverlay.classList.toggle('active');
                });
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.add('-translate-x-full');
                    this.classList.remove('active');
                });
            }
            
            // Window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    sidebar.classList.remove('-translate-x-full');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                }
            });
            
            // Elements
            const studentSelect = document.getElementById('studentSelect');
            const obtainedMarks = document.getElementById('obtainedMarks');
            const gradePreview = document.getElementById('gradePreview');
            const remarks = document.getElementById('remarks');
            const componentInputs = document.querySelectorAll('.component-input');
            const componentTotal = document.getElementById('componentTotal');
            const submitBtn = document.getElementById('submitBtn');
            const existingResultInfo = document.getElementById('existingResultInfo');
            const existingResultText = document.getElementById('existingResultText');
            const studentStatusAlert = document.getElementById('studentStatusAlert');
            
            // Total marks
            const totalMarks = <?php echo $exam['total_marks']; ?>;
            
            // Auto-calculate grade
            function calculateGrade(marks) {
                if (!marks || marks < 0) return '-';
                const percentage = (marks / totalMarks) * 100;
                
                if (percentage >= 90) return 'A+';
                if (percentage >= 80) return 'A';
                if (percentage >= 70) return 'B+';
                if (percentage >= 60) return 'B';
                if (percentage >= 50) return 'C';
                if (percentage >= 40) return 'D';
                return 'F';
            }
            
            // Update grade on marks input
            if (obtainedMarks) {
                obtainedMarks.addEventListener('input', function() {
                    const marks = parseFloat(this.value);
                    if (!isNaN(marks)) {
                        gradePreview.value = calculateGrade(marks);
                    } else {
                        gradePreview.value = '';
                    }
                });
            }
            
            // Calculate component total
            function calculateComponentTotal() {
                let total = 0;
                componentInputs.forEach(input => {
                    const val = parseFloat(input.value);
                    if (!isNaN(val)) {
                        total += val;
                    }
                });
                if (componentTotal) {
                    componentTotal.textContent = total.toFixed(2);
                }
                
                // Auto-fill obtained marks if all components provided and total matches
                const allFilled = Array.from(componentInputs).every(input => input.value !== '');
                if (allFilled && obtainedMarks && !obtainedMarks.value) {
                    obtainedMarks.value = total.toFixed(2);
                    obtainedMarks.dispatchEvent(new Event('input'));
                }
            }
            
            componentInputs.forEach(input => {
                input.addEventListener('input', calculateComponentTotal);
            });
            
            // Student select change
            $('#studentSelect').on('change', function() {
                const selected = $(this).find(':selected');
                const hasResult = selected.data('has-result') === true || selected.data('has-result') === 'true';
                const marks = selected.data('marks');
                const grade = selected.data('grade');
                const remarksText = selected.data('remarks');
                const mcq = selected.data('mcq');
                const project = selected.data('project');
                const viva = selected.data('viva');
                
                // Clear alerts
                studentStatusAlert.classList.add('d-none');
                
                // Check if student is inactive
                const optionText = selected.text();
                if (optionText.includes('Inactive')) {
                    studentStatusAlert.classList.remove('d-none', 'alert-warning', 'alert-info');
                    studentStatusAlert.classList.add('alert-warning');
                    studentStatusAlert.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> This student is inactive. Results can still be uploaded if needed.';
                }
                
                if (hasResult) {
                    // Show existing result info
                    existingResultInfo.classList.remove('d-none');
                    existingResultText.innerHTML = `Result already uploaded: <strong>${marks} marks</strong> (Grade: ${grade})`;
                    
                    // Pre-fill form
                    if (obtainedMarks) obtainedMarks.value = marks;
                    if (gradePreview) gradePreview.value = grade;
                    if (remarks) remarks.value = remarksText || '';
                    
                    // Pre-fill components
                    componentInputs.forEach(input => {
                        const name = input.getAttribute('name');
                        if (name === 'mcq_marks' && mcq) input.value = mcq;
                        if (name === 'project_marks' && project) input.value = project;
                        if (name === 'viva_marks' && viva) input.value = viva;
                    });
                    
                    calculateComponentTotal();
                    
                } else {
                    existingResultInfo.classList.add('d-none');
                }
            });
            
            // Edit existing result
            const editExistingResult = document.getElementById('editExistingResult');
            if (editExistingResult) {
                editExistingResult.addEventListener('click', function() {
                    existingResultInfo.classList.add('d-none');
                });
            }
            
            // Clear form
            document.getElementById('clearFormBtn').addEventListener('click', function() {
                if (studentSelect) studentSelect.value = '';
                if (obtainedMarks) obtainedMarks.value = '';
                if (gradePreview) gradePreview.value = '';
                if (remarks) remarks.value = '';
                componentInputs.forEach(input => input.value = '');
                calculateComponentTotal();
                existingResultInfo.classList.add('d-none');
                $('#studentSelect').trigger('change.select2');
            });
            
            // Quick fill buttons
            document.querySelectorAll('.quick-fill').forEach(btn => {
                btn.addEventListener('click', function() {
                    const studentId = this.dataset.studentId;
                    const hasResult = this.dataset.hasResult === 'true';
                    const marks = this.dataset.marks;
                    const mcq = this.dataset.mcq;
                    const project = this.dataset.project;
                    const viva = this.dataset.viva;
                    const remarksText = this.dataset.remarks;
                    
                    // Set student select
                    if (studentSelect) {
                        studentSelect.value = studentId;
                        $('#studentSelect').trigger('change');
                        
                        // Small delay to allow change event to populate
                        setTimeout(() => {
                            if (hasResult) {
                                if (obtainedMarks) obtainedMarks.value = marks;
                                if (remarks) remarks.value = remarksText || '';
                                
                                componentInputs.forEach(input => {
                                    const name = input.getAttribute('name');
                                    if (name === 'mcq_marks' && mcq) input.value = mcq;
                                    if (name === 'project_marks' && project) input.value = project;
                                    if (name === 'viva_marks' && viva) input.value = viva;
                                });
                                
                                calculateComponentTotal();
                                if (obtainedMarks) obtainedMarks.dispatchEvent(new Event('input'));
                            }
                        }, 100);
                    }
                    
                    // Scroll to form
                    document.querySelector('.upload-card').scrollIntoView({ behavior: 'smooth' });
                });
            });
            
            // Filter students
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    // Update active state
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const filter = this.dataset.filter;
                    const students = document.querySelectorAll('.student-row');
                    
                    students.forEach(student => {
                        const status = student.dataset.status;
                        if (filter === 'all' || status === filter) {
                            student.style.display = 'block';
                        } else {
                            student.style.display = 'none';
                        }
                    });
                });
            });
            
            // Search students
            const studentSearch = document.getElementById('studentSearch');
            if (studentSearch) {
                studentSearch.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const students = document.querySelectorAll('.student-row');
                    
                    students.forEach(student => {
                        const name = student.dataset.name;
                        const id = student.dataset.id;
                        
                        if (name.includes(searchTerm) || id.includes(searchTerm)) {
                            student.style.display = 'block';
                        } else {
                            student.style.display = 'none';
                        }
                    });
                });
            }
            
            // Bulk upload CSV parsing
            const csvFile = document.getElementById('csvFile');
            const bulkPreview = document.getElementById('bulkPreview');
            const previewHeader = document.getElementById('previewHeader');
            const previewBody = document.getElementById('previewBody');
            const processBulkBtn = document.getElementById('processBulkBtn');
            const resultsData = document.getElementById('resultsData');
            
            if (csvFile) {
                csvFile.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (!file) {
                        bulkPreview.classList.add('d-none');
                        processBulkBtn.disabled = true;
                        return;
                    }
                    
                    Papa.parse(file, {
                        header: true,
                        skipEmptyLines: true,
                        complete: function(results) {
                            if (results.data.length > 0) {
                                // Show preview
                                bulkPreview.classList.remove('d-none');
                                
                                // Build header
                                const headers = Object.keys(results.data[0]);
                                let headerHtml = '<tr>';
                                headers.forEach(h => {
                                    headerHtml += `<th>${h}</th>`;
                                });
                                headerHtml += '</tr>';
                                previewHeader.innerHTML = headerHtml;
                                
                                // Build body (show first 5 rows)
                                let bodyHtml = '';
                                const previewRows = results.data.slice(0, 5);
                                previewRows.forEach(row => {
                                    bodyHtml += '<tr>';
                                    headers.forEach(h => {
                                        bodyHtml += `<td>${row[h] || '-'}</td>`;
                                    });
                                    bodyHtml += '</tr>';
                                });
                                
                                if (results.data.length > 5) {
                                    bodyHtml += '<tr><td colspan="100" class="text-muted text-center">... and ' + (results.data.length - 5) + ' more rows</td></tr>';
                                }
                                
                                previewBody.innerHTML = bodyHtml;
                                
                                // Store data for processing
                                resultsData.value = JSON.stringify(results.data);
                                processBulkBtn.disabled = false;
                            }
                        },
                        error: function(error) {
                            alert('Error parsing CSV: ' + error);
                        }
                    });
                });
            }
            
            // Attendance quick actions
            const markAllPresent = document.getElementById('markAllPresent');
            const markAllAbsent = document.getElementById('markAllAbsent');
            
            if (markAllPresent) {
                markAllPresent.addEventListener('click', function() {
                    document.querySelectorAll('.attendance-check').forEach(cb => {
                        cb.checked = true;
                    });
                });
            }
            
            if (markAllAbsent) {
                markAllAbsent.addEventListener('click', function() {
                    document.querySelectorAll('.attendance-check').forEach(cb => {
                        cb.checked = false;
                    });
                });
            }
            
            // Clear results confirmation
            const confirmClear = document.getElementById('confirmClear');
            const clearResultsBtn = document.getElementById('clearResultsBtn');
            
            if (confirmClear) {
                confirmClear.addEventListener('change', function() {
                    clearResultsBtn.disabled = !this.checked;
                });
            }
            
            // Form validation before submit
            document.getElementById('uploadForm').addEventListener('submit', function(e) {
                const marks = parseFloat(obtainedMarks.value);
                
                if (isNaN(marks) || marks < 0 || marks > totalMarks) {
                    e.preventDefault();
                    alert(`Please enter valid marks between 0 and ${totalMarks}`);
                    return false;
                }
                
                // Validate components if present
                let componentValid = true;
                componentInputs.forEach(input => {
                    if (input.value) {
                        const val = parseFloat(input.value);
                        const max = parseFloat(input.getAttribute('max'));
                        if (isNaN(val) || val < 0 || val > max) {
                            componentValid = false;
                            alert(`Component marks must be between 0 and ${max}`);
                        }
                    }
                });
                
                if (!componentValid) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
            
            // Auto-refresh check (optional)
            setInterval(function() {
                // Could implement AJAX check for new uploads
                console.log('Checking for updates...');
            }, 60000);
        });
    </script>
    
    <style>
        /* Additional custom styles */
        .student-list-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .student-list-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .student-list-container::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            border-radius: 10px;
        }
        
        .student-list-container::-webkit-scrollbar-thumb:hover {
            background: #234C6A;
        }
        
        .select2-container--default .select2-selection--single {
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
            padding: 0.375rem 0.75rem;
            height: 38px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            padding-left: 0;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        .select2-dropdown {
            border-color: #ced4da;
            border-radius: 0.375rem;
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: white;
            border-color: transparent;
        }
        
        .filter-btn.active:hover {
            background: linear-gradient(135deg, #1B3C53, #6d28d9);
        }
        
        @media (max-width: 576px) {
            .btn-group {
                flex-wrap: wrap;
            }
            
            .btn-group .btn {
                flex: 0 0 auto;
                width: calc(50% - 0.25rem);
                margin: 0.125rem;
            }
        }
    </style>
</body>
</html>