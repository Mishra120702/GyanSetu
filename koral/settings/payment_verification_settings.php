<?php
/**
 * payment_verification_settings.php
 * Admin page to configure which batches require payment verification
 */

include '../db_connection.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_batch'])) {
        $batch_id = filter_input(INPUT_POST, 'batch_id', FILTER_SANITIZE_STRING);
        
        if (!empty($batch_id)) {
            try {
                // Check if batch exists in batches table
                $stmt = $db->prepare("SELECT batch_id FROM batches WHERE batch_id = ?");
                $stmt->execute([$batch_id]);
                
                if ($stmt->rowCount() > 0) {
                    // Check if already added
                    $stmt = $db->prepare("SELECT id FROM payment_verification_settings WHERE batch_id = ?");
                    $stmt->execute([$batch_id]);
                    
                    if ($stmt->rowCount() == 0) {
                        $stmt = $db->prepare("INSERT INTO payment_verification_settings (batch_id, created_by) VALUES (?, ?)");
                        $stmt->execute([$batch_id, $_SESSION['user_id']]);
                        $message = "Batch added to payment verification list successfully!";
                        $message_type = "success";
                    } else {
                        $message = "This batch is already in the verification list!";
                        $message_type = "warning";
                    }
                } else {
                    $message = "Batch ID not found! Please enter a valid batch ID.";
                    $message_type = "error";
                }
            } catch (PDOException $e) {
                error_log("Error adding batch to payment verification: " . $e->getMessage());
                $message = "Error adding batch. Please try again.";
                $message_type = "error";
            }
        }
    } elseif (isset($_POST['toggle_status'])) {
        $batch_id = filter_input(INPUT_POST, 'batch_id', FILTER_SANITIZE_STRING);
        $new_status = filter_input(INPUT_POST, 'new_status', FILTER_VALIDATE_INT);
        
        try {
            $stmt = $db->prepare("UPDATE payment_verification_settings SET is_active = ? WHERE batch_id = ?");
            $stmt->execute([$new_status, $batch_id]);
            
            $status_text = $new_status ? "enabled" : "disabled";
            $message = "Payment verification for batch {$batch_id} has been {$status_text}!";
            $message_type = "success";
        } catch (PDOException $e) {
            error_log("Error updating batch status: " . $e->getMessage());
            $message = "Error updating status. Please try again.";
            $message_type = "error";
        }
    } elseif (isset($_POST['remove_batch'])) {
        $batch_id = filter_input(INPUT_POST, 'batch_id', FILTER_SANITIZE_STRING);
        
        try {
            $stmt = $db->prepare("DELETE FROM payment_verification_settings WHERE batch_id = ?");
            $stmt->execute([$batch_id]);
            $message = "Batch removed from payment verification list!";
            $message_type = "success";
        } catch (PDOException $e) {
            error_log("Error removing batch: " . $e->getMessage());
            $message = "Error removing batch. Please try again.";
            $message_type = "error";
        }
    } elseif (isset($_POST['bulk_action'])) {
        $action = filter_input(INPUT_POST, 'bulk_action', FILTER_SANITIZE_STRING);
        $selected_batches = $_POST['selected_batches'] ?? [];
        
        if (!empty($selected_batches)) {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_batches), '?'));
                
                if ($action === 'enable') {
                    $stmt = $db->prepare("UPDATE payment_verification_settings SET is_active = 1 WHERE batch_id IN ($placeholders)");
                    $stmt->execute($selected_batches);
                    $message = "Selected batches enabled for payment verification!";
                } elseif ($action === 'disable') {
                    $stmt = $db->prepare("UPDATE payment_verification_settings SET is_active = 0 WHERE batch_id IN ($placeholders)");
                    $stmt->execute($selected_batches);
                    $message = "Selected batches disabled for payment verification!";
                } elseif ($action === 'delete') {
                    $stmt = $db->prepare("DELETE FROM payment_verification_settings WHERE batch_id IN ($placeholders)");
                    $stmt->execute($selected_batches);
                    $message = "Selected batches removed from payment verification!";
                }
                $message_type = "success";
            } catch (PDOException $e) {
                error_log("Error performing bulk action: " . $e->getMessage());
                $message = "Error performing action. Please try again.";
                $message_type = "error";
            }
        } else {
            $message = "Please select at least one batch!";
            $message_type = "warning";
        }
    }
}

// Get all batches with payment verification settings
$batches = $db->query("
    SELECT 
        pvs.*,
        b.batch_name,
        b.status as batch_status,
        b.start_date,
        b.end_date,
        b.current_enrollment,
        COUNT(DISTINCT s.student_id) as unpaid_students
    FROM payment_verification_settings pvs
    LEFT JOIN batches b ON pvs.batch_id = b.batch_id
    LEFT JOIN students s ON s.batch_name = b.batch_id 
        AND s.fees_status IN ('unpaid', 'partially_paid', 'overdue')
    GROUP BY pvs.batch_id
    ORDER BY pvs.is_active DESC, b.batch_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get all available batches for dropdown
$all_batches = $db->query("
    SELECT batch_id, batch_name, start_date, end_date, current_enrollment 
    FROM batches 
    WHERE status IN ('upcoming', 'ongoing')
    ORDER BY batch_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total_batches,
        SUM(is_active = 1) as active_batches,
        SUM(is_active = 0) as inactive_batches
    FROM payment_verification_settings
")->fetch(PDO::FETCH_ASSOC);

// Get unpaid students count
$unpaid_stats = $db->query("
    SELECT 
        COUNT(DISTINCT s.student_id) as total_unpaid,
        COUNT(DISTINCT CASE WHEN pvs.is_active = 1 THEN s.student_id END) as unpaid_in_active_batches
    FROM students s
    JOIN batches b ON s.batch_name = b.batch_id
    LEFT JOIN payment_verification_settings pvs ON b.batch_id = pvs.batch_id
    WHERE s.fees_status IN ('unpaid', 'partially_paid', 'overdue')
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verification Settings - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        /* Brand Colour Theme: #1B3C53 · #234C6A · #456882 · #D2C1B6 */
        :root {
            --brand-darkest:  #1B3C53;
            --brand-dark:     #234C6A;
            --brand-mid:      #456882;
            --brand-light:    #A4C4D4;
            --brand-sand:     #D2C1B6;
            --primary-gradient: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
            --accent-gradient:  linear-gradient(135deg, #456882 0%, #234C6A 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(145deg, #e8eef3 0%, #d6e4ed 30%, #e4edf4 60%, #dce8f0 100%) !important;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Soft decorative blobs matching dashboard */
        body::before {
            content: '';
            position: fixed;
            top: -120px; left: -120px;
            width: 420px; height: 420px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(27,60,83,0.1) 0%, transparent 70%);
            animation: driftOrb1 20s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -100px; right: -100px;
            width: 380px; height: 380px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(69,104,130,0.09) 0%, transparent 70%);
            animation: driftOrb2 25s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        @keyframes driftOrb1 {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(60px,50px) scale(1.1); }
        }
        @keyframes driftOrb2 {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(-50px,-60px) scale(1.12); }
        }

        .content-wrapper {
            position: relative;
            z-index: 1;
        }

        .dashboard-header {
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            border: 1px solid rgba(27, 60, 83, 0.10) !important;
            border-radius: 20px !important;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
        }

        .dashboard-header h1, .dashboard-header p, .dashboard-header h4, .dashboard-header small {
            color: #1B3C53 !important;
        }

        .dashboard-header .bg-white {
            background: rgba(27, 60, 83, 0.05) !important;
            border: 1px solid rgba(27, 60, 83, 0.1) !important;
        }

        .metric-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border-radius: 20px !important;
            padding: 25px;
            height: 100%;
            border: 1px solid rgba(27, 60, 83, 0.10) !important;
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
        }

        .metric-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 30px rgba(27, 60, 83, 0.12) !important;
        }

        /* Metric card brand gradients */
        .mc-slate  { background: linear-gradient(135deg, #3a6278 0%, #1B3C53 100%) !important; box-shadow: 0 8px 24px rgba(27, 60, 83, 0.2) !important; }
        .mc-teal   { background: linear-gradient(135deg, #2d7a8a 0%, #1B3C53 100%) !important; box-shadow: 0 8px 24px rgba(45, 122, 138, 0.2) !important; }
        .mc-red    { background: linear-gradient(135deg, #c0392b 0%, #922b21 100%) !important; box-shadow: 0 8px 24px rgba(192, 57, 43, 0.2) !important; }
        .mc-orange { background: linear-gradient(135deg, #b6876a 0%, #9c6f55 100%) !important; box-shadow: 0 8px 24px rgba(182, 135, 106, 0.2) !important; }
        
        .mc-slate *, .mc-teal *, .mc-red *, .mc-orange * {
            color: white !important;
        }

        .stat-icon {
            font-size: 1.8rem !important;
            width: 60px;
            height: 60px;
            line-height: 60px;
            border-radius: 15px;
            background: rgba(27, 60, 83, 0.08);
            color: #234C6A !important;
            margin-bottom: 15px;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .mc-slate .stat-icon, .mc-teal .stat-icon, .mc-red .stat-icon, .mc-orange .stat-icon {
            background: rgba(255, 255, 255, 0.2) !important;
            color: white !important;
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 5px;
            color: #1B3C53;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #456882;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .table-container {
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            border: 1px solid rgba(27, 60, 83, 0.10) !important;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
            border-radius: 20px !important;
            padding: 25px;
            margin-bottom: 25px;
        }

        .table-container h4, .table-container label {
            color: #1B3C53 !important;
        }

        .form-select, .form-control {
            border: 1px solid rgba(27, 60, 83, 0.15) !important;
            border-radius: 8px !important;
            transition: all 0.3s ease !important;
        }

        .form-select:focus, .form-control:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 0.25rem rgba(35, 76, 106, 0.25) !important;
        }

        .btn-primary {
            background: var(--primary-gradient) !important;
            color: white !important;
            border: none !important;
            border-radius: 8px !important;
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.2) !important;
            transition: all 0.3s ease !important;
        }

        .btn-primary:hover {
            background: var(--accent-gradient) !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(27, 60, 83, 0.3) !important;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }

        .status-active {
            background: linear-gradient(135deg, #eaf2f1 0%, #cce0dd 100%) !important;
            color: #2d7a8a !important;
            border: 1px solid #cce0dd !important;
        }

        .status-inactive {
            background: linear-gradient(135deg, #faf3ec 0%, #eed6c5 100%) !important;
            color: #b6876a !important;
            border: 1px solid #eed6c5 !important;
        }

        .fees-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .fees-unpaid {
            background: linear-gradient(135deg, #fdf3f2 0%, #f9d5d2 100%) !important;
            color: #c0392b !important;
            border: 1px solid #f9d5d2 !important;
        }

        .fees-partial {
            background: linear-gradient(135deg, #faf3ec 0%, #eed6c5 100%) !important;
            color: #b6876a !important;
            border: 1px solid #eed6c5 !important;
        }

        .fees-paid {
            background: linear-gradient(135deg, #eaf2f1 0%, #cce0dd 100%) !important;
            color: #2d7a8a !important;
            border: 1px solid #cce0dd !important;
        }

        .action-btn {
            transition: all 0.3s ease;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.15);
        }

        .btn-enable {
            background: linear-gradient(135deg, #eaf2f1 0%, #cce0dd 100%) !important;
            color: #2d7a8a !important;
            border: 1px solid #cce0dd !important;
        }

        .btn-disable {
            background: linear-gradient(135deg, #faf3ec 0%, #eed6c5 100%) !important;
            color: #b6876a !important;
            border: 1px solid #eed6c5 !important;
        }

        .btn-remove {
            background: linear-gradient(135deg, #fdf3f2 0%, #f9d5d2 100%) !important;
            color: #c0392b !important;
            border: 1px solid #f9d5d2 !important;
        }

        .modal-content {
            border-radius: 20px !important;
            overflow: hidden;
            border: none !important;
            box-shadow: 0 15px 35px rgba(27, 60, 83, 0.2) !important;
        }

        .modal-header {
            background: var(--primary-gradient) !important;
            color: white !important;
            border: none !important;
        }

        .modal-header * {
            color: white !important;
        }

        .alert-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .floating-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-gradient) !important;
            color: white !important;
            border: none;
            box-shadow: 0 10px 25px rgba(27, 60, 83, 0.3) !important;
            font-size: 24px;
            cursor: pointer;
            z-index: 100;
            transition: all 0.3s ease !important;
        }

        .floating-btn:hover {
            transform: scale(1.1) !important;
            box-shadow: 0 15px 30px rgba(27, 60, 83, 0.45) !important;
        }

        .bulk-actions {
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            border: 1px solid rgba(27, 60, 83, 0.10) !important;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
            padding: 15px;
            border-radius: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .bulk-actions label {
            color: #1B3C53 !important;
        }

        /* Checkbox styling */
        .form-check-input:checked {
            background-color: #234C6A !important;
            border-color: #234C6A !important;
        }

        /* Table styling overrides */
        #batchesTable thead th {
            background-color: rgba(27, 60, 83, 0.05) !important;
            color: #1B3C53 !important;
            border: none !important;
            font-weight: 700;
        }

        #batchesTable tbody tr:hover {
            background-color: rgba(35, 76, 106, 0.04) !important;
        }

        #batchesTable td {
            vertical-align: middle;
            border-bottom: 1px solid rgba(27, 60, 83, 0.05) !important;
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="content-wrapper ml-0 md:ml-64 min-h-screen p-4 md:p-6">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="display-5 fw-bold mb-3">
                        <i class="fas fa-money-check-alt me-3"></i>
                        Payment Verification Settings
                    </h1>
                    <p class="lead mb-0">
                        Configure which batches require payment verification before students can access the portal
                    </p>
                </div>
                <div class="col-lg-4 text-end">
                    <div class="d-inline-block p-3 bg-white bg-opacity-20 rounded-4">
                        <h4 class="mb-0 fw-bold">Payment Security</h4>
                        <small>Active Batches: <?php echo $stats['active_batches'] ?? 0; ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4 g-4">
            <div class="col-xl-3 col-lg-6">
                <div class="metric-card mc-slate">
                    <div class="text-center">
                        <i class="fas fa-list-check stat-icon"></i>
                        <h2 class="stat-number"><?php echo $stats['total_batches'] ?? 0; ?></h2>
                        <p class="stat-label">Total Batches in List</p>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6">
                <div class="metric-card mc-teal">
                    <div class="text-center">
                        <i class="fas fa-toggle-on stat-icon"></i>
                        <h2 class="stat-number"><?php echo $stats['active_batches'] ?? 0; ?></h2>
                        <p class="stat-label">Active Verification</p>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6">
                <div class="metric-card mc-red">
                    <div class="text-center">
                        <i class="fas fa-users-slash stat-icon"></i>
                        <h2 class="stat-number"><?php echo $unpaid_stats['unpaid_in_active_batches'] ?? 0; ?></h2>
                        <p class="stat-label">Unpaid in Active Batches</p>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6">
                <div class="metric-card mc-orange">
                    <div class="text-center">
                        <i class="fas fa-user-clock stat-icon"></i>
                        <h2 class="stat-number"><?php echo $unpaid_stats['total_unpaid'] ?? 0; ?></h2>
                        <p class="stat-label">Total Unpaid Students</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Batch Form -->
        <div class="table-container mb-4">
            <h4 class="mb-4 fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i> Add Batch to Verification List</h4>
            <form method="POST" class="row g-3">
                <div class="col-md-6">
                    <label for="batch_id" class="form-label">Select Batch</label>
                    <select class="form-select" id="batch_id" name="batch_id" required>
                        <option value="">-- Select a Batch --</option>
                        <?php foreach ($all_batches as $batch): ?>
                            <?php 
                            $is_in_list = false;
                            foreach ($batches as $v_batch) {
                                if ($v_batch['batch_id'] === $batch['batch_id']) {
                                    $is_in_list = true;
                                    break;
                                }
                            }
                            ?>
                            <?php if (!$is_in_list): ?>
                                <option value="<?php echo htmlspecialchars($batch['batch_id']); ?>">
                                    <?php echo htmlspecialchars($batch['batch_name']); ?> (<?php echo htmlspecialchars($batch['batch_id']); ?>)
                                    - <?php echo date('M Y', strtotime($batch['start_date'])); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" name="add_batch" class="btn btn-primary btn-lg">
                        <i class="fas fa-plus me-2"></i> Add to Verification List
                    </button>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <form method="POST" id="bulkForm">
            <div class="bulk-actions">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="selectAll">
                    <label class="form-check-label fw-bold" for="selectAll">
                        Select All
                    </label>
                </div>
                <select class="form-select w-auto" name="bulk_action" id="bulkAction">
                    <option value="">Bulk Actions</option>
                    <option value="enable">Enable Verification</option>
                    <option value="disable">Disable Verification</option>
                    <option value="delete">Remove from List</option>
                </select>
                <button type="submit" class="btn btn-primary" onclick="return confirmBulkAction()">
                    <i class="fas fa-play me-2"></i> Apply
                </button>
                <span class="text-muted ms-3" id="selectedCount">0 batches selected</span>
            </div>

            <!-- Batches List -->
            <div class="table-container">
                <h4 class="mb-4 fw-bold"><i class="fas fa-list me-2 text-primary"></i> Batches with Payment Verification</h4>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : ($message_type === 'warning' ? 'warning' : 'danger'); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover" id="batchesTable">
                        <thead>
                            <tr>
                                <th width="50"></th>
                                <th>Batch ID</th>
                                <th>Batch Name</th>
                                <th>Status</th>
                                <th>Verification Status</th>
                                <th>Start Date</th>
                                <th>Students</th>
                                <th>Unpaid Students</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($batches)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">No batches configured for payment verification</h5>
                                        <p class="text-muted">Add batches using the form above</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($batches as $batch): ?>
                                <tr>
                                    <td>
                                        <input class="form-check-input batch-checkbox" type="checkbox" name="selected_batches[]" value="<?php echo htmlspecialchars($batch['batch_id']); ?>">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($batch['batch_id']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($batch['batch_name']); ?></td>
                                    <td>
                                        <?php 
                                        $batch_status_color = '';
                                        switch($batch['batch_status']) {
                                            case 'upcoming': $batch_status_color = 'info'; break;
                                            case 'ongoing': $batch_status_color = 'success'; break;
                                            case 'completed': $batch_status_color = 'secondary'; break;
                                            default: $batch_status_color = 'warning';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $batch_status_color; ?>">
                                            <?php echo ucfirst($batch['batch_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($batch['is_active']): ?>
                                            <span class="status-badge status-active">
                                                <i class="fas fa-toggle-on me-1"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-inactive">
                                                <i class="fas fa-toggle-off me-1"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($batch['start_date'])); ?></td>
                                    <td><?php echo $batch['current_enrollment']; ?></td>
                                    <td>
                                        <?php if ($batch['unpaid_students'] > 0): ?>
                                            <span class="badge bg-danger rounded-pill">
                                                <?php echo $batch['unpaid_students']; ?> unpaid
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success rounded-pill">
                                                All paid
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($batch['is_active']): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch['batch_id']); ?>">
                                                    <input type="hidden" name="new_status" value="0">
                                                    <button type="submit" name="toggle_status" class="btn btn-warning btn-sm" 
                                                            onclick="return confirm('Disable payment verification for this batch?')">
                                                        <i class="fas fa-toggle-off"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch['batch_id']); ?>">
                                                    <input type="hidden" name="new_status" value="1">
                                                    <button type="submit" name="toggle_status" class="btn btn-success btn-sm"
                                                            onclick="return confirm('Enable payment verification for this batch?')">
                                                        <i class="fas fa-toggle-on"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch['batch_id']); ?>">
                                                <button type="submit" name="remove_batch" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Remove this batch from verification list?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            
                                            <a href="../students/student_list.php?batch=<?php echo urlencode($batch['batch_id']); ?>&filter=unpaid" 
                                               class="btn btn-info btn-sm" target="_blank" title="View Unpaid Students">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>

    <!-- Add Batch Modal (Alternative) -->
    <div class="modal fade" id="addBatchModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Add Batch to Verification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addBatchForm">
                        <div class="mb-3">
                            <label for="modal_batch_id" class="form-label">Batch ID</label>
                            <input type="text" class="form-control" id="modal_batch_id" name="batch_id" 
                                   placeholder="Enter Batch ID (e.g., BATCH001)" required>
                            <div class="form-text">Enter the exact Batch ID from the batches table</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addBatchForm" name="add_batch" class="btn btn-primary">Add Batch</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button class="floating-btn" data-bs-toggle="modal" data-bs-target="#addBatchModal">
        <i class="fas fa-plus"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#batchesTable').DataTable({
                pageLength: 10,
                responsive: true,
                order: [[3, 'desc']], // Sort by verification status
                language: {
                    search: "Search batches:",
                    lengthMenu: "Show _MENU_ batches",
                    info: "Showing _START_ to _END_ of _TOTAL_ batches"
                }
            });
        });

        // Select All functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.batch-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.batch-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = selected + ' batches selected';
        }

        // Add event listeners to checkboxes
        document.querySelectorAll('.batch-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Confirm bulk action
        function confirmBulkAction() {
            const action = document.getElementById('bulkAction').value;
            const selected = document.querySelectorAll('.batch-checkbox:checked').length;
            
            if (!action) {
                alert('Please select a bulk action first.');
                return false;
            }
            
            if (selected === 0) {
                alert('Please select at least one batch.');
                return false;
            }
            
            let message = '';
            switch(action) {
                case 'enable':
                    message = 'Enable payment verification for ' + selected + ' selected batch(es)?';
                    break;
                case 'disable':
                    message = 'Disable payment verification for ' + selected + ' selected batch(es)?';
                    break;
                case 'delete':
                    message = 'Remove ' + selected + ' batch(es) from verification list? This cannot be undone.';
                    break;
            }
            
            return confirm(message);
        }

        // Auto-refresh page every 60 seconds to show updated unpaid counts
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>