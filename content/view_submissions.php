<?php
include '../db_connection.php';
session_start();

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$assignment_id = $_GET['id'] ?? 0;

// Get assignment details
$assignment_stmt = $db->prepare("
    SELECT u.*, b.batch_name 
    FROM uploads u
    JOIN batch_uploads bu ON u.id = bu.upload_id
    JOIN batches b ON bu.batch_id = b.batch_id
    WHERE u.id = ? AND u.file_type = 'Assignment'
");
$assignment_stmt->execute([$assignment_id]);
$assignment = $assignment_stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    die("Assignment not found");
}

// Get all submissions for this assignment
$submissions_stmt = $db->prepare("
    SELECT s.*, st.first_name, st.last_name, st.student_id, u.name as graded_by_name
    FROM assignment_submissions s
    JOIN students st ON s.student_id = st.student_id
    LEFT JOIN users u ON s.graded_by = u.id
    WHERE s.upload_id = ?
    ORDER BY s.submitted_at DESC
");
$submissions_stmt->execute([$assignment_id]);
$submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle grading
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission'])) {
    $submission_id = $_POST['submission_id'];
    $grade = $_POST['grade'];
    $feedback = $_POST['feedback'];
    
    $update = $db->prepare("
        UPDATE assignment_submissions 
        SET grade = :grade, 
            feedback = :feedback, 
            status = 'graded',
            graded_by = :graded_by,
            graded_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':grade' => $grade,
        ':feedback' => $feedback,
        ':graded_by' => $_SESSION['user_id'],
        ':id' => $submission_id
    ]);
    
    header("Location: view_submissions.php?id=$assignment_id&message=Graded successfully");
    exit;
}

// Calculate statistics
$total_submissions = count($submissions);
$graded_count = 0;
$pending_count = 0;
$late_count = 0;
$total_grade = 0;
$avg_grade = 0;

foreach ($submissions as $sub) {
    if ($sub['status'] === 'graded' && $sub['grade'] !== null) {
        $graded_count++;
        $total_grade += $sub['grade'];
    } elseif ($sub['status'] === 'submitted') {
        $pending_count++;
    }
    
    // Check if late
    if ($assignment['due_date']) {
        $due_datetime = new DateTime($assignment['due_date'], new DateTimeZone('Asia/Kolkata'));
        if (!empty($assignment['due_time'])) {
            $time_parts = explode(':', $assignment['due_time']);
            $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
        } else {
            $due_datetime->setTime(23, 59, 59);
        }
        $submitted_datetime = new DateTime($sub['submitted_at'], new DateTimeZone('Asia/Kolkata'));
        if ($submitted_datetime > $due_datetime) {
            $late_count++;
        }
    }
}

if ($graded_count > 0) {
    $avg_grade = round($total_grade / $graded_count, 2);
}

$submission_percentage = $total_submissions > 0 ? round(($graded_count / $total_submissions) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment Submissions - ASD Academy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --deepest-navy: #1B3C53;
            --dark-steel: #234C6A;
            --mid-steel: #456882;
            --warm-sand: #D2C1B6;
            --soft-sky: #A4C4D4;
            --white: #ffffff;
            --terracotta: #C97B50;
            --amber: #f59e0b;
            --success-green: #166534;
            --danger-red: #C0392B;
        }

        * {
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
        }

        body {
            background:
                radial-gradient(1100px 500px at 100% -8%, rgba(69,104,130,.22), transparent 55%),
                radial-gradient(900px 450px at -10% 108%, rgba(27,60,83,.16), transparent 55%),
                radial-gradient(rgba(27,60,83,.045) 1px, transparent 1px) 0 0 / 22px 22px,
                linear-gradient(165deg, #e8e2db 0%, #e4ddd5 44%, #d9e3ec 100%);
            background-attachment: fixed;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(27,60,83,.13);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .glass-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--mid-steel);
            border-radius: 4px 0 0 4px;
            z-index: 2;
        }

        .glass-card:hover {
            box-shadow: 0 8px 32px rgba(27,60,83,.18);
            transform: translateY(-2px);
        }

        .glass-card::after {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 18px;
            background: conic-gradient(
                var(--deepest-navy), var(--dark-steel), var(--mid-steel),
                var(--warm-sand), var(--soft-sky), var(--deepest-navy)
            );
            z-index: -1;
            opacity: 0;
            transition: opacity 0.5s ease;
            animation: conicSpin 6s linear infinite;
            animation-play-state: paused;
        }

        .glass-card:hover::after {
            opacity: 0.35;
            animation-play-state: running;
        }

        @keyframes conicSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .hero-banner {
            background: linear-gradient(135deg, var(--deepest-navy) 0%, var(--dark-steel) 45%, var(--mid-steel) 100%);
            color: var(--white);
            border-radius: 16px;
            padding: 1.5rem 2rem;
            box-shadow: 0 4px 24px rgba(27,60,83,.2);
        }

        .hero-banner h1 {
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .badge-brand {
            background: var(--deepest-navy);
            color: var(--white);
            border-radius: 9999px;
            padding: 0.5rem 1.25rem;
            font-weight: 600;
            font-size: 0.85rem;
            box-shadow: 0 3px 10px rgba(27,60,83,.2);
        }

        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 1.25rem;
            box-shadow: 0 4px 20px rgba(27,60,83,.13);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border-left: 4px solid var(--mid-steel);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(27,60,83,.18);
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-card .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--deepest-navy);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--mid-steel);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .btn-brand {
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.6rem 1.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
            letter-spacing: 0.01em;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
        }

        .btn-brand:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,.15);
            text-decoration: none;
        }

        .btn-primary-brand {
            background: linear-gradient(135deg, var(--amber), var(--terracotta));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(201,123,80,.35);
        }

        .btn-primary-brand:hover {
            box-shadow: 0 8px 24px rgba(201,123,80,.45);
            color: var(--white);
        }

        .btn-secondary-brand {
            background: linear-gradient(135deg, #EAE4E0, var(--warm-sand));
            color: var(--deepest-navy);
            box-shadow: 0 4px 14px rgba(210,193,182,.35);
        }

        .btn-secondary-brand:hover {
            box-shadow: 0 8px 24px rgba(210,193,182,.45);
            color: var(--deepest-navy);
        }

        .btn-success-brand {
            background: linear-gradient(135deg, var(--mid-steel), var(--dark-steel));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(35,76,106,.35);
        }

        .btn-success-brand:hover {
            box-shadow: 0 8px 24px rgba(35,76,106,.45);
            color: var(--white);
        }

        .btn-danger-brand {
            background: linear-gradient(135deg, #ef4444, var(--danger-red));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(192,57,43,.35);
        }

        .btn-danger-brand:hover {
            box-shadow: 0 8px 24px rgba(192,57,43,.45);
            color: var(--white);
        }

        .btn-brand-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
        }

        .table-brand {
            width: 100%;
            border-collapse: collapse;
        }

        .table-brand thead th {
            background: linear-gradient(90deg, var(--deepest-navy), var(--dark-steel), var(--mid-steel));
            color: var(--white);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.9rem 1.25rem;
            border: none;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table-brand tbody td {
            padding: 0.9rem 1.25rem;
            vertical-align: middle;
            border-bottom: 1px solid rgba(210,193,182,.25);
        }

        .table-brand tbody tr:nth-child(even) {
            background: #f4ede7;
        }

        .table-brand tbody tr:hover {
            background: #e8dfd8;
            transform: scale(1.01);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .table-brand tbody tr:last-child td {
            border-bottom: none;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.8rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .badge-status.submitted {
            background: rgba(59, 130, 246, 0.12);
            color: #2563eb;
        }

        .badge-status.graded {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }

        .badge-status.late {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
        }

        .badge-status.pending {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        .badge-late {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .action-btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn-group .action-btn {
            padding: 0.4rem 0.8rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .action-btn-group .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }

        .action-btn-group .action-btn.view-btn {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }

        .action-btn-group .action-btn.view-btn:hover {
            background: #2563eb;
            color: white;
        }

        .action-btn-group .action-btn.download-btn {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }

        .action-btn-group .action-btn.download-btn:hover {
            background: #059669;
            color: white;
        }

        .action-btn-group .action-btn.grade-btn {
            background: rgba(139, 92, 246, 0.1);
            color: #7c3aed;
        }

        .action-btn-group .action-btn.grade-btn:hover {
            background: #7c3aed;
            color: white;
        }

        /* Modal styles */
        .modal-backdrop-custom {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1040;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-backdrop-custom.active {
            display: flex;
        }

        .modal-brand {
            width: 100%;
            max-width: 500px;
            animation: modalSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-brand .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 30px 60px rgba(0,0,0,.3);
            overflow: hidden;
            background: var(--white);
        }

        .modal-brand .modal-header {
            background: linear-gradient(135deg, var(--deepest-navy), var(--dark-steel), var(--mid-steel));
            color: var(--white);
            border-bottom: none;
            padding: 1.25rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-brand .modal-header .btn-close {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: var(--white);
            opacity: 0.8;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .modal-brand .modal-header .btn-close:hover {
            opacity: 1;
            background: rgba(255,255,255,0.1);
            transform: rotate(90deg);
        }

        .modal-brand .modal-body {
            padding: 1.5rem 1.75rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-brand .modal-footer {
            padding: 1rem 1.75rem 1.5rem 1.75rem;
            border-top: 1px solid rgba(210,193,182,.25);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            background: #faf8f7;
        }

        .modal-brand .modal-footer .btn-brand {
            padding: 0.6rem 1.5rem;
        }

        .input-brand {
            border-radius: 12px;
            border: 2px solid var(--warm-sand);
            padding: 0.65rem 1rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background: var(--white);
            color: var(--deepest-navy);
            width: 100%;
        }

        .input-brand:focus {
            border-color: var(--dark-steel);
            box-shadow: 0 0 0 4px rgba(35,76,106,.12);
            outline: none;
        }

        textarea.input-brand {
            resize: vertical;
            min-height: 100px;
        }

        .progress-bar {
            height: 6px;
            border-radius: 9999px;
            background: rgba(210,193,182,.3);
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-bar .progress-fill {
            height: 100%;
            border-radius: 9999px;
            background: linear-gradient(90deg, var(--mid-steel), var(--dark-steel));
            transition: width 0.8s ease;
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--warm-sand);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--mid-steel), var(--dark-steel));
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--dark-steel), var(--deepest-navy));
        }

        .modal-brand .modal-body::-webkit-scrollbar {
            width: 6px;
        }
        .modal-brand .modal-body::-webkit-scrollbar-track {
            background: #f3f0ed;
            border-radius: 10px;
        }
        .modal-brand .modal-body::-webkit-scrollbar-thumb {
            background: var(--warm-sand);
            border-radius: 10px;
        }
        .modal-brand .modal-body::-webkit-scrollbar-thumb:hover {
            background: var(--mid-steel);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-slide-in {
            animation: slideIn 0.5s ease-out forwards;
        }

        @media (max-width: 768px) {
            .hero-banner {
                padding: 1rem 1.25rem;
            }
            .stat-card .stat-number {
                font-size: 1.25rem;
            }
            .stat-card {
                padding: 0.75rem;
            }
            .table-brand thead th,
            .table-brand tbody td {
                padding: 0.7rem 0.75rem;
                font-size: 0.8rem;
            }
            .action-btn-group .action-btn {
                font-size: 0.65rem;
                padding: 0.3rem 0.6rem;
            }
            .action-btn-group {
                gap: 0.3rem;
            }
            .modal-brand {
                max-width: 100%;
                margin: 1rem;
            }
            .modal-brand .modal-header {
                padding: 1rem 1.25rem;
            }
            .modal-brand .modal-body {
                padding: 1rem 1.25rem;
            }
            .modal-brand .modal-footer {
                padding: 0.75rem 1.25rem 1.25rem 1.25rem;
                flex-direction: column;
            }
            .modal-brand .modal-footer .btn-brand {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300">
        <!-- Hero Banner -->
        <div class="hero-banner mx-4 mt-4 md:mx-6 md:mt-6">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <button class="d-md-none btn btn-link text-white p-2" onclick="toggleSidebar()">
                        <i class="fas fa-bars fa-lg"></i>
                    </button>
                    <a href="course_folder.php?course_id=<?= htmlspecialchars($assignment['course_id'] ?? 0) ?>" class="text-white/70 hover:text-white transition-colors mr-2" title="Back to Course">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <span class="rounded-3 p-2.5 d-inline-flex align-items-center justify-content-center" style="background: rgba(255,255,255,.15);">
                        <i class="fas fa-tasks text-white" style="font-size: 1.5rem;"></i>
                    </span>
                    <div>
                        <h1 class="mb-0" style="font-size: 1.5rem; font-weight: 800;">Assignment Submissions</h1>
                        <p class="mb-0 opacity-75" style="font-size: 0.85rem;">Review and grade student submissions</p>
                    </div>
                </div>
                <span class="badge-brand">
                    <i class="fas fa-file me-1.5"></i> <?= htmlspecialchars($assignment['title']) ?>
                </span>
            </div>
        </div>

        <div class="p-4 md:p-6 max-w-7xl mx-auto">
            <!-- Assignment Info Card -->
            <div class="glass-card p-6 mb-6 animate-slide-in">
                <div class="flex flex-wrap justify-between items-start gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($assignment['title']) ?></h2>
                        <?php if (!empty($assignment['description'])): ?>
                            <p class="text-gray-600 mt-1"><?= htmlspecialchars($assignment['description']) ?></p>
                        <?php endif; ?>
                        <div class="mt-3 flex flex-wrap gap-4 text-sm">
                            <div>
                                <span class="font-medium" style="color: var(--dark-steel);">Batch:</span>
                                <span class="text-gray-600"><?= htmlspecialchars($assignment['batch_name']) ?></span>
                            </div>
                            <?php if ($assignment['due_date']): ?>
                            <div>
                                <span class="font-medium" style="color: var(--dark-steel);">Due Date & Time (IST):</span>
                                <span class="text-gray-600">
                                    <?= date('M j, Y', strtotime($assignment['due_date'])) ?>
                                    <?php if (!empty($assignment['due_time'])): ?>
                                        <?= date('h:i A', strtotime($assignment['due_time'])) ?> IST
                                    <?php else: ?>
                                        11:59 PM IST
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <div>
                                <span class="font-medium" style="color: var(--dark-steel);">Max Marks:</span>
                                <span class="text-gray-600 font-bold"><?= $assignment['max_marks'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="px-3 py-1 rounded-full text-sm font-medium" style="background: rgba(27,60,83,.08); color: var(--dark-steel);">
                            <i class="fas fa-users mr-1"></i> <?= $total_submissions ?> Submissions
                        </span>
                    </div>
                </div>

                <!-- Progress bar for grading -->
                <?php if ($total_submissions > 0): ?>
                <div class="mt-4">
                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                        <span>Grading Progress</span>
                        <span><?= $graded_count ?> / <?= $total_submissions ?> graded (<?= $submission_percentage ?>%)</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $submission_percentage ?>%;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 animate-slide-in" style="animation-delay: 0.1s">
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(59, 130, 246, 0.12); color: #2563eb;">
                            <i class="fas fa-users text-lg"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?= $total_submissions ?></div>
                            <div class="stat-label">Total Submissions</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                            <i class="fas fa-check-circle text-lg"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?= $graded_count ?></div>
                            <div class="stat-label">Graded</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(239, 68, 68, 0.12); color: #dc2626;">
                            <i class="fas fa-clock text-lg"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?= $pending_count ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(139, 92, 246, 0.12); color: #7c3aed;">
                            <i class="fas fa-star text-lg"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?= $avg_grade ?></div>
                            <div class="stat-label">Average Grade</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submissions Table -->
            <div class="glass-card p-0 overflow-hidden animate-slide-in" style="animation-delay: 0.2s">
                <div class="p-4 border-b" style="border-color: rgba(210,193,182,.25); background: rgba(248,245,242,.3);">
                    <div class="flex flex-wrap justify-between items-center gap-3">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-gradient-to-r from-amber-500 to-terracotta flex items-center justify-center text-white text-sm">
                                <i class="fas fa-list"></i>
                            </div>
                            <span>All Submissions</span>
                        </h3>
                        <div class="text-sm text-gray-500">
                            <?= count($submissions) ?> submissions found
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="table-brand">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Submission Date (IST)</th>
                                <th>Status</th>
                                <th>Grade</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($submissions)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                        <i class="fas fa-inbox text-3xl mb-2 block" style="color: var(--warm-sand);"></i>
                                        <p class="text-lg font-medium text-gray-600">No submissions yet</p>
                                        <p class="text-sm">Students haven't submitted this assignment yet</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($submissions as $submission): ?>
                                    <?php
                                    $isLate = false;
                                    if ($assignment['due_date']) {
                                        $due_datetime = new DateTime($assignment['due_date'], new DateTimeZone('Asia/Kolkata'));
                                        if (!empty($assignment['due_time'])) {
                                            $time_parts = explode(':', $assignment['due_time']);
                                            $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
                                        } else {
                                            $due_datetime->setTime(23, 59, 59);
                                        }
                                        $submitted_datetime = new DateTime($submission['submitted_at'], new DateTimeZone('Asia/Kolkata'));
                                        if ($submitted_datetime > $due_datetime) {
                                            $isLate = true;
                                        }
                                    }

                                    $statusClass = 'submitted';
                                    if ($submission['status'] === 'graded') {
                                        $statusClass = 'graded';
                                    } elseif ($isLate) {
                                        $statusClass = 'late';
                                    }
                                    ?>
                                    <tr class="table-row" style="animation: slideIn 0.3s ease-out <?= $loop->index * 0.05 ?>s both;">
                                        <td>
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 rounded-full flex items-center justify-center mr-3" style="background: rgba(27,60,83,.08);">
                                                    <i class="fas fa-user text-sm" style="color: var(--dark-steel);"></i>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']) ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?= htmlspecialchars($submission['student_id']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap text-sm text-gray-500">
                                            <?= date('M j, Y H:i', strtotime($submission['submitted_at'])) ?> IST
                                            <?php if ($isLate): ?>
                                                <span class="badge-late">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i> Late
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="whitespace-nowrap">
                                            <span class="badge-status <?= $statusClass ?>">
                                                <?php if ($submission['status'] === 'graded'): ?>
                                                    <i class="fas fa-check-circle mr-1"></i> Graded
                                                <?php elseif ($isLate): ?>
                                                    <i class="fas fa-clock mr-1"></i> Late
                                                <?php else: ?>
                                                    <i class="fas fa-hourglass-half mr-1"></i> Submitted
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($submission['grade'] !== null): ?>
                                                <div>
                                                    <span class="text-lg font-bold" style="color: var(--deepest-navy);">
                                                        <?= $submission['grade'] ?>
                                                    </span>
                                                    <span class="text-sm text-gray-500">/ <?= $assignment['max_marks'] ?></span>
                                                    <?php if ($submission['graded_by_name']): ?>
                                                        <div class="text-xs text-gray-500">
                                                            By <?= htmlspecialchars($submission['graded_by_name']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">Not graded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div class="action-btn-group" style="justify-content: flex-end;">
                                                <a href="<?= htmlspecialchars($submission['file_path']) ?>" 
                                                   target="_blank"
                                                   class="action-btn view-btn">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="<?= htmlspecialchars($submission['file_path']) ?>" 
                                                   download
                                                   class="action-btn download-btn">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                                <button onclick="openGradeModal(<?= $submission['id'] ?>, <?= $submission['grade'] !== null ? 'true' : 'false' ?>, '<?= addslashes($submission['feedback'] ?? '') ?>', <?= $submission['grade'] ?? 'null' ?>)"
                                                        class="action-btn grade-btn">
                                                    <i class="fas fa-edit"></i> Grade
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

            <!-- Back button -->
            <div class="mt-6 animate-slide-in" style="animation-delay: 0.3s">
                <a href="course_folder.php?course_id=<?= htmlspecialchars($assignment['course_id'] ?? 0) ?>" class="btn-brand btn-secondary-brand">
                    <i class="fas fa-arrow-left"></i> Back to Course
                </a>
            </div>
        </div>
    </div>

    <!-- Grade Modal -->
    <div id="gradeModal" class="modal-backdrop-custom">
        <div class="modal-brand">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2" style="margin: 0; font-size: 1.25rem;">
                        <i class="fas fa-edit"></i> Grade Submission
                    </h5>
                    <button type="button" class="btn-close" onclick="closeGradeModal()" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="modal-body">
                    <form id="gradeForm" method="POST">
                        <input type="hidden" name="submission_id" id="modalSubmissionId">
                        <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                        
                        <div class="mb-4">
                            <label for="grade" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-star text-indigo-500 mr-2"></i>
                                Grade (out of <?= $assignment['max_marks'] ?>)
                            </label>
                            <input type="number" id="grade" name="grade" step="0.01" min="0" max="<?= $assignment['max_marks'] ?>"
                                   class="input-brand" placeholder="Enter grade">
                        </div>
                        
                        <div>
                            <label for="feedback" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-comment text-indigo-500 mr-2"></i>
                                Feedback
                            </label>
                            <textarea id="feedback" name="feedback" rows="4"
                                      class="input-brand" placeholder="Provide feedback to the student"></textarea>
                        </div>
                    </form>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-brand btn-secondary-brand" onclick="closeGradeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn-brand btn-success-brand" onclick="document.getElementById('gradeForm').submit()">
                        <i class="fas fa-save"></i> Save Grade
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.classList.toggle('-translate-x-full');
        }
        document.body.classList.toggle('overflow-hidden');
    }

    function openGradeModal(submissionId, isGraded, feedback, grade) {
        document.getElementById('modalSubmissionId').value = submissionId;
        document.getElementById('feedback').value = feedback || '';
        
        const gradeInput = document.getElementById('grade');
        if (isGraded && grade !== null && grade !== undefined) {
            gradeInput.value = grade;
        } else {
            gradeInput.value = '';
        }
        
        document.getElementById('gradeModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeGradeModal() {
        document.getElementById('gradeModal').classList.remove('active');
        document.body.style.overflow = '';
        document.getElementById('gradeForm').reset();
    }

    // Close modal on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeGradeModal();
        }
    });

    // Close modal on backdrop click
    document.getElementById('gradeModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeGradeModal();
        }
    });

    // Show success message if redirected with message
    <?php if (isset($_GET['message'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: '<?= htmlspecialchars($_GET['message']) ?>',
        confirmButtonColor: '#1B3C53',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000
    });
    <?php endif; ?>
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>