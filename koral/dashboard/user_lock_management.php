<?php
include '../db_connection.php';
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}

$success_message = '';
$error_message = '';

// Handle lock/unlock actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Single lock/unlock action
    if (isset($_POST['lock_user']) || isset($_POST['unlock_user'])) {
        $user_id = (int)$_POST['user_id'];
        $reason = trim($_POST['reason']);
        $lock_type = $_POST['lock_type'] ?? 'permanent';
        $duration_days = isset($_POST['duration_days']) ? (int)$_POST['duration_days'] : 0;
        $expiry_date = null;
        
        if ($lock_type === 'temporary' && $duration_days > 0) {
            $expiry_date = date('Y-m-d', strtotime("+$duration_days days"));
        }
        
        if (isset($_POST['lock_user'])) {
            // Lock the user
            $stmt = $db->prepare("UPDATE users SET account_locked = 1, locked_reason = ?, locked_at = NOW(), locked_by = ? WHERE id = ?");
            if ($stmt->execute([$reason, $_SESSION['user_id'], $user_id])) {
                // Log the action
                $stmt = $db->prepare("INSERT INTO user_lock_logs (user_id, action, reason, performed_by, duration_days, expiry_date) VALUES (?, 'locked', ?, ?, ?, ?)");
                $stmt->execute([$user_id, $reason, $_SESSION['user_id'], $duration_days, $expiry_date]);
                
                $success_message = "User locked successfully!";
            } else {
                $error_message = "Failed to lock user";
            }
        } else {
            // Unlock the user
            $stmt = $db->prepare("UPDATE users SET account_locked = 0, locked_reason = NULL, locked_at = NULL, locked_by = NULL, failed_login_attempts = 0 WHERE id = ?");
            if ($stmt->execute([$user_id])) {
                // Log the action
                $stmt = $db->prepare("INSERT INTO user_lock_logs (user_id, action, reason, performed_by) VALUES (?, 'unlocked', ?, ?)");
                $stmt->execute([$user_id, $reason, $_SESSION['user_id']]);
                
                $success_message = "User unlocked successfully!";
            } else {
                $error_message = "Failed to unlock user";
            }
        }
    }
    // Bulk actions
    elseif (isset($_POST['bulk_action'])) {
        $bulk_action = $_POST['bulk_action'];
        $bulk_reason = trim($_POST['bulk_reason'] ?? '');
        $bulk_lock_type = $_POST['bulk_lock_type'] ?? 'permanent';
        $bulk_duration_days = isset($_POST['bulk_duration_days']) ? (int)$_POST['bulk_duration_days'] : 0;
        $bulk_expiry_date = null;
        
        if ($bulk_lock_type === 'temporary' && $bulk_duration_days > 0) {
            $bulk_expiry_date = date('Y-m-d', strtotime("+$bulk_duration_days days"));
        }
        
        if (isset($_POST['selected_users']) && is_array($_POST['selected_users'])) {
            $selected_users = array_map('intval', $_POST['selected_users']);
            $success_count = 0;
            $error_count = 0;
            
            foreach ($selected_users as $user_id) {
                try {
                    if ($bulk_action === 'lock') {
                        // Lock user
                        $stmt = $db->prepare("UPDATE users SET account_locked = 1, locked_reason = ?, locked_at = NOW(), locked_by = ? WHERE id = ?");
                        if ($stmt->execute([$bulk_reason, $_SESSION['user_id'], $user_id])) {
                            // Log the action
                            $stmt = $db->prepare("INSERT INTO user_lock_logs (user_id, action, reason, performed_by, duration_days, expiry_date) VALUES (?, 'locked', ?, ?, ?, ?)");
                            $stmt->execute([$user_id, $bulk_reason, $_SESSION['user_id'], $bulk_duration_days, $bulk_expiry_date]);
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    } 
                    elseif ($bulk_action === 'unlock') {
                        // Unlock user
                        $stmt = $db->prepare("UPDATE users SET account_locked = 0, locked_reason = NULL, locked_at = NULL, locked_by = NULL, failed_login_attempts = 0 WHERE id = ?");
                        if ($stmt->execute([$user_id])) {
                            // Log the action
                            $stmt = $db->prepare("INSERT INTO user_lock_logs (user_id, action, reason, performed_by) VALUES (?, 'unlocked', ?, ?)");
                            $stmt->execute([$user_id, $bulk_reason, $_SESSION['user_id']]);
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    }
                    elseif ($bulk_action === 'reset_attempts') {
                        // Reset failed attempts
                        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = 0 WHERE id = ?");
                        if ($stmt->execute([$user_id])) {
                            // Log the action
                            $stmt = $db->prepare("INSERT INTO user_lock_logs (user_id, action, reason, performed_by) VALUES (?, 'reset_attempts', ?, ?)");
                            $stmt->execute([$user_id, 'Failed login attempts reset', $_SESSION['user_id']]);
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    }
                } catch (Exception $e) {
                    $error_count++;
                    error_log("Bulk action failed for user $user_id: " . $e->getMessage());
                }
            }
            
            if ($success_count > 0) {
                $success_message = "Bulk action completed successfully! $success_count user(s) updated.";
                if ($error_count > 0) {
                    $success_message .= " $error_count user(s) failed.";
                }
            } else {
                $error_message = "Bulk action failed for all selected users.";
            }
        } else {
            $error_message = "No users selected for bulk action.";
        }
    }
}

// Get filter parameters
$filter_role = $_GET['role'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_search = $_GET['search'] ?? '';
$filter_start_date = $_GET['start_date'] ?? '';
$filter_end_date = $_GET['end_date'] ?? '';

// Build query with filters
$query = "SELECT u.*, 
          (SELECT COUNT(*) FROM user_lock_logs WHERE user_id = u.id AND action = 'locked') as lock_count,
          (SELECT MAX(performed_at) FROM user_lock_logs WHERE user_id = u.id) as last_action_date
          FROM users u WHERE 1=1";
$params = [];

if ($filter_role) {
    $query .= " AND u.role = ?";
    $params[] = $filter_role;
}

if ($filter_status !== '') {
    $query .= " AND u.account_locked = ?";
    $params[] = $filter_status;
}

if ($filter_search) {
    $query .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.id = ?)";
    $search_param = "%$filter_search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $filter_search;
}

if ($filter_start_date) {
    $query .= " AND DATE(u.created_at) >= ?";
    $params[] = $filter_start_date;
}

if ($filter_end_date) {
    $query .= " AND DATE(u.created_at) <= ?";
    $params[] = $filter_end_date;
}

$query .= " ORDER BY u.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get lock history for each user
foreach ($users as &$user) {
    $stmt = $db->prepare("SELECT * FROM user_lock_logs WHERE user_id = ? ORDER BY performed_at DESC LIMIT 5");
    $stmt->execute([$user['id']]);
    $user['lock_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($user);

// Get statistics
$stats = $db->query("SELECT 
    COUNT(*) as total_users,
    SUM(account_locked = 1) as locked_users,
    SUM(account_locked = 0) as active_users,
    SUM(failed_login_attempts > 0) as failed_attempt_users
    FROM users")->fetch(PDO::FETCH_ASSOC);

// Get recent lock activities
$recent_locks = $db->query("SELECT 
    ul.*, 
    u.name as user_name,
    a.name as admin_name
    FROM user_lock_logs ul
    LEFT JOIN users u ON ul.user_id = u.id
    LEFT JOIN users a ON ul.performed_by = a.id
    ORDER BY ul.performed_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Lock Management - ASD Academy</title>
    <?php include '../header.php'; ?>
    <style>
        :root {
            --primary-color: #667eea;
            --danger-color: #e53e3e;
            --warning-color: #dd6b20;
            --success-color: #38a169;
            --info-color: #4299e1;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.1);
        }
        
        .stat-card {
            transition: all 0.3s ease;
            cursor: pointer;
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        
        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stat-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 24px rgba(31, 38, 135, 0.15);
        }
        
        .user-card {
            animation: slideIn 0.5s ease forwards;
            opacity: 0;
            transform: translateX(-20px);
        }
        
        @keyframes slideIn {
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .badge-locked {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #9b2c2c;
            border: 1px solid #feb2b2;
        }
        
        .badge-active {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        
        .badge-warning {
            background: linear-gradient(135deg, #feebc8 0%, #fbd38d 100%);
            color: #9c4221;
            border: 1px solid #fbd38d;
        }
        
        .badge-admin {
            background: linear-gradient(135deg, #e9d8fd 0%, #d6bcfa 100%);
            color: #553c9a;
            border: 1px solid #d6bcfa;
        }
        
        .badge-mentor {
            background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%);
            color: #2c5282;
            border: 1px solid #90cdf4;
        }
        
        .badge-student {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        
        .lock-reason {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(135deg, rgba(254, 215, 215, 0.1) 0%, rgba(254, 178, 178, 0.1) 100%);
            border-radius: 8px;
        }
        
        .lock-reason.show {
            max-height: 200px;
            padding: 12px;
            margin-top: 8px;
            border: 1px solid rgba(254, 178, 178, 0.3);
        }
        
        .filter-container {
            animation: fadeInDown 0.5s ease;
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
        }
        
        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 16px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            transform: scale(0.9) translateY(20px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-overlay.show .modal-content {
            transform: scale(1) translateY(0);
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, #e2e8f0 0%, #cbd5e0 100%);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -25px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 3px;
            transition: all 0.3s ease;
        }
        
        .timeline-item.locked::before {
            background: var(--danger-color);
            box-shadow: 0 0 0 3px var(--danger-color);
        }
        
        .timeline-item.unlocked::before {
            background: var(--success-color);
            box-shadow: 0 0 0 3px var(--success-color);
        }
        
        .timeline-item.reset_attempts::before {
            background: var(--warning-color);
            box-shadow: 0 0 0 3px var(--warning-color);
        }
        
        .action-btn {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .action-btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        
        .action-btn:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        
        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            100% {
                transform: scale(20, 20);
                opacity: 0;
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(230, 62, 62, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(230, 62, 62, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(230, 62, 62, 0);
            }
        }
        
        .shimmer {
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
            position: relative;
            overflow: hidden;
        }
        
        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, 
                rgba(255,255,255,0.1) 25%, 
                rgba(255,255,255,0.3) 50%, 
                rgba(255,255,255,0.1) 75%);
            animation: shimmer 2s infinite;
        }
        
        .checkbox-container {
            position: relative;
            cursor: pointer;
        }
        
        .checkbox-container input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }
        
        .checkmark {
            position: relative;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background-color: #f8fafc;
            border: 2px solid #cbd5e0;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .checkbox-container:hover input ~ .checkmark {
            border-color: #667eea;
            background-color: #ebf4ff;
        }
        
        .checkbox-container input:checked ~ .checkmark {
            background-color: #667eea;
            border-color: #667eea;
        }
        
        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
            left: 6px;
            top: 2px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        .checkbox-container input:checked ~ .checkmark:after {
            display: block;
            animation: checkmark 0.3s ease;
        }
        
        @keyframes checkmark {
            0% {
                transform: rotate(45deg) scale(0);
            }
            50% {
                transform: rotate(45deg) scale(1.2);
            }
            100% {
                transform: rotate(45deg) scale(1);
            }
        }
        
        .floating-action {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 100;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) scale(1);
            }
            50% {
                transform: translateY(-10px) scale(1.05);
            }
        }
        
        .tooltip {
            position: relative;
        }
        
        .tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background-color: #1a202c;
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .tooltip .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #1a202c transparent transparent transparent;
        }
        
        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
            border-radius: 4px;
        }
        
        @keyframes skeleton-loading {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }
        
        .rotate-icon {
            transition: transform 0.3s ease;
        }
        
        .rotate-icon.rotate {
            transform: rotate(180deg);
        }
        
        .slide-down {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .slide-down.show {
            max-height: 500px;
        }
        
        .blink {
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-user-lock text-red-500"></i>
                <span>User Lock Management</span>
            </h1>
            <div class="flex items-center space-x-3">
                <span class="text-sm text-gray-500 hidden md:block">
                    <i class="fas fa-users mr-1"></i>
                    Total Users: <span class="font-semibold"><?php echo $stats['total_users']; ?></span>
                </span>
                <button onclick="showBulkActionModal()" 
                        class="bg-gradient-to-r from-blue-500 to-purple-600 text-white px-4 py-2 rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all action-btn shadow-lg">
                    <i class="fas fa-bolt mr-2"></i>Bulk Actions
                </button>
            </div>
        </header>

        <!-- Statistics Cards -->
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="glass-card stat-card p-6 cursor-pointer" onclick="filterByStatus(1)" style="animation-delay: 0.1s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Locked Users</p>
                        <h3 class="text-3xl font-bold text-red-600"><?php echo $stats['locked_users']; ?></h3>
                        <p class="text-xs text-gray-400 mt-1">Click to view</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-red-100 to-red-200 rounded-full flex items-center justify-center shadow-inner">
                        <i class="fas fa-lock text-red-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="progress-bar">
                        <div class="progress-fill bg-gradient-to-r from-red-400 to-red-500" 
                             style="width: <?php echo $stats['total_users'] > 0 ? ($stats['locked_users']/$stats['total_users'])*100 : 0; ?>%"></div>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-6 cursor-pointer" onclick="filterByStatus(0)" style="animation-delay: 0.2s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Active Users</p>
                        <h3 class="text-3xl font-bold text-green-600"><?php echo $stats['active_users']; ?></h3>
                        <p class="text-xs text-gray-400 mt-1">Click to view</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 rounded-full flex items-center justify-center shadow-inner">
                        <i class="fas fa-user-check text-green-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="progress-bar">
                        <div class="progress-fill bg-gradient-to-r from-green-400 to-green-500" 
                             style="width: <?php echo $stats['total_users'] > 0 ? ($stats['active_users']/$stats['total_users'])*100 : 0; ?>%"></div>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-6" style="animation-delay: 0.3s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Failed Attempts</p>
                        <h3 class="text-3xl font-bold text-yellow-600"><?php echo $stats['failed_attempt_users']; ?></h3>
                        <p class="text-xs text-yellow-500 mt-1">Requires attention</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-full flex items-center justify-center shadow-inner">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="progress-bar">
                        <div class="progress-fill bg-gradient-to-r from-yellow-400 to-yellow-500" 
                             style="width: <?php echo $stats['total_users'] > 0 ? ($stats['failed_attempt_users']/$stats['total_users'])*100 : 0; ?>%"></div>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-6 cursor-pointer" onclick="resetFilters()" style="animation-delay: 0.4s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Total Lock Actions</p>
                        <?php 
                        $total_locks = $db->query("SELECT COUNT(*) FROM user_lock_logs")->fetchColumn();
                        ?>
                        <h3 class="text-3xl font-bold text-blue-600"><?php echo $total_locks; ?></h3>
                        <p class="text-xs text-gray-400 mt-1">Click to reset filters</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-blue-100 to-blue-200 rounded-full flex items-center justify-center shadow-inner">
                        <i class="fas fa-history text-blue-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="progress-bar">
                        <div class="progress-fill bg-gradient-to-r from-blue-400 to-blue-500" 
                             style="width: <?php echo min(100, $total_locks/10); ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="px-6">
            <div class="glass-card filter-container p-6 mb-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-search mr-1 text-gray-400"></i>Search
                        </label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" 
                               placeholder="Name, Email, or ID" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user-tag mr-1 text-gray-400"></i>Role
                        </label>
                        <select name="role" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $filter_role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="mentor" <?php echo $filter_role === 'mentor' ? 'selected' : ''; ?>>Mentor</option>
                            <option value="student" <?php echo $filter_role === 'student' ? 'selected' : ''; ?>>Student</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user-shield mr-1 text-gray-400"></i>Status
                        </label>
                        <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                            <option value="">All Status</option>
                            <option value="1" <?php echo $filter_status === '1' ? 'selected' : ''; ?>>Locked</option>
                            <option value="0" <?php echo $filter_status === '0' ? 'selected' : ''; ?>>Active</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-alt mr-1 text-gray-400"></i>From Date
                        </label>
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($filter_start_date); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-alt mr-1 text-gray-400"></i>To Date
                        </label>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($filter_end_date); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                    </div>
                    
                    <div class="lg:col-span-5 flex justify-end space-x-3 mt-4">
                        <button type="submit" 
                                class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-6 py-2 rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all action-btn shadow-md flex items-center">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                        <a href="?" 
                           class="bg-gradient-to-r from-gray-200 to-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:from-gray-300 hover:to-gray-400 transition-all shadow-md flex items-center">
                            <i class="fas fa-redo mr-2"></i>Reset
                        </a>
                        <button type="button" onclick="exportToCSV()" 
                                class="bg-gradient-to-r from-green-500 to-green-600 text-white px-6 py-2 rounded-lg hover:from-green-600 hover:to-green-700 transition-all action-btn shadow-md flex items-center">
                            <i class="fas fa-file-export mr-2"></i>Export
                        </button>
                        <button type="button" onclick="toggleAdvancedFilters()" 
                                class="bg-gradient-to-r from-purple-500 to-purple-600 text-white px-6 py-2 rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all action-btn shadow-md flex items-center">
                            <i class="fas fa-sliders-h mr-2"></i>Advanced
                        </button>
                    </div>
                    
                    <!-- Advanced Filters -->
                    <div id="advancedFilters" class="lg:col-span-5 slide-down mt-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Min Failed Attempts</label>
                                <input type="number" name="min_attempts" min="0" value="<?php echo $_GET['min_attempts'] ?? ''; ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Account Created After</label>
                                <input type="date" name="created_after" value="<?php echo $_GET['created_after'] ?? ''; ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Last Login Before</label>
                                <input type="date" name="login_before" value="<?php echo $_GET['login_before'] ?? ''; ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Users Table -->
        <div class="px-6 pb-6">
            <div class="glass-card overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <div class="checkbox-container">
                            <input type="checkbox" id="selectAll" class="select-all-checkbox">
                            <span class="checkmark"></span>
                        </div>
                        <span class="text-sm text-gray-600">
                            <span id="selectedCount">0</span> selected
                        </span>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="selectByRole('admin')" 
                                class="text-xs px-3 py-1 bg-purple-100 text-purple-700 rounded-full hover:bg-purple-200 transition-all">
                            <i class="fas fa-crown mr-1"></i>Select Admins
                        </button>
                        <button onclick="selectByStatus('locked')" 
                                class="text-xs px-3 py-1 bg-red-100 text-red-700 rounded-full hover:bg-red-200 transition-all">
                            <i class="fas fa-lock mr-1"></i>Select Locked
                        </button>
                        <button onclick="deselectAll()" 
                                class="text-xs px-3 py-1 bg-gray-100 text-gray-700 rounded-full hover:bg-gray-200 transition-all">
                            <i class="fas fa-times mr-1"></i>Deselect All
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Select
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Failed Attempts</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="userTableBody">
                            <?php foreach ($users as $index => $user): ?>
                            <tr class="user-card hover:bg-gray-50 transition-colors" style="animation-delay: <?php echo $index * 0.05; ?>s" 
                                data-user-id="<?php echo $user['id']; ?>"
                                data-role="<?php echo $user['role']; ?>"
                                data-status="<?php echo $user['account_locked'] ? 'locked' : 'active'; ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="checkbox-container">
                                        <input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" 
                                               class="user-checkbox">
                                        <span class="checkmark"></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-gradient-to-br from-blue-100 to-blue-200 rounded-full flex items-center justify-center shadow-inner">
                                            <span class="text-blue-600 font-bold"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></span>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                            <div class="text-xs text-gray-400 mt-1">
                                                <i class="fas fa-id-card mr-1"></i>ID: <?php echo $user['id']; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="badge badge-<?php echo $user['role']; ?>">
                                        <i class="fas fa-<?php echo $user['role'] === 'admin' ? 'crown' : 
                                                         ($user['role'] === 'mentor' ? 'chalkboard-teacher' : 'user-graduate'); ?> mr-1"></i>
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($user['account_locked']): ?>
                                        <div class="flex items-center">
                                            <span class="badge badge-locked pulse tooltip" data-tooltip="<?php echo htmlspecialchars($user['locked_reason'] ?? 'No reason provided'); ?>">
                                                <i class="fas fa-lock mr-1"></i> Locked
                                            </span>
                                            <?php if ($user['locked_reason']): ?>
                                                <button onclick="toggleReason(<?php echo $user['id']; ?>)" 
                                                        class="ml-2 text-red-500 hover:text-red-700 transition-colors">
                                                    <i class="fas fa-info-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <div id="reason-<?php echo $user['id']; ?>" class="lock-reason">
                                            <div class="flex items-start">
                                                <i class="fas fa-exclamation-circle text-red-500 mr-2 mt-0.5"></i>
                                                <div>
                                                    <strong class="text-sm text-gray-700">Reason:</strong>
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($user['locked_reason']); ?></p>
                                                    <small class="text-gray-400 text-xs">
                                                        <i class="fas fa-user-shield mr-1"></i>Locked by: <?php echo $user['locked_by'] ? 'Admin' : 'System'; ?> | 
                                                        <i class="fas fa-clock mr-1"></i>Date: <?php echo $user['locked_at'] ? date('M d, Y H:i', strtotime($user['locked_at'])) : 'N/A'; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-active">
                                            <i class="fas fa-check-circle mr-1"></i> Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <span class="text-sm font-medium <?php echo $user['failed_login_attempts'] > 0 ? 'text-yellow-600' : 'text-gray-500'; ?> mr-3">
                                            <?php echo $user['failed_login_attempts']; ?>
                                        </span>
                                        <?php if ($user['failed_login_attempts'] > 0): ?>
                                            <div class="w-24">
                                                <div class="progress-bar">
                                                    <div class="progress-fill bg-gradient-to-r from-yellow-400 to-yellow-500" 
                                                         style="width: <?php echo min($user['failed_login_attempts'] * 20, 100); ?>%"></div>
                                                </div>
                                                <?php if ($user['failed_login_attempts'] >= 3): ?>
                                                    <p class="text-xs text-yellow-500 mt-1 blink">
                                                        <i class="fas fa-exclamation-triangle mr-1"></i>High Risk
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-500">
                                        <?php if ($user['last_login']): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-sign-in-alt mr-2 text-green-500"></i>
                                                <?php echo date('M d, Y', strtotime($user['last_login'])); ?>
                                            </div>
                                            <div class="text-xs text-gray-400">
                                                <?php echo date('H:i', strtotime($user['last_login'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 italic">
                                                <i class="fas fa-ban mr-1"></i>Never
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <?php if ($user['account_locked']): ?>
                                            <button onclick="showUnlockModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['name'])); ?>')"
                                                    class="text-green-600 hover:text-green-900 action-btn px-3 py-1 rounded-lg border border-green-200 hover:bg-green-50 transition-all shadow-sm">
                                                <i class="fas fa-unlock mr-1"></i> Unlock
                                            </button>
                                        <?php else: ?>
                                            <button onclick="showLockModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['name'])); ?>')"
                                                    class="text-red-600 hover:text-red-900 action-btn px-3 py-1 rounded-lg border border-red-200 hover:bg-red-50 transition-all shadow-sm">
                                                <i class="fas fa-lock mr-1"></i> Lock
                                            </button>
                                        <?php endif; ?>
                                        <button onclick="showHistoryModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['name'])); ?>')"
                                                class="text-blue-600 hover:text-blue-900 action-btn px-3 py-1 rounded-lg border border-blue-200 hover:bg-blue-50 transition-all shadow-sm">
                                            <i class="fas fa-history mr-1"></i> History
                                        </button>
                                        <button onclick="showQuickActionMenu(<?php echo $user['id']; ?>, this)" 
                                                class="text-gray-600 hover:text-gray-900 action-btn px-2 py-1 rounded-lg border border-gray-200 hover:bg-gray-50 transition-all">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="px-6 pb-6">
            <div class="glass-card p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-history text-blue-500 mr-2"></i>
                        Recent Lock Activities
                    </h2>
                    <button onclick="refreshRecentActivities()" 
                            class="text-blue-500 hover:text-blue-700 transition-colors">
                        <i class="fas fa-redo"></i>
                    </button>
                </div>
                <div class="timeline">
                    <?php foreach ($recent_locks as $activity): ?>
                    <div class="timeline-item <?php echo $activity['action']; ?>">
                        <div class="bg-gradient-to-r from-gray-50 to-white rounded-lg p-4 border border-gray-200 shadow-sm">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="flex items-center">
                                        <i class="fas fa-user-circle text-gray-400 mr-2"></i>
                                        <span class="font-medium text-gray-900"><?php echo htmlspecialchars($activity['user_name']); ?></span>
                                        <span class="mx-2 text-gray-400">•</span>
                                        <span class="font-bold <?php echo $activity['action'] === 'locked' ? 'text-red-600' : 'text-green-600'; ?>">
                                            <?php echo $activity['action']; ?>
                                        </span>
                                    </div>
                                    <?php if ($activity['reason']): ?>
                                        <p class="text-sm text-gray-600 mt-2 ml-6 pl-2 border-l-2 border-gray-300">
                                            <i class="fas fa-comment-alt mr-1 text-gray-400"></i>
                                            <?php echo htmlspecialchars($activity['reason']); ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($activity['duration_days']): ?>
                                        <p class="text-xs text-yellow-600 mt-2 ml-6 pl-2 border-l-2 border-yellow-300">
                                            <i class="fas fa-clock mr-1"></i>
                                            Temporary lock (<?php echo $activity['duration_days']; ?> days)
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right">
                                    <div class="flex items-center justify-end">
                                        <i class="fas fa-user-shield text-blue-500 mr-2"></i>
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($activity['admin_name']); ?></p>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">
                                        <i class="far fa-clock mr-1"></i>
                                        <?php echo date('M d, Y H:i', strtotime($activity['performed_at'])); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Lock Modal -->
    <div id="lockModal" class="modal-overlay">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center">
                    <i class="fas fa-lock text-red-500 mr-2"></i>
                    Lock User Account
                </h3>
                <button onclick="hideLockModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="lockForm">
                <input type="hidden" name="user_id" id="lock_user_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Lock Type</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center p-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-red-300 hover:bg-red-50 transition-all <?php echo ($_POST['lock_type'] ?? 'permanent') === 'permanent' ? 'border-red-300 bg-red-50' : ''; ?>">
                            <input type="radio" name="lock_type" value="permanent" checked 
                                   class="h-4 w-4 text-red-600 focus:ring-red-500">
                            <div class="ml-3">
                                <span class="block text-sm font-medium text-gray-700">Permanent</span>
                                <span class="block text-xs text-gray-500">Until manually unlocked</span>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-yellow-300 hover:bg-yellow-50 transition-all <?php echo ($_POST['lock_type'] ?? '') === 'temporary' ? 'border-yellow-300 bg-yellow-50' : ''; ?>">
                            <input type="radio" name="lock_type" value="temporary"
                                   class="h-4 w-4 text-yellow-600 focus:ring-yellow-500">
                            <div class="ml-3">
                                <span class="block text-sm font-medium text-gray-700">Temporary</span>
                                <span class="block text-xs text-gray-500">Auto-unlock after period</span>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div id="durationSection" class="mb-4 hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Duration (Days)</label>
                    <input type="number" name="duration_days" min="1" max="365" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition-all" 
                           placeholder="Enter number of days">
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-comment-alt mr-1 text-gray-400"></i>Reason for Locking
                    </label>
                    <select name="reason" id="reasonSelect" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md mb-2 focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all" 
                            onchange="toggleCustomReason()">
                        <option value="">-- Select a reason --</option>
                        <option value="Too many failed login attempts">Too many failed login attempts</option>
                        <option value="Account compromised">Account compromised</option>
                        <option value="Violation of terms">Violation of terms</option>
                        <option value="Inactive for too long">Inactive for too long</option>
                        <option value="Payment issues">Payment issues</option>
                        <option value="Suspicious activity">Suspicious activity</option>
                        <option value="custom">Other (Specify below)</option>
                    </select>
                    <textarea name="custom_reason" id="customReason" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-md hidden focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all" 
                              placeholder="Enter custom reason..." rows="3"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="hideLockModal()" 
                            class="px-4 py-2 text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-all shadow-sm">
                        Cancel
                    </button>
                    <button type="submit" name="lock_user" 
                            class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-md hover:from-red-700 hover:to-red-800 transition-all action-btn shadow-md">
                        <i class="fas fa-lock mr-2"></i>Lock Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Unlock Modal -->
    <div id="unlockModal" class="modal-overlay">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center">
                    <i class="fas fa-unlock text-green-500 mr-2"></i>
                    Unlock User Account
                </h3>
                <button onclick="hideUnlockModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="unlockForm">
                <input type="hidden" name="user_id" id="unlock_user_id">
                
                <div class="mb-4 p-4 bg-gradient-to-r from-yellow-50 to-yellow-100 rounded-lg border border-yellow-200">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">Warning</h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <p>Are you sure you want to unlock this account?</p>
                                <p class="mt-1 text-xs">This will reset failed login attempts and allow the user to log in immediately.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-comment-alt mr-1 text-gray-400"></i>Unlock Reason
                    </label>
                    <textarea name="reason" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all" 
                              placeholder="Enter reason for unlocking..." rows="3" required></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="hideUnlockModal()" 
                            class="px-4 py-2 text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-all shadow-sm">
                        Cancel
                    </button>
                    <button type="submit" name="unlock_user" 
                            class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-md hover:from-green-700 hover:to-green-800 transition-all action-btn shadow-md">
                        <i class="fas fa-unlock mr-2"></i>Unlock Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 600px;">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center">
                    <i class="fas fa-history text-blue-500 mr-2"></i>
                    Lock History
                </h3>
                <button onclick="hideHistoryModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="historyContent" class="max-h-96 overflow-y-auto pr-2">
                <!-- History will be loaded here via AJAX -->
            </div>
        </div>
    </div>

    <!-- Bulk Action Modal -->
    <div id="bulkModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 500px;">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center">
                    <i class="fas fa-bolt text-purple-500 mr-2"></i>
                    Bulk Actions
                </h3>
                <button onclick="hideBulkActionModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="bulkActionForm">
                <div class="mb-4 p-4 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg border border-blue-200">
                    <div class="flex items-center">
                        <i class="fas fa-users text-blue-500 text-xl mr-3"></i>
                        <div>
                            <p class="text-sm text-blue-800">Selected users: <span id="selectedCountModal" class="font-bold">0</span></p>
                            <p class="text-xs text-blue-600 mt-1">Choose an action to apply to all selected users</p>
                        </div>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Action</label>
                    <select id="bulkAction" name="bulk_action" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md mb-3 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all" 
                            onchange="toggleBulkOptions()">
                        <option value="">-- Select Action --</option>
                        <option value="lock">Lock Selected Users</option>
                        <option value="unlock">Unlock Selected Users</option>
                        <option value="reset_attempts">Reset Failed Attempts</option>
                    </select>
                </div>
                
                <!-- Bulk Lock Options -->
                <div id="bulkLockOptions" class="hidden mb-4">
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Lock Type</label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center p-2 border border-gray-300 rounded cursor-pointer hover:bg-red-50">
                                <input type="radio" name="bulk_lock_type" value="permanent" checked 
                                       class="h-4 w-4 text-red-600 focus:ring-red-500">
                                <div class="ml-2">
                                    <span class="block text-xs font-medium text-gray-700">Permanent</span>
                                </div>
                            </label>
                            <label class="flex items-center p-2 border border-gray-300 rounded cursor-pointer hover:bg-yellow-50">
                                <input type="radio" name="bulk_lock_type" value="temporary"
                                       class="h-4 w-4 text-yellow-600 focus:ring-yellow-500">
                                <div class="ml-2">
                                    <span class="block text-xs font-medium text-gray-700">Temporary</span>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <div id="bulkDurationSection" class="mb-3 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Duration (Days)</label>
                        <input type="number" name="bulk_duration_days" min="1" max="365" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md" 
                               placeholder="Enter number of days">
                    </div>
                </div>
                
                <!-- Bulk Reason -->
                <div id="bulkReasonSection" class="hidden mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Reason for Action
                    </label>
                    <textarea id="bulkReason" name="bulk_reason" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all" 
                              placeholder="Enter reason for this action..." rows="3"></textarea>
                </div>
                
                <!-- Selected Users List -->
                <div id="selectedUsersList" class="mb-4 max-h-40 overflow-y-auto hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Selected Users</label>
                    <div class="space-y-2" id="selectedUsersContent">
                        <!-- Users will be listed here -->
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="hideBulkActionModal()" 
                            class="px-4 py-2 text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-all shadow-sm">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-gradient-to-r from-purple-600 to-purple-700 text-white rounded-md hover:from-purple-700 hover:to-purple-800 transition-all action-btn shadow-md">
                        <i class="fas fa-bolt mr-2"></i>Execute Bulk Action
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Action Menu -->
    <div id="quickActionMenu" class="fixed z-50 bg-white rounded-lg shadow-lg border border-gray-200 hidden" style="min-width: 200px;">
        <div class="py-1">
            <a href="#" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" onclick="resetSingleAttempts()">
                <i class="fas fa-redo mr-2 text-blue-500"></i>Reset Attempts
            </a>
            <a href="#" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" onclick="sendWarningEmail()">
                <i class="fas fa-envelope mr-2 text-yellow-500"></i>Send Warning
            </a>
            <a href="#" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" onclick="viewUserDetails()">
                <i class="fas fa-eye mr-2 text-green-500"></i>View Details
            </a>
            <div class="border-t border-gray-200">
                <a href="#" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50" onclick="forceLogout()">
                    <i class="fas fa-sign-out-alt mr-2"></i>Force Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <div class="floating-action">
        <button onclick="showBulkActionModal()" 
                class="bg-gradient-to-r from-purple-600 to-pink-600 text-white p-4 rounded-full shadow-2xl hover:from-purple-700 hover:to-pink-700 transition-all action-btn">
            <i class="fas fa-bolt text-xl"></i>
        </button>
    </div>

    <?php include '../footer.php'; ?>
    
    <script>
        // Global variables
        let currentUserId = null;
        let quickActionMenu = document.getElementById('quickActionMenu');
        let currentQuickActionButton = null;

        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stat cards sequentially
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Animate user cards
            const userCards = document.querySelectorAll('.user-card');
            userCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateX(0)';
                }, index * 50);
            });
            
            // Initialize checkboxes
            initCheckboxes();
            
            // Update selected count
            updateSelectedCount();
            
            // Initialize tooltips
            initTooltips();
            
            // Close modals with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    hideAllModals();
                    hideQuickActionMenu();
                }
            });
            
            // Close modals when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.modal-content') && !e.target.closest('[onclick^="show"]')) {
                    hideAllModals();
                }
                if (!e.target.closest('#quickActionMenu') && !e.target.closest('[onclick^="showQuickActionMenu"]')) {
                    hideQuickActionMenu();
                }
            });
        });
        
        // Initialize checkboxes
        function initCheckboxes() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.user-checkbox');
            
            selectAll.addEventListener('change', function() {
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                    updateCheckboxStyle(checkbox);
                });
                updateSelectedCount();
                updateSelectedUsersList();
            });
            
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateCheckboxStyle(this);
                    updateSelectedCount();
                    updateSelectedUsersList();
                    updateSelectAllCheckbox();
                });
                
                // Initialize styles
                updateCheckboxStyle(checkbox);
            });
        }
        
        // Update checkbox visual style
        function updateCheckboxStyle(checkbox) {
            const container = checkbox.closest('.checkbox-container');
            const checkmark = container.querySelector('.checkmark');
            
            if (checkbox.checked) {
                container.classList.add('checked');
            } else {
                container.classList.remove('checked');
            }
        }
        
        // Update select all checkbox state
        function updateSelectAllCheckbox() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            const selectAll = document.getElementById('selectAll');
            const allChecked = checkboxes.length > 0 && Array.from(checkboxes).every(cb => cb.checked);
            const anyChecked = Array.from(checkboxes).some(cb => cb.checked);
            
            selectAll.checked = allChecked;
            selectAll.indeterminate = anyChecked && !allChecked;
            updateCheckboxStyle(selectAll);
        }
        
        // Update selected count
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.user-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = selected;
            document.getElementById('selectedCountModal').textContent = selected;
            
            // Show/hide bulk action button in header
            const bulkActionBtn = document.querySelector('[onclick="showBulkActionModal()"]');
            if (selected > 0) {
                bulkActionBtn.classList.add('animate-pulse');
            } else {
                bulkActionBtn.classList.remove('animate-pulse');
            }
        }
        
        // Update selected users list for modal
        function updateSelectedUsersList() {
            const selectedUsers = Array.from(document.querySelectorAll('.user-checkbox:checked'));
            const selectedUsersContent = document.getElementById('selectedUsersContent');
            const selectedUsersList = document.getElementById('selectedUsersList');
            
            if (selectedUsers.length > 0) {
                selectedUsersList.classList.remove('hidden');
                selectedUsersContent.innerHTML = '';
                
                selectedUsers.forEach(checkbox => {
                    const row = checkbox.closest('tr');
                    const userId = row.dataset.userId;
                    const userName = row.querySelector('.text-sm.font-medium').textContent;
                    const userEmail = row.querySelector('.text-sm.text-gray-500').textContent;
                    
                    const userDiv = document.createElement('div');
                    userDiv.className = 'flex items-center justify-between p-2 bg-gray-50 rounded';
                    userDiv.innerHTML = `
                        <div class="flex items-center">
                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center mr-2">
                                <span class="text-xs text-blue-600 font-bold">${userName.charAt(0)}</span>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-700">${userName}</p>
                                <p class="text-xs text-gray-500">${userEmail}</p>
                            </div>
                        </div>
                        <span class="text-xs text-gray-400">ID: ${userId}</span>
                    `;
                    selectedUsersContent.appendChild(userDiv);
                });
            } else {
                selectedUsersList.classList.add('hidden');
            }
        }
        
        // Initialize tooltips
        function initTooltips() {
            const tooltips = document.querySelectorAll('.tooltip');
            tooltips.forEach(tooltip => {
                const tooltipText = tooltip.getAttribute('data-tooltip');
                if (tooltipText) {
                    const tooltipElement = document.createElement('span');
                    tooltipElement.className = 'tooltip-text';
                    tooltipElement.textContent = tooltipText;
                    tooltip.appendChild(tooltipElement);
                }
            });
        }
        
        // Modal functions
        function showLockModal(userId, userName) {
            document.getElementById('lock_user_id').value = userId;
            document.querySelector('#lockModal .modal-content h3').innerHTML = 
                `<i class="fas fa-lock text-red-500 mr-2"></i>Lock User: ${userName}`;
            document.getElementById('lockModal').classList.add('show');
        }
        
        function hideLockModal() {
            document.getElementById('lockModal').classList.remove('show');
        }
        
        function showUnlockModal(userId, userName) {
            document.getElementById('unlock_user_id').value = userId;
            document.querySelector('#unlockModal .modal-content h3').innerHTML = 
                `<i class="fas fa-unlock text-green-500 mr-2"></i>Unlock User: ${userName}`;
            document.getElementById('unlockModal').classList.add('show');
        }
        
        function hideUnlockModal() {
            document.getElementById('unlockModal').classList.remove('show');
        }
        
        function showHistoryModal(userId, userName) {
            fetch(`get_lock_history.php?user_id=${userId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('historyContent').innerHTML = data;
                    document.querySelector('#historyModal .modal-content h3').innerHTML = 
                        `<i class="fas fa-history text-blue-500 mr-2"></i>Lock History: ${userName}`;
                    document.getElementById('historyModal').classList.add('show');
                });
        }
        
        function hideHistoryModal() {
            document.getElementById('historyModal').classList.remove('show');
        }
        
        function showBulkActionModal() {
            const selectedCount = document.querySelectorAll('.user-checkbox:checked').length;
            if (selectedCount === 0) {
                showNotification('Please select at least one user first.', 'warning');
                return;
            }
            
            updateSelectedUsersList();
            document.getElementById('bulkModal').classList.add('show');
        }
        
        function hideBulkActionModal() {
            document.getElementById('bulkModal').classList.remove('show');
        }
        
        function hideAllModals() {
            hideLockModal();
            hideUnlockModal();
            hideHistoryModal();
            hideBulkActionModal();
        }
        
        // Toggle lock reason visibility
        function toggleReason(userId) {
            const reasonDiv = document.getElementById(`reason-${userId}`);
            reasonDiv.classList.toggle('show');
        }
        
        // Handle lock type selection
        document.querySelectorAll('input[name="lock_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const durationSection = document.getElementById('durationSection');
                if (this.value === 'temporary') {
                    durationSection.classList.remove('hidden');
                } else {
                    durationSection.classList.add('hidden');
                }
            });
        });
        
        // Handle bulk lock type selection
        document.querySelectorAll('input[name="bulk_lock_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const durationSection = document.getElementById('bulkDurationSection');
                if (this.value === 'temporary') {
                    durationSection.classList.remove('hidden');
                } else {
                    durationSection.classList.add('hidden');
                }
            });
        });
        
        // Handle custom reason
        function toggleCustomReason() {
            const reasonSelect = document.getElementById('reasonSelect');
            const customReason = document.getElementById('customReason');
            if (reasonSelect.value === 'custom') {
                customReason.classList.remove('hidden');
                customReason.required = true;
            } else {
                customReason.classList.add('hidden');
                customReason.required = false;
            }
        }
        
        // Toggle bulk options based on action
        function toggleBulkOptions() {
            const bulkAction = document.getElementById('bulkAction').value;
            const bulkLockOptions = document.getElementById('bulkLockOptions');
            const bulkReasonSection = document.getElementById('bulkReasonSection');
            
            if (bulkAction === 'lock') {
                bulkLockOptions.classList.remove('hidden');
                bulkReasonSection.classList.remove('hidden');
                document.getElementById('bulkReason').required = true;
            } else if (bulkAction === 'unlock') {
                bulkLockOptions.classList.add('hidden');
                bulkReasonSection.classList.remove('hidden');
                document.getElementById('bulkReason').required = true;
            } else if (bulkAction === 'reset_attempts') {
                bulkLockOptions.classList.add('hidden');
                bulkReasonSection.classList.remove('hidden');
                document.getElementById('bulkReason').required = false;
            } else {
                bulkLockOptions.classList.add('hidden');
                bulkReasonSection.classList.add('hidden');
            }
        }
        
        // Before submitting lock form, combine reason and custom reason
        document.getElementById('lockForm').addEventListener('submit', function(e) {
            const reasonSelect = document.getElementById('reasonSelect');
            const customReason = document.getElementById('customReason');
            
            if (reasonSelect.value === 'custom' && customReason.value.trim()) {
                // Create a hidden input with the custom reason
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'reason';
                hiddenInput.value = customReason.value.trim();
                this.appendChild(hiddenInput);
            }
        });
        
        // Selection functions
        function selectByRole(role) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                if (row.dataset.role === role) {
                    checkbox.checked = true;
                    updateCheckboxStyle(checkbox);
                }
            });
            updateSelectedCount();
            updateSelectedUsersList();
            updateSelectAllCheckbox();
        }
        
        function selectByStatus(status) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                if (row.dataset.status === status) {
                    checkbox.checked = true;
                    updateCheckboxStyle(checkbox);
                }
            });
            updateSelectedCount();
            updateSelectedUsersList();
            updateSelectAllCheckbox();
        }
        
        function deselectAll() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
                updateCheckboxStyle(checkbox);
            });
            updateSelectedCount();
            updateSelectedUsersList();
            updateSelectAllCheckbox();
        }
        
        // Filter functions
        function filterByStatus(status) {
            window.location.href = `?status=${status}`;
        }
        
        function resetFilters() {
            window.location.href = '?';
        }
        
        // Export to CSV
        function exportToCSV() {
            const url = new URL(window.location.href);
            url.searchParams.set('export', 'csv');
            window.open(url.toString(), '_blank');
        }
        
        // Toggle advanced filters
        function toggleAdvancedFilters() {
            const advancedFilters = document.getElementById('advancedFilters');
            const toggleIcon = document.querySelector('[onclick="toggleAdvancedFilters()"] i');
            
            advancedFilters.classList.toggle('show');
            toggleIcon.classList.toggle('rotate');
        }
        
        // Refresh recent activities
        function refreshRecentActivities() {
            location.reload();
        }
        
        // Quick Action Menu
        function showQuickActionMenu(userId, button) {
            currentUserId = userId;
            currentQuickActionButton = button;
            
            // Position the menu
            const rect = button.getBoundingClientRect();
            quickActionMenu.style.left = `${rect.left + window.scrollX - 180}px`;
            quickActionMenu.style.top = `${rect.bottom + window.scrollY + 5}px`;
            
            quickActionMenu.classList.remove('hidden');
            
            // Add animation
            quickActionMenu.style.opacity = '0';
            quickActionMenu.style.transform = 'scale(0.95) translateY(-10px)';
            
            setTimeout(() => {
                quickActionMenu.style.transition = 'all 0.2s ease';
                quickActionMenu.style.opacity = '1';
                quickActionMenu.style.transform = 'scale(1) translateY(0)';
            }, 10);
        }
        
        function hideQuickActionMenu() {
            quickActionMenu.classList.add('hidden');
            currentUserId = null;
            currentQuickActionButton = null;
        }
        
        // Quick Action Functions
        function resetSingleAttempts() {
            if (!currentUserId) return;
            
            fetch(`reset_attempts.php?user_id=${currentUserId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Failed attempts reset successfully!', 'success');
                    // Reload the page after a delay
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(data.error || 'Failed to reset attempts', 'error');
                }
            })
            .catch(error => {
                showNotification('An error occurred: ' + error, 'error');
            });
            
            hideQuickActionMenu();
        }
        
        function sendWarningEmail() {
            if (!currentUserId) return;
            showNotification('Warning email feature would be sent here', 'info');
            hideQuickActionMenu();
        }
        
        function viewUserDetails() {
            if (!currentUserId) return;
            window.open(`user_details.php?id=${currentUserId}`, '_blank');
            hideQuickActionMenu();
        }
        
        function forceLogout() {
            if (!currentUserId) return;
            
            if (confirm('Force logout this user from all devices?')) {
                fetch(`force_logout.php?user_id=${currentUserId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('User logged out from all devices', 'success');
                    } else {
                        showNotification(data.error || 'Failed to force logout', 'error');
                    }
                });
            }
            hideQuickActionMenu();
        }
        
        // Show notifications
        <?php if (!empty($success_message)): ?>
            showNotification('<?php echo addslashes($success_message); ?>', 'success');
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            showNotification('<?php echo addslashes($error_message); ?>', 'error');
        <?php endif; ?>
        
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 
                                      type === 'error' ? 'exclamation-triangle' : 
                                      type === 'warning' ? 'exclamation-circle' : 'info-circle'} mr-2"></i>
                    <div class="flex-1">${message}</div>
                    <span class="notification-close ml-4 cursor-pointer" onclick="this.parentElement.parentElement.remove()">&times;</span>
                </div>
            `;
            
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                color: white;
                z-index: 10000;
                transform: translateX(120%);
                transition: transform 0.3s ease;
                min-width: 300px;
                max-width: 400px;
                background: ${type === 'success' ? 'linear-gradient(135deg, #48bb78, #38a169)' : 
                           type === 'error' ? 'linear-gradient(135deg, #f56565, #e53e3e)' : 
                           type === 'warning' ? 'linear-gradient(135deg, #ed8936, #dd6b20)' : 'linear-gradient(135deg, #4299e1, #3182ce)'};
                box-shadow: 0 10px 25px rgba(0,0,0,0.1);
                backdrop-filter: blur(10px);
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 10);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(120%)';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }
    </script>
</body>
</html>