<?php
session_start();
require_once '../../db_connection.php';
require_once '../../vendor/autoload.php'; // If using TCPDF or similar

use TCPDF;

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    die('Unauthorized');
}

$application_id = $_GET['id'] ?? 0;

$query = $db->prepare("
    SELECT l.*, b.batch_name as batch_title
    FROM leave_applications l
    LEFT JOIN batches b ON l.batch_id = b.batch_id
    WHERE l.id = :id AND l.student_id = :student_id
");
$query->execute([
    ':id' => $application_id,
    ':student_id' => $_SESSION['student_id'] ?? ''
]);
$app = $query->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    die('Application not found');
}

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('ASD Academy');
$pdf->SetAuthor('ASD Academy');
$pdf->SetTitle('Leave Application - ' . $app['application_no']);
$pdf->SetSubject('Leave Application');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 12);

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'ASD Academy - Leave Application', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Application #: ' . $app['application_no'], 0, 1, 'C');
$pdf->Cell(0, 5, 'Date: ' . date('d M Y', strtotime($app['created_at'])), 0, 1, 'C');
$pdf->Ln(10);

// Student Info
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Student Information', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(40, 6, 'Name:', 0, 0);
$pdf->Cell(0, 6, $app['student_name'], 0, 1);
$pdf->Cell(40, 6, 'Student ID:', 0, 0);
$pdf->Cell(0, 6, $app['student_id'], 0, 1);
$pdf->Cell(40, 6, 'Batch:', 0, 0);
$pdf->Cell(0, 6, $app['batch_title'] . ' (' . $app['batch_id'] . ')', 0, 1);
$pdf->Ln(5);

// Leave Details
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Leave Details', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(40, 6, 'Start Date:', 0, 0);
$pdf->Cell(60, 6, date('d M Y', strtotime($app['start_date'])), 0, 1);
$pdf->Cell(40, 6, 'End Date:', 0, 0);
$pdf->Cell(60, 6, date('d M Y', strtotime($app['end_date'])), 0, 1);
$pdf->Cell(40, 6, 'Total Days:', 0, 0);
$pdf->Cell(60, 6, $app['total_days'] . ' day(s)', 0, 1);
$pdf->Cell(40, 6, 'Reason Category:', 0, 0);
$pdf->Cell(0, 6, $app['reason_category'], 0, 1);
$pdf->Cell(40, 6, 'Absence Type:', 0, 0);
$pdf->Cell(60, 6, $app['absence_type'], 0, 1);
$pdf->Cell(40, 6, 'Informed Academy:', 0, 0);
$pdf->Cell(60, 6, $app['informed_academy'], 0, 1);
$pdf->Ln(5);

// Reason Detail
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Detailed Reason', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 6, $app['reason_detail'], 0, 'L', false, 1);
$pdf->Ln(5);

// Status
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Application Status', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(40, 6, 'Status:', 0, 0);
$pdf->SetTextColor($app['status'] === 'approved' ? 0 : ($app['status'] === 'rejected' ? 255 : 0), 
                   $app['status'] === 'approved' ? 128 : 0, 0);
$pdf->Cell(0, 6, strtoupper($app['status']), 0, 1);
$pdf->SetTextColor(0, 0, 0);

// Output PDF
$pdf->Output('Leave_Application_' . $app['application_no'] . '.pdf', 'D');
?>