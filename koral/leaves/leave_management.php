<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../db_connection.php';

// Check if admin/mentor is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'mentor'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Handle application approval/rejection
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_application'])) {
        $application_id = $_POST['application_id'];
        $remarks = $_POST['admin_remarks'] ?? '';
        
        $db->beginTransaction();
        
        try {
            // Update application
            $stmt = $db->prepare("
                UPDATE leave_applications 
                SET status = 'approved', 
                    approved_by = :approved_by, 
                    approved_at = NOW(),
                    admin_remarks = :remarks
                WHERE id = :id AND status = 'pending'
            ");
            $stmt->execute([
                ':approved_by' => $user_id,
                ':remarks' => $remarks,
                ':id' => $application_id
            ]);
            
            // Add to history
            $history_stmt = $db->prepare("
                INSERT INTO leave_application_history (application_id, action, action_by, remarks)
                VALUES (:application_id, 'approved', :action_by, :remarks)
            ");
            $history_stmt->execute([
                ':application_id' => $application_id,
                ':action_by' => $user_id,
                ':remarks' => $remarks
            ]);
            
            $db->commit();
            $success_message = "Application approved successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Failed to approve application: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_application'])) {
        $application_id = $_POST['application_id'];
        $rejection_reason = $_POST['rejection_reason'];
        $remarks = $_POST['admin_remarks'] ?? '';
        
        if (empty($rejection_reason)) {
            $error_message = "Please provide a reason for rejection.";
        } else {
            $db->beginTransaction();
            
            try {
                // Update application
                $stmt = $db->prepare("
                    UPDATE leave_applications 
                    SET status = 'rejected', 
                        rejected_by = :rejected_by, 
                        rejected_at = NOW(),
                        rejection_reason = :rejection_reason,
                        admin_remarks = :remarks
                    WHERE id = :id AND status = 'pending'
                ");
                $stmt->execute([
                    ':rejected_by' => $user_id,
                    ':rejection_reason' => $rejection_reason,
                    ':remarks' => $remarks,
                    ':id' => $application_id
                ]);
                
                // Add to history
                $history_stmt = $db->prepare("
                    INSERT INTO leave_application_history (application_id, action, action_by, remarks)
                    VALUES (:application_id, 'rejected', :action_by, :remarks)
                ");
                $history_stmt->execute([
                    ':application_id' => $application_id,
                    ':action_by' => $user_id,
                    ':remarks' => $rejection_reason
                ]);
                
                $db->commit();
                $success_message = "Application rejected successfully!";
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Failed to reject application: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'pending';
$user_type_filter = $_GET['user_type'] ?? 'all';
$search_query = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query - Differentiate between students and trainers
$query = "
    SELECT l.*, 
           b.batch_name as batch_title,
           u.name as approved_by_name,
           u2.name as rejected_by_name,
           CASE 
               WHEN s.student_id IS NOT NULL THEN 'student'
               WHEN t.user_id IS NOT NULL THEN 'trainer'
               ELSE 'unknown'
           END as applicant_type,
           CASE 
               WHEN s.student_id IS NOT NULL THEN CONCAT(s.first_name, ' ', s.last_name)
               WHEN t.user_id IS NOT NULL THEN t.name
               ELSE l.student_name
           END as applicant_full_name,
           CASE 
               WHEN s.student_id IS NOT NULL THEN s.email
               WHEN t.user_id IS NOT NULL THEN t.email
               ELSE l.email
           END as applicant_email,
           CASE 
               WHEN s.student_id IS NOT NULL THEN s.phone_number
               WHEN t.user_id IS NOT NULL THEN t.phone
               ELSE NULL
           END as applicant_phone
    FROM leave_applications l
    LEFT JOIN batches b ON l.batch_id = b.batch_id
    LEFT JOIN users u ON l.approved_by = u.id
    LEFT JOIN users u2 ON l.rejected_by = u2.id
    LEFT JOIN students s ON l.student_id = s.student_id
    LEFT JOIN trainers t ON l.student_id = t.user_id
    WHERE 1=1
";

$params = [];

if ($status_filter !== 'all') {
    $query .= " AND l.status = :status";
    $params[':status'] = $status_filter;
}

// User type filter (student or trainer)
if ($user_type_filter === 'student') {
    $query .= " AND s.student_id IS NOT NULL";
} elseif ($user_type_filter === 'trainer') {
    $query .= " AND t.user_id IS NOT NULL";
}

// Search by name (student or trainer name) - FIXED: Using different parameter names
if (!empty($search_query)) {
    $query .= " AND (
        l.application_no LIKE :search1 
        OR l.student_name LIKE :search2
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE :search3
        OR t.name LIKE :search4
        OR s.email LIKE :search5
        OR t.email LIKE :search6
        OR l.reason_category LIKE :search7
    )";
    $search_param = "%$search_query%";
    $params[':search1'] = $search_param;
    $params[':search2'] = $search_param;
    $params[':search3'] = $search_param;
    $params[':search4'] = $search_param;
    $params[':search5'] = $search_param;
    $params[':search6'] = $search_param;
    $params[':search7'] = $search_param;
}

if (!empty($date_from)) {
    $query .= " AND DATE(l.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(l.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$query .= " ORDER BY 
    CASE l.status 
        WHEN 'pending' THEN 1
        WHEN 'approved' THEN 2
        WHEN 'rejected' THEN 3
        WHEN 'cancelled' THEN 4
    END,
    l.created_at DESC";

try {
    $applications_stmt = $db->prepare($query);
    $applications_stmt->execute($params);
    $applications = $applications_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Query Error: " . $e->getMessage());
    error_log("Query: " . $query);
    error_log("Params: " . print_r($params, true));
    $applications = [];
    $error_message = "Database query error: " . $e->getMessage();
}

// Get statistics with user type breakdown - FIXED
try {
    $stats_query = $db->prepare("
        SELECT 
            COUNT(DISTINCT l.id) as total,
            SUM(CASE WHEN l.status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN l.status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN l.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN l.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN s.student_id IS NOT NULL THEN 1 ELSE 0 END) as student_applications,
            SUM(CASE WHEN t.user_id IS NOT NULL THEN 1 ELSE 0 END) as trainer_applications
        FROM leave_applications l
        LEFT JOIN students s ON l.student_id = s.student_id
        LEFT JOIN trainers t ON l.student_id = t.user_id
    ");
    $stats_query->execute();
    $stats = $stats_query->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Stats Query Error: " . $e->getMessage());
    $stats = [
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'cancelled' => 0,
        'student_applications' => 0,
        'trainer_applications' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .animate-fade-up { animation: fadeInUp 0.6s ease-out forwards; }
        .animate-slide-left { animation: slideInLeft 0.5s ease-out forwards; }
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }
        .delay-500 { animation-delay: 0.5s; }
        
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.8);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 24px rgba(0,0,0,0.15); }
        
        .application-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }
        
        .application-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-approved { background-color: #d1fae5; color: #065f46; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; }
        .status-cancelled { background-color: #f3f4f6; color: #374151; }
        
        .user-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .user-student { background-color: #dbeafe; color: #1e40af; }
        .user-trainer { background-color: #fce7f3; color: #9d174d; }
        
        .filter-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            transition: all 0.2s ease;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            transition: all 0.2s ease;
        }
        .btn-success:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            transition: all 0.2s ease;
        }
        .btn-danger:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
        
        .btn-secondary { background: #6b7280; transition: all 0.2s ease; }
        .btn-secondary:hover { background: #4b5563; }
        
        .form-input, .form-select {
            transition: all 0.2s ease;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.6rem 1rem;
        }
        
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    
    <div class="flex">
        <?php include '../sidebar.php'; ?>
        
        <div class="flex-1 ml-64 p-8">
            <!-- Header -->
            <div class="mb-8 animate-fade-up">
                <div class="bg-white rounded-2xl shadow-lg p-6">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center space-x-4">
                            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-2xl shadow-lg flex items-center justify-center">
                                <i class="fas fa-calendar-alt text-white text-3xl"></i>
                            </div>
                            <div>
                                <h1 class="text-3xl font-bold text-gray-800">Leave Management</h1>
                                <p class="text-gray-500 mt-1">Manage and process leave applications from students and trainers</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="px-4 py-2 bg-blue-50 rounded-xl">
                                <i class="fas fa-user-shield text-blue-600 mr-2"></i>
                                <span class="text-sm text-blue-700"><?= ucfirst($user_role) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-lg animate-fade-up">
                    <div class="flex items-center"><i class="fas fa-check-circle mr-2"></i><span><?= htmlspecialchars($success_message) ?></span></div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg animate-fade-up">
                    <div class="flex items-center"><i class="fas fa-exclamation-circle mr-2"></i><span><?= htmlspecialchars($error_message) ?></span></div>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-7 gap-6 mb-8">
                <div class="stat-card p-6 animate-slide-left delay-100">
                    <div class="flex justify-between items-start">
                        <div><p class="text-gray-500 text-sm font-medium">Total</p><h3 class="text-3xl font-bold text-gray-800 mt-2"><?= $stats['total'] ?? 0 ?></h3></div>
                        <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center"><i class="fas fa-file-alt text-blue-600 text-xl"></i></div>
                    </div>
                </div>
                
                <div class="stat-card p-6 animate-slide-left delay-200">
                    <div class="flex justify-between items-start">
                        <div><p class="text-gray-500 text-sm font-medium">Pending</p><h3 class="text-3xl font-bold text-yellow-600 mt-2"><?= $stats['pending'] ?? 0 ?></h3></div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center"><i class="fas fa-clock text-yellow-600 text-xl"></i></div>
                    </div>
                </div>
                
                <div class="stat-card p-6 animate-slide-left delay-300">
                    <div class="flex justify-between items-start">
                        <div><p class="text-gray-500 text-sm font-medium">Approved</p><h3 class="text-3xl font-bold text-green-600 mt-2"><?= $stats['approved'] ?? 0 ?></h3></div>
                        <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle text-green-600 text-xl"></i></div>
                    </div>
                </div>
                
                <div class="stat-card p-6 animate-slide-left delay-400">
                    <div class="flex justify-between items-start">
                        <div><p class="text-gray-500 text-sm font-medium">Rejected</p><h3 class="text-3xl font-bold text-red-600 mt-2"><?= $stats['rejected'] ?? 0 ?></h3></div>
                        <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center"><i class="fas fa-times-circle text-red-600 text-xl"></i></div>
                    </div>
                </div>
                
                <div class="stat-card p-6 animate-slide-left delay-500">
                    <div class="flex justify-between items-start">
                        <div><p class="text-gray-500 text-sm font-medium">Cancelled</p><h3 class="text-3xl font-bold text-gray-600 mt-2"><?= $stats['cancelled'] ?? 0 ?></h3></div>
                        <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center"><i class="fas fa-ban text-gray-600 text-xl"></i></div>
                    </div>
                </div>
                
                <div class="stat-card p-6 animate-slide-left delay-100">
                    <div class="flex justify-between items-start">
                        <div><p class="text-gray-500 text-sm font-medium">Students</p><h3 class="text-3xl font-bold text-blue-600 mt-2"><?= $stats['student_applications'] ?? 0 ?></h3></div>
                        <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center"><i class="fas fa-user-graduate text-blue-600 text-xl"></i></div>
                    </div>
                </div>
                
                <div class="stat-card p-6 animate-slide-left delay-200">
                    <div class="flex justify-between items-start">
                        <div><p class="text-gray-500 text-sm font-medium">Trainers</p><h3 class="text-3xl font-bold text-purple-600 mt-2"><?= $stats['trainer_applications'] ?? 0 ?></h3></div>
                        <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center"><i class="fas fa-chalkboard-teacher text-purple-600 text-xl"></i></div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card p-6 mb-8 animate-fade-up">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select name="status" class="form-select w-full">
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">User Type</label>
                        <select name="user_type" class="form-select w-full">
                            <option value="all" <?= $user_type_filter === 'all' ? 'selected' : '' ?>>All Users</option>
                            <option value="student" <?= $user_type_filter === 'student' ? 'selected' : '' ?>>Students Only</option>
                            <option value="trainer" <?= $user_type_filter === 'trainer' ? 'selected' : '' ?>>Trainers Only</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Name, Email, Application No..." class="form-input w-full">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                        <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input w-full">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                        <div class="flex gap-2">
                            <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input flex-1">
                            <button type="submit" class="btn-primary px-4 py-2 text-white rounded-lg">
                                <i class="fas fa-search"></i>
                            </button>
                            <a href="leave_management.php" class="btn-secondary px-4 py-2 text-white rounded-lg">
                                <i class="fas fa-sync-alt"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Applications List -->
            <div class="space-y-4">
                <?php if (empty($applications)): ?>
                    <div class="bg-white rounded-2xl shadow-lg p-12 text-center">
                        <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-calendar-times text-gray-400 text-4xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">No Applications Found</h3>
                        <p class="text-gray-500">No leave applications match your filter criteria.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($applications as $index => $app): ?>
                        <div class="application-card p-6" style="animation: fadeInUp 0.5s ease-out forwards; animation-delay: <?= $index * 0.05 ?>s">
                            <div class="flex flex-col lg:flex-row lg:items-start justify-between">
                                <!-- Application Info -->
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center gap-3 mb-4">
                                        <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($app['application_no']) ?></h3>
                                        <span class="status-badge <?= 
                                            $app['status'] === 'approved' ? 'status-approved' : 
                                            ($app['status'] === 'rejected' ? 'status-rejected' : 
                                            ($app['status'] === 'cancelled' ? 'status-cancelled' : 'status-pending')) ?>">
                                            <i class="fas <?= $app['status'] === 'approved' ? 'fa-check-circle' : ($app['status'] === 'rejected' ? 'fa-times-circle' : ($app['status'] === 'cancelled' ? 'fa-ban' : 'fa-clock')) ?> mr-1"></i>
                                            <?= ucfirst($app['status']) ?>
                                        </span>
                                        <span class="user-badge <?= $app['applicant_type'] === 'student' ? 'user-student' : 'user-trainer' ?>">
                                            <i class="fas <?= $app['applicant_type'] === 'student' ? 'fa-user-graduate' : 'fa-chalkboard-teacher' ?> mr-1"></i>
                                            <?= ucfirst($app['applicant_type']) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                        <div>
                                            <p class="text-xs text-gray-500">Applicant Name</p>
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($app['applicant_full_name'] ?? $app['student_name']) ?></p>
                                            <p class="text-xs text-gray-500">ID: <?= htmlspecialchars($app['student_id']) ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Email</p>
                                            <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($app['applicant_email'] ?? $app['email']) ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Phone</p>
                                            <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($app['applicant_phone'] ?? 'N/A') ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Batch</p>
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($app['batch_title'] ?? $app['batch_id']) ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Leave Period</p>
                                            <p class="font-semibold text-gray-800">
                                                <?= date('d M Y', strtotime($app['start_date'])) ?> - <?= date('d M Y', strtotime($app['end_date'])) ?>
                                            </p>
                                            <p class="text-xs text-gray-500"><?= $app['total_days'] ?> day(s)</p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Reason Category</p>
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($app['reason_category']) ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Applied On</p>
                                            <p class="font-semibold text-gray-800"><?= date('d M Y', strtotime($app['created_at'])) ?></p>
                                            <p class="text-xs text-gray-500"><?= date('h:i A', strtotime($app['created_at'])) ?></p>
                                        </div>
                                    </div>
                                    
                                    <!-- Detailed Reason -->
                                    <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                                        <p class="text-xs text-gray-500 mb-1">Reason:</p>
                                        <p class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($app['reason_detail'])) ?></p>
                                    </div>
                                    
                                    <?php if ($app['status'] === 'rejected' && !empty($app['rejection_reason'])): ?>
                                        <div class="mt-3 p-3 bg-red-50 rounded-lg border-l-4 border-red-500">
                                            <p class="text-xs text-red-800 font-medium">Rejection Reason:</p>
                                            <p class="text-sm text-red-700"><?= htmlspecialchars($app['rejection_reason']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($app['status'] === 'approved' && !empty($app['admin_remarks'])): ?>
                                        <div class="mt-3 p-3 bg-green-50 rounded-lg border-l-4 border-green-500">
                                            <p class="text-xs text-green-800 font-medium">Admin Remarks:</p>
                                            <p class="text-sm text-green-700"><?= htmlspecialchars($app['admin_remarks']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="mt-4 lg:mt-0 lg:ml-6 flex flex-col space-y-2 min-w-[200px]">
                                    <a href="view_application.php?id=<?= $app['id'] ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all text-center">
                                        <i class="fas fa-eye mr-2"></i> View Details
                                    </a>
                                    <?php if ($app['status'] === 'pending'): ?>
                                        <a href="accept_leave.php?id=<?= $app['id'] ?>" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all text-center">
                                            <i class="fas fa-check mr-2"></i> Approve
                                        </a>
                                        <a href="reject_leave.php?id=<?= $app['id'] ?>" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all text-center">
                                            <i class="fas fa-times mr-2"></i> Reject
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-20 right-4 p-4 rounded-lg shadow-lg text-white z-50 transform transition-all duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500'}`;
            toast.style.minWidth = '300px';
            toast.innerHTML = `<div class="flex items-center"><i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2 text-xl"></i><span>${message}</span></div>`;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('translate-x-0', 'opacity-100'), 10);
            setTimeout(() => {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('success')) {
                showToast(urlParams.get('message') || 'Operation completed successfully!', 'success');
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            if (urlParams.has('error')) {
                showToast(urlParams.get('message') || 'An error occurred!', 'error');
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
    </script>
</body>
</html>