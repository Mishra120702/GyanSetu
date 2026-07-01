<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Initialize filter variables
$filter_batch = isset($_GET['batch']) ? $_GET['batch'] : '';
$filter_student = isset($_GET['student']) ? trim($_GET['student']) : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$filter_action_by = isset($_GET['action_by']) ? $_GET['action_by'] : '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all active batches for filter dropdown
    $stmt = $db->query("
        SELECT batch_id, batch_name 
        FROM batches 
        WHERE status IN ('upcoming', 'ongoing') 
        ORDER BY start_date DESC
    ");
    $active_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get admin users for filter dropdown
    $stmt = $db->query("
        SELECT id, name 
        FROM users 
        WHERE role = 'admin' AND status = 'active'
        ORDER BY name
    ");
    $admin_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build the WHERE clause for transfers query
    $where_conditions = [];
    $params = [];
    
    if (!empty($filter_batch)) {
        $where_conditions[] = "(fb.batch_id = :filter_batch OR tb.batch_id = :filter_batch)";
        $params[':filter_batch'] = $filter_batch;
    }
    if (!empty($filter_student)) {
        $where_conditions[] = "(s.first_name LIKE :filter_student OR s.last_name LIKE :filter_student OR s.student_id LIKE :filter_student)";
        $params[':filter_student'] = "%$filter_student%";
    }
    if (!empty($filter_date_from)) {
        $where_conditions[] = "DATE(h.transfer_date) >= :date_from";
        $params[':date_from'] = $filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $where_conditions[] = "DATE(h.transfer_date) <= :date_to";
        $params[':date_to'] = $filter_date_to;
    }
    if (!empty($filter_action_by)) {
        $where_conditions[] = "h.transferred_by = :action_by";
        $params[':action_by'] = $filter_action_by;
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Get all transfers with filters applied
    $query = "
        SELECT h.*, 
               s.first_name, s.last_name, s.email as student_email, s.phone_number,
               fb.batch_name as from_batch_name,
               tb.batch_name as to_batch_name,
               u.name as transferred_by_name,
               u.email as transferred_by_email
        FROM student_batch_history h
        JOIN students s ON h.student_id = s.student_id
        LEFT JOIN batches fb ON h.from_batch_id = fb.batch_id
        LEFT JOIN batches tb ON h.to_batch_id = tb.batch_id
        LEFT JOIN users u ON h.transferred_by = u.id
        $where_clause
        ORDER BY h.transfer_date DESC
    ";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $all_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get transfer statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_transfers,
            COUNT(DISTINCT student_id) as unique_students_transferred,
            COUNT(DISTINCT transferred_by) as admins_involved,
            SUM(CASE WHEN transfer_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_30_days
        FROM student_batch_history
    ";
    $transfer_stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);
    
    // Get monthly transfer trend for chart
    $trend_query = "
        SELECT 
            DATE_FORMAT(transfer_date, '%Y-%m') as month,
            COUNT(*) as transfer_count
        FROM student_batch_history
        WHERE transfer_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(transfer_date, '%Y-%m')
        ORDER BY month DESC
    ";
    $trend_data = $db->query($trend_query)->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = "Database error occurred";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Transfer Management - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        primary: { DEFAULT: '#4F46E5', dark: '#4338CA', light: '#EEF2FF' },
                        success: { DEFAULT: '#10B981', dark: '#059669', light: '#ECFDF5' },
                        warning: { DEFAULT: '#F59E0B', dark: '#D97706', light: '#FFFBEB' },
                        danger:  { DEFAULT: '#EF4444', dark: '#DC2626', light: '#FEF2F2' },
                        violet:  { DEFAULT: '#7C3AED', dark: '#6D28D9', light: '#F5F3FF' },
                        sky:     { DEFAULT: '#06B6D4', dark: '#0891B2', light: '#ECFEFF' },
                        ink:     '#0F172A',
                    },
                    boxShadow: {
                        soft: '0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -8px rgba(15,23,42,0.08)',
                        lift: '0 16px 40px -12px rgba(79,70,229,0.25)',
                    },
                }
            }
        }
    </script>
        <style>
       /* ===============================
   ASD PREMIUM BLUE THEME
================================ */

:root{
    --primary:#1B3C53;
    --primary-dark:#163247;
    --secondary:#234C6A;
    --accent:#456882;
    --highlight:#D2C1B6;

    --primary-light:#EEF4F8;
    --surface:#FFFFFF;
    --bg:#F7F9FB;

    --text:#1E293B;
    --text-light:#64748B;
    --border:#E2E8F0;
}

/* PAGE */
body{
    font-family:'Inter',sans-serif;
    background:linear-gradient(
        180deg,
        #F8FAFC 0%,
        #F1F5F9 100%
    ) fixed!important;
    color:var(--text);
}

/* GLASS CARD */
.glass-card,
.table-card,
.chart-card,
.qa-card,
.hero-card{
    background:rgba(255,255,255,.92)!important;
    border:1px solid rgba(27,60,83,.10)!important;
    border-radius:20px!important;
    box-shadow:0 8px 30px rgba(27,60,83,.06)!important;
}
.hero-card{
    background:linear-gradient(
        135deg,
        rgba(27,60,83,.05),
        rgba(35,76,106,.04),
        rgba(69,104,130,.04)
    )!important;
}

/* HERO */
.hero-card{
    border-top:4px solid var(--primary)!important;
    background:linear-gradient(
        135deg,
        #EEF4F8,
        #FFFFFF
    )!important;
}

/* TOP STAT CARDS */
/* TOP STAT CARDS */

.stat-total{
    background:linear-gradient(135deg,#1B3C53,#234C6A)!important;
    color:#fff!important;
    border:none!important;
    border-radius:20px!important;
    box-shadow:0 12px 28px rgba(27,60,83,.20)!important;
}

.stat-students{
    background:linear-gradient(135deg,#234C6A,#456882)!important;
    color:#fff!important;
    border:none!important;
    border-radius:20px!important;
    box-shadow:0 12px 28px rgba(35,76,106,.20)!important;
}

.stat-days{
    background:linear-gradient(135deg,#456882,#5D7C96)!important;
    color:#fff!important;
    border:none!important;
    border-radius:20px!important;
    box-shadow:0 12px 28px rgba(69,104,130,.20)!important;
}

.stat-admins{
    background:linear-gradient(135deg,#D2C1B6,#E6D9D0)!important;
    color:#1B3C53!important;
    border:none!important;
    border-radius:20px!important;
    box-shadow:0 12px 28px rgba(210,193,182,.25)!important;
}

.stat-total *,
.stat-students *,
.stat-days *{
    color:#fff!important;
}

.stat-admins *,
.stat-admins .stat-value,
.stat-admins .stat-label{
    color:#1B3C53!important;
}

.stat-total:hover,
.stat-students:hover,
.stat-days:hover,
.stat-admins:hover{
    transform:translateY(-4px);
}

/* ICONS INSIDE CARDS */

.stat-total .stat-icon,
.stat-students .stat-icon,
.stat-days .stat-icon{
    background:rgba(255,255,255,.18)!important;
    color:#fff!important;
}

.stat-admins .stat-icon{
    background:rgba(27,60,83,.08)!important;
    color:#1B3C53!important;
}

/* ICONS */
.stat-icon{
    background:rgba(255,255,255,.18)!important;
}

.icon-tile{
    border-radius:16px!important;
}

/* FILTER PANEL */
.filter-panel{
    background:#fff!important;
    border:1px solid #E6ECF1!important;
    border-top:4px solid var(--primary)!important;
}

/* INPUTS */
.pretty-input,
.pretty-select{
    background:#F8FAFC!important;
    border:1px solid #DCE5EC!important;
}

.pretty-input:focus,
.pretty-select:focus{
    border-color:var(--secondary)!important;
    box-shadow:0 0 0 4px rgba(35,76,106,.10)!important;
}

/* BUTTONS */
.btn-primary-pill{
    background:linear-gradient(
        135deg,
        #1B3C53,
        #234C6A
    )!important;
    color:#fff!important;
}

.btn-primary-pill:hover{
    background:linear-gradient(
        135deg,
        #234C6A,
        #456882
    )!important;
}

.btn-ghost-pill{
    background:#fff!important;
    border:1px solid #DCE5EC!important;
}

.btn-ghost-pill:hover{
    background:#EEF4F8!important;
    color:#234C6A!important;
}

/* TABLE */
.skeu-table thead tr{
    background:#EEF4F8!important;
}

.skeu-table th{
    color:#1B3C53!important;
}

.skeu-table tbody tr:hover td{
    background:#F5F8FA!important;
}

.skeu-table tbody tr:nth-child(even) td{
    background:#FAFBFC!important;
}

/* BADGES */
.bp-count{
    background:#EEF4F8!important;
    color:#234C6A!important;
    border:none!important;
}

.bp-in{
    background:#ECFDF5!important;
    color:#15803D!important;
}

.bp-out{
    background:#FEF2F2!important;
    color:#DC2626!important;
}

/* CHART CARD */
.chart-card{
    border-top:4px solid #234C6A!important;
}

/* QUICK ACTION CARDS */
.qa-card:hover{
    transform:translateY(-6px)!important;
    border-color:#234C6A!important;
}

.qa-card a{
    color:#234C6A!important;
    font-weight:600!important;
}

/* FOOTER */
.table-footer{
    background:#F8FAFC!important;
}

/* SCROLLBAR */
::-webkit-scrollbar{
    width:7px;
}

::-webkit-scrollbar-track{
    background:#F1F5F9;
}

::-webkit-scrollbar-thumb{
    background:#456882;
    border-radius:10px;
}

::-webkit-scrollbar-thumb:hover{
    background:#234C6A;
}
/* =====================================
   GLOBAL ROUNDED CONTROLS THEME
===================================== */

/* All Buttons */
button,
.btn,
.btn-primary,
.btn-secondary,
.btn-ghost,
.btn-upload,
.btn-cancel,
.btn-template,
.btn-back,
.action-chip,
.bulk-btn,
.page-btn,
a.btn,
input[type="submit"],
input[type="button"]{
    border-radius:14px !important;
}

/* Search & Inputs */
input[type="text"],
input[type="email"],
input[type="number"],
input[type="password"],
input[type="date"],
input[type="url"],
input[type="search"],
textarea,
.filter-input,
.pretty-input,
.field-input{
    border-radius:14px !important;
}

/* Select Dropdowns */
select,
.pretty-select{
    border-radius:14px !important;
}

/* Action Buttons inside cards */
.card-actions a,
.card-actions button,
.batch-action,
.topbar-action{
    border-radius:12px !important;
}

/* Pagination */
.page-btn{
    border-radius:12px !important;
}

/* Filter Apply / Reset */
.filter-actions .btn{
    border-radius:14px !important;
}

/* Export / Print */
.export-btn,
.print-btn{
    border-radius:14px !important;
}

/* Small icon buttons */
.btn-icon,
.icon-btn{
    border-radius:12px !important;
}

/* Upload / Download buttons */
.btn-upload,
.btn-template{
    border-radius:14px !important;
}
/* GLOBAL PILL BUTTONS */

.btn-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:.5rem;

    padding:.75rem 1.5rem;

    border-radius:999px !important;

    font-weight:600;
    text-decoration:none;

    transition:.25s ease;
}

/* Primary */

.btn-primary-pill{
    border-radius:999px !important;
}

/* Secondary */

.btn-ghost-pill{
    border-radius:999px !important;
}

/* Print Export */

#exportCSV,
button[onclick*="print"]{
    border-radius:999px !important;
}

/* Students + Transfer buttons */

.hero-card a{
    border-radius:999px !important;
}

/* Reset button */

.filter-panel a{
    border-radius:999px !important;
}

/* Empty state Create Transfer */

.empty-state a,
.text-center a.btn-pill{
    border-radius:999px !important;
}
.pretty-input,
.pretty-select{
    width:100% !important;
    height:48px !important;
}

.filter-panel .grid > div{
    min-width:0 !important;
}
    </style>
</head>
<body style="background:linear-gradient(180deg,#F5F3FF 0%,#EEF2FF 40%,#ECFEFF 100%) !important;background-attachment:fixed;">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content ml-64 p-6 transition-all duration-300">
        <div class="max-w-[1600px] mx-auto">
            
            <!-- Hero Header -->
            <div class="hero-card p-8 mb-8 fade-up">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <div class="icon-tile bg-primary-light">
                                <i class="fas fa-people-arrows text-2xl text-primary"></i>
                            </div>
                            <h1 class="text-3xl font-bold text-ink tracking-tight">Batch Transfer Management</h1>
                        </div>
                        <p class="text-slate-500 mt-1 ml-[68px]">Manage and track student transfers between batches with detailed history</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="../student/students_list.php" class="btn-pill btn-ghost-pill">
                            <i class="fas fa-users"></i> Students
                        </a>
                        <a href="batch_wise_transfer.php" class="btn-pill btn-primary-pill">
                            <i class="fas fa-exchange-alt"></i> New Transfer
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="p-4 mb-6 rounded-2xl border-l-4 fade-up" style="border-left-color:var(--success);background:var(--success-lt);border:1px solid rgba(16,185,129,.2);" role="alert">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-success text-xl"></i>
                        <span class="text-emerald-800"><?= htmlspecialchars($_SESSION['success']) ?></span>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="p-4 mb-6 rounded-2xl border-l-4 fade-up" style="border-left-color:var(--danger);background:var(--danger-lt);border:1px solid rgba(239,68,68,.2);" role="alert">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-circle text-danger text-xl"></i>
                        <span class="text-red-800"><?= htmlspecialchars($_SESSION['error']) ?></span>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 fade-up">
                <div class="stat-total p-6">
                    <div class="flex items-center justify-between mb-2">
                        <div class="stat-label">Total Transfers</div>
                        <div class="stat-icon"><i class="fas fa-chart-line text-2xl"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($transfer_stats['total_transfers'] ?? 0) ?></div>
                </div>
                <div class="stat-students p-6">
                    <div class="flex items-center justify-between mb-2">
                        <div class="stat-label">Unique Students</div>
                        <div class="stat-icon"><i class="fas fa-user-graduate text-2xl"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($transfer_stats['unique_students_transferred'] ?? 0) ?></div>
                </div>
                <div class="stat-days p-6">
                    <div class="flex items-center justify-between mb-2">
                        <div class="stat-label">Last 30 Days</div>
                        <div class="stat-icon"><i class="fas fa-calendar-week text-2xl"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($transfer_stats['last_30_days'] ?? 0) ?></div>
                </div>
                <div class="stat-admins p-6">
                    <div class="flex items-center justify-between mb-2">
                        <div class="stat-label">Admins Involved</div>
                        <div class="stat-icon"><i class="fas fa-user-tie text-2xl"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($transfer_stats['admins_involved'] ?? 0) ?></div>
                </div>
            </div>
            
            <!-- Transfer Trend Chart -->
            <?php if (!empty($trend_data)): ?>
            <div class="chart-card p-6 mb-8 fade-up">
                <div class="flex items-center gap-3 mb-4">
                    <i class="fas fa-chart-simple text-xl" style="color:var(--indigo);"></i>
                    <h3 class="text-lg font-semibold text-ink">Transfer Trend (Last 6 Months)</h3>
                </div>
                <canvas id="transferTrendChart" height="80" style="max-height: 200px;"></canvas>
            </div>
            <?php endif; ?>
            
            <!-- Filters Section -->
            <div class="filter-panel p-6 mb-8 fade-up">
                <div class="flex items-center gap-3 mb-5">
                    <i class="fas fa-filter" style="color:var(--purple);"></i>
                    <h3 class="text-lg font-semibold" style="color:var(--ink);">Filter Transfers</h3>
                </div>
                <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="filter-label">Batch</label>
                        <select name="batch" class="pretty-select">
                            <option value="">All Batches</option>
                            <?php foreach ($active_batches as $batch): ?>
                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $filter_batch == $batch['batch_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($batch['batch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
    <label class="filter-label">Student</label>
    <input type="text"
           name="student"
           class="pretty-input w-full"
           placeholder="Name or ID"
           value="<?= htmlspecialchars($filter_student) ?>">
</div>
                    <div>
                        <label class="filter-label">From Date</label>
                        <input type="date" name="date_from" class="pretty-input" value="<?= htmlspecialchars($filter_date_from) ?>">
                    </div>
                    <div>
                        <label class="filter-label">To Date</label>
                        <input type="date" name="date_to" class="pretty-input" value="<?= htmlspecialchars($filter_date_to) ?>">
                    </div>
                    <div>
                        <label class="filter-label">Action By</label>
                        <select name="action_by" class="pretty-select">
                            <option value="">All Admins</option>
                            <?php foreach ($admin_users as $admin): ?>
                                <option value="<?= $admin['id'] ?>" <?= $filter_action_by == $admin['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($admin['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-5 flex justify-end items-end gap-3">
                        <button type="submit" class="btn-pill btn-primary-pill">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="batch_transfers.php" class="btn-pill btn-ghost-pill">
                            <i class="fas fa-undo-alt"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Transfers Table -->
            <div class="table-card fade-up">
                <div class="table-header px-6 py-5 flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-history text-indigo-600 text-xl"></i>
                        <h2 class="text-xl font-semibold text-ink">Transfer History</h2>
                        <span class="badge-pill bp-count">
                            <i class="fas fa-list-ul"></i> <?= count($all_transfers) ?> Records
                        </span>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="window.print()" class="btn-pill btn-ghost-pill text-sm">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button id="exportCSV" class="btn-pill btn-ghost-pill text-sm">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <?php if (count($all_transfers) > 0): ?>
                        <table class="skeu-table w-full text-sm">
                            <thead>
                                <tr>
                                    <th>Student Details</th>
                                    <th>From Batch</th>
                                    <th>To Batch</th>
                                    <th>Transfer Date</th>
                                    <th>Reason</th>
                                    <th>Transferred By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_transfers as $transfer): ?>
                                    <tr class="transition-all duration-200">
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <div class="student-avatar">
                                                    <i class="fas fa-user-graduate text-sm" style="color:var(--indigo);"></i>
                                                </div>
                                                <div>
                                                    <div class="font-semibold text-ink">
                                                        <?= htmlspecialchars($transfer['first_name'] . ' ' . $transfer['last_name']) ?>
                                                    </div>
                                                    <div class="text-xs text-slate-500 flex gap-3 mt-1">
                                                        <span><i class="fas fa-id-card mr-1"></i> <?= htmlspecialchars($transfer['student_id']) ?></span>
                                                        <?php if (!empty($transfer['student_email'])): ?>
                                                            <span><i class="fas fa-envelope mr-1"></i> <?= htmlspecialchars($transfer['student_email']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge-pill bp-out">
                                                <i class="fas fa-sign-out-alt"></i>
                                                <?= htmlspecialchars($transfer['from_batch_name'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-pill bp-count">
                                                <i class="fas fa-sign-in-alt"></i>
                                                <?= htmlspecialchars($transfer['to_batch_name'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="font-medium text-slate-700"><?= date('M j, Y', strtotime($transfer['transfer_date'])) ?></div>
                                            <div class="text-xs text-slate-400"><?= date('h:i A', strtotime($transfer['transfer_date'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="max-w-[200px] truncate text-slate-600" title="<?= htmlspecialchars($transfer['transfer_reason'] ?? '') ?>">
                                                <?= htmlspecialchars($transfer['transfer_reason'] ?? '—') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-user-circle text-slate-400"></i>
                                                <div>
                                                    <div class="font-medium text-slate-700"><?= htmlspecialchars($transfer['transferred_by_name'] ?? 'System') ?></div>
                                                    <?php if (!empty($transfer['transferred_by_email'])): ?>
                                                        <div class="text-xs text-slate-400"><?= htmlspecialchars($transfer['transferred_by_email']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-16">
                            <div class="icon-tile empty-icon-wrap w-20 h-20 mx-auto mb-4">
                                <i class="fas fa-exchange-alt text-3xl" style="color:var(--muted);"></i>
                            </div>
                            <p class="text-slate-500 text-lg">No transfer records found</p>
                            <p class="text-slate-400 text-sm mt-1">Try adjusting your filters or create a new transfer</p>
                            <a href="batch_wise_transfer.php" class="btn-pill btn-primary-pill mt-6">
                                <i class="fas fa-plus"></i> Create Transfer
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="table-footer px-6 py-4 flex justify-between items-center text-sm flex-wrap gap-2" style="color:var(--slate);">
                    <div>
                        <i class="fas fa-database mr-1"></i> Showing <?= count($all_transfers) ?> transfer records
                    </div>
                    <div>
                        <i class="fas fa-info-circle mr-1"></i> Transfers are recorded with full audit trail
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8 fade-up">
                <div class="qa-card p-6 text-center group">
                    <div class="icon-tile w-16 h-16 mx-auto mb-4" style="background:linear-gradient(135deg,var(--indigo-lt),#DDD6FE);">
                        <i class="fas fa-chart-pie text-2xl group-hover:scale-110 transition-transform" style="color:var(--indigo);"></i>
                    </div>
                    <h3 class="font-semibold text-ink text-lg">Transfer Analytics</h3>
                    <p class="text-slate-500 text-sm mt-2">View detailed reports and statistics</p>
                    <a href="transfer_reports.php" class="inline-block mt-4 font-semibold" style="color:var(--indigo);">Explore →</a>
                </div>
                <div class="qa-card p-6 text-center group">
                    <div class="icon-tile w-16 h-16 mx-auto mb-4" style="background:linear-gradient(135deg,var(--success-lt),#A7F3D0);">
                        <i class="fas fa-layer-group text-2xl group-hover:scale-110 transition-transform" style="color:var(--success);"></i>
                    </div>
                    <h3 class="font-semibold text-ink text-lg">Bulk Transfer</h3>
                    <p class="text-slate-500 text-sm mt-2">Transfer multiple students at once</p>
                    <a href="batch_wise_transfer.php" class="inline-block mt-4 font-semibold" style="color:var(--success);">Get Started →</a>
                </div>
                <div class="qa-card p-6 text-center group">
                    <div class="icon-tile w-16 h-16 mx-auto mb-4" style="background:linear-gradient(135deg,var(--purple-lt),#EDE9FE);">
                        <i class="fas fa-book-open text-2xl group-hover:scale-110 transition-transform" style="color:var(--purple);"></i>
                    </div>
                    <h3 class="font-semibold text-ink text-lg">Batch Overview</h3>
                    <p class="text-slate-500 text-sm mt-2">View all active batches and enrollments</p>
                    <a href="batches_list.php" class="inline-block mt-4 font-semibold" style="color:var(--purple);">View Batches →</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Transfer Trend Chart
        <?php if (!empty($trend_data)): ?>
        const ctx = document.getElementById('transferTrendChart').getContext('2d');
        const trendLabels = <?= json_encode(array_reverse(array_column($trend_data, 'month'))) ?>;
        const trendValues = <?= json_encode(array_reverse(array_column($trend_data, 'transfer_count'))) ?>;
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Transfers',
                    data: trendValues,
                    borderColor: '#4F46E5',
                    backgroundColor: 'rgba(79, 70, 229, 0.08)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#4F46E5',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#0F172A',
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            stepSize: 1,
                            precision: 0
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Export to CSV functionality
        document.getElementById('exportCSV')?.addEventListener('click', function() {
            const table = document.querySelector('.skeu-table');
            if (!table) return;
            
            const rows = table.querySelectorAll('tr');
            const csvData = [];
            
            // Get headers
            const headers = [];
            table.querySelectorAll('th').forEach(th => {
                headers.push(th.innerText.trim());
            });
            csvData.push(headers);
            
            // Get data rows
            rows.forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach(cell => {
                    // Clean up cell content
                    let text = cell.innerText.trim();
                    // Handle multi-line text
                    text = text.replace(/\n/g, ' ').replace(/\s+/g, ' ');
                    rowData.push(text);
                });
                if (rowData.length) csvData.push(rowData);
            });
            
            // Convert to CSV string
            const csvString = csvData.map(row => 
                row.map(cell => `"${cell.replace(/"/g, '""')}"`).join(',')
            ).join('\n');
            
            // Download
            const blob = new Blob(["\uFEFF" + csvString], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `batch_transfers_${new Date().toISOString().slice(0,10)}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        });
        
        
    </script>
</body>
</html>
