<?php
// view_submissions.php
session_start();
require_once '../db_connection.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../../logout_t.php");
    exit;
}

// Get trainer details
$trainer_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT t.* FROM trainers t WHERE t.user_id = ?");
$stmt->execute([$trainer_id]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    die("Trainer information not found");
}

// Get assignment ID from URL
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;

if (!$assignment_id) {
    header("Location: trainer_grade_assignments.php");
    exit;
}

// Verify trainer has access to this assignment
$assignment_stmt = $db->prepare("
    SELECT u.*, b.batch_name, b.batch_id,
           COUNT(DISTINCT s.student_id) as total_students,
           COUNT(DISTINCT sub.id) as total_submissions,
           COUNT(DISTINCT CASE WHEN sub.status = 'graded' THEN sub.id END) as graded_count,
           AVG(sub.grade) as avg_grade
    FROM uploads u
    JOIN batch_uploads bu ON u.id = bu.upload_id
    JOIN batches b ON bu.batch_id = b.batch_id
    LEFT JOIN students s ON s.batch_name_2 = b.batch_id OR s.batch_name_3 = b.batch_id
    LEFT JOIN assignment_submissions sub ON u.id = sub.upload_id AND s.student_id = sub.student_id
    WHERE u.id = ? AND u.file_type = 'Assignment' AND b.batch_mentor_id = ?
    GROUP BY u.id, b.batch_name, b.batch_id
");
$assignment_stmt->execute([$assignment_id, $trainer['id']]);
$assignment = $assignment_stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    header("Location: trainer_grade_assignments.php?error=Access denied or assignment not found");
    exit;
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'submitted_at';
$sort_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Build submissions query
$submissions_query = "
    SELECT 
        s.student_id,
        s.first_name,
        s.last_name,
        s.email,
        s.phone_number,
        sub.id as submission_id,
        sub.file_path,
        sub.submitted_at,
        sub.updated_at,
        sub.status,
        sub.grade,
        sub.feedback,
        sub.graded_at,
        u.max_marks,
        u.title as assignment_title,
        u.due_date,
        u.id as upload_id,
        u.file_type,
        u.uploaded_by,
        b.batch_name,
        b.batch_id,
        t.name as trainer_name,
        usr.name as grader_name
    FROM students s
    LEFT JOIN assignment_submissions sub ON s.student_id = sub.student_id AND sub.upload_id = ?
    LEFT JOIN uploads u ON sub.upload_id = u.id
    LEFT JOIN batches b ON b.batch_id = s.batch_name_2 OR b.batch_id = s.batch_name_3
    LEFT JOIN trainers t ON u.uploaded_by = t.id
    LEFT JOIN users usr ON sub.graded_by = usr.id
    WHERE (s.batch_name_2 = ? OR s.batch_name_3 = ?)
    AND s.current_status = 'active'
";

$params = [$assignment_id, $assignment['batch_id'], $assignment['batch_id']];

// Apply status filter
if ($status_filter === 'submitted') {
    $submissions_query .= " AND sub.id IS NOT NULL";
} elseif ($status_filter === 'graded') {
    $submissions_query .= " AND sub.status = 'graded'";
} elseif ($status_filter === 'pending') {
    $submissions_query .= " AND sub.status = 'pending'";
} elseif ($status_filter === 'not_submitted') {
    $submissions_query .= " AND sub.id IS NULL";
} elseif ($status_filter === 'late') {
    $submissions_query .= " AND sub.status = 'late'";
}

// Apply search filter
if (!empty($search_term)) {
    $submissions_query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_id LIKE ? OR s.email LIKE ?)";
    $search_pattern = "%$search_term%";
    $params = array_merge($params, [$search_pattern, $search_pattern, $search_pattern, $search_pattern]);
}

// Apply sorting
$allowed_sort_columns = ['first_name', 'last_name', 'submitted_at', 'grade', 'status'];
$sort_by = in_array($sort_by, $allowed_sort_columns) ? $sort_by : 'submitted_at';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// For sorting by name, we need to handle the full name
if ($sort_by === 'first_name') {
    $submissions_query .= " ORDER BY s.first_name $sort_order, s.last_name $sort_order";
} elseif ($sort_by === 'last_name') {
    $submissions_query .= " ORDER BY s.last_name $sort_order, s.first_name $sort_order";
} elseif ($sort_by === 'submitted_at') {
    // For sorting by submission date, handle NULL values
    $submissions_query .= " ORDER BY CASE WHEN sub.submitted_at IS NULL THEN 1 ELSE 0 END, sub.submitted_at $sort_order";
} elseif ($sort_by === 'grade') {
    $submissions_query .= " ORDER BY CASE WHEN sub.grade IS NULL THEN 1 ELSE 0 END, sub.grade $sort_order";
} else {
    $submissions_query .= " ORDER BY sub.$sort_by $sort_order";
}

$submissions_stmt = $db->prepare($submissions_query);
$submissions_stmt->execute($params);
$submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_students = count($submissions);
$submitted_count = 0;
$graded_count = 0;
$pending_count = 0;
$not_submitted_count = 0;
$late_count = 0;
$total_grade_sum = 0;
$grade_count = 0;

foreach ($submissions as $sub) {
    if ($sub['submission_id']) {
        $submitted_count++;
        if ($sub['status'] === 'graded') {
            $graded_count++;
            if ($sub['grade'] !== null) {
                $total_grade_sum += floatval($sub['grade']);
                $grade_count++;
            }
        } elseif ($sub['status'] === 'pending') {
            $pending_count++;
        } elseif ($sub['status'] === 'late') {
            $late_count++;
        }
    } else {
        $not_submitted_count++;
    }
}

$avg_grade = $grade_count > 0 ? round($total_grade_sum / $grade_count, 2) : null;
$submission_rate = $total_students > 0 ? round(($submitted_count / $total_students) * 100, 1) : 0;

// Build query string helper
function buildQueryString($newParams = []) {
    $params = $_GET;
    foreach ($newParams as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return http_build_query($params);
}

// Handle bulk grading
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_grade'])) {
    $submission_ids = isset($_POST['submission_ids']) ? $_POST['submission_ids'] : [];
    $bulk_grade = isset($_POST['bulk_grade_value']) ? floatval($_POST['bulk_grade_value']) : 0;
    $bulk_feedback = isset($_POST['bulk_feedback']) ? trim($_POST['bulk_feedback']) : '';
    
    if (!empty($submission_ids) && $bulk_grade >= 0) {
        try {
            $db->beginTransaction();
            
            foreach ($submission_ids as $sub_id) {
                // Verify the submission belongs to this trainer
                $verify_stmt = $db->prepare("
                    SELECT s.id 
                    FROM assignment_submissions s
                    JOIN uploads u ON s.upload_id = u.id
                    JOIN batch_uploads bu ON u.id = bu.upload_id
                    JOIN batches b ON bu.batch_id = b.batch_id
                    WHERE s.id = ? AND b.batch_mentor_id = ?
                ");
                $verify_stmt->execute([$sub_id, $trainer['id']]);
                if ($verify_stmt->fetch()) {
                    $update_stmt = $db->prepare("
                        UPDATE assignment_submissions 
                        SET grade = :grade, 
                            feedback = :feedback, 
                            status = 'graded',
                            graded_by = :graded_by,
                            graded_at = NOW()
                        WHERE id = :id
                    ");
                    $update_stmt->execute([
                        ':grade' => $bulk_grade,
                        ':feedback' => $bulk_feedback,
                        ':graded_by' => $trainer_id,
                        ':id' => $sub_id
                    ]);
                }
            }
            
            $db->commit();
            $_SESSION['success'] = 'Successfully graded ' . count($submission_ids) . ' submissions';
            header("Location: view_submissions.php?assignment_id=" . $assignment_id . "&" . http_build_query($_GET));
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Error grading submissions: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Please select at least one submission and enter a valid grade';
    }
}

// Handle individual grade submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission'])) {
    $submission_id = intval($_POST['submission_id']);
    $grade = floatval($_POST['grade']);
    $feedback = trim($_POST['feedback']);
    
    if ($grade >= 0) {
        try {
            $update_stmt = $db->prepare("
                UPDATE assignment_submissions 
                SET grade = :grade, 
                    feedback = :feedback, 
                    status = 'graded',
                    graded_by = :graded_by,
                    graded_at = NOW()
                WHERE id = :id
            ");
            $update_stmt->execute([
                ':grade' => $grade,
                ':feedback' => $feedback,
                ':graded_by' => $trainer_id,
                ':id' => $submission_id
            ]);
            
            $_SESSION['success'] = 'Submission graded successfully';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error grading submission: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Please enter a valid grade';
    }
    
    header("Location: view_submissions.php?assignment_id=" . $assignment_id . "&" . http_build_query($_GET));
    exit;
}

// Handle individual submission deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_submission'])) {
    $submission_id = intval($_POST['submission_id']);
    
    try {
        $delete_stmt = $db->prepare("DELETE FROM assignment_submissions WHERE id = ?");
        $delete_stmt->execute([$submission_id]);
        $_SESSION['success'] = 'Submission deleted successfully';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting submission: ' . $e->getMessage();
    }
    
    header("Location: view_submissions.php?assignment_id=" . $assignment_id . "&" . http_build_query($_GET));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submissions - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --brand-dark: #1B3C53;
            --brand-primary: #234C6A;
            --brand-secondary: #456882;
            --brand-soft: #D2C1B6;
            --theme-navy: linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%);
        }
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83,0.10), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130,0.10), transparent 30%),
                linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
            min-height: 100vh;
        }
        
        .gradient-header {
            background: var(--theme-navy) !important;
            border-bottom: 1.5px solid rgba(255,255,255,0.22) !important;
            box-shadow: 0 20px 44px rgba(27,60,53,0.22) !important;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.92) !important;
            border: 1.5px solid rgba(210,193,182,0.50) !important;
            border-radius: 18px !important;
            box-shadow: 0 12px 28px rgba(27,60,53,0.08) !important;
            transition: all 0.3s ease !important;
        }
        
        .stat-card:hover {
            transform: translateY(-4px) !important;
            box-shadow: 0 20px 40px rgba(27,60,53,0.14) !important;
            border-color: rgba(69,104,130,0.40) !important;
        }
        
        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }
        
        .card-main {
            background: rgba(255,255,255,0.94) !important;
            border: 1.5px solid rgba(210,193,182,0.50) !important;
            border-radius: 22px !important;
            box-shadow: 0 16px 38px rgba(27,60,53,0.08) !important;
        }
        
        .card-main:hover {
            border-color: rgba(69,104,130,0.30) !important;
        }
        
        .filter-section {
            background: rgba(238,243,246,0.80) !important;
            border: 1.5px solid rgba(210,193,182,0.50) !important;
            border-radius: 16px !important;
        }
        
        .submission-row {
            transition: all 0.2s ease !important;
            border-bottom: 1px solid rgba(210,193,182,0.25) !important;
        }
        
        .submission-row:hover {
            background: rgba(238,243,246,0.50) !important;
        }
        
        .submission-row:last-child {
            border-bottom: none !important;
        }
        
        .badge-status {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        .badge-graded {
            background: #d1fae5 !important;
            color: #065f46 !important;
        }
        
        .badge-pending {
            background: #fef3c7 !important;
            color: #92400e !important;
        }
        
        .badge-late {
            background: #fecaca !important;
            color: #991b1b !important;
        }
        
        .badge-not-submitted {
            background: #f3f4f6 !important;
            color: #4b5563 !important;
        }
        
        .badge-submitted {
            background: #dbeafe !important;
            color: #1e40af !important;
        }
        
        .btn-primary {
            background: var(--theme-navy) !important;
            color: #ffffff !important;
            border: 1.3px solid rgba(255,255,255,0.30) !important;
            border-radius: 12px !important;
            font-weight: 700 !important;
            box-shadow: 0 10px 24px rgba(27,60,53,0.18) !important;
            transition: all 0.3s ease !important;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 16px 32px rgba(27,60,53,0.25) !important;
        }
        
        .btn-outline {
            background: transparent !important;
            border: 1.5px solid rgba(69,104,130,0.35) !important;
            color: #234C6A !important;
            border-radius: 12px !important;
            font-weight: 600 !important;
            transition: all 0.3s ease !important;
        }
        
        .btn-outline:hover {
            background: rgba(27,60,53,0.06) !important;
            border-color: #234C6A !important;
        }
        
        .btn-danger-outline {
            background: transparent !important;
            border: 1.5px solid rgba(239,68,68,0.35) !important;
            color: #dc2626 !important;
            border-radius: 12px !important;
            font-weight: 600 !important;
            transition: all 0.3s ease !important;
        }
        
        .btn-danger-outline:hover {
            background: rgba(239,68,68,0.08) !important;
            border-color: #dc2626 !important;
        }
        
        .grade-input {
            width: 80px !important;
            border: 1.5px solid rgba(210,193,182,0.50) !important;
            border-radius: 10px !important;
            padding: 0.4rem 0.6rem !important;
            font-weight: 600 !important;
            transition: all 0.2s ease !important;
        }
        
        .grade-input:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 3px rgba(35,76,106,0.15) !important;
            outline: none !important;
        }
        
        .feedback-input {
            border: 1.5px solid rgba(210,193,182,0.50) !important;
            border-radius: 10px !important;
            padding: 0.4rem 0.6rem !important;
            font-size: 0.85rem !important;
            transition: all 0.2s ease !important;
        }
        
        .feedback-input:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 3px rgba(35,76,106,0.12) !important;
            outline: none !important;
        }
        
        .avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            color: white;
            flex-shrink: 0;
        }
        
        .pagination-btn {
            border-radius: 10px !important;
            font-weight: 600 !important;
            transition: all 0.2s ease !important;
        }
        
        .pagination-btn:hover {
            transform: translateY(-2px) !important;
        }
        
        .pagination-active {
            background: var(--theme-navy) !important;
            color: white !important;
            border-color: transparent !important;
        }
        
        .checkbox-custom {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 2px solid #d1d5db;
            accent-color: #234C6A;
            cursor: pointer;
        }
        
        .checkbox-custom:checked {
            border-color: #234C6A;
        }
        
        .sortable-header {
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }
        
        .sortable-header:hover {
            color: #234C6A !important;
        }
        
        .submission-file-link {
            color: #234C6A !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
        }
        
        .submission-file-link:hover {
            color: #1B3C53 !important;
            text-decoration: underline !important;
        }
        
        .grading-form {
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 768px) {
            .stat-card {
                padding: 1rem !important;
            }
            
            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            
            .grade-input {
                width: 70px !important;
                font-size: 0.8rem !important;
            }
            
            .feedback-input {
                font-size: 0.75rem !important;
            }
            
            .table-responsive {
                overflow-x: auto !important;
            }
            
            .table-responsive table {
                min-width: 800px !important;
            }
        }
        
        @media (max-width: 480px) {
            .stat-card {
                padding: 0.75rem !important;
            }
            
            .stat-icon {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }
            
            .badge-status {
                font-size: 0.6rem !important;
                padding: 0.15rem 0.5rem !important;
            }
        }
        
        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(210,193,182,0.20);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #1B3C53, #456882);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #234C6A, #1B3C53);
        }
    </style>
</head>
<body>
<?php include '../t_sidebar.php'; ?>

<div class="flex-1 ml-0 lg:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <!-- Header -->
    <header class="gradient-header text-white shadow-xl px-4 sm:px-6 py-4 sm:py-6 sticky top-0 z-30">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div class="flex items-center space-x-3 sm:space-x-4">
                <div class="p-2.5 sm:p-3 bg-white/20 rounded-2xl backdrop-blur-sm">
                    <i class="fas fa-file-alt text-xl sm:text-2xl"></i>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold">View Submissions</h1>
                    <p class="text-white/80 text-xs sm:text-sm mt-0.5">
                        <?= htmlspecialchars($assignment['title']) ?> - <?= htmlspecialchars($assignment['batch_name']) ?>
                    </p>
                </div>
            </div>
            <div class="mt-3 md:mt-0 flex flex-wrap items-center gap-2">
                <a href="trainer_grade_assignments.php" class="px-3 sm:px-4 py-1.5 sm:py-2 bg-white/20 hover:bg-white/30 rounded-xl text-white text-sm font-medium transition-all backdrop-blur-sm border border-white/20 flex items-center">
                    <i class="fas fa-arrow-left mr-2 text-sm"></i>
                    Back
                </a>
                <a href="../<?= htmlspecialchars($assignment['file_path']) ?>" download class="px-3 sm:px-4 py-1.5 sm:py-2 bg-white/20 hover:bg-white/30 rounded-xl text-white text-sm font-medium transition-all backdrop-blur-sm border border-white/20 flex items-center">
                    <i class="fas fa-download mr-2 text-sm"></i>
                    Download Assignment
                </a>
            </div>
        </div>
    </header>

    <div class="p-3 sm:p-4 md:p-6">
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-2xl shadow-sm animate__animated animate__fadeIn">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-green-800 font-medium text-sm sm:text-base"><?= htmlspecialchars($_SESSION['success']) ?></p>
                    </div>
                    <div class="ml-auto">
                        <button onclick="this.parentElement.parentElement.remove()" class="text-green-600 hover:text-green-800">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-gradient-to-r from-red-50 to-rose-50 border border-red-200 rounded-2xl shadow-sm animate__animated animate__fadeIn">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-red-800 font-medium text-sm sm:text-base"><?= htmlspecialchars($_SESSION['error']) ?></p>
                    </div>
                    <div class="ml-auto">
                        <button onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 sm:gap-4 mb-4 sm:mb-6">
            <div class="stat-card p-3 sm:p-4 flex items-center space-x-3">
                <div class="stat-icon bg-gradient-to-br from-blue-500 to-indigo-600 text-white shadow-md">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 font-medium">Total Students</p>
                    <p class="text-xl sm:text-2xl font-bold text-gray-900"><?= $total_students ?></p>
                </div>
            </div>
            
            <div class="stat-card p-3 sm:p-4 flex items-center space-x-3">
                <div class="stat-icon bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-md">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 font-medium">Submitted</p>
                    <p class="text-xl sm:text-2xl font-bold text-gray-900"><?= $submitted_count ?></p>
                    <p class="text-xs text-gray-400"><?= $submission_rate ?>%</p>
                </div>
            </div>
            
            <div class="stat-card p-3 sm:p-4 flex items-center space-x-3">
                <div class="stat-icon bg-gradient-to-br from-yellow-500 to-orange-600 text-white shadow-md">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 font-medium">Pending</p>
                    <p class="text-xl sm:text-2xl font-bold text-gray-900"><?= $pending_count ?></p>
                </div>
            </div>
            
            <div class="stat-card p-3 sm:p-4 flex items-center space-x-3">
                <div class="stat-icon bg-gradient-to-br from-purple-500 to-pink-600 text-white shadow-md">
                    <i class="fas fa-star"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 font-medium">Graded</p>
                    <p class="text-xl sm:text-2xl font-bold text-gray-900"><?= $graded_count ?></p>
                </div>
            </div>
            
            <div class="stat-card p-3 sm:p-4 flex items-center space-x-3">
                <div class="stat-icon bg-gradient-to-br from-red-500 to-rose-600 text-white shadow-md">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 font-medium">Late</p>
                    <p class="text-xl sm:text-2xl font-bold text-gray-900"><?= $late_count ?></p>
                </div>
            </div>
            
            <div class="stat-card p-3 sm:p-4 flex items-center space-x-3">
                <div class="stat-icon bg-gradient-to-br from-gray-500 to-slate-600 text-white shadow-md">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 font-medium">Not Submitted</p>
                    <p class="text-xl sm:text-2xl font-bold text-gray-900"><?= $not_submitted_count ?></p>
                </div>
            </div>
        </div>

        <!-- Assignment Info -->
        <div class="card-main p-4 sm:p-6 mb-4 sm:mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900"><?= htmlspecialchars($assignment['title']) ?></h2>
                    <div class="flex flex-wrap items-center gap-3 mt-2 text-sm text-gray-600">
                        <span class="flex items-center">
                            <i class="fas fa-book mr-1.5 text-gray-400"></i>
                            <?= htmlspecialchars($assignment['batch_name']) ?>
                        </span>
                        <span class="flex items-center">
                            <i class="fas fa-calendar-alt mr-1.5 text-gray-400"></i>
                            Due: <?= date('M j, Y', strtotime($assignment['due_date'])) ?>
                        </span>
                        <span class="flex items-center">
                            <i class="fas fa-star mr-1.5 text-yellow-400"></i>
                            Max Marks: <?= $assignment['max_marks'] ?>
                        </span>
                        <?php if ($avg_grade !== null): ?>
                            <span class="flex items-center">
                                <i class="fas fa-chart-line mr-1.5 text-green-500"></i>
                                Avg Grade: <?= $avg_grade ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-3 md:mt-0 flex items-center space-x-2">
                    <span class="text-sm font-medium text-gray-500">Submissions:</span>
                    <span class="bg-gradient-to-r from-indigo-500 to-purple-600 text-white px-3 py-1 rounded-full text-sm font-bold">
                        <?= $submitted_count ?>/<?= $total_students ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section p-3 sm:p-4 mb-4 sm:mb-6">
            <form method="GET" class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                
                <div class="flex-1 min-w-[150px]">
                    <input type="text" name="search" value="<?= htmlspecialchars($search_term) ?>" 
                           placeholder="Search student..." 
                           class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                </div>
                
                <div class="min-w-[140px]">
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Students</option>
                        <option value="submitted" <?= $status_filter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="graded" <?= $status_filter === 'graded' ? 'selected' : '' ?>>Graded</option>
                        <option value="late" <?= $status_filter === 'late' ? 'selected' : '' ?>>Late</option>
                        <option value="not_submitted" <?= $status_filter === 'not_submitted' ? 'selected' : '' ?>>Not Submitted</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-primary px-4 py-2 text-white rounded-xl flex items-center space-x-2 text-sm">
                    <i class="fas fa-filter"></i>
                    <span>Filter</span>
                </button>
                
                <a href="view_submissions.php?assignment_id=<?= $assignment_id ?>" class="btn-outline px-4 py-2 rounded-xl flex items-center space-x-2 text-sm">
                    <i class="fas fa-sync-alt"></i>
                    <span>Reset</span>
                </a>
            </form>
        </div>

        <!-- Submissions Table -->
        <div class="card-main p-4 sm:p-6">
            <!-- Bulk Actions -->
            <?php if ($submitted_count > 0): ?>
            <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
                <div class="flex items-center space-x-3">
                    <label class="flex items-center space-x-2 text-sm text-gray-600">
                        <input type="checkbox" id="selectAll" class="checkbox-custom" onchange="toggleAllCheckboxes()">
                        <span>Select All</span>
                    </label>
                    <span class="text-sm text-gray-400">|</span>
                    <span class="text-sm text-gray-500" id="selectedCount">0 selected</span>
                </div>
                
                <div class="flex flex-wrap items-center gap-2">
                    <input type="number" id="bulkGrade" placeholder="Grade" 
                           class="w-24 px-3 py-1.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                    <input type="text" id="bulkFeedback" placeholder="Feedback (optional)" 
                           class="w-40 px-3 py-1.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                    <button onclick="submitBulkGrade()" class="btn-primary px-4 py-1.5 text-white rounded-xl flex items-center space-x-2 text-sm">
                        <i class="fas fa-check-double"></i>
                        <span>Bulk Grade</span>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Table -->
            <div class="table-responsive overflow-x-auto">
                <table class="w-full divide-y divide-gray-200">
                    <thead>
                        <tr class="bg-gradient-to-r from-indigo-50/50 to-purple-50/50">
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                <input type="checkbox" id="selectAllTable" class="checkbox-custom" onchange="toggleAllCheckboxes()">
                            </th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider sortable-header" onclick="sortTable('first_name')">
                                <div class="flex items-center">
                                    <span>Student</span>
                                    <i class="fas fa-sort ml-1 text-gray-400"></i>
                                </div>
                            </th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider sortable-header" onclick="sortTable('status')">
                                <div class="flex items-center">
                                    <span>Status</span>
                                    <i class="fas fa-sort ml-1 text-gray-400"></i>
                                </div>
                            </th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider sortable-header" onclick="sortTable('submitted_at')">
                                <div class="flex items-center">
                                    <span>Submitted</span>
                                    <i class="fas fa-sort ml-1 text-gray-400"></i>
                                </div>
                            </th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider sortable-header" onclick="sortTable('grade')">
                                <div class="flex items-center">
                                    <span>Grade</span>
                                    <i class="fas fa-sort ml-1 text-gray-400"></i>
                                </div>
                            </th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($submissions)): ?>
                            <tr>
                                <td colspan="6" class="px-3 py-8 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                            <i class="fas fa-inbox text-2xl text-gray-400"></i>
                                        </div>
                                        <p class="text-gray-500 font-medium">No students found</p>
                                        <p class="text-gray-400 text-sm">No active students are enrolled in this batch</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($submissions as $index => $sub): 
                                $has_submission = !empty($sub['submission_id']);
                                $is_graded = $sub['status'] === 'graded';
                                $is_pending = $sub['status'] === 'pending';
                                $is_late = $sub['status'] === 'late';
                                $is_not_submitted = empty($sub['submission_id']);
                                
                                // Get initials
                                $initials = strtoupper(substr($sub['first_name'], 0, 1) . substr($sub['last_name'], 0, 1));
                                
                                // Avatar color based on name
                                $colors = ['#1B3C53', '#234C6A', '#456882', '#D2C1B6'];
                                $color_index = abs(crc32($sub['first_name'] . $sub['last_name'])) % 4;
                                $avatar_color = $colors[$color_index];
                            ?>
                            <tr class="submission-row">
                                <td class="px-3 py-3">
                                    <?php if ($has_submission): ?>
                                        <input type="checkbox" class="checkbox-custom submission-checkbox" value="<?= $sub['submission_id'] ?>" onchange="updateSelectedCount()">
                                    <?php else: ?>
                                        <span class="text-gray-300 text-sm">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center space-x-3">
                                        <div class="avatar-circle" style="background: <?= $avatar_color ?>">
                                            <?= $initials ?>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900 text-sm"><?= htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name']) ?></p>
                                            <p class="text-xs text-gray-500"><?= htmlspecialchars($sub['student_id']) ?></p>
                                            <?php if (!empty($sub['email'])): ?>
                                                <p class="text-xs text-gray-400"><?= htmlspecialchars($sub['email']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <?php if ($is_not_submitted): ?>
                                        <span class="badge-status badge-not-submitted">
                                            <i class="fas fa-times-circle mr-1"></i> Not Submitted
                                        </span>
                                    <?php elseif ($is_graded): ?>
                                        <span class="badge-status badge-graded">
                                            <i class="fas fa-check-circle mr-1"></i> Graded
                                        </span>
                                    <?php elseif ($is_pending): ?>
                                        <span class="badge-status badge-pending">
                                            <i class="fas fa-clock mr-1"></i> Pending
                                        </span>
                                    <?php elseif ($is_late): ?>
                                        <span class="badge-status badge-late">
                                            <i class="fas fa-exclamation-triangle mr-1"></i> Late
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-status badge-submitted">
                                            <i class="fas fa-upload mr-1"></i> Submitted
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($has_submission && !$is_not_submitted): ?>
                                        <div class="mt-1">
                                            <a href="../<?= htmlspecialchars($sub['file_path']) ?>" target="_blank" class="submission-file-link text-xs">
                                                <i class="fas fa-file-pdf mr-0.5"></i> View File
                                            </a>
                                            <span class="text-gray-300 mx-1">|</span>
                                            <a href="../<?= htmlspecialchars($sub['file_path']) ?>" download class="submission-file-link text-xs">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    <?php if ($has_submission): ?>
                                        <div class="text-gray-700">
                                            <?= date('M j, Y', strtotime($sub['submitted_at'])) ?>
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            <?= date('h:i A', strtotime($sub['submitted_at'])) ?>
                                        </div>
                                        <?php if ($sub['updated_at'] && $sub['updated_at'] != $sub['submitted_at']): ?>
                                            <div class="text-xs text-gray-400 mt-0.5">
                                                <i class="fas fa-edit mr-0.5"></i> Updated: <?= date('M j, Y', strtotime($sub['updated_at'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-sm">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3">
                                    <?php if ($is_graded): ?>
                                        <div class="flex items-center space-x-2">
                                            <span class="font-bold text-lg text-green-700"><?= $sub['grade'] ?></span>
                                            <span class="text-sm text-gray-400">/ <?= $assignment['max_marks'] ?></span>
                                        </div>
                                        <?php if (!empty($sub['feedback'])): ?>
                                            <div class="text-xs text-gray-500 mt-1 max-w-[150px] truncate">
                                                <i class="fas fa-comment mr-0.5"></i> <?= htmlspecialchars($sub['feedback']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($sub['graded_at'])): ?>
                                            <div class="text-xs text-gray-400 mt-0.5">
                                                <i class="fas fa-check mr-0.5"></i> <?= date('M j, Y', strtotime($sub['graded_at'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($has_submission): ?>
                                        <!-- Individual grading form -->
                                        <form method="POST" class="grading-form flex flex-wrap items-center gap-2" onsubmit="return validateGrade(this)">
                                            <input type="hidden" name="submission_id" value="<?= $sub['submission_id'] ?>">
                                            <input type="number" name="grade" step="0.01" min="0" max="<?= $assignment['max_marks'] ?>"
                                                   placeholder="Grade" 
                                                   class="grade-input text-sm" 
                                                   required>
                                            <input type="text" name="feedback" placeholder="Feedback" 
                                                   class="feedback-input text-sm min-w-[120px]">
                                            <button type="submit" name="grade_submission" class="btn-primary px-3 py-1.5 text-white rounded-xl text-xs flex items-center space-x-1">
                                                <i class="fas fa-check"></i>
                                                <span>Grade</span>
                                            </button>
                                            <button type="button" onclick="deleteSubmission(<?= $sub['submission_id'] ?>, '<?= htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name']) ?>')" 
                                                    class="btn-danger-outline px-2 py-1 rounded-xl text-xs flex items-center space-x-1">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-sm">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3">
                                    <?php if ($has_submission): ?>
                                        <button onclick="toggleGrading(<?= $sub['submission_id'] ?>)" 
                                                class="text-blue-600 hover:text-blue-800 text-sm font-medium transition-colors">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-sm">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Footer -->
            <div class="flex flex-col sm:flex-row justify-between items-center mt-4 gap-3">
                <div class="text-sm text-gray-500">
                    Showing <span class="font-medium"><?= count($submissions) ?></span> students
                </div>
                <div class="text-sm text-gray-500">
                    <span class="font-medium"><?= $submitted_count ?></span> submitted · 
                    <span class="font-medium text-green-600"><?= $graded_count ?></span> graded · 
                    <span class="font-medium text-yellow-600"><?= $pending_count ?></span> pending
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Grade Form -->
<form id="bulkGradeForm" method="POST" class="hidden">
    <input type="hidden" name="bulk_grade" value="1">
    <input type="hidden" name="bulk_grade_value" id="bulkGradeValue">
    <input type="hidden" name="bulk_feedback" id="bulkFeedbackValue">
    <input type="hidden" name="submission_ids" id="bulkSubmissionIds">
</form>

<?php include '../../footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar functionality
    const sidebar = document.querySelector('aside');
    const mobileToggleBtn = document.getElementById('mobileSidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    function showSidebar() {
        if (sidebar) sidebar.classList.remove('-translate-x-full');
        if (sidebarOverlay) sidebarOverlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    
    function hideSidebar() {
        if (sidebar) sidebar.classList.add('-translate-x-full');
        if (sidebarOverlay) sidebarOverlay.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
    
    if (mobileToggleBtn) mobileToggleBtn.addEventListener('click', showSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', hideSidebar);
    
    document.addEventListener('click', function(event) {
        if (window.innerWidth < 1024 && 
            sidebar && !sidebar.contains(event.target) && 
            mobileToggleBtn && !mobileToggleBtn.contains(event.target) && 
            !sidebar.classList.contains('-translate-x-full')) {
            hideSidebar();
        }
    });
    
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            if (sidebar) sidebar.classList.remove('-translate-x-full');
            if (sidebarOverlay) sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    });
    
    if (window.innerWidth >= 1024 && sidebar) {
        sidebar.classList.remove('-translate-x-full');
    }
});

function toggleSidebar() {
    const sidebar = document.querySelector('aside');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar && sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        if (overlay) overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    } else if (sidebar) {
        sidebar.classList.add('-translate-x-full');
        if (overlay) overlay.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
}

function toggleAllCheckboxes() {
    const masterCheckbox = document.getElementById('selectAll') || document.getElementById('selectAllTable');
    const checkboxes = document.querySelectorAll('.submission-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = masterCheckbox.checked;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.submission-checkbox:checked');
    const count = checkboxes.length;
    const selectedCountEl = document.getElementById('selectedCount');
    if (selectedCountEl) {
        selectedCountEl.textContent = count + ' selected';
    }
}

function submitBulkGrade() {
    const checkboxes = document.querySelectorAll('.submission-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);
    const grade = document.getElementById('bulkGrade').value;
    const feedback = document.getElementById('bulkFeedback').value;
    
    if (ids.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Selection',
            text: 'Please select at least one submission to grade',
            confirmButtonColor: '#234C6A'
        });
        return;
    }
    
    if (!grade || parseFloat(grade) < 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Grade',
            text: 'Please enter a valid grade value',
            confirmButtonColor: '#234C6A'
        });
        return;
    }
    
    const maxMarks = <?= $assignment['max_marks'] ?>;
    if (parseFloat(grade) > maxMarks) {
        Swal.fire({
            icon: 'error',
            title: 'Grade Too High',
            text: `Grade cannot exceed ${maxMarks} marks`,
            confirmButtonColor: '#234C6A'
        });
        return;
    }
    
    Swal.fire({
        title: 'Confirm Bulk Grading',
        text: `You are about to grade ${ids.length} submission(s) with ${grade} marks${feedback ? ' and feedback: "' + feedback + '"' : ''}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#234C6A',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, Grade All'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('bulkGradeValue').value = grade;
            document.getElementById('bulkFeedbackValue').value = feedback;
            document.getElementById('bulkSubmissionIds').value = ids.join(',');
            document.getElementById('bulkGradeForm').submit();
        }
    });
}

function sortTable(column) {
    const currentUrl = new URL(window.location.href);
    const currentSort = currentUrl.searchParams.get('sort');
    const currentOrder = currentUrl.searchParams.get('order');
    
    let newOrder = 'DESC';
    if (currentSort === column) {
        newOrder = currentOrder === 'DESC' ? 'ASC' : 'DESC';
    }
    
    currentUrl.searchParams.set('sort', column);
    currentUrl.searchParams.set('order', newOrder);
    window.location.href = currentUrl.toString();
}

function toggleGrading(submissionId) {
    // Find the grading form for this submission and toggle visibility
    const forms = document.querySelectorAll('form.grading-form');
    // This is a simplified approach - you can expand this to toggle visibility
    // of the grade input fields
}

function validateGrade(form) {
    const gradeInput = form.querySelector('input[name="grade"]');
    const maxMarks = <?= $assignment['max_marks'] ?>;
    const grade = parseFloat(gradeInput.value);
    
    if (isNaN(grade) || grade < 0) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Grade',
            text: 'Please enter a valid grade (0 or higher)',
            confirmButtonColor: '#234C6A'
        });
        return false;
    }
    
    if (grade > maxMarks) {
        Swal.fire({
            icon: 'error',
            title: 'Grade Too High',
            text: `Grade cannot exceed ${maxMarks} marks`,
            confirmButtonColor: '#234C6A'
        });
        return false;
    }
    
    return true;
}

function deleteSubmission(submissionId, studentName) {
    Swal.fire({
        title: 'Delete Submission',
        text: `Are you sure you want to delete the submission from ${studentName}? This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            // Create a form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_submission';
            input.value = '1';
            form.appendChild(input);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'submission_id';
            idInput.value = submissionId;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.animate__animated.animate__fadeIn');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert.parentElement) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentElement) alert.remove();
                }, 500);
            }
        }, 5000);
    });
});
</script>
</body>
</html>