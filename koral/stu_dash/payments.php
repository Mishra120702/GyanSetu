<?php
// payments.php - Student payment and fee management
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit;
}

$student_id = $_SESSION['user_id'];

// Get student information
$student_query = $db->prepare("
    SELECT s.*, b.batch_name, b.start_date, b.end_date
    FROM students s
    LEFT JOIN batches b ON s.batch_name = b.batch_id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get all fee installments for the student
$installments_query = $db->prepare("
    SELECT 
        fi.*,
        u.name as received_by_name,
        DATEDIFF(CURDATE(), fi.due_date) as days_overdue,
        CASE 
            WHEN fi.payment_status = 'pending' AND fi.due_date < CURDATE() THEN 'overdue'
            WHEN fi.payment_status = 'pending' AND fi.due_date >= CURDATE() THEN 'upcoming'
            ELSE fi.payment_status
        END as payment_status_detailed
    FROM fee_installments fi
    LEFT JOIN users u ON fi.created_by = u.id
    WHERE fi.student_id = :student_id
    ORDER BY fi.due_date ASC
");
$installments_query->execute([':student_id' => $student['student_id']]);
$installments = $installments_query->fetchAll(PDO::FETCH_ASSOC);

// Get overdue installments (due date passed and still pending)
$overdue_installments = array_filter($installments, function($inst) {
    return $inst['payment_status_detailed'] === 'overdue';
});

// Get upcoming installments (due date in future and pending)
$upcoming_installments = array_filter($installments, function($inst) {
    return $inst['payment_status_detailed'] === 'upcoming';
});

// Calculate total overdue amount
$total_overdue_amount = array_sum(array_map(function($inst) {
    return $inst['installment_amount'] - $inst['paid_amount'];
}, $overdue_installments));

// Get all verified transactions (receipts)
$transactions_query = $db->prepare("
    SELECT 
        t.*,
        b.batch_name,
        u.name as verified_by_name,
        DATE_FORMAT(t.transaction_date, '%Y-%m-%d') as receipt_date,
        DATE_FORMAT(t.verified_at, '%Y-%m-%d %H:%i') as verification_time
    FROM transactions t
    LEFT JOIN batches b ON t.batch_id = b.batch_id
    LEFT JOIN users u ON t.verified_by = u.id
    WHERE t.student_id = :student_id 
    AND t.status = 'verified'
    ORDER BY t.transaction_date DESC, t.verified_at DESC
");
$transactions_query->execute([':student_id' => $student['student_id']]);
$transactions = $transactions_query->fetchAll(PDO::FETCH_ASSOC);

// Calculate fee summary
$total_fees = $student['enrollment_fees'] ?? 0;
$total_paid = $student['total_fees_paid'] ?? 0;
$outstanding_fees = $total_fees - $total_paid;

// Get account lock information
$account_lock_query = $db->prepare("
    SELECT 
        ul.*,
        u.name as locked_by_name,
        DATEDIFF(ul.expiry_date, CURDATE()) as days_remaining
    FROM user_lock_logs ul
    LEFT JOIN users u ON ul.performed_by = u.id
    WHERE ul.user_id = :user_id 
    AND ul.action = 'locked'
    AND (ul.expiry_date IS NULL OR ul.expiry_date > CURDATE())
    ORDER BY ul.performed_at DESC
    LIMIT 1
");
$account_lock_query->execute([':user_id' => $_SESSION['user_id']]);
$account_lock = $account_lock_query->fetch(PDO::FETCH_ASSOC);

// Check if account is at risk of being locked
$days_before_lock = 7; // Account will be locked if overdue for 7 days
$account_at_risk = false;
$risk_days = 0;
$lock_date = null;

if (count($overdue_installments) > 0) {
    // Find the oldest overdue installment
    $oldest_overdue = min(array_map(function($inst) {
        return strtotime($inst['due_date']);
    }, $overdue_installments));
    
    $days_overdue = floor((time() - $oldest_overdue) / (60 * 60 * 24));
    $risk_days = max(0, $days_before_lock - $days_overdue);
    
    if ($days_overdue >= $days_before_lock) {
        $account_at_risk = 'immediate';
    } elseif ($days_overdue >= ($days_before_lock - 3)) {
        $account_at_risk = 'high';
    } elseif ($days_overdue >= ($days_before_lock - 7)) {
        $account_at_risk = 'medium';
    }
    
    $lock_date = date('Y-m-d', strtotime("+$risk_days days"));
}

// Get payment history stats
$payment_stats_query = $db->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        SUM(amount) as total_verified_amount,
        MIN(transaction_date) as first_payment_date,
        MAX(transaction_date) as last_payment_date
    FROM transactions
    WHERE student_id = :student_id 
    AND status = 'verified'
");
$payment_stats_query->execute([':student_id' => $student['student_id']]);
$payment_stats = $payment_stats_query->fetch(PDO::FETCH_ASSOC);

// Get recent transactions (last 5)
$recent_transactions = array_slice($transactions, 0, 5);
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <!-- Mobile Header -->
    <header class="bg-gradient-to-r from-green-50 to-emerald-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden">
        <button class="text-xl text-emerald-600 hover:text-emerald-800 transition-colors" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
        
        <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-emerald-100 p-2 rounded-lg">
                <i class="fas fa-credit-card text-emerald-600 text-sm"></i>
            </div>
            <span>My Payments</span>
        </h1>
        
        <div class="flex items-center space-x-3">
            <?php if (count($overdue_installments) > 0): ?>
                <div class="relative">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center animate-pulse">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <span class="absolute -top-1 -right-1 bg-red-600 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                        <?= count($overdue_installments) ?>
                    </span>
                </div>
            <?php else: ?>
                <div class="relative">
                    <div class="w-8 h-8 bg-emerald-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-graduate text-emerald-600"></i>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Desktop Header -->
    <header class="hidden md:flex bg-gradient-to-r from-green-50 to-emerald-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
        <div class="flex-1"></div>
        
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-emerald-100 p-2 rounded-lg">
                <i class="fas fa-credit-card text-emerald-600 text-xl"></i>
            </div>
            <span>Payment & Fee Management</span>
            <?php if (count($overdue_installments) > 0): ?>
                <span class="ml-2 px-3 py-1 bg-red-100 text-red-800 text-sm rounded-full animate-pulse">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <?= count($overdue_installments) ?> Overdue
                </span>
            <?php endif; ?>
        </h1>
        
        <div class="flex-1 flex justify-end items-center space-x-4">
            <?php if (count($overdue_installments) > 0): ?>
                <div class="animate-pulse bg-red-100 rounded-full p-2">
                    <i class="fas fa-exclamation-triangle text-red-600"></i>
                </div>
            <?php else: ?>
                <div class="animate-pulse bg-emerald-100 rounded-full p-2">
                    <i class="fas fa-user-graduate text-emerald-600"></i>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Mobile Navigation Menu (Same as my_batches.php) -->
    <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden">
        <!-- ... Mobile menu content same as my_batches.php ... -->
    </div>

    <div class="p-4 md:p-6 bg-gray-50 min-h-screen">
        <!-- Account Status Alert -->
        <?php if ($account_lock): ?>
            <div class="mb-6 bg-gradient-to-r from-red-500 to-red-600 text-white p-4 rounded-2xl shadow-lg animate-pulse">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="bg-white p-3 rounded-full mr-4">
                            <i class="fas fa-lock text-red-600 text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold">ACCOUNT LOCKED</h3>
                            <p class="text-red-100">
                                Your account has been locked due to non-payment.
                                <?php if ($account_lock['expiry_date']): ?>
                                    Lock expires on: <?= date('d M, Y', strtotime($account_lock['expiry_date'])) ?>
                                    (<?= $account_lock['days_remaining'] ?> days remaining)
                                <?php else: ?>
                                    Lock is permanent until resolved.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <a href="contact_accounts.php" 
                       class="px-4 py-2 bg-white text-red-600 font-bold rounded-lg hover:bg-red-50 transition-colors duration-300">
                        <i class="fas fa-headset mr-2"></i>Contact Support
                    </a>
                </div>
                <div class="mt-3 text-sm bg-red-400/30 p-3 rounded-lg">
                    <strong>Reason:</strong> <?= htmlspecialchars($account_lock['reason'] ?? 'Payment overdue') ?>
                    <br>
                    <strong>Locked by:</strong> <?= htmlspecialchars($account_lock['locked_by_name'] ?? 'Administrator') ?>
                    on <?= date('d M, Y H:i', strtotime($account_lock['performed_at'])) ?>
                </div>
            </div>
        <?php elseif ($account_at_risk): ?>
            <?php
            $alert_class = '';
            $alert_icon = '';
            $alert_title = '';
            
            if ($account_at_risk === 'immediate') {
                $alert_class = 'from-red-500 to-red-600';
                $alert_icon = 'fa-skull-crossbones';
                $alert_title = 'IMMEDIATE ACTION REQUIRED';
            } elseif ($account_at_risk === 'high') {
                $alert_class = 'from-orange-500 to-red-500';
                $alert_icon = 'fa-exclamation-triangle';
                $alert_title = 'HIGH RISK OF ACCOUNT LOCK';
            } elseif ($account_at_risk === 'medium') {
                $alert_class = 'from-yellow-500 to-orange-500';
                $alert_icon = 'fa-exclamation-circle';
                $alert_title = 'ACCOUNT AT RISK';
            }
            ?>
            <div class="mb-6 bg-gradient-to-r <?= $alert_class ?> text-white p-4 rounded-2xl shadow-lg animate-pulse">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="bg-white p-3 rounded-full mr-4">
                            <i class="fas <?= $alert_icon ?> text-red-600 text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold"><?= $alert_title ?></h3>
                            <p class="text-white/90">
                                <?php if ($account_at_risk === 'immediate'): ?>
                                    Your account will be locked immediately. Please contact accounts department.
                                <?php elseif ($account_at_risk === 'high'): ?>
                                    Your account will be locked in <?= $risk_days ?> day<?= $risk_days !== 1 ? 's' : '' ?> if payments are not cleared.
                                <?php else: ?>
                                    Your account is at risk of being locked. Please clear overdue payments.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <a href="contact_accounts.php" 
                       class="px-4 py-2 bg-white <?= $account_at_risk === 'immediate' ? 'text-red-600' : 'text-orange-600' ?> font-bold rounded-lg hover:opacity-90 transition-all duration-300">
                        <i class="fas fa-headset mr-2"></i>Contact Support
                    </a>
                </div>
                <div class="mt-3 text-sm bg-white/20 p-3 rounded-lg">
                    <strong>Estimated Lock Date:</strong> <?= date('d M, Y', strtotime($lock_date)) ?>
                    <br>
                    <strong>Overdue Amount:</strong> ₹<?= number_format($total_overdue_amount, 2) ?>
                    <br>
                    <strong>Overdue Since:</strong> <?= date('d M, Y', $oldest_overdue) ?>
                    (<?= floor((time() - $oldest_overdue) / (60 * 60 * 24)) ?> days)
                </div>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <!-- Total Fees Card -->
            <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-3 rounded-lg mr-3">
                            <i class="fas fa-money-bill-wave text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-800">Total Course Fee</h3>
                            <p class="text-sm text-gray-500">Complete program</p>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="text-2xl font-bold text-blue-600">
                        ₹<?= number_format($total_fees, 2) ?>
                    </div>
                </div>
                <div class="text-xs text-gray-500">
                    <span><?= $student['fees_payment_mode'] ?? 'Not specified' ?> plan</span>
                </div>
            </div>

            <!-- Paid Amount Card -->
            <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <div class="bg-green-100 p-3 rounded-lg mr-3">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-800">Paid Amount</h3>
                            <p class="text-sm text-gray-500">Total received</p>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="text-2xl font-bold text-green-600">
                        ₹<?= number_format($total_paid, 2) ?>
                    </div>
                </div>
                <div class="text-xs text-gray-500">
                    <span>Last paid: <?= $student['last_payment_date'] ? date('d M, Y', strtotime($student['last_payment_date'])) : 'Never' ?></span>
                </div>
            </div>

            <!-- Outstanding Fees Card -->
            <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <div class="bg-orange-100 p-3 rounded-lg mr-3">
                            <i class="fas fa-exclamation-triangle text-orange-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-800">Outstanding</h3>
                            <p class="text-sm text-gray-500">Balance to pay</p>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="text-2xl font-bold <?= $outstanding_fees > 0 ? 'text-orange-600' : 'text-green-600' ?>">
                        ₹<?= number_format($outstanding_fees, 2) ?>
                    </div>
                </div>
                <div class="text-xs text-gray-500">
                    <?php if ($student['next_payment_due_date']): ?>
                        <span>Next due: <?= date('d M, Y', strtotime($student['next_payment_due_date'])) ?></span>
                    <?php else: ?>
                        <span>No upcoming dues</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Status Card -->
            <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <div class="bg-purple-100 p-3 rounded-lg mr-3">
                            <i class="fas fa-chart-pie text-purple-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-800">Payment Status</h3>
                            <p class="text-sm text-gray-500">Fee compliance</p>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <?php
                    $payment_percentage = $total_fees > 0 ? round(($total_paid / $total_fees) * 100, 2) : 0;
                    ?>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">Progress</span>
                        <span class="font-bold <?= $payment_percentage >= 100 ? 'text-green-600' : 'text-purple-600' ?>">
                            <?= $payment_percentage ?>%
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="h-2 rounded-full transition-all duration-1000 ease-out 
                            <?= $payment_percentage >= 100 ? 'bg-green-600' : 
                               ($payment_percentage >= 75 ? 'bg-blue-600' : 
                               ($payment_percentage >= 50 ? 'bg-yellow-600' : 'bg-red-600')) ?>" 
                             style="width: <?= $payment_percentage ?>%"></div>
                    </div>
                </div>
                <div class="text-xs text-gray-500">
                    <span class="<?= $student['fees_status'] === 'fully_paid' ? 'text-green-600 font-bold' : 
                                 ($student['fees_status'] === 'partially_paid' ? 'text-yellow-600' : 
                                 ($student['fees_status'] === 'overdue' ? 'text-red-600 font-bold' : 'text-gray-600')) ?>">
                        <?= str_replace('_', ' ', ucfirst($student['fees_status'] ?? 'Unknown')) ?>
                    </span>
                    <?php if (count($overdue_installments) > 0): ?>
                        <div class="mt-1 text-red-600">
                            <i class="fas fa-exclamation-circle"></i> <?= count($overdue_installments) ?> overdue
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Installment Schedule -->
            <div class="bg-white p-6 rounded-2xl shadow-lg">
                <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                    <div class="bg-blue-100 p-2 rounded-lg mr-3">
                        <i class="fas fa-calendar-alt text-blue-600"></i>
                    </div>
                    Installment Schedule
                </h2>
                
                <?php if (count($installments) > 0): ?>
                    <div class="overflow-x-auto rounded-2xl shadow-inner">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gradient-to-r from-blue-50 to-indigo-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">#</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">Due Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">Paid</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">Days</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($installments as $installment): ?>
                                    <?php
                                    $due_date = new DateTime($installment['due_date']);
                                    $today = new DateTime();
                                    $is_overdue = $installment['payment_status_detailed'] === 'overdue';
                                    $is_upcoming = $installment['payment_status_detailed'] === 'upcoming';
                                    $is_paid = $installment['payment_status'] === 'paid';
                                    
                                    if ($is_overdue) {
                                        $days_overdue = $installment['days_overdue'];
                                        if ($days_overdue >= 14) {
                                            $status_class = 'bg-red-500 text-white';
                                        } elseif ($days_overdue >= 7) {
                                            $status_class = 'bg-red-100 text-red-800';
                                        } else {
                                            $status_class = 'bg-orange-100 text-orange-800';
                                        }
                                    } elseif ($is_upcoming) {
                                        $days_until = $today->diff($due_date)->days;
                                        if ($days_until <= 3) {
                                            $status_class = 'bg-yellow-100 text-yellow-800';
                                        } else {
                                            $status_class = 'bg-blue-100 text-blue-800';
                                        }
                                    } else {
                                        $status_class = 'bg-green-100 text-green-800';
                                    }
                                    ?>
                                    <tr class="transition-all duration-300 hover:bg-blue-50 
                                        <?= $is_overdue ? 'bg-red-50' : ($is_upcoming ? 'bg-yellow-50' : '') ?>">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                                    <i class="fas fa-hashtag text-blue-600"></i>
                                                </div>
                                                <span class="text-sm font-medium text-gray-900">
                                                    Installment <?= $installment['installment_number'] ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 <?= $is_overdue ? 'text-red-600 font-bold' : '' ?>">
                                                <?= date('d M, Y', strtotime($installment['due_date'])) ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?= $is_overdue ? 'Overdue' : ($is_upcoming ? 'Upcoming' : 'Completed') ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            ₹<?= number_format($installment['installment_amount'], 2) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            ₹<?= number_format($installment['paid_amount'], 2) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-3 py-1 text-xs rounded-full <?= $status_class ?>">
                                                <?php if ($is_overdue): ?>
                                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                                <?php elseif ($is_upcoming): ?>
                                                    <i class="fas fa-clock mr-1"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-check mr-1"></i>
                                                <?php endif; ?>
                                                <?= ucfirst(str_replace('_', ' ', $installment['payment_status'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php if ($is_overdue): ?>
                                                <span class="text-red-600 font-bold">
                                                    <?= abs($installment['days_overdue']) ?> days
                                                </span>
                                            <?php elseif ($is_upcoming): ?>
                                                <?php
                                                $days_until = $today->diff($due_date)->days;
                                                $status_class = $days_until <= 3 ? 'text-yellow-600' : 'text-blue-600';
                                                ?>
                                                <span class="<?= $status_class ?>">
                                                    in <?= $days_until ?> days
                                                </span>
                                            <?php else: ?>
                                                <span class="text-green-600">
                                                    Paid
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Installment Summary -->
                    <div class="mt-6 grid grid-cols-4 gap-4">
                        <div class="bg-blue-50 p-3 rounded-lg border border-blue-100">
                            <div class="text-xs text-blue-600 font-medium mb-1">Total</div>
                            <div class="text-lg font-bold text-gray-800"><?= count($installments) ?></div>
                        </div>
                        <div class="bg-green-50 p-3 rounded-lg border border-green-100">
                            <div class="text-xs text-green-600 font-medium mb-1">Paid</div>
                            <div class="text-lg font-bold text-gray-800">
                                <?= count(array_filter($installments, fn($i) => $i['payment_status'] === 'paid')) ?>
                            </div>
                        </div>
                        <div class="bg-red-50 p-3 rounded-lg border border-red-100">
                            <div class="text-xs text-red-600 font-medium mb-1">Overdue</div>
                            <div class="text-lg font-bold text-gray-800">
                                <?= count($overdue_installments) ?>
                            </div>
                        </div>
                        <div class="bg-yellow-50 p-3 rounded-lg border border-yellow-100">
                            <div class="text-xs text-yellow-600 font-medium mb-1">Upcoming</div>
                            <div class="text-lg font-bold text-gray-800">
                                <?= count($upcoming_installments) ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <div class="bg-blue-50 inline-block p-4 rounded-full mb-4">
                            <i class="fas fa-calendar-times text-blue-500 text-3xl"></i>
                        </div>
                        <p class="text-gray-500 text-lg">No installment schedule found</p>
                        <p class="text-gray-400 mt-2">Please contact accounts department</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Overdue Payments Details -->
            <div class="bg-white p-6 rounded-2xl shadow-lg">
                <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                    <div class="bg-red-100 p-2 rounded-lg mr-3">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    Overdue Payments
                    <?php if (count($overdue_installments) > 0): ?>
                        <span class="ml-2 px-3 py-1 bg-red-100 text-red-800 text-sm rounded-full">
                            <?= count($overdue_installments) ?> overdue
                        </span>
                    <?php endif; ?>
                </h2>
                
                <?php if (count($overdue_installments) > 0): ?>
                    <div class="space-y-4">
                        <?php foreach ($overdue_installments as $installment): ?>
                            <?php
                            $due_date = new DateTime($installment['due_date']);
                            $today = new DateTime();
                            $days_overdue = $installment['days_overdue'];
                            
                            if ($days_overdue >= 14) {
                                $alert_level = 'critical';
                                $bg_class = 'from-red-600 to-red-700';
                                $text_class = 'text-white';
                                $icon = 'fa-skull-crossbones';
                                $message = 'Account lock imminent';
                            } elseif ($days_overdue >= 7) {
                                $alert_level = 'high';
                                $bg_class = 'from-orange-500 to-red-500';
                                $text_class = 'text-white';
                                $icon = 'fa-exclamation-triangle';
                                $message = 'Account at high risk';
                            } else {
                                $alert_level = 'medium';
                                $bg_class = 'from-orange-50 to-red-50';
                                $text_class = 'text-gray-800';
                                $icon = 'fa-exclamation-circle';
                                $message = 'Payment overdue';
                            }
                            
                            $due_amount = $installment['installment_amount'] - $installment['paid_amount'];
                            ?>
                            <div class="bg-gradient-to-r <?= $bg_class ?> p-4 rounded-xl border border-red-200">
                                <div class="flex justify-between items-start">
                                    <div class="flex items-start">
                                        <div class="bg-white p-2 rounded-lg mr-3 mt-1">
                                            <i class="fas <?= $icon ?> text-red-600"></i>
                                        </div>
                                        <div class="<?= $text_class ?>">
                                            <h4 class="font-bold">Installment #<?= $installment['installment_number'] ?></h4>
                                            <p class="text-sm opacity-90">
                                                Due: <?= date('d M, Y', strtotime($installment['due_date'])) ?>
                                                • Overdue: <?= abs($days_overdue) ?> days
                                            </p>
                                            <div class="mt-2">
                                                <span class="text-lg font-bold">₹<?= number_format($due_amount, 2) ?></span>
                                                <span class="text-sm ml-2">due</span>
                                            </div>
                                            <div class="mt-2 text-sm">
                                                <i class="fas fa-info-circle mr-1"></i>
                                                <strong><?= $message ?></strong>
                                                <?php if ($alert_level === 'critical'): ?>
                                                    - Account will be locked immediately
                                                <?php elseif ($alert_level === 'high'): ?>
                                                    - Account lock in <?= 14 - $days_overdue ?> days
                                                <?php else: ?>
                                                    - Please pay to avoid account lock
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs <?= $text_class ?> opacity-80 mb-2">
                                            Total: ₹<?= number_format($installment['installment_amount'], 2) ?>
                                            <br>
                                            Paid: ₹<?= number_format($installment['paid_amount'], 2) ?>
                                        </div>
                                        <button onclick="payNow(<?= $installment['id'] ?>)" 
                                                class="px-4 py-2 bg-white text-red-600 font-bold rounded-lg hover:bg-red-50 transition-colors duration-300">
                                            <i class="fas fa-credit-card mr-2"></i>Pay Now
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Overdue Summary -->
                        <div class="mt-6 bg-gradient-to-r from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                            <h4 class="font-bold text-gray-800 mb-3">Overdue Summary</h4>
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Total Overdue Amount:</span>
                                    <span class="font-bold text-red-700">₹<?= number_format($total_overdue_amount, 2) ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Late Fee (if applicable):</span>
                                    <span class="font-bold text-orange-700">₹<?= number_format($total_overdue_amount * 0.05, 2) ?> (5%)</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Total Payable:</span>
                                    <span class="font-bold text-red-800 text-lg">
                                        ₹<?= number_format($total_overdue_amount * 1.05, 2) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Account Lock Warning -->
                            <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                                <div class="flex items-start">
                                    <i class="fas fa-shield-alt text-red-600 mt-1 mr-2"></i>
                                    <div>
                                        <h5 class="font-bold text-red-700">Account Lock Warning</h5>
                                        <p class="text-sm text-red-600">
                                            <?php if ($account_at_risk === 'immediate'): ?>
                                                <i class="fas fa-skull-crossbones mr-1"></i>
                                                <strong>IMMEDIATE ACTION REQUIRED:</strong> Your account will be locked immediately.
                                            <?php elseif ($account_at_risk === 'high'): ?>
                                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                                <strong>HIGH RISK:</strong> Account will be locked in <?= $risk_days ?> days.
                                            <?php elseif ($account_at_risk === 'medium'): ?>
                                                <i class="fas fa-exclamation-circle mr-1"></i>
                                                <strong>MEDIUM RISK:</strong> Account at risk of being locked.
                                            <?php else: ?>
                                                <i class="fas fa-info-circle mr-1"></i>
                                                Account is currently safe from locking.
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($account_at_risk): ?>
                                            <p class="text-xs text-red-500 mt-1">
                                                <strong>Estimated Lock Date:</strong> <?= date('d M, Y', strtotime($lock_date)) ?>
                                                <br>
                                                <strong>Suggestion:</strong> Clear all overdue payments before <?= date('d M, Y', strtotime($lock_date)) ?> to avoid account suspension.
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="mt-4 grid grid-cols-2 gap-3">
                                <a href="contact_accounts.php" 
                                   class="text-center px-4 py-3 bg-gradient-to-r from-red-600 to-pink-600 text-white rounded-lg hover:shadow-lg transition-all duration-300 flex items-center justify-center">
                                    <i class="fas fa-headset mr-2"></i>
                                    Contact Support
                                </a>
                                <button onclick="showPaymentOptions()" 
                                        class="px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-lg hover:shadow-lg transition-all duration-300 flex items-center justify-center">
                                    <i class="fas fa-credit-card mr-2"></i>
                                    Make Payment
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <div class="bg-green-50 inline-block p-4 rounded-full mb-4">
                            <i class="fas fa-thumbs-up text-green-500 text-3xl"></i>
                        </div>
                        <p class="text-gray-500 text-lg">No overdue payments</p>
                        <p class="text-gray-400 mt-2">Great! You're on track with payments</p>
                        
                        <!-- Security Status -->
                        <div class="mt-6 bg-gradient-to-r from-green-50 to-emerald-50 p-4 rounded-xl border border-green-200">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-shield-check text-green-600 text-2xl mr-3"></i>
                                <div>
                                    <h5 class="font-bold text-green-700">Account Security Status: SAFE</h5>
                                    <p class="text-sm text-green-600">
                                        Your account is not at risk of being locked.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upcoming Payments -->
        <?php if (count($upcoming_installments) > 0): ?>
        <div class="bg-white p-6 rounded-2xl shadow-lg mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                <div class="bg-yellow-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-clock text-yellow-600"></i>
                </div>
                Upcoming Payments (Next 30 Days)
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($upcoming_installments as $installment): ?>
                    <?php
                    $due_date = new DateTime($installment['due_date']);
                    $today = new DateTime();
                    $days_until = $today->diff($due_date)->days;
                    
                    if ($days_until <= 3) {
                        $card_class = 'from-orange-50 to-yellow-50 border-orange-200';
                        $text_class = 'text-orange-700';
                        $icon = 'fa-exclamation-triangle';
                    } elseif ($days_until <= 7) {
                        $card_class = 'from-yellow-50 to-amber-50 border-yellow-200';
                        $text_class = 'text-yellow-700';
                        $icon = 'fa-clock';
                    } else {
                        $card_class = 'from-blue-50 to-cyan-50 border-blue-200';
                        $text_class = 'text-blue-700';
                        $icon = 'fa-calendar-alt';
                    }
                    ?>
                    <div class="bg-gradient-to-r <?= $card_class ?> p-4 rounded-xl border <?= $card_class ?>-border">
                        <div class="flex justify-between items-start">
                            <div>
                                <h4 class="font-bold text-gray-900">
                                    Installment #<?= $installment['installment_number'] ?>
                                </h4>
                                <div class="mt-2">
                                    <div class="text-lg font-bold <?= $text_class ?>">
                                        ₹<?= number_format($installment['installment_amount'], 2) ?>
                                    </div>
                                    <div class="text-sm text-gray-600">
                                        Due: <?= date('d M, Y', strtotime($installment['due_date'])) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="bg-white p-2 rounded-lg mb-2">
                                    <i class="fas <?= $icon ?> <?= $text_class ?>"></i>
                                </div>
                                <div class="text-xs <?= $text_class ?>">
                                    in <?= $days_until ?> days
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="h-2 rounded-full bg-green-600" 
                                     style="width: <?= ($installment['paid_amount'] / $installment['installment_amount']) * 100 ?>%"></div>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                Paid: ₹<?= number_format($installment['paid_amount'], 2) ?> / 
                                ₹<?= number_format($installment['installment_amount'], 2) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Payments & Receipts -->
        <div class="bg-white p-6 rounded-2xl shadow-lg mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                <div class="bg-green-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-receipt text-green-600"></i>
                </div>
                Recent Payments & Receipts
            </h2>
            
            <?php if (count($recent_transactions) > 0): ?>
                <div class="space-y-4">
                    <?php foreach ($recent_transactions as $transaction): ?>
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 hover:border-green-300 transition-colors duration-200">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="bg-green-100 p-2 rounded-lg mr-3">
                                            <i class="fas fa-file-invoice-dollar text-green-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-medium text-gray-900">
                                                Payment Receipt #<?= htmlspecialchars($transaction['receipt_no'] ?? $transaction['transaction_id']) ?>
                                            </h4>
                                            <p class="text-xs text-gray-500">
                                                <?= date('d M, Y', strtotime($transaction['receipt_date'])) ?> • 
                                                <?= htmlspecialchars($transaction['batch_name'] ?? 'Course Fee') ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="ml-11">
                                        <div class="flex items-center space-x-4 text-sm text-gray-600">
                                            <div class="flex items-center">
                                                <i class="fas fa-money-bill-wave mr-1 text-green-500"></i>
                                                <span class="font-medium">₹<?= number_format($transaction['amount'], 2) ?></span>
                                            </div>
                                            <div class="flex items-center">
                                                <i class="fas fa-credit-card mr-1 text-blue-500"></i>
                                                <span><?= ucfirst(str_replace('_', ' ', $transaction['payment_mode'])) ?></span>
                                            </div>
                                            <?php if ($transaction['payment_recipient']): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-building mr-1 text-purple-500"></i>
                                                <span><?= htmlspecialchars($transaction['payment_recipient']) ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($transaction['verified_by_name']): ?>
                                        <div class="mt-2 text-xs text-gray-500">
                                            Verified by: <?= htmlspecialchars($transaction['verified_by_name']) ?> on 
                                            <?= date('d/m/Y H:i', strtotime($transaction['verification_time'])) ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="ml-4 flex flex-col items-end">
                                    <a href="../accounts/generate_receipt.php?id=<?= $transaction['id'] ?>" 
                                       target="_blank"
                                       class="mb-2 px-3 py-1 bg-green-100 text-green-800 text-xs font-medium rounded-lg hover:bg-green-200 transition-colors duration-200 flex items-center">
                                        <i class="fas fa-download mr-1"></i>
                                        Receipt
                                    </a>
                                    <span class="text-xs text-gray-500">
                                        Transaction ID: <?= substr($transaction['transaction_id'], 0, 8) ?>...
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- View All Receipts Button -->
                <?php if (count($transactions) > 5): ?>
                    <div class="mt-6 text-center">
                        <button onclick="showAllReceipts()" 
                                class="px-4 py-2 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-lg hover:shadow-lg transition-all duration-300 flex items-center mx-auto">
                            <i class="fas fa-list mr-2"></i>
                            View All <?= count($transactions) ?> Receipts
                        </button>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <div class="bg-green-50 inline-block p-4 rounded-full mb-4">
                        <i class="fas fa-file-invoice text-green-500 text-3xl"></i>
                    </div>
                    <p class="text-gray-500 text-lg">No payment receipts found</p>
                    <p class="text-gray-400 mt-2">Your payment receipts will appear here</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Information & Help Section -->
        <div class="bg-gradient-to-r from-blue-50 to-cyan-50 p-6 rounded-2xl shadow-lg mb-6 border border-blue-200">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                Payment Information & Support
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-bold text-gray-700 mb-3">Account Lock Policy</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-red-500 mt-1 mr-2"></i>
                            <span><strong>7+ days overdue:</strong> Account lock warning</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-skull-crossbones text-red-600 mt-1 mr-2"></i>
                            <span><strong>14+ days overdue:</strong> Immediate account lock</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-lock text-orange-500 mt-1 mr-2"></i>
                            <span>Locked accounts cannot access classes, content, or tests</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-unlock text-green-500 mt-1 mr-2"></i>
                            <span>Account unlocked within 24 hours of full payment</span>
                        </li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="font-bold text-gray-700 mb-3">Contact Accounts Department</h3>
                    <div class="space-y-3 text-sm text-gray-600">
                        <div class="flex items-center">
                            <i class="fas fa-envelope text-blue-500 mr-2"></i>
                            <span>accounts@asdacademy.in</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-phone text-blue-500 mr-2"></i>
                            <span>+91 9680100687 (Urgent payments only)</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-clock text-blue-500 mr-2"></i>
                            <span>Mon-Sat: 10:00 AM - 6:00 PM</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-map-marker-alt text-blue-500 mr-2"></i>
                            <span>Visit: Flat No. 1841-42, Akansha Deep Heights, Kota</span>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <a href="contact_accounts.php" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-300">
                            <i class="fas fa-headset mr-2"></i>
                            Contact Support
                        </a>
                        <button onclick="showPaymentInstructions()" 
                                class="ml-3 inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors duration-300">
                            <i class="fas fa-credit-card mr-2"></i>
                            Payment Instructions
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .animate-fade-in {
        animation: fadeIn 0.6s ease-out forwards;
    }
    
    .delay-100 { animation-delay: 0.1s; }
    .delay-200 { animation-delay: 0.2s; }
    .delay-300 { animation-delay: 0.3s; }
    
    tr {
        animation: fadeIn 0.5s ease-out forwards;
    }
    
    /* Warning pulse animation */
    @keyframes pulse-warning {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    
    .animate-pulse-warning {
        animation: pulse-warning 2s infinite;
    }
    
    /* Custom scrollbar */
    .overflow-x-auto::-webkit-scrollbar {
        height: 8px;
    }
    
    .overflow-x-auto::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .overflow-x-auto::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }
    
    .overflow-x-auto::-webkit-scrollbar-thumb:hover {
        background: #a1a1a1;
    }
    
    /* Print styles */
    @media print {
        .action-buttons, 
        button,
        .no-print {
            display: none !important;
        }
        
        body {
            background: white !important;
            padding: 0 !important;
        }
        
        .receipt-container {
            border: none !important;
            box-shadow: none !important;
        }
    }
</style>

<script>
// Mobile menu toggle
function toggleMobileMenu() {
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileMenuContent = mobileMenu.querySelector('div');
    
    if (mobileMenu.classList.contains('hidden')) {
        mobileMenu.classList.remove('hidden');
        setTimeout(() => {
            mobileMenuContent.classList.remove('-translate-x-full');
        }, 10);
    } else {
        mobileMenuContent.classList.add('-translate-x-full');
        setTimeout(() => {
            mobileMenu.classList.add('hidden');
        }, 300);
    }
}

// Show payment options modal
function showPaymentOptions() {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4';
    modal.innerHTML = `
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full animate-fade-in">
            <div class="bg-gradient-to-r from-green-500 to-emerald-600 p-4 flex justify-between items-center rounded-t-2xl">
                <h3 class="text-xl font-bold text-white">Make Payment</h3>
                <button onclick="this.closest('.fixed').remove()" class="text-white hover:text-gray-200 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <h4 class="font-bold text-gray-800 mb-2">Total Amount Due:</h4>
                    <div class="text-3xl font-bold text-red-600">
                        ₹<?= number_format($total_overdue_amount * 1.05, 2) ?>
                    </div>
                    <p class="text-sm text-gray-600 mt-1">
                        Includes 5% late fee (if applicable)
                    </p>
                </div>
                
                <div class="space-y-4">
                    <div class="border rounded-lg p-4">
                        <h5 class="font-bold text-gray-700 mb-2">Bank Transfer</h5>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div><strong>Account Name:</strong> ASD CYBERNETICS INC.</div>
                            <div><strong>Account No:</strong> 50200067618515</div>
                            <div><strong>IFSC:</strong> HDFC0001028</div>
                            <div><strong>Bank:</strong> HDFC Bank, Kunhadi, Kota</div>
                        </div>
                        <p class="text-xs text-red-600 mt-2">
                            <i class="fas fa-exclamation-circle"></i> 
                            Mention "Student ID: <?= htmlspecialchars($student['student_id']) ?>" in payment remarks
                        </p>
                    </div>
                    
                    <div class="border rounded-lg p-4">
                        <h5 class="font-bold text-gray-700 mb-2">UPI Payment</h5>
                        <div class="flex items-center space-x-3">
                            <div class="bg-blue-100 p-3 rounded-lg">
                                <i class="fas fa-qrcode text-blue-600 text-2xl"></i>
                            </div>
                            <div>
                                <div class="font-mono text-sm bg-gray-100 p-2 rounded">
                                    9680100687@hdfcbank
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Scan QR code or use UPI ID</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="border rounded-lg p-4">
                        <h5 class="font-bold text-gray-700 mb-2">Cash Payment</h5>
                        <div class="text-sm text-gray-600">
                            Visit our office during working hours:
                            <br>
                            <strong>Address:</strong> Flat No. 1841-42, Akansha Deep Heights, Kunhadi, Nanta, Kota – 324008
                            <br>
                            <strong>Timings:</strong> Mon-Sat, 10:00 AM - 6:00 PM
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 bg-yellow-50 border border-yellow-200 p-3 rounded-lg">
                    <h6 class="font-bold text-yellow-800 mb-1"><i class="fas fa-exclamation-triangle mr-1"></i> Important</h6>
                    <p class="text-sm text-yellow-700">
                        After making payment, upload the receipt/screenshot through the payment portal or email to accounts@asdacademy.in
                    </p>
                </div>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <button onclick="this.closest('.fixed').remove()" 
                            class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors duration-200">
                        Cancel
                    </button>
                    <a href="../accounts/upload_receipt.php" 
                       class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors duration-200">
                        <i class="fas fa-upload mr-2"></i>Upload Receipt
                    </a>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// Show payment instructions
function showPaymentInstructions() {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4';
    modal.innerHTML = `
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full animate-fade-in">
            <div class="bg-gradient-to-r from-blue-500 to-cyan-600 p-4 flex justify-between items-center rounded-t-2xl">
                <h3 class="text-xl font-bold text-white">Payment Instructions</h3>
                <button onclick="this.closest('.fixed').remove()" class="text-white hover:text-gray-200 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <h4 class="font-bold text-blue-700 mb-2">Step 1: Make Payment</h4>
                        <p class="text-sm text-gray-700">
                            Choose any payment method (Bank Transfer, UPI, Cash, Cheque) and complete the payment.
                        </p>
                    </div>
                    
                    <div class="bg-green-50 p-4 rounded-lg">
                        <h4 class="font-bold text-green-700 mb-2">Step 2: Save Proof</h4>
                        <p class="text-sm text-gray-700">
                            Save the payment receipt/screenshot/transaction ID. For cash payments, collect the official receipt.
                        </p>
                    </div>
                    
                    <div class="bg-purple-50 p-4 rounded-lg">
                        <h4 class="font-bold text-purple-700 mb-2">Step 3: Upload Proof</h4>
                        <p class="text-sm text-gray-700">
                            Upload the payment proof through the student portal or email to accounts@asdacademy.in
                        </p>
                        <p class="text-xs text-purple-600 mt-1">
                            <strong>Email Subject:</strong> Payment Proof - Student ID: <?= htmlspecialchars($student['student_id']) ?>
                        </p>
                    </div>
                    
                    <div class="bg-yellow-50 p-4 rounded-lg">
                        <h4 class="font-bold text-yellow-700 mb-2">Step 4: Verification</h4>
                        <p class="text-sm text-gray-700">
                            Accounts department will verify within 24-48 hours. You'll receive confirmation email.
                        </p>
                    </div>
                    
                    <div class="bg-red-50 p-4 rounded-lg">
                        <h4 class="font-bold text-red-700 mb-2">Important Notes:</h4>
                        <ul class="text-sm text-gray-700 space-y-1">
                            <li><i class="fas fa-check-circle text-green-500 mr-1"></i> Always mention Student ID in payment remarks</li>
                            <li><i class="fas fa-check-circle text-green-500 mr-1"></i> Keep payment proof until verification is complete</li>
                            <li><i class="fas fa-check-circle text-green-500 mr-1"></i> Clear payments before due date to avoid late fees</li>
                            <li><i class="fas fa-exclamation-triangle text-red-500 mr-1"></i> Accounts overdue for 14+ days will be locked</li>
                        </ul>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button onclick="this.closest('.fixed').remove()" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        I Understand
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// Pay specific installment
function payNow(installmentId) {
    // In a real implementation, this would redirect to a payment gateway
    // For now, show payment options
    showPaymentOptions();
}

// Show all receipts modal (from previous code)
function showAllReceipts() {
    // ... same as before ...
}

// Print individual receipt
function printReceipt(transactionId) {
    const printWindow = window.open(`../accounts/generate_receipt.php?id=${transactionId}&print=true`, '_blank');
    setTimeout(() => {
        if (printWindow) {
            printWindow.print();
        }
    }, 1000);
}

// Auto-animate elements on page load
document.addEventListener('DOMContentLoaded', function() {
    // Animate cards
    const cards = document.querySelectorAll('.bg-white');
    cards.forEach((card, index) => {
        card.classList.add('animate-fade-in');
        card.classList.add(`delay-${(index % 4) + 1}00`);
    });
    
    // Animate progress bars
    const progressBars = document.querySelectorAll('.h-2');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, 300);
    });
    
    // Auto-scroll to overdue section if there are overdue payments
    <?php if (count($overdue_installments) > 0): ?>
    setTimeout(() => {
        const overdueSection = document.querySelector('[class*="bg-red-100"]');
        if (overdueSection) {
            overdueSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 1000);
    <?php endif; ?>
});

// Handle ESC key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const mobileMenu = document.getElementById('mobileMenu');
        if (!mobileMenu.classList.contains('hidden')) {
            toggleMobileMenu();
        }
        
        // Close modal if open
        const modal = document.querySelector('.fixed.bg-black');
        if (modal) {
            modal.remove();
        }
    }
});

// Auto-refresh page every 5 minutes to update overdue status
setTimeout(() => {
    window.location.reload();
}, 300000); // 5 minutes
</script>

<?php include '../footer.php'; ?>