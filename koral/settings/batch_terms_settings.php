<?php
/**
 * batch_terms_settings.php
 * Admin interface to manage which batches require terms acceptance
 */

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Get all batches
$batches = $db->query("
    SELECT b.batch_id, b.batch_name, b.status, b.start_date, b.end_date,
           COALESCE(ts.require_terms_acceptance, 1) as require_terms,
           ts.custom_terms_enabled,
           ts.last_updated,
           u.name as updated_by_name
    FROM batches b
    LEFT JOIN batch_terms_settings ts ON b.batch_id = ts.batch_id
    LEFT JOIN users u ON ts.updated_by = u.id
    ORDER BY b.start_date DESC, b.batch_name
")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        $batch_id = $_POST['batch_id'];
        $require_terms = isset($_POST['require_terms']) ? 1 : 0;
        $custom_terms_enabled = isset($_POST['custom_terms_enabled']) ? 1 : 0;
        
        // Check if settings already exist
        $check = $db->prepare("SELECT id FROM batch_terms_settings WHERE batch_id = ?");
        $check->execute([$batch_id]);
        
        if ($check->rowCount() > 0) {
            // Update existing settings
            $stmt = $db->prepare("
                UPDATE batch_terms_settings 
                SET require_terms_acceptance = ?,
                    custom_terms_enabled = ?,
                    updated_by = ?,
                    last_updated = NOW()
                WHERE batch_id = ?
            ");
            $stmt->execute([$require_terms, $custom_terms_enabled, $_SESSION['user_id'], $batch_id]);
        } else {
            // Insert new settings
            $stmt = $db->prepare("
                INSERT INTO batch_terms_settings 
                (batch_id, require_terms_acceptance, custom_terms_enabled, updated_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$batch_id, $require_terms, $custom_terms_enabled, $_SESSION['user_id']]);
        }
        
        $_SESSION['success_message'] = "Settings updated successfully for batch $batch_id";
        header("Location: batch_terms_settings.php");
        exit();
    }
    
    // Handle bulk update
    if (isset($_POST['bulk_update'])) {
        $action = $_POST['bulk_action'];
        $selected_batches = $_POST['selected_batches'] ?? [];
        
        if (empty($selected_batches)) {
            $_SESSION['error_message'] = "Please select at least one batch";
        } else {
            $placeholders = implode(',', array_fill(0, count($selected_batches), '?'));
            
            if ($action === 'enable') {
                $sql = "INSERT INTO batch_terms_settings (batch_id, require_terms_acceptance, updated_by, created_at)
                        VALUES (?, 1, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                        require_terms_acceptance = 1,
                        updated_by = VALUES(updated_by),
                        last_updated = NOW()";
            } else {
                $sql = "INSERT INTO batch_terms_settings (batch_id, require_terms_acceptance, updated_by, created_at)
                        VALUES (?, 0, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                        require_terms_acceptance = 0,
                        updated_by = VALUES(updated_by),
                        last_updated = NOW()";
            }
            
            $stmt = $db->prepare($sql);
            foreach ($selected_batches as $batch_id) {
                $stmt->execute([$batch_id, $_SESSION['user_id']]);
            }
            
            $_SESSION['success_message'] = "Updated " . count($selected_batches) . " batch(es)";
            header("Location: batch_terms_settings.php");
            exit();
        }
    }
}

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(DISTINCT b.batch_id) as total_batches,
        SUM(CASE WHEN COALESCE(ts.require_terms_acceptance, 1) = 1 THEN 1 ELSE 0 END) as require_terms_count,
        SUM(CASE WHEN ts.custom_terms_enabled = 1 THEN 1 ELSE 0 END) as custom_terms_count
    FROM batches b
    LEFT JOIN batch_terms_settings ts ON b.batch_id = ts.batch_id
    WHERE b.status IN ('ongoing', 'upcoming')
")->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Terms & Conditions Settings - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
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

        h1, h2, h3, h4, h5, h6, strong {
            color: #1B3C53 !important;
        }

        .card {
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            border: 1px solid rgba(27, 60, 83, 0.10) !important;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
            border-radius: 20px !important;
            overflow: hidden;
        }

        .card-header {
            background: var(--primary-gradient) !important;
            border-bottom: 1px solid rgba(27, 60, 83, 0.1) !important;
            color: white !important;
            padding: 15px 20px !important;
        }

        .card-header h5, .card-header h6, .card-header h5 i {
            color: white !important;
            font-weight: 700;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        .status-ongoing {
            background: linear-gradient(135deg, #eaf2f1 0%, #cce0dd 100%) !important;
            color: #2d7a8a !important;
            border: 1px solid #cce0dd !important;
        }

        .status-upcoming {
            background: linear-gradient(135deg, #faf3ec 0%, #eed6c5 100%) !important;
            color: #b6876a !important;
            border: 1px solid #eed6c5 !important;
        }

        .status-completed {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%) !important;
            color: #475569 !important;
            border: 1px solid #e2e8f0 !important;
        }
        
        .terms-enabled {
            background: linear-gradient(135deg, #eaf2f1 0%, #cce0dd 100%) !important;
            color: #2d7a8a !important;
            border: 1px solid #cce0dd !important;
        }

        .terms-disabled {
            background: linear-gradient(135deg, #fdf3f2 0%, #f9d5d2 100%) !important;
            color: #c0392b !important;
            border: 1px solid #f9d5d2 !important;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(35, 76, 106, 0.04) !important;
        }

        .table thead th {
            background-color: rgba(27, 60, 83, 0.05) !important;
            color: #1B3C53 !important;
            border: none !important;
            font-weight: 700;
        }

        .table td {
            vertical-align: middle;
            border-bottom: 1px solid rgba(27, 60, 83, 0.05) !important;
        }
        
        .action-btn {
            transition: all 0.3s ease;
            border-radius: 8px !important;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.15) !important;
        }

        .btn-primary {
            background: var(--primary-gradient) !important;
            color: white !important;
            border: none !important;
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.2) !important;
            transition: all 0.3s ease !important;
        }

        .btn-primary:hover {
            background: var(--accent-gradient) !important;
            box-shadow: 0 6px 20px rgba(27, 60, 83, 0.3) !important;
        }

        .btn-outline-secondary {
            border: 1px solid rgba(27, 60, 83, 0.2) !important;
            color: #234C6A !important;
            border-radius: 8px !important;
            transition: all 0.3s ease !important;
        }

        .btn-outline-secondary:hover {
            background-color: rgba(35, 76, 106, 0.08) !important;
            color: #1B3C53 !important;
            border-color: #234C6A !important;
        }
        
        .stats-card {
            border-radius: 20px !important;
            border: 1px solid rgba(27, 60, 83, 0.1) !important;
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 30px rgba(27, 60, 83, 0.12) !important;
        }

        .mc-blue   { background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important; }
        .mc-green  { background: linear-gradient(135deg, #2d7a8a 0%, #1B3C53 100%) !important; }
        .mc-orange { background: linear-gradient(135deg, #b6876a 0%, #9c6f55 100%) !important; }

        .mc-blue *, .mc-green *, .mc-orange * {
            color: white !important;
        }

        .avatar-sm {
            width: 50px;
            height: 50px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px !important;
            background: rgba(27, 60, 83, 0.08) !important;
            color: #234C6A !important;
            font-size: 1.5rem;
        }

        .mc-blue .avatar-sm, .mc-green .avatar-sm, .mc-orange .avatar-sm {
            background: rgba(255, 255, 255, 0.2) !important;
            color: white !important;
        }

        .mc-blue .avatar-sm i, .mc-green .avatar-sm i, .mc-orange .avatar-sm i {
            color: white !important;
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

        .form-check-input:checked {
            background-color: #234C6A !important;
            border-color: #234C6A !important;
        }
        
        .filter-badge {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-badge:hover {
            opacity: 0.8;
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
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="content-wrapper ml-0 md:ml-64 min-h-screen p-4 md:p-6">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-2">
                    <i class="fas fa-file-contract text-primary me-2"></i>
                    Batch Terms & Conditions Settings
                </h1>
                <p class="text-muted">Manage which batches require students to accept terms and conditions</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#helpModal">
                    <i class="fas fa-question-circle me-2"></i>Help
                </button>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card stats-card mc-blue">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="avatar-sm">
                                    <i class="fas fa-layer-group"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h5 class="mb-1">Total Batches</h5>
                                <h2 class="mb-0"><?php echo $stats['total_batches']; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card stats-card mc-green">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="avatar-sm">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h5 class="mb-1">Require Terms</h5>
                                <h2 class="mb-0"><?php echo $stats['require_terms_count']; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card stats-card mc-orange">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="avatar-sm">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h5 class="mb-1">Custom Terms</h5>
                                <h2 class="mb-0"><?php echo $stats['custom_terms_count']; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Bulk Actions</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="bulkForm">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <div class="input-group">
                                <select class="form-select" name="bulk_action" required>
                                    <option value="">Select Action</option>
                                    <option value="enable">Enable Terms Requirement</option>
                                    <option value="disable">Disable Terms Requirement</option>
                                </select>
                                <button type="submit" name="bulk_update" class="btn btn-primary">
                                    <i class="fas fa-play me-2"></i>Apply
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                                <label class="form-check-label" for="selectAll">
                                    Select All Batches
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Batch Terms Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Batch Terms Settings</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th width="50">
                                    <input type="checkbox" id="selectAllRows">
                                </th>
                                <th>Batch ID</th>
                                <th>Batch Name</th>
                                <th>Status</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Terms Required</th>
                                <th>Custom Terms</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_batches[]" 
                                           value="<?php echo $batch['batch_id']; ?>" 
                                           class="batch-checkbox"
                                           form="bulkForm">
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($batch['batch_id']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($batch['batch_name']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $batch['status']; ?>">
                                        <?php echo ucfirst($batch['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($batch['start_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($batch['end_date'])); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $batch['require_terms'] ? 'terms-enabled' : 'terms-disabled'; ?>">
                                        <?php echo $batch['require_terms'] ? 'Required' : 'Not Required'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($batch['custom_terms_enabled']): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-check me-1"></i>Enabled
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <i class="fas fa-times me-1"></i>Disabled
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($batch['last_updated']): ?>
                                        <?php echo date('M d, Y', strtotime($batch['last_updated'])); ?><br>
                                        <small class="text-muted">by <?php echo htmlspecialchars($batch['updated_by_name'] ?? 'System'); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary action-btn" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editModal"
                                            data-batch-id="<?php echo $batch['batch_id']; ?>"
                                            data-batch-name="<?php echo htmlspecialchars($batch['batch_name']); ?>"
                                            data-require-terms="<?php echo $batch['require_terms']; ?>"
                                            data-custom-terms="<?php echo $batch['custom_terms_enabled']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (empty($batches)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h4 class="text-muted">No batches found</h4>
                    <p class="text-muted">Create some batches first to manage their terms settings.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Terms Settings</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Batch</label>
                            <input type="text" class="form-control" id="modalBatchName" readonly>
                            <input type="hidden" name="batch_id" id="modalBatchId">
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" 
                                       name="require_terms" id="requireTerms" value="1">
                                <label class="form-check-label" for="requireTerms">
                                    <strong>Require Terms Acceptance</strong>
                                </label>
                                <div class="form-text">
                                    When enabled, students in this batch must accept terms before accessing dashboard.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" 
                                       name="custom_terms_enabled" id="customTerms" value="1">
                                <label class="form-check-label" for="customTerms">
                                    <strong>Enable Custom Terms</strong>
                                </label>
                                <div class="form-text">
                                    Use custom terms content for this batch instead of default terms.
                                </div>
                            </div>
                        </div>
                        
                        <div id="customTermsSection" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Custom Terms Content</label>
                                <textarea class="form-control" name="custom_terms" rows="5" 
                                          placeholder="Enter custom terms content for this batch..."></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Upload Custom Terms File (Optional)</label>
                                <input type="file" class="form-control" name="terms_file" accept=".pdf,.doc,.docx,.txt">
                                <div class="form-text">Upload a PDF, Word, or text file with custom terms</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_settings" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i>Help - Batch Terms Settings</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6><i class="fas fa-info-circle text-primary me-2"></i>About This Feature</h6>
                                    <p class="mb-0">
                                        This feature allows you to control which batches require students to 
                                        accept terms and conditions before accessing their dashboard.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6><i class="fas fa-toggle-on text-success me-2"></i>Terms Requirement</h6>
                                    <p class="mb-0">
                                        When enabled, students in that batch will be redirected to 
                                        terms acceptance page on their first login.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-lightbulb me-2"></i>Best Practices</h6>
                        <ul class="mb-0">
                            <li>Enable terms acceptance for all ongoing batches</li>
                            <li>Use custom terms for special batches with unique policies</li>
                            <li>Review and update terms content periodically</li>
                            <li>Keep a record of when students accepted terms</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit modal setup
        const editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const batchId = button.getAttribute('data-batch-id');
                const batchName = button.getAttribute('data-batch-name');
                const requireTerms = button.getAttribute('data-require-terms') === '1';
                const customTerms = button.getAttribute('data-custom-terms') === '1';
                
                document.getElementById('modalBatchId').value = batchId;
                document.getElementById('modalBatchName').value = `${batchId} - ${batchName}`;
                document.getElementById('requireTerms').checked = requireTerms;
                document.getElementById('customTerms').checked = customTerms;
                
                // Show/hide custom terms section
                toggleCustomTermsSection();
            });
        }
        
        // Toggle custom terms section
        document.getElementById('customTerms').addEventListener('change', function() {
            toggleCustomTermsSection();
        });
        
        function toggleCustomTermsSection() {
            const customTermsSection = document.getElementById('customTermsSection');
            const customTermsCheckbox = document.getElementById('customTerms');
            
            if (customTermsCheckbox.checked) {
                customTermsSection.style.display = 'block';
            } else {
                customTermsSection.style.display = 'none';
            }
        }
        
        // Select all checkboxes
        document.getElementById('selectAllRows').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.batch-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        
        // Select all for bulk form
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.batch-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            document.getElementById('selectAllRows').checked = this.checked;
        });
        
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>