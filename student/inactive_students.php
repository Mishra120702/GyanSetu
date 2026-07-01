<?php
// inactive_students.php
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get all on hold students with additional statistics
$query = "SELECT s.*, u.name as processed_by_name, b.batch_name, b.batch_id,
                 s.on_hold_reason, s.on_hold_date
          FROM students s
          LEFT JOIN users u ON s.dropout_processed_by = u.id
          LEFT JOIN batches b ON s.batch_name = b.batch_id
          WHERE s.current_status = 'on hold'
          ORDER BY s.on_hold_date DESC, s.dropout_date DESC";
$on_hold_students = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Get statistics for dashboard
$stats_query = "SELECT 
                COUNT(*) as total_on_hold,
                COUNT(CASE WHEN on_hold_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as recent_on_hold,
                (SELECT COUNT(*) FROM students WHERE current_status = 'active') as total_active
                FROM students WHERE current_status = 'on hold'";
$stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

// Get on hold reasons statistics
$reasons_query = "SELECT 
                  on_hold_reason,
                  COUNT(*) as count
                  FROM students 
                  WHERE current_status = 'on hold' AND on_hold_reason IS NOT NULL
                  GROUP BY on_hold_reason
                  ORDER BY count DESC";
$reasons_stats = $db->query($reasons_query)->fetchAll(PDO::FETCH_ASSOC);

// Get monthly on hold trends for chart
$trend_query = "SELECT DATE_FORMAT(on_hold_date, '%b') as month, COUNT(*) as count
                FROM students 
                WHERE current_status = 'on hold' 
                AND on_hold_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY MONTH(on_hold_date)
                ORDER BY on_hold_date ASC";
$trend_data = $db->query($trend_query)->fetchAll(PDO::FETCH_ASSOC);

// Handle reactivation if requested
if (isset($_POST['reactivate'])) {
    $student_id = $_POST['student_id'];
    $reason = $_POST['reactivation_reason'];
    
    $stmt = $db->prepare("UPDATE students SET current_status = 'active', 
                         on_hold_date = NULL, on_hold_reason = NULL,
                         dropout_date = NULL, dropout_reason = NULL, 
                         dropout_processed_by = NULL, dropout_processed_at = NULL
                         WHERE student_id = ?");
    $stmt->execute([$student_id]);
    
    // Log the reactivation
    $log_stmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by, processed_at)
                             VALUES (?, 'reactivated', ?, ?, NOW())");
    $log_stmt->execute([$student_id, $reason, $_SESSION['user_id']]);
    
    header("Location: inactive_students.php?success=1&student=" . urlencode($student_id));
    exit;
}

// Handle adding/updating on hold reason
if (isset($_POST['update_reason'])) {
    $student_id = $_POST['student_id'];
    $on_hold_reason = $_POST['on_hold_reason'];
    
    // Check if "Other" was selected and custom reason provided
    if ($on_hold_reason === 'Other' && isset($_POST['custom_reason']) && !empty($_POST['custom_reason'])) {
        $on_hold_reason = $_POST['custom_reason'];
    }
    
    $stmt = $db->prepare("UPDATE students SET on_hold_reason = ? WHERE student_id = ?");
    $stmt->execute([$on_hold_reason, $student_id]);
    
    header("Location: inactive_students.php?updated=1&student=" . urlencode($student_id));
    exit;
}

// Calculate totals
$total_on_hold = count($on_hold_students);
$total_reasons = count($reasons_stats);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inactive Students Management | ASD Academy</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --deep-navy: #1B3C53;
            --navy: #234C6A;
            --steel-blue: #456882;
            --warm-sand: #D2C1B6;
            
            --bg-primary: #F8FAFC;
            --bg-secondary: #FFFFFF;
            --bg-card: #FFFFFF;
            --bg-input: #F8FAFC;
            --bg-hover: rgba(27, 60, 83, 0.05);
            --bg-table: #FFFFFF;
            --bg-table-alt: rgba(210, 193, 182, 0.1);
            
            --text-primary: #1B3C53;
            --text-secondary: #234C6A;
            --text-muted: #456882;
            --text-heading: #1B3C53;
            --text-subtitle: #456882;
            
            --border-color: rgba(210, 193, 182, 0.3);
            --border-focus: #456882;
            
            --shadow-sm: 0 1px 3px rgba(27, 60, 83, 0.08);
            --shadow-md: 0 4px 6px rgba(27, 60, 83, 0.1);
            --shadow-lg: 0 10px 25px rgba(27, 60, 83, 0.12);
            --shadow-xl: 0 20px 40px rgba(27, 60, 83, 0.15);
            
            --gradient-primary: linear-gradient(135deg, #1B3C53, #234C6A);
            --gradient-accent: linear-gradient(135deg, #234C6A, #456882);
            --gradient-light: linear-gradient(135deg, #D2C1B6, #E5D9CF);
            --gradient-warning: linear-gradient(135deg, #D2C1B6, #E5D9CF);
            
            --radius-sm: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.25rem;
            --radius-2xl: 1.5rem;
            --radius-full: 9999px;
            
            --transition-fast: 0.2s ease;
            --transition-normal: 0.3s ease;
            --transition-slow: 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            
            --sidebar-width: 16rem;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--steel-blue); border-radius: var(--radius-full); }

        .main-content {
            position: relative;
            z-index: 1;
            margin-left: var(--sidebar-width);
            padding: 2rem 2.5rem;
            min-height: 100vh;
            width: calc(100% - var(--sidebar-width));
        }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; width: 100%; }
        }
        @media (max-width: 640px) {
            .main-content { padding: 1rem; }
        }

        /* Stat Cards */
        .stat-card {
            background: var(--bg-card);
            border-radius: var(--radius-2xl);
            padding: 1.75rem;
            box-shadow: var(--shadow-lg);
            transition: all var(--transition-slow);
            border: 1px solid rgba(210, 193, 182, 0.3);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--steel-blue);
        }

        .stat-icon {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all var(--transition-slow);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-weight: 700;
            color: var(--text-muted);
        }

        .progress-bar {
            height: 6px;
            border-radius: var(--radius-full);
            background: rgba(210, 193, 182, 0.2);
            overflow: hidden;
            margin-top: 1rem;
        }

        .progress-bar .fill {
            height: 100%;
            border-radius: var(--radius-full);
            transition: width 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* Glass Card */
        .glass-card {
            background: var(--bg-card);
            border: 1px solid rgba(210, 193, 182, 0.3);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-lg);
            transition: all var(--transition-slow);
        }

        .glass-card:hover {
            box-shadow: var(--shadow-xl);
            border-color: var(--steel-blue);
        }

        /* Table */
        .table-wrapper {
            background: var(--bg-card);
            border: 1px solid rgba(210, 193, 182, 0.3);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .table-wrapper table {
            width: 100%;
            border-collapse: collapse;
        }

        .table-wrapper thead th {
            background: var(--bg-table-alt);
            color: var(--deep-navy);
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 1rem 1.25rem;
            border-bottom: 2px solid rgba(210, 193, 182, 0.3);
            text-align: left;
        }

        .table-wrapper tbody td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid rgba(210, 193, 182, 0.2);
            color: var(--text-primary);
            font-size: 0.875rem;
        }

        .table-wrapper tbody tr {
            transition: all var(--transition-fast);
        }

        .table-wrapper tbody tr:hover {
            background: rgba(210, 193, 182, 0.1);
        }

        .table-wrapper tbody tr:last-child td {
            border-bottom: none;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.85rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            transition: all var(--transition-fast);
            white-space: nowrap;
        }

        .badge-navy {
            background: rgba(27, 60, 83, 0.1);
            color: var(--deep-navy);
            border: 1px solid rgba(27, 60, 83, 0.2);
        }

        .badge-steel {
            background: rgba(69, 104, 130, 0.1);
            color: var(--steel-blue);
            border: 1px solid rgba(69, 104, 130, 0.2);
        }

        .badge-sand {
            background: rgba(210, 193, 182, 0.2);
            color: var(--navy);
            border: 1px solid rgba(210, 193, 182, 0.4);
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        /* Reason Tags */
        .reason-tag {
            display: inline-block;
            padding: 0.25rem 0.85rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            margin: 2px;
            transition: all var(--transition-fast);
            border: 1px solid transparent;
        }

        .reason-tag:hover {
            transform: scale(1.05);
        }

        .reason-medical {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border-color: rgba(239, 68, 68, 0.3);
        }

        .reason-financial {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            border-color: rgba(245, 158, 11, 0.3);
        }

        .reason-personal {
            background: rgba(27, 60, 83, 0.1);
            color: var(--deep-navy);
            border-color: rgba(27, 60, 83, 0.3);
        }

        .reason-academic {
            background: rgba(69, 104, 130, 0.1);
            color: var(--steel-blue);
            border-color: rgba(69, 104, 130, 0.3);
        }

        .reason-other {
            background: rgba(210, 193, 182, 0.2);
            color: var(--navy);
            border-color: rgba(210, 193, 182, 0.4);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-lg);
            font-weight: 600;
            font-size: 0.875rem;
            transition: all var(--transition-slow);
            cursor: pointer;
            border: none;
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(27, 60, 83, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        .btn-outline {
            background: white;
            border: 1px solid rgba(210, 193, 182, 0.3);
            color: var(--deep-navy);
            box-shadow: var(--shadow-sm);
        }

        .btn-outline:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--steel-blue);
        }

        .btn-sm {
            padding: 0.4rem 0.85rem;
            font-size: 0.75rem;
            border-radius: var(--radius-md);
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 99999;
            background: white;
            border: 1px solid rgba(210, 193, 182, 0.3);
            border-radius: var(--radius-lg);
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transform: translateX(120%);
            transition: transform var(--transition-slow);
            max-width: 420px;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast-title {
            color: var(--deep-navy);
            font-weight: 700;
            font-size: 0.95rem;
        }

        .toast-message {
            color: var(--steel-blue);
            font-size: 0.8rem;
            margin-top: 0.125rem;
        }

        /* Input Fields */
        .input-field {
            width: 100%;
            padding: 0.625rem 1rem;
            border-radius: var(--radius-lg);
            border: 2px solid rgba(210, 193, 182, 0.3);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 0.875rem;
            font-family: inherit;
            transition: all var(--transition-fast);
        }

        .input-field:focus {
            outline: none;
            border-color: var(--steel-blue);
            box-shadow: 0 0 0 4px rgba(69, 104, 130, 0.1);
        }

        .input-field::placeholder {
            color: rgba(69, 104, 130, 0.5);
        }

        /* Section Heading */
        .section-heading {
            color: var(--deep-navy);
            font-weight: 700;
            font-size: 1.1rem;
            position: relative;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }

        .section-heading::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 3px;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
        }

        .chart-container canvas {
            width: 100% !important;
            height: 100% !important;
        }

        /* Reason Item */
        .reason-item {
            background: rgba(210, 193, 182, 0.1);
            border: 1px solid rgba(210, 193, 182, 0.3);
            border-radius: var(--radius-lg);
            padding: 1rem;
            transition: all var(--transition-fast);
            cursor: pointer;
        }

        .reason-item:hover {
            background: rgba(210, 193, 182, 0.2);
            transform: translateX(4px);
        }

        .reason-item .reason-name {
            color: var(--deep-navy);
            font-weight: 600;
            font-size: 0.875rem;
        }

        .reason-bar-bg {
            background: rgba(210, 193, 182, 0.2);
            border-radius: var(--radius-full);
            height: 6px;
            overflow: hidden;
        }

        .reason-bar-fill {
            height: 100%;
            border-radius: var(--radius-full);
            transition: width 1.5s ease;
        }

        /* Toggle Form */
        .toggle-form {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, opacity 0.3s ease, margin 0.3s ease;
            opacity: 0;
        }

        .toggle-form.show {
            max-height: 400px;
            opacity: 1;
            margin-top: 0.75rem;
        }

        /* Page Heading */
        .page-heading {
            color: var(--deep-navy);
        }

        .page-subtitle {
            color: var(--steel-blue);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }

        .empty-state .empty-icon {
            font-size: 5rem;
            color: var(--warm-sand);
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .empty-state .empty-title {
            color: var(--deep-navy);
            font-size: 1.125rem;
            font-weight: 700;
        }

        .empty-state .empty-description {
            color: var(--steel-blue);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        /* Update link */
        .update-link {
            color: var(--steel-blue);
            cursor: pointer;
        }

        .update-link:hover {
            color: var(--deep-navy);
            text-decoration: underline;
        }

        /* DataTables Customization */
        .dataTables_wrapper .dataTables_filter input {
            background: var(--bg-input) !important;
            color: var(--text-primary) !important;
            border: 1px solid rgba(210, 193, 182, 0.3) !important;
            border-radius: var(--radius-lg) !important;
            padding: 0.5rem 1rem !important;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            outline: none !important;
            border-color: var(--steel-blue) !important;
            box-shadow: 0 0 0 3px rgba(69, 104, 130, 0.1) !important;
        }

        .dataTables_wrapper .dataTables_length select {
            background: var(--bg-input) !important;
            color: var(--text-primary) !important;
            border: 1px solid rgba(210, 193, 182, 0.3) !important;
            border-radius: var(--radius-md) !important;
        }

        .dataTables_wrapper .dataTables_info {
            color: var(--steel-blue) !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: var(--steel-blue) !important;
            border-radius: var(--radius-md) !important;
            background: var(--bg-card) !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--gradient-primary) !important;
            color: white !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--gradient-primary) !important;
            color: white !important;
        }

        /* Footer */
        .footer-text {
            color: var(--steel-blue);
        }
    </style>
</head>
<body>
    <!-- Toast Notifications -->
    <?php if (isset($_GET['success'])): ?>
    <div class="toast show" id="toast" role="alert">
        <div style="width: 2.5rem; height: 2.5rem; border-radius: 50%; background: rgba(34, 197, 94, 0.2); color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="flex-1">
            <p class="toast-title">Reactivation Successful</p>
            <p class="toast-message">Student has been reactivated and returned to active status.</p>
        </div>
        <button onclick="this.closest('.toast').classList.remove('show')" style="color: var(--steel-blue); cursor: pointer; background: none; border: none;">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php elseif (isset($_GET['updated'])): ?>
    <div class="toast show" id="toast" role="alert">
        <div style="width: 2.5rem; height: 2.5rem; border-radius: 50%; background: rgba(69, 104, 130, 0.2); color: var(--steel-blue); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
            <i class="fas fa-info-circle"></i>
        </div>
        <div class="flex-1">
            <p class="toast-title">Reason Updated</p>
            <p class="toast-message">Student's on hold reason has been updated successfully.</p>
        </div>
        <button onclick="this.closest('.toast').classList.remove('show')" style="color: var(--steel-blue); cursor: pointer; background: none; border: none;">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>
    
    <script>
        setTimeout(() => {
            const toast = document.getElementById('toast');
            if (toast) toast.classList.remove('show');
        }, 5000);
    </script>

    <!-- Sidebar & Header -->
    <?php include '../sidebar.php'; ?>
    <?php include '../header.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        
        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg flex-shrink-0" style="background: var(--gradient-primary);">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold tracking-tight page-heading">
                        Inactive Students
                    </h1>
                    <p class="text-sm page-subtitle mt-1">Track and manage students with on-hold status</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <span class="badge badge-sand px-4 py-2">
                    <i class="fas fa-database mr-2"></i>
                    <?= $total_on_hold ?> Records
                </span>
            </div>
        </div>

        <!-- Stats Dashboard -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <!-- Total On Hold -->
            <div class="stat-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="stat-label">Total On Hold</p>
                        <p class="stat-number" style="color: var(--deep-navy);"><?= $stats['total_on_hold'] ?></p>
                    </div>
                    <div class="stat-icon" style="background: rgba(27, 60, 83, 0.1); color: var(--deep-navy);">
                        <i class="fas fa-pause-circle"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="badge badge-navy text-xs">
                        <i class="fas fa-clock"></i>
                        <?= $stats['recent_on_hold'] ?> in last 30d
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="fill" style="width: <?= $stats['total_on_hold'] > 0 ? min(100, ($stats['recent_on_hold'] / $stats['total_on_hold']) * 100) : 0 ?>%; background: var(--gradient-primary);"></div>
                </div>
            </div>

            <!-- Active Students -->
            <div class="stat-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="stat-label">Active Students</p>
                        <p class="stat-number" style="color: #16a34a;"><?= $stats['total_active'] ?></p>
                    </div>
                    <div class="stat-icon" style="background: rgba(34, 197, 94, 0.1); color: #16a34a;">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="badge badge-success text-xs">
                        <i class="fas fa-users"></i> Currently enrolled
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="fill" style="width: 100%; background: linear-gradient(135deg, #22c55e, #16a34a);"></div>
                </div>
            </div>

            <!-- On Hold Rate -->
            <div class="stat-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="stat-label">On Hold Rate</p>
                        <p class="stat-number" style="color: #d97706;">
                            <?= $stats['total_active'] > 0 ? round(($stats['total_on_hold'] / ($stats['total_active'] + $stats['total_on_hold'])) * 100, 1) : 0 ?>%
                        </p>
                    </div>
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="badge badge-sand text-xs">
                        <i class="fas fa-percentage"></i> Historical rate
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="fill" style="width: <?= $stats['total_active'] > 0 ? min(100, round(($stats['total_on_hold'] / ($stats['total_active'] + $stats['total_on_hold'])) * 100)) : 0 ?>%; background: linear-gradient(135deg, #D2C1B6, #E5D9CF);"></div>
                </div>
            </div>

            <!-- Reasons Recorded -->
            <div class="stat-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="stat-label">Reasons Recorded</p>
                        <p class="stat-number" style="color: var(--steel-blue);"><?= $total_reasons ?></p>
                    </div>
                    <div class="stat-icon" style="background: rgba(69, 104, 130, 0.1); color: var(--steel-blue);">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="badge badge-steel text-xs">
                        <i class="fas fa-list-alt"></i> Different reasons
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="fill" style="width: 100%; background: var(--gradient-accent);"></div>
                </div>
            </div>
        </div>

        <!-- Charts & Reasons Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-8">
            <!-- Monthly Trend Chart -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h3 class="text-sm font-bold" style="color: var(--deep-navy);">Monthly On-Hold Trend</h3>
                        <p class="text-xs" style="color: var(--steel-blue); margin-top: 2px;">Last 6 months analysis</p>
                    </div>
                    <span class="badge badge-navy">
                        <i class="fas fa-chart-line"></i>
                        <?= array_sum(array_column($trend_data, 'count')) ?> Total
                    </span>
                </div>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <!-- Reasons Distribution -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h3 class="text-sm font-bold" style="color: var(--deep-navy);">Reasons Distribution</h3>
                        <p class="text-xs" style="color: var(--steel-blue); margin-top: 2px;">On-hold reasons breakdown</p>
                    </div>
                    <span class="badge badge-steel">
                        <i class="fas fa-chart-bar"></i>
                        <?= $total_reasons ?> Categories
                    </span>
                </div>
                
                <?php if (!empty($reasons_stats)): ?>
                <div class="space-y-3" style="max-height: 280px; overflow-y: auto;">
                    <?php foreach ($reasons_stats as $reason): 
                        $reason_lower = strtolower($reason['on_hold_reason']);
                        $icon = 'fa-question-circle';
                        $color = '#456882';
                        
                        if (strpos($reason_lower, 'medical') !== false || strpos($reason_lower, 'health') !== false) {
                            $icon = 'fa-heartbeat'; $color = '#dc2626';
                        } elseif (strpos($reason_lower, 'financial') !== false || strpos($reason_lower, 'money') !== false || strpos($reason_lower, 'fee') !== false) {
                            $icon = 'fa-money-bill-wave'; $color = '#d97706';
                        } elseif (strpos($reason_lower, 'personal') !== false || strpos($reason_lower, 'family') !== false) {
                            $icon = 'fa-user-friends'; $color = '#1B3C53';
                        } elseif (strpos($reason_lower, 'academic') !== false || strpos($reason_lower, 'study') !== false) {
                            $icon = 'fa-book'; $color = '#456882';
                        }
                        
                        $maxCount = $reasons_stats[0]['count'];
                        $barWidth = $maxCount > 0 ? ($reason['count'] / $maxCount) * 100 : 0;
                    ?>
                    <div class="reason-item flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-lg flex-shrink-0" style="background: <?= $color ?>15; color: <?= $color ?>;">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="reason-name truncate"><?= htmlspecialchars($reason['on_hold_reason']) ?></span>
                                <span class="text-sm font-bold ml-2 flex-shrink-0" style="color: <?= $color ?>;"><?= $reason['count'] ?></span>
                            </div>
                            <div class="reason-bar-bg">
                                <div class="reason-bar-fill" style="width: <?= $barWidth ?>%; background: <?= $color ?>;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-inbox text-3xl mb-2" style="color: var(--warm-sand); opacity: 0.5;"></i>
                    <p style="color: var(--steel-blue);">No reasons recorded yet</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Students Table -->
        <div class="table-wrapper">
            <div style="padding: 1.5rem; border-bottom: 1px solid rgba(210, 193, 182, 0.3); background: rgba(210, 193, 182, 0.05);">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold flex items-center gap-2" style="color: var(--deep-navy);">
                            <i class="fas fa-list-ul" style="color: var(--steel-blue);"></i>
                            Inactive Students List
                        </h3>
                        <p class="text-xs" style="color: var(--steel-blue); margin-top: 2px;">Review and manage students with on-hold status</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="exportToExcel()" class="btn btn-outline btn-sm">
                            <i class="fas fa-file-excel"></i> Export
                        </button>
                        <button onclick="window.location.reload()" class="btn btn-outline btn-sm">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>
            
            <div style="overflow-x: auto;">
                <table id="inactiveTable" class="display" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Student Info</th>
                            <th>Batch</th>
                            <th>On Hold Date</th>
                            <th>On Hold Reason</th>
                            <th>Dropout Info</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($total_on_hold > 0): ?>
                            <?php foreach ($on_hold_students as $student): 
                                $on_hold_reason = $student['on_hold_reason'] ?? '';
                                $reason_lower = strtolower($on_hold_reason);
                                $badge_class = 'reason-other';
                                
                                if (strpos($reason_lower, 'medical') !== false || strpos($reason_lower, 'health') !== false) {
                                    $badge_class = 'reason-medical';
                                } elseif (strpos($reason_lower, 'financial') !== false || strpos($reason_lower, 'money') !== false) {
                                    $badge_class = 'reason-financial';
                                } elseif (strpos($reason_lower, 'personal') !== false || strpos($reason_lower, 'family') !== false) {
                                    $badge_class = 'reason-personal';
                                } elseif (strpos($reason_lower, 'academic') !== false || strpos($reason_lower, 'study') !== false) {
                                    $badge_class = 'reason-academic';
                                }
                                
                                $daysOnHold = $student['on_hold_date'] ? round((time() - strtotime($student['on_hold_date'])) / (60 * 60 * 24)) : 0;
                            ?>
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div style="width: 2.5rem; height: 2.5rem; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 0.875rem; box-shadow: var(--shadow-md); flex-shrink: 0;">
                                            <?= strtoupper(substr($student['first_name'] ?? 'A', 0, 1) . substr($student['last_name'] ?? 'S', 0, 1)) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <p style="font-weight: 600; font-size: 0.875rem; color: var(--deep-navy);" class="truncate">
                                                <?= htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?>
                                            </p>
                                            <p style="font-size: 0.75rem; color: var(--steel-blue);" class="truncate">
                                                <?= htmlspecialchars($student['email'] ?? 'N/A') ?>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-steel">
                                        <?= htmlspecialchars($student['batch_name'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td>
                                    <div>
                                        <span style="font-weight: 500; font-size: 0.875rem; color: var(--deep-navy);">
                                            <?= $student['on_hold_date'] ? date('M j, Y', strtotime($student['on_hold_date'])) : 'N/A' ?>
                                        </span>
                                        <?php if ($daysOnHold > 0): ?>
                                        <br>
                                        <span class="badge <?= $daysOnHold > 60 ? 'badge-navy' : 'badge-sand' ?> text-xs">
                                            <?= $daysOnHold ?> days
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($on_hold_reason): ?>
                                        <span class="reason-tag <?= $badge_class ?>">
                                            <?= htmlspecialchars($on_hold_reason) ?>
                                        </span>
                                        <br>
                                    <?php endif; ?>
                                    <button class="text-xs font-medium mt-1 inline-flex items-center gap-1 cursor-pointer update-link"
                                            onclick="toggleReasonForm('<?= $student['student_id'] ?>')">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                </td>
                                <td>
                                    <?php if ($student['dropout_date']): ?>
                                        <span style="font-size: 0.875rem; color: var(--deep-navy);"><?= date('M j, Y', strtotime($student['dropout_date'])) ?></span>
                                        <?php if ($student['dropout_reason']): ?>
                                        <br>
                                        <span style="font-size: 0.75rem; color: var(--steel-blue);">
                                            <?= htmlspecialchars($student['dropout_reason']) ?>
                                        </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>
                                        <button class="btn btn-success btn-sm mb-2 w-full" 
                                                onclick="reactivateStudent('<?= $student['student_id'] ?>')">
                                            <i class="fas fa-user-plus"></i> Reactivate
                                        </button>
                                        
                                        <!-- Reason Update Form -->
                                        <div class="toggle-form" id="reason-form-<?= $student['student_id'] ?>" 
                                             style="background: var(--bg-card); border-radius: var(--radius-xl); border: 1px solid rgba(210, 193, 182, 0.3); padding: 1rem;">
                                            <form method="POST">
                                                <input type="hidden" name="student_id" value="<?= htmlspecialchars($student['student_id']) ?>">
                                                <div class="space-y-2">
                                                    <label style="color: var(--steel-blue); font-size: 0.75rem; font-weight: 600;">Update Reason</label>
                                                    <select name="on_hold_reason" class="input-field text-sm"
                                                            onchange="handleReasonChange(this, '<?= $student['student_id'] ?>')">
                                                        <option value="">Select reason...</option>
                                                        <option value="Medical/Health Issues" <?= $on_hold_reason == 'Medical/Health Issues' ? 'selected' : '' ?>>Medical/Health Issues</option>
                                                        <option value="Financial Difficulties" <?= $on_hold_reason == 'Financial Difficulties' ? 'selected' : '' ?>>Financial Difficulties</option>
                                                        <option value="Personal/Family Reasons" <?= $on_hold_reason == 'Personal/Family Reasons' ? 'selected' : '' ?>>Personal/Family Reasons</option>
                                                        <option value="Academic Pressure" <?= $on_hold_reason == 'Academic Pressure' ? 'selected' : '' ?>>Academic Pressure</option>
                                                        <option value="Not Responding" <?= $on_hold_reason == 'Not Responding' ? 'selected' : '' ?>>Not Responding</option>
                                                        <option value="Work Commitments" <?= $on_hold_reason == 'Work Commitments' ? 'selected' : '' ?>>Work Commitments</option>
                                                        <option value="Travel/Relocation" <?= $on_hold_reason == 'Travel/Relocation' ? 'selected' : '' ?>>Travel/Relocation</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                    <input type="text" name="custom_reason" 
                                                           id="custom-reason-<?= $student['student_id'] ?>"
                                                           class="input-field text-sm hidden" 
                                                           placeholder="Specify other reason...">
                                                    <div class="flex gap-2">
                                                        <button type="submit" name="update_reason" class="btn btn-primary btn-sm flex-1">
                                                            <i class="fas fa-save"></i> Save
                                                        </button>
                                                        <button type="button" class="btn btn-outline btn-sm"
                                                                onclick="toggleReasonForm('<?= $student['student_id'] ?>')">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <div class="empty-icon">
                                            <i class="fas fa-inbox"></i>
                                        </div>
                                        <h4 class="empty-title">No Inactive Students</h4>
                                        <p class="empty-description">There are currently no students with on-hold status.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-8 text-center text-xs footer-text">
            <p>&copy; <?= date('Y') ?> ASD Academy. All rights reserved.</p>
        </div>
        <?php include '../footer.php'; ?>
        
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <script>
        // ============================================================
        // REASON FORM TOGGLE
        // ============================================================
        function toggleReasonForm(studentId) {
            const form = document.getElementById('reason-form-' + studentId);
            if (form) {
                form.classList.toggle('show');
                if (form.classList.contains('show')) {
                    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }

        function handleReasonChange(selectElement, studentId) {
            const customInput = document.getElementById('custom-reason-' + studentId);
            if (selectElement.value === 'Other') {
                customInput.classList.remove('hidden');
                customInput.required = true;
                setTimeout(() => customInput.focus(), 100);
            } else {
                customInput.classList.add('hidden');
                customInput.required = false;
                customInput.value = '';
            }
        }

        // ============================================================
        // STUDENT REACTIVATION
        // ============================================================
        function reactivateStudent(studentId) {
            const reason = prompt('📋 Please enter the reason for reactivation:\n\nThis will be logged in the student\'s history.');
            
            if (reason === null) return;
            
            if (reason.trim() === '') {
                alert('⚠️ Please provide a valid reason for reactivation.');
                return;
            }
            
            if (confirm('✅ Are you sure you want to reactivate this student?\n\nReason: ' + reason.trim())) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                form.innerHTML = `
                    <input type="hidden" name="reactivate" value="1">
                    <input type="hidden" name="student_id" value="${studentId}">
                    <input type="hidden" name="reactivation_reason" value="${escapeHtml(reason.trim())}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ============================================================
        // EXPORT TO EXCEL
        // ============================================================
        function exportToExcel() {
            const table = document.getElementById('inactiveTable');
            const ws = XLSX.utils.table_to_sheet(table);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Inactive Students");
            
            const today = new Date();
            const dateStr = today.toISOString().split('T')[0];
            
            XLSX.writeFile(wb, `Inactive_Students_${dateStr}.xlsx`);
        }

        // ============================================================
        // DATATABLES INITIALIZATION
        // ============================================================
        const TOTAL_COLUMNS = 6;

        $(document).ready(function() {
            const table = document.getElementById('inactiveTable');
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            
            const rows = tbody.querySelectorAll('tr');
            let hasData = false;
            
            if (rows.length > 0) {
                const firstRowCells = rows[0].querySelectorAll('td');
                if (firstRowCells.length === TOTAL_COLUMNS) {
                    const firstCell = firstRowCells[0];
                    if (!firstCell.hasAttribute('colspan') || firstCell.getAttribute('colspan') != TOTAL_COLUMNS) {
                        hasData = true;
                    }
                }
            }
            
            if (hasData) {
                $('#inactiveTable').DataTable({
                    responsive: false,
                    order: [[2, 'desc']],
                    pageLength: 10,
                    lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "🔍 Search students...",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ students",
                        infoEmpty: "No students found",
                        infoFiltered: "(filtered from _MAX_ total)",
                        zeroRecords: "No matching students found"
                    },
                    columnDefs: [
                        { orderable: false, targets: [5] }
                    ]
                });
            }
        });

        // ============================================================
        // TREND CHART
        // ============================================================
        let trendChart = null;

        function initTrendChart() {
            const textColor = '#456882';
            const gridColor = 'rgba(210, 193, 182, 0.5)';
            const tooltipBg = '#FFFFFF';
            const tooltipTitle = '#1B3C53';
            const tooltipBody = '#456882';
            const tooltipBorder = 'rgba(210, 193, 182, 0.5)';
            
            if (trendChart) {
                trendChart.destroy();
                trendChart = null;
            }

            const trendCtx = document.getElementById('trendChart');
            if (!trendCtx) return;
            
            const ctx = trendCtx.getContext('2d');
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(27, 60, 83, 0.2)');
            gradient.addColorStop(1, 'rgba(27, 60, 83, 0.0)');
            
            const trendData = <?= json_encode($trend_data) ?>;
            const months = trendData.length ? trendData.map(d => d.month) : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
            const counts = trendData.length ? trendData.map(d => parseInt(d.count)) : [0, 0, 0, 0, 0, 0];
            
            trendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'On Hold',
                        data: counts,
                        borderColor: '#1B3C53',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        pointBackgroundColor: '#1B3C53',
                        pointBorderColor: '#FFFFFF',
                        pointBorderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index',
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: tooltipBg,
                            titleColor: tooltipTitle,
                            bodyColor: tooltipBody,
                            borderColor: tooltipBorder,
                            borderWidth: 1,
                            cornerRadius: 12,
                            padding: 12,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return 'On Hold: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: gridColor, drawBorder: false },
                            ticks: { 
                                color: textColor, 
                                font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" },
                                stepSize: 1,
                                padding: 8,
                            },
                            title: {
                                display: true,
                                text: 'Number of Students',
                                color: textColor,
                                font: { size: 10, family: "'Plus Jakarta Sans', sans-serif" }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { 
                                color: textColor, 
                                font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" },
                                padding: 8,
                            },
                            title: {
                                display: true,
                                text: 'Month',
                                color: textColor,
                                font: { size: 10, family: "'Plus Jakarta Sans', sans-serif" }
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', initTrendChart);

        // ============================================================
        // KEYBOARD SHORTCUTS
        // ============================================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const toast = document.getElementById('toast');
                if (toast) toast.classList.remove('show');
            }
        });

        // ============================================================
        // RESPONSIVE HANDLING
        // ============================================================
        function handleResponsiveLayout() {
            const mainContent = document.querySelector('.main-content');
            
            if (window.innerWidth < 1024) {
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                    mainContent.style.width = '100%';
                }
            } else {
                if (mainContent) {
                    mainContent.style.marginLeft = '16rem';
                    mainContent.style.width = 'calc(100% - 16rem)';
                }
            }
        }

        window.addEventListener('load', handleResponsiveLayout);
        window.addEventListener('resize', handleResponsiveLayout);
        handleResponsiveLayout();

        console.log('✅ Inactive Students page loaded with ASD Academy theme');
    </script>
</body>
</html>