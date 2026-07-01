<?php
// get_transactions_info.php
include '../db_connection.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid transaction ID');
}

$transaction_id = (int)$_GET['id'];

$stmt = $db->prepare("
    SELECT t.*, 
           b.batch_name,
           u.name as verified_by_name,
           s.email as student_email,
           s.phone_number as student_phone
    FROM transactions t
    LEFT JOIN batches b ON t.batch_id = b.batch_id
    LEFT JOIN users u ON t.verified_by = u.id
    LEFT JOIN students s ON t.student_id = s.student_id
    WHERE t.id = ?
");

$stmt->execute([$transaction_id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    die('Transaction not found');
}

// Determine file path based on file extension
$file_ext = strtolower(pathinfo($transaction['screenshot_path'], PATHINFO_EXTENSION));
$is_pdf = $file_ext === 'pdf';
$file_url = '../uploads/transactions/' . $transaction['screenshot_path'];
$thumb_url = '../uploads/transactions/thumbs/' . $transaction['screenshot_path'];

// Check if file exists
$file_exists = file_exists($file_url);
?>

<div class="space-y-6">
    <!-- Header with Transaction ID -->
    <div class="flex justify-between items-center">
        <div>
            <h3 class="text-xl font-bold text-gray-800">Transaction #<?php echo htmlspecialchars($transaction['transaction_id']); ?></h3>
            
        </div>
        <div class="text-right">
            <span class="text-3xl font-bold text-green-600">₹<?php echo number_format($transaction['amount'], 2); ?></span>
            <div class="text-sm text-gray-500 mt-1">
                <i class="fas fa-credit-card mr-1"></i>
                <?php echo ucfirst(str_replace('_', ' ', $transaction['payment_mode'])); ?>
            </div>
        </div>
    </div>
    
    <!-- Status Badge -->
    <div class="flex items-center space-x-4">
        <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-bold
            <?php echo $transaction['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                   ($transaction['status'] === 'verified' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'); ?>">
            <i class="fas fa-<?php 
                echo $transaction['status'] === 'pending' ? 'clock' : 
                     ($transaction['status'] === 'verified' ? 'check-circle' : 'times-circle'); 
            ?> mr-2"></i>
            <?php echo ucfirst($transaction['status']); ?>
        </span>
        
        <?php if ($transaction['verified_by_name']): ?>
            <div class="text-sm text-gray-600">
                <i class="fas fa-user-check mr-1"></i>
                Verified by: <?php echo htmlspecialchars($transaction['verified_by_name']); ?> on 
                <?php echo date('M d, Y H:i', strtotime($transaction['verified_at'])); ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Student and Batch Info -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-gray-50 p-4 rounded-lg">
            <h4 class="font-medium text-gray-700 mb-3 flex items-center">
                <i class="fas fa-user-graduate mr-2 text-blue-500"></i>Student Information
            </h4>
            <div class="space-y-2">
                <div>
                    <div class="text-sm text-gray-500">Student Name</div>
                    <div class="font-medium"><?php echo htmlspecialchars($transaction['student_name']); ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Student ID</div>
                    <div class="font-medium"><?php echo htmlspecialchars($transaction['student_id']); ?></div>
                </div>
                <?php if ($transaction['student_email']): ?>
                <div>
                    <div class="text-sm text-gray-500">Email</div>
                    <div class="font-medium"><?php echo htmlspecialchars($transaction['student_email']); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($transaction['student_phone']): ?>
                <div>
                    <div class="text-sm text-gray-500">Phone</div>
                    <div class="font-medium"><?php echo htmlspecialchars($transaction['student_phone']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="bg-gray-50 p-4 rounded-lg">
            <h4 class="font-medium text-gray-700 mb-3 flex items-center">
                <i class="fas fa-layer-group mr-2 text-green-500"></i>Batch Information
            </h4>
            <div class="space-y-2">
                <div>
                    <div class="text-sm text-gray-500">Batch Name</div>
                    <div class="font-medium"><?php echo htmlspecialchars($transaction['batch_name']); ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Batch ID</div>
                    <div class="font-medium"><?php echo htmlspecialchars($transaction['batch_id']); ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Transaction Date</div>
                    <div class="font-medium"><?php echo date('F d, Y', strtotime($transaction['transaction_date'])); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Screenshot/Proof Section -->
    <div class="bg-white border border-gray-200 rounded-lg p-4">
        <h4 class="font-medium text-gray-700 mb-3 flex items-center">
            <i class="fas fa-camera mr-2 text-purple-500"></i>Payment Proof
        </h4>
        
        <?php if (!$file_exists): ?>
            <div class="text-center p-8 bg-yellow-50 rounded-lg">
                <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-3"></i>
                <p class="text-gray-700 font-medium">File not found</p>
                <p class="text-gray-500 text-sm mt-1">The uploaded file is missing from the server.</p>
                <p class="text-gray-500 text-sm">Expected path: <?php echo htmlspecialchars($file_url); ?></p>
            </div>
        <?php elseif ($is_pdf): ?>
            <div class="text-center p-6 border-2 border-dashed border-gray-300 rounded-lg">
                <i class="fas fa-file-pdf text-red-500 text-5xl mb-4"></i>
                <p class="text-gray-700 font-medium">PDF Document</p>
                <p class="text-gray-500 text-sm mb-4"><?php echo htmlspecialchars($transaction['screenshot_path']); ?></p>
                <div class="flex justify-center space-x-3">
                    <a href="<?php echo $file_url; ?>" target="_blank" 
                       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors">
                        <i class="fas fa-external-link-alt mr-2"></i> View PDF
                    </a>
                    <a href="<?php echo $file_url; ?>" download 
                       class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors">
                        <i class="fas fa-download mr-2"></i> Download
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <a href="<?php echo $file_url; ?>" target="_blank" class="block">
                    <div class="screenshot-container">
                        <?php if (file_exists($thumb_url)): ?>
                            <img src="<?php echo $thumb_url; ?>" 
                                 alt="Transaction Screenshot" 
                                 class="max-w-full max-h-96 object-contain rounded-lg shadow-sm">
                        <?php else: ?>
                            <img src="<?php echo $file_url; ?>" 
                                 alt="Transaction Screenshot" 
                                 class="max-w-full max-h-96 object-contain rounded-lg shadow-sm"
                                 onerror="this.src='https://cdn-icons-png.flaticon.com/512/1178/1178479.png'">
                        <?php endif; ?>
                    </div>
                </a>
                <div class="flex justify-between items-center">
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-file-image mr-1"></i>
                        <?php echo htmlspecialchars($transaction['screenshot_path']); ?>
                    </div>
                    <div class="flex space-x-2">
                        <a href="<?php echo $file_url; ?>" target="_blank" 
                           class="text-blue-600 hover:text-blue-800 text-sm font-medium inline-flex items-center">
                            <i class="fas fa-expand mr-1"></i> Full View
                        </a>
                        <a href="<?php echo $file_url; ?>" download 
                           class="text-green-600 hover:text-green-800 text-sm font-medium inline-flex items-center">
                            <i class="fas fa-download mr-1"></i> Download
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Notes and Remarks -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php if ($transaction['remarks']): ?>
        <div class="bg-blue-50 p-4 rounded-lg">
            <h4 class="font-medium text-gray-700 mb-2 flex items-center">
                <i class="fas fa-sticky-note mr-2 text-blue-500"></i>Remarks from Student
            </h4>
            <p class="text-gray-700 bg-white p-3 rounded border border-blue-100"><?php echo nl2br(htmlspecialchars($transaction['remarks'])); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($transaction['verification_notes']): ?>
        <div class="bg-green-50 p-4 rounded-lg">
            <h4 class="font-medium text-gray-700 mb-2 flex items-center">
                <i class="fas fa-check-circle mr-2 text-green-500"></i>Verification Notes
            </h4>
            <p class="text-gray-700 bg-white p-3 rounded border border-green-100"><?php echo nl2br(htmlspecialchars($transaction['verification_notes'])); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($transaction['rejection_reason']): ?>
        <div class="bg-red-50 p-4 rounded-lg">
            <h4 class="font-medium text-gray-700 mb-2 flex items-center">
                <i class="fas fa-times-circle mr-2 text-red-500"></i>Rejection Reason
            </h4>
            <p class="text-gray-700 bg-white p-3 rounded border border-red-100"><?php echo nl2br(htmlspecialchars($transaction['rejection_reason'])); ?></p>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Action Buttons (for pending transactions) -->
    <?php if ($transaction['status'] === 'pending'): ?>
    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
        <h4 class="font-medium text-gray-700 mb-3">Actions</h4>
        <div class="flex space-x-3">
            <button onclick="approveTransaction(<?php echo $transaction['id']; ?>)" 
                    class="flex-1 bg-gradient-to-r from-green-500 to-green-600 text-white px-4 py-3 rounded-lg hover:from-green-600 hover:to-green-700 transition-all shadow-sm flex items-center justify-center">
                <i class="fas fa-check mr-2"></i> Approve Transaction
            </button>
            <button onclick="rejectTransaction(<?php echo $transaction['id']; ?>)" 
                    class="flex-1 bg-gradient-to-r from-red-500 to-red-600 text-white px-4 py-3 rounded-lg hover:from-red-600 hover:to-red-700 transition-all shadow-sm flex items-center justify-center">
                <i class="fas fa-times mr-2"></i> Reject Transaction
            </button>
            <button onclick="hideModal()" 
                    class="px-4 py-3 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-all flex items-center">
                <i class="fas fa-times mr-2"></i> Close
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Additional Information -->
    <div class="text-xs text-gray-500 border-t border-gray-200 pt-4">
        <div class="flex justify-between">
            <div>
                <i class="fas fa-database mr-1"></i> Transaction ID: <?php echo $transaction['id']; ?>
            </div>
            <div>
                Last Updated: <?php echo date('M d, Y H:i:s', strtotime($transaction['uploaded_at'])); ?>
            </div>
        </div>
    </div>
</div>

<script>
// Function to approve transaction (called from action buttons)
function approveTransaction(transactionId) {
    // Hide the view modal first
    hideModal();
    
    // Show the approve modal
    setTimeout(() => {
        window.parent.approveTransaction(transactionId);
    }, 300);
}

// Function to reject transaction (called from action buttons)
function rejectTransaction(transactionId) {
    // Hide the view modal first
    hideModal();
    
    // Show the reject modal
    setTimeout(() => {
        window.parent.rejectTransaction(transactionId);
    }, 300);
}

// Function to hide modal (for close button)
function hideModal() {
    if (window.parent && window.parent.hideModal) {
        window.parent.hideModal();
    }
}
</script>

<style>
.screenshot-container {
    max-width: 100%;
    max-height: 500px;
    overflow: hidden;
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8fafc;
    margin: 0 auto;
}

.screenshot-container img {
    max-width: 100%;
    max-height: 450px;
    object-fit: contain;
    transition: transform 0.3s ease;
}

.screenshot-container img:hover {
    transform: scale(1.02);
}

.progress-bar {
    background: #e5e7eb;
    border-radius: 9999px;
    height: 4px;
    overflow: hidden;
}

.progress-bar div {
    height: 100%;
    transition: width 0.3s ease;
}

/* Smooth animations */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.space-y-6 > * {
    animation: fadeIn 0.3s ease forwards;
}

.space-y-6 > *:nth-child(1) { animation-delay: 0.1s; }
.space-y-6 > *:nth-child(2) { animation-delay: 0.2s; }
.space-y-6 > *:nth-child(3) { animation-delay: 0.3s; }
.space-y-6 > *:nth-child(4) { animation-delay: 0.4s; }
.space-y-6 > *:nth-child(5) { animation-delay: 0.5s; }
.space-y-6 > *:nth-child(6) { animation-delay: 0.6s; }
.space-y-6 > *:nth-child(7) { animation-delay: 0.7s; }
</style>