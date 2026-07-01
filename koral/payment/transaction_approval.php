<?php
// transaction_approval.php
include '../db_connection.php';
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$success_message = '';
$error_message = '';

// Function to update fee installments and student fees
function updateFeeInstallmentsAndStudent($transaction_id, $db, $user_id) {
    try {
        // Get transaction details
        $stmt = $db->prepare("
            SELECT t.*, s.student_id, s.enrollment_fees, s.total_fees_paid, 
                   s.fees_status, s.next_payment_due_date,
                   s.batch_name as student_batch
            FROM transactions t
            LEFT JOIN students s ON t.student_id = s.student_id
            WHERE t.id = ?
        ");
        $stmt->execute([$transaction_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            throw new Exception("Transaction not found");
        }
        
        $student_id = $transaction['student_id'];
        $transaction_amount = $transaction['amount'];
        
        // 1. Update fee_installments table
        // First, check if there are any pending installments for this student
        $installmentStmt = $db->prepare("
            SELECT * FROM fee_installments 
            WHERE student_id = ? 
            AND payment_status = 'pending'
            ORDER BY installment_number ASC
            LIMIT 1
        ");
        $installmentStmt->execute([$student_id]);
        $pendingInstallment = $installmentStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pendingInstallment) {
            // Update the pending installment
            $updateInstallmentStmt = $db->prepare("
                UPDATE fee_installments 
                SET paid_amount = ?,
                    payment_date = ?,
                    payment_method = ?,
                    payment_status = CASE 
                        WHEN ? >= installment_amount THEN 'paid'
                        ELSE 'partially_paid'
                    END,
                    transaction_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateInstallmentStmt->execute([
                $transaction_amount,
                $transaction['transaction_date'],
                $transaction['payment_mode'],
                $transaction_amount,
                $transaction['transaction_id'],
                $pendingInstallment['id']
            ]);
        } else {
            // Create a new installment record for this payment
            // Get the next installment number
            $maxInstallmentStmt = $db->prepare("
                SELECT MAX(installment_number) as max_number 
                FROM fee_installments 
                WHERE student_id = ?
            ");
            $maxInstallmentStmt->execute([$student_id]);
            $maxResult = $maxInstallmentStmt->fetch(PDO::FETCH_ASSOC);
            $nextInstallmentNumber = ($maxResult['max_number'] ?: 0) + 1;
            
            // Calculate next due date (1 month from approval date)
            $nextDueDate = date('Y-m-d', strtotime('+1 month'));
            
            $insertInstallmentStmt = $db->prepare("
                INSERT INTO fee_installments (
                    student_id, installment_number, installment_amount,
                    due_date, paid_amount, payment_date, payment_method,
                    payment_status, transaction_id, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, NOW(), NOW())
            ");
            $insertInstallmentStmt->execute([
                $student_id,
                $nextInstallmentNumber,
                $transaction_amount,
                $nextDueDate,
                $transaction_amount,
                $transaction['transaction_date'],
                $transaction['payment_mode'],
                $transaction['transaction_id'],
                $user_id
            ]);
        }
        
        // 2. Update students table
        // Calculate new total fees paid
        $new_total_fees_paid = $transaction['total_fees_paid'] + $transaction_amount;
        
        // Determine fees status based on enrollment fees
        $enrollment_fees = $transaction['enrollment_fees'] ?: 0;
        $fees_status = 'partially_paid';
        
        if ($enrollment_fees > 0) {
            if ($new_total_fees_paid >= $enrollment_fees) {
                $fees_status = 'fully_paid';
            } elseif ($new_total_fees_paid > 0) {
                $fees_status = 'partially_paid';
            } else {
                $fees_status = 'unpaid';
            }
        }
        
        // Calculate next payment due date (1 month from transaction date)
        $next_payment_due_date = date('Y-m-d', strtotime($transaction['transaction_date'] . ' +1 month'));
        
        $updateStudentStmt = $db->prepare("
            UPDATE students 
            SET total_fees_paid = ?,
                fees_status = ?,
                last_payment_date = ?,
                next_payment_due_date = ?
            WHERE student_id = ?
        ");
        $updateStudentStmt->execute([
            $new_total_fees_paid,
            $fees_status,
            $transaction['transaction_date'],
            $next_payment_due_date,
            $student_id
        ]);
        
        // 3. Create a log entry for the fee update
        $logStmt = $db->prepare("
            INSERT INTO student_status_log (
                student_id, action, reason, enrollment_fees,
                fees_status, processed_by, processed_at
            ) VALUES (?, 'payment_received', ?, ?, ?, ?, NOW())
        ");
        $logStmt->execute([
            $student_id,
            "Payment received via transaction ID: " . $transaction['transaction_id'],
            $enrollment_fees,
            $fees_status,
            $user_id
        ]);
        
        return [
            'success' => true,
            'message' => 'Fee records updated successfully',
            'student_id' => $student_id,
            'new_total_fees_paid' => $new_total_fees_paid,
            'fees_status' => $fees_status,
            'next_payment_due_date' => $next_payment_due_date
        ];
        
    } catch (Exception $e) {
        error_log("Failed to update fee records for transaction {$transaction_id}: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Handle approval via GET
if (isset($_GET['approve'])) {
    $transaction_id = (int)$_GET['approve'];
    
    // Ask for confirmation
    if (!isset($_GET['confirm'])) {
        $stmt = $db->prepare("
            SELECT t.*, s.email, s.first_name, s.last_name,
                   CONCAT(s.first_name, ' ', s.last_name) as student_name
            FROM transactions t
            LEFT JOIN students s ON t.student_id = s.student_id
            WHERE t.id = ? AND t.status = 'pending'
        ");
        $stmt->execute([$transaction_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($transaction) {
            echo "<script>
                if (confirm('Approve this transaction?\\n\\nStudent: " . addslashes($transaction['student_name']) . "\\nAmount: ₹" . number_format($transaction['amount'], 2) . "\\n\\nThis will update the student\\'s fee records.')) {
                    window.location.href = 'transaction_approval.php?approve=" . $transaction_id . "&confirm=yes';
                } else {
                    window.location.href = 'transaction_approval.php';
                }
            </script>";
            exit;
        }
    }
    
    try {
        // Start transaction for the entire approval process
        $db->beginTransaction();
        
        // Update transaction status
        $stmt = $db->prepare("
            UPDATE transactions 
            SET status = 'verified', 
                verified_by = ?, 
                verified_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$_SESSION['user_id'], $transaction_id]);
        
        if ($stmt->rowCount() > 0) {
            // Update fee installments and student records
            $feeUpdateResult = updateFeeInstallmentsAndStudent($transaction_id, $db, $_SESSION['user_id']);
            
            if (!$feeUpdateResult['success']) {
                throw new Exception("Failed to update fee records: " . $feeUpdateResult['error']);
            }
            
            $success_message = "Transaction verified successfully! Fee records updated.";
            
            // Add fee update details to success message
            if (isset($feeUpdateResult['student_id'])) {
                $success_message .= " Student ID: " . $feeUpdateResult['student_id'] . 
                                   ", New Total Paid: ₹" . number_format($feeUpdateResult['new_total_fees_paid'], 2) . 
                                   ", Status: " . $feeUpdateResult['fees_status'] .
                                   ", Next Due: " . $feeUpdateResult['next_payment_due_date'];
            }
        } else {
            throw new Exception("Transaction not found or already processed.");
        }
        
        // Commit transaction
        $db->commit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = "Failed to update transaction: " . $e->getMessage();
    }
    
    // Redirect back
    $redirectParams = [];
    if ($success_message) $redirectParams['success'] = $success_message;
    if ($error_message) $redirectParams['error'] = $error_message;
    if (isset($_GET['status'])) $redirectParams['status'] = $_GET['status'];
    
    $redirectUrl = 'transaction_approval.php' . ($redirectParams ? '?' . http_build_query($redirectParams) : '');
    header("Location: $redirectUrl");
    exit;
    
} elseif (isset($_GET['reject'])) {
    $transaction_id = (int)$_GET['reject'];
    
    // Get transaction details for confirmation
    $stmt = $db->prepare("
        SELECT t.*, s.first_name, s.last_name,
               CONCAT(s.first_name, ' ', s.last_name) as student_name
        FROM transactions t
        LEFT JOIN students s ON t.student_id = s.student_id
        WHERE t.id = ? AND t.status = 'pending'
    ");
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        $error_message = "Transaction not found or already processed.";
        header("Location: transaction_approval.php?error=" . urlencode($error_message));
        exit;
    }
    
    // Show rejection modal if not submitted
    if (!isset($_POST['rejection_reason'])) {
        // Return JSON response for AJAX or show HTML modal
        if (isset($_GET['ajax'])) {
            echo json_encode([
                'success' => true,
                'html' => '
                <div class="rejection-modal">
                    <div class="modal-header">
                        <h3>Reject Transaction</h3>
                    </div>
                    <div class="modal-body">
                        <p><strong>Transaction Details:</strong></p>
                        <ul>
                            <li>Student: ' . htmlspecialchars($transaction['student_name']) . '</li>
                            <li>Amount: ₹' . number_format($transaction['amount'], 2) . '</li>
                            <li>Date: ' . date('d/m/Y', strtotime($transaction['transaction_date'])) . '</li>
                            <li>Payment Mode: ' . ucfirst($transaction['payment_mode']) . '</li>
                        </ul>
                        <form id="rejectForm" method="POST" action="transaction_approval.php?reject=' . $transaction_id . '">
                            <div class="form-group">
                                <label for="rejection_reason">Reason for Rejection *</label>
                                <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="4" required 
                                          placeholder="Please provide a clear reason for rejecting this transaction..."></textarea>
                            </div>
                            <div class="form-group">
                                <label for="notify_student">
                                    <input type="checkbox" name="notify_student" id="notify_student" value="1">
                                    Notify student about rejection
                                </label>
                            </div>
                            <div class="modal-actions">
                                <button type="button" class="btn-secondary" onclick="hideModal()">Cancel</button>
                                <button type="submit" class="btn-danger">Confirm Rejection</button>
                            </div>
                        </form>
                    </div>
                </div>
                '
            ]);
            exit;
        } else {
            // Fallback to prompt for non-AJAX
            echo "<script>
                var reason = prompt('Please enter reason for rejection:', '');
                if (reason !== null && reason.trim() !== '') {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'transaction_approval.php?reject=" . $transaction_id . "';
                    
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'rejection_reason';
                    input.value = reason;
                    form.appendChild(input);
                    
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    window.location.href = 'transaction_approval.php';
                }
            </script>";
            exit;
        }
    }
    
    // Process rejection
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rejection_reason'])) {
        $rejection_reason = trim($_POST['rejection_reason']);
        $notify_student = isset($_POST['notify_student']) ? 1 : 0;
        
        if (empty($rejection_reason)) {
            $error_message = "Rejection reason is required.";
            header("Location: transaction_approval.php?error=" . urlencode($error_message));
            exit;
        }
        
        try {
            $db->beginTransaction();
            
            // Update transaction status to rejected
            $stmt = $db->prepare("
                UPDATE transactions 
                SET status = 'rejected', 
                    verified_by = ?, 
                    verified_at = NOW(),
                    rejection_reason = ?,
                    remarks = CONCAT(COALESCE(remarks, ''), '\\nRejected: ', ?)
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                $rejection_reason,
                $rejection_reason,
                $transaction_id
            ]);
            
            if ($stmt->rowCount() > 0) {
                // Log the rejection
                $logStmt = $db->prepare("
                    INSERT INTO student_status_log (
                        student_id, action, reason, processed_by, processed_at
                    ) VALUES (?, 'payment_rejected', ?, ?, NOW())
                ");
                $logStmt->execute([
                    $transaction['student_id'],
                    "Transaction ID: " . $transaction['transaction_id'] . " rejected. Reason: " . $rejection_reason,
                    $_SESSION['user_id']
                ]);
                
                // Send notification to student if requested
                if ($notify_student) {
                    // Get student email
                    $emailStmt = $db->prepare("
                        SELECT email FROM students WHERE student_id = ?
                    ");
                    $emailStmt->execute([$transaction['student_id']]);
                    $student = $emailStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($student && !empty($student['email'])) {
                        // Send email notification
                        $subject = "Transaction Rejected - " . $transaction['transaction_id'];
                        $message = "
                            Dear Student,\n\n
                            Your transaction with ID: " . $transaction['transaction_id'] . " has been rejected.\n
                            Amount: ₹" . number_format($transaction['amount'], 2) . "\n
                            Date: " . $transaction['transaction_date'] . "\n
                            Reason: " . $rejection_reason . "\n\n
                            Please contact the accounts department for more information.\n\n
                            Regards,\n
                            ASD Academy Accounts Team
                        ";
                        $headers = "From: accounts@asdacademy.com";
                        
                        // In production, use a proper email library like PHPMailer
                        // mail($student['email'], $subject, $message, $headers);
                        
                        // Log email sent
                        error_log("Rejection email sent to: " . $student['email'] . " for transaction: " . $transaction['transaction_id']);
                    }
                }
                
                $success_message = "Transaction rejected successfully!";
                if ($notify_student) {
                    $success_message .= " Student has been notified.";
                }
                
                $db->commit();
            } else {
                throw new Exception("Transaction not found or already processed.");
            }
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error_message = "Failed to reject transaction: " . $e->getMessage();
        }
        
        // Redirect back
        $redirectParams = [];
        if ($success_message) $redirectParams['success'] = $success_message;
        if ($error_message) $redirectParams['error'] = $error_message;
        if (isset($_GET['status'])) $redirectParams['status'] = $_GET['status'];
        
        $redirectUrl = 'transaction_approval.php' . ($redirectParams ? '?' . http_build_query($redirectParams) : '');
        header("Location: $redirectUrl");
    }
}

// Handle receipt email sending
if (isset($_GET['send_receipt'])) {
    $transaction_id = (int)$_GET['send_receipt'];
    
    // Check if transaction is verified
    $stmt = $db->prepare("
        SELECT t.*, s.email as student_email, 
               s.first_name, s.last_name,
               CONCAT(s.first_name, ' ', s.last_name) as student_name
        FROM transactions t
        LEFT JOIN students s ON t.student_id = s.student_id
        WHERE t.id = ? AND t.status = 'verified'
    ");
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        $error_message = "Transaction not found or not verified.";
    } elseif (empty($transaction['student_email'])) {
        $error_message = "Student email not found. Cannot send receipt.";
    } else {
        // Include the send_receipt_email.php file
        require_once 'send_receipt_email.php';
        
        // Call the email sending function directly
        $response = sendReceiptEmail($transaction_id, $db, $_SESSION['user_id']);
        
        if ($response['success']) {
            $success_message = $response['message'];
        } else {
            $error_message = $response['message'];
        }
    }
    
    // Redirect back
    $redirectParams = [];
    if ($success_message) $redirectParams['success'] = $success_message;
    if ($error_message) $redirectParams['error'] = $error_message;
    if (isset($_GET['status'])) $redirectParams['status'] = $_GET['status'];
    
    $redirectUrl = 'transaction_approval.php' . ($redirectParams ? '?' . http_build_query($redirectParams) : '');
    header("Location: $redirectUrl");
    exit;
}

// Handle POST approval/rejection (for backward compatibility)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $transaction_id = (int)$_POST['transaction_id'];
    $action = $_POST['action'];
    $notes = trim($_POST['notes'] ?? '');
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    try {
        if ($action === 'approve') {
            // Start transaction for the entire approval process
            $db->beginTransaction();
            
            // Update transaction status
            $stmt = $db->prepare("
                UPDATE transactions 
                SET status = 'verified', 
                    verified_by = ?, 
                    verified_at = NOW(),
                    verification_notes = ?
                WHERE id = ? AND status = 'pending'
            ");
            $result = $stmt->execute([$_SESSION['user_id'], $notes, $transaction_id]);
            
            if ($stmt->rowCount() > 0) {
                // Update fee installments and student records
                $feeUpdateResult = updateFeeInstallmentsAndStudent($transaction_id, $db, $_SESSION['user_id']);
                
                if (!$feeUpdateResult['success']) {
                    throw new Exception("Failed to update fee records: " . $feeUpdateResult['error']);
                }
                
                $success_message = "Transaction verified successfully! Fee records updated.";
            } else {
                throw new Exception("Transaction not found or already processed.");
            }
            
            // Commit transaction
            $db->commit();
            
        } elseif ($action === 'reject') {
            if (empty($rejection_reason)) {
                throw new Exception("Rejection reason is required.");
            }
            
            $stmt = $db->prepare("
                UPDATE transactions 
                SET status = 'rejected', 
                    verified_by = ?, 
                    verified_at = NOW(),
                    rejection_reason = ?,
                    remarks = CONCAT(COALESCE(remarks, ''), '\\nRejected: ', ?)
                WHERE id = ? AND status = 'pending'
            ");
            $result = $stmt->execute([
                $_SESSION['user_id'], 
                $rejection_reason,
                $rejection_reason,
                $transaction_id
            ]);
            
            if ($stmt->rowCount() > 0) {
                // Log the rejection
                $logStmt = $db->prepare("
                    INSERT INTO student_status_log (
                        student_id, action, reason, processed_by, processed_at
                    ) VALUES (?, 'payment_rejected', ?, ?, NOW())
                ");
                $logStmt->execute([
                    $_POST['student_id'] ?? '',
                    "Transaction ID: " . ($_POST['transaction_id'] ?? '') . " rejected. Reason: " . $rejection_reason,
                    $_SESSION['user_id']
                ]);
                
                $success_message = "Transaction rejected successfully!";
            } else {
                $error_message = "Transaction not found or already processed.";
            }
        }
        
    } catch (Exception $e) {
        // Rollback transaction if one is active
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = "Failed to update transaction: " . $e->getMessage();
    }
    
    // Redirect back
    $redirectParams = [];
    if ($success_message) $redirectParams['success'] = $success_message;
    if ($error_message) $redirectParams['error'] = $error_message;
    if (isset($_GET['status'])) $redirectParams['status'] = $_GET['status'];
    
    $redirectUrl = 'transaction_approval.php' . ($redirectParams ? '?' . http_build_query($redirectParams) : '');
    header("Location: $redirectUrl");
    exit;
}

// Get success/error messages from URL
if (isset($_GET['success'])) {
    $success_message = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = urldecode($_GET['error']);
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'pending';
$filter_batch = $_GET['batch'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_student = $_GET['student'] ?? '';

// Build query
$query = "
    SELECT t.*, 
           b.batch_name,
           u.name as verified_by_name,
           s.total_fees_paid,
           s.fees_status,
           s.enrollment_fees,
           s.email as student_email,
           s.first_name,
           s.last_name,
           CONCAT(s.first_name, ' ', s.last_name) as student_name
    FROM transactions t
    LEFT JOIN batches b ON t.batch_id = b.batch_id
    LEFT JOIN users u ON t.verified_by = u.id
    LEFT JOIN students s ON t.student_id = s.student_id
    WHERE 1=1
";
$params = [];

if ($filter_status !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $filter_status;
}

if ($filter_batch) {
    $query .= " AND t.batch_id = ?";
    $params[] = $filter_batch;
}

if ($filter_date_from) {
    $query .= " AND DATE(t.transaction_date) >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to) {
    $query .= " AND DATE(t.transaction_date) <= ?";
    $params[] = $filter_date_to;
}

if ($filter_student) {
    $query .= " AND (t.student_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
    $search_param = "%$filter_student%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY t.uploaded_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get batches for filter
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total_transactions,
        SUM(status = 'pending') as pending,
        SUM(status = 'verified') as verified,
        SUM(status = 'rejected') as rejected,
        SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END) as total_verified_amount,
        SUM(amount) as total_amount
    FROM transactions
")->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Approval - ASD Academy</title>
    <?php include '../header.php'; ?>
    <style>
        :root {
            --primary-color: #667eea;
            --success-color: #38a169;
            --warning-color: #dd6b20;
            --danger-color: #e53e3e;
            --info-color: #4299e1;
            --purple-color: #9f7aea;
            --teal-color: #38b2ac;
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
            cursor: pointer;
            animation: fadeInUp 0.6s ease-out forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        
        @keyframes fadeInUp {
            0% {
                opacity: 0;
                transform: translateY(20px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
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
        
        .status-pending {
            background: linear-gradient(135deg, #feebc8 0%, #fbd38d 100%);
            color: #9c4221;
            border: 1px solid #fbd38d;
        }
        
        .status-verified {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #9b2c2c;
            border: 1px solid #feb2b2;
        }
        
        .action-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
        }
        
        .amount-badge {
            font-weight: bold;
            font-size: 1.1rem;
            color: #2d3748;
        }
        
        .transaction-card {
            animation: slideIn 0.5s ease-out forwards;
            opacity: 0;
            transform: translateX(-20px);
        }
        
        @keyframes slideIn {
            0% {
                opacity: 0;
                transform: translateX(-20px);
            }
            100% {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .quick-actions {
            display: flex;
            gap: 8px;
        }
        
        .quick-action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
        }
        
        .quick-view-btn {
            background: rgba(66, 153, 225, 0.1);
            color: #4299e1;
            border-color: rgba(66, 153, 225, 0.2);
        }
        
        .quick-view-btn:hover {
            background: rgba(66, 153, 225, 0.2);
            transform: translateY(-1px);
        }
        
        .quick-approve-btn {
            background: rgba(72, 187, 120, 0.1);
            color: #38a169;
            border-color: rgba(72, 187, 120, 0.2);
        }
        
        .quick-approve-btn:hover {
            background: rgba(72, 187, 120, 0.2);
            transform: translateY(-1px);
        }
        
        .quick-reject-btn {
            background: rgba(245, 101, 101, 0.1);
            color: #e53e3e;
            border-color: rgba(245, 101, 101, 0.2);
        }
        
        .quick-reject-btn:hover {
            background: rgba(245, 101, 101, 0.2);
            transform: translateY(-1px);
        }
        
        .quick-receipt-btn {
            background: rgba(159, 122, 234, 0.1);
            color: #9f7aea;
            border-color: rgba(159, 122, 234, 0.2);
        }
        
        .quick-receipt-btn:hover {
            background: rgba(159, 122, 234, 0.2);
            transform: translateY(-1px);
        }
        
        .quick-email-btn {
            background: rgba(56, 178, 172, 0.1);
            color: #38b2ac;
            border-color: rgba(56, 178, 172, 0.2);
        }
        
        .quick-email-btn:hover {
            background: rgba(56, 178, 172, 0.2);
            transform: translateY(-1px);
        }
        
        .badge-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .badge-pending { background: #fbbf24; color: #78350f; }
        .badge-verified { background: #34d399; color: #064e3b; }
        .badge-rejected { background: #f87171; color: #7f1d1d; }
        
        .filter-active {
            border: 2px solid #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 10px;
            color: white;
            z-index: 10000;
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.27, 1.55);
            min-width: 300px;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .notification.success {
            background: linear-gradient(135deg, #48bb78, #38a169);
        }
        
        .notification.error {
            background: linear-gradient(135deg, #f56565, #e53e3e);
        }
        
        .notification.info {
            background: linear-gradient(135deg, #4299e1, #3182ce);
        }
        
        .new-badge {
            background: #e53e3e;
            color: white;
            font-size: 0.6rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0% { 
                transform: scale(1); 
                opacity: 1; 
            }
            50% { 
                transform: scale(1.1); 
                opacity: 0.8; 
            }
            100% { 
                transform: scale(1); 
                opacity: 1; 
            }
        }
        
        .progress-bar {
            background: #e5e7eb;
            border-radius: 9999px;
            height: 4px;
            overflow: hidden;
        }
        
        .progress-bar div {
            height: 100%;
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .modal-overlay.show {
            display: flex;
            opacity: 1;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 24px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            transform: translateY(-20px);
            opacity: 0;
        }
        
        @keyframes modalSlideIn {
            0% {
                opacity: 0;
                transform: translateY(-20px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .rejection-modal {
            width: 100%;
        }
        
        .modal-header {
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #1f2937;
            font-size: 1.25rem;
        }
        
        .modal-body {
            padding: 0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #374151;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
        }
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }
        
        .btn-secondary {
            padding: 10px 20px;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .btn-danger {
            padding: 10px 20px;
            background: linear-gradient(135deg, #f56565, #e53e3e);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #e53e3e, #c53030);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
            transform: translateY(-2px);
        }
        
        .fees-info {
            font-size: 0.75rem;
            color: #666;
            margin-top: 2px;
        }
        
        .fees-paid {
            color: #38a169;
            font-weight: 500;
        }
        
        .fees-pending {
            color: #dd6b20;
            font-weight: 500;
        }
        
        .receipt-status {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            background: #e5e7eb;
            color: #4b5563;
            margin-top: 2px;
            display: inline-block;
        }
        
        .receipt-generated {
            background: #d1fae5;
            color: #065f46;
        }
        
        .email-sent {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .email-not-sent {
            background: #fed7d7;
            color: #9b2c2c;
        }
        
        /* Expandable row styles */
        .expand-row {
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        
        .expand-row:hover {
            background-color: rgba(0, 0, 0, 0.02);
            transform: translateY(-1px);
        }
        
        .row-details {
            display: none;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .row-details.active {
            display: table-row;
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        
        @keyframes slideDown {
            0% {
                max-height: 0;
                opacity: 0;
                transform: translateY(-10px);
            }
            100% {
                max-height: 1000px;
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .details-content {
            padding: 24px;
            background: white;
            animation: fadeIn 0.3s ease-out 0.1s forwards;
            opacity: 0;
        }
        
        @keyframes fadeIn {
            0% {
                opacity: 0;
            }
            100% {
                opacity: 1;
            }
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
        }
        
        .details-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .details-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05);
        }
        
        .details-section h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
        }
        
        .details-section h4 i {
            margin-right: 8px;
            color: #667eea;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px dashed #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .detail-item:hover {
            border-bottom-color: #667eea;
            padding-bottom: 14px;
        }
        
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .detail-label {
            font-weight: 500;
            color: #4a5568;
            font-size: 0.875rem;
        }
        
        .detail-value {
            color: #2d3748;
            font-weight: 600;
            text-align: right;
            max-width: 200px;
            word-break: break-word;
        }
        
        .detail-value.status {
            font-size: 0.75rem;
            padding: 4px 12px;
            border-radius: 9999px;
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .detail-value.status-pending {
            background: #feebc8;
            color: #9c4221;
        }
        
        .detail-value.status-verified {
            background: #c6f6d5;
            color: #276749;
        }
        
        .detail-value.status-rejected {
            background: #fed7d7;
            color: #9b2c2c;
        }
        
        .detail-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }
        
        .expand-toggle {
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            margin-right: 12px;
            color: #667eea;
            position: relative;
        }
        
        .expand-toggle i {
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .expand-toggle.expanded i {
            transform: rotate(180deg);
        }
        
        .expand-toggle:hover {
            color: #805ad5;
            transform: scale(1.1);
        }
        
        .screenshot-container {
            margin-top: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .screenshot-container:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: #667eea;
        }
        
        .screenshot-container img, .screenshot-container iframe {
            width: 100%;
            height: auto;
            max-height: 400px;
            object-fit: contain;
            transition: transform 0.3s ease;
        }
        
        .screenshot-container img:hover {
            transform: scale(1.02);
        }
        
        .no-screenshot {
            text-align: center;
            padding: 20px;
            color: #718096;
            font-style: italic;
        }
        
        .actions-container {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }
        
        .action-button {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            cursor: pointer;
            font-size: 0.875rem;
            min-width: 120px;
            position: relative;
            overflow: hidden;
        }
        
        .action-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s ease;
        }
        
        .action-button:hover::before {
            left: 100%;
        }
        
        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .action-button:active {
            transform: translateY(-1px);
        }
        
        .action-button.approve {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
        }
        
        .action-button.reject {
            background: linear-gradient(135deg, #f56565, #e53e3e);
            color: white;
        }
        
        .action-button.view {
            background: linear-gradient(135deg, #4299e1, #3182ce);
            color: white;
        }
        
        .action-button.receipt {
            background: linear-gradient(135deg, #9f7aea, #805ad5);
            color: white;
        }
        
        .action-button.email {
            background: linear-gradient(135deg, #38b2ac, #319795);
            color: white;
        }
        
        .action-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        
        .action-button:disabled::before {
            display: none;
        }
        
        .expandable-header {
            display: flex;
            align-items: center;
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 12px;
            transition: all 0.3s ease;
        }
        
        .expand-row:hover .student-avatar {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .table-header {
            position: relative;
            overflow: hidden;
        }
        
        .table-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, #667eea, #9f7aea);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }
        
        .table-header:hover::after {
            transform: translateX(0);
        }
        
        /* Smooth scrolling for the whole page */
        html {
            scroll-behavior: smooth;
        }
        
        /* Loading animation for action buttons */
        .action-button.loading {
            position: relative;
            color: transparent;
        }
        
        .action-button.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Ripple effect for buttons */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        
        /* Floating animation for stat cards */
        .stat-card:hover {
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        /* Shimmer effect for loading states */
        .shimmer {
            background: linear-gradient(90deg, 
                rgba(255,255,255,0) 0%, 
                rgba(255,255,255,0.3) 50%, 
                rgba(255,255,255,0) 100%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-check-circle text-green-500"></i>
                <span>Transaction Approval</span>
                <span class="text-sm font-normal text-gray-500 hidden md:inline">
                    | Manage payments & update fee records
                </span>
            </h1>
            <div class="flex items-center space-x-3">
                <span class="text-sm text-gray-500 hidden md:block">
                    <i class="fas fa-receipt mr-1"></i>
                    Total: <span class="font-semibold"><?php echo $stats['total_transactions']; ?></span>
                </span>
                <a href="a.php" target="_blank" 
                   class="bg-gradient-to-r from-blue-500 to-purple-600 text-white px-4 py-2 rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all action-btn shadow-lg flex items-center relative overflow-hidden">
                    <i class="fas fa-plus mr-2"></i>New Transaction
                </a>
            </div>
        </header>

        <!-- Statistics Cards -->
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="glass-card stat-card p-6 cursor-pointer <?php echo $filter_status === 'pending' ? 'filter-active' : ''; ?>" 
                 onclick="filterByStatus('pending')" style="animation-delay: 0.1s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Pending</p>
                        <h3 class="text-3xl font-bold text-yellow-600"><?php echo $stats['pending']; ?></h3>
                        <p class="text-xs text-gray-400 mt-1">Requires approval</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-full flex items-center justify-center shadow-inner">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="progress-bar bg-gray-200 rounded-full h-2">
                        <div class="bg-yellow-500 h-2 rounded-full" style="width: <?php echo $stats['total_transactions'] > 0 ? ($stats['pending']/$stats['total_transactions'])*100 : 0; ?>%"></div>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-6 cursor-pointer <?php echo $filter_status === 'verified' ? 'filter-active' : ''; ?>" 
                 onclick="filterByStatus('verified')" style="animation-delay: 0.2s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Verified</p>
                        <h3 class="text-3xl font-bold text-green-600"><?php echo $stats['verified']; ?></h3>
                        <p class="text-xs text-gray-400 mt-1">Fee records updated</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 rounded-full flex items-center justify-center shadow-inner">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="progress-bar bg-gray-200 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full" style="width: <?php echo $stats['total_transactions'] > 0 ? ($stats['verified']/$stats['total_transactions'])*100 : 0; ?>%"></div>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-6 cursor-pointer <?php echo $filter_status === 'rejected' ? 'filter-active' : ''; ?>" 
                 onclick="filterByStatus('rejected')" style="animation-delay: 0.3s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Rejected</p>
                        <h3 class="text-3xl font-bold text-red-600"><?php echo $stats['rejected']; ?></h3>
                        <p class="text-xs text-gray-400 mt-1">Rejected transactions</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-red-100 to-red-200 rounded-full flex items-center justify-center shadow-inner">
                        <i class="fas fa-times-circle text-red-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="progress-bar bg-gray-200 rounded-full h-2">
                        <div class="bg-red-500 h-2 rounded-full" style="width: <?php echo $stats['total_transactions'] > 0 ? ($stats['rejected']/$stats['total_transactions'])*100 : 0; ?>%"></div>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-6" style="animation-delay: 0.4s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Total Verified Amount</p>
                        <h3 class="text-3xl font-bold text-blue-600">₹<?php echo number_format($stats['total_verified_amount'], 2); ?></h3>
                        <p class="text-xs text-gray-400 mt-1">All verified transactions</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-blue-100 to-blue-200 rounded-full flex items-center justify-center shadow-inner">
                        <i class="fas fa-rupee-sign text-blue-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="progress-bar bg-gray-200 rounded-full h-2">
                        <div class="bg-blue-500 h-2 rounded-full" style="width: 100%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="px-6">
            <div class="glass-card filter-container p-6 mb-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-filter mr-1 text-gray-400"></i>Status
                        </label>
                        <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="verified" <?php echo $filter_status === 'verified' ? 'selected' : ''; ?>>Verified</option>
                            <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-layer-group mr-1 text-gray-400"></i>Batch
                        </label>
                        <select name="batch" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                            <option value="">All Batches</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?php echo htmlspecialchars($batch['batch_id']); ?>"
                                    <?php echo $filter_batch == $batch['batch_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($batch['batch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-alt mr-1 text-gray-400"></i>From Date
                        </label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-alt mr-1 text-gray-400"></i>To Date
                        </label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-search mr-1 text-gray-400"></i>Student
                        </label>
                        <input type="text" name="student" value="<?php echo htmlspecialchars($filter_student); ?>" 
                               placeholder="Name or ID" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                    </div>
                    
                    <div class="lg:col-span-5 flex justify-end space-x-3 mt-4">
                        <button type="submit" 
                                class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-6 py-2 rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all action-btn shadow-md flex items-center relative overflow-hidden">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                        <a href="?" 
                           class="bg-gradient-to-r from-gray-200 to-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:from-gray-300 hover:to-gray-400 transition-all shadow-md flex items-center relative overflow-hidden">
                            <i class="fas fa-redo mr-2"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="px-6 pb-6">
            <div class="glass-card overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center table-header">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-list mr-2 text-blue-500"></i>
                        Transactions
                        <span class="text-sm font-normal text-gray-600 ml-2">
                            (Showing <?php echo count($transactions); ?> records)
                        </span>
                    </h2>
                    <div class="flex items-center space-x-3">
                        <div class="text-sm text-gray-600 flex items-center space-x-2">
                            <span class="flex items-center">
                                <span class="w-3 h-3 bg-yellow-400 rounded-full mr-1"></span>
                                Pending: <span class="font-semibold ml-1"><?php echo $stats['pending']; ?></span>
                            </span>
                            <span class="flex items-center">
                                <span class="w-3 h-3 bg-green-500 rounded-full mr-1"></span>
                                Verified: <span class="font-semibold ml-1"><?php echo $stats['verified']; ?></span>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount & Fees</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($transactions as $index => $transaction): ?>
                            <?php 
                                // Get file extension
                                $file_ext = strtolower(pathinfo($transaction['screenshot_path'], PATHINFO_EXTENSION));
                                $is_pdf = $file_ext === 'pdf';
                                $file_url = '../uploads/transactions/' . $transaction['screenshot_path'];
                                
                                // Check if transaction is new (within 24 hours)
                                $is_new = $transaction['status'] === 'verified' && strtotime($transaction['verified_at']) > strtotime('-24 hours');
                                
                                // Calculate fees information
                                $enrollment_fees = $transaction['enrollment_fees'] ?: 0;
                                $total_fees_paid = $transaction['total_fees_paid'] ?: 0;
                                $fees_status = $transaction['fees_status'] ?: 'unpaid';
                                
                                // Check if receipt has been generated
                                $receipt_generated = !empty($transaction['receipt_path']);
                                $receipt_status_class = $receipt_generated ? 'receipt-generated' : '';
                                $receipt_status_text = $receipt_generated ? 'Receipt Generated' : 'No Receipt';
                                
                                // Check if email has been sent
                                $email_sent = !empty($transaction['receipt_sent']) && $transaction['receipt_sent'] == 1;
                                $email_status_class = $email_sent ? 'email-sent' : 'email-not-sent';
                                $email_status_text = $email_sent ? 'Email Sent' : 'Email Pending';
                                
                                // Check if student has email
                                $has_email = !empty($transaction['student_email']);
                                $email_available = $has_email ? 'Email Available' : 'No Email';
                                
                                // Get student name from CONCAT or from first_name/last_name
                                $student_name = $transaction['student_name'] ?? 
                                               $transaction['student_name'] ?? 
                                               $transaction['first_name'] . ' ' . $transaction['last_name'];
                                
                                // Get first letter for avatar
                                $first_letter = strtoupper(substr($student_name, 0, 1));
                            ?>
                            <!-- Main Row -->
                            <tr class="transaction-card expand-row hover:shadow-md" 
                                style="animation-delay: <?php echo $index * 0.05; ?>s"
                                onclick="toggleRowDetails(<?php echo $transaction['id']; ?>)"
                                id="row-<?php echo $transaction['id']; ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="expandable-header">
                                        <div class="expand-toggle">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($transaction['transaction_id']); ?>
                                                <?php if ($is_new): ?>
                                                    <span class="new-badge">NEW</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('M d, Y H:i', strtotime($transaction['uploaded_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="student-avatar">
                                            <?php echo $first_letter; ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student_name); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($transaction['student_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($transaction['batch_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($transaction['batch_id']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="amount-badge">₹<?php echo number_format($transaction['amount'], 2); ?></span>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="fas fa-credit-card mr-1"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $transaction['payment_mode'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                        <i class="fas fa-<?php 
                                            echo $transaction['status'] === 'pending' ? 'clock' : 
                                                  ($transaction['status'] === 'verified' ? 'check-circle' : 'times-circle'); 
                                        ?> mr-1"></i>
                                        <?php echo ucfirst($transaction['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button onclick="event.stopPropagation(); viewTransactionDetails(<?php echo $transaction['id']; ?>)" 
                                                class="text-blue-600 hover:text-blue-900 action-btn px-3 py-1 rounded-lg border border-blue-200 hover:bg-blue-50 transition-all shadow-sm flex items-center relative overflow-hidden">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </button>
                                        <?php if ($transaction['status'] === 'pending'): ?>
                                            <button onclick="event.stopPropagation(); approveTransaction(<?php echo $transaction['id']; ?>)" 
                                                    class="text-green-600 hover:text-green-900 action-btn px-3 py-1 rounded-lg border border-green-200 hover:bg-green-50 transition-all shadow-sm flex items-center relative overflow-hidden">
                                                <i class="fas fa-check mr-1"></i> Approve
                                            </button>
                                            <button onclick="event.stopPropagation(); showRejectModal(<?php echo $transaction['id']; ?>)" 
                                                    class="text-red-600 hover:text-red-900 action-btn px-3 py-1 rounded-lg border border-red-200 hover:bg-red-50 transition-all shadow-sm flex items-center relative overflow-hidden">
                                                <i class="fas fa-times mr-1"></i> Reject
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Expanded Details Row -->
                            <tr class="row-details" id="details-<?php echo $transaction['id']; ?>">
                                <td colspan="7" class="px-0">
                                    <div class="details-content">
                                        <div class="details-grid">
                                            <!-- Transaction Details Section -->
                                            <div class="details-section">
                                                <h4><i class="fas fa-receipt"></i> Transaction Details</h4>
                                                <div class="detail-item">
                                                    <span class="detail-label">Transaction ID:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($transaction['transaction_id']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Amount:</span>
                                                    <span class="detail-value">₹<?php echo number_format($transaction['amount'], 2); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Payment Mode:</span>
                                                    <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $transaction['payment_mode'])); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Transaction Date:</span>
                                                    <span class="detail-value"><?php echo date('F d, Y', strtotime($transaction['transaction_date'])); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Status:</span>
                                                    <span class="detail-value status status-<?php echo $transaction['status']; ?>">
                                                        <?php echo ucfirst($transaction['status']); ?>
                                                    </span>
                                                </div>
                                                <?php if ($transaction['verified_by_name']): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Verified By:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($transaction['verified_by_name']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($transaction['status'] === 'rejected' && $transaction['rejection_reason']): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Rejection Reason:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($transaction['rejection_reason']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Student Details Section -->
                                            <div class="details-section">
                                                <h4><i class="fas fa-user-graduate"></i> Student Details</h4>
                                                <div class="detail-item">
                                                    <span class="detail-label">Name:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($student_name); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Student ID:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($transaction['student_id']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Email:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($transaction['student_email'] ?: 'Not available'); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Batch:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($transaction['batch_name']); ?></span>
                                                </div>
                                                <?php if ($enrollment_fees > 0): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Enrollment Fees:</span>
                                                    <span class="detail-value">₹<?php echo number_format($enrollment_fees, 2); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Total Paid:</span>
                                                    <span class="detail-value fees-paid">₹<?php echo number_format($total_fees_paid, 2); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Remaining:</span>
                                                    <span class="detail-value fees-pending">₹<?php echo number_format($enrollment_fees - $total_fees_paid, 2); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Fees Status:</span>
                                                    <span class="detail-value"><?php echo $fees_status; ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Screenshot Section -->
                                            <div class="details-section">
                                                <h4><i class="fas fa-image"></i> Payment Proof</h4>
                                                <?php if (!empty($transaction['screenshot_path']) && file_exists('../uploads/transactions/' . $transaction['screenshot_path'])): ?>
                                                    <div class="screenshot-container">
                                                        <?php if ($is_pdf): ?>
                                                            <iframe src="../uploads/transactions/<?php echo htmlspecialchars($transaction['screenshot_path']); ?>#toolbar=0" 
                                                                    width="100%" height="400"></iframe>
                                                        <?php else: ?>
                                                            <img src="../uploads/transactions/<?php echo htmlspecialchars($transaction['screenshot_path']); ?>" 
                                                                 alt="Payment Screenshot" 
                                                                 class="cursor-pointer" 
                                                                 onclick="window.open('../uploads/transactions/<?php echo htmlspecialchars($transaction['screenshot_path']); ?>', '_blank')">
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-center mt-2">
                                                        <a href="../uploads/transactions/<?php echo htmlspecialchars($transaction['screenshot_path']); ?>" 
                                                           target="_blank" 
                                                           class="text-blue-600 hover:text-blue-800 text-sm inline-flex items-center transition-all hover:scale-105">
                                                            <i class="fas fa-external-link-alt mr-1"></i>Open in new tab
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="no-screenshot">
                                                        <i class="fas fa-image text-gray-300 text-3xl mb-2"></i>
                                                        <p>No screenshot uploaded</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Receipt & Email Status Section -->
                                            <?php if ($transaction['status'] === 'verified'): ?>
                                            <div class="details-section">
                                                <h4><i class="fas fa-envelope"></i> Receipt & Email Status</h4>
                                                <div class="detail-item">
                                                    <span class="detail-label">Receipt Generated:</span>
                                                    <span class="detail-value <?php echo $receipt_generated ? 'text-green-600' : 'text-gray-600'; ?>">
                                                        <i class="fas fa-<?php echo $receipt_generated ? 'check' : 'times'; ?>-circle mr-1"></i>
                                                        <?php echo $receipt_status_text; ?>
                                                    </span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Email Sent:</span>
                                                    <span class="detail-value <?php echo $email_sent ? 'text-green-600' : ($has_email ? 'text-yellow-600' : 'text-gray-600'); ?>">
                                                        <i class="fas fa-<?php echo $email_sent ? 'check' : ($has_email ? 'envelope' : 'times'); ?>-circle mr-1"></i>
                                                        <?php echo $email_status_text; ?>
                                                        <?php if (!$has_email): ?>
                                                            <i class="fas fa-exclamation-triangle ml-1" title="Student email not found"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <?php if ($receipt_generated): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Receipt Path:</span>
                                                    <span class="detail-value">
                                                        <a href="../uploads/receipts/<?php echo htmlspecialchars($transaction['receipt_path']); ?>" 
                                                           target="_blank" 
                                                           class="text-blue-600 hover:text-blue-800 inline-flex items-center transition-all hover:scale-105">
                                                            <i class="fas fa-file-pdf mr-1"></i>View Receipt
                                                        </a>
                                                    </span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Actions Container -->
                                        <div class="actions-container">
                                            <?php if ($transaction['status'] === 'pending'): ?>
                                                <button onclick="approveTransaction(<?php echo $transaction['id']; ?>)" 
                                                        class="action-button approve">
                                                    <i class="fas fa-check mr-1"></i> Approve Transaction
                                                </button>
                                                <button onclick="showRejectModal(<?php echo $transaction['id']; ?>)" 
                                                        class="action-button reject">
                                                    <i class="fas fa-times mr-1"></i> Reject Transaction
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($transaction['status'] === 'verified'): ?>
                                                <button onclick="generateReceipt(<?php echo $transaction['id']; ?>)" 
                                                        class="action-button receipt">
                                                    <i class="fas fa-receipt mr-1"></i> Generate Receipt
                                                </button>
                                                
                                                <?php if ($has_email && !$email_sent): ?>
                                                    <button onclick="sendReceiptEmail(<?php echo $transaction['id']; ?>)" 
                                                            class="action-button email"
                                                            title="Send receipt email to <?php echo htmlspecialchars($transaction['student_email']); ?>">
                                                        <i class="fas fa-envelope mr-1"></i> Send Email
                                                    </button>
                                                <?php elseif ($has_email && $email_sent): ?>
                                                    <button class="action-button email" disabled
                                                            title="Receipt already sent to <?php echo htmlspecialchars($transaction['student_email']); ?>">
                                                        <i class="fas fa-check-circle mr-1"></i> Email Sent
                                                    </button>
                                                <?php else: ?>
                                                    <button class="action-button email" disabled
                                                            title="Student email not available">
                                                        <i class="fas fa-envelope mr-1"></i> No Email
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <button onclick="viewTransactionDetails(<?php echo $transaction['id']; ?>)" 
                                                    class="action-button view">
                                                <i class="fas fa-eye mr-1"></i> View Full Details
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if (empty($transactions)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-receipt text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No transactions found</h3>
                            <p class="text-gray-500">Try adjusting your filters or check back later.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectModal" class="modal-overlay">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    Reject Transaction
                </h3>
                <button onclick="hideModal()" class="text-gray-400 hover:text-gray-600 transition-colors relative overflow-hidden">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="rejectModalContent">
                <!-- Rejection form will be loaded here -->
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
    
    <script>
        // Show notification messages
        <?php if (!empty($success_message)): ?>
            showNotification('<?php echo addslashes($success_message); ?>', 'success');
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            showNotification('<?php echo addslashes($error_message); ?>', 'error');
        <?php endif; ?>
        
        // Initialize animations
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            const transactionCards = document.querySelectorAll('.transaction-card');
            transactionCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateX(0)';
                }, index * 50);
            });
            
            // Add ripple effect to all buttons
            document.querySelectorAll('button, .action-btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';
                    ripple.classList.add('ripple');
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });
        });
        
        // Toggle row details
        let expandedRow = null;
        
        function toggleRowDetails(rowId) {
            const detailsRow = document.getElementById(`details-${rowId}`);
            const expandIcon = document.querySelector(`#row-${rowId} .expand-toggle i`);
            
            // Close previously expanded row if different
            if (expandedRow && expandedRow !== rowId) {
                const prevDetails = document.getElementById(`details-${expandedRow}`);
                const prevIcon = document.querySelector(`#row-${expandedRow} .expand-toggle i`);
                if (prevDetails) {
                    prevDetails.classList.remove('active');
                    prevIcon.classList.remove('expanded');
                    // Reset animation
                    prevDetails.style.animation = 'none';
                    void prevDetails.offsetWidth; // Trigger reflow
                }
            }
            
            // Toggle current row
            if (detailsRow) {
                const isExpanding = !detailsRow.classList.contains('active');
                
                if (isExpanding) {
                    // Add expanded class with animation
                    detailsRow.classList.add('active');
                    expandIcon.classList.add('expanded');
                    expandedRow = rowId;
                    
                    // Scroll into view smoothly
                    setTimeout(() => {
                        detailsRow.scrollIntoView({ 
                            behavior: 'smooth', 
                            block: 'nearest',
                            inline: 'nearest'
                        });
                    }, 300);
                } else {
                    // Remove expanded class with reverse animation
                    detailsRow.style.animation = 'none';
                    void detailsRow.offsetWidth; // Trigger reflow
                    detailsRow.classList.remove('active');
                    expandIcon.classList.remove('expanded');
                    expandedRow = null;
                }
            }
        }
        
        // Modal functions
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Animate modal content
            setTimeout(() => {
                const modalContent = modal.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.style.animation = 'modalSlideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                }
            }, 10);
        }
        
        function hideModal() {
            document.querySelectorAll('.modal-overlay').forEach(modal => {
                modal.classList.remove('show');
            });
            document.body.style.overflow = 'auto';
        }
        
        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') hideModal();
        });
        
        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                hideModal();
            }
        });
        
        // View full transaction details (legacy function)
        function viewTransactionDetails(transactionId) {
            window.open('get_transactions_info.php?id=' + transactionId, '_blank');
        }
        
        // Approve transaction (direct approval with confirmation)
        function approveTransaction(transactionId) {
            window.location.href = 'transaction_approval.php?approve=' + transactionId;
        }
        
        // Show rejection modal with form
        function showRejectModal(transactionId) {
            fetch('transaction_approval.php?reject=' + transactionId + '&ajax=1')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('rejectModalContent').innerHTML = data.html;
                        showModal('rejectModal');
                        
                        // Add form submission handler
                        const rejectForm = document.getElementById('rejectForm');
                        if (rejectForm) {
                            rejectForm.addEventListener('submit', function(e) {
                                e.preventDefault();
                                const formData = new FormData(this);
                                
                                // Add loading state
                                const submitBtn = this.querySelector('button[type="submit"]');
                                const originalText = submitBtn.innerHTML;
                                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                                submitBtn.disabled = true;
                                
                                fetch(this.action, {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(response => response.text())
                                .then(() => {
                                    hideModal();
                                    // Show loading animation before reload
                                    document.body.classList.add('loading');
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 300);
                                })
                                .catch(error => {
                                    console.error('Error rejecting transaction:', error);
                                    showNotification('Failed to reject transaction.', 'error');
                                    submitBtn.innerHTML = originalText;
                                    submitBtn.disabled = false;
                                });
                            });
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading rejection form:', error);
                    // Fallback to old method
                    window.location.href = 'transaction_approval.php?reject=' + transactionId;
                });
        }
        
        // Generate receipt
        function generateReceipt(transactionId) {
            window.open('generate_receipt.php?id=' + transactionId, '_blank');
        }
        
        // Send receipt email
        function sendReceiptEmail(transactionId) {
            if (confirm('Send receipt email to student? This will generate a PDF receipt and email it to the student.')) {
                // Show loading state on the button if available
                const emailBtn = document.querySelector(`button[onclick*="${transactionId}"]`);
                if (emailBtn) {
                    const originalText = emailBtn.innerHTML;
                    emailBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Sending...';
                    emailBtn.disabled = true;
                }
                
                window.location.href = 'transaction_approval.php?send_receipt=' + transactionId;
            }
        }
        
        // Filter functions
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }
        
        // Show notification
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            const icon = type === 'success' ? 'check-circle' : 
                        type === 'error' ? 'exclamation-triangle' : 
                        type === 'info' ? 'info-circle' : 'bell';
            
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-${icon} mr-3"></i>
                    <div class="flex-1">${message}</div>
                    <span class="notification-close ml-4 cursor-pointer text-lg hover:opacity-75 transition-opacity" 
                          onclick="this.parentElement.parentElement.remove()">&times;</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 10);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(120%)';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }
        
        // Auto-refresh page every 30 seconds for pending transactions
        <?php if ($filter_status === 'pending'): ?>
        setTimeout(() => {
            // Add fade out animation before refresh
            document.body.style.opacity = '0.7';
            document.body.style.transition = 'opacity 0.3s ease';
            setTimeout(() => {
                window.location.reload();
            }, 300);
        }, 30000);
        <?php endif; ?>
        
        // Add smooth hover effects
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effect to table rows
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
                });
            });
            
            // Add parallax effect to stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('mousemove', function(e) {
                    const rect = this.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    
                    const centerX = rect.width / 2;
                    const centerY = rect.height / 2;
                    
                    const rotateY = (x - centerX) / 25;
                    const rotateX = (centerY - y) / 25;
                    
                    this.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-5px)`;
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) translateY(0)';
                });
            });
        });
    </script>
</body>
</html>