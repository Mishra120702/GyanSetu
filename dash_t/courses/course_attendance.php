<?php
// file: attendance.php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
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
        require_once '../../attendance/attendance_upload.php'; // Include the processing script
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
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');

        :root {
            --grad-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 43%, #456882 100%);
            --grad-blue: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --grad-green: linear-gradient(135deg, #10b981 0%, #14b8a6 100%);
            --grad-orange: linear-gradient(135deg, #f97316 0%, #f59e0b 100%);
            --ink: #172033;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(circle at 18% 8%, rgba(35,76,106, 0.18), transparent 28%),
                radial-gradient(circle at 90% 12%, rgba(69,104,130, 0.16), transparent 25%),
                linear-gradient(145deg, #f8fbff 0%, #eef4ff 48%, #f7f0ff 100%);
            color: var(--ink);
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(35,76,106, 0.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(35,76,106, 0.045) 1px, transparent 1px);
            background-size: 46px 46px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.9), rgba(0,0,0,.25));
            z-index: -2;
        }

        .page-shell { position: relative; }
        .page-shell::after {
            content: '';
            position: fixed;
            right: -180px;
            bottom: -220px;
            width: 520px;
            height: 520px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(35,76,106, 0.18), transparent 68%);
            z-index: -1;
            filter: blur(8px);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.90);
            border: 1px solid rgba(226, 232, 240, 0.90);
            border-radius: 24px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.075);
            backdrop-filter: blur(18px);
        }

        .gradient-text {
            background: var(--grad-main);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hero-card {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            background: var(--grad-main);
            color: white;
            box-shadow: 0 22px 50px rgba(35,76,106, 0.28);
        }
        .hero-card::before {
            content: '';
            position: absolute;
            inset: -90px -120px auto auto;
            width: 360px;
            height: 360px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
        }
        .hero-card::after {
            content: '';
            position: absolute;
            right: 9%;
            bottom: -95px;
            width: 260px;
            height: 260px;
            border-radius: 999px;
            background: rgba(255,255,255,0.10);
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .48rem .75rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, .15);
            border: 1px solid rgba(255,255,255,.22);
            font-weight: 800;
            font-size: .72rem;
            letter-spacing: .02em;
            backdrop-filter: blur(12px);
        }

        .hero-mini-card {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.24);
            border-radius: 18px;
            padding: 1rem;
            backdrop-filter: blur(14px);
        }

        .metric-card {
            position: relative;
            background: rgba(255,255,255,.94);
            border: 1px solid rgba(226,232,240,.95);
            border-radius: 20px;
            padding: 1rem 1.1rem;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .06);
            overflow: hidden;
            transition: .22s ease;
        }
        .metric-card:hover { transform: translateY(-3px); box-shadow: 0 18px 38px rgba(15, 23, 42, .10); }
        .metric-card::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--grad-main);
        }
        .metric-icon {
            width: 42px;
            height: 42px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            color: white;
            box-shadow: 0 12px 24px rgba(35,76,106,.20);
        }

        .control-input {
            width: 100%;
            border: 1px solid #e2e8f0;
            background: rgba(248,250,252,.95);
            color: #172033;
            border-radius: 16px;
            padding: .85rem 1rem;
            transition: .2s ease;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.6);
        }
        .control-input:focus {
            outline: none;
            border-color: #234C6A;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, .14);
            background: white;
        }

        .action-btn {
            border-radius: 16px;
            padding: .9rem 1rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .55rem;
            transition: all .22s ease;
            min-height: 48px;
        }
        .action-btn:hover { transform: translateY(-2px); filter: brightness(1.02); }
        .btn-green { background: var(--grad-green); color: white; box-shadow: 0 14px 26px rgba(16,185,129,.18); }
        .btn-purple { background: var(--grad-main); color: white; box-shadow: 0 14px 26px rgba(35,76,106,.22); }
        .btn-blue { background: var(--grad-blue); color: white; box-shadow: 0 14px 26px rgba(35,76,106,.18); }
        .btn-soft { background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.24); color: white; }

        .summary-card {
            position: relative;
            overflow: hidden;
            background: white;
            border-radius: 22px;
            border: 1px solid #EEF3F6;
            padding: 1.05rem 1.15rem;
            box-shadow: 0 12px 28px rgba(15,23,42,.065);
        }
        .summary-card::after {
            content: '';
            position: absolute;
            right: -28px;
            top: -28px;
            width: 82px;
            height: 82px;
            border-radius: 999px;
            background: rgba(35,76,106,.08);
        }

        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .data-table thead th {
            background: linear-gradient(90deg, #f8fbff, #f4f7ff);
            padding: 1rem 1.1rem;
            font-size: 0.85rem !important;
            font-weight: 800 !important;
            color: #1B3C53 !important;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
            visibility: visible !important;
            opacity: 1 !important;
        }
        .data-table tbody td {
            padding: .95rem 1.1rem;
            border-bottom: 1px solid #f1f5f9;
            color: #172033;
            font-size: .88rem;
            vertical-align: middle;
        }
        .data-table tbody tr:hover td { background: rgba(139, 92, 246, .045); }

        .table-card-top { background: linear-gradient(90deg, rgba(255,255,255,.94), rgba(247,242,255,.92)); }

        .badge-batch {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: linear-gradient(135deg, #F6F1ED 0%, #ede9fe 100%);
            color: #6d28d9;
            padding: .35rem .75rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 900;
            border: 1px solid #ddd6fe;
            white-space: nowrap;
        }

        .switch { position: relative; display: inline-block; width: 58px; height: 32px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #f43f5e;
            transition: .25s ease;
            border-radius: 999px;
            box-shadow: inset 0 2px 4px rgba(15,23,42,.12);
        }
        .slider:before {
            position: absolute;
            content: '';
            height: 26px;
            width: 26px;
            left: 3px;
            top: 3px;
            background: #fff;
            transition: .25s ease;
            border-radius: 999px;
            box-shadow: 0 4px 10px rgba(15,23,42,.16);
        }
        input:checked + .slider { background: #10b981; }
        input:checked + .slider:before { transform: translateX(26px); }
        .camera-slider input:checked + .slider { background: #234C6A; }
        .camera-slider.disabled { opacity: .52; cursor: not-allowed; }
        .camera-slider.disabled .slider { cursor: not-allowed; background: #cbd5e1; }
        .status-label {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            font-weight: 1000;
            color: white;
            pointer-events: none;
            transition: opacity .2s;
        }
        .status-present-label { left: 10px; opacity: 0; }
        .status-absent-label { right: 10px; opacity: 1; }
        input:checked + .slider .status-present-label { opacity: 1; }
        input:checked + .slider .status-absent-label { opacity: 0; }

        .toast-notification {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1000;
            animation: slideUp .28s ease;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .spinner { width: 44px; height: 44px; border: 4px solid #ede9fe; border-top-color: #234C6A; border-radius: 999px; animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .empty-state {
            background: linear-gradient(135deg, rgba(248,250,252,.95), rgba(245,243,255,.95));
        }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #EEF3F6; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #234C6A, #456882); border-radius: 999px; }


        /* ===== Visual-only polish: highlighted section/card theme, no new workflow ===== */
        .feature-shell {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(255,255,255,.94), rgba(248,250,255,.97));
            border: 1px solid rgba(226,232,240,.92);
            box-shadow: 0 18px 42px rgba(15,23,42,.075);
        }

        .feature-shell::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--grad-main));
        }

        .feature-shell::after {
            content: '';
            position: absolute;
            width: 210px;
            height: 210px;
            right: -70px;
            top: -70px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(10px);
            opacity: .8;
            pointer-events: none;
        }

        .feature-shell > * { position: relative; z-index: 1; }

        .feature-control {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.16), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-summary {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.16), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-records {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(59,130,246,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .36rem .72rem;
            border-radius: 999px;
            margin-bottom: .8rem;
            background: rgba(255,255,255,.82);
            border: 1px solid rgba(226,232,240,.9);
            color: #1B3C53;
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            box-shadow: 0 6px 18px rgba(15,23,42,.05);
        }

        .attendance-row td {
            background: rgba(255,255,255,.88);
        }

        .attendance-row {
            position: relative;
            overflow: hidden;
        }

        .attendance-row:hover td {
            background: linear-gradient(90deg, rgba(245,243,255,.95), rgba(255,241,248,.88));
        }

        .attendance-row td:first-child {
            border-left: 4px solid rgba(139,92,246,.45);
        }

        .table-action-footer {
            background: linear-gradient(90deg, rgba(255,255,255,.92), rgba(248,250,255,.92));
        }

        .summary-card {
            transition: all .22s ease;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 38px rgba(15,23,42,.10);
        }

        @media (max-width: 768px) {
            .hero-card { border-radius: 22px; }
            .glass-card { border-radius: 20px; }
            .switch { width: 50px; height: 28px; }
            .slider:before { height: 22px; width: 22px; }
            input:checked + .slider:before { transform: translateX(22px); }
            .toast-notification { right: 1rem; bottom: 1rem; left: 1rem; }
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

/* Force DataTables Headers to be visible */
table.dataTable thead th, 
table.dataTable thead td,
.data-table thead th {
    color: #1B3C53 !important;
    background-color: #f8fbff !important;
    opacity: 1 !important;
    visibility: visible !important;
    font-size: 0.85rem !important;
    font-weight: 800 !important;
    text-shadow: none !important;
    -webkit-text-fill-color: #1B3C53 !important;
}
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include '../t_sidebar.php'; ?>
    
    <div class="page-shell ml-0 lg:ml-64 transition-all duration-300 min-h-screen" id="main-content">
                        <!-- Mobile Header -->
        <div class="lg:hidden sticky top-0 z-40 bg-white/90 backdrop-blur-xl border-b border-slate-200 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <button id="mobileSidebarToggle" class="p-2 text-slate-600 hover:bg-slate-100 rounded-xl transition-colors">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-lg font-black gradient-text">Course Attendance</h1>
                </div>
            </div>
        </div>

        <!-- Desktop Header -->
        <header class="hidden lg:block bg-white/80 backdrop-blur-xl border-b border-slate-200 sticky top-0 z-40">
            <div class="px-8 py-4 flex justify-between items-center">
                <div class="flex items-center gap-3">
                    <div class="h-11 w-11 rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 flex items-center justify-center text-white shadow-card">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-black gradient-text">Course Attendance</h1>
                        <p class="text-slate-500 text-sm">Track presence for <?= htmlspecialchars($batch_name_display) ?> - <?= htmlspecialchars($course_name) ?></p>
                    </div>
                </div>
                <div>
                    <a href="my_courses.php?batch_id=<?= urlencode($preselected_batch) ?>&course_id=<?= urlencode($course_id) ?>" class="btn-soft text-slate-700 bg-white shadow-sm hover:bg-slate-50 border border-slate-200 px-4 py-2 rounded-xl text-sm font-semibold flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Course
                    </a>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6 lg:p-8">
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

                        

                        <!-- Hero Section -->
            <section class="hero-card p-6 md:p-8 mb-5 md:mb-6">
                <div class="relative z-10 grid grid-cols-1 xl:grid-cols-3 gap-6 items-center">
                    <div class="xl:col-span-2">
                        <div class="flex flex-wrap gap-2 mb-4">
                            <span class="hero-pill"><i class="fas fa-book"></i> <?= htmlspecialchars($course_name) ?></span>
                            <span class="hero-pill"><i class="fas fa-users"></i> <?= htmlspecialchars($batch_name_display) ?></span>
                        </div>
                        <h2 class="text-3xl md:text-4xl font-black tracking-tight mb-3">Course Attendance Workspace</h2>
                        <p class="text-white/85 max-w-3xl text-sm md:text-base leading-relaxed">
                            Mark manual attendance or import Excel sheets. Toggle between the tabs below.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Manual Attendance Section -->
            <div id="manualAttendanceSection">
                <!-- Filters Card -->
                <div class="glass-card mb-5" style="overflow: visible !important; position: relative; z-index: 50;">
                    <div class="flex flex-wrap gap-4 items-center" style="overflow: visible !important;">
                                                <input type="hidden" id="batchFilter" value="<?= htmlspecialchars($preselected_batch) ?>">
                        <select id="courseFilter" class="minimal-input" onchange="window.location.href='course_attendance.php?batch_id=<?= urlencode($preselected_batch) ?>&course_id=' + this.value + '&date=' + document.getElementById('dateFilter').value">
                            <option value="">-- Select Course --</option>
                            <?php foreach ($batch_courses as $bc): ?>
                            <option value="<?= htmlspecialchars($bc['id']) ?>" 
                                <?= ($course_id == $bc['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($bc['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="text" id="dateFilter" class="minimal-input date-picker" placeholder="Select date" value="<?= $preselected_date ?>">
                        
                        <button id="markAllPresent" class="action-btn btn-purple">
                            <i class="fas fa-check-circle mr-2"></i> Mark All Present
                        </button>
                        
                        <button id="loadAttendance" class="action-btn btn-purple">
                            <i class="fas fa-sync-alt mr-2"></i> Load Attendance
                        </button>
                        
                        <!-- Export Dropdown Button -->
                        <div class="relative inline-block">
                            <button id="exportDropdownBtn" class="action-btn btn-green w-full" onclick="toggleExportDropdown()">
                                <i class="fas fa-download mr-2"></i> Export <i class="fas fa-caret-down ml-1"></i>
                            </button>
                            <div id="exportDropdown" class="export-dropdown hidden" style="position: absolute; top: calc(100% + 5px); right: 0; z-index: 9999; min-width: 260px; background: white; border: 1px solid #cbd5e1; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);">
                                <a href="../../attendance/daily_attendance_export.php">
                                    <i class="fas fa-calendar-day text-blue-500"></i> Daily Export Page
                                </a>
                                <button onclick="quickExportCurrent()">
                                    <i class="fas fa-file-excel text-green-500"></i> Export Current View (Excel)
                                </button>
                                <button onclick="exportToCSV()">
                                    <i class="fas fa-file-csv text-blue-500"></i> Export Current View (CSV)
                                </button>
                                <hr>
                                <button onclick="exportAllBatchesToday()">
                                    <i class="fas fa-calendar-check text-purple-500"></i> All Batches - Today
                                </button>
                                <button onclick="exportWithDetails()">
                                    <i class="fas fa-info-circle text-orange-500"></i> Export with Student Details
                                </button>
                            </div>
                        </div>

                        <!-- Reports Button -->
                        <a href="../../attendance/attendance_reports.php?batch_id=<?= urlencode($preselected_batch) ?>&course_id=<?= urlencode($course_id) ?>" class="action-btn btn-purple" style="background-color: #6366f1; display: flex; align-items: center; justify-content: center; text-decoration: none;">
                            <i class="fas fa-chart-bar mr-2"></i> Reports
                        </a>
                    </div>
                </div>
                
                <!-- Attendance Table Card -->
                <div class="glass-card">
                    <div id="attendanceError" class="alert alert-error mb-4" style="display: none;">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span id="errorMessage">Error loading attendance data</span>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table id="attendanceTable" class="data-table" style="width:100%">
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
                        <button id="saveAttendance" class="action-btn btn-purple">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Upload Excel Section (initially hidden) -->
            <div id="uploadExcelSection" style="display: none;">
                <div class="glass-card">
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
                            <h4><i class="fas fa-info-circle mr-2 text-blue-500"></i>Excel Template Requirements</h4>
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
                                <a href="javascript:void(0)" onclick="downloadTemplate()" class="text-blue-500 hover:text-blue-700 text-sm font-medium">
                                    <i class="fas fa-download mr-1"></i> Download Excel Template
                                </a>
                            </div>
                        </div>
                        
                        <button type="submit" name="import" class="action-btn btn-purple mt-4">
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
                    <button type="button" id="cancelDeleteBtn" class="action-btn btn-purple">
                        <i class="fas fa-times mr-2"></i> Cancel
                    </button>
                    <button type="button" id="confirmDeleteBtn" class="action-btn bg-red-500 hover:bg-red-600 text-white shadow-sm">
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
                url: '../../attendance/course_attendance_api.php',
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
                url: '../../attendance/course_attendance_api.php',
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
        
        window.location.href = '../../attendance/daily_attendance_export.php?date=' + encodeURIComponent(date) + 
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
        
        window.location.href = '../../attendance/daily_attendance_export.php?date=' + encodeURIComponent(date) + 
                              '&batch_id=' + encodeURIComponent(batchId) + 
                              '&format=csv&export=true';
    }

    // Export all batches for today
    function exportAllBatchesToday() {
        const today = new Date().toISOString().split('T')[0];
        window.location.href = '../../attendance/daily_attendance_export.php?date=' + today + 
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
        
        window.location.href = '../../attendance/daily_attendance_export.php?date=' + encodeURIComponent(date) + 
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




