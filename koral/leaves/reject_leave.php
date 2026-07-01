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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject'])) {
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    $remarks = $_POST['admin_remarks'] ?? '';
    
    if (empty($rejection_reason)) {
        $error_message = "Please provide a reason for rejection.";
    } else {
        $db->beginTransaction();
        
        try {
            // Update application
            $stmt = $db->prepare("
                UPDATE leave_applications 
                SET status = 'rejected', 
                    rejected_by = :rejected_by, 
                    rejected_at = NOW(),
                    rejection_reason = :rejection_reason,
                    admin_remarks = :remarks
                WHERE id = :id AND status = 'pending'
            ");
            $stmt->execute([
                ':rejected_by' => $_SESSION['user_id'],
                ':rejection_reason' => $rejection_reason,
                ':remarks' => $remarks,
                ':id' => $application_id
            ]);
            
            // Add to history
            $history_stmt = $db->prepare("
                INSERT INTO leave_application_history (application_id, action, action_by, remarks)
                VALUES (:application_id, 'rejected', :action_by, :remarks)
            ");
            $history_stmt->execute([
                ':application_id' => $application_id,
                ':action_by' => $_SESSION['user_id'],
                ':remarks' => $rejection_reason
            ]);
            
            $db->commit();
            
            header("Location: leave_management.php?success=1&message=" . urlencode("Application rejected successfully!"));
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Failed to reject application: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reject Application - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../admin_header.php'; ?>
    
    <div class="flex">
        <?php include '../sidebar.php'; ?>
        
        <div class="flex-1 ml-64 p-8">
            <div class="max-w-3xl mx-auto">
                <!-- Header -->
                <div class="mb-6">
                    <div class="flex items-center space-x-4">
                        <a href="leave_management.php" class="text-gray-600 hover:text-gray-800">
                            <i class="fas fa-arrow-left text-xl"></i>
                        </a>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Reject Leave Application</h1>
                            <p class="text-gray-500 mt-1">Review and reject the leave application</p>
                        </div>
                    </div>
                </div>
                
                <!-- Application Summary -->
                <div class="bg-white rounded-2xl shadow-lg p-6 mb-6 animate-fade-in">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">Application Summary</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Application Number</p>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($application['application_no']) ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Student Name</p>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($application['student_name']) ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Student ID</p>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($application['student_id']) ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Batch</p>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($application['batch_title'] ?? $application['batch_id']) ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Leave Period</p>
                            <p class="font-semibold text-gray-800">
                                <?= date('d M Y', strtotime($application['start_date'])) ?> - <?= date('d M Y', strtotime($application['end_date'])) ?>
                                (<?= $application['total_days'] ?> days)
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Reason Category</p>
                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($application['reason_category']) ?></p>
                        </div>
                    </div>
                    
                    <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                        <p class="text-sm text-gray-500 mb-1">Detailed Reason</p>
                        <p class="text-gray-700"><?= nl2br(htmlspecialchars($application['reason_detail'])) ?></p>
                    </div>
                    
                    <div class="mt-4">
                        <a href="view_application.php?id=<?= $application['id'] ?>" class="text-blue-600 hover:underline">
                            <i class="fas fa-eye mr-1"></i> View full application details
                        </a>
                    </div>
                </div>
                
                <!-- Rejection Form -->
                <div class="bg-white rounded-2xl shadow-lg p-6 animate-fade-in">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">Rejection Form</h2>
                    
                    <?php if ($error_message): ?>
                        <div class="mb-4 p-3 bg-red-100 border-l-4 border-red-500 text-red-700 rounded">
                            <i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($error_message) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Rejection Reason *</label>
                            <textarea name="rejection_reason" rows="4" required
                                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
                                      placeholder="Please provide a detailed reason for rejecting this application..."></textarea>
                            <p class="text-xs text-gray-500 mt-1">This reason will be visible to the student</p>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Admin Remarks (Optional)</label>
                            <textarea name="admin_remarks" rows="3" 
                                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
                                      placeholder="Internal notes about this rejection..."></textarea>
                        </div>
                        
                        <div class="flex gap-4">
                            <button type="submit" name="reject" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all font-semibold">
                                <i class="fas fa-times mr-2"></i> Confirm Rejection
                            </button>
                            <a href="leave_management.php" class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all text-center font-semibold">
                                <i class="fas fa-arrow-left mr-2"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Warning Note -->
                <div class="mt-6 p-4 bg-yellow-50 border-l-4 border-yellow-500 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 mr-3"></i>
                        <div>
                            <p class="text-sm font-medium text-yellow-800">Important Note</p>
                            <p class="text-sm text-yellow-700 mt-1">Rejecting an application will notify the student and they will not be able to reapply for the same dates. Please ensure you provide a clear reason for rejection.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>