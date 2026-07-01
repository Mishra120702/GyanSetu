<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Sanitize filter parameters
$batch_filter = isset($_GET['batch']) ? filter_var($_GET['batch'], FILTER_SANITIZE_STRING) : '';
$date_from = isset($_GET['date_from']) ? filter_var($_GET['date_from'], FILTER_SANITIZE_STRING) : '';
$date_to = isset($_GET['date_to']) ? filter_var($_GET['date_to'], FILTER_SANITIZE_STRING) : '';
$rating_min = isset($_GET['rating_min']) ? filter_var($_GET['rating_min'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) : '';
$rating_max = isset($_GET['rating_max']) ? filter_var($_GET['rating_max'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) : '';

// Build query with filters
$query = "SELECT 
            date, 
            student_name, 
            email, 
            batch_id, 
            is_regular, 
            batch_name, 
            class_rating, 
            assignment_understanding, 
            practical_understanding, 
            satisfied, 
            suggestions, 
            feedback_text, 
            action_taken
          FROM feedback WHERE 1=1";
$params = [];

if (!empty($batch_filter)) {
    $query .= " AND batch_id = :batch_id";
    $params[':batch_id'] = $batch_filter;
}

if (!empty($date_from)) {
    $query .= " AND date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND date <= :date_to";
    $params[':date_to'] = $date_to;
}

if (!empty($rating_min)) {
    $query .= " AND class_rating >= :rating_min";
    $params[':rating_min'] = $rating_min;
}

if (!empty($rating_max)) {
    $query .= " AND class_rating <= :rating_max";
    $params[':rating_max'] = $rating_max;
}

$query .= " ORDER BY date DESC, student_name";

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Determine export type
    $export_type = isset($_GET['export']) ? strtolower(filter_var($_GET['export'], FILTER_SANITIZE_STRING)) : 'csv';
    
    switch ($export_type) {
        case 'excel':
            exportExcel($feedback);
            break;
        case 'pdf':
            exportPDF($feedback);
            break;
        case 'csv':
        default:
            exportCSV($feedback);
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header("Location: feedback.php?error=export_failed");
    exit;
}

function exportCSV($data) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="feedback_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Header row
    fputcsv($output, [
        'Date', 'Student Name', 'Email', 'Batch ID', 'Regular', 
        'Course', 'Class Rating', 'Assignment', 'Practical', 
        'Satisfied', 'Suggestions', 'Feedback', 'Action Taken'
    ]);
    
    // Data rows
    foreach ($data as $row) {
        fputcsv($output, [
            $row['date'],
            $row['student_name'],
            $row['email'],
            $row['batch_id'],
            $row['is_regular'],
            $row['batch_name'],
            $row['class_rating'],
            $row['assignment_understanding'],
            $row['practical_understanding'],
            $row['satisfied'],
            $row['suggestions'],
            $row['feedback_text'],
            $row['action_taken'] ?? ''
        ]);
    }
    
    fclose($output);
    exit();
}

function exportExcel($data) {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="feedback_export_' . date('Y-m-d') . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    echo '<x:Name>Feedback Data</x:Name>';
    echo '<x:WorksheetOptions>';
    echo '<x:DisplayGridlines/>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '</head>';
    echo '<body>';
    
    echo '<table border="1">';
    echo '<tr>';
    echo '<th>Date</th>';
    echo '<th>Student Name</th>';
    echo '<th>Email</th>';
    echo '<th>Batch ID</th>';
    echo '<th>Regular</th>';
    echo '<th>Course</th>';
    echo '<th>Class Rating</th>';
    echo '<th>Assignment</th>';
    echo '<th>Practical</th>';
    echo '<th>Satisfied</th>';
    echo '<th>Suggestions</th>';
    echo '<th>Feedback</th>';
    echo '<th>Action Taken</th>';
    echo '</tr>';
    
    foreach ($data as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['date'], ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars($row['student_name'], ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars($row['email'], ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars($row['batch_id'], ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars($row['is_regular'], ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars($row['batch_name'], ENT_QUOTES) . '</td>';
        echo '<td>' . $row['class_rating'] . '</td>';
        echo '<td>' . $row['assignment_understanding'] . '</td>';
        echo '<td>' . $row['practical_understanding'] . '</td>';
        echo '<td>' . htmlspecialchars($row['satisfied'], ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars($row['suggestions'], ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars($row['feedback_text'], ENT_QUOTES) . '</td>';
        echo '<td>' . htmlspecialchars($row['action_taken'] ?? '', ENT_QUOTES) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit();
}

function exportPDF($data) {
    if (!class_exists('TCPDF')) {
        require_once('../tcpdf/tcpdf.php');
    }
    
    try {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('ASD Academy');
        $pdf->SetTitle('Feedback Report');
        $pdf->SetSubject('Feedback Data');
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Feedback Report', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        
        // Add date and filter info
        $pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 8);
        $header = ['Date', 'Student', 'Batch', 'Course', 'Class', 'Assign', 'Pract', 'Action'];
        $w = [20, 30, 15, 30, 10, 10, 10, 40];
        
        for ($i = 0; $i < count($header); $i++) {
            $pdf->Cell($w[$i], 7, $header[$i], 1, 0, 'C');
        }
        $pdf->Ln();
        
        // Table data
        $pdf->SetFont('helvetica', '', 8);
        foreach ($data as $row) {
            $pdf->Cell($w[0], 6, $row['date'], 'LR', 0, 'C');
            $pdf->Cell($w[1], 6, substr($row['student_name'], 0, 20), 'LR', 0, 'L');
            $pdf->Cell($w[2], 6, $row['batch_id'], 'LR', 0, 'C');
            $pdf->Cell($w[3], 6, substr($row['batch_name'], 0, 20), 'LR', 0, 'L');
            $pdf->Cell($w[4], 6, $row['class_rating'], 'LR', 0, 'C');
            $pdf->Cell($w[5], 6, $row['assignment_understanding'], 'LR', 0, 'C');
            $pdf->Cell($w[6], 6, $row['practical_understanding'], 'LR', 0, 'C');
            $pdf->Cell($w[7], 6, substr($row['action_taken'] ?? '', 0, 30), 'LR', 0, 'L');
            $pdf->Cell();
        }
        
        $pdf->Output('feedback_export_' . date('Y-m-d') . '.pdf', 'D');
        exit();
    } catch (Exception $e) {
        error_log("PDF generation error: " . $e->getMessage());
        header("Location: feedback.php?error=pdf_failed");
        exit;
    }
}