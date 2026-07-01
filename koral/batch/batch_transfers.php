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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Skeuomorphic Design Styles */
        body {
            background: linear-gradient(135deg, #e0e5ec 0%, #cbd0d9 100%);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        
        /* Skeuomorphic Card Effect */
        .skeu-card {
            background: #eef2f8;
            border-radius: 24px;
            box-shadow: 8px 8px 16px rgba(0, 0, 0, 0.1), -4px -4px 12px rgba(255, 255, 255, 0.7), inset 0px 1px 0px rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
        }
        
        .skeu-card:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgba(0, 0, 0, 0.12), -6px -6px 14px rgba(255, 255, 255, 0.8);
        }
        
        /* Skeuomorphic Button */
        .skeu-btn {
            background: #eef2f8;
            border: none;
            border-radius: 40px;
            padding: 10px 24px;
            font-weight: 600;
            box-shadow: 3px 3px 6px rgba(0, 0, 0, 0.1), -2px -2px 4px rgba(255, 255, 255, 0.7);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .skeu-btn:active {
            transform: translateY(2px);
            box-shadow: inset 2px 2px 4px rgba(0, 0, 0, 0.1), inset -1px -1px 2px rgba(255, 255, 255, 0.6);
        }
        
        .skeu-btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            text-shadow: 0px 1px 0px rgba(0,0,0,0.2);
            box-shadow: 3px 3px 8px rgba(0, 0, 0, 0.15), -1px -1px 3px rgba(255, 255, 255, 0.5), inset 0px 1px 0px rgba(255,255,255,0.3);
        }
        
        .skeu-btn-primary:active {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(2px);
            box-shadow: inset 2px 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        /* Skeuomorphic Input */
        .skeu-input, .skeu-select {
            background: #f5f7fb;
            border: none;
            border-radius: 40px;
            padding: 12px 20px;
            box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.08), inset -2px -2px 4px rgba(255, 255, 255, 0.7), 1px 1px 0px rgba(255,255,255,0.5);
            transition: all 0.2s ease;
            width: 100%;
        }
        
        .skeu-input:focus, .skeu-select:focus {
            outline: none;
            box-shadow: inset 4px 4px 8px rgba(0, 0, 0, 0.1), inset -2px -2px 5px rgba(255, 255, 255, 0.8);
        }
        
        /* Skeuomorphic Table */
        .skeu-table {
            background: #f8fafc;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 5px 5px 12px rgba(0, 0, 0, 0.08), -3px -3px 8px rgba(255, 255, 255, 0.6);
        }
        
        .skeu-table th {
            background: #e8edf3;
            padding: 16px 20px;
            font-weight: 600;
            color: #1e293b;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
        }
        
        .skeu-table td {
            padding: 14px 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            color: #334155;
        }
        
        .skeu-table tr:hover {
            background: rgba(59, 130, 246, 0.05);
        }
        
        /* Stat Cards */
        .stat-card {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 28px;
            padding: 24px;
            box-shadow: 6px 6px 12px rgba(0, 0, 0, 0.08), -4px -4px 10px rgba(255, 255, 255, 0.7);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            background: #eef2f8;
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 2px 2px 5px rgba(0,0,0,0.05), inset -1px -1px 3px rgba(255,255,255,0.8), 3px 3px 6px rgba(0,0,0,0.05);
        }
        
        /* Badge Styles */
        .badge {
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .badge-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .badge-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .main-content {
            margin-left: 15rem;
        }
        
        @media (max-width: 1280px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #e2e8f0;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="main-content p-6 transition-all duration-300">
        <div class="max-w-[1600px] mx-auto">
            
            <!-- Header with Skeuomorphic Design -->
            <div class="skeu-card p-8 mb-8">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <div class="stat-icon">
                                <i class="fas fa-people-arrows text-2xl text-blue-600"></i>
                            </div>
                            <h1 class="text-3xl font-bold text-slate-800">Batch Transfer Management</h1>
                        </div>
                        <p class="text-slate-500 mt-1 ml-16">Manage and track student transfers between batches with detailed history</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="students_list.php" class="skeu-btn flex items-center gap-2">
                            <i class="fas fa-users"></i> Students
                        </a>
                        <a href="batch_wise_transfer.php" class="skeu-btn skeu-btn-primary flex items-center gap-2">
                            <i class="fas fa-exchange-alt"></i> New Transfer
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="skeu-card p-4 mb-6 bg-green-50 border-l-4 border-green-500" role="alert">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        <span class="text-green-800"><?= htmlspecialchars($_SESSION['success']) ?></span>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="skeu-card p-4 mb-6 bg-red-50 border-l-4 border-red-500" role="alert">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-circle text-red-600 text-xl"></i>
                        <span class="text-red-800"><?= htmlspecialchars($_SESSION['error']) ?></span>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-500 text-sm font-medium uppercase tracking-wide">Total Transfers</p>
                            <p class="text-4xl font-bold text-slate-800 mt-2"><?= number_format($transfer_stats['total_transfers'] ?? 0) ?></p>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-chart-line text-2xl text-blue-500"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-500 text-sm font-medium uppercase tracking-wide">Unique Students</p>
                            <p class="text-4xl font-bold text-slate-800 mt-2"><?= number_format($transfer_stats['unique_students_transferred'] ?? 0) ?></p>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-user-graduate text-2xl text-green-500"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-500 text-sm font-medium uppercase tracking-wide">Last 30 Days</p>
                            <p class="text-4xl font-bold text-slate-800 mt-2"><?= number_format($transfer_stats['last_30_days'] ?? 0) ?></p>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-calendar-week text-2xl text-orange-500"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-500 text-sm font-medium uppercase tracking-wide">Admins Involved</p>
                            <p class="text-4xl font-bold text-slate-800 mt-2"><?= number_format($transfer_stats['admins_involved'] ?? 0) ?></p>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-user-tie text-2xl text-purple-500"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Transfer Trend Chart -->
            <?php if (!empty($trend_data)): ?>
            <div class="skeu-card p-6 mb-8">
                <div class="flex items-center gap-3 mb-4">
                    <i class="fas fa-chart-simple text-xl text-blue-600"></i>
                    <h3 class="text-lg font-semibold text-slate-800">Transfer Trend (Last 6 Months)</h3>
                </div>
                <canvas id="transferTrendChart" height="80" style="max-height: 200px;"></canvas>
            </div>
            <?php endif; ?>
            
            <!-- Filters Section -->
            <div class="skeu-card p-6 mb-8">
                <div class="flex items-center gap-3 mb-5">
                    <i class="fas fa-filter text-blue-600"></i>
                    <h3 class="text-lg font-semibold text-slate-800">Filter Transfers</h3>
                </div>
                <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-2">Batch</label>
                        <select name="batch" class="skeu-select">
                            <option value="">All Batches</option>
                            <?php foreach ($active_batches as $batch): ?>
                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $filter_batch == $batch['batch_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($batch['batch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-2">Student</label>
                        <input type="text" name="student" class="skeu-input" placeholder="Name or ID" value="<?= htmlspecialchars($filter_student) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-2">From Date</label>
                        <input type="date" name="date_from" class="skeu-input" value="<?= htmlspecialchars($filter_date_from) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-2">To Date</label>
                        <input type="date" name="date_to" class="skeu-input" value="<?= htmlspecialchars($filter_date_to) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-2">Action By</label>
                        <select name="action_by" class="skeu-select">
                            <option value="">All Admins</option>
                            <?php foreach ($admin_users as $admin): ?>
                                <option value="<?= $admin['id'] ?>" <?= $filter_action_by == $admin['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($admin['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="skeu-btn skeu-btn-primary flex items-center gap-2">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="batch_transfers.php" class="skeu-btn flex items-center gap-2">
                            <i class="fas fa-undo-alt"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Transfers Table -->
            <div class="skeu-card overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-history text-blue-600 text-xl"></i>
                            <h2 class="text-xl font-semibold text-slate-800">Transfer History</h2>
                            <span class="badge badge-success text-xs">
                                <i class="fas fa-list-ul"></i> <?= count($all_transfers) ?> Records
                            </span>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="window.print()" class="skeu-btn text-sm flex items-center gap-2">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button id="exportCSV" class="skeu-btn text-sm flex items-center gap-2">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </button>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <?php if (count($all_transfers) > 0): ?>
                        <table class="skeu-table w-full">
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
                                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center shadow-inner">
                                                    <i class="fas fa-user-graduate text-blue-600 text-sm"></i>
                                                </div>
                                                <div>
                                                    <div class="font-semibold text-slate-800">
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
                                            <span class="inline-flex items-center gap-2 bg-red-50 text-red-700 px-3 py-1 rounded-full text-sm">
                                                <i class="fas fa-sign-out-alt text-red-500"></i>
                                                <?= htmlspecialchars($transfer['from_batch_name'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="inline-flex items-center gap-2 bg-green-50 text-green-700 px-3 py-1 rounded-full text-sm">
                                                <i class="fas fa-sign-in-alt text-green-500"></i>
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
                            <div class="stat-icon w-20 h-20 mx-auto mb-4 flex items-center justify-center">
                                <i class="fas fa-exchange-alt text-3xl text-slate-400"></i>
                            </div>
                            <p class="text-slate-500 text-lg">No transfer records found</p>
                            <p class="text-slate-400 text-sm mt-1">Try adjusting your filters or create a new transfer</p>
                            <a href="batch_wise_transfer.php" class="skeu-btn skeu-btn-primary mt-6 inline-flex items-center gap-2">
                                <i class="fas fa-plus"></i> Create Transfer
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 flex justify-between items-center text-sm text-slate-500">
                    <div>
                        <i class="fas fa-database mr-1"></i> Showing <?= count($all_transfers) ?> transfer records
                    </div>
                    <div>
                        <i class="fas fa-info-circle mr-1"></i> Transfers are recorded with full audit trail
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
                <div class="skeu-card p-6 text-center group hover:bg-gradient-to-br hover:from-white hover:to-slate-50 transition-all duration-300">
                    <div class="stat-icon w-16 h-16 mx-auto mb-4">
                        <i class="fas fa-chart-pie text-2xl text-blue-600 group-hover:scale-110 transition-transform"></i>
                    </div>
                    <h3 class="font-semibold text-slate-800 text-lg">Transfer Analytics</h3>
                    <p class="text-slate-500 text-sm mt-2">View detailed reports and statistics</p>
                    <a href="transfer_reports.php" class="inline-block mt-4 text-blue-600 font-medium hover:text-blue-800">Explore →</a>
                </div>
                <div class="skeu-card p-6 text-center group hover:bg-gradient-to-br hover:from-white hover:to-slate-50 transition-all duration-300">
                    <div class="stat-icon w-16 h-16 mx-auto mb-4">
                        <i class="fas fa-layer-group text-2xl text-green-600 group-hover:scale-110 transition-transform"></i>
                    </div>
                    <h3 class="font-semibold text-slate-800 text-lg">Bulk Transfer</h3>
                    <p class="text-slate-500 text-sm mt-2">Transfer multiple students at once</p>
                    <a href="batch_wise_transfer.php" class="inline-block mt-4 text-green-600 font-medium hover:text-green-800">Get Started →</a>
                </div>
                <div class="skeu-card p-6 text-center group hover:bg-gradient-to-br hover:from-white hover:to-slate-50 transition-all duration-300">
                    <div class="stat-icon w-16 h-16 mx-auto mb-4">
                        <i class="fas fa-book-open text-2xl text-purple-600 group-hover:scale-110 transition-transform"></i>
                    </div>
                    <h3 class="font-semibold text-slate-800 text-lg">Batch Overview</h3>
                    <p class="text-slate-500 text-sm mt-2">View all active batches and enrollments</p>
                    <a href="batches_list.php" class="inline-block mt-4 text-purple-600 font-medium hover:text-purple-800">View Batches →</a>
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
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#2563eb',
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
                        backgroundColor: '#1e293b',
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
        
        // Sidebar toggle for wider view (optional)
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.createElement('button');
            sidebarToggle.innerHTML = '<i class="fas fa-expand"></i>';
            sidebarToggle.className = 'fixed top-20 left-4 z-50 bg-slate-800 text-white p-3 rounded-full shadow-lg hover:bg-slate-700 transition-all duration-300';
            sidebarToggle.title = 'Toggle Sidebar';
            
            sidebarToggle.addEventListener('click', function() {
                document.body.classList.toggle('sidebar-collapsed');
                const mainContent = document.querySelector('.main-content');
                if (document.body.classList.contains('sidebar-collapsed')) {
                    mainContent.style.marginLeft = '5rem';
                    this.innerHTML = '<i class="fas fa-compress"></i>';
                } else {
                    mainContent.style.marginLeft = '15rem';
                    this.innerHTML = '<i class="fas fa-expand"></i>';
                }
            });
            
            document.body.appendChild(sidebarToggle);
        });
    </script>
</body>
</html>