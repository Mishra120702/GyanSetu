<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}

// Get current month and last month dates
$currentMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));

// Prepare all database queries with parameterized statements to prevent SQL injection
// Running Batches
$stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE status = 'ongoing'");
$stmt->execute();
$running_batches = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE status = 'ongoing' AND 
                     (start_date <= :lastMonthEnd AND (end_date >= :lastMonthStart OR end_date IS NULL))");
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->bindParam(':lastMonthStart', $lastMonthStart);
$stmt->execute();
$last_month_running = $stmt->fetchColumn();
$running_diff = $running_batches - $last_month_running;

// Upcoming Batches
$stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE status = 'upcoming'");
$stmt->execute();
$upcoming_batches = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE status = 'upcoming' AND 
                     created_at BETWEEN :lastMonthStart AND :lastMonthEnd");
$stmt->bindParam(':lastMonthStart', $lastMonthStart);
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->execute();
$last_month_upcoming = $stmt->fetchColumn();
$upcoming_diff = $upcoming_batches - $last_month_upcoming;

// Total Enrolled Students
$stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE current_status = 'active'");
$stmt->execute();
$total_students = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE current_status = 'active' AND 
                     enrollment_date <= :lastMonthEnd");
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->execute();
$last_month_students = $stmt->fetchColumn();
$students_diff = $total_students - $last_month_students;

// Classes Occurred
$stmt = $db->prepare("SELECT COUNT(DISTINCT date) FROM attendance");
$stmt->execute();
$classes_occurred = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(DISTINCT date) FROM attendance WHERE date BETWEEN :lastMonthStart AND :lastMonthEnd");
$stmt->bindParam(':lastMonthStart', $lastMonthStart);
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->execute();
$last_month_classes = $stmt->fetchColumn();
$classes_diff = $classes_occurred - $last_month_classes;

// Upcoming Live Classes
$stmt = $db->prepare("
    SELECT schedule_date, start_time, end_time, topic, batch_id 
    FROM schedule 
    WHERE schedule_date >= CURDATE() AND is_cancelled = 0 
    ORDER BY schedule_date ASC, start_time ASC 
    LIMIT 5
");
$stmt->execute();
$upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Absentees
$stmt = $db->prepare("
    SELECT student_name, date, batch_id 
    FROM attendance 
    WHERE status = 'Absent' 
    ORDER BY date DESC 
    LIMIT 2
");
$stmt->execute();
$recent_absentees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unaddressed Feedbacks
$stmt = $db->prepare("
    SELECT COUNT(*) FROM feedback 
    WHERE action_taken IS NULL OR action_taken = ''
");
$stmt->execute();
$unaddressed_feedbacks = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(*) FROM feedback 
    WHERE (action_taken IS NULL OR action_taken = '') AND date <= :lastMonthEnd
");
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->execute();
$last_month_feedbacks = $stmt->fetchColumn();
$feedbacks_diff = $unaddressed_feedbacks - $last_month_feedbacks;

// ==================== LEAVE INFORMATION ====================
// Pending Leave Applications
$stmt = $db->prepare("SELECT COUNT(*) FROM leave_applications WHERE status = 'pending'");
$stmt->execute();
$pending_leaves = $stmt->fetchColumn();

// Approved Leaves (This Month)
$stmt = $db->prepare("SELECT COUNT(*) FROM leave_applications WHERE status = 'approved' AND approved_at >= :monthStart");
$stmt->bindParam(':monthStart', $currentMonthStart);
$stmt->execute();
$approved_leaves_month = $stmt->fetchColumn();

// Total Leaves (All Time)
$stmt = $db->prepare("SELECT COUNT(*) FROM leave_applications WHERE status IN ('approved', 'pending')");
$stmt->execute();
$total_leaves = $stmt->fetchColumn();

// Recent Leave Applications (for display)
$stmt = $db->prepare("
    SELECT l.id, l.application_no, l.student_name, l.batch_id, l.start_date, l.end_date, l.status, l.created_at
    FROM leave_applications l
    WHERE l.status IN ('pending', 'approved')
    ORDER BY l.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== PAYMENT INFORMATION ====================
// Total Pending Payments (Unverified Transactions)
$stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
$stmt->execute();
$pending_payments = $stmt->fetchColumn();

// Total Verified Payments (This Month)
$stmt = $db->prepare("
    SELECT COUNT(*) FROM transactions 
    WHERE status = 'verified' AND verified_at >= :monthStart
");
$stmt->bindParam(':monthStart', $currentMonthStart);
$stmt->execute();
$verified_payments_month = $stmt->fetchColumn();

// Total Revenue (This Month - Verified Payments)
$stmt = $db->prepare("
    SELECT SUM(amount) FROM transactions 
    WHERE status = 'verified' AND verified_at >= :monthStart
");
$stmt->bindParam(':monthStart', $currentMonthStart);
$stmt->execute();
$monthly_revenue = $stmt->fetchColumn() ?: 0;

// Total Revenue (All Time)
$stmt = $db->prepare("SELECT SUM(amount) FROM transactions WHERE status = 'verified'");
$stmt->execute();
$total_revenue = $stmt->fetchColumn() ?: 0;

// Recent Payment Transactions (for display)
$stmt = $db->prepare("
    SELECT t.id, t.transaction_id, t.student_name, t.batch_id, t.amount, t.transaction_date, t.status, t.uploaded_at
    FROM transactions t
    WHERE t.status IN ('pending', 'verified')
    ORDER BY t.uploaded_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fee Status Summary
$stmt = $db->prepare("
    SELECT 
        SUM(CASE WHEN fees_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid,
        SUM(CASE WHEN fees_status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid,
        SUM(CASE WHEN fees_status = 'fully_paid' THEN 1 ELSE 0 END) as fully_paid,
        SUM(CASE WHEN fees_status = 'overdue' THEN 1 ELSE 0 END) as overdue
    FROM students 
    WHERE current_status = 'active'
");
$stmt->execute();
$fee_status_summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Overdue Payments Count
$stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE fees_status = 'overdue' AND current_status = 'active'");
$stmt->execute();
$overdue_payments = $stmt->fetchColumn();

// Recent Messages
$stmt = $db->prepare("
    SELECT m.message, m.created_at as sent_at, u.name as sender_name, m.conversation_id
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.conversation_id IN (
        SELECT conversation_id FROM conversation_members 
        WHERE user_id = :user_id AND is_active = TRUE
    )
    ORDER BY m.created_at DESC 
    LIMIT 3
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$recent_msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread notifications count
$stmt = $db->prepare("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = :user_id AND is_read = 0
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$unread_notifications = $stmt->fetchColumn();

// Pending feedback notifications
$stmt = $db->prepare("
    SELECT f.id, f.student_name, f.batch_id, f.feedback_text, f.date
    FROM feedback f
    WHERE (f.action_taken IS NULL OR f.action_taken = '')
    ORDER BY f.date DESC
    LIMIT 5
");
$stmt->execute();
$pending_feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages
$stmt = $db->prepare("
    SELECT m.id, m.message, m.created_at as sent_at, u.name as sender_name, m.conversation_id
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.conversation_id IN (
        SELECT conversation_id FROM conversation_members 
        WHERE user_id = :user_id AND is_active = TRUE
    )
    AND m.sender_id != :current_user_id
    AND m.is_read = 0
    ORDER BY m.created_at DESC
    LIMIT 5
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->bindParam(':current_user_id', $_SESSION['user_id']);
$stmt->execute();
$unread_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch status data for statistics cards
$ongoing_batches = $db->query("SELECT COUNT(*) FROM batches WHERE status='ongoing'")->fetchColumn();
$upcoming_batches_count = $db->query("SELECT COUNT(*) FROM batches WHERE status='upcoming'")->fetchColumn();
$completed_batches = $db->query("SELECT COUNT(*) FROM batches WHERE status='completed'")->fetchColumn();
$cancelled_batches = $db->query("SELECT COUNT(*) FROM batches WHERE status='cancelled'")->fetchColumn();
$total_batches = $ongoing_batches + $upcoming_batches_count + $completed_batches + $cancelled_batches;

// Check for new notifications since last visit
$last_notification_check = $_SESSION['last_notification_check'] ?? 0;
$stmt = $db->prepare("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = :user_id AND is_read = 0 AND created_at > FROM_UNIXTIME(:last_check)
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->bindParam(':last_check', $last_notification_check);
$stmt->execute();
$new_notifications_count = $stmt->fetchColumn();

// Update last check time
$_SESSION['last_notification_check'] = time();

// Determine if we should play notification sound
$play_notification_sound = $new_notifications_count > 0;

// Success messages
if (isset($_GET['success'])) {
    $success_messages = [
        'batch_created' => "Batch created successfully!",
        'notification_marked' => "Notifications marked as read!",
        'payment_verified' => "Payment verified successfully!",
        'leave_approved' => "Leave application approved!"
    ];
    
    if (array_key_exists($_GET['success'], $success_messages)) {
        $success_message = htmlspecialchars($success_messages[$_GET['success']]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #3B82F6;
            --primary-light: #EFF6FF;
            --primary-dark: #2563EB;
            --secondary: #10B981;
            --secondary-light: #D1FAE5;
            --danger: #EF4444;
            --danger-light: #FEE2E2;
            --warning: #F59E0B;
            --warning-light: #FEF3C7;
            --info: #6366F1;
            --info-light: #E0E7FF;
            --dark: #1F2937;
            --light: #F9FAFB;
            --gray: #6B7280;
            --gray-light: #E5E7EB;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #F3F4F6;
            min-height: 100vh;
            color: var(--dark);
        }
        
        /* Header styles */
        .dashboard-header {
            background: white;
            border-bottom: 1px solid var(--gray-light);
        }
        
        /* Notification styles */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: linear-gradient(135deg, var(--danger), #FCA5A5);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
        }
        
        .notification-badge.pulse {
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }
        
        /* Notification dropdown */
        #notificationDropdown {
            transform-origin: top right;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            opacity: 0;
            transform: scale(0.95) translateY(-10px);
            visibility: hidden;
            max-width: 380px;
        }
        
        #notificationDropdown.show {
            opacity: 1;
            transform: scale(1) translateY(0);
            visibility: visible;
        }
        
        .notification-item {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .notification-item:hover {
            transform: translateX(5px);
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.1), transparent);
        }
        
        /* Metric cards */
        .metric-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--gray-light);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--info));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }
        
        .metric-card:hover::before {
            transform: scaleX(1);
        }
        
        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        /* Info cards */
        .info-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-light);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .info-card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        /* Quick action buttons */
        .quick-action {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
        }
        
        .quick-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.3);
        }
        
        /* Floating animation */
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        
        .float-animation {
            animation: float 3s ease-in-out infinite;
        }
        
        /* Glow effect */
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 5px rgba(239, 68, 68, 0.5); }
            50% { box-shadow: 0 0 20px rgba(239, 68, 68, 0.8); }
        }
        
        .glow-effect {
            animation: glow 2s infinite;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary), var(--info));
            border-radius: 10px;
        }
        
        /* Main content area */
        .main-content {
            margin-left: 16rem;
            min-height: 100vh;
            background: #F3F4F6;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Success message animation */
        @keyframes slideIn {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .animate-slide-in {
            animation: slideIn 0.5s ease-out;
        }
        
        /* Fade out animation */
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        
        .fade-out {
            animation: fadeOut 1s ease forwards;
        }
        
        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .status-badge.ongoing {
            background: var(--primary-light);
            color: var(--primary-dark);
        }
        
        .status-badge.upcoming {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .status-badge.completed {
            background: var(--secondary-light);
            color: var(--secondary);
        }
        
        .status-badge.cancelled {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .status-badge.pending {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .status-badge.approved {
            background: var(--secondary-light);
            color: var(--secondary);
        }
        
        .status-badge.verified {
            background: var(--secondary-light);
            color: var(--secondary);
        }

        /* Batch status statistics card styles */
        .batch-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }
        
        .batch-stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-light);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            text-align: center;
        }
        
        .batch-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .batch-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 0.75rem auto;
        }
        
        .batch-stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }
        
        .batch-stat-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray);
            margin-top: 0.25rem;
        }
        
        .batch-stat-percent {
            font-size: 0.75rem;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid var(--gray-light);
            color: var(--gray);
        }
        
        @media (max-width: 768px) {
            .batch-stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
        }

        /* Fee status cards */
        .fee-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .fee-stat-card {
            background: #F9FAFB;
            border-radius: 0.75rem;
            padding: 1rem;
            text-align: center;
            border: 1px solid var(--gray-light);
        }
        
        .fee-stat-number {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .fee-stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }
        
        @media (max-width: 768px) {
            .fee-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
<?php 
include '../header.php';
include '../sidebar.php';
?>

<!-- Audio element for notification sound (hidden) -->
<audio id="notificationSound" preload="auto">
    <source src="../assets/sounds/notification.mp3" type="audio/mpeg">
    <source src="../assets/sounds/notification.ogg" type="audio/ogg">
</audio>

<!-- Main Content -->
<div class="main-content">
    <!-- Header -->
    <header class="dashboard-header px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-500 flex items-center justify-center text-white">
                <i class="fas fa-tachometer-alt"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-gray-800">
                    Admin Dashboard
                </h1>
                <p class="text-sm text-gray-500">Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>!</p>
            </div>
        </div>
        <div class="flex items-center space-x-4">
            <div class="relative">
                <button id="notificationButton" class="p-2 rounded-full hover:bg-gray-100 relative transition-colors duration-200 float-animation">
                    <div class="relative">
                        <i class="fas fa-bell text-gray-600 text-xl"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="notification-badge pulse"><?= $unread_notifications ?></span>
                        <?php endif; ?>
                    </div>
                </button>
                
                <!-- Notification Dropdown -->
                <div id="notificationDropdown" class="absolute right-0 mt-2 w-96 bg-white rounded-xl shadow-2xl z-50 border border-gray-200 hidden">
                    <div class="p-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 rounded-t-xl">
                        <h3 class="font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-bell mr-2 text-blue-600"></i>
                            Notifications
                        </h3>
                        <form action="../notifications/mark_notifications_read.php" method="POST">
                            <button type="submit" class="text-xs text-blue-600 hover:text-blue-800 transition-colors duration-200 flex items-center bg-white px-3 py-1 rounded-full shadow-sm">
                                <i class="fas fa-check-circle mr-1"></i> Mark all as read
                            </button>
                        </form>
                    </div>
                    <div class="max-h-96 overflow-y-auto">
                        <?php if (count($pending_feedbacks) > 0 || count($unread_messages) > 0): ?>
                            <?php foreach ($pending_feedbacks as $index => $feedback): ?>
                                <a href="../dashboard/pending_feedbacks.php" class="block">
                                    <div class="notification-item px-4 py-3 hover:bg-blue-50 border-b border-gray-100 last:border-0 <?= $index === 0 ? 'glow-effect' : '' ?>">
                                        <div class="flex items-start">
                                            <div class="relative flex-shrink-0">
                                                <div class="bg-gradient-to-br from-red-100 to-red-50 text-red-600 p-2 rounded-xl mr-3 flex items-center justify-center h-10 w-10">
                                                    <i class="fas fa-comment-dots"></i>
                                                </div>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex justify-between items-start">
                                                    <span class="font-medium text-gray-900 truncate">New Feedback</span>
                                                    <span class="text-xs text-gray-500 whitespace-nowrap ml-2 bg-gray-100 px-2 py-1 rounded-full">
                                                        <?= date('M j, g:i A', strtotime($feedback['date'])) ?>
                                                    </span>
                                                </div>
                                                <div class="text-sm text-gray-600 mt-1 truncate">
                                                    From <?= htmlspecialchars($feedback['student_name']) ?> (Batch <?= $feedback['batch_id'] ?>)
                                                </div>
                                                <div class="mt-2 text-xs text-red-600 font-medium flex items-center bg-red-50 px-2 py-1 rounded-full w-fit">
                                                    <i class="fas fa-exclamation-circle mr-1"></i> Requires action
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                            
                            <?php foreach ($unread_messages as $message): ?>
                                <a href="../chat/index.php" class="block">
                                    <div class="notification-item px-4 py-3 hover:bg-blue-50 border-b border-gray-100 last:border-0">
                                        <div class="flex items-start">
                                            <div class="relative flex-shrink-0">
                                                <div class="bg-gradient-to-br from-blue-100 to-blue-50 text-blue-600 p-2 rounded-xl mr-3 flex items-center justify-center h-10 w-10">
                                                    <i class="fas fa-comment"></i>
                                                </div>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex justify-between items-start">
                                                    <span class="font-medium text-gray-900 truncate">New Message</span>
                                                    <span class="text-xs text-gray-500 whitespace-nowrap ml-2 bg-gray-100 px-2 py-1 rounded-full">
                                                        <?= date('M j, g:i A', strtotime($message['sent_at'])) ?>
                                                    </span>
                                                </div>
                                                <div class="text-sm text-gray-600 mt-1">
                                                    <span class="font-semibold text-blue-600"><?= htmlspecialchars($message['sender_name']) ?>:</span> 
                                                    <?= htmlspecialchars(substr($message['message'], 0, 60)) ?>...
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-8 text-center text-gray-500 flex flex-col items-center">
                                <div class="bg-gradient-to-br from-gray-100 to-gray-50 p-4 rounded-full mb-3 text-gray-400">
                                    <i class="fas fa-bell-slash text-3xl"></i>
                                </div>
                                <p class="text-sm font-medium">No new notifications</p>
                                <p class="text-xs mt-1">You're all caught up!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3 border-t border-gray-200 text-center bg-gray-50 rounded-b-xl">
                        <a href="../notifications/all_notifications.php" class="text-sm text-blue-600 hover:text-blue-800 transition-colors duration-200 inline-flex items-center font-medium">
                            <i class="fas fa-list mr-1"></i> View all notifications
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>
            <a href="../logout.php" class="flex items-center space-x-2 bg-gradient-to-r from-red-500 to-red-600 text-white px-4 py-2 rounded-xl hover:from-red-600 hover:to-red-700 transition-all duration-200 shadow-md">
                <i class="fas fa-sign-out-alt"></i>
                <span class="text-sm font-medium">Logout</span>
            </a>    
        </div>
    </header>

    <div class="p-4 md:p-6">
        <?php if (isset($success_message)): ?>
            <div class="bg-gradient-to-r from-green-500 to-emerald-500 text-white p-4 mb-6 rounded-xl shadow-lg animate-slide-in" role="alert">
                <div class="flex items-center">
                    <div class="bg-white bg-opacity-20 rounded-full p-2 mr-3">
                        <i class="fas fa-check-circle text-white"></i>
                    </div>
                    <div>
                        <p class="font-bold">Success!</p>
                        <p class="text-sm opacity-90"><?= $success_message ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Metrics Row -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <!-- Running Batches -->
            <a href="../dashboard/running_batches.php" class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <div class="metric-icon bg-gradient-to-br from-blue-500 to-blue-600 text-white">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <span class="text-3xl font-bold text-gray-800"><?= $running_batches ?></span>
                </div>
                <h3 class="text-sm font-medium text-gray-500 mb-1">Running Batches</h3>
                <p class="text-xs flex items-center">
                    <?php if ($running_diff > 0): ?>
                        <span class="text-green-500 font-semibold">↑ +<?= $running_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php elseif ($running_diff < 0): ?>
                        <span class="text-red-500 font-semibold">↓ <?= $running_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php else: ?>
                        <span class="text-gray-400">→ No change from last month</span>
                    <?php endif; ?>
                </p>
            </a>
            
            <!-- Upcoming Batches -->
            <a href="../dashboard/upcoming_batches.php" class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <div class="metric-icon bg-gradient-to-br from-yellow-500 to-yellow-600 text-white">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <span class="text-3xl font-bold text-gray-800"><?= $upcoming_batches ?></span>
                </div>
                <h3 class="text-sm font-medium text-gray-500 mb-1">Upcoming Batches</h3>
                <p class="text-xs flex items-center">
                    <?php if ($upcoming_diff > 0): ?>
                        <span class="text-green-500 font-semibold">↑ +<?= $upcoming_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php elseif ($upcoming_diff < 0): ?>
                        <span class="text-red-500 font-semibold">↓ <?= $upcoming_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php else: ?>
                        <span class="text-gray-400">→ No change from last month</span>
                    <?php endif; ?>
                </p>
            </a>
            
            <!-- Enrolled Students -->
            <a href="../dashboard/enrolled_students.php" class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <div class="metric-icon bg-gradient-to-br from-green-500 to-green-600 text-white">
                        <i class="fas fa-users"></i>
                    </div>
                    <span class="text-3xl font-bold text-gray-800"><?= $total_students ?></span>
                </div>
                <h3 class="text-sm font-medium text-gray-500 mb-1">Enrolled Students</h3>
                <p class="text-xs flex items-center">
                    <?php if ($students_diff > 0): ?>
                        <span class="text-green-500 font-semibold">↑ +<?= $students_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php elseif ($students_diff < 0): ?>
                        <span class="text-red-500 font-semibold">↓ <?= $students_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php else: ?>
                        <span class="text-gray-400">→ No change from last month</span>
                    <?php endif; ?>
                </p>
            </a>
            
            <!-- Classes Occurred -->
            <a href="../dashboard/classes_occurred.php" class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <div class="metric-icon bg-gradient-to-br from-purple-500 to-purple-600 text-white">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <span class="text-3xl font-bold text-gray-800"><?= $classes_occurred ?></span>
                </div>
                <h3 class="text-sm font-medium text-gray-500 mb-1">Classes Occurred</h3>
                <p class="text-xs flex items-center">
                    <?php if ($classes_diff > 0): ?>
                        <span class="text-green-500 font-semibold">↑ +<?= $classes_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php elseif ($classes_diff < 0): ?>
                        <span class="text-red-500 font-semibold">↓ <?= $classes_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php else: ?>
                        <span class="text-gray-400">→ No change from last month</span>
                    <?php endif; ?>
                </p>
            </a>
            
            <!-- Pending Feedbacks -->
            <a href="../dashboard/pending_feedbacks.php" class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <div class="metric-icon bg-gradient-to-br from-red-500 to-red-600 text-white">
                        <i class="fas fa-comment-dots"></i>
                    </div>
                    <span class="text-3xl font-bold text-gray-800"><?= $unaddressed_feedbacks ?></span>
                </div>
                <h3 class="text-sm font-medium text-gray-500 mb-1">Pending Feedbacks</h3>
                <p class="text-xs flex items-center">
                    <?php if ($feedbacks_diff > 0): ?>
                        <span class="text-red-500 font-semibold">↑ +<?= $feedbacks_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php elseif ($feedbacks_diff < 0): ?>
                        <span class="text-green-500 font-semibold">↓ <?= $feedbacks_diff ?></span>
                        <span class="text-gray-400 ml-1">from last month</span>
                    <?php else: ?>
                        <span class="text-gray-400">→ No change from last month</span>
                    <?php endif; ?>
                </p>
            </a>
        </div>

        <!-- Leave and Payment Summary Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Leave Information Card -->
            <div class="info-card">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <div class="w-2 h-2 bg-orange-500 rounded-full mr-2"></div>
                        <i class="fas fa-calendar-check mr-2 text-orange-500"></i>
                        Leave Applications
                    </h2>
                    <a href="../leaves/leave_management.php" class="text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center">
                        Manage Leaves <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                
                <!-- Leave Stats -->
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-orange-50 rounded-lg p-3 text-center border border-orange-100">
                        <div class="text-2xl font-bold text-orange-600"><?= $pending_leaves ?></div>
                        <div class="text-xs text-gray-600">Pending</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-3 text-center border border-green-100">
                        <div class="text-2xl font-bold text-green-600"><?= $approved_leaves_month ?></div>
                        <div class="text-xs text-gray-600">Approved (This Month)</div>
                    </div>
                    <div class="bg-blue-50 rounded-lg p-3 text-center border border-blue-100">
                        <div class="text-2xl font-bold text-blue-600"><?= $total_leaves ?></div>
                        <div class="text-xs text-gray-600">Total Leaves</div>
                    </div>
                </div>
                
                <!-- Recent Leave Applications -->
                <div class="mt-3">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2 flex items-center">
                        <i class="fas fa-history mr-1 text-gray-500"></i>
                        Recent Applications
                    </h3>
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <?php if (count($recent_leaves) > 0): ?>
                            <?php foreach ($recent_leaves as $leave): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors duration-200">
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between">
                                            <span class="font-medium text-gray-800"><?= htmlspecialchars($leave['student_name']) ?></span>
                                            <span class="status-badge <?= $leave['status'] == 'pending' ? 'pending' : 'approved' ?>">
                                                <?= ucfirst($leave['status']) ?>
                                            </span>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <i class="far fa-calendar-alt mr-1"></i>
                                            <?= date('M d', strtotime($leave['start_date'])) ?> - <?= date('M d', strtotime($leave['end_date'])) ?>
                                            <span class="mx-1">•</span>
                                            <i class="fas fa-tag mr-1"></i> Batch #<?= $leave['batch_id'] ?>
                                        </div>
                                    </div>
                                    <a href="../leave/view_application.php?id=<?= $leave['id'] ?>" class="text-blue-600 hover:text-blue-800 ml-2">
                                        <i class="fas fa-eye text-sm"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-6 text-gray-500">
                                <i class="fas fa-check-circle text-3xl mb-2 text-green-400"></i>
                                <p class="text-sm">No pending leave applications</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Payment Information Card -->
            <div class="info-card">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                        <i class="fas fa-rupee-sign mr-2 text-green-500"></i>
                        Payment & Revenue
                    </h2>
                    <a href="../payments/transactions.php" class="text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                
                <!-- Revenue Stats -->
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="bg-green-50 rounded-lg p-3 text-center border border-green-100">
                        <div class="text-xl font-bold text-green-600">₹<?= number_format($monthly_revenue, 2) ?></div>
                        <div class="text-xs text-gray-600">This Month Revenue</div>
                    </div>
                    <div class="bg-blue-50 rounded-lg p-3 text-center border border-blue-100">
                        <div class="text-xl font-bold text-blue-600">₹<?= number_format($total_revenue, 2) ?></div>
                        <div class="text-xs text-gray-600">Total Revenue</div>
                    </div>
                </div>
                
                <!-- Payment Stats -->
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-yellow-50 rounded-lg p-2 text-center border border-yellow-100">
                        <div class="text-lg font-bold text-yellow-600"><?= $pending_payments ?></div>
                        <div class="text-xs text-gray-600">Pending Verification</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-2 text-center border border-green-100">
                        <div class="text-lg font-bold text-green-600"><?= $verified_payments_month ?></div>
                        <div class="text-xs text-gray-600">Verified (This Month)</div>
                    </div>
                    <div class="bg-red-50 rounded-lg p-2 text-center border border-red-100">
                        <div class="text-lg font-bold text-red-600"><?= $overdue_payments ?></div>
                        <div class="text-xs text-gray-600">Overdue</div>
                    </div>
                </div>
                
                <!-- Recent Transactions -->
                <div class="mt-3">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2 flex items-center">
                        <i class="fas fa-history mr-1 text-gray-500"></i>
                        Recent Transactions
                    </h3>
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <?php if (count($recent_payments) > 0): ?>
                            <?php foreach ($recent_payments as $payment): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors duration-200">
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between">
                                            <span class="font-medium text-gray-800"><?= htmlspecialchars($payment['student_name']) ?></span>
                                            <span class="status-badge <?= $payment['status'] == 'pending' ? 'pending' : 'verified' ?>">
                                                <?= ucfirst($payment['status']) ?>
                                            </span>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <i class="fas fa-rupee-sign mr-1"></i> ₹<?= number_format($payment['amount'], 2) ?>
                                            <span class="mx-1">•</span>
                                            <i class="fas fa-tag mr-1"></i> Batch #<?= $payment['batch_id'] ?>
                                            <span class="mx-1">•</span>
                                            <i class="far fa-calendar-alt mr-1"></i>
                                            <?= date('M d, Y', strtotime($payment['transaction_date'])) ?>
                                        </div>
                                    </div>
                                    <a href="../payments/view_transaction.php?id=<?= $payment['id'] ?>" class="text-blue-600 hover:text-blue-800 ml-2">
                                        <i class="fas fa-eye text-sm"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-6 text-gray-500">
                                <i class="fas fa-receipt text-3xl mb-2 text-gray-400"></i>
                                <p class="text-sm">No recent transactions</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Status Summary -->
        
        <!-- Batch Status Statistics -->
        <div class="info-card mb-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                    <div class="w-2 h-2 bg-blue-500 rounded-full mr-2"></div>
                    Batch Status Overview
                </h2>
                <a href="../batch/manage_batches.php" class="text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center">
                    Manage Batches <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
            <div class="batch-stats-grid">
                <div class="batch-stat-card">
                    <div class="batch-stat-icon bg-gradient-to-br from-blue-500 to-blue-600 text-white">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="batch-stat-number"><?= $ongoing_batches ?></div>
                    <div class="batch-stat-label">Ongoing Batches</div>
                    <div class="batch-stat-percent"><?= $total_batches > 0 ? round(($ongoing_batches / $total_batches) * 100) : 0 ?>% of total</div>
                </div>
                <div class="batch-stat-card">
                    <div class="batch-stat-icon bg-gradient-to-br from-yellow-500 to-yellow-600 text-white">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="batch-stat-number"><?= $upcoming_batches_count ?></div>
                    <div class="batch-stat-label">Upcoming Batches</div>
                    <div class="batch-stat-percent"><?= $total_batches > 0 ? round(($upcoming_batches_count / $total_batches) * 100) : 0 ?>% of total</div>
                </div>
                <div class="batch-stat-card">
                    <div class="batch-stat-icon bg-gradient-to-br from-green-500 to-green-600 text-white">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="batch-stat-number"><?= $completed_batches ?></div>
                    <div class="batch-stat-label">Completed Batches</div>
                    <div class="batch-stat-percent"><?= $total_batches > 0 ? round(($completed_batches / $total_batches) * 100) : 0 ?>% of total</div>
                </div>
                <div class="batch-stat-card">
                    <div class="batch-stat-icon bg-gradient-to-br from-red-500 to-red-600 text-white">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div class="batch-stat-number"><?= $cancelled_batches ?></div>
                    <div class="batch-stat-label">Cancelled Batches</div>
                    <div class="batch-stat-percent"><?= $total_batches > 0 ? round(($cancelled_batches / $total_batches) * 100) : 0 ?>% of total</div>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-200 flex justify-between items-center">
                <span class="text-sm text-gray-600">Total Batches: <strong class="text-gray-800"><?= $total_batches ?></strong></span>
                <a href="../batch/add_batch.php" class="text-sm bg-blue-50 hover:bg-blue-100 text-blue-600 px-3 py-1.5 rounded-lg transition-colors duration-200 font-medium">
                    <i class="fas fa-plus mr-1"></i> Add New Batch
                </a>
            </div>
        </div>

        <!-- Class & Absentee Info -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Upcoming Live Classes -->
            <div class="info-card">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                        Upcoming Live Classes
                    </h2>
                    <a href="../dashboard/upcoming_live_classes.php" class="text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                <div class="space-y-3">
                    <?php foreach ($upcoming_classes as $class): ?>
                        <div class="flex items-start p-3 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-100 hover:border-blue-300 transition-all duration-200">
                            <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white p-3 rounded-xl mr-3 shadow-md">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-semibold text-gray-800"><?= htmlspecialchars($class['topic']) ?></h4>
                                <div class="flex items-center text-sm text-gray-600 mt-1">
                                    <i class="far fa-calendar-alt mr-1 text-blue-500"></i>
                                    <?= date('D, M j', strtotime($class['schedule_date'])) ?>
                                    <i class="far fa-clock ml-3 mr-1 text-blue-500"></i>
                                    <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                </div>
                                <span class="status-badge upcoming mt-2 inline-flex">
                                    <i class="fas fa-tag mr-1"></i> Batch #<?= $class['batch_id'] ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($upcoming_classes)): ?>
                        <div class="p-8 bg-gray-50 rounded-xl border border-gray-200 text-center">
                            <div class="bg-gray-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-3">
                                <i class="far fa-calendar-times text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-gray-500 font-medium">No upcoming classes scheduled</p>
                            <p class="text-xs text-gray-400 mt-1">Check back later for updates</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Absentees -->
            <div class="info-card">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <div class="w-2 h-2 bg-red-500 rounded-full mr-2"></div>
                        Recent Absentees
                    </h2>
                    <a href="../dashboard/absent_reasons.php" class="text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                <div class="space-y-3">
                    <?php foreach ($recent_absentees as $absent): ?>
                        <div class="flex items-center p-3 bg-gradient-to-r from-red-50 to-orange-50 rounded-xl border border-red-100 hover:border-red-300 transition-all duration-200">
                            <div class="bg-gradient-to-br from-red-500 to-red-600 text-white p-3 rounded-xl mr-3 shadow-md">
                                <i class="fas fa-user-times"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h4 class="font-semibold text-gray-800"><?= htmlspecialchars($absent['student_name']) ?></h4>
                                        <div class="flex items-center text-sm text-gray-600 mt-1">
                                            <i class="far fa-calendar-alt mr-1 text-red-500"></i>
                                            <?= date('M j, Y', strtotime($absent['date'])) ?>
                                        </div>
                                    </div>
                                    <span class="status-badge cancelled">
                                        <i class="fas fa-tag mr-1"></i> Batch #<?= $absent['batch_id'] ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($recent_absentees)): ?>
                        <div class="p-8 bg-gray-50 rounded-xl border border-gray-200 text-center">
                            <div class="bg-green-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-user-check text-green-500 text-2xl"></i>
                            </div>
                            <p class="text-gray-500 font-medium">No absentees today</p>
                            <p class="text-xs text-gray-400 mt-1">Perfect attendance record!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Messages and Quick Actions -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Messages -->
            <div class="info-card lg:col-span-2">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <div class="w-2 h-2 bg-purple-500 rounded-full mr-2"></div>
                        Recent Messages
                    </h2>
                    <a href="../chat/index.php" class="text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center">
                        Open Chat <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                <div class="space-y-3">
                    <?php foreach ($recent_msgs as $msg): ?>
                        <div class="flex items-start p-3 bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl border border-purple-100 hover:border-purple-300 transition-all duration-200">
                            <div class="bg-gradient-to-br from-purple-500 to-purple-600 text-white p-3 rounded-xl mr-3 shadow-md">
                                <i class="fas fa-comment"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="font-semibold text-gray-800"><?= htmlspecialchars($msg['sender_name']) ?></span>
                                        <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($msg['message']) ?></p>
                                    </div>
                                    <span class="text-xs bg-white px-2 py-1 rounded-full text-gray-500 shadow-sm">
                                        <?= date('M j, g:i A', strtotime($msg['sent_at'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($recent_msgs)): ?>
                        <div class="p-8 bg-gray-50 rounded-xl border border-gray-200 text-center">
                            <div class="bg-purple-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-comment-slash text-purple-500 text-2xl"></i>
                            </div>
                            <p class="text-gray-500 font-medium">No recent messages</p>
                            <p class="text-xs text-gray-400 mt-1">Start a conversation in the chat</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="info-card">
                <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <div class="w-2 h-2 bg-yellow-500 rounded-full mr-2"></div>
                    Quick Actions
                </h2>
                <div class="grid grid-cols-2 gap-3">
                    <a href="add_batch.php" class="quick-action flex flex-col items-center justify-center p-4 bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl border border-blue-100 hover:shadow-lg group">
                        <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white p-3 rounded-xl mb-2 group-hover:scale-110 transition-transform duration-200">
                            <i class="fas fa-plus"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700">Add Batch</span>
                    </a>
                    <a href="../attendance/attendance.php" class="quick-action flex flex-col items-center justify-center p-4 bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl border border-green-100 hover:shadow-lg group">
                        <div class="bg-gradient-to-br from-green-500 to-green-600 text-white p-3 rounded-xl mb-2 group-hover:scale-110 transition-transform duration-200">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700">Mark Attendance</span>
                    </a>
                    <a href="../payments/verify_payments.php" class="quick-action flex flex-col items-center justify-center p-4 bg-gradient-to-br from-yellow-50 to-amber-50 rounded-xl border border-yellow-100 hover:shadow-lg group">
                        <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 text-white p-3 rounded-xl mb-2 group-hover:scale-110 transition-transform duration-200">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700">Verify Payments</span>
                    </a>
                    <a href="../leave/manage_leaves.php" class="quick-action flex flex-col items-center justify-center p-4 bg-gradient-to-br from-orange-50 to-red-50 rounded-xl border border-orange-100 hover:shadow-lg group">
                        <div class="bg-gradient-to-br from-orange-500 to-red-500 text-white p-3 rounded-xl mb-2 group-hover:scale-110 transition-transform duration-200">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-700">Manage Leaves</span>
                    </a>
                </div>
                
                <!-- Additional quick stats -->
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-600">Total Batches</span>
                        <span class="font-semibold text-gray-800"><?= $total_batches ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm mt-2">
                        <span class="text-gray-600">Active Students</span>
                        <span class="font-semibold text-gray-800"><?= $total_students ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm mt-2">
                        <span class="text-gray-600">Completion Rate</span>
                        <span class="font-semibold text-green-600">
                            <?= $total_batches > 0 ? round(($completed_batches / $total_batches) * 100) : 0 ?>%
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Play notification sound if there are new notifications
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($play_notification_sound): ?>
            const notificationSound = document.getElementById('notificationSound');
            notificationSound.volume = 0.3;
            notificationSound.play().catch(e => console.log('Notification sound play failed:', e));
            
            // Add animation to notification icon
            const notificationIcon = document.querySelector('.fa-bell').parentElement;
            if (notificationIcon) {
                notificationIcon.classList.add('animate-pulse');
                setTimeout(() => {
                    notificationIcon.classList.remove('animate-pulse');
                }, 3000);
            }
        <?php endif; ?>
        
        // Auto-hide success message
        const successMessage = document.querySelector('.animate-slide-in');
        if (successMessage) {
            setTimeout(() => {
                successMessage.classList.add('fade-out');
                setTimeout(() => {
                    successMessage.remove();
                }, 1000);
            }, 5000);
        }
    });

    // Enhanced notification dropdown toggle with animations
    const notificationButton = document.getElementById('notificationButton');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    notificationButton.addEventListener('click', function(e) {
        e.stopPropagation();
        
        if (notificationDropdown.classList.contains('hidden')) {
            // Open dropdown with animation
            notificationDropdown.classList.remove('hidden');
            setTimeout(() => {
                notificationDropdown.classList.add('show');
            }, 10);
            
            // Hide the notification badge when dropdown is opened
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.style.display = 'none';
            }
            
            // Mark notifications as seen via AJAX
            fetch('../notifications/mark_notifications_seen.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            }).catch(error => console.error('Error:', error));
        } else {
            // Close dropdown with animation
            notificationDropdown.classList.remove('show');
            setTimeout(() => {
                notificationDropdown.classList.add('hidden');
            }, 200);
        }
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        if (!notificationDropdown.classList.contains('hidden') && 
            !notificationButton.contains(event.target) && 
            !notificationDropdown.contains(event.target)) {
            notificationDropdown.classList.remove('show');
            setTimeout(() => {
                notificationDropdown.classList.add('hidden');
            }, 200);
        }
    });
    
    // Prevent dropdown from closing when clicking inside it
    notificationDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });
    
    // Add hover effect to metric cards
    document.querySelectorAll('.metric-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = '';
        });
    });

    // Add ripple effect to buttons
    function createRipple(event) {
        const button = event.currentTarget;
        const ripple = document.createElement('span');
        const rect = button.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        const x = event.clientX - rect.left - size / 2;
        const y = event.clientY - rect.top - size / 2;
        
        ripple.style.width = ripple.style.height = `${size}px`;
        ripple.style.left = `${x}px`;
        ripple.style.top = `${y}px`;
        ripple.classList.add('ripple');
        
        const existingRipple = button.querySelector('.ripple');
        if (existingRipple) {
            existingRipple.remove();
        }
        
        button.appendChild(ripple);
        
        setTimeout(() => {
            ripple.remove();
        }, 600);
    }

    document.querySelectorAll('.quick-action').forEach(button => {
        button.addEventListener('click', createRipple);
    });
</script>

<style>
    /* Ripple effect */
    .ripple {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.5);
        transform: scale(0);
        animation: ripple-animation 0.6s ease-out;
        pointer-events: none;
    }
    
    @keyframes ripple-animation {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
    
    /* Loading animation */
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .loading {
        animation: spin 1s linear infinite;
    }
    
    /* Hover glow effects */
    .hover-glow {
        transition: box-shadow 0.3s ease;
    }
    
    .hover-glow:hover {
        box-shadow: 0 0 15px rgba(59, 130, 246, 0.5);
    }
</style>

<?php include '../footer.php'; ?>