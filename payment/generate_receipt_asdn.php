<?php
// generate_receipt.php
include '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get transaction details
$stmt = $db->prepare("
    SELECT t.*, b.batch_name, s.email, s.phone_number, s.father_name,
           u.name as verified_by_name, u.email as verified_by_email
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

// Generate receipt number
$receipt_no = "ASDN-A-" . str_pad($transaction['id'], 4, '0', STR_PAD_LEFT);

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

// Calculate GST (assuming 18% for example)
$gst_percentage = 18;
$gst_amount = ($transaction['amount'] * $gst_percentage) / 100;
$total_with_gst = $transaction['amount'] + $gst_amount;

// Convert amount to words
$amount_in_words = numberToWords(round($transaction['amount'])) . " Rupees Only";

// Get payment mode in proper format
$payment_mode_display = '';
switch($transaction['payment_mode']) {
    case 'cash':
        $payment_mode_display = 'Cash';
        break;
    case 'cheque':
        $payment_mode_display = 'Cheque';
        break;
    case 'bank_transfer':
        $payment_mode_display = 'Bank Transfer';
        break;
    case 'e_wallet':
        $payment_mode_display = 'E-Wallet';
        break;
    default:
        $payment_mode_display = ucfirst(str_replace('_', ' ', $transaction['payment_mode']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TAX INVOICE - ASDN CYBERNETICS INC.</title>
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
            max-width: 800px;
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
            text-align: left;
        }
        
        .amount-table th {
            background: #f0f0f0;
            font-weight: bold;
        }
        
        .amount-table .total-row {
            font-weight: bold;
            background: #f0f0f0;
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
        }
        
        .signature-line {
            border-top: 1px solid #000;
            width: 100%;
            margin: 20px 0 10px;
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
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        
        .btn {
            padding: 8px 20px;
            border: 1px solid #ddd;
            background: #f0f0f0;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-print {
            background: #4CAF50;
            color: white;
            border: none;
        }
        
        .btn-download {
            background: #2196F3;
            color: white;
            border: none;
        }
        
        .btn-back {
            background: #757575;
            color: white;
            border: none;
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
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Header -->
        <div class="receipt-header">
            <div class="header-row">
                <div><strong>GSTIN:</strong> 08ABRFA2507Q1ZW</div>
                <div><strong>PAN No:</strong> ABRFA2507Q</div>
                <div><strong>Reg. No:</strong> SCA/2020/20/132603</div>
            </div>
            
            <div class="company-name">ASDN CYBERNETICS INC.</div>
            
            <div class="company-address">
                Flat No. 1441-42, Akansha Deep Heights, Kunhadi, Nanta, Kota – 324008 Raj. India
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
            <div><strong>Receipt No.:</strong> <?php echo $receipt_no; ?></div>
            <div><strong>Date:</strong> <?php echo date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])); ?></div>
        </div>
        
        <!-- Body -->
        <div class="receipt-body">
            <!-- Form Table -->
            <table class="form-table">
                <tr>
                    <td class="label">Cash received From</td>
                    <td class="value"><?php echo htmlspecialchars($transaction['student_name']); ?></td>
                </tr>
                <tr>
                    <td class="label">Mobile No.</td>
                    <td class="value"><?php echo htmlspecialchars($transaction['phone_number'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td class="label">For</td>
                    <td class="value"><?php echo htmlspecialchars($transaction['batch_name']); ?> (Batch ID: <?php echo htmlspecialchars($transaction['batch_id']); ?>)</td>
                </tr>
                <tr>
                    <td class="label">Payment Received in:</td>
                    <td class="value">
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <span class="checkbox" style="<?php echo $transaction['payment_mode'] === 'cash' ? 'background: #000;' : ''; ?>"></span> Cash
                            </div>
                            <div class="checkbox-item">
                                <span class="checkbox" style="<?php echo $transaction['payment_mode'] === 'cheque' ? 'background: #000;' : ''; ?>"></span> Cheque
                            </div>
                            <div class="checkbox-item">
                                <span class="checkbox" style="<?php echo $transaction['payment_mode'] === 'bank_transfer' ? 'background: #000;' : ''; ?>"></span> Bank Transfer
                            </div>
                            <div class="checkbox-item">
                                <span class="checkbox" style="<?php echo $transaction['payment_mode'] === 'e_wallet' ? 'background: #000;' : ''; ?>"></span> E-Wallet
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
                        <th>Amount (₹)</th>
                        <th>CGST %</th>
                        <th>SGST %</th>
                        <th>IGST %</th>
                        <th>Total (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Course Fee - <?php echo htmlspecialchars($transaction['batch_name']); ?></td>
                        <td><?php echo number_format($transaction['amount'], 2); ?></td>
                        <td><?php echo number_format($gst_percentage/2, 1); ?>%</td>
                        <td><?php echo number_format($gst_percentage/2, 1); ?>%</td>
                        <td>0%</td>
                        <td><?php echo number_format($total_with_gst, 2); ?></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="5" style="text-align: right;"><strong>Total Amount Due:</strong></td>
                        <td><strong>₹<?php echo number_format($total_with_gst, 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td colspan="5" style="text-align: right;">Amount Received:</td>
                        <td>₹<?php echo number_format($transaction['amount'], 2); ?></td>
                    </tr>
                    <tr>
                        <td colspan="5" style="text-align: right;">Balance Due:</td>
                        <td>₹<?php echo number_format($gst_amount, 2); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Amount in Words -->
            <div class="amount-in-words">
                <div class="label">Amount in Words:</div>
                <div><?php echo $amount_in_words; ?></div>
            </div>
            
            <!-- Signature Section -->
            <div class="signature-section">
                <div class="signature-box">
                    <div>Received By</div>
                    <div class="signature-line"></div>
                    <div style="font-size: 11px; margin-top: 5px;">
                        Authorized Signatory<br>
                        ASDN CYBERNETICS INC.
                    </div>
                </div>
                <div class="signature-box">
                    <div>Paid By</div>
                    <div class="signature-line"></div>
                    <div style="font-size: 11px; margin-top: 5px;">
                        <?php echo htmlspecialchars($transaction['student_name']); ?><br>
                        Student/Parent
                    </div>
                </div>
            </div>
            
            <!-- Footer Note -->
            <div class="footer-note">
                <strong>Note:</strong> The amount received once is not refundable in any case. Area of Jurisdiction will be Kota only.
                <br>Transaction verified by: <?php echo htmlspecialchars($transaction['verified_by_name']); ?> 
                on <?php echo date('d/m/Y H:i', strtotime($transaction['verified_at'])); ?>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-back" onclick="history.back()">
                ← Back
            </button>
            <button class="btn btn-print" onclick="printReceipt()">
                🖨️ Print Receipt
            </button>
            <button class="btn btn-download" onclick="downloadAsPDF()">
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
                margin:       10,
                filename:     'tax_invoice_<?php echo $receipt_no; ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(element).save();
        }
        
        // Auto-focus print on page load
        window.addEventListener('load', function() {
            if (window.location.search.includes('print=true')) {
                window.print();
            }
        });
    </script>
</body>
</html>