<?php
// absent_reasons.php
include '../db_connection.php';
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}

// Get current date for default filtering
$current_date = date('Y-m-d');
$selected_date = isset($_GET['date']) ? $_GET['date'] : $current_date;
$selected_batch = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';

// Handle remark updates via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    if ($_POST['action'] === 'update_remarks') {
        $attendance_id = $_POST['attendance_id'];
        $remarks = trim($_POST['remarks']);
        
        try {
            $stmt = $db->prepare("UPDATE attendance SET remarks = ? WHERE id = ?");
            $stmt->execute([$remarks, $attendance_id]);
            
            $response['success'] = true;
            $response['message'] = "Remarks updated successfully!";
            $response['remarks'] = nl2br(htmlspecialchars($remarks));
        } catch (PDOException $e) {
            $response['message'] = "Error updating remarks: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'mark_as_present') {
        $attendance_id = $_POST['attendance_id'];
        $remarks = trim($_POST['remarks'] ?? '');
        
        try {
            $stmt = $db->prepare("UPDATE attendance SET status = 'Present', remarks = ? WHERE id = ?");
            $stmt->execute([$remarks, $attendance_id]);
            
            $response['success'] = true;
            $response['message'] = "Student marked as present!";
        } catch (PDOException $e) {
            $response['message'] = "Error updating status: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'bulk_update') {
        if (isset($_POST['attendance_ids']) && !empty($_POST['attendance_ids'])) {
            $attendance_ids = $_POST['attendance_ids'];
            $bulk_remarks = trim($_POST['bulk_remarks']);
            $bulk_status = $_POST['bulk_status'] ?? 'Absent';
            
            try {
                $placeholders = implode(',', array_fill(0, count($attendance_ids), '?'));
                $sql = "UPDATE attendance SET remarks = ?, status = ? WHERE id IN ($placeholders)";
                $stmt = $db->prepare($sql);
                $stmt->execute(array_merge([$bulk_remarks, $bulk_status], $attendance_ids));
                
                $response['success'] = true;
                $response['message'] = "Bulk update completed for " . count($attendance_ids) . " student(s)!";
            } catch (PDOException $e) {
                $response['message'] = "Error in bulk update: " . $e->getMessage();
            }
        } else {
            $response['message'] = "No students selected for bulk update.";
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get all batches for filter dropdown
$batch_stmt = $db->prepare("SELECT batch_id, batch_name FROM batches WHERE status IN ('ongoing', 'upcoming') ORDER BY batch_id");
$batch_stmt->execute();
$batches = $batch_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for absent students with filters
$query = "SELECT a.*, s.email, s.phone_number, s.batch_name, s.father_name, s.father_phone_number 
          FROM attendance a 
          LEFT JOIN students s ON a.student_id = s.student_id 
          WHERE a.status = 'Absent'";
$params = [];

if (!empty($selected_date)) {
    $query .= " AND a.date = ?";
    $params[] = $selected_date;
}

if (!empty($selected_batch)) {
    $query .= " AND a.batch_id = ?";
    $params[] = $selected_batch;
}

$query .= " ORDER BY a.date DESC, a.student_name ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$absent_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_absent,
                COUNT(CASE WHEN remarks IS NOT NULL AND remarks != '' THEN 1 END) as with_remarks,
                COUNT(CASE WHEN (remarks IS NULL OR remarks = '') THEN 1 END) as without_remarks
                FROM attendance 
                WHERE status = 'Absent'";
                
if (!empty($selected_date)) {
    $stats_query .= " AND date = ?";
}

$stats_stmt = $db->prepare($stats_query);
$stats_params = !empty($selected_date) ? [$selected_date] : [];
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absent Students & Remarks - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #3B82F6;
            --primary-light: #EFF6FF;
            --secondary: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
            --info: #6366F1;
            --dark: #1F2937;
            --light: #F9FAFB;
            --gray: #6B7280;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #F3F4F6;
            color: var(--dark);
            margin: 0;
            padding: 0;
        }
        
        /* Main Content Area - Matching dashboard.php */
        .flex-1 {
            flex: 1;
        }
        
        .ml-0 {
            margin-left: 0;
        }
        
        .md\:ml-64 {
            margin-left: 0;
        }
        
        @media (min-width: 768px) {
            .md\:ml-64 {
                margin-left: 16rem;
            }
        }
        
        .min-h-screen {
            min-height: 100vh;
        }
        
        .p-4 {
            padding: 1rem;
        }
        
        .md\:p-6 {
            padding: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .p-4 {
                padding: 1.5rem;
            }
            .md\:p-6 {
                padding: 1.5rem;
            }
        }
        
        /* Header */
        header {
            background-color: white;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 30;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1F2937;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }
        
        /* Grid layouts matching dashboard.php */
        .grid {
            display: grid;
        }
        
        .grid-cols-1 {
            grid-template-columns: 1fr;
        }
        
        .sm\:grid-cols-2 {
            grid-template-columns: 1fr;
        }
        
        .lg\:grid-cols-4 {
            grid-template-columns: 1fr;
        }
        
        @media (min-width: 640px) {
            .sm\:grid-cols-2 {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .lg\:grid-cols-4 {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .gap-4 {
            gap: 1rem;
        }
        
        .gap-6 {
            gap: 1.5rem;
        }
        
        .mb-6 {
            margin-bottom: 1.5rem;
        }
        
        /* Metric Cards - Matching dashboard.php */
        .metric-card {
            background-color: white;
            padding: 1.25rem;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            border-left-width: 4px;
            border-left-style: solid;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 0;
            background: linear-gradient(to bottom, var(--primary), var(--info));
            transition: height 0.3s ease;
        }
        
        .metric-card:hover::before {
            height: 100%;
        }
        
        .border-l-blue-500 {
            border-left-color: #3B82F6;
        }
        
        .border-l-red-500 {
            border-left-color: #EF4444;
        }
        
        .border-l-green-500 {
            border-left-color: #10B981;
        }
        
        .border-l-yellow-500 {
            border-left-color: #F59E0B;
        }
        
        .border-l-purple-500 {
            border-left-color: #8B5CF6;
        }
        
        /* Filters Card */
        .filters-card {
            background-color: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }
        
        .filter-form {
            display: grid;
            gap: 1rem;
            align-items: end;
        }
        
        @media (min-width: 640px) {
            .filter-form {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.625rem 0.75rem;
            border: 1px solid #D1D5DB;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* Buttons */
        .btn {
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            height: 2.75rem;
        }
        
        .btn-primary {
            background-color: #3B82F6;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2563EB;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3);
        }
        
        .btn-outline {
            background-color: transparent;
            color: #3B82F6;
            border: 2px solid #3B82F6;
        }
        
        .btn-outline:hover {
            background-color: #3B82F6;
            color: white;
        }
        
        .btn-success {
            background-color: #10B981;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #059669;
        }
        
        /* Table Container */
        .table-container {
            background-color: white;
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }
        
        .table-header {
            background-color: #F8FAFC;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #E5E7EB;
        }
        
        .table-header h3 {
            margin: 0;
            font-size: 1.125rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background-color: #F8FAFC;
        }
        
        th {
            padding: 0.875rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #E5E7EB;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid #E5E7EB;
            vertical-align: middle;
        }
        
        tbody tr {
            transition: background-color 0.2s ease;
        }
        
        tbody tr:hover {
            background-color: #F9FAFB;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-absent {
            background-color: #FEE2E2;
            color: #DC2626;
        }
        
        .status-present {
            background-color: #D1FAE5;
            color: #065F46;
        }
        
        /* Checkbox */
        .checkbox-cell {
            width: 50px;
            text-align: center;
        }
        
        /* Remarks Area - Enhanced */
        .remarks-container {
            position: relative;
            width: 100%;
        }
        
        .remarks-display {
            min-height: 60px;
            padding: 0.75rem;
            border-radius: 0.5rem;
            background-color: #F8FAFC;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .remarks-display:hover {
            background-color: #F1F5F9;
            border-color: #D1D5DB;
        }
        
        .remarks-display.empty {
            color: #9CA3AF;
            font-style: italic;
            border: 1px dashed #D1D5DB;
        }
        
        .remarks-display.empty:hover {
            background-color: #F3F4F6;
            border-color: #9CA3AF;
        }
        
        .remarks-display .remarks-content {
            white-space: pre-wrap;
            word-break: break-word;
        }
        
        .remarks-edit-container {
            position: relative;
            width: 100%;
        }
        
        .remarks-textarea {
            width: 100%;
            min-height: 100px;
            padding: 0.75rem;
            border: 2px solid #3B82F6;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-family: 'Inter', sans-serif;
            resize: vertical;
            transition: all 0.2s ease;
        }
        
        .remarks-textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .remarks-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        
        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .action-btn.save {
            background-color: #10B981;
            color: white;
        }
        
        .action-btn.save:hover {
            background-color: #059669;
            transform: translateY(-1px);
        }
        
        .action-btn.present {
            background-color: #3B82F6;
            color: white;
        }
        
        .action-btn.present:hover {
            background-color: #2563EB;
            transform: translateY(-1px);
        }
        
        .action-btn.cancel {
            background-color: #F3F4F6;
            color: #4B5563;
        }
        
        .action-btn.cancel:hover {
            background-color: #E5E7EB;
        }
        
        /* Bulk Actions */
        .bulk-actions {
            background-color: #FFFBEB;
            padding: 1.25rem;
            margin: 1rem 1.5rem;
            border-radius: 0.5rem;
            border: 2px dashed #F59E0B;
        }
        
        .bulk-actions h4 {
            margin-top: 0;
            margin-bottom: 1rem;
            color: #92400E;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
        }
        
        .bulk-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            align-items: end;
        }
        
        @media (min-width: 768px) {
            .bulk-form {
                grid-template-columns: 1fr auto auto;
            }
        }
        
        /* Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }
        
        .alert-success {
            background-color: #D1FAE5;
            color: #065F46;
            border-left: 4px solid #10B981;
        }
        
        .alert-error {
            background-color: #FEE2E2;
            color: #991B1B;
            border-left: 4px solid #EF4444;
        }
        
        .alert-info {
            background-color: #E0F2FE;
            color: #075985;
            border-left: 4px solid #0EA5E9;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* No Data State */
        .no-data {
            text-align: center;
            padding: 4rem 2rem;
            color: #6B7280;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #D1D5DB;
        }
        
        .no-data h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3B82F6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive Adjustments */
        @media (max-width: 640px) {
            td, th {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
            }
            
            .bulk-form {
                grid-template-columns: 1fr;
            }
        }
        
        /* Highlight Animation */
        .highlight-update {
            animation: highlight 1.5s ease;
        }
        
        @keyframes highlight {
            0% { background-color: rgba(59, 130, 246, 0.2); }
            100% { background-color: transparent; }
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <!-- Header -->
        <header>
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1>
                <i class="fas fa-user-times text-red-500"></i>
                Absent Students & Remarks
            </h1>
            <div class="flex items-center space-x-4">
                <a href="../dashboard/dashboard.php" class="text-sm text-blue-600 hover:underline flex items-center space-x-1">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </header>

        <div class="p-4 md:p-6">
            <!-- Statistics -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <a href="#" class="metric-card border-l-red-500" onclick="filterWithoutRemarks()">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Absent</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1"><?php echo $stats['total_absent'] ?? 0; ?></h3>
                        </div>
                        <div class="p-3 rounded-full bg-red-100 text-red-600">
                            <i class="fas fa-user-times text-lg"></i>
                        </div>
                    </div>
                </a>
                
                <a href="#" class="metric-card border-l-green-500" onclick="filterWithRemarks()">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500">With Remarks</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1"><?php echo $stats['with_remarks'] ?? 0; ?></h3>
                        </div>
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-comment-check text-lg"></i>
                        </div>
                    </div>
                </a>
                
                <a href="#" class="metric-card border-l-yellow-500" onclick="filterWithoutRemarks()">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Without Remarks</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1"><?php echo $stats['without_remarks'] ?? 0; ?></h3>
                        </div>
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <i class="fas fa-comment-slash text-lg"></i>
                        </div>
                    </div>
                </a>
                
                <div class="metric-card border-l-blue-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Selected Date</p>
                            <h3 class="text-lg font-bold text-gray-800 mt-1"><?php echo date('M j, Y', strtotime($selected_date)); ?></h3>
                        </div>
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-calendar-day text-lg"></i>
                        </div>
                    </div>
                </div>
                
                <div class="metric-card border-l-purple-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Showing</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1"><?php echo count($absent_students); ?></h3>
                            <p class="text-xs text-gray-500 mt-1">Students</p>
                        </div>
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-eye text-lg"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="" class="filter-form">
                    <div class="form-group">
                        <label for="date"><i class="fas fa-calendar"></i> Select Date</label>
                        <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" 
                               onchange="this.form.submit()">
                    </div>
                    
                    <div class="form-group">
                        <label for="batch_id"><i class="fas fa-layer-group"></i> Select Batch</label>
                        <select id="batch_id" name="batch_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Batches</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?php echo htmlspecialchars($batch['batch_id']); ?>" 
                                    <?php echo ($selected_batch == $batch['batch_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($batch['batch_name'] . ' (' . $batch['batch_id'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="absent_reasons.php" class="btn btn-outline">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <?php if (!empty($absent_students)): ?>
            <div class="bulk-actions">
                <h4><i class="fas fa-bulk"></i> Bulk Actions</h4>
                <div class="bulk-form">
                    <div>
                        <input type="text" id="bulk_remarks" class="form-control" placeholder="Enter common remarks for selected students...">
                    </div>
                    <div>
                        <select id="bulk_status" class="form-control">
                            <option value="Absent">Keep as Absent</option>
                            <option value="Present">Mark as Present</option>
                        </select>
                    </div>
                    <div>
                        <button type="button" class="btn btn-success" onclick="performBulkUpdate()">
                            <i class="fas fa-check-double"></i> Apply to Selected
                        </button>
                    </div>
                </div>
                <div class="mt-2 text-xs text-gray-600">
                    <i class="fas fa-info-circle"></i> Select students using checkboxes below
                </div>
            </div>
            <?php endif; ?>

            <!-- Absent Students Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>
                        <i class="fas fa-users-slash"></i>
                        Absent Students List
                        <span class="text-sm text-gray-600 font-normal">
                            <?php if (!empty($selected_date)): ?>
                                - <?php echo date('F j, Y', strtotime($selected_date)); ?>
                            <?php endif; ?>
                        </span>
                    </h3>
                </div>
                
                <div class="table-responsive">
                    <?php if (!empty($absent_students)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th class="checkbox-cell">
                                        <input type="checkbox" id="select-all" onclick="toggleSelectAll()">
                                    </th>
                                    <th>Student Details</th>
                                    <th>Batch & Date</th>
                                    <th>Contact Information</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($absent_students as $student): ?>
                                <tr id="row-<?php echo $student['id']; ?>" data-id="<?php echo $student['id']; ?>">
                                    <td class="checkbox-cell">
                                        <input type="checkbox" name="attendance_ids[]" value="<?php echo $student['id']; ?>" 
                                               class="student-checkbox" onchange="updateSelectedCount()">
                                    </td>
                                    <td>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($student['student_name']); ?></div>
                                        <div class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($student['student_id']); ?></div>
                                        <span class="status-badge status-absent mt-1">Absent</span>
                                    </td>
                                    <td>
                                        <div class="font-medium"><?php echo htmlspecialchars($student['batch_id']); ?></div>
                                        <div class="text-sm text-gray-600"><?php echo htmlspecialchars($student['batch_name'] ?? 'N/A'); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo date('M j, Y', strtotime($student['date'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="space-y-1">
                                            <?php if ($student['email']): ?>
                                                <div class="text-sm">
                                                    <i class="fas fa-envelope text-gray-400 mr-1"></i>
                                                    <span class="text-gray-700"><?php echo htmlspecialchars($student['email']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($student['phone_number']): ?>
                                                <div class="text-sm">
                                                    <i class="fas fa-phone text-gray-400 mr-1"></i>
                                                    <span class="text-gray-700"><?php echo htmlspecialchars($student['phone_number']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($student['father_name']): ?>
                                                <div class="text-xs text-gray-500">
                                                    <i class="fas fa-user-friends mr-1"></i>
                                                    <?php echo htmlspecialchars($student['father_name']); ?>
                                                    <?php if ($student['father_phone_number']): ?>
                                                        (<?php echo htmlspecialchars($student['father_phone_number']); ?>)
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="remarks-container">
                                            <div class="remarks-display <?php echo empty($student['remarks']) ? 'empty' : ''; ?>" 
                                                 onclick="editRemarks(<?php echo $student['id']; ?>)">
                                                <div class="remarks-content" id="remarks-display-<?php echo $student['id']; ?>">
                                                    <?php if (!empty($student['remarks'])): ?>
                                                        <?php echo nl2br(htmlspecialchars($student['remarks'])); ?>
                                                    <?php else: ?>
                                                        Click to add remarks...
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="remarks-edit-container" id="remarks-edit-<?php echo $student['id']; ?>" style="display: none;">
                                                <textarea class="remarks-textarea" id="remarks-textarea-<?php echo $student['id']; ?>" 
                                                          placeholder="Enter remarks for this absence..."><?php echo htmlspecialchars($student['remarks'] ?? ''); ?></textarea>
                                                <div class="remarks-actions">
                                                    <button type="button" class="action-btn save" 
                                                            onclick="saveRemarks(<?php echo $student['id']; ?>)">
                                                        <i class="fas fa-save"></i> Save
                                                    </button>
                                                    <button type="button" class="action-btn present" 
                                                            onclick="markAsPresent(<?php echo $student['id']; ?>)">
                                                        <i class="fas fa-user-check"></i> Mark as Present
                                                    </button>
                                                    <button type="button" class="action-btn cancel" 
                                                            onclick="cancelEdit(<?php echo $student['id']; ?>)">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-user-check"></i>
                            <h3>No Absent Students Found</h3>
                            <p>There are no absent students for the selected criteria.</p>
                            <a href="absent_reasons.php" class="btn btn-primary mt-4">
                                <i class="fas fa-redo"></i> View All Absentees
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let selectedCount = 0;
        
        // Toggle select all checkboxes
        function toggleSelectAll() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            updateSelectedCount();
        }
        
        // Update selected count
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            selectedCount = checkboxes.length;
            document.getElementById('select-all').checked = selectedCount === document.querySelectorAll('.student-checkbox').length;
            
            // Update UI if needed
            const bulkActions = document.querySelector('.bulk-actions');
            if (selectedCount > 0) {
                bulkActions.classList.add('highlight-update');
                setTimeout(() => bulkActions.classList.remove('highlight-update'), 1500);
            }
        }
        
        // Edit remarks inline
        function editRemarks(studentId) {
            // Hide all other edit modes first
            document.querySelectorAll('.remarks-edit-container').forEach(el => {
                el.style.display = 'none';
            });
            document.querySelectorAll('.remarks-display').forEach(el => {
                el.style.display = 'block';
            });
            
            // Show edit mode for this student
            const display = document.getElementById('remarks-display-' + studentId).parentElement;
            const editContainer = document.getElementById('remarks-edit-' + studentId);
            
            display.style.display = 'none';
            editContainer.style.display = 'block';
            
            // Focus on textarea
            const textarea = document.getElementById('remarks-textarea-' + studentId);
            textarea.focus();
            textarea.setSelectionRange(textarea.value.length, textarea.value.length);
            
            // Highlight the row
            const row = document.getElementById('row-' + studentId);
            row.classList.add('highlight-update');
        }
        
        // Cancel edit mode
        function cancelEdit(studentId) {
            const display = document.getElementById('remarks-display-' + studentId).parentElement;
            const editContainer = document.getElementById('remarks-edit-' + studentId);
            
            editContainer.style.display = 'none';
            display.style.display = 'block';
        }
        
        // Save remarks via AJAX
        function saveRemarks(studentId) {
            const remarks = document.getElementById('remarks-textarea-' + studentId).value.trim();
            
            showLoading();
            
            fetch('absent_reasons.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=update_remarks&attendance_id=' + studentId + '&remarks=' + encodeURIComponent(remarks)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    // Update display
                    const display = document.getElementById('remarks-display-' + studentId);
                    const displayContainer = display.parentElement;
                    const editContainer = document.getElementById('remarks-edit-' + studentId);
                    
                    if (remarks) {
                        display.innerHTML = data.remarks;
                        displayContainer.classList.remove('empty');
                    } else {
                        display.innerHTML = 'Click to add remarks...';
                        displayContainer.classList.add('empty');
                    }
                    
                    editContainer.style.display = 'none';
                    displayContainer.style.display = 'block';
                    
                    // Show success message
                    showAlert('success', data.message);
                    
                    // Highlight row
                    const row = document.getElementById('row-' + studentId);
                    row.classList.add('highlight-update');
                    setTimeout(() => row.classList.remove('highlight-update'), 1500);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                hideLoading();
                showAlert('error', 'Network error occurred. Please try again.');
                console.error('Error:', error);
            });
        }
        
        // Mark student as present
        function markAsPresent(studentId) {
            Swal.fire({
                title: 'Mark as Present?',
                text: 'This will change the attendance status to Present.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10B981',
                cancelButtonColor: '#EF4444',
                confirmButtonText: 'Yes, mark as present',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const remarks = document.getElementById('remarks-textarea-' + studentId).value.trim();
                    
                    showLoading();
                    
                    fetch('absent_reasons.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=mark_as_present&attendance_id=' + studentId + '&remarks=' + encodeURIComponent(remarks)
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        
                        if (data.success) {
                            // Remove the row
                            const row = document.getElementById('row-' + studentId);
                            row.style.opacity = '0.5';
                            setTimeout(() => {
                                row.style.transition = 'all 0.3s ease';
                                row.style.height = '0';
                                row.style.padding = '0';
                                row.style.border = 'none';
                                row.style.overflow = 'hidden';
                                setTimeout(() => row.remove(), 300);
                            }, 300);
                            
                            // Update statistics
                            updateStatistics();
                            
                            showAlert('success', data.message);
                        } else {
                            showAlert('error', data.message);
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        showAlert('error', 'Network error occurred. Please try again.');
                        console.error('Error:', error);
                    });
                }
            });
        }
        
        // Perform bulk update
        function performBulkUpdate() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            if (checkboxes.length === 0) {
                showAlert('error', 'Please select at least one student.');
                return;
            }
            
            const attendanceIds = Array.from(checkboxes).map(cb => cb.value);
            const remarks = document.getElementById('bulk_remarks').value.trim();
            const status = document.getElementById('bulk_status').value;
            
            Swal.fire({
                title: 'Confirm Bulk Update',
                html: `This will update <strong>${attendanceIds.length} student(s)</strong>.<br><br>
                      <strong>Action:</strong> ${status === 'Present' ? 'Mark as Present' : 'Update Remarks'}<br>
                      <strong>Remarks:</strong> ${remarks || '(none)'}`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#10B981',
                cancelButtonColor: '#EF4444',
                confirmButtonText: 'Yes, update all',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    
                    const formData = new FormData();
                    formData.append('action', 'bulk_update');
                    formData.append('bulk_remarks', remarks);
                    formData.append('bulk_status', status);
                    attendanceIds.forEach(id => formData.append('attendance_ids[]', id));
                    
                    fetch('absent_reasons.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        
                        if (data.success) {
                            // If marking as present, remove those rows
                            if (status === 'Present') {
                                attendanceIds.forEach(id => {
                                    const row = document.getElementById('row-' + id);
                                    if (row) {
                                        row.style.opacity = '0.5';
                                        setTimeout(() => {
                                            row.style.transition = 'all 0.3s ease';
                                            row.style.height = '0';
                                            row.style.padding = '0';
                                            row.style.border = 'none';
                                            row.style.overflow = 'hidden';
                                            setTimeout(() => row.remove(), 300);
                                        }, 300);
                                    }
                                });
                            }
                            
                            // Update statistics
                            updateStatistics();
                            
                            // Reset bulk form
                            document.getElementById('bulk_remarks').value = '';
                            document.getElementById('select-all').checked = false;
                            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
                            updateSelectedCount();
                            
                            showAlert('success', data.message);
                        } else {
                            showAlert('error', data.message);
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        showAlert('error', 'Network error occurred. Please try again.');
                        console.error('Error:', error);
                    });
                }
            });
        }
        
        // Filter functions
        function filterWithRemarks() {
            // Implement filtering logic
            showAlert('info', 'Filtering students with remarks...');
        }
        
        function filterWithoutRemarks() {
            // Implement filtering logic
            showAlert('info', 'Filtering students without remarks...');
        }
        
        // Update statistics
        function updateStatistics() {
            // This would ideally refresh the page or make an AJAX call
            // For now, we'll just show a message
            setTimeout(() => {
                location.reload();
            }, 1000);
        }
        
        // Utility functions
        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('active');
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('active');
        }
        
        function showAlert(type, message) {
            // Remove existing alerts
            const existingAlert = document.querySelector('.alert');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            // Create new alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            // Insert after header
            const header = document.querySelector('header');
            header.parentNode.insertBefore(alertDiv, header.nextSibling);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.style.opacity = '0';
                    alertDiv.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => alertDiv.remove(), 500);
                }
            }, 5000);
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
        });
    </script>
</body>
</html>