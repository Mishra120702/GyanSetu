<?php
ob_start();
session_start();
require_once '../../db_connection.php';
require_once '../../vendor/autoload.php';

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    die('Unauthorized');
}

// Validate application ID
$application_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$application_id) {
    die('Invalid application ID');
}

// Fetch the real student_id from the DB using the session user_id
$student_query = $db->prepare("SELECT student_id FROM students WHERE user_id = :user_id");
$student_query->execute([':user_id' => $_SESSION['user_id']]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student record not found');
}

// Fetch the leave application (scoped to this student for security)
$query = $db->prepare("
    SELECT l.*, b.batch_name as batch_title
    FROM leave_applications l
    LEFT JOIN batches b ON l.batch_id = b.batch_id
    WHERE l.id = :id AND l.student_id = :student_id
");
$query->execute([
    ':id'         => $application_id,
    ':student_id' => $student['student_id'],
]);
$app = $query->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    die('Application not found');
}

// ─── Colour palette (using the provided brand colours) ───────────────────────
// Brand colours:
//   #1B3C53 -> RGB(27, 60, 83)   – dark navy
//   #234C6A -> RGB(35, 76, 106)  – medium navy
//   #456882 -> RGB(69, 104, 130) – muted blue
//   #D2C1B6 -> RGB(210,193,182)  – warm beige
// Extended neutrals:
//   #E8E0D9 -> RGB(232,224,217)  – light beige
//   #F5F0EB -> RGB(245,240,235)  – very light beige
//   #EDE8E2 -> RGB(237,232,226)  – soft beige

// Header gradient: left dark navy → right muted blue
$c_header1 = [27, 60, 83];    // #1B3C53
$c_header2 = [69, 104, 130];  // #456882

// Section colour schemes (background, border, title text)
$sec_student = [
    'bg'    => [245, 240, 235], // #F5F0EB
    'bd'    => [210, 193, 182], // #D2C1B6
    'title' => [27, 60, 83],    // #1B3C53
];
$sec_leave = [
    'bg'    => [237, 232, 226], // #EDE8E2
    'bd'    => [210, 193, 182], // #D2C1B6
    'title' => [35, 76, 106],   // #234C6A
];
$sec_reason = [
    'bg'    => [232, 224, 217], // #E8E0D9
    'bd'    => [210, 193, 182], // #D2C1B6
    'title' => [69, 104, 130],  // #456882
];
$sec_reflection = [
    'bg'    => [245, 240, 235], // #F5F0EB
    'bd'    => [210, 193, 182], // #D2C1B6
    'title' => [27, 60, 83],    // #1B3C53
];

// Status colours (preserved – green/red/amber for clarity)
$status = strtolower($app['status']);
if ($status === 'approved') {
    $c_st_bg   = [236, 253, 245]; // #ecfdf5
    $c_st_bd   = [167, 243, 208]; // #a7f3d0
    $c_st_dot  = [16,  185, 129]; // #10b981
    $c_st_text = [6,   78,  59];  // #064e3b
} elseif ($status === 'rejected') {
    $c_st_bg   = [254, 242, 242]; // #fef2f2
    $c_st_bd   = [254, 202, 202]; // #fecaca
    $c_st_dot  = [239,  68,  68]; // #ef4444
    $c_st_text = [153,  27,  27]; // #991b1b
} else {
    $c_st_bg   = [255, 251, 235]; // #fffbeb
    $c_st_bd   = [253, 230, 138]; // #fde68a
    $c_st_dot  = [245, 158,  11]; // #f59e0b
    $c_st_text = [146,  64,  14]; // #92400e
}

// ─── Custom TCPDF class with header & footer ──────────────────────────────────
class LeavePDF extends TCPDF {
    public $appData;

    public function Header() {
        // Gradient header bar: left dark navy → right muted blue (two rectangles)
        $this->SetFillColor(27, 60, 83);   // #1B3C53
        $this->Rect(0, 0, 140, 36, 'F');
        $this->SetFillColor(69, 104, 130); // #456882
        $this->Rect(140, 0, 70, 36, 'F');

        // Subtle white circle decoration (top-right)
        $this->SetFillColor(255, 255, 255);
        $this->SetAlpha(0.05);
        $this->Circle(190, 2, 30, 0, 360, 'F');
        $this->Circle(208, 20, 16, 0, 360, 'F');
        $this->SetAlpha(1);

        // Academy name
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetXY(12, 7);
        $this->Cell(0, 7, 'ASD Academy', 0, 1, 'L');

        // Subtitle
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(210, 193, 182); // #D2C1B6 – warm beige
        $this->SetX(12);
        $this->Cell(0, 5, 'Student Leave Application', 0, 1, 'L');

        // App no & date
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(210, 193, 182); // #D2C1B6
        $this->SetX(12);
        $this->Cell(0, 5,
            'Ref: ' . $this->appData['application_no'] .
            '   |   Submitted: ' . date('d M Y', strtotime($this->appData['created_at'])),
            0, 1, 'L');

        $this->SetTextColor(17, 24, 39);
    }

    public function Footer() {
        $this->SetY(-15);
        // Light border line
        $this->SetDrawColor(229, 231, 235);
        $this->SetLineWidth(0.3);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(2);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(107, 114, 128); // gray-500
        $this->Cell(0, 5, 'ASD Academy  |  Confidential  |  Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'L');
        $this->Cell(0, 5, 'Generated: ' . date('d M Y, h:i A'), 0, 0, 'R');
        $this->SetTextColor(17, 24, 39);
    }
}

// ─── Init PDF ─────────────────────────────────────────────────────────────────
$pdf = new LeavePDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->appData = $app;
$pdf->SetCreator('ASD Academy');
$pdf->SetAuthor('ASD Academy');
$pdf->SetTitle('Leave Application - ' . $app['application_no']);
$pdf->SetSubject('Leave Application');
$pdf->SetMargins(12, 46, 12);
$pdf->SetFooterMargin(16);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetAutoPageBreak(true, 22);
$pdf->AddPage();

// ─── Helper: section card header ─────────────────────────────────────────────
function secHead($pdf, $title, $bg, $bd, $titleColor) {
    $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
    $pdf->SetDrawColor($bd[0], $bd[1], $bd[2]);
    $pdf->SetTextColor($titleColor[0], $titleColor[1], $titleColor[2]);
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetLineWidth(0.3);
    $pdf->Cell(0, 9, '   ' . $title, 1, 1, 'L', true);
    $pdf->SetDrawColor(229, 231, 235);
    $pdf->SetLineWidth(0.2);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Ln(1);
}

// ─── Helper: label + value row ────────────────────────────────────────────────
function infoRow($pdf, $label, $value, $shade = false) {
    if ($shade) {
        $pdf->SetFillColor(250, 250, 250); // #fafafa
    } else {
        $pdf->SetFillColor(255, 255, 255);
    }
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(52, 7, $label, 0, 0, 'L', true);
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 7, $value, 0, 1, 'L', true);
}

// ═══════════════════════════════════════════════════════════════════════════════
// STATUS BADGE
// ═══════════════════════════════════════════════════════════════════════════════
$pdf->Ln(3);
$yBadge = $pdf->GetY();
$pdf->SetFillColor($c_st_bg[0], $c_st_bg[1], $c_st_bg[2]);
$pdf->SetDrawColor($c_st_bd[0], $c_st_bd[1], $c_st_bd[2]);
$pdf->SetLineWidth(0.3);
$pdf->RoundedRect(12, $yBadge, 186, 11, 2.5, '1111', 'DF');

// Status dot
$pdf->SetFillColor($c_st_dot[0], $c_st_dot[1], $c_st_dot[2]);
$pdf->Circle(20, $yBadge + 5.5, 2, 0, 360, 'F');

// Status text
$pdf->SetTextColor($c_st_text[0], $c_st_text[1], $c_st_text[2]);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(25, $yBadge);
$pdf->Cell(0, 11, 'Status: ' . strtoupper($status), 0, 1, 'L');
$pdf->SetTextColor(17, 24, 39);
$pdf->SetDrawColor(229, 231, 235);
$pdf->SetLineWidth(0.2);
$pdf->Ln(5);

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 1 — Student Information  (uses $sec_student)
// ═══════════════════════════════════════════════════════════════════════════════
secHead($pdf, 'Student Information', $sec_student['bg'], $sec_student['bd'], $sec_student['title']);
infoRow($pdf, 'Full Name',  $app['student_name'],  false);
infoRow($pdf, 'Student ID', $app['student_id'],    true);
infoRow($pdf, 'Email',      $app['email'] ?? 'N/A', false);
infoRow($pdf, 'Batch',      ($app['batch_title'] ?? '') . ' (' . $app['batch_id'] . ')', true);
$pdf->Ln(5);

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 2 — Leave Details  (uses $sec_leave)
// ═══════════════════════════════════════════════════════════════════════════════
secHead($pdf, 'Leave Details', $sec_leave['bg'], $sec_leave['bd'], $sec_leave['title']);

// Two-column date display
$yDates = $pdf->GetY();
$pdf->SetFillColor($sec_leave['bg'][0], $sec_leave['bg'][1], $sec_leave['bg'][2]);

// Left: Start Date
$pdf->SetXY(12, $yDates);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(107, 114, 128);
$pdf->Cell(93, 5, 'Start Date', 0, 0, 'L', true);
$pdf->SetXY(12, $yDates + 5);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor($sec_leave['title'][0], $sec_leave['title'][1], $sec_leave['title'][2]);
$pdf->Cell(93, 8, date('d M Y', strtotime($app['start_date'])), 0, 0, 'L', true);

// Right: End Date
$pdf->SetXY(105, $yDates);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(107, 114, 128);
$pdf->Cell(93, 5, 'End Date', 0, 0, 'L', true);
$pdf->SetXY(105, $yDates + 5);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor($sec_leave['title'][0], $sec_leave['title'][1], $sec_leave['title'][2]);
$pdf->Cell(93, 8, date('d M Y', strtotime($app['end_date'])), 0, 1, 'L', true);

$pdf->Ln(2);

// Total days — light pill with leave section colours
$pdf->SetFillColor($sec_leave['bg'][0], $sec_leave['bg'][1], $sec_leave['bg'][2]);
$pdf->SetDrawColor($sec_leave['bd'][0], $sec_leave['bd'][1], $sec_leave['bd'][2]);
$pdf->SetLineWidth(0.3);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor($sec_leave['title'][0], $sec_leave['title'][1], $sec_leave['title'][2]);
$pdf->Cell(0, 8, '   Total Duration: ' . $app['total_days'] . ' Day(s)', 1, 1, 'L', true);
$pdf->SetDrawColor(229, 231, 235);
$pdf->SetLineWidth(0.2);
$pdf->SetTextColor(17, 24, 39);
$pdf->Ln(2);

infoRow($pdf, 'Reason Category',  $app['reason_category'],  false);
infoRow($pdf, 'Absence Type',     $app['absence_type'],     true);
infoRow($pdf, 'Informed Academy', $app['informed_academy'], false);
$pdf->Ln(5);

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 3 — Detailed Reason  (uses $sec_reason)
// ═══════════════════════════════════════════════════════════════════════════════
secHead($pdf, 'Detailed Reason', $sec_reason['bg'], $sec_reason['bd'], $sec_reason['title']);

$pdf->SetFillColor($sec_reason['bg'][0], $sec_reason['bg'][1], $sec_reason['bg'][2]);
$pdf->SetDrawColor($sec_reason['bd'][0], $sec_reason['bd'][1], $sec_reason['bd'][2]);
$pdf->SetLineWidth(0.3);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor($sec_reason['title'][0], $sec_reason['title'][1], $sec_reason['title'][2]);
$pdf->MultiCell(0, 6, $app['reason_detail'], 1, 'L', true, 1);
$pdf->SetDrawColor(229, 231, 235);
$pdf->SetLineWidth(0.2);
$pdf->SetTextColor(17, 24, 39);
$pdf->Ln(5);

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 4 — Reflection & Commitment  (uses $sec_reflection, only if data exists)
// ═══════════════════════════════════════════════════════════════════════════════
$reflection_fields = [
    'Course Importance'   => $app['course_importance']   ?? '',
    'Content Value'       => $app['content_value']       ?? '',
    'Topic Understanding' => $app['topic_understanding'] ?? '',
    'Practical Ability'   => $app['practical_ability']   ?? '',
    'Unique Learning'     => $app['unique_learning']     ?? '',
    'Loss Reflection'     => $app['loss_reflection']     ?? '',
    'Future Commitment'   => $app['future_commitment']   ?? '',
];

if (array_filter($reflection_fields)) {
    secHead($pdf, 'Student Reflection & Commitment', $sec_reflection['bg'], $sec_reflection['bd'], $sec_reflection['title']);
    $shade = false;
    foreach ($reflection_fields as $label => $value) {
        if (!empty($value)) {
            infoRow($pdf, $label, $value, $shade);
            $shade = !$shade;
        }
    }
    $pdf->Ln(5);
}

// ═══════════════════════════════════════════════════════════════════════════════
// DECLARATION BOX  (using neutral beige palette)
// ═══════════════════════════════════════════════════════════════════════════════
$pdf->SetFillColor(245, 240, 235);   // #F5F0EB
$pdf->SetDrawColor(210, 193, 182);   // #D2C1B6
$pdf->SetLineWidth(0.3);
$pdf->RoundedRect(12, $pdf->GetY(), 186, 16, 2.5, '1111', 'DF');
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(27, 60, 83);      // #1B3C53
$pdf->SetX(16);
$pdf->MultiCell(178, 5.5,
    'I hereby declare that the information provided in this leave application is true and accurate. ' .
    'I accept full responsibility for any academic content missed during my absence.',
    0, 'L', false, 1);

$pdf->SetTextColor(17, 24, 39);
$pdf->SetDrawColor(229, 231, 235);
$pdf->SetLineWidth(0.2);

// ─── Output ───────────────────────────────────────────────────────────────────
ob_end_clean();
$pdf->Output('Leave_Application_' . $app['application_no'] . '.pdf', 'D');
?>