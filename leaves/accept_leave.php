<?php
session_start();
require_once '../db_connection.php';

// Check if admin/mentor is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'mentor'])) {
    header("Location: ../login.php");
    exit();
}

$application_id = $_GET['id'] ?? 0;

// Get application details
$query = $db->prepare("
    SELECT l.*, b.batch_name as batch_title
    FROM leave_applications l
    LEFT JOIN batches b ON l.batch_id = b.batch_id
    WHERE l.id = :id AND l.status = 'pending'
");
$query->execute([':id' => $application_id]);
$application = $query->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header("Location: leave_management.php?error=1&message=" . urlencode("Application not found or already processed"));
    exit();
}

// Handle form submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    $remarks = $_POST['admin_remarks'] ?? '';
    
    $db->beginTransaction();
    
    try {
        // Update application
        $stmt = $db->prepare("
            UPDATE leave_applications 
            SET status = 'approved', 
                approved_by = :approved_by, 
                approved_at = NOW(),
                admin_remarks = :remarks
            WHERE id = :id AND status = 'pending'
        ");
        $stmt->execute([
            ':approved_by' => $_SESSION['user_id'],
            ':remarks' => $remarks,
            ':id' => $application_id
        ]);
        
        // Add to history
        $history_stmt = $db->prepare("
            INSERT INTO leave_application_history (application_id, action, action_by, remarks)
            VALUES (:application_id, 'approved', :action_by, :remarks)
        ");
        $history_stmt->execute([
            ':application_id' => $application_id,
            ':action_by' => $_SESSION['user_id'],
            ':remarks' => $remarks
        ]);
        
        $db->commit();
        
        header("Location: leave_management.php?success=1&message=" . urlencode("Application approved successfully!"));
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Failed to approve application: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Application - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in { animation: fadeIn 0.6s ease-out forwards; }

        body {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 50%, #f0f9ff 100%);
            min-height: 100vh;
        }
        .page-header-bar {
            background: linear-gradient(135deg, #059669 0%, #0284c7 100%);
            border-radius: 1.25rem;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 24px rgba(5,150,105,0.25);
        }
        .page-header-bar a { color: rgba(255,255,255,0.8); }
        .page-header-bar a:hover { color: #fff; }
        .page-header-bar h1 { color: #fff; }
        .page-header-bar p { color: rgba(255,255,255,0.75); }
        .summary-card {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 4px 20px rgba(5,150,105,0.08);
            border-top: 4px solid #059669;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .summary-card h2 { color: #059669; }
        .info-chip {
            background: linear-gradient(135deg, #ecfdf5, #e0f2fe);
            border-radius: 0.5rem;
            padding: 0.65rem 0.85rem;
        }
        .summary-label { font-size: 0.75rem; color: #9ca3af; }
        .summary-value { font-weight: 600; color: #1f2937; }
        .reason-box {
            background: linear-gradient(135deg, #f0fdf4, #e0f2fe);
            border-left: 3px solid #10b981;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
        }
        .approval-card {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 4px 20px rgba(2,132,199,0.1);
            border-top: 4px solid #0284c7;
            padding: 1.5rem;
        }
        .approval-card h2 { color: #0284c7; }
        .approval-card textarea:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5,150,105,0.15);
        }
        .btn-approve {
            background: linear-gradient(135deg, #059669, #0284c7);
            color: #fff;
            border-radius: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            box-shadow: 0 4px 12px rgba(5,150,105,0.35);
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-approve:hover { opacity: 0.92; transform: translateY(-1px); }
        .btn-cancel {
            background: linear-gradient(135deg, #dcfce7, #e0f2fe);
            color: #065f46;
            border-radius: 0.75rem;
            font-weight: 700;
            transition: opacity 0.2s;
        }
        .btn-cancel:hover { opacity: 0.85; }
        .view-link { color: #059669; }
        .view-link:hover { color: #047857; text-decoration: underline; }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    
    <div class="flex">
        <?php include '../sidebar.php'; ?>
        
        <div class="flex-1 ml-64 p-8">
            <div class="max-w-3xl mx-auto">
                <!-- Header -->
                <div class="page-header-bar animate-fade-in">
                    <div class="flex items-center space-x-4">
                        <a href="leave_management.php">
                            <i class="fas fa-arrow-left text-xl"></i>
                        </a>
                        <div>
                            <h1 class="text-2xl font-bold">✔ Approve Leave Application</h1>
                            <p class="mt-1 text-sm">Review and confirm approval of the student's leave request</p>
                        </div>
                    </div>
                </div>
                
                <!-- Application Summary -->
                <div class="summary-card animate-fade-in">
                    <h2 class="text-lg font-bold mb-4">📋 Application Summary</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="info-chip">
                            <p class="summary-label">Application Number</p>
                            <p class="summary-value"><?= htmlspecialchars($application['application_no']) ?></p>
                        </div>
                        <div class="info-chip">
                            <p class="summary-label">Student Name</p>
                            <p class="summary-value"><?= htmlspecialchars($application['student_name']) ?></p>
                        </div>
                        <div class="info-chip">
                            <p class="summary-label">Student ID</p>
                            <p class="summary-value"><?= htmlspecialchars($application['student_id']) ?></p>
                        </div>
                        <div class="info-chip">
                            <p class="summary-label">Batch</p>
                            <p class="summary-value"><?= htmlspecialchars($application['batch_title'] ?? $application['batch_id']) ?></p>
                        </div>
                        <div class="info-chip">
                            <p class="summary-label">Leave Period</p>
                            <p class="summary-value">
                                <?= date('d M Y', strtotime($application['start_date'])) ?> – <?= date('d M Y', strtotime($application['end_date'])) ?>
                                <span class="text-emerald-600 font-bold">(<?= $application['total_days'] ?> days)</span>
                            </p>
                        </div>
                        <div class="info-chip">
                            <p class="summary-label">Reason Category</p>
                            <p class="summary-value"><?= htmlspecialchars($application['reason_category']) ?></p>
                        </div>
                    </div>

                    <div class="mt-4 reason-box">
                        <p class="summary-label mb-1">Detailed Reason</p>
                        <p class="text-gray-700"><?= nl2br(htmlspecialchars($application['reason_detail'])) ?></p>
                    </div>

                    <div class="mt-4">
                        <a href="view_application.php?id=<?= $application['id'] ?>" class="view-link text-sm font-medium">
                            <i class="fas fa-eye mr-1"></i> View full application details
                        </a>
                    </div>
                </div>
                
                <!-- Approval Form -->
                <div class="approval-card animate-fade-in">
                    <h2 class="text-lg font-bold mb-4">✅ Approval Form</h2>

                    <?php if ($error_message): ?>
                        <div class="mb-4 p-3 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg">
                            <i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($error_message) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-6">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Admin Remarks <span class="text-gray-400 font-normal">(Optional)</span></label>
                            <textarea name="admin_remarks" rows="4"
                                      class="w-full px-4 py-3 border border-gray-300 rounded-xl transition-all"
                                      placeholder="Add any remarks or notes about this approval..."></textarea>
                        </div>

                        <div class="flex gap-4">
                            <button type="submit" name="approve" class="btn-approve flex-1 px-6 py-3">
                                <i class="fas fa-check mr-2"></i> Confirm Approval
                            </button>
                            <a href="leave_management.php" class="btn-cancel flex-1 px-6 py-3 text-center">
                                <i class="fas fa-times mr-2"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>