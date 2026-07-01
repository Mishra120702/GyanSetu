<?php
// drop_list.php - Complete fixed file
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get all dropped students with additional statistics
$query = "SELECT s.*, u.name as processed_by_name, b.batch_name, b.batch_id,
                 s.dropout_reason, s.dropout_date, s.dropout_processed_at,
                 s.dropout_processed_by
          FROM students s
          LEFT JOIN users u ON s.dropout_processed_by = u.id
          LEFT JOIN batches b ON s.batch_name = b.batch_id
          WHERE s.current_status = 'dropped'
          ORDER BY s.dropout_date DESC, s.dropout_processed_at DESC";
$dropped_students = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Get statistics for dashboard
$stats_query = "SELECT 
                COUNT(*) as total_dropped,
                COUNT(CASE WHEN dropout_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as recent_dropped,
                (SELECT COUNT(*) FROM students WHERE current_status = 'active') as total_active,
                (SELECT COUNT(*) FROM students WHERE current_status = 'on hold') as total_on_hold
                FROM students WHERE current_status = 'dropped'";
$stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

// Get dropout reasons statistics
$reasons_query = "SELECT 
                  dropout_reason,
                  COUNT(*) as count
                  FROM students 
                  WHERE current_status = 'dropped' AND dropout_reason IS NOT NULL
                  GROUP BY dropout_reason
                  ORDER BY count DESC";
$reasons_stats = $db->query($reasons_query)->fetchAll(PDO::FETCH_ASSOC);

// Get monthly dropout trends for chart
$trend_query = "SELECT DATE_FORMAT(dropout_date, '%b') as month, COUNT(*) as count
                FROM students 
                WHERE current_status = 'dropped' 
                AND dropout_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY MONTH(dropout_date)
                ORDER BY dropout_date ASC";
$trend_data = $db->query($trend_query)->fetchAll(PDO::FETCH_ASSOC);

// Handle restoration if requested
if (isset($_POST['restore'])) {
    $student_id = $_POST['student_id'];
    $reason = $_POST['restoration_reason'];
    
    $stmt = $db->prepare("UPDATE students SET current_status = 'active', 
                         dropout_date = NULL, dropout_reason = NULL, 
                         dropout_processed_by = NULL, dropout_processed_at = NULL,
                         updated_at = NOW()
                         WHERE student_id = ?");
    $stmt->execute([$student_id]);
    
    // Log the restoration
    $log_stmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by, created_at)
                             VALUES (?, 'restored', ?, ?, NOW())");
    $log_stmt->execute([$student_id, $reason, $_SESSION['user_id']]);
    
    header("Location: drop_list.php?success=1&student=" . urlencode($student_id));
    exit;
}

// Handle adding/updating dropout reason
if (isset($_POST['update_reason'])) {
    $student_id = $_POST['student_id'];
    $dropout_reason = $_POST['dropout_reason'];
    
    // Check if "Other" was selected and custom reason provided
    if ($dropout_reason === 'Other' && isset($_POST['custom_reason']) && !empty($_POST['custom_reason'])) {
        $dropout_reason = $_POST['custom_reason'];
    }
    
    $stmt = $db->prepare("UPDATE students SET dropout_reason = ? WHERE student_id = ?");
    $stmt->execute([$dropout_reason, $student_id]);
    
    header("Location: drop_list.php?updated=1&student=" . urlencode($student_id));
    exit;
}

// Calculate totals
$total_dropped = count($dropped_students);
$total_reasons = count($reasons_stats);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dropped Students Management | ASD Academy</title>

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
            --gradient-danger: linear-gradient(135deg, #dc2626, #b91c1c);
            
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

        .badge-danger {
            background: rgba(220, 38, 38, 0.1);
            color: #dc2626;
            border: 1px solid rgba(220, 38, 38, 0.2);
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

        .reason-dropped {
            background: rgba(220, 38, 38, 0.1);
            color: #dc2626;
            border-color: rgba(220, 38, 38, 0.3);
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

        .btn-danger {
            background: var(--gradient-danger);
            color: white;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
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
            background: var(--gradient-danger);
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

        /* Dropout Icon Animation */
        .drop-icon {
            animation: pulse-drop 2s infinite;
        }

        @keyframes pulse-drop {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
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
            <p class="toast-title">Restoration Successful</p>
            <p class="toast-message">Student has been restored and returned to active status.</p>
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
            <p class="toast-message">Student's dropout reason has been updated successfully.</p>
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
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg flex-shrink-0" style="background: var(--gradient-danger);">
                    <i class="fas fa-user-slash drop-icon"></i>
                </div>
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold tracking-tight page-heading">
                        Dropped Students
                    </h1>
                    <p class="text-sm page-subtitle mt-1">Track and manage students who have dropped out</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <span class="badge badge-danger px-4 py-2">
                    <i class="fas fa-database mr-2"></i>
                    <?= $total_dropped ?> Records
                </span>
            </div>
        </div>

        <!-- Stats Dashboard -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <!-- Total Dropped -->
            <div class="stat-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="stat-label">Total Dropped</p>
                        <p class="stat-number" style="color: #dc2626;"><?= $stats['total_dropped'] ?></p>
                    </div>
                    <div class="stat-icon" style="background: rgba(220, 38, 38, 0.1); color: #dc2626;">
                        <i class="fas fa-user-slash"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="badge badge-danger text-xs">
                        <i class="fas fa-clock"></i>
                        <?= $stats['recent_dropped'] ?> in last 30d
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="fill" style="width: <?= $stats['total_dropped'] > 0 ? min(100, ($stats['recent_dropped'] / $stats['total_dropped']) * 100) : 0 ?>%; background: var(--gradient-danger);"></div>
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

            <!-- On Hold Students -->
            <div class="stat-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="stat-label">On Hold</p>
                        <p class="stat-number" style="color: #d97706;"><?= $stats['total_on_hold'] ?></p>
                    </div>
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
                        <i class="fas fa-pause-circle"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="badge badge-sand text-xs">
                        <i class="fas fa-hourglass-half"></i> Currently on hold
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="fill" style="width: <?= $stats['total_on_hold'] > 0 ? min(100, ($stats['total_on_hold'] / max(1, $stats['total_active'] + $stats['total_on_hold'] + $stats['total_dropped'])) * 100) : 0 ?>%; background: linear-gradient(135deg, #D2C1B6, #E5D9CF);"></div>
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
                        <h3 class="text-sm font-bold" style="color: var(--deep-navy);">Monthly Dropout Trend</h3>
                        <p class="text-xs" style="color: var(--steel-blue); margin-top: 2px;">Last 6 months analysis</p>
                    </div>
                    <span class="badge badge-danger">
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
                        <p class="text-xs" style="color: var(--steel-blue); margin-top: 2px;">Dropout reasons breakdown</p>
                    </div>
                    <span class="badge badge-steel">
                        <i class="fas fa-chart-bar"></i>
                        <?= $total_reasons ?> Categories
                    </span>
                </div>
                
                <?php if (!empty($reasons_stats)): ?>
                <div class="space-y-3" style="max-height: 280px; overflow-y: auto;">
                    <?php foreach ($reasons_stats as $reason): 
                        $reason_lower = strtolower($reason['dropout_reason']);
                        $icon = 'fa-question-circle';
                        $color = '#456882';
                        
                        if (strpos($reason_lower, 'medical') !== false || strpos($reason_lower, 'health') !== false) {
                            $icon = 'fa-heartbeat'; $color = '#dc2626';
                        } elseif (strpos($reason_lower, 'financial') !== false || strpos($reason_lower, 'money') !== false || strpos($reason_lower, 'fee') !== false) {
                            $icon = 'fa-money-bill-wave'; $color = '#d97706';
                        } elseif (strpos($reason_lower, 'personal') !== false || strpos($reason_lower, 'family') !== false) {
                            $icon = 'fa-user-friends'; $color = '#1B3C53';
                        } elseif (strpos($reason_lower, 'academic') !== false || strpos($reason_lower, 'study') !== false || strpos($reason_lower, 'performance') !== false) {
                            $icon = 'fa-book'; $color = '#456882';
                        } elseif (strpos($reason_lower, 'transfer') !== false || strpos($reason_lower, 'move') !== false || strpos($reason_lower, 'relocation') !== false) {
                            $icon = 'fa-exchange-alt'; $color = '#234C6A';
                        } elseif (strpos($reason_lower, 'work') !== false || strpos($reason_lower, 'job') !== false || strpos($reason_lower, 'employment') !== false) {
                            $icon = 'fa-briefcase'; $color = '#6B7280';
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
                                <span class="reason-name truncate"><?= htmlspecialchars($reason['dropout_reason']) ?></span>
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
                            <i class="fas fa-list-ul" style="color: #dc2626;"></i>
                            Dropped Students List
                        </h3>
                        <p class="text-xs" style="color: var(--steel-blue); margin-top: 2px;">Review and manage students who have dropped out</p>
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
                <table id="dropTable" class="display" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Student Info</th>
                            <th>Batch</th>
                            <th>Dropout Date</th>
                            <th>Dropout Reason</th>
                            <th>Processed By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($total_dropped > 0): ?>
                            <?php foreach ($dropped_students as $student): 
                                $dropout_reason = $student['dropout_reason'] ?? '';
                                $reason_lower = strtolower($dropout_reason);
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
                                
                                $daysDropped = $student['dropout_date'] ? round((time() - strtotime($student['dropout_date'])) / (60 * 60 * 24)) : 0;
                            ?>
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div style="width: 2.5rem; height: 2.5rem; border-radius: 50%; background: var(--gradient-danger); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 0.875rem; box-shadow: var(--shadow-md); flex-shrink: 0;">
                                            <?= strtoupper(substr($student['first_name'] ?? 'A', 0, 1) . substr($student['last_name'] ?? 'S', 0, 1)) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <p style="font-weight: 600; font-size: 0.875rem; color: var(--deep-navy);" class="truncate">
                                                <?= htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?>
                                            </p>
                                            <p style="font-size: 0.75rem; color: var(--steel-blue);" class="truncate">
                                                <?= htmlspecialchars($student['email'] ?? 'N/A') ?>
                                            </p>
                                            <p style="font-size: 0.7rem; color: #dc2626;" class="truncate">
                                                <i class="fas fa-id-card"></i> <?= htmlspecialchars($student['student_id'] ?? 'N/A') ?>
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
                                            <?= $student['dropout_date'] ? date('M j, Y', strtotime($student['dropout_date'])) : 'N/A' ?>
                                        </span>
                                        <?php if ($daysDropped > 0): ?>
                                        <br>
                                        <span class="badge <?= $daysDropped > 60 ? 'badge-danger' : 'badge-sand' ?> text-xs">
                                            <?= $daysDropped ?> days ago
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($dropout_reason): ?>
                                        <span class="reason-tag <?= $badge_class ?>">
                                            <?= htmlspecialchars($dropout_reason) ?>
                                        </span>
                                        <br>
                                    <?php endif; ?>
                                    <button class="text-xs font-medium mt-1 inline-flex items-center gap-1 cursor-pointer update-link"
                                            onclick="toggleReasonForm('<?= $student['student_id'] ?>')">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                </td>
                                <td>
                                    <?php if ($student['dropout_processed_by']): ?>
                                        <span style="font-size: 0.875rem; color: var(--deep-navy);">
                                            <?= htmlspecialchars($student['processed_by_name'] ?? 'Unknown') ?>
                                        </span>
                                        <?php if ($student['dropout_processed_at']): ?>
                                        <br>
                                        <span style="font-size: 0.7rem; color: var(--steel-blue);">
                                            <?= date('M j, Y g:i A', strtotime($student['dropout_processed_at'])) ?>
                                        </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>
                                        <button class="btn btn-success btn-sm mb-2 w-full" 
                                                onclick="restoreStudent('<?= $student['student_id'] ?>')">
                                            <i class="fas fa-user-plus"></i> Restore
                                        </button>
                                        
                                        <!-- Reason Update Form -->
                                        <div class="toggle-form" id="reason-form-<?= $student['student_id'] ?>" 
                                             style="background: var(--bg-card); border-radius: var(--radius-xl); border: 1px solid rgba(210, 193, 182, 0.3); padding: 1rem;">
                                            <form method="POST">
                                                <input type="hidden" name="student_id" value="<?= htmlspecialchars($student['student_id']) ?>">
                                                <div class="space-y-2">
                                                    <label style="color: var(--steel-blue); font-size: 0.75rem; font-weight: 600;">Update Reason</label>
                                                    <select name="dropout_reason" class="input-field text-sm"
                                                            onchange="handleReasonChange(this, '<?= $student['student_id'] ?>')">
                                                        <option value="">Select reason...</option>
                                                        <option value="Academic Performance" <?= $dropout_reason == 'Academic Performance' ? 'selected' : '' ?>>Academic Performance</option>
                                                        <option value="Financial Difficulties" <?= $dropout_reason == 'Financial Difficulties' ? 'selected' : '' ?>>Financial Difficulties</option>
                                                        <option value="Medical/Health Issues" <?= $dropout_reason == 'Medical/Health Issues' ? 'selected' : '' ?>>Medical/Health Issues</option>
                                                        <option value="Personal/Family Reasons" <?= $dropout_reason == 'Personal/Family Reasons' ? 'selected' : '' ?>>Personal/Family Reasons</option>
                                                        <option value="Work/Employment" <?= $dropout_reason == 'Work/Employment' ? 'selected' : '' ?>>Work/Employment</option>
                                                        <option value="Transfer/Relocation" <?= $dropout_reason == 'Transfer/Relocation' ? 'selected' : '' ?>>Transfer/Relocation</option>
                                                        <option value="Not Responding" <?= $dropout_reason == 'Not Responding' ? 'selected' : '' ?>>Not Responding</option>
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
                                        <h4 class="empty-title">No Dropped Students</h4>
                                        <p class="empty-description">There are currently no students with dropped status.</p>
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
        // STUDENT RESTORATION
        // ============================================================
        function restoreStudent(studentId) {
            const reason = prompt('📋 Please enter the reason for restoration:\n\nThis will be logged in the student\'s history.');
            
            if (reason === null) return;
            
            if (reason.trim() === '') {
                alert('⚠️ Please provide a valid reason for restoration.');
                return;
            }
            
            if (confirm('✅ Are you sure you want to restore this student?\n\nReason: ' + reason.trim())) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                form.innerHTML = `
                    <input type="hidden" name="restore" value="1">
                    <input type="hidden" name="student_id" value="${studentId}">
                    <input type="hidden" name="restoration_reason" value="${escapeHtml(reason.trim())}">
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
            const table = document.getElementById('dropTable');
            const ws = XLSX.utils.table_to_sheet(table);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Dropped Students");
            
            const today = new Date();
            const dateStr = today.toISOString().split('T')[0];
            
            XLSX.writeFile(wb, `Dropped_Students_${dateStr}.xlsx`);
        }

        // ============================================================
        // DATATABLES INITIALIZATION
        // ============================================================
        const TOTAL_COLUMNS = 6;

        $(document).ready(function() {
            const table = document.getElementById('dropTable');
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
                $('#dropTable').DataTable({
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
            gradient.addColorStop(0, 'rgba(220, 38, 38, 0.2)');
            gradient.addColorStop(1, 'rgba(220, 38, 38, 0.0)');
            
            const trendData = <?= json_encode($trend_data) ?>;
            const months = trendData.length ? trendData.map(d => d.month) : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
            const counts = trendData.length ? trendData.map(d => parseInt(d.count)) : [0, 0, 0, 0, 0, 0];
            
            trendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Dropped',
                        data: counts,
                        borderColor: '#dc2626',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        pointBackgroundColor: '#dc2626',
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
                                    return 'Dropped: ' + context.parsed.y;
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

        console.log('✅ Dropped Students page loaded with ASD Academy theme');
    </script>
</body>
</html>