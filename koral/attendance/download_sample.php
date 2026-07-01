<?php
require '../vendor/autoload.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set headers
$sheet->setCellValue('A1', 'Date');
$sheet->setCellValue('B1', 'Batch ID');
$sheet->setCellValue('C1', 'Student Name');
$sheet->setCellValue('D1', 'Status');
$sheet->setCellValue('E1', 'Camera Status');
$sheet->setCellValue('F1', 'Remarks');

// Add sample data
$sheet->setCellValue('A2', '2025-07-01');
$sheet->setCellValue('B2', 'B001');
$sheet->setCellValue('C2', 'Alice Williams');
$sheet->setCellValue('D2', 'Present');
$sheet->setCellValue('E2', 'On');
$sheet->setCellValue('F2', 'Attended on time');

// Set column widths
$sheet->getColumnDimension('A')->setWidth(15);
$sheet->getColumnDimension('B')->setWidth(10);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(15);
$sheet->getColumnDimension('F')->setWidth(30);

// Output the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="attendance_template.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;