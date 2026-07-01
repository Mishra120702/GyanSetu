<?php
require_once '../db_connection.php';
session_start();

// Check user role and authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission (admin, mentor, or accounts)
$user_role = $_SESSION['user_role'] ?? '';
if (!in_array($user_role, ['admin'])) {
    header("Location: ../dashboard.php");
    exit();
}

// ============================================================
// ============================================================
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // Fetch all exams
    $allExams = $db->query("
        SELECT exam_id, exam_name, batch_id, exam_date 
        FROM exams 
        ORDER BY exam_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Output a simple selection page (using minimal styling)
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Select Exam to Edit | ASD Academy</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { background: #f5f0eb; font-family: 'Inter', sans-serif; padding: 40px 20px; }
            .container { max-width: 900px; margin: auto; background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 20px rgba(27,60,83,0.1); }
            .table th { background: #f5f0eb; color: #1B3C53; }
            .btn-sm { border-radius: 8px; }
            .btn-primary { background: #1B3C53; border-color: #1B3C53; }
            .btn-primary:hover { background: #234C6A; border-color: #234C6A; }
        </style>
    </head>
    <body>
        <div class="container">
            <h2 class="mb-3" style="color:#1B3C53;"><i class="fas fa-edit me-2" style="color:#234C6A;"></i>Select an Exam to Edit</h2>
            <p class="text-muted">Choose an exam from the list below to modify its details, manage students, or view analytics.</p>
            <?php if (empty($allExams)): ?>
                <div class="alert alert-warning">No exams found. <a href="exams.php">Go back</a></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr><th>ID</th><th>Exam Name</th><th>Batch</th><th>Date</th><th class="text-center">Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allExams as $exam): ?>
                            <tr>
                                <td><?php echo $exam['exam_id']; ?></td>
                                <td><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                <td><?php echo $exam['batch_id']; ?></td>
                                <td><?php echo date('d M Y', strtotime($exam['exam_date'])); ?></td>
                                <td class="text-center">
                                    <a href="edit_exam.php?id=<?php echo $exam['exam_id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-pen me-1"></i> Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="mt-3"><a href="exams.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Exams</a></div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit();
}

// ============================================================
// ORIGINAL CODE CONTINUES – UNCHANGED
// ============================================================
$message = '';
$error = '';
$exam = null;

// Check if exam ID is provided
if (!isset($_GET['id'])) {
    header("Location: exams.php");
    exit();
}

$exam_id = $_GET['id'];

// Fetch exam details with related information
$stmt = $db->prepare("
    SELECT e.*, 
           b.batch_name,
           b.batch_mentor_id,
           t.name as mentor_name,
           u.name as created_by_name,
           (SELECT COUNT(*) FROM exam_results WHERE exam_id = e.exam_id) as results_count,
           (SELECT COUNT(*) FROM exam_enrollments WHERE exam_id = e.exam_id) as enrolled_count
    FROM exams e 
    LEFT JOIN batches b ON e.batch_id = b.batch_id 
    LEFT JOIN trainers t ON b.batch_mentor_id = t.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.exam_id = ?
");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    $error = "Exam not found!";
}

// Get batches for dropdown
$batches = $db->query("
    SELECT batch_id, batch_name, batch_mentor_id 
    FROM batches 
    WHERE status IN ('ongoing', 'upcoming', 'completed') 
    ORDER BY 
        CASE status 
            WHEN 'ongoing' THEN 1 
            WHEN 'upcoming' THEN 2 
            WHEN 'completed' THEN 3 
        END,
        batch_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get courses for dropdown
$courses = $db->query("SELECT id, name FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get students enrolled in this exam
$enrolled_students = [];
if ($exam) {
    $stmt = $db->prepare("
        SELECT s.*, er.obtained_marks, er.grade
        FROM students s
        LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
        WHERE s.student_id IN (
            SELECT student_id FROM exam_enrollments WHERE exam_id = ?
        )
        ORDER BY s.first_name, s.last_name
    ");
    $stmt->execute([$exam_id, $exam_id]);
    $enrolled_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submission for exam details update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_exam'])) {
    $exam_name = trim($_POST['exam_name']);
    $batch_id = $_POST['batch_id'];
    $course_id = !empty($_POST['course_id']) ? $_POST['course_id'] : null;
    $subject = trim($_POST['subject']);
    $exam_date = $_POST['exam_date'];
    $total_marks = floatval($_POST['total_marks']);
    $passing_marks = floatval($_POST['passing_marks']);
    $exam_type = $_POST['exam_type'];
    $description = trim($_POST['description']);
    $enrollment_status = $_POST['enrollment_status'];
    $is_back_schedule = isset($_POST['is_back_schedule']) ? 1 : 0;
    
    // Exam components
    $exam_components = isset($_POST['exam_components']) ? $_POST['exam_components'] : [];
    $exam_components_str = implode(',', $exam_components);
    
    $mcq_marks = isset($_POST['mcq_marks']) ? floatval($_POST['mcq_marks']) : 0;
    $project_marks = isset($_POST['project_marks']) ? floatval($_POST['project_marks']) : 0;
    $viva_marks = isset($_POST['viva_marks']) ? floatval($_POST['viva_marks']) : 0;
    
    // Validation
    $errors = [];
    
    if (empty($exam_name)) {
        $errors[] = "Exam name is required";
    }
    
    if (empty($batch_id)) {
        $errors[] = "Batch is required";
    }
    
    if (empty($subject)) {
        $errors[] = "Subject is required";
    }
    
    if (empty($exam_date)) {
        $errors[] = "Exam date is required";
    }
    
    if ($total_marks <= 0) {
        $errors[] = "Total marks must be greater than 0";
    }
    
    if ($passing_marks < 0) {
        $errors[] = "Passing marks cannot be negative";
    }
    
    // Validate component marks don't exceed total marks
    $component_total = $mcq_marks + $project_marks + $viva_marks;
    if ($component_total > $total_marks) {
        $errors[] = "Sum of component marks cannot exceed total marks!";
    }
    
    if ($passing_marks > $total_marks) {
        $errors[] = "Passing marks cannot exceed total marks!";
    }
    
    // Validate if any component is selected but marks are zero
    if (in_array('mcq', $exam_components) && $mcq_marks <= 0) {
        $errors[] = "MCQ marks must be greater than 0 when MCQ component is selected";
    }
    
    if (in_array('project', $exam_components) && $project_marks <= 0) {
        $errors[] = "Project marks must be greater than 0 when Project component is selected";
    }
    
    if (in_array('viva', $exam_components) && $viva_marks <= 0) {
        $errors[] = "Viva marks must be greater than 0 when Viva component is selected";
    }
    
    // Validate date format
    $date_obj = DateTime::createFromFormat('Y-m-d', $exam_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $exam_date) {
        $errors[] = "Invalid exam date format";
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                UPDATE exams SET 
                    exam_name = ?, 
                    batch_id = ?, 
                    course_id = ?,
                    subject = ?, 
                    exam_date = ?, 
                    total_marks = ?, 
                    passing_marks = ?, 
                    exam_type = ?, 
                    description = ?, 
                    exam_components = ?, 
                    mcq_marks = ?, 
                    project_marks = ?, 
                    viva_marks = ?,
                    enrollment_status = ?,
                    is_back_schedule = ?
                WHERE exam_id = ?
            ");
            
            if ($stmt->execute([
                $exam_name, 
                $batch_id, 
                $course_id,
                $subject, 
                $exam_date, 
                $total_marks, 
                $passing_marks, 
                $exam_type, 
                $description, 
                $exam_components_str, 
                $mcq_marks, 
                $project_marks, 
                $viva_marks,
                $enrollment_status,
                $is_back_schedule,
                $exam_id
            ])) {
                $db->commit();
                $message = "Exam updated successfully!";
                
                // Refresh exam data
                $stmt = $db->prepare("
                    SELECT e.*, b.batch_name 
                    FROM exams e 
                    LEFT JOIN batches b ON e.batch_id = b.batch_id 
                    WHERE e.exam_id = ?
                ");
                $stmt->execute([$exam_id]);
                $exam = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $db->rollBack();
                $error = "Failed to update exam: " . $stmt->errorInfo()[2];
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Handle student enrollment management
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_students'])) {
    $selected_students = $_POST['selected_students'] ?? [];
    
    if (!empty($selected_students)) {
        try {
            $db->beginTransaction();
            
            $insert_stmt = $db->prepare("
                INSERT INTO exam_enrollments (exam_id, student_id, enrolled_by) 
                VALUES (?, ?, ?)
            ");
            
            foreach ($selected_students as $student_id) {
                // Check if already enrolled
                $check = $db->prepare("SELECT id FROM exam_enrollments WHERE exam_id = ? AND student_id = ?");
                $check->execute([$exam_id, $student_id]);
                
                if (!$check->fetch()) {
                    $insert_stmt->execute([$exam_id, $student_id, $_SESSION['user_id']]);
                }
            }
            
            $db->commit();
            $message = "Students added successfully!";
            
            // Refresh enrolled students list
            $stmt = $db->prepare("
                SELECT s.*, er.obtained_marks, er.grade
                FROM students s
                LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
                WHERE s.student_id IN (
                    SELECT student_id FROM exam_enrollments WHERE exam_id = ?
                )
                ORDER BY s.first_name, s.last_name
            ");
            $stmt->execute([$exam_id, $exam_id]);
            $enrolled_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error adding students: " . $e->getMessage();
        }
    }
}

// Handle student removal from exam
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_student'])) {
    $student_id = $_POST['student_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if results exist
        $check_results = $db->prepare("SELECT id FROM exam_results WHERE exam_id = ? AND student_id = ?");
        $check_results->execute([$exam_id, $student_id]);
        
        if ($check_results->fetch()) {
            $error = "Cannot remove student with existing exam results!";
        } else {
            $stmt = $db->prepare("DELETE FROM exam_enrollments WHERE exam_id = ? AND student_id = ?");
            if ($stmt->execute([$exam_id, $student_id])) {
                $db->commit();
                $message = "Student removed from exam successfully!";
                
                // Refresh enrolled students list
                $stmt = $db->prepare("
                    SELECT s.*, er.obtained_marks, er.grade
                    FROM students s
                    LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
                    WHERE s.student_id IN (
                        SELECT student_id FROM exam_enrollments WHERE exam_id = ?
                    )
                    ORDER BY s.first_name, s.last_name
                ");
                $stmt->execute([$exam_id, $exam_id]);
                $enrolled_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $db->rollBack();
                $error = "Failed to remove student";
            }
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get available students for enrollment (for the same batch)
$available_students = [];
if ($exam && $exam['batch_id']) {
    if ($exam['enrollment_status'] == 'all_students') {
        // For 'all_students', show all batch students not yet enrolled
        $stmt = $db->prepare("
            SELECT s.* 
            FROM students s 
            WHERE s.batch_name = ? 
            AND s.student_id NOT IN (
                SELECT student_id FROM exam_enrollments WHERE exam_id = ?
            )
            AND s.current_status = 'active'
            ORDER BY s.first_name, s.last_name
        ");
        $stmt->execute([$exam['batch_id'], $exam_id]);
    } else {
        // For 'selected_students', show all active students
        $stmt = $db->prepare("
            SELECT s.* 
            FROM students s 
            WHERE s.current_status = 'active'
            AND s.student_id NOT IN (
                SELECT student_id FROM exam_enrollments WHERE exam_id = ?
            )
            ORDER BY s.first_name, s.last_name
        ");
        $stmt->execute([$exam_id]);
    }
    $available_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Exam - <?php echo htmlspecialchars($exam['exam_name'] ?? 'Exam'); ?> | ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* ===== COLOR PALETTE ===== */
        :root {
            --primary: #1B3C53;
            --primary-dark: #0f2a3a;
            --primary-light: #234C6A;
            --accent: #456882;
            --neutral: #D2C1B6;
            --neutral-light: #e8dfd9;
            --neutral-dark: #b8a89d;
            
            --success: #2d7d5d;
            --danger: #c0392b;
            --warning: #b7950b;
            --info: #2c7b9e;
            
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(27, 60, 83, 0.12);
            --shadow: 0 4px 24px rgba(27, 60, 83, 0.10);
            --shadow-light: 0 2px 12px rgba(27, 60, 83, 0.06);
            --shadow-hover: 0 8px 32px rgba(27, 60, 83, 0.15);
            
            --gradient-primary: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --gradient-success: linear-gradient(135deg, #2d7d5d 0%, #1a5a40 100%);
            --gradient-danger: linear-gradient(135deg, #c0392b 0%, #922B21 100%);
            
            --body-bg: #f5f0eb;
            --card-bg: var(--glass-bg);
            --border-color: var(--glass-border);
            --text-primary: #1B3C53;
            --text-secondary: #5a6c7a;
            --text-muted: #8a9aa8;
            
            --input-bg: #ffffff;
            --input-border: rgba(27, 60, 83, 0.15);
            --input-focus-border: #1B3C53;
            --input-focus-shadow: rgba(27, 60, 83, 0.12);
            
            --table-hover: rgba(27, 60, 83, 0.04);
            --table-th-bg: rgba(27, 60, 83, 0.05);
            --badge-bg: #f0ebe6;
            --badge-text: #1B3C53;
        }

        /* ===== BASE ===== */
        body {
            background: var(--body-bg);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            color: var(--text-primary);
        }

        .main-content {
            margin-left: 0;
            padding: 20px;
            animation: fadeIn 0.6s ease-out;
        }

        @media (min-width: 768px) {
            .main-content {
                margin-left: 250px;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===== CARDS ===== */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            color: var(--text-primary);
        }

        .card-header.bg-white {
            background: transparent !important;
        }

        /* ===== BUTTONS ===== */
        .btn-primary {
            background: var(--gradient-primary);
            border: none;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.25);
            color: white !important;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(27, 60, 83, 0.35);
            background: var(--gradient-primary);
            color: white !important;
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: var(--neutral);
            border: none;
            color: var(--text-primary) !important;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: var(--neutral-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            color: var(--text-primary) !important;
        }

        .btn-danger {
            background: var(--gradient-danger);
            border: none;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(192, 57, 43, 0.25);
            color: white !important;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(192, 57, 43, 0.35);
            color: white !important;
        }

        .btn-outline-danger {
            border-color: var(--danger);
            color: var(--danger);
            border-radius: 8px;
        }

        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
        }

        .btn-outline-secondary {
            border-color: var(--text-muted);
            color: var(--text-secondary);
            border-radius: 8px;
        }

        .btn-outline-secondary:hover {
            background: var(--text-secondary);
            color: white;
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 0.85rem;
            border-radius: 8px;
        }

        /* ===== ALERTS ===== */
        .alert {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: var(--shadow-light);
            animation: slideInDown 0.5s ease-out;
        }

        .alert-success {
            background: rgba(45, 125, 93, 0.08);
            border-color: rgba(45, 125, 93, 0.2);
            color: #1a5a40;
        }

        .alert-danger {
            background: rgba(192, 57, 43, 0.08);
            border-color: rgba(192, 57, 43, 0.2);
            color: #922B21;
        }

        .alert-warning {
            background: rgba(183, 149, 11, 0.08);
            border-color: rgba(183, 149, 11, 0.2);
            color: #7a6408;
        }

        .alert-info {
            background: rgba(44, 123, 158, 0.08);
            border-color: rgba(44, 123, 158, 0.2);
            color: #1a5a78;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            border-bottom: none;
            margin-bottom: 28px;
            padding-bottom: 16px;
            position: relative;
        }

        .page-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }

        .gradient-text {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }

        /* ===== FORMS ===== */
        .form-control, .form-select {
            background: var(--input-bg);
            border: 2px solid var(--input-border);
            border-radius: 10px;
            padding: 11px 16px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            color: var(--text-primary);
        }

        .form-control::placeholder, .form-select::placeholder {
            color: var(--text-muted);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--input-focus-border);
            box-shadow: 0 0 0 4px var(--input-focus-shadow);
            transform: translateY(-1px);
        }

        .form-label {
            color: var(--text-primary);
            font-weight: 500;
        }

        .form-text {
            color: var(--text-muted) !important;
            font-size: 0.85rem;
        }

        .form-check-input {
            background-color: var(--input-bg);
            border-color: var(--input-border);
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .form-check-label {
            color: var(--text-primary);
        }

        .form-check.card {
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
            padding: 14px 18px;
            margin: 0;
        }

        .form-check.card:hover {
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-light);
        }

        .form-check-input:checked + .form-check-label {
            color: var(--primary);
            font-weight: 600;
        }

        /* ===== COMPONENT SECTION ===== */
        .component-section {
            background: rgba(27, 60, 83, 0.04);
            border: 2px dashed rgba(27, 60, 83, 0.15);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }

        .component-section:hover {
            border-color: rgba(27, 60, 83, 0.25);
            background: rgba(27, 60, 83, 0.06);
        }

        .component-fields {
            display: none;
            margin-top: 16px;
            animation: fadeIn 0.4s ease;
        }

        .component-fields.active {
            display: block;
        }

        /* ===== STATS CARDS ===== */
        .stats-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-light);
            transition: all 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .stats-card h6 {
            color: var(--text-muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .stats-card .h4, .stats-card h4 {
            font-weight: 700;
            margin-bottom: 0;
            color: var(--text-primary);
        }

        .stats-card .text-primary { color: var(--primary) !important; }
        .stats-card .text-success { color: var(--success) !important; }
        .stats-card .text-danger { color: var(--danger) !important; }
        .stats-card .text-info { color: var(--info) !important; }

        /* ===== BADGES ===== */
        .badge {
            border-radius: 10px;
            padding: 5px 12px;
            font-weight: 600;
            font-size: 0.75rem;
            letter-spacing: 0.3px;
        }

        .badge-exam {
            background: var(--gradient-primary);
            color: white;
        }

        .badge.bg-primary { background: var(--primary) !important; color: white; }
        .badge.bg-success { background: var(--success) !important; color: white; }
        .badge.bg-danger { background: var(--danger) !important; color: white; }
        .badge.bg-warning { background: var(--warning) !important; color: white; }
        .badge.bg-info { background: var(--info) !important; color: white; }
        .badge.bg-secondary { background: var(--neutral) !important; color: var(--text-primary); }

        /* ===== PROGRESS ===== */
        .progress {
            height: 8px;
            border-radius: 8px;
            background: rgba(27, 60, 83, 0.08);
            overflow: hidden;
        }

        .progress-bar {
            border-radius: 8px;
            transition: width 1s ease-in-out;
            background: var(--gradient-primary);
        }

        .progress-bar.bg-success { background: var(--gradient-success); }
        .progress-bar.bg-danger { background: var(--gradient-danger); }
        .progress-bar.bg-warning { background: linear-gradient(135deg, #b7950b, #d4a017); }
        .progress-bar.bg-info { background: linear-gradient(135deg, #2c7b9e, #1a5a78); }

        /* ===== TABLES ===== */
        .table {
            --bs-table-bg: transparent;
            border-collapse: separate;
            border-spacing: 0 6px;
            color: var(--text-primary);
        }

        .table th {
            background: var(--table-th-bg);
            border: none;
            font-weight: 600;
            color: var(--text-primary);
            padding: 0.7rem 0.5rem;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .table tbody tr {
            background: var(--card-bg);
            border-radius: 10px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-light);
        }

        .table tbody tr:hover {
            transform: translateX(4px);
            box-shadow: var(--shadow-hover);
            background: var(--table-hover);
        }

        .table td {
            padding: 0.7rem 0.5rem;
            vertical-align: middle;
            border: none;
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .table td:first-child {
            border-top-left-radius: 10px;
            border-bottom-left-radius: 10px;
        }

        .table td:last-child {
            border-top-right-radius: 10px;
            border-bottom-right-radius: 10px;
        }

        /* ===== NAV TABS ===== */
        .nav-tabs {
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 24px;
            gap: 4px;
        }

        .nav-tabs .nav-link {
            border: none;
            color: var(--text-secondary);
            font-weight: 500;
            padding: 12px 20px;
            border-radius: 10px 10px 0 0;
            position: relative;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .nav-tabs .nav-link:hover {
            color: var(--primary);
            background: rgba(27, 60, 83, 0.05);
        }

        .nav-tabs .nav-link.active {
            color: var(--primary);
            background: transparent;
            border-bottom: 3px solid var(--primary);
            font-weight: 600;
        }

        .nav-tabs .nav-link i {
            margin-right: 8px;
        }

        /* ===== INFO BOX ===== */
        .info-box {
            background: rgba(27, 60, 83, 0.05);
            border-radius: 12px;
            padding: 16px 20px;
            border-left: 4px solid var(--primary);
        }

        /* ===== BACK BUTTON ===== */
        .back-button {
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            color: var(--primary);
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .back-button:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        /* ===== SELECT2 OVERRIDES ===== */
        .select2-container--default .select2-selection--multiple {
            background: var(--input-bg) !important;
            border: 2px solid var(--input-border) !important;
            border-radius: 10px !important;
            padding: 6px;
        }

        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: var(--input-focus-border) !important;
            box-shadow: 0 0 0 4px var(--input-focus-shadow) !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background: var(--primary) !important;
            color: white !important;
            border: none !important;
            border-radius: 6px !important;
            padding: 3px 10px !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white !important;
            margin-right: 6px !important;
        }

        .select2-dropdown {
            background: var(--card-bg) !important;
            border-color: var(--border-color) !important;
        }

        .select2-container--default .select2-results__option {
            color: var(--text-primary) !important;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background: var(--primary) !important;
            color: white !important;
        }

        /* ===== DATATABLES OVERRIDES ===== */
        .dataTables_wrapper .dataTables_filter input {
            background: var(--input-bg) !important;
            border: 2px solid var(--input-border) !important;
            border-radius: 10px !important;
            padding: 8px 16px !important;
            color: var(--text-primary) !important;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--input-focus-border) !important;
            box-shadow: 0 0 0 4px var(--input-focus-shadow) !important;
        }

        .dataTables_wrapper .dataTables_length select {
            background: var(--input-bg) !important;
            border: 2px solid var(--input-border) !important;
            border-radius: 10px !important;
            color: var(--text-primary) !important;
            padding: 4px 8px !important;
        }

        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: var(--text-secondary) !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--primary) !important;
            color: white !important;
            border: none !important;
            border-radius: 8px !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--primary-light) !important;
            color: white !important;
            border: none !important;
            border-radius: 8px !important;
        }

        /* ===== RESPONSIVENESS ===== */
        @media (max-width: 991.98px) {
            .stats-card .h4, .stats-card h4 {
                font-size: 1.2rem;
            }
            
            .card-header {
                padding: 1rem 1.25rem;
            }
            
            .card-body {
                padding: 1.25rem;
            }
        }

        @media (max-width: 767.98px) {
            .main-content {
                padding: 12px;
            }

            .page-header .row {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 12px;
            }

            .page-header .col-auto {
                width: 100%;
            }

            .back-button {
                width: 100%;
                justify-content: center;
            }

            .card {
                border-radius: 12px;
                margin-bottom: 16px;
            }

            .card-header {
                padding: 0.875rem 1rem;
                font-size: 0.95rem;
            }

            .card-body {
                padding: 1rem;
            }

            .form-control, .form-select {
                font-size: 0.9rem;
                padding: 10px 14px;
            }

            .btn {
                font-size: 0.85rem;
                padding: 8px 16px;
                width: 100%;
                justify-content: center;
            }

            .btn-sm {
                width: auto;
            }

            .nav-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                gap: 0;
                padding-bottom: 2px;
            }

            .nav-tabs .nav-link {
                padding: 10px 14px;
                font-size: 0.8rem;
                white-space: nowrap;
            }

            .nav-tabs .nav-link i {
                margin-right: 4px;
            }

            .stats-card {
                padding: 14px 16px;
            }

            .stats-card h6 {
                font-size: 0.7rem;
            }

            .stats-card .h4, .stats-card h4 {
                font-size: 1.1rem;
            }

            .component-section {
                padding: 14px;
            }

            .form-check.card {
                padding: 12px 14px;
            }

            .table {
                font-size: 0.8rem;
            }

            .table td, .table th {
                padding: 0.5rem 0.4rem;
            }

            .badge {
                font-size: 0.65rem;
                padding: 4px 10px;
            }

            .row.g-4 {
                --bs-gutter-y: 1rem;
                --bs-gutter-x: 0.75rem;
            }

            .row.g-3 {
                --bs-gutter-y: 0.75rem;
                --bs-gutter-x: 0.75rem;
            }

            .info-box {
                padding: 12px 16px;
                font-size: 0.9rem;
            }

            .gradient-text {
                font-size: 1.3rem;
            }

            .table-responsive {
                margin: 0 -4px;
            }

            .table tbody tr {
                display: table-row;
            }

            .table tbody tr:hover {
                transform: none;
            }

            .dataTables_wrapper .dataTables_filter input {
                width: 100% !important;
            }

            .select2-container {
                width: 100% !important;
            }

            .component-fields .card {
                padding: 14px !important;
            }

            .d-flex.gap-3 {
                flex-direction: column;
                gap: 8px !important;
            }

            .d-flex.gap-3 .btn {
                width: 100%;
            }

            .alert {
                font-size: 0.9rem;
                padding: 12px 16px;
            }

            .alert .d-flex {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 8px;
            }

            .alert .btn-close {
                position: absolute;
                top: 8px;
                right: 8px;
            }
        }

        @media (max-width: 575.98px) {
            .main-content {
                padding: 8px;
            }

            .card-body {
                padding: 0.75rem;
            }

            .nav-tabs .nav-link {
                padding: 8px 10px;
                font-size: 0.75rem;
            }

            .stats-card {
                padding: 10px 12px;
            }

            .stats-card .h4, .stats-card h4 {
                font-size: 1rem;
            }

            .table td, .table th {
                padding: 0.4rem 0.3rem;
                font-size: 0.75rem;
            }

            .badge {
                font-size: 0.6rem;
                padding: 3px 8px;
            }

            .form-check.card {
                padding: 10px 12px;
            }

            .component-section {
                padding: 10px;
            }

            .gradient-text {
                font-size: 1.1rem;
            }

            .back-button {
                font-size: 0.8rem;
                padding: 6px 14px;
            }

            .btn {
                font-size: 0.8rem;
                padding: 6px 14px;
            }

            .component-fields .card {
                padding: 10px !important;
            }

            .info-box {
                padding: 10px 14px;
                font-size: 0.85rem;
            }

            .progress {
                height: 6px;
            }
        }

        /* ===== PRINT ===== */
        @media print {
            .btn, .back-button { display: none !important; }
            body { background: white; color: black; }
            .main-content { margin: 0; padding: 0; }
            .card { background: white; box-shadow: none; border: 1px solid #ddd; }
            .table tbody tr { background: white; box-shadow: none; border: 1px solid #ddd; }
            .table th { background: #f5f0eb; }
        }

        /* ===== UTILITY ===== */
        .text-primary-custom { color: var(--primary) !important; }
        .text-secondary-custom { color: var(--text-secondary) !important; }
        .bg-neutral { background: var(--neutral) !important; }
        .border-primary-custom { border-color: var(--primary) !important; }

        /* Ripple effect */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }

        @keyframes ripple {
            to { transform: scale(4); opacity: 0; }
        }

        .btn {
            position: relative;
            overflow: hidden;
        }

        .exam-status-badge {
            font-size: 0.8rem;
            padding: 6px 16px;
        }

        /* Tab content animations */
        .tab-pane.fade {
            transition: opacity 0.3s ease;
        }

        .tab-pane.fade.show {
            opacity: 1;
        }

        /* Student row styling */
        .student-row {
            cursor: default;
        }

        /* Better form validation styling */
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: var(--danger);
            background-image: none;
        }

        .form-control.is-invalid:focus, .form-select.is-invalid:focus {
            border-color: var(--danger);
            box-shadow: 0 0 0 4px rgba(192, 57, 43, 0.12);
        }

        .invalid-feedback {
            color: var(--danger);
            font-size: 0.8rem;
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
                        <h1 class="h2 mb-1 gradient-text">
                            <i class="fas fa-edit me-2"></i>Edit Exam
                        </h1>
                        <p class="text-muted mb-0">
                            <i class="fas fa-file-alt me-2"></i>
                            <?php echo htmlspecialchars($exam['exam_name'] ?? 'Exam'); ?> | 
                            <span class="badge bg-primary"><?php echo $exam['exam_id'] ?? ''; ?></span>
                        </p>
                    </div>
                    <div class="col-auto">
                        <a href="exams.php" class="back-button">
                            <i class="fas fa-arrow-left me-2"></i> Back to Exams
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle fa-lg text-danger"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="alert-heading mb-1">Update Failed!</h6>
                            <p class="mb-0"><?php echo $error; ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle fa-lg text-success"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="alert-heading mb-1">Success!</h6>
                            <p class="mb-0"><?php echo $message; ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($exam): ?>
            <!-- Exam Info Cards -->
            <div class="row mb-4">
                <div class="col-6 col-md-3">
                    <div class="stats-card">
                        <h6>Exam ID</h6>
                        <h4 class="text-primary"><?php echo htmlspecialchars($exam['exam_id']); ?></h4>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card">
                        <h6>Status</h6>
                        <div>
                            <?php
                            $exam_date = strtotime($exam['exam_date']);
                            $current_date = time();
                            $status_badge = '';
                            $status_text = '';
                            
                            if ($exam_date > $current_date) {
                                $status_badge = 'bg-success';
                                $status_text = 'Upcoming';
                            } elseif ($exam_date == date('Y-m-d')) {
                                $status_badge = 'bg-warning';
                                $status_text = 'Today';
                            } else {
                                $status_badge = 'bg-secondary';
                                $status_text = 'Completed';
                            }
                            ?>
                            <span class="badge <?php echo $status_badge; ?> exam-status-badge"><?php echo $status_text; ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card">
                        <h6>Enrolled / Results</h6>
                        <h4>
                            <?php echo $exam['enrolled_count'] ?? 0; ?> / 
                            <?php echo $exam['results_count'] ?? 0; ?>
                        </h4>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card">
                        <h6>Created By</h6>
                        <h4><?php echo htmlspecialchars($exam['created_by_name'] ?? 'System'); ?></h4>
                        <small class="text-muted"><?php echo date('d M Y', strtotime($exam['created_at'])); ?></small>
                    </div>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <ul class="nav nav-tabs" id="examTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab">
                        <i class="fas fa-info-circle"></i> Exam Details
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button" role="tab">
                        <i class="fas fa-users"></i> Manage Students 
                        <span class="badge bg-primary ms-1"><?php echo count($enrolled_students); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="results-tab" data-bs-toggle="tab" data-bs-target="#results" type="button" role="tab">
                        <i class="fas fa-chart-bar"></i> Results Overview
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="examTabsContent">
                <!-- Details Tab -->
                <div class="tab-pane fade show active" id="details" role="tabpanel">
                    <div class="card animate__animated animate__fadeInUp">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 gradient-text">
                                <i class="fas fa-edit me-2"></i>Edit Exam Details
                            </h5>
                            <span class="badge badge-exam">
                                <?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" id="editExamForm">
                                <div class="row g-4">
                                    <!-- Basic Information -->
                                    <div class="col-md-6">
                                        <label for="exam_name" class="form-label">
                                            <i class="fas fa-file-alt me-2"></i>Exam Name *
                                        </label>
                                        <input type="text" class="form-control" id="exam_name" name="exam_name" 
                                               value="<?php echo htmlspecialchars($exam['exam_name']); ?>" required
                                               placeholder="Enter exam name">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="batch_id" class="form-label">
                                            <i class="fas fa-users me-2"></i>Batch *
                                        </label>
                                        <select class="form-select" id="batch_id" name="batch_id" required>
                                            <option value="">Select Batch</option>
                                            <?php foreach ($batches as $batch): ?>
                                                <option value="<?php echo $batch['batch_id']; ?>" 
                                                    <?php echo ($batch['batch_id'] == $exam['batch_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($batch['batch_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="course_id" class="form-label">
                                            <i class="fas fa-book-open me-2"></i>Course (Optional)
                                        </label>
                                        <select class="form-select" id="course_id" name="course_id">
                                            <option value="">No specific course</option>
                                            <?php foreach ($courses as $course): ?>
                                                <option value="<?php echo $course['id']; ?>" 
                                                    <?php echo (isset($exam['course_id']) && $course['id'] == $exam['course_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($course['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="subject" class="form-label">
                                            <i class="fas fa-book me-2"></i>Subject *
                                        </label>
                                        <input type="text" class="form-control" id="subject" name="subject" 
                                               value="<?php echo htmlspecialchars($exam['subject']); ?>" required
                                               placeholder="Enter subject">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="exam_date" class="form-label">
                                            <i class="fas fa-calendar-alt me-2"></i>Exam Date *
                                        </label>
                                        <input type="date" class="form-control" id="exam_date" name="exam_date" 
                                               value="<?php echo $exam['exam_date']; ?>" required
                                               min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    
                                    <!-- Marks Information -->
                                    <div class="col-md-4">
                                        <label for="total_marks" class="form-label">
                                            <i class="fas fa-chart-bar me-2"></i>Total Marks *
                                        </label>
                                        <input type="number" class="form-control" id="total_marks" name="total_marks" 
                                               step="0.01" value="<?php echo $exam['total_marks']; ?>" required
                                               placeholder="e.g., 100" min="1">
                                        <div class="form-text">
                                            Maximum marks for the exam
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label for="passing_marks" class="form-label">
                                            <i class="fas fa-check-circle me-2"></i>Passing Marks *
                                        </label>
                                        <input type="number" class="form-control" id="passing_marks" name="passing_marks" 
                                               step="0.01" value="<?php echo $exam['passing_marks']; ?>" required
                                               placeholder="e.g., 40" min="0">
                                        <div class="form-text">
                                            Minimum marks to pass
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label for="exam_type" class="form-label">
                                            <i class="fas fa-tag me-2"></i>Exam Type *
                                        </label>
                                        <select class="form-select" id="exam_type" name="exam_type" required>
                                            <option value="unit_test" <?php echo ($exam['exam_type'] == 'unit_test') ? 'selected' : ''; ?>>Unit Test</option>
                                            <option value="quarterly" <?php echo ($exam['exam_type'] == 'quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                                            <option value="half-yearly" <?php echo ($exam['exam_type'] == 'half-yearly') ? 'selected' : ''; ?>>Half Yearly</option>
                                            <option value="final" <?php echo ($exam['exam_type'] == 'final') ? 'selected' : ''; ?>>Final</option>
                                            <option value="practice" <?php echo ($exam['exam_type'] == 'practice') ? 'selected' : ''; ?>>Practice</option>
                                        </select>
                                    </div>

                                    <!-- Enrollment Status -->
                                    <div class="col-md-6">
                                        <label for="enrollment_status" class="form-label">
                                            <i class="fas fa-user-plus me-2"></i>Enrollment Status *
                                        </label>
                                        <select class="form-select" id="enrollment_status" name="enrollment_status" required>
                                            <option value="all_students" <?php echo ($exam['enrollment_status'] == 'all_students') ? 'selected' : ''; ?>>
                                                All Batch Students
                                            </option>
                                            <option value="selected_students" <?php echo ($exam['enrollment_status'] == 'selected_students') ? 'selected' : ''; ?>>
                                                Selected Students Only
                                            </option>
                                        </select>
                                        <div class="form-text">
                                            <?php if ($exam['enrollment_status'] == 'all_students'): ?>
                                                All active students from the batch will be automatically enrolled
                                            <?php else: ?>
                                                You need to manually select students for this exam
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-check form-switch mt-4 pt-2">
                                            <input class="form-check-input" type="checkbox" role="switch" 
                                                   id="is_back_schedule" name="is_back_schedule" 
                                                   <?php echo ($exam['is_back_schedule'] == 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_back_schedule">
                                                <i class="fas fa-clock me-2"></i>Back Schedule Exam
                                            </label>
                                            <div class="form-text">
                                                Check this if this is a back schedule / remedial exam
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Component Progress Bar -->
                                    <div class="col-12 mt-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fw-semibold">Component Marks Allocation</span>
                                            <span class="badge bg-primary">
                                                <span id="component_total_display">0</span> / 
                                                <span id="total_marks_display"><?php echo $exam['total_marks']; ?></span>
                                            </span>
                                        </div>
                                        <div class="progress mb-4">
                                            <div id="component_progress" class="progress-bar bg-success" 
                                                 role="progressbar" style="width: 0%"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Enhanced Exam Components Section -->
                                    <div class="col-12">
                                        <div class="component-section">
                                            <h6 class="fw-semibold mb-3 gradient-text">
                                                <i class="fas fa-puzzle-piece me-2"></i>Exam Components
                                            </h6>
                                            
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-4">
                                                    <div class="form-check card p-3">
                                                        <input class="form-check-input component-checkbox" type="checkbox" 
                                                               id="component_mcq" name="exam_components[]" value="mcq" 
                                                               <?php echo (strpos($exam['exam_components'] ?? '', 'mcq') !== false) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label fw-medium" for="component_mcq">
                                                            <i class="fas fa-list-ul me-2"></i>MCQ Section
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-check card p-3">
                                                        <input class="form-check-input component-checkbox" type="checkbox" 
                                                               id="component_project" name="exam_components[]" value="project"
                                                               <?php echo (strpos($exam['exam_components'] ?? '', 'project') !== false) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label fw-medium" for="component_project">
                                                            <i class="fas fa-project-diagram me-2"></i>Project Work
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-check card p-3">
                                                        <input class="form-check-input component-checkbox" type="checkbox" 
                                                               id="component_viva" name="exam_components[]" value="viva"
                                                               <?php echo (strpos($exam['exam_components'] ?? '', 'viva') !== false) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label fw-medium" for="component_viva">
                                                            <i class="fas fa-microphone me-2"></i>Viva Voce
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- MCQ Component Fields -->
                                            <div id="mcqFields" class="component-fields <?php echo (strpos($exam['exam_components'] ?? '', 'mcq') !== false) ? 'active' : ''; ?>">
                                                <div class="card p-3">
                                                    <label for="mcq_marks" class="form-label fw-medium">
                                                        <i class="fas fa-list-ul me-2"></i>MCQ Marks *
                                                    </label>
                                                    <input type="number" class="form-control component-marks" id="mcq_marks" 
                                                           name="mcq_marks" step="0.01" 
                                                           value="<?php echo $exam['mcq_marks'] ?? 0; ?>" min="0"
                                                           placeholder="Enter MCQ marks">
                                                    <div class="form-text">Marks allocated for MCQ section</div>
                                                </div>
                                            </div>

                                            <!-- Project Component Fields -->
                                            <div id="projectFields" class="component-fields <?php echo (strpos($exam['exam_components'] ?? '', 'project') !== false) ? 'active' : ''; ?>">
                                                <div class="card p-3">
                                                    <label for="project_marks" class="form-label fw-medium">
                                                        <i class="fas fa-project-diagram me-2"></i>Project Marks *
                                                    </label>
                                                    <input type="number" class="form-control component-marks" id="project_marks" 
                                                           name="project_marks" step="0.01" 
                                                           value="<?php echo $exam['project_marks'] ?? 0; ?>" min="0"
                                                           placeholder="Enter project marks">
                                                    <div class="form-text">Marks allocated for project work</div>
                                                </div>
                                            </div>

                                            <!-- Viva Component Fields -->
                                            <div id="vivaFields" class="component-fields <?php echo (strpos($exam['exam_components'] ?? '', 'viva') !== false) ? 'active' : ''; ?>">
                                                <div class="card p-3">
                                                    <label for="viva_marks" class="form-label fw-medium">
                                                        <i class="fas fa-microphone me-2"></i>Viva Marks *
                                                    </label>
                                                    <input type="number" class="form-control component-marks" id="viva_marks" 
                                                           name="viva_marks" step="0.01" 
                                                           value="<?php echo $exam['viva_marks'] ?? 0; ?>" min="0"
                                                           placeholder="Enter viva marks">
                                                    <div class="form-text">Marks allocated for viva voce</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Description -->
                                    <div class="col-12">
                                        <label for="description" class="form-label">
                                            <i class="fas fa-align-left me-2"></i>Description
                                        </label>
                                        <textarea class="form-control" id="description" name="description" rows="4"
                                                  placeholder="Optional exam description"><?php echo htmlspecialchars($exam['description']); ?></textarea>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="col-12">
                                        <div class="d-flex justify-content-end gap-3 pt-3 border-top">
                                            <a href="exams.php" class="btn btn-secondary">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </a>
                                            <button type="submit" name="update_exam" class="btn btn-primary">
                                                <i class="fas fa-save me-2"></i>Update Exam
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Students Tab -->
                <div class="tab-pane fade" id="students" role="tabpanel">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 gradient-text">
                                <i class="fas fa-users me-2"></i>Manage Exam Students
                            </h5>
                            <div>
                                <span class="badge bg-primary me-1">Enrolled: <?php echo count($enrolled_students); ?></span>
                                <span class="badge bg-success">Available: <?php echo count($available_students); ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Add Students Form -->
                            <?php if (!empty($available_students) && $exam['enrollment_status'] == 'selected_students'): ?>
                            <div class="info-box mb-4">
                                <h6 class="fw-semibold mb-3">
                                    <i class="fas fa-user-plus me-2"></i>Add Students to Exam
                                </h6>
                                <form method="POST" action="" id="addStudentsForm">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <select class="form-select select2-multiple" name="selected_students[]" multiple="multiple" style="width: 100%">
                                                <?php foreach ($available_students as $student): ?>
                                                    <option value="<?php echo $student['student_id']; ?>">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> 
                                                        (<?php echo $student['student_id']; ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <button type="submit" name="add_students" class="btn btn-primary w-100">
                                                <i class="fas fa-plus-circle me-2"></i>Add Selected Students
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>

                            <!-- Enrolled Students List -->
                            <h6 class="fw-semibold mb-3">
                                <i class="fas fa-list me-2"></i>Enrolled Students
                                <?php if ($exam['enrollment_status'] == 'all_students'): ?>
                                    <span class="badge bg-info ms-2">Auto-enrolled from batch</span>
                                <?php endif; ?>
                            </h6>
                            
                            <?php if (empty($enrolled_students)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-users fa-4x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Students Enrolled</h5>
                                    <p class="text-muted">
                                        <?php if ($exam['enrollment_status'] == 'all_students'): ?>
                                            No active students found in the batch.
                                        <?php else: ?>
                                            Use the form above to add students to this exam.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover" id="studentsTable">
                                        <thead>
                                            <tr>
                                                <th>Student ID</th>
                                                <th>Name</th>
                                                <th>Contact</th>
                                                <th>Batch</th>
                                                <th>Status</th>
                                                <th>Results</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($enrolled_students as $student): ?>
                                                <tr class="student-row">
                                                    <td>
                                                        <span class="fw-medium"><?php echo htmlspecialchars($student['student_id']); ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($student['father_name'] ?? ''); ?></small>
                                                    </td>
                                                    <td>
                                                        <div><?php echo htmlspecialchars($student['phone_number'] ?? 'N/A'); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($student['email'] ?? ''); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info"><?php echo htmlspecialchars($student['batch_name'] ?? 'N/A'); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_class = match($student['current_status']) {
                                                            'active' => 'bg-success',
                                                            'dropped' => 'bg-danger',
                                                            'on hold' => 'bg-warning',
                                                            'transferred' => 'bg-info',
                                                            'completed' => 'bg-primary',
                                                            default => 'bg-secondary'
                                                        };
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?>">
                                                            <?php echo ucfirst($student['current_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if (isset($student['obtained_marks'])): ?>
                                                            <span class="badge bg-<?php echo ($student['obtained_marks'] >= $exam['passing_marks']) ? 'success' : 'danger'; ?>">
                                                                <?php echo $student['obtained_marks']; ?> / <?php echo $exam['total_marks']; ?>
                                                                <?php if ($student['grade']): ?> (<?php echo $student['grade']; ?>) <?php endif; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Not Published</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!isset($student['obtained_marks'])): ?>
                                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Are you sure you want to remove this student from the exam?');">
                                                                <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                                <button type="submit" name="remove_student" class="btn btn-sm btn-outline-danger">
                                                                    <i class="fas fa-user-minus"></i>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <button class="btn btn-sm btn-outline-secondary" disabled title="Cannot remove student with results">
                                                                <i class="fas fa-lock"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Results Tab -->
                <div class="tab-pane fade" id="results" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0 gradient-text">
                                <i class="fas fa-chart-bar me-2"></i>Results Overview
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php
                            // Calculate statistics
                            $total_enrolled = count($enrolled_students);
                            $results_published = 0;
                            $passed = 0;
                            $failed = 0;
                            $total_marks_sum = 0;
                            
                            foreach ($enrolled_students as $student) {
                                if (isset($student['obtained_marks'])) {
                                    $results_published++;
                                    $total_marks_sum += $student['obtained_marks'];
                                    if ($student['obtained_marks'] >= $exam['passing_marks']) {
                                        $passed++;
                                    } else {
                                        $failed++;
                                    }
                                }
                            }
                            
                            $avg_marks = $results_published > 0 ? $total_marks_sum / $results_published : 0;
                            $pass_percentage = $results_published > 0 ? ($passed / $results_published) * 100 : 0;
                            ?>
                            
                            <div class="row g-4 mb-4">
                                <div class="col-6 col-md-3">
                                    <div class="stats-card text-center">
                                        <h6>Results Published</h6>
                                        <h4 class="text-primary"><?php echo $results_published; ?> / <?php echo $total_enrolled; ?></h4>
                                        <small class="text-muted"><?php echo $total_enrolled > 0 ? round(($results_published/$total_enrolled)*100, 1) : 0; ?>% Complete</small>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stats-card text-center">
                                        <h6>Passed</h6>
                                        <h4 class="text-success"><?php echo $passed; ?></h4>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stats-card text-center">
                                        <h6>Failed</h6>
                                        <h4 class="text-danger"><?php echo $failed; ?></h4>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stats-card text-center">
                                        <h6>Pass Percentage</h6>
                                        <h4 class="text-info"><?php echo round($pass_percentage, 1); ?>%</h4>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="stats-card">
                                        <h6>Average Marks</h6>
                                        <h4><?php echo round($avg_marks, 2); ?> / <?php echo $exam['total_marks']; ?></h4>
                                        <div class="progress mt-2">
                                            <div class="progress-bar bg-info" role="progressbar" 
                                                 style="width: <?php echo ($avg_marks / $exam['total_marks']) * 100; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stats-card">
                                        <h6>Grade Distribution</h6>
                                        <?php
                                        $grades = [];
                                        foreach ($enrolled_students as $student) {
                                            if (isset($student['grade']) && !empty($student['grade'])) {
                                                $grades[] = $student['grade'];
                                            }
                                        }
                                        $grade_counts = array_count_values($grades);
                                        ?>
                                        <?php if (!empty($grade_counts)): ?>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach ($grade_counts as $grade => $count): ?>
                                                    <span class="badge bg-secondary p-2">
                                                        <?php echo $grade; ?>: <?php echo $count; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted mb-0">No grades available</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($results_published > 0): ?>
                                <div class="text-center mt-4">
                                    <a href="exam_results.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-primary">
                                        <i class="fas fa-chart-line me-2"></i>View Detailed Results
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Results Published Yet</h5>
                                    <p class="text-muted">Results will appear here once they are published.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- Error State -->
            <div class="card animate__animated animate__shakeX">
                <div class="card-body text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-exclamation-triangle fa-4x text-warning"></i>
                    </div>
                    <h4 class="text-muted mb-3">Exam Not Found</h4>
                    <p class="text-muted mb-4">The exam you're trying to edit doesn't exist or you don't have permission to access it.</p>
                    <a href="exams.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i> Back to Exams
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // ===== DATATABLE =====
            if ($('#studentsTable').length) {
                $('#studentsTable').DataTable({
                    pageLength: 10,
                    order: [[1, 'asc']],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search students..."
                    },
                    responsive: true
                });
            }

            // ===== SELECT2 =====
            $('.select2-multiple').select2({
                placeholder: "Select students to add",
                allowClear: true,
                width: '100%'
            });

            // ===== COMPONENT CHECKBOX =====
            $('.component-checkbox').change(function() {
                const componentId = $(this).val();
                const componentFields = $('#' + componentId + 'Fields');
                
                if ($(this).is(':checked')) {
                    componentFields.slideDown(300).addClass('active');
                } else {
                    componentFields.slideUp(300).removeClass('active');
                    $('#' + componentId + '_marks').val('0');
                }
                calculateComponentTotal();
            });
            
            function initializeComponentFields() {
                $('.component-checkbox').each(function() {
                    const componentId = $(this).val();
                    if ($(this).is(':checked')) {
                        $('#' + componentId + 'Fields').addClass('active').show();
                    }
                });
            }
            
            function calculateComponentTotal() {
                let total = 0;
                const totalMarks = parseFloat($('#total_marks').val()) || 0;
                
                $('.component-marks').each(function() {
                    if ($(this).closest('.component-fields').hasClass('active')) {
                        total += parseFloat($(this).val()) || 0;
                    }
                });
                
                $('#component_total_display').text(total.toFixed(2));
                $('#total_marks_display').text(totalMarks.toFixed(2));
                
                const percentage = totalMarks > 0 ? (total / totalMarks) * 100 : 0;
                const progressBar = $('#component_progress');
                progressBar.css('width', Math.min(percentage, 100) + '%');
                
                if (percentage > 100) {
                    progressBar.removeClass('bg-success bg-warning').addClass('bg-danger');
                } else if (percentage > 80) {
                    progressBar.removeClass('bg-warning bg-danger').addClass('bg-success');
                } else if (percentage > 0) {
                    progressBar.removeClass('bg-success bg-danger').addClass('bg-warning');
                } else {
                    progressBar.removeClass('bg-success bg-warning bg-danger').addClass('bg-success');
                }
                
                validateMarks();
            }
            
            function validateMarks() {
                const totalMarks = parseFloat($('#total_marks').val()) || 0;
                const passingMarks = parseFloat($('#passing_marks').val()) || 0;
                const passingInput = $('#passing_marks');
                
                if (passingMarks > totalMarks) {
                    passingInput.addClass('is-invalid');
                    if (!passingInput.next('.invalid-feedback').length) {
                        $('<div class="invalid-feedback">Passing marks cannot exceed total marks!</div>').insertAfter(passingInput);
                    }
                } else {
                    passingInput.removeClass('is-invalid');
                    passingInput.next('.invalid-feedback').remove();
                }
                
                let componentTotal = 0;
                $('.component-marks').each(function() {
                    if ($(this).closest('.component-fields').hasClass('active')) {
                        componentTotal += parseFloat($(this).val()) || 0;
                    }
                });
                
                if (componentTotal > totalMarks) {
                    $('.component-section').addClass('border-danger');
                    $('.component-section').removeClass('border-primary-custom');
                    $('#component_total_display').addClass('text-danger');
                } else {
                    $('.component-section').removeClass('border-danger');
                    $('.component-section').addClass('border-primary-custom');
                    $('#component_total_display').removeClass('text-danger');
                }
            }
            
            $('#total_marks, #passing_marks, .component-marks').on('input', function() {
                calculateComponentTotal();
            });
            
            // Ripple effect for buttons
            $('.btn').on('click', function(e) {
                const $btn = $(this);
                const x = e.pageX - $btn.offset().left;
                const y = e.pageY - $btn.offset().top;
                
                $btn.append('<span class="ripple" style="top:' + y + 'px; left:' + x + 'px;"></span>');
                
                setTimeout(function() {
                    $btn.find('.ripple').remove();
                }, 600);
            });
            
            // Form submission validation
            $('#editExamForm').on('submit', function(e) {
                const totalMarks = parseFloat($('#total_marks').val()) || 0;
                const passingMarks = parseFloat($('#passing_marks').val()) || 0;
                let componentTotal = 0;
                
                $('.component-marks').each(function() {
                    if ($(this).closest('.component-fields').hasClass('active')) {
                        componentTotal += parseFloat($(this).val()) || 0;
                    }
                });
                
                if (passingMarks > totalMarks) {
                    e.preventDefault();
                    alert('Error: Passing marks cannot exceed total marks!');
                    return false;
                }
                
                if (componentTotal > totalMarks) {
                    e.preventDefault();
                    alert('Error: Sum of component marks cannot exceed total marks!');
                    return false;
                }
                
                if ($('.component-checkbox:checked').length === 0) {
                    e.preventDefault();
                    alert('Error: Please select at least one exam component!');
                    return false;
                }
                
                $('button[name="update_exam"]').html('<i class="fas fa-spinner fa-spin me-2"></i>Updating...');
                $('button[name="update_exam"]').prop('disabled', true);
                
                return true;
            });
            
            initializeComponentFields();
            calculateComponentTotal();
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);

            // Confirm before removing student
            $('#addStudentsForm').on('submit', function(e) {
                if ($('.select2-multiple').val().length === 0) {
                    e.preventDefault();
                    alert('Please select at least one student to add.');
                }
            });

            // Smooth scroll to top on form submission
            $('#editExamForm').on('submit', function() {
                $('html, body').animate({ scrollTop: 0 }, 500);
            });

            // Tab persistence
            var activeTab = localStorage.getItem('activeExamTab');
            if (activeTab) {
                $('#examTabs button[data-bs-target="' + activeTab + '"]').tab('show');
            }

            $('#examTabs button').on('shown.bs.tab', function (e) {
                localStorage.setItem('activeExamTab', $(e.target).attr('data-bs-target'));
            });

            $(window).on('unload', function() {
                localStorage.removeItem('activeExamTab');
            });
        });
    </script>
</body>
</html>