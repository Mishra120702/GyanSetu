<?php
// record_payment.php - Record manual payment for installment
include '../db_connection.php';
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$installment_id = $_GET['installment_id'] ?? 0;
$success_message = '';
$error_message = '';

// Get installment details
$stmt = $db->prepare("
    SELECT fi.*, s.*, b.batch_name as batch_full_name
    FROM fee_installments fi
    LEFT JOIN students s ON fi.student_id = s.student_id
    LEFT JOIN batches b ON s.batch_name = b.batch_id
    WHERE fi.id = ?
");
$stmt->execute([$installment_id]);
$installment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$installment) {
    $error_message = "Installment not found";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $installment) {
    try {
        $payment_amount = floatval($_POST['payment_amount']);
        $payment_date = $_POST['payment_date'];
        $payment_mode = $_POST['payment_mode'];
        $transaction_id = $_POST['transaction_id'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        if ($payment_amount <= 0) {
            throw new Exception('Payment amount must be greater than 0');
        }
        
        if ($payment_amount > ($installment['installment_amount'] - $installment['paid_amount'])) {
            throw new Exception('Payment amount cannot exceed remaining balance');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Update installment
        $new_paid_amount = $installment['paid_amount'] + $payment_amount;
        $payment_status = $new_paid_amount >= $installment['installment_amount'] ? 'paid' : 'partially_paid';
        
        $updateStmt = $db->prepare("
            UPDATE fee_installments 
            SET paid_amount = ?,
                payment_date = ?,
                payment_method = ?,
                payment_status = ?,
                transaction_id = ?,
                notes = CONCAT(IFNULL(notes, ''), '\n', ?),
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([
            $new_paid_amount,
            $payment_date,
            $payment_mode,
            $payment_status,
            $transaction_id,
            "Manual payment recorded: ₹" . number_format($payment_amount, 2) . " on " . $payment_date,
            $installment_id
        ]);
        
        // Update student's total fees paid
        $studentUpdateStmt = $db->prepare("
            UPDATE students 
            SET total_fees_paid = total_fees_paid + ?,
                last_payment_date = ?,
                fees_status = CASE 
                    WHEN (total_fees_paid + ?) >= enrollment_fees THEN 'fully_paid'
                    WHEN (total_fees_paid + ?) > 0 THEN 'partially_paid'
                    ELSE 'unpaid'
                END
            WHERE student_id = ?
        ");
        $studentUpdateStmt->execute([
            $payment_amount,
            $payment_date,
            $payment_amount,
            $payment_amount,
            $installment['student_id']
        ]);
        
        // Create transaction record
        $transactionStmt = $db->prepare("
            INSERT INTO transactions 
            (transaction_id, student_id, student_name, batch_id, transaction_date, 
             amount, payment_mode, payment_recipient, screenshot_path, status,
             verified_by, verified_at, remarks, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'BugDetox Technologies LLP', 
                   'manual_payment.jpg', 'verified', ?, NOW(), ?, NOW())
        ");
        
        $student_name = $installment['first_name'] . ' ' . $installment['last_name'];
        $transactionStmt->execute([
            $transaction_id ?: 'MANUAL-' . time(),
            $installment['student_id'],
            $student_name,
            $installment['batch_name'],
            $payment_date,
            $payment_amount,
            $payment_mode,
            $_SESSION['user_id'],
            "Manual payment for installment #" . $installment['installment_number'] . ". " . $notes
        ]);
        
        $db->commit();
        
        $success_message = "Payment recorded successfully!";
        
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Failed to record payment: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payment - ASD Academy</title>
    <?php include '../header.php'; ?>
</head>
<body class="bg-gray-50">
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen p-6">
        <div class="max-w-2xl mx-auto">
            <div class="glass-card p-6">
                <h1 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-money-bill-wave text-green-500 mr-3"></i>
                    Record Payment
                </h1>
                
                <?php if ($error_message): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($installment): ?>
                    <!-- Installment Details -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h3 class="font-semibold text-blue-800 mb-2">Installment Details</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-600">Student</p>
                                <p class="font-medium"><?php echo htmlspecialchars($installment['first_name'] . ' ' . $installment['last_name']); ?></p>
                                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($installment['student_id']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Batch</p>
                                <p class="font-medium"><?php echo htmlspecialchars($installment['batch_full_name']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Installment</p>
                                <p class="font-medium">#<?php echo $installment['installment_number']; ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Due Date</p>
                                <p class="font-medium"><?php echo date('M d, Y', strtotime($installment['due_date'])); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Amount</p>
                                <p class="font-medium">₹<?php echo number_format($installment['installment_amount'], 2); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Paid</p>
                                <p class="font-medium">₹<?php echo number_format($installment['paid_amount'], 2); ?></p>
                            </div>
                            <div class="col-span-2">
                                <p class="text-sm text-gray-600">Balance</p>
                                <p class="text-lg font-bold <?php echo ($installment['installment_amount'] - $installment['paid_amount']) > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                    ₹<?php echo number_format($installment['installment_amount'] - $installment['paid_amount'], 2); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Form -->
                    <form method="POST" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Amount *</label>
                                <input type="number" name="payment_amount" 
                                       step="0.01" min="0.01" max="<?php echo $installment['installment_amount'] - $installment['paid_amount']; ?>"
                                       value="<?php echo $installment['installment_amount'] - $installment['paid_amount']; ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       required>
                                <p class="text-xs text-gray-500 mt-1">
                                    Max: ₹<?php echo number_format($installment['installment_amount'] - $installment['paid_amount'], 2); ?>
                                </p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                                <input type="date" name="payment_date" 
                                       value="<?php echo date('Y-m-d'); ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Mode *</label>
                                <select name="payment_mode" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Transaction ID (Optional)</label>
                                <input type="text" name="transaction_id" 
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="e.g., TXN-123456">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
                            <textarea name="notes" rows="3" 
                                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="Any additional notes about this payment..."></textarea>
                        </div>
                        
                        <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                            <button type="button" onclick="window.history.back()" 
                                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium">
                                <i class="fas fa-save mr-2"></i>Record Payment
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Installment Not Found</h3>
                        <p class="text-gray-500 mb-4">The requested installment could not be found.</p>
                        <a href="payments_dashboard.php" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>