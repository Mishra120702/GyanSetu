<?php
// generate_receipt.php - Unified receipt generator for both manual and uploaded transactions
include '../db_connection.php';
session_start();



$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_manual = isset($_GET['manual']) ? (bool)$_GET['manual'] : false;

// Handle receipt generation from database transaction
if (!$is_manual && $transaction_id > 0) {
    // Get transaction details from database
    $stmt = $db->prepare("
        SELECT t.*, 
               b.batch_name, 
               s.email, 
               s.phone_number, 
               s.father_name,
               s.enrollment_fees as student_total_fees,
               s.total_fees_paid as student_previous_payments,
               (s.enrollment_fees - s.total_fees_paid) as student_outstanding_fees,
               u.name as verified_by_name, 
               u.email as verified_by_email
        FROM transactions t
        LEFT JOIN batches b ON t.batch_id = b.batch_id
        LEFT JOIN students s ON t.student_id = s.student_id
        LEFT JOIN users u ON t.verified_by = u.id
        WHERE t.id = ? AND t.status = 'verified'
    ");
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        die("Transaction not found or not verified.");
    }

    // Generate receipt number if not exists (IMPORTANT: Don't change existing receipt numbers)
    if (empty($transaction['receipt_no'])) {
        // Determine prefix based on payment recipient
        $is_bugdetox = strpos($transaction['payment_recipient'] ?? '', 'BUGDETOX') !== false || 
                       strpos($transaction['payment_recipient'] ?? '', 'BugDetox') !== false;
        
        if ($is_bugdetox) {
            $receipt_prefix = "BUG-A-";
        } else {
            $receipt_prefix = "ASDN-A-";
        }
        
        // Get next sequence number
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE receipt_no LIKE ?");
        $stmt->execute([$receipt_prefix . '%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_seq = ($result['count'] ?? 0) + 1;
        
        $receipt_no = $receipt_prefix . str_pad($next_seq, 5, '0', STR_PAD_LEFT);
        
        // Update transaction with receipt number
        $updateStmt = $db->prepare("UPDATE transactions SET receipt_no = ?, receipt_generated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$receipt_no, $transaction_id]);
        
        $transaction['receipt_no'] = $receipt_no;
    }

    // Determine company based on payment recipient
    $is_bugdetox = strpos($transaction['payment_recipient'] ?? '', 'BUGDETOX') !== false || 
                   strpos($transaction['payment_recipient'] ?? '', 'BugDetox') !== false;

    // Prepare receipt data
    $receipt_data = [
        'receipt_no' => $transaction['receipt_no'],
        'student_id' => $transaction['student_id'],
        'student_name' => $transaction['student_name'],
        'phone_number' => $transaction['phone_number'] ?? 'N/A',
        'email' => $transaction['email'] ?? 'N/A',
        'father_name' => $transaction['father_name'] ?? 'N/A',
        'batch_name' => $transaction['batch_name'] ?? 'Course Fee',
        'batch_id' => $transaction['batch_id'] ?? '',
        'total_fees' => $transaction['total_fees'] ?? $transaction['student_total_fees'] ?? $transaction['amount'],
        'amount_received' => $transaction['amount'],
        'previous_payments' => $transaction['previous_payments'] ?? $transaction['student_previous_payments'] ?? 0,
        'outstanding_fees' => $transaction['outstanding_fees'] ?? $transaction['student_outstanding_fees'] ?? 0,
        'payment_mode' => $transaction['payment_mode'],
        'payment_recipient' => $transaction['payment_recipient'] ?? 'ASDN Cybernatics',
        'receipt_date' => $transaction['transaction_date'] . ' ' . date('H:i:s', strtotime($transaction['uploaded_at'] ?? 'now')),
        'remarks' => $transaction['remarks'] ?? '',
        'verified_by_name' => $transaction['verified_by_name'] ?? $_SESSION['name'],
        'verified_at' => $transaction['verified_at'] ?? date('Y-m-d H:i:s'),
        'is_bugdetox' => $is_bugdetox,
        'is_manual' => $transaction['is_manual'] ?? 0,
        'transaction_id' => $transaction['transaction_id'],
        'id' => $transaction['id']
    ];
    
    // Calculate outstanding if not already calculated
    if ($receipt_data['outstanding_fees'] <= 0) {
        $receipt_data['outstanding_fees'] = max(0, 
            $receipt_data['total_fees'] - 
            ($receipt_data['previous_payments'] + $receipt_data['amount_received'])
        );
    }

    // Set company details based on payment recipient
    if ($is_bugdetox) {
        $receipt_data['company_name'] = "BUGDETOX TECHNOLOGY LLP";
        $receipt_data['company_address'] = "Shop No. B-5, Akansha Deep Heights, Kunhadi, Nanta, Kunhadi, Kota - 324008 Raj. India";
        $receipt_data['pan_no'] = "ABDFB1950C";
    } else {
        $receipt_data['company_name'] = "ASDN CYBERNETICS INC.";
        $receipt_data['company_address'] = "Flat No. 1841-42, Akansha Deep Heights, Kunhadi, Nanta, Kota – 324008 Raj. India";
        $receipt_data['pan_no'] = "ABRFA2507Q";
        $receipt_data['gstin'] = "08ABRFA2507Q1ZW";
        $receipt_data['reg_no'] = "SCA/2020/20/132603";
    }

    $amount_in_words = numberToWords(round($receipt_data['amount_received'])) . " Rupees Only";
} elseif ($is_manual && isset($_SESSION['manual_receipt'])) {
    // Use session data for manual receipt preview
    $receipt_data = $_SESSION['manual_receipt'];
    $amount_in_words = numberToWords(round($receipt_data['amount_received'])) . " Rupees Only";
} else {
    die("No receipt data available.");
}

// Function to convert number to words
function numberToWords($num) {
    $ones = array(
        0 => "Zero", 1 => "One", 2 => "Two", 3 => "Three", 4 => "Four",
        5 => "Five", 6 => "Six", 7 => "Seven", 8 => "Eight", 9 => "Nine",
        10 => "Ten", 11 => "Eleven", 12 => "Twelve", 13 => "Thirteen",
        14 => "Fourteen", 15 => "Fifteen", 16 => "Sixteen", 17 => "Seventeen",
        18 => "Eighteen", 19 => "Nineteen"
    );
    
    $tens = array(
        2 => "Twenty", 3 => "Thirty", 4 => "Forty", 5 => "Fifty",
        6 => "Sixty", 7 => "Seventy", 8 => "Eighty", 9 => "Ninety"
    );
    
    if ($num < 20) {
        return $ones[$num];
    } elseif ($num < 100) {
        $result = $tens[intval($num / 10)];
        if ($num % 10 > 0) {
            $result .= " " . $ones[$num % 10];
        }
        return $result;
    } elseif ($num < 1000) {
        $result = $ones[intval($num / 100)] . " Hundred";
        if ($num % 100 > 0) {
            $result .= " and " . numberToWords($num % 100);
        }
        return $result;
    } elseif ($num < 100000) {
        $result = numberToWords(intval($num / 1000)) . " Thousand";
        if ($num % 1000 > 0) {
            $result .= " " . numberToWords($num % 1000);
        }
        return $result;
    } elseif ($num < 10000000) {
        $result = numberToWords(intval($num / 100000)) . " Lakh";
        if ($num % 100000 > 0) {
            $result .= " " . numberToWords($num % 100000);
        }
        return $result;
    }
    
    return $num;
}

// Function to check payment mode selection
function isPaymentModeSelected($transactionMode, $checkMode) {
    return $transactionMode === $checkMode ? 'background: #000;' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INVOICE - <?php echo $receipt_data['company_name']; ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background: #f5f5f5;
            color: #000;
            padding: 20px;
            min-height: 100vh;
        }
        
        .receipt-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border: 1px solid #ddd;
            position: relative;
        }
        
        .receipt-header {
            padding: 15px 20px;
            border-bottom: 1px solid #000;
        }
        
        .header-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 11px;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
            text-align: center;
        }
        
        .company-address {
            font-size: 11px;
            text-align: center;
            margin-bottom: 5px;
            line-height: 1.4;
        }
        
        .company-contacts {
            font-size: 11px;
            text-align: center;
            margin-bottom: 5px;
        }
        
        .iso-cert {
            font-size: 10px;
            text-align: center;
            font-style: italic;
            margin-bottom: 10px;
        }
        
        .tax-invoice {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            padding: 10px;
            border-bottom: 2px solid #000;
        }
        
        .receipt-info {
            display: flex;
            justify-content: space-between;
            padding: 10px 20px;
            border-bottom: 1px solid #ddd;
            font-size: 12px;
        }
        
        .receipt-body {
            padding: 15px 20px;
        }
        
        .form-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        .form-table td {
            padding: 8px 5px;
            vertical-align: top;
            border: none;
        }
        
        .form-table .label {
            font-weight: bold;
            width: 30%;
            white-space: nowrap;
        }
        
        .form-table .value {
            width: 70%;
            border-bottom: 1px solid #ddd;
        }
        
        .form-table .checkbox-group {
            display: flex;
            gap: 15px;
            margin-top: 5px;
        }
        
        .form-table .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .form-table .checkbox {
            width: 12px;
            height: 12px;
            border: 1px solid #000;
            display: inline-block;
        }
        
        .amount-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
        }
        
        .amount-table th,
        .amount-table td {
            padding: 8px;
            border: 1px solid #000;
            text-align: right;
        }
        
        .amount-table th {
            background: #f0f0f0;
            font-weight: bold;
        }
        
        .amount-table th:first-child {
            text-align: left;
        }
        
        .amount-table td:first-child {
            text-align: left;
        }
        
        .amount-table .total-row {
            font-weight: bold;
            background: #f0f0f0;
        }
        
        .amount-table .grand-total {
            font-weight: bold;
            background: #e0e0e0;
        }
        
        .amount-in-words {
            margin-top: 15px;
            padding: 10px;
            border: 1px solid #ddd;
            font-size: 12px;
        }
        
        .amount-in-words .label {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .signature-section {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            padding: 0 20px;
        }
        
        .signature-box {
            text-align: center;
            width: 200px;
            position: relative;
        }
        
        .signature-image {
            height: 50px;
            margin-bottom: 5px;
            object-fit: contain;
            position: relative;
            z-index: 1;
        }
        
        .stamp-container {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 200px;
            height: 80px;
            z-index: 2;
            pointer-events: none;
        }
        
        .stamp-image {
            width: 100%;
            height: 100%;
            object-fit: contain;
            opacity: 0.9;
            mix-blend-mode: multiply;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            width: 100%;
            margin: 10px 0 5px;
        }
        
        .footer-note {
            margin-top: 20px;
            padding: 10px;
            font-size: 11px;
            text-align: center;
            color: #666;
            border-top: 1px solid #ddd;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
            color: white;
        }
        
        .btn-print {
            background: #4CAF50;
        }
        
        .btn-print:hover {
            background: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }
        
        .btn-download {
            background: #2196F3;
        }
        
        .btn-download:hover {
            background: #1976D2;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.3);
        }
        
        .btn-back {
            background: #757575;
        }
        
        .btn-back:hover {
            background: #616161;
        }
        
        .btn-new {
            background: #FF9800;
        }
        
        .btn-new:hover {
            background: #F57C00;
        }
        
        .red-text {
            color: #e53e3e !important;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            
            .action-buttons {
                display: none !important;
            }
            
            .receipt-container {
                border: none;
                max-width: 100%;
                margin: 0;
            }
            
            .btn {
                display: none !important;
            }
            
            .stamp-image {
                opacity: 0.85;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Header -->
        <div class="receipt-header">
            <div class="header-row">
                <?php if (!$receipt_data['is_bugdetox'] && isset($receipt_data['gstin'])): ?>
                    <div><strong>GSTIN:</strong> <?php echo $receipt_data['gstin']; ?></div>
                <?php endif; ?>
                <div><strong>PAN No:</strong> <?php echo $receipt_data['pan_no']; ?></div>
                <?php if (!$receipt_data['is_bugdetox'] && isset($receipt_data['reg_no'])): ?>
                    <div><strong>Reg. No:</strong> <?php echo $receipt_data['reg_no']; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="company-name"><?php echo $receipt_data['company_name']; ?></div>
            
            <div class="company-address">
                <?php echo $receipt_data['company_address']; ?>
            </div>
            
            <div class="company-contacts">
                (W) www.asdacademy.in &nbsp;&nbsp; (E) info@asdacademy.in &nbsp;&nbsp; 
                M. 9680100687, 9461188436
            </div>
            
            <div class="iso-cert">
                AS ISO 27001:2013 & 9001:2015 Certified Company
            </div>
        </div>
        
        <!-- Tax Invoice Title -->
        <div class="tax-invoice">TAX INVOICE</div>
        
        <!-- Receipt Info -->
        <div class="receipt-info">
            <div><strong>Receipt No.:</strong> <?php echo $receipt_data['receipt_no']; ?></div>
            <div><strong>Date:</strong> <?php echo date('Y-m-d H:i:s', strtotime($receipt_data['receipt_date'])); ?></div>
            <?php if ($receipt_data['student_id']): ?>
            <div><strong>Student ID:</strong> <?php echo htmlspecialchars($receipt_data['student_id']); ?></div>
            <?php endif; ?>
        </div>
        
        <!-- Body -->
        <div class="receipt-body">
            <!-- Form Table -->
            <table class="form-table">
                <tr>
                    <td class="label">Cash received From</td>
                    <td class="value"><?php echo htmlspecialchars($receipt_data['student_name']); ?></td>
                </tr>
                <tr>
                    <td class="label">Mobile No.</td>
                    <td class="value"><?php echo htmlspecialchars($receipt_data['phone_number']); ?></td>
                </tr>
                <tr>
                    <td class="label">Email</td>
                    <td class="value"><?php echo htmlspecialchars($receipt_data['email']); ?></td>
                </tr>
                <?php if ($receipt_data['father_name'] && $receipt_data['father_name'] !== 'N/A'): ?>
                <tr>
                    <td class="label">Father's Name</td>
                    <td class="value"><?php echo htmlspecialchars($receipt_data['father_name']); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="label">For</td>
                    <td class="value">
                        <?php echo htmlspecialchars($receipt_data['batch_name']); ?>
                        <?php if ($receipt_data['batch_id']): ?>
                            (Batch ID: <?php echo htmlspecialchars($receipt_data['batch_id']); ?>)
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="label">Payment Received in:</td>
                    <td class="value">
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <span class="checkbox" style="<?php echo isPaymentModeSelected($receipt_data['payment_mode'], 'cash'); ?>"></span> Cash
                            </div>
                            <div class="checkbox-item">
                                <span class="checkbox" style="<?php echo isPaymentModeSelected($receipt_data['payment_mode'], 'cheque'); ?>"></span> Cheque
                            </div>
                            <div class="checkbox-item">
                                <span class="checkbox" style="<?php echo isPaymentModeSelected($receipt_data['payment_mode'], 'bank_transfer'); ?>"></span> Bank Transfer
                            </div>
                            <div class="checkbox-item">
                                <span class="checkbox" style="<?php echo isPaymentModeSelected($receipt_data['payment_mode'], 'other'); ?>"></span> Other
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
            
            <!-- Amount Table -->
            <table class="amount-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Total Fees (₹)</th>
                        <th>This Payment (₹)</th>
                        <th>Outstanding Fees (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Course Fee - <?php echo htmlspecialchars($receipt_data['batch_name']); ?></td>
                        <td>₹<?php echo number_format($receipt_data['total_fees'], 2); ?></td>
                        <td>₹<?php echo number_format($receipt_data['amount_received'], 2); ?></td>
                        <td>₹<?php echo number_format($receipt_data['outstanding_fees'], 2); ?></td>
                    </tr>
                    
                    <?php if ($receipt_data['previous_payments'] > 0): ?>
                    <tr>
                        <td>Previous Payments Received</td>
                        <td></td>
                        <td>₹<?php echo number_format($receipt_data['previous_payments'], 2); ?></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- Total Received Row -->
                    <tr class="total-row">
                        <td><strong>Total Received So Far</strong></td>
                        <td></td>
                        <td><strong>₹<?php echo number_format($receipt_data['previous_payments'] + $receipt_data['amount_received'], 2); ?></strong></td>
                        <td></td>
                    </tr>
                    
                    <!-- Grand Total Row -->
                    <tr class="grand-total">
                        <td><strong>FEE SUMMARY</strong></td>
                        <td><strong>₹<?php echo number_format($receipt_data['total_fees'], 2); ?></strong></td>
                        <td><strong>₹<?php echo number_format($receipt_data['previous_payments'] + $receipt_data['amount_received'], 2); ?></strong></td>
                        <td><strong class="red-text">₹<?php echo number_format($receipt_data['outstanding_fees'], 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            
            <?php if ($receipt_data['remarks']): ?>
            <div class="amount-in-words">
                <div class="label">Remarks:</div>
                <div><?php echo htmlspecialchars($receipt_data['remarks']); ?></div>
            </div>
            <?php endif; ?>
            
            <!-- Amount in Words -->
            <div class="amount-in-words">
                <div class="label">Amount in Words:</div>
                <div><?php echo $amount_in_words; ?></div>
            </div>
            
            <!-- Signature Section -->
            <div class="signature-section">
                <div class="signature-box">
                    <!-- Stamp over the signature -->
                    <div class="stamp-container">
                        <img src="seal.png" alt="Company Stamp" class="stamp-image" onerror="this.style.display='none'">
                    </div>
                    <img src="sign.jpg" alt="Signature-Receipent" class="signature-image" onerror="this.style.display='none'">
                    <div>Received By</div>
                    <div class="signature-line"></div>
                    <div style="font-size: 11px; margin-top: 5px;">
                        Authorized Signatory<br>
                        <?php echo htmlspecialchars($receipt_data['company_name']); ?>
                    </div>
                </div>
                <div class="signature-box">
                    <img src="sign2.png" alt="Signature-Payee" class="signature-image" onerror="this.style.display='none'">
                    <div>Paid By</div>
                    <div class="signature-line"></div>
                    <div style="font-size: 11px; margin-top: 5px;">
                        <?php echo htmlspecialchars($receipt_data['student_name']); ?><br>
                        Student/Parent
                    </div>
                </div>
            </div>
            
            <!-- Footer Note -->
            <div class="footer-note">
                <strong>Note:</strong> The amount received once is not refundable in any case. Area of Jurisdiction will be Kota only.
                <br>Payment Mode: <?php echo strtoupper(str_replace('_', ' ', $receipt_data['payment_mode'])); ?> | 
                Payment Recipient: <?php echo htmlspecialchars($receipt_data['payment_recipient']); ?>
                <br>Verified by: <?php echo htmlspecialchars($receipt_data['verified_by_name']); ?> 
                on <?php echo date('d/m/Y H:i', strtotime($receipt_data['verified_at'])); ?>
                <?php if ($receipt_data['outstanding_fees'] > 0): ?>
                    <br><strong>Outstanding Fees:</strong> ₹<?php echo number_format($receipt_data['outstanding_fees'], 2); ?> (to be paid by student)
                <?php endif; ?>
                <?php if ($receipt_data['is_manual']): ?>
                    <br><em>This is a manually generated receipt</em>
                <?php endif; ?>
                <br><strong>Receipt Number:</strong> <?php echo $receipt_data['receipt_no']; ?> (For taxation purposes)
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            
            <button onclick="printReceipt()" class="btn btn-print">
                🖨️ Print Receipt
            </button>
            <button onclick="downloadAsPDF()" class="btn btn-download">
                📥 Download PDF
            </button>
            
        </div>
    </div>

    <!-- html2pdf library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <script>
        // Print function
        function printReceipt() {
            window.print();
        }
        
        // Download as PDF
        function downloadAsPDF() {
            const element = document.querySelector('.receipt-container');
            const opt = {
                margin: 10,
                filename: 'Invoice_<?php echo htmlspecialchars($receipt_data['student_name']); ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'A3', orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(element).save();
        }
        
        // Auto-focus print on page load
        window.addEventListener('load', function() {
            if (window.location.search.includes('print=true')) {
                window.print();
            }
            
            // Clear session data for manual receipts after 5 minutes
            if (<?php echo $is_manual ? 'true' : 'false'; ?>) {
                setTimeout(() => {
                    fetch('clear_session.php?type=manual_receipt').catch(err => console.error(err));
                }, 300000);
            }
        });
    </script>
</body>
</html>