<?php
// trainer_grade_assignments.php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../../logout_t.php.php");
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

// Get batches taught by this trainer
$batches = $db->prepare("
    SELECT batch_id, batch_name 
    FROM batches 
    WHERE batch_mentor_id = :trainer_id 
    ORDER BY batch_id ASC
");
$batches->execute([':trainer_id' => $trainer['id']]);
$mentor_batches = $batches->fetchAll(PDO::FETCH_ASSOC);

// Get assignments for these batches
if (!empty($mentor_batches)) {
    $batch_ids = array_column($mentor_batches, 'batch_id');
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    
    $assignments_query = $db->prepare("
        SELECT u.*, b.batch_name, 
               (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id) as submission_count,
               (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id AND status = 'graded') as graded_count,
               (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id AND status = 'pending') as pending_count
        FROM uploads u
        JOIN batch_uploads bu ON u.id = bu.upload_id
        JOIN batches b ON bu.batch_id = b.batch_id
        WHERE bu.batch_id IN ($placeholders)
        AND u.file_type = 'Assignment'
        ORDER BY u.due_date DESC, u.uploaded_at DESC
    ");
    $assignments_query->execute($batch_ids);
    $assignments = $assignments_query->fetchAll(PDO::FETCH_ASSOC);
}

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
        ':graded_by' => $trainer_id,
        ':id' => $submission_id
    ]);
    
    header("Location: trainer_grade_assignments.php?message=Graded successfully&assignment_id=" . $_POST['assignment_id']);
    exit;
}

// Get specific assignment if provided
$assignment_id = $_GET['assignment_id'] ?? 0;
$specific_assignment = null;
$assignment_submissions = [];

if ($assignment_id) {
    // Verify trainer has access to this assignment
    $assignment_stmt = $db->prepare("
        SELECT u.*, b.batch_name 
        FROM uploads u
        JOIN batch_uploads bu ON u.id = bu.upload_id
        JOIN batches b ON bu.batch_id = b.batch_id
        WHERE u.id = ? AND u.file_type = 'Assignment' AND b.batch_mentor_id = ?
    ");
    $assignment_stmt->execute([$assignment_id, $trainer['id']]);
    $specific_assignment = $assignment_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($specific_assignment) {
        // Get submissions for this assignment
        $submissions_stmt = $db->prepare("
            SELECT s.*, st.first_name, st.last_name, st.student_id,
                   (SELECT grade FROM assignment_submissions WHERE student_id = st.student_id AND upload_id = ?) as grade
            FROM assignment_submissions s
            JOIN students st ON s.student_id = st.student_id
            WHERE s.upload_id = ?
            ORDER BY s.submitted_at DESC
        ");
        $submissions_stmt->execute([$assignment_id, $assignment_id]);
        $assignment_submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Assignments - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #1B3C53;
            --primary-light: #e0e7ff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-light: #f8fafc;
        }
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        .gradient-bg {
            background: linear-gradient(120deg, #1B3C53 0%, #234C6A 50%, #c026d3 100%);
            background-size: 200% 200%;
            animation: gradientShift 12s ease infinite;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: center;
        }
        
        .card-hover:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 20px 40px rgba(27,60,83, 0.18);
            border-color: #c4b5fd !important;
        }
        
        .submission-card {
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .progress-ring {
            transform: rotate(-90deg);
        }
        
        .progress-ring__circle {
            stroke-dasharray: 283;
            stroke-dashoffset: 283;
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .badge {
            transition: all 0.2s ease;
        }
        
        .badge:hover {
            transform: scale(1.05);
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
<body class="bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50 min-h-screen">
<?php include '../../header.php'; ?>
<?php include '../t_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 transition-all duration-300 ease-in-out min-h-screen">
    <!-- Enhanced Header -->
    <header class="gradient-bg text-white shadow-xl px-6 py-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div class="flex items-center space-x-4">
                <div class="p-3 bg-white/20 rounded-2xl backdrop-blur-sm">
                    <i class="fas fa-check-double text-2xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold">Grade Assignments</h1>
                    <p class="text-white/80 mt-1">Review and evaluate student submissions</p>
                </div>
            </div>
            <div class="mt-4 md:mt-0 flex space-x-2">
                <div class="px-4 py-2 bg-white/20 rounded-xl backdrop-blur-md border border-white/30 shadow-lg">
                    <div class="text-sm opacity-90">Total Batches</div>
                    <div class="text-xl font-bold"><?= count($mentor_batches) ?></div>
                </div>
                <div class="px-4 py-2 bg-white/20 rounded-xl backdrop-blur-md border border-white/30 shadow-lg">
                    <div class="text-sm opacity-90">Total Assignments</div>
                    <div class="text-xl font-bold"><?= count($assignments) ?></div>
                </div>
            </div>
        </div>
    </header>

    <div class="p-6">
        <!-- Success Message -->
        <?php if (isset($_GET['message'])): ?>
        <div class="mb-6 p-4 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-xl shadow-md animate-fade-in">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-500 text-xl"></i>
                </div>
                <div class="ml-3">
                    <p class="text-green-800 font-medium"><?= htmlspecialchars($_GET['message']) ?></p>
                </div>
                <div class="ml-auto">
                    <button onclick="this.parentElement.parentElement.remove()" class="text-green-600 hover:text-green-800">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($assignment_id && $specific_assignment): ?>
        <!-- Specific Assignment Grading (Enhanced) -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden mb-6 fade-in">
            <!-- Assignment Header -->
            <div class="gradient-bg text-white p-6">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between">
                    <div class="flex-1">
                        <div class="flex items-center space-x-3 mb-3">
                            <i class="fas fa-clipboard-check text-2xl"></i>
                            <h2 class="text-2xl font-bold"><?= htmlspecialchars($specific_assignment['title']) ?></h2>
                        </div>
                        <p class="text-white/90 mb-4"><?= htmlspecialchars($specific_assignment['description']) ?></p>
                        
                        <div class="flex flex-wrap gap-4">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-users text-white/70"></i>
                                <span class="font-medium">Batch:</span>
                                <span class="bg-white/20 px-3 py-1 rounded-full text-sm"><?= htmlspecialchars($specific_assignment['batch_name']) ?></span>
                            </div>
                            <?php if ($specific_assignment['due_date']): ?>
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-calendar-alt text-white/70"></i>
                                <span class="font-medium">Due:</span>
                                <span class="bg-white/20 px-3 py-1 rounded-full text-sm">
                                    <?= date('M j, Y', strtotime($specific_assignment['due_date'])) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-star text-white/70"></i>
                                <span class="font-medium">Max Marks:</span>
                                <span class="bg-white/20 px-3 py-1 rounded-full text-sm"><?= $specific_assignment['max_marks'] ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stats Circle -->
                    <div class="mt-4 md:mt-0 flex flex-col items-center">
                        <div class="relative w-24 h-24">
                            <svg class="w-24 h-24" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="45" fill="none" stroke="#ffffff30" stroke-width="8"/>
                                <?php 
                                $total = count($assignment_submissions);
                                $graded = 0;
                                foreach ($assignment_submissions as $s) {
                                    if ($s['status'] == 'graded') $graded++;
                                }
                                $percentage = $total > 0 ? ($graded / $total) * 100 : 0;
                                $dashoffset = 283 - (283 * $percentage / 100);
                                ?>
                                <circle cx="50" cy="50" r="45" fill="none" 
                                        stroke="white" stroke-width="8" stroke-linecap="round"
                                        stroke-dasharray="283" 
                                        stroke-dashoffset="<?= $dashoffset ?>"
                                        class="transition-all duration-1000 ease-out"/>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="text-center">
                                    <div class="text-2xl font-bold"><?= $graded ?>/<?= $total ?></div>
                                    <div class="text-xs opacity-80">Graded</div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2 text-sm text-white/90">Submissions</div>
                    </div>
                </div>
            </div>

            <!-- Submissions Section -->
            <div class="p-6">
                <?php if (empty($assignment_submissions)): ?>
                    <div class="text-center py-12">
                        <div class="inline-block p-6 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl mb-4">
                            <i class="fas fa-inbox text-4xl text-gray-400"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-700 mb-2">No Submissions Yet</h3>
                        <p class="text-gray-500 max-w-md mx-auto">Students haven't submitted any work for this assignment yet. Check back later.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <?php foreach ($assignment_submissions as $submission): ?>
                        <div class="submission-card border border-gray-200 rounded-xl p-5 hover:border-blue-300 transition-all duration-300 <?= $submission['status'] == 'graded' ? 'bg-gradient-to-br from-green-50 to-white' : 'bg-gradient-to-br from-blue-50 to-white' ?>">
                            <!-- Student Header -->
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex items-center">
                                    <div class="relative">
                                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 flex items-center justify-center text-white font-semibold shadow-md">
                                            <?= substr($submission['first_name'], 0, 1) . substr($submission['last_name'], 0, 1) ?>
                                        </div>
                                        <?php if ($submission['status'] == 'graded'): ?>
                                        <div class="absolute -top-1 -right-1 w-5 h-5 bg-gradient-to-br from-green-400 to-emerald-600 rounded-full flex items-center justify-center shadow-sm">
                                            <i class="fas fa-check text-xs text-white"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ml-3">
                                        <div class="font-bold text-gray-900"><?= htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']) ?></div>
                                        <div class="text-sm text-gray-600"><?= htmlspecialchars($submission['student_id']) ?></div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm text-gray-500 mb-1">
                                        <i class="fas fa-clock mr-1"></i>
                                        <?= date('M j, Y H:i', strtotime($submission['submitted_at'])) ?>
                                    </div>
                                    <?php if ($submission['status'] == 'graded'): ?>
                                        <div class="inline-flex items-center px-3 py-1 rounded-full bg-gradient-to-r from-green-100 to-emerald-100 border border-green-200">
                                            <i class="fas fa-star text-yellow-500 mr-1"></i>
                                            <span class="font-bold text-green-800"><?= $submission['grade'] ?>/<?= $specific_assignment['max_marks'] ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="inline-flex items-center px-3 py-1 rounded-full bg-gradient-to-r from-yellow-100 to-orange-100 border border-yellow-200 pulse">
                                            <i class="fas fa-clock text-yellow-600 mr-1"></i>
                                            <span class="font-medium text-yellow-800">Pending</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Submission Info -->
                            <div class="mb-4">
                                <div class="flex items-center justify-between text-sm mb-2">
                                    <span class="text-gray-600">Submission File:</span>
                                    <span class="font-medium truncate ml-2">
                                        <?= basename($submission['file_path']) ?>
                                    </span>
                                </div>
                                <div class="flex space-x-2">
                                    <a href="../<?= htmlspecialchars($submission['file_path']) ?>" 
                                       target="_blank"
                                       class="flex-1 flex items-center justify-center px-3 py-2 bg-gradient-to-r from-blue-50 to-indigo-100 hover:from-blue-100 hover:to-indigo-200 text-blue-700 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-external-link-alt mr-2"></i>
                                        Preview
                                    </a>
                                    <a href="../<?= htmlspecialchars($submission['file_path']) ?>" 
                                       download
                                       class="flex-1 flex items-center justify-center px-3 py-2 bg-gradient-to-r from-emerald-50 to-green-100 hover:from-emerald-100 hover:to-green-200 text-green-700 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-download mr-2"></i>
                                        Download
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Grading Form -->
                            <form method="POST" class="space-y-3">
                                <input type="hidden" name="submission_id" value="<?= $submission['id'] ?>">
                                <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            <i class="fas fa-star mr-1 text-yellow-500"></i> Grade
                                        </label>
                                        <div class="flex items-center">
                                            <input type="number" name="grade" step="0.01" min="0" max="<?= $specific_assignment['max_marks'] ?>"
                                                   value="<?= $submission['grade'] ?? '' ?>"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                                            <span class="ml-2 text-gray-600 font-medium">/<?= $specific_assignment['max_marks'] ?></span>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            <i class="fas fa-comment-dots mr-1 text-blue-500"></i> Feedback
                                        </label>
                                        <input type="text" name="feedback" 
                                               value="<?= htmlspecialchars($submission['feedback'] ?? '') ?>"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
                                               placeholder="Add feedback (optional)">
                                    </div>
                                </div>
                                
                                <div class="flex justify-end space-x-2 pt-2">
                                    <a href="trainer_grade_assignments.php" 
                                       class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                                        Cancel
                                    </a>
                                    <button type="submit" name="grade_submission"
                                            class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg hover:from-indigo-700 hover:to-purple-700 transform hover:-translate-y-0.5 transition-all duration-200 shadow-md hover:shadow-xl flex items-center">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        Save Grade
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Back Button -->
                <div class="mt-8 text-center">
                    <a href="trainer_grade_assignments.php" 
                       class="inline-flex items-center px-5 py-3 bg-gradient-to-r from-gray-100 to-gray-200 text-gray-800 rounded-xl hover:from-gray-200 hover:to-gray-300 transition-all duration-300 transform hover:-translate-y-0.5 shadow-md">
                        <i class="fas fa-arrow-left mr-3"></i>
                        <span class="font-medium">Back to All Assignments</span>
                    </a>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- All Assignments List (Enhanced) -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="border-b border-gray-200 p-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Your Assignments</h2>
                        <p class="text-gray-600 mt-1">Select an assignment to start grading</p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <div class="flex items-center space-x-2 text-sm">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-gradient-to-br from-blue-400 to-indigo-600 rounded-full mr-2 shadow-sm"></div>
                                <span class="text-gray-600">Ungraded</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-gradient-to-br from-emerald-400 to-green-600 rounded-full mr-2 shadow-sm"></div>
                                <span class="text-gray-600">Graded</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="p-6">
                <?php if (empty($assignments)): ?>
                    <div class="text-center py-16">
                        <div class="inline-block p-8 bg-gradient-to-br from-indigo-50 to-purple-100 rounded-3xl mb-6 shadow-inner">
                            <i class="fas fa-tasks text-6xl bg-gradient-to-br from-indigo-400 to-purple-500 bg-clip-text text-transparent"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-700 mb-2">No Assignments Found</h3>
                        <p class="text-gray-500 max-w-md mx-auto mb-8">Upload assignments for your batches to start receiving submissions.</p>
                        <a href="trainer_upload_material.php" 
                           class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 transition-all duration-300 transform hover:-translate-y-1 shadow-lg hover:shadow-xl">
                            <i class="fas fa-plus-circle mr-3"></i>
                            Upload New Assignment
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($assignments as $assignment): 
                            $progress = $assignment['submission_count'] > 0 ? 
                                ($assignment['graded_count'] / $assignment['submission_count']) * 100 : 0;
                            $statusColor = $progress == 100 ? 'bg-gradient-to-br from-green-500 to-emerald-600' : 
                                          ($progress > 50 ? 'bg-gradient-to-br from-blue-500 to-purple-600' : 
                                          'bg-gradient-to-br from-yellow-500 to-orange-600');
                        ?>
                            <div class="card-hover bg-white border border-gray-200 rounded-xl p-5 hover:border-purple-300 relative overflow-hidden">
                                <div class="absolute top-0 left-0 w-full h-1 <?= $statusColor ?>"></div>
                                <!-- Assignment Header -->
                                <div class="flex items-start justify-between mb-4">
                                    <div>
                                        <div class="flex items-center mb-2">
                                            <div class="p-2 rounded-lg bg-gradient-to-br from-blue-100 to-indigo-200 mr-3 shadow-sm">
                                                <i class="fas fa-file-alt text-blue-600"></i>
                                            </div>
                                            <h3 class="font-bold text-gray-900 text-lg truncate"><?= htmlspecialchars($assignment['title']) ?></h3>
                                        </div>
                                        <div class="text-sm text-gray-600 ml-11">
                                            <div class="flex items-center mb-1">
                                                <i class="fas fa-users text-gray-400 mr-2 w-4"></i>
                                                <?= htmlspecialchars($assignment['batch_name']) ?>
                                            </div>
                                            <?php if ($assignment['due_date']): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-calendar text-gray-400 mr-2 w-4"></i>
                                                <?= date('M j, Y', strtotime($assignment['due_date'])) ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="relative">
                                        <div class="w-16 h-16">
                                            <svg class="w-16 h-16" viewBox="0 0 100 100">
                                                <circle cx="50" cy="50" r="40" fill="none" stroke="#f3f4f6" stroke-width="8"/>
                                                <circle cx="50" cy="50" r="40" fill="none" 
                                                        stroke="url(#grad<?= $assignment['id'] ?>)" stroke-width="8" stroke-linecap="round"
                                                        stroke-dasharray="251" 
                                                        stroke-dashoffset="<?= 251 - (251 * $progress / 100) ?>"
                                                        class="transition-all duration-1000 ease-out"/>
                                                <defs>
                                                    <linearGradient id="grad<?= $assignment['id'] ?>" x1="0%" y1="0%" x2="100%" y2="100%">
                                                        <stop offset="0%" style="stop-color:<?= $progress == 100 ? '#10b981' : ($progress > 50 ? '#1B3C53' : '#f59e0b') ?>;stop-opacity:1" />
                                                        <stop offset="100%" style="stop-color:<?= $progress == 100 ? '#047857' : ($progress > 50 ? '#234C6A' : '#d97706') ?>;stop-opacity:1" />
                                                    </linearGradient>
                                                </defs>
                                            </svg>
                                        </div>
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <div class="text-center">
                                                <div class="text-lg font-bold"><?= intval($progress) ?>%</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Stats -->
                                <div class="mb-5">
                                    <div class="grid grid-cols-3 gap-2 text-center">
                                        <div class="p-3 bg-gradient-to-br from-blue-50 to-indigo-100 rounded-lg border border-blue-100">
                                            <div class="text-2xl font-bold text-blue-700"><?= $assignment['submission_count'] ?></div>
                                            <div class="text-xs text-blue-600 font-medium">Total</div>
                                        </div>
                                        <div class="p-3 bg-gradient-to-br from-emerald-50 to-green-100 rounded-lg border border-emerald-100">
                                            <div class="text-2xl font-bold text-green-700"><?= $assignment['graded_count'] ?></div>
                                            <div class="text-xs text-green-600 font-medium">Graded</div>
                                        </div>
                                        <div class="p-3 bg-gradient-to-br from-amber-50 to-yellow-100 rounded-lg border border-amber-100">
                                            <div class="text-2xl font-bold text-yellow-700"><?= $assignment['pending_count'] ?? 0 ?></div>
                                            <div class="text-xs text-yellow-600 font-medium">Pending</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Footer -->
                                <div class="flex items-center justify-between">
                                    <div class="text-sm text-gray-500">
                                        <i class="fas fa-star mr-1"></i>
                                        Max: <?= $assignment['max_marks'] ?>
                                    </div>
                                    <a href="trainer_grade_assignments.php?assignment_id=<?= $assignment['id'] ?>" 
                                       class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg hover:from-indigo-700 hover:to-purple-700 transition-all duration-300 transform hover:-translate-y-0.5 shadow-md hover:shadow-xl">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        <?= $assignment['graded_count'] == $assignment['submission_count'] ? 'Review' : 'Grade' ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../footer.php'; ?>

<script>
    // Add smooth scrolling
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        });
    });

    // Form submission confirmation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const gradeInput = this.querySelector('input[name="grade"]');
            if (gradeInput && gradeInput.value) {
                const maxMarks = gradeInput.max;
                const grade = parseFloat(gradeInput.value);
                
                if (grade > parseFloat(maxMarks)) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid Grade',
                        text: `Grade cannot exceed ${maxMarks} marks`,
                        confirmButtonColor: '#1B3C53',
                    });
                    return false;
                }
                
                if (grade < 0) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid Grade',
                        text: 'Grade cannot be negative',
                        confirmButtonColor: '#1B3C53',
                    });
                    return false;
                }
            }
        });
    });

    // Animate progress circles on scroll
    const observerOptions = {
        threshold: 0.2
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const circles = entry.target.querySelectorAll('.progress-ring__circle');
                circles.forEach(circle => {
                    const dashoffset = circle.style.strokeDashoffset;
                    circle.style.strokeDashoffset = dashoffset;
                });
            }
        });
    }, observerOptions);

    document.querySelectorAll('.submission-card').forEach(card => {
        observer.observe(card);
    });

    // Tooltip for file names
    document.querySelectorAll('a[href*="file_path"]').forEach(link => {
        link.addEventListener('mouseenter', function(e) {
            const fileName = this.href.split('/').pop();
            if (fileName.length > 30) {
                this.setAttribute('title', fileName);
            }
        });
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            const form = document.querySelector('form');
            if (form) {
                form.querySelector('button[type="submit"]').click();
            }
        }
    });

    // Initialize animations
    document.addEventListener('DOMContentLoaded', () => {
        // Add stagger animation to cards
        const cards = document.querySelectorAll('.card-hover');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
        });
    });
</script>
</body>
</html>