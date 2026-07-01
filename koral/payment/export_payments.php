<?php
// export_payments.php - Export payments data
include '../db_connection.php';
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once 'payment_functions.php';
$paymentDashboard = new PaymentDashboard($db);

// Get export format
$exportFormat = $_GET['export'] ?? 'pdf';

// Get filter parameters (same as dashboard)
$dateFilter = $_GET['date_filter'] ?? 'month';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$batchFilter = $_GET['batch'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$paymentModeFilter = $_GET['payment_mode'] ?? '';

// Get data for export
$stats = $paymentDashboard->getPaymentStatistics($dateFrom, $dateTo);
$paymentTrends = $paymentDashboard->getPaymentTrends('monthly', 12);
$paymentModes = $paymentDashboard->getPaymentModeSummary();
$paymentsByBatch = $paymentDashboard->getPaymentsByBatch();
$pendingInstallments = $paymentDashboard->getPendingInstallments();
$studentFeeOverview = $paymentDashboard->getStudentFeeOverview();
$topPayingStudents = $paymentDashboard->getTopPayingStudents();

// Set headers based on format
switch ($exportFormat) {
    case 'pdf':
        // For PDF, you would typically use a library like TCPDF or Dompdf
        // This is a simplified version
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="payments_report_' . date('Y-m-d') . '.pdf"');
        echo generatePDFContent();
        break;
        
    case 'excel':
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="payments_report_' . date('Y-m-d') . '.xls"');
        echo generateExcelContent();
        break;
        
    case 'csv':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="payments_report_' . date('Y-m-d') . '.csv"');
        echo generateCSVContent();
        break;
        
    default:
        header('Content-Type: application/json');
        echo json_encode([
            'stats' => $stats,
            'trends' => $paymentTrends,
            'modes' => $paymentModes,
            'batches' => $paymentsByBatch,
            'pending' => $pendingInstallments,
            'students' => $studentFeeOverview,
            'top_payers' => $topPayingStudents
        ]);
}

function generateExcelContent() {
    global $stats, $paymentTrends, $paymentModes, $paymentsByBatch, $pendingInstallments, $studentFeeOverview, $topPayingStudents;
    
    $output = "<html><head><style>td { padding: 5px; border: 1px solid #ccc; }</style></head><body>";
    
    // Summary Section
    $output .= "<h2>Payments Dashboard Report</h2>";
    $output .= "<table>";
    $output .= "<tr><td>Total Payments</td><td>₹" . number_format($stats['total_amount'], 2) . "</td><td>" . $stats['total_payments'] . " transactions</td></tr>";
    $output .= "<tr><td>Verified Payments</td><td>₹" . number_format($stats['verified_amount'], 2) . "</td><td>" . $stats['verified_payments'] . " transactions</td></tr>";
    $output .= "<tr><td>Pending Payments</td><td>₹" . number_format($stats['pending_amount'], 2) . "</td><td>" . $stats['pending_payments'] . " transactions</td></tr>";
    $output .= "<tr><td>Rejected Payments</td><td>₹" . number_format($stats['rejected_amount'], 2) . "</td><td>" . $stats['rejected_payments'] . " transactions</td></tr>";
    $output .= "</table><br>";
    
    // Payment Modes
    $output .= "<h3>Payment Methods Summary</h3>";
    $output .= "<table>";
    $output .= "<tr><th>Method</th><th>Count</th><th>Total Amount</th><th>Average Amount</th></tr>";
    foreach ($paymentModes as $mode) {
        $output .= "<tr>";
        $output .= "<td>" . ucfirst(str_replace('_', ' ', $mode['payment_mode'])) . "</td>";
        $output .= "<td>" . $mode['count'] . "</td>";
        $output .= "<td>₹" . number_format($mode['total_amount'], 2) . "</td>";
        $output .= "<td>₹" . number_format($mode['average_amount'], 2) . "</td>";
        $output .= "</tr>";
    }
    $output .= "</table><br>";
    
    // Top Paying Students
    $output .= "<h3>Top 10 Paying Students</h3>";
    $output .= "<table>";
    $output .= "<tr><th>Rank</th><th>Student Name</th><th>Student ID</th><th>Batch</th><th>Total Paid</th><th>Enrollment Fees</th><th>Progress</th></tr>";
    foreach ($topPayingStudents as $index => $student) {
        $progress = $student['enrollment_fees'] > 0 ? ($student['total_fees_paid'] / $student['enrollment_fees'] * 100) : 0;
        $output .= "<tr>";
        $output .= "<td>" . ($index + 1) . "</td>";
        $output .= "<td>" . htmlspecialchars($student['student_name']) . "</td>";
        $output .= "<td>" . htmlspecialchars($student['student_id']) . "</td>";
        $output .= "<td>" . htmlspecialchars($student['batch_full_name']) . "</td>";
        $output .= "<td>₹" . number_format($student['total_fees_paid'], 2) . "</td>";
        $output .= "<td>₹" . number_format($student['enrollment_fees'], 2) . "</td>";
        $output .= "<td>" . number_format($progress, 1) . "%</td>";
        $output .= "</tr>";
    }
    $output .= "</table>";
    
    $output .= "</body></html>";
    
    return $output;
}

function generateCSVContent() {
    global $stats, $paymentTrends, $paymentModes, $paymentsByBatch, $pendingInstallments, $studentFeeOverview, $topPayingStudents;
    
    $output = "";
    
    // Summary
    $output .= "Payments Dashboard Report\n\n";
    $output .= "Summary Statistics\n";
    $output .= "Total Payments,₹" . number_format($stats['total_amount'], 2) . "," . $stats['total_payments'] . " transactions\n";
    $output .= "Verified Payments,₹" . number_format($stats['verified_amount'], 2) . "," . $stats['verified_payments'] . " transactions\n";
    $output .= "Pending Payments,₹" . number_format($stats['pending_amount'], 2) . "," . $stats['pending_payments'] . " transactions\n";
    $output .= "Rejected Payments,₹" . number_format($stats['rejected_amount'], 2) . "," . $stats['rejected_payments'] . " transactions\n\n";
    
    // Payment Modes
    $output .= "Payment Methods\n";
    $output .= "Method,Count,Total Amount,Average Amount\n";
    foreach ($paymentModes as $mode) {
        $output .= ucfirst(str_replace('_', ' ', $mode['payment_mode'])) . ",";
        $output .= $mode['count'] . ",";
        $output .= "₹" . number_format($mode['total_amount'], 2) . ",";
        $output .= "₹" . number_format($mode['average_amount'], 2) . "\n";
    }
    $output .= "\n";
    
    // Top Paying Students
    $output .= "Top 10 Paying Students\n";
    $output .= "Rank,Student Name,Student ID,Batch,Total Paid,Enrollment Fees,Progress\n";
    foreach ($topPayingStudents as $index => $student) {
        $progress = $student['enrollment_fees'] > 0 ? ($student['total_fees_paid'] / $student['enrollment_fees'] * 100) : 0;
        $output .= ($index + 1) . ",";
        $output .= '"' . $student['student_name'] . '",';
        $output .= $student['student_id'] . ",";
        $output .= '"' . $student['batch_full_name'] . '",';
        $output .= "₹" . number_format($student['total_fees_paid'], 2) . ",";
        $output .= "₹" . number_format($student['enrollment_fees'], 2) . ",";
        $output .= number_format($progress, 1) . "%\n";
    }
    
    return $output;
}

function generatePDFContent() {
    // This would require a PDF library like TCPDF
    // For now, return simple HTML that can be converted to PDF
    return generateExcelContent();
}
?>