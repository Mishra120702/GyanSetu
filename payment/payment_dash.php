<?php
// payments_dashboard.php - Admin Payments Dashboard
include '../db_connection.php';
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Include payment functions
require_once 'payment_functions.php';
$paymentDashboard = new PaymentDashboard($db);

// Get filter parameters
$dateFilter = $_GET['date_filter'] ?? 'month';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$batchFilter = $_GET['batch'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$paymentModeFilter = $_GET['payment_mode'] ?? '';

// Adjust date range based on filter
switch ($dateFilter) {
    case 'today':
        $dateFrom = date('Y-m-d');
        $dateTo = date('Y-m-d');
        break;
    case 'yesterday':
        $dateFrom = date('Y-m-d', strtotime('-1 day'));
        $dateTo = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'month':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-d');
        break;
    case 'quarter':
        $quarter = ceil(date('n') / 3);
        $monthStart = (($quarter - 1) * 3) + 1;
        $dateFrom = date('Y-' . str_pad($monthStart, 2, '0', STR_PAD_LEFT) . '-01');
        $dateTo = date('Y-m-d');
        break;
    case 'year':
        $dateFrom = date('Y-01-01');
        $dateTo = date('Y-m-d');
        break;
    case 'custom':
        // Use custom dates from form
        break;
}

// Get dashboard data
$stats = $paymentDashboard->getPaymentStatistics($dateFrom, $dateTo);
$recentPayments = $paymentDashboard->getRecentPayments(10);
$paymentTrends = $paymentDashboard->getPaymentTrends('monthly', 12);
$paymentModes = $paymentDashboard->getPaymentModeSummary();
$paymentsByBatch = $paymentDashboard->getPaymentsByBatch(10);
$pendingInstallments = $paymentDashboard->getPendingInstallments();
$topPayingStudents = $paymentDashboard->getTopPayingStudents(10);
$studentFeeOverview = $paymentDashboard->getStudentFeeOverview();
$feeCollectionSummary = $paymentDashboard->getFeeCollectionSummary($dateFrom, $dateTo);

// Get batches for filter dropdown
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate additional statistics
$collectionRate = ($stats['total_amount'] > 0) ? ($stats['verified_amount'] / $stats['total_amount'] * 100) : 0;
$pendingRate = ($stats['total_amount'] > 0) ? ($stats['pending_amount'] / $stats['total_amount'] * 100) : 0;
$rejectionRate = ($stats['total_amount'] > 0) ? ($stats['rejected_amount'] / $stats['total_amount'] * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments Dashboard - ASD Academy</title>
    <?php include '../header.php'; ?>
    <style>
        :root {
            --primary-color: #667eea;
            --success-color: #38a169;
            --warning-color: #dd6b20;
            --danger-color: #e53e3e;
            --info-color: #4299e1;
            --purple-color: #9f7aea;
            --teal-color: #319795;
            --pink-color: #d53f8c;
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
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        
        @keyframes fadeInUp {
            to { opacity: 1; transform: translateY(0); }
        }
        
        .progress-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .progress-circle svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        
        .progress-circle circle {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
        }
        
        .progress-circle .progress-bg {
            stroke: #e5e7eb;
        }
        
        .progress-circle .progress-bar {
            stroke: currentColor;
            stroke-dasharray: 226;
            stroke-dashoffset: calc(226 - (226 * var(--percentage)) / 100);
            transition: stroke-dashoffset 1s ease;
        }
        
        .trend-up {
            color: var(--success-color);
        }
        
        .trend-down {
            color: var(--danger-color);
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .status-verified {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #feebc8 0%, #fbd38d 100%);
            color: #9c4221;
            border: 1px solid #fbd38d;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #9b2c2c;
            border: 1px solid #feb2b2;
        }
        
        .status-overdue {
            background: linear-gradient(135deg, #feb2b2 0%, #fc8181 100%);
            color: #9b2c2c;
            border: 1px solid #fc8181;
        }
        
        .status-paid {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        
        .amount-badge {
            font-weight: bold;
            font-size: 1.1rem;
            color: #2d3748;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .sparkline {
            height: 40px;
            width: 100%;
        }
        
        .hover-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .tab-button {
            padding: 8px 16px;
            border-radius: 6px;
            background: transparent;
            border: 2px solid transparent;
            color: #6b7280;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .tab-button:hover:not(.active) {
            background: #f3f4f6;
            color: #374151;
        }
        
        .filter-active {
            border: 2px solid var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger-color);
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table tr:hover {
            background: #f9fafb;
        }
        
        .metric-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
        }
        
        .metric-card .metric-value {
            font-size: 2rem;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .metric-card .metric-label {
            font-size: 0.875rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .metric-card .metric-change {
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .badge-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .bg-gradient-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--purple-color));
        }
        
        .bg-gradient-success {
            background: linear-gradient(135deg, var(--success-color), #48bb78);
        }
        
        .bg-gradient-warning {
            background: linear-gradient(135deg, var(--warning-color), #ed8936);
        }
        
        .bg-gradient-danger {
            background: linear-gradient(135deg, var(--danger-color), #f56565);
        }
        
        .bg-gradient-info {
            background: linear-gradient(135deg, var(--info-color), #63b3ed);
        }
        
        .summary-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-left: 4px solid;
        }
        
        .summary-card.verified {
            border-left-color: var(--success-color);
        }
        
        .summary-card.pending {
            border-left-color: var(--warning-color);
        }
        
        .summary-card.rejected {
            border-left-color: var(--danger-color);
        }
        
        .summary-card.total {
            border-left-color: var(--primary-color);
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-button {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }
        
        .action-button.primary {
            background: var(--primary-color);
            color: white;
        }
        
        .action-button.primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .action-button.secondary {
            background: #f3f4f6;
            color: #374151;
            border-color: #d1d5db;
        }
        
        .action-button.secondary:hover {
            background: #e5e7eb;
        }
        
        .download-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--success-color);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 100;
        }
        
        .download-btn:hover {
            transform: translateY(-5px) scale(1.1);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .loader {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .tooltip {
            position: relative;
            display: inline-block;
        }
        
        .tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background-color: #374151;
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 1000;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.875rem;
            font-weight: normal;
            text-transform: none;
            letter-spacing: normal;
        }
        
        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .responsive-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .responsive-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-button {
                width: 100%;
                justify-content: center;
            }
            
            .chart-container {
                height: 200px;
            }
        }
    </style>
    
    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- ApexCharts Library -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
</head>
<body class="bg-gray-50">
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-credit-card text-green-500"></i>
                <span>Payments Dashboard</span>
                <span class="text-sm font-normal text-gray-500 hidden md:inline">
                    | Monitor and manage all payments
                </span>
            </h1>
            <div class="flex items-center space-x-3">
                <div class="relative">
                    <button onclick="toggleExportMenu()" 
                            class="bg-gradient-to-r from-green-500 to-green-600 text-white px-4 py-2 rounded-lg hover:from-green-600 hover:to-green-700 transition-all action-button shadow-md flex items-center">
                        <i class="fas fa-download mr-2"></i>Export
                    </button>
                    <div id="exportMenu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                        <a href="javascript:exportData('pdf')" class="block px-4 py-3 hover:bg-gray-50 border-b border-gray-100 flex items-center">
                            <i class="fas fa-file-pdf text-red-500 mr-3"></i>PDF Report
                        </a>
                        <a href="javascript:exportData('excel')" class="block px-4 py-3 hover:bg-gray-50 border-b border-gray-100 flex items-center">
                            <i class="fas fa-file-excel text-green-500 mr-3"></i>Excel Report
                        </a>
                        <a href="javascript:exportData('csv')" class="block px-4 py-3 hover:bg-gray-50 flex items-center">
                            <i class="fas fa-file-csv text-blue-500 mr-3"></i>CSV Data
                        </a>
                    </div>
                </div>
                <a href="transaction_approval.php" 
                   class="bg-gradient-to-r from-blue-500 to-purple-600 text-white px-4 py-2 rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all action-button shadow-md flex items-center relative">
                    <i class="fas fa-check-circle mr-2"></i>Approve Payments
                    <?php if ($stats['pending_payments'] > 0): ?>
                        <span class="notification-badge"><?php echo $stats['pending_payments']; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </header>
        
        <!-- Filters -->
        <div class="px-6 py-4">
            <div class="glass-card p-6">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Date Range -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-calendar-alt mr-1"></i>Date Range
                            </label>
                            <select name="date_filter" id="dateFilter" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="today" <?php echo $dateFilter == 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $dateFilter == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="week" <?php echo $dateFilter == 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="month" <?php echo $dateFilter == 'month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="quarter" <?php echo $dateFilter == 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                                <option value="year" <?php echo $dateFilter == 'year' ? 'selected' : ''; ?>>This Year</option>
                                <option value="custom" <?php echo $dateFilter == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        
                        <!-- Custom Date Inputs (hidden by default) -->
                        <div id="customDateRange" class="hidden md:col-span-2">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                                    <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                                    <input type="date" name="date_to" value="<?php echo $dateTo; ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Batch Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-layer-group mr-1"></i>Batch
                            </label>
                            <select name="batch" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Batches</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?php echo htmlspecialchars($batch['batch_id']); ?>"
                                        <?php echo $batchFilter == $batch['batch_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($batch['batch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Status Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-filter mr-1"></i>Status
                            </label>
                            <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Status</option>
                                <option value="verified" <?php echo $statusFilter == 'verified' ? 'selected' : ''; ?>>Verified</option>
                                <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="rejected" <?php echo $statusFilter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Payment Mode Filter -->
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2">
                        <div class="flex items-center">
                            <input type="radio" id="mode_all" name="payment_mode" value="" 
                                   class="hidden peer" <?php echo $paymentModeFilter == '' ? 'checked' : ''; ?>>
                            <label for="mode_all" 
                                   class="w-full px-3 py-2 text-center border rounded-lg cursor-pointer peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-600 hover:bg-gray-50">
                                All Modes
                            </label>
                        </div>
                        <div class="flex items-center">
                            <input type="radio" id="mode_bank" name="payment_mode" value="bank_transfer" 
                                   class="hidden peer" <?php echo $paymentModeFilter == 'bank_transfer' ? 'checked' : ''; ?>>
                            <label for="mode_bank" 
                                   class="w-full px-3 py-2 text-center border rounded-lg cursor-pointer peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-600 hover:bg-gray-50">
                                <i class="fas fa-university mr-1"></i>Bank
                            </label>
                        </div>
                        <div class="flex items-center">
                            <input type="radio" id="mode_cash" name="payment_mode" value="cash" 
                                   class="hidden peer" <?php echo $paymentModeFilter == 'cash' ? 'checked' : ''; ?>>
                            <label for="mode_cash" 
                                   class="w-full px-3 py-2 text-center border rounded-lg cursor-pointer peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-600 hover:bg-gray-50">
                                <i class="fas fa-money-bill-wave mr-1"></i>Cash
                            </label>
                        </div>
                        <div class="flex items-center">
                            <input type="radio" id="mode_cheque" name="payment_mode" value="cheque" 
                                   class="hidden peer" <?php echo $paymentModeFilter == 'cheque' ? 'checked' : ''; ?>>
                            <label for="mode_cheque" 
                                   class="w-full px-3 py-2 text-center border rounded-lg cursor-pointer peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-600 hover:bg-gray-50">
                                <i class="fas fa-sticky-note mr-1"></i>Cheque
                            </label>
                        </div>
                        <div class="flex items-center">
                            <input type="radio" id="mode_other" name="payment_mode" value="other" 
                                   class="hidden peer" <?php echo $paymentModeFilter == 'other' ? 'checked' : ''; ?>>
                            <label for="mode_other" 
                                   class="w-full px-3 py-2 text-center border rounded-lg cursor-pointer peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-600 hover:bg-gray-50">
                                <i class="fas fa-ellipsis-h mr-1"></i>Other
                            </label>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                        <button type="submit" 
                                class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-6 py-2 rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all action-button flex items-center">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                        <a href="?" 
                           class="bg-gradient-to-r from-gray-200 to-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:from-gray-300 hover:to-gray-400 transition-all action-button flex items-center">
                            <i class="fas fa-redo mr-2"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="px-6 pb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <!-- Total Payments Card -->
                <div class="glass-card stat-card p-6" style="animation-delay: 0.1s">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Total Payments</p>
                            <h3 class="text-3xl font-bold text-gray-800">₹<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></h3>
                            <p class="text-xs text-gray-400 mt-1"><?php echo $stats['total_payments'] ?? 0; ?> transactions</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-100 to-blue-200 rounded-full flex items-center justify-center shadow-inner">
                            <i class="fas fa-credit-card text-blue-600 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-green-600">
                                <i class="fas fa-arrow-up"></i> ₹<?php echo number_format($stats['verified_amount'] ?? 0, 2); ?> verified
                            </span>
                            <span class="text-gray-500"><?php echo number_format($collectionRate, 1); ?>%</span>
                        </div>
                        <div class="progress-bar bg-gray-200 rounded-full h-2 mt-1">
                            <div class="bg-blue-500 h-2 rounded-full" style="width: 100%"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Verified Payments Card -->
                <div class="glass-card stat-card p-6" style="animation-delay: 0.2s">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Verified Payments</p>
                            <h3 class="text-3xl font-bold text-green-600">₹<?php echo number_format($stats['verified_amount'] ?? 0, 2); ?></h3>
                            <p class="text-xs text-gray-400 mt-1"><?php echo $stats['verified_payments'] ?? 0; ?> transactions</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 rounded-full flex items-center justify-center shadow-inner">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-green-600">
                                <i class="fas fa-check"></i> Collected
                            </span>
                            <span class="text-gray-500"><?php echo number_format($collectionRate, 1); ?>% rate</span>
                        </div>
                        <div class="progress-bar bg-gray-200 rounded-full h-2 mt-1">
                            <div class="bg-green-500 h-2 rounded-full" style="width: <?php echo $collectionRate; ?>%"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Payments Card -->
                <div class="glass-card stat-card p-6" style="animation-delay: 0.3s">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Pending Payments</p>
                            <h3 class="text-3xl font-bold text-yellow-600">₹<?php echo number_format($stats['pending_amount'] ?? 0, 2); ?></h3>
                            <p class="text-xs text-gray-400 mt-1"><?php echo $stats['pending_payments'] ?? 0; ?> transactions</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-full flex items-center justify-center shadow-inner">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-yellow-600">
                                <i class="fas fa-exclamation-triangle"></i> Needs approval
                            </span>
                            <span class="text-gray-500"><?php echo number_format($pendingRate, 1); ?>% of total</span>
                        </div>
                        <div class="progress-bar bg-gray-200 rounded-full h-2 mt-1">
                            <div class="bg-yellow-500 h-2 rounded-full" style="width: <?php echo $pendingRate; ?>%"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Rejected Payments Card -->
                <div class="glass-card stat-card p-6" style="animation-delay: 0.4s">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Rejected Payments</p>
                            <h3 class="text-3xl font-bold text-red-600">₹<?php echo number_format($stats['rejected_amount'] ?? 0, 2); ?></h3>
                            <p class="text-xs text-gray-400 mt-1"><?php echo $stats['rejected_payments'] ?? 0; ?> transactions</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-br from-red-100 to-red-200 rounded-full flex items-center justify-center shadow-inner">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-red-600">
                                <i class="fas fa-ban"></i> Rejected
                            </span>
                            <span class="text-gray-500"><?php echo number_format($rejectionRate, 1); ?>% of total</span>
                        </div>
                        <div class="progress-bar bg-gray-200 rounded-full h-2 mt-1">
                            <div class="bg-red-500 h-2 rounded-full" style="width: <?php echo $rejectionRate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts and Detailed Information -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Payment Trends Chart -->
                <div class="glass-card p-6 lg:col-span-2">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-chart-line text-blue-500 mr-2"></i>
                        Payment Trends
                        <span class="text-sm font-normal text-gray-500 ml-2">(Last 12 Months)</span>
                    </h3>
                    <div class="chart-container">
                        <canvas id="paymentTrendsChart"></canvas>
                    </div>
                </div>
                
                <!-- Payment Mode Distribution -->
                <div class="glass-card p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-money-check-alt text-green-500 mr-2"></i>
                        Payment Methods
                    </h3>
                    <div class="space-y-4">
                        <?php foreach ($paymentModes as $mode): 
                            $percentage = $mode['total_amount'] > 0 ? ($mode['total_amount'] / $stats['total_amount'] * 100) : 0;
                        ?>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="font-medium">
                                    <?php echo ucfirst(str_replace('_', ' ', $mode['payment_mode'])); ?>
                                </span>
                                <span class="text-gray-600"><?php echo number_format($percentage, 1); ?>%</span>
                            </div>
                            <div class="progress-bar bg-gray-200 rounded-full h-2">
                                <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                            <div class="flex justify-between text-xs text-gray-500 mt-1">
                                <span>₹<?php echo number_format($mode['total_amount'], 2); ?></span>
                                <span><?php echo $mode['count']; ?> payments</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Tabs for Different Views -->
            <div class="mb-6">
                <div class="flex space-x-4 border-b border-gray-200">
                    <button id="tabRecent" class="tab-button active" onclick="switchTab('recent')">
                        <i class="fas fa-history mr-2"></i>Recent Payments
                    </button>
                    <button id="tabPending" class="tab-button" onclick="switchTab('pending')">
                        <i class="fas fa-clock mr-2"></i>Pending Installments
                        <?php if (count($pendingInstallments) > 0): ?>
                            <span class="badge-count bg-red-500 text-white"><?php echo count($pendingInstallments); ?></span>
                        <?php endif; ?>
                    </button>
                    <button id="tabStudents" class="tab-button" onclick="switchTab('students')">
                        <i class="fas fa-users mr-2"></i>Student Fees
                    </button>
                    <button id="tabBatches" class="tab-button" onclick="switchTab('batches')">
                        <i class="fas fa-layer-group mr-2"></i>Batch Summary
                    </button>
                </div>
                
                <!-- Recent Payments Tab -->
                <div id="tabContentRecent" class="tab-content active">
                    <div class="glass-card overflow-hidden mt-4">
                        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">
                                Recent Payment Transactions
                            </h3>
                            <span class="text-sm text-gray-500">
                                Showing last <?php echo count($recentPayments); ?> transactions
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Transaction ID</th>
                                        <th>Student</th>
                                        <th>Batch</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPayments as $payment): ?>
                                    <tr class="hover-card">
                                        <td>
                                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($payment['transaction_id']); ?></div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('M d, Y H:i', strtotime($payment['uploaded_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="font-medium"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($payment['student_id']); ?></div>
                                        </td>
                                        <td>
                                            <div class="text-sm"><?php echo htmlspecialchars($payment['batch_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($payment['batch_id']); ?></div>
                                        </td>
                                        <td>
                                            <span class="amount-badge">₹<?php echo number_format($payment['amount'], 2); ?></span>
                                            <div class="text-xs text-gray-500">
                                                <?php echo ucfirst($payment['payment_mode']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-sm"><?php echo date('M d, Y', strtotime($payment['transaction_date'])); ?></div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $payment['status']; ?>">
                                                <?php echo ucfirst($payment['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button onclick="viewTransaction(<?php echo $payment['id']; ?>)" 
                                                        class="action-button secondary">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($payment['status'] == 'pending'): ?>
                                                <button onclick="approveTransaction(<?php echo $payment['id']; ?>)" 
                                                        class="action-button primary">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <?php if (empty($recentPayments)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-receipt"></i>
                                    <h4 class="text-lg font-medium text-gray-900 mb-2">No recent payments</h4>
                                    <p class="text-gray-500">No payment transactions found for the selected period.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Installments Tab -->
                <div id="tabContentPending" class="tab-content">
                    <div class="glass-card overflow-hidden mt-4">
                        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">
                                Pending Fee Installments
                            </h3>
                            <span class="text-sm text-gray-500">
                                <?php echo count($pendingInstallments); ?> pending installments
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Batch</th>
                                        <th>Installment</th>
                                        <th>Amount</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingInstallments as $installment): ?>
                                    <tr class="hover-card">
                                        <td>
                                            <div class="font-medium"><?php echo htmlspecialchars($installment['first_name'] . ' ' . $installment['last_name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($installment['student_id']); ?></div>
                                        </td>
                                        <td>
                                            <div class="text-sm"><?php echo htmlspecialchars($installment['batch_full_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($installment['batch_name']); ?></div>
                                        </td>
                                        <td>
                                            <div class="font-medium">#<?php echo $installment['installment_number']; ?></div>
                                            <div class="text-xs text-gray-500">
                                                Total: ₹<?php echo number_format($installment['installment_amount'], 2); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="amount-badge">₹<?php echo number_format($installment['installment_amount'] - $installment['paid_amount'], 2); ?></span>
                                            <div class="text-xs text-gray-500">
                                                Paid: ₹<?php echo number_format($installment['paid_amount'], 2); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-sm"><?php echo date('M d, Y', strtotime($installment['due_date'])); ?></div>
                                            <div class="text-xs <?php echo $installment['due_status'] == 'overdue' ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>">
                                                <?php 
                                                if ($installment['due_status'] == 'overdue') {
                                                    echo abs($installment['days_remaining']) . ' days overdue';
                                                } elseif ($installment['due_status'] == 'due_soon') {
                                                    echo $installment['days_remaining'] . ' days remaining';
                                                } else {
                                                    echo $installment['days_remaining'] . ' days remaining';
                                                }
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $installment['due_status'] == 'overdue' ? 'overdue' : $installment['payment_status']; ?>">
                                                <?php echo ucfirst($installment['due_status'] == 'overdue' ? 'Overdue' : $installment['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button onclick="sendReminder('<?php echo $installment['student_id']; ?>', <?php echo $installment['id']; ?>)" 
                                                        class="action-button secondary">
                                                    <i class="fas fa-bell"></i> Remind
                                                </button>
                                                <button onclick="recordPayment(<?php echo $installment['id']; ?>)" 
                                                        class="action-button primary">
                                                    <i class="fas fa-money-bill-wave"></i> Record
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <?php if (empty($pendingInstallments)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle text-green-500"></i>
                                    <h4 class="text-lg font-medium text-gray-900 mb-2">All clear!</h4>
                                    <p class="text-gray-500">No pending installments at the moment.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Student Fees Tab -->
                <div id="tabContentStudents" class="tab-content">
                    <div class="glass-card overflow-hidden mt-4">
                        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">
                                Student Fee Overview
                            </h3>
                            <div class="flex items-center space-x-4">
                                <div class="text-sm text-gray-500">
                                    Total Students: <?php echo count($studentFeeOverview); ?>
                                </div>
                                <input type="text" id="studentSearch" placeholder="Search students..." 
                                       class="px-3 py-1 border border-gray-300 rounded-lg text-sm">
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Batch</th>
                                        <th>Enrollment Fees</th>
                                        <th>Paid Amount</th>
                                        <th>Balance</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th>Last Payment</th>
                                    </tr>
                                </thead>
                                <tbody id="studentTableBody">
                                    <?php foreach ($studentFeeOverview as $student): 
                                        $balance = $student['enrollment_fees'] - $student['total_fees_paid'];
                                        $progress = $student['enrollment_fees'] > 0 ? ($student['total_fees_paid'] / $student['enrollment_fees'] * 100) : 0;
                                    ?>
                                    <tr class="hover-card">
                                        <td>
                                            <div class="font-medium"><?php echo htmlspecialchars($student['student_name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['student_id']); ?></div>
                                        </td>
                                        <td>
                                            <div class="text-sm"><?php echo htmlspecialchars($student['batch_full_name']); ?></div>
                                        </td>
                                        <td>
                                            <span class="font-medium">₹<?php echo number_format($student['enrollment_fees'], 2); ?></span>
                                        </td>
                                        <td>
                                            <span class="font-medium text-green-600">₹<?php echo number_format($student['total_fees_paid'], 2); ?></span>
                                        </td>
                                        <td>
                                            <span class="font-medium <?php echo $balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                                ₹<?php echo number_format($balance, 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex items-center space-x-2">
                                                <div class="w-24 bg-gray-200 rounded-full h-2">
                                                    <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo min($progress, 100); ?>%"></div>
                                                </div>
                                                <span class="text-sm text-gray-600"><?php echo number_format($progress, 1); ?>%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $student['fees_status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $student['fees_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="text-sm">
                                                <?php echo $student['last_payment_date'] ? date('M d, Y', strtotime($student['last_payment_date'])) : 'Never'; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Batch Summary Tab -->
                <div id="tabContentBatches" class="tab-content">
                    <div class="responsive-grid mt-4">
                        <?php foreach ($paymentsByBatch as $batch): 
                            if ($batch['total_amount'] > 0): ?>
                            <div class="glass-card p-6 hover-card">
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($batch['batch_name']); ?></h4>
                                        <p class="text-sm text-gray-500">Batch ID: <?php echo htmlspecialchars($batch['batch_id']); ?></p>
                                    </div>
                                    <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded">
                                        <?php echo $batch['payment_count']; ?> payments
                                    </span>
                                </div>
                                
                                <div class="space-y-3">
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span class="text-gray-600">Total Collected</span>
                                            <span class="font-semibold">₹<?php echo number_format($batch['total_amount'], 2); ?></span>
                                        </div>
                                        <div class="progress-bar bg-gray-200 rounded-full h-2">
                                            <div class="bg-green-500 h-2 rounded-full" 
                                                 style="width: <?php echo ($batch['verified_amount'] / $batch['total_amount'] * 100); ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-2 text-sm">
                                        <div class="text-center p-2 bg-green-50 rounded">
                                            <div class="font-semibold text-green-700">₹<?php echo number_format($batch['verified_amount'], 2); ?></div>
                                            <div class="text-xs text-green-600">Verified</div>
                                        </div>
                                        <div class="text-center p-2 bg-yellow-50 rounded">
                                            <div class="font-semibold text-yellow-700">₹<?php echo number_format($batch['pending_amount'], 2); ?></div>
                                            <div class="text-xs text-yellow-600">Pending</div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center pt-4 border-t border-gray-100">
                                        <button onclick="viewBatchDetails('<?php echo $batch['batch_id']; ?>')" 
                                                class="action-button secondary w-full">
                                            <i class="fas fa-chart-bar mr-2"></i>View Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Top Paying Students -->
            <div class="glass-card overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-trophy text-yellow-500 mr-2"></i>
                        Top Paying Students
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Student</th>
                                <th>Batch</th>
                                <th>Total Paid</th>
                                <th>Enrollment Fees</th>
                                <th>Progress</th>
                                <th>Last Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topPayingStudents as $index => $student): 
                                $progress = $student['enrollment_fees'] > 0 ? ($student['total_fees_paid'] / $student['enrollment_fees'] * 100) : 0;
                            ?>
                            <tr class="hover-card">
                                <td>
                                    <div class="flex items-center justify-center">
                                        <?php if ($index < 3): ?>
                                            <span class="w-8 h-8 rounded-full bg-yellow-100 text-yellow-800 flex items-center justify-center font-bold">
                                                <?php echo $index + 1; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="w-8 h-8 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center font-bold">
                                                <?php echo $index + 1; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="font-medium"><?php echo htmlspecialchars($student['student_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['student_id']); ?></div>
                                </td>
                                <td>
                                    <div class="text-sm"><?php echo htmlspecialchars($student['batch_full_name']); ?></div>
                                </td>
                                <td>
                                    <span class="amount-badge">₹<?php echo number_format($student['total_fees_paid'], 2); ?></span>
                                </td>
                                <td>
                                    <span class="font-medium">₹<?php echo number_format($student['enrollment_fees'], 2); ?></span>
                                </td>
                                <td>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-32 bg-gray-200 rounded-full h-2">
                                            <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo min($progress, 100); ?>%"></div>
                                        </div>
                                        <span class="text-sm text-gray-600"><?php echo number_format($progress, 1); ?>%</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-sm">
                                        <?php echo $student['last_payment_date'] ? date('M d, Y', strtotime($student['last_payment_date'])) : 'Never'; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Daily Collection Summary -->
            <?php if (!empty($feeCollectionSummary)): ?>
            <div class="glass-card overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-calendar-day text-purple-500 mr-2"></i>
                        Daily Collection Summary (<?php echo date('M d, Y', strtotime($dateFrom)); ?> - <?php echo date('M d, Y', strtotime($dateTo)); ?>)
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transactions</th>
                                <th>Total Collected</th>
                                <th>Bank Transfer</th>
                                <th>Cash</th>
                                <th>Cheque</th>
                                <th>Other</th>
                                <th>Unique Students</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feeCollectionSummary as $summary): ?>
                            <tr>
                                <td class="font-medium"><?php echo date('M d, Y', strtotime($summary['collection_date'])); ?></td>
                                <td><?php echo $summary['transaction_count']; ?></td>
                                <td class="font-semibold text-green-600">₹<?php echo number_format($summary['total_collected'], 2); ?></td>
                                <td>₹<?php echo number_format($summary['bank_transfer_total'], 2); ?></td>
                                <td>₹<?php echo number_format($summary['cash_total'], 2); ?></td>
                                <td>₹<?php echo number_format($summary['cheque_total'], 2); ?></td>
                                <td>₹<?php echo number_format($summary['other_total'], 2); ?></td>
                                <td><?php echo $summary['unique_students']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Quick Export Button -->
    <div class="download-btn tooltip" onclick="quickExport()">
        <i class="fas fa-download"></i>
        <span class="tooltip-text">Export Dashboard Data</span>
    </div>
    
    <?php include '../footer.php'; ?>
    
    <script>
        // Initialize animations
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Initialize charts
            initializeCharts();
            
            // Handle date filter toggle
            document.getElementById('dateFilter').addEventListener('change', function() {
                const customRange = document.getElementById('customDateRange');
                if (this.value === 'custom') {
                    customRange.classList.remove('hidden');
                    customRange.style.gridColumn = 'span 2';
                } else {
                    customRange.classList.add('hidden');
                }
            });
            
            // Initialize date filter
            if (document.getElementById('dateFilter').value === 'custom') {
                document.getElementById('customDateRange').classList.remove('hidden');
            }
            
            // Initialize student search
            const studentSearch = document.getElementById('studentSearch');
            if (studentSearch) {
                studentSearch.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('#studentTableBody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            }
        });
        
        // Tab switching function
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(`tabContent${tabName.charAt(0).toUpperCase() + tabName.slice(1)}`).classList.add('active');
            document.getElementById(`tab${tabName.charAt(0).toUpperCase() + tabName.slice(1)}`).classList.add('active');
        }
        
        // Initialize charts
        function initializeCharts() {
            // Payment Trends Chart
            const trendsCtx = document.getElementById('paymentTrendsChart').getContext('2d');
            const trendsData = {
                labels: <?php echo json_encode(array_column($paymentTrends, 'period')); ?>.reverse(),
                datasets: [{
                    label: 'Total Amount',
                    data: <?php echo json_encode(array_column($paymentTrends, 'total_amount')); ?>.reverse(),
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Verified Amount',
                    data: <?php echo json_encode(array_column($paymentTrends, 'verified_amount')); ?>.reverse(),
                    borderColor: '#38a169',
                    backgroundColor: 'rgba(56, 161, 105, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            };
            
            new Chart(trendsCtx, {
                type: 'line',
                data: trendsData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ₹${context.parsed.y.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value.toLocaleString('en-IN');
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'nearest'
                    }
                }
            });
        }
        
        // Export functions
        function toggleExportMenu() {
            const menu = document.getElementById('exportMenu');
            menu.classList.toggle('hidden');
        }
        
        function exportData(format) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', format);
            
            switch (format) {
                case 'pdf':
                    window.open(`export_payments.php?${params.toString()}`, '_blank');
                    break;
                case 'excel':
                    window.open(`export_payments.php?${params.toString()}`, '_blank');
                    break;
                case 'csv':
                    window.open(`export_payments.php?${params.toString()}`, '_blank');
                    break;
            }
            
            // Hide menu
            document.getElementById('exportMenu').classList.add('hidden');
        }
        
        function quickExport() {
            exportData('pdf');
        }
        
        // Action functions
        function viewTransaction(id) {
            window.open(`get_transactions_info.php?id=${id}`, '_blank');
        }
        
        function approveTransaction(id) {
            if (confirm('Approve this transaction?')) {
                window.location.href = `transaction_approval.php?approve=${id}&confirm=yes`;
            }
        }
        
        function sendReminder(studentId, installmentId) {
            const message = prompt('Enter reminder message:', 'Gentle reminder: Your installment is due. Please make the payment at your earliest convenience.');
            if (message) {
                // Send reminder via AJAX
                fetch('send_reminder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        student_id: studentId,
                        installment_id: installmentId,
                        message: message
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Reminder sent successfully!');
                    } else {
                        alert('Failed to send reminder: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to send reminder.');
                });
            }
        }
        
        function recordPayment(installmentId) {
            window.open(`record_payment.php?installment_id=${installmentId}`, '_blank');
        }
        
        function viewBatchDetails(batchId) {
            window.open(`batch_payments.php?batch_id=${batchId}`, '_blank');
        }
        
        // Close export menu when clicking outside
        document.addEventListener('click', function(event) {
            const exportMenu = document.getElementById('exportMenu');
            const exportButton = document.querySelector('button[onclick="toggleExportMenu()"]');
            
            if (!exportMenu.contains(event.target) && !exportButton.contains(event.target)) {
                exportMenu.classList.add('hidden');
            }
        });
        
        // Refresh page every 60 seconds for live updates
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>e