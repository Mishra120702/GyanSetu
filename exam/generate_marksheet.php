<?php
// generate_marksheet.php - Clean light theme with premium color palette

require_once '../db_connection.php';
session_start();

// Check user role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Get parameters
$student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$marksheet_type = isset($_GET['type']) ? trim($_GET['type']) : 'batch';

$student_info = null;
$results = [];
$batches = [];
$exams = [];
$overall_total = 0;
$overall_obtained = 0;
$error_message = '';

// Get all batches for dropdown
try {
    $sql = "SELECT b.*, c.name as course_name 
            FROM batches b 
            LEFT JOIN courses c ON b.course_id = c.id 
            WHERE b.status = 'active' 
            ORDER BY b.created_at DESC";
    $stmt = $db->query($sql);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error loading batches: " . $e->getMessage();
}

// If batch is selected, get its exams
if ($batch_id > 0) {
    try {
        $sql = "SELECT * FROM exams WHERE batch_id = ? ORDER BY exam_date DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$batch_id]);
        $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Error loading exams: " . $e->getMessage();
    }
}

// Fetch data based on marksheet type
if (!empty($student_id)) {
    try {
        // Get student information
        $sql = "SELECT s.*, b.batch_name, b.batch_id, c.name as course_name 
                FROM students s 
                LEFT JOIN batches b ON s.batch_name = b.batch_id 
                LEFT JOIN courses c ON s.course = c.id 
                WHERE s.student_id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$student_id]);
        $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student_info) {
            // Build query based on marksheet type
            $whereClause = "WHERE er.student_id = ?";
            $params = [$student_id];
            
            if ($marksheet_type == 'batch' && $batch_id > 0) {
                $whereClause .= " AND e.batch_id = ?";
                $params[] = $batch_id;
            } elseif ($marksheet_type == 'exam' && $exam_id > 0) {
                $whereClause .= " AND e.exam_id = ?";
                $params[] = $exam_id;
            }
            
            $sql = "SELECT er.*, e.exam_name, e.exam_type, e.exam_date, e.subject,
                           e.total_marks, e.passing_marks, b.batch_name
                    FROM exam_results er 
                    JOIN exams e ON er.exam_id = e.exam_id 
                    JOIN batches b ON e.batch_id = b.batch_id 
                    JOIN students s ON er.student_id = s.student_id
                    $whereClause
                    ORDER BY e.exam_date ASC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate overall marks
            foreach ($results as $result) {
                $overall_total += $result['total_marks'];
                $overall_obtained += $result['obtained_marks'];
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

$overall_percentage = ($overall_total > 0) ? ($overall_obtained / $overall_total) * 100 : 0;
$result_status = $overall_percentage >= 40 ? 'PASS' : 'FAIL';

function getGrade($percentage) {
    if ($percentage >= 90) return 'A+';
    elseif ($percentage >= 75) return 'A';
    elseif ($percentage >= 60) return 'B';
    elseif ($percentage >= 40) return 'C';
    else return 'F';
}

function getGradeColor($grade) {
    $colors = [
        'A+' => '#10B981',
        'A' => '#1B3C53',
        'B' => '#234C6A',
        'C' => '#456882',
        'F' => '#EF4444'
    ];
    return $colors[$grade] ?? '#6B7280';
}

function generateVerificationId() {
    return 'ASD-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
}

$verification_id = generateVerificationId();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marksheet Generator | ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        /* ================================================================
           CLEAN LIGHT THEME - PREMIUM COLOR PALETTE
           Primary: #1B3C53 | Secondary: #234C6A | Accent: #456882 | Neutral: #D2C1B6
           ================================================================ */
        :root {
            --primary: #1B3C53;
            --secondary: #234C6A;
            --accent: #456882;
            --neutral: #D2C1B6;
            --body-bg: #f5f2ef;
            --card-bg: #ffffff;
            --border-color: #e5e0da;
            --text-primary: #1a1a1a;
            --text-secondary: #2d3748;
            --text-muted: #5a6c7d;
            --input-bg: #ffffff;
            --input-border: #d9d2cb;
            --shadow: 0 4px 20px rgba(27, 60, 83, 0.08);
            --shadow-light: 0 2px 10px rgba(27, 60, 83, 0.05);
            --shadow-hover: 0 8px 30px rgba(27, 60, 83, 0.12);
            --gradient-primary: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --gradient-success: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --gradient-warning: linear-gradient(135deg, #456882 0%, #1B3C53 100%);
            --gradient-danger: linear-gradient(135deg, #c0392b 0%, #e74c3c 100%);
            --hover-bg: rgba(27, 60, 83, 0.04);
            --stat-number-color: #1B3C53;
            --table-row-bg: #ffffff;
            --border-color-hover: #c4bbb2;
            --badge-bg: #f0ede8;
            --badge-text: #1B3C53;
            --success: #234C6A;
            --warning: #456882;
            --danger: #c0392b;
            --sidebar-bg: #1B3C53;
            --sidebar-text: #D2C1B6;
            --sidebar-hover: rgba(210, 193, 182, 0.12);
            --sidebar-active: rgba(210, 193, 182, 0.2);
        }

        /* ================================================================
           BASE STYLES
           ================================================================ */
        * {
            transition: background-color 0.25s ease, color 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
        
        body {
            background: var(--body-bg);
            color: var(--text-primary);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        .main-content {
            margin-left: 16rem;
            padding: 28px 32px;
            animation: fadeIn 0.5s ease-out;
            min-height: 100vh;
            background: transparent;
        }
        
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 16px; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ================================================================
           CARDS
           ================================================================ */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            transition: all 0.3s ease;
            overflow: hidden;
            color: var(--text-primary);
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            border-color: var(--border-color-hover);
        }
        
        .card-header {
            background: linear-gradient(to bottom, #fafaf9, #ffffff);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1.05rem;
            color: var(--primary);
            letter-spacing: -0.01em;
        }
        
        .card-body { padding: 1.5rem; }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 1.5rem 1.75rem;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-light);
            cursor: default;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
            border-radius: 4px 0 0 4px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }
        
        .stat-card .h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--stat-number-color);
            margin-bottom: 0.25rem;
            letter-spacing: -0.02em;
        }
        
        .stat-card .text-muted {
            color: var(--text-muted) !important;
            font-size: 0.82rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ================================================================
           BUTTONS
           ================================================================ */
        .btn {
            border-radius: 10px;
            padding: 0.6rem 1.4rem;
            font-weight: 500;
            transition: all 0.25s ease;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.93rem;
            border: 1px solid transparent;
            letter-spacing: -0.01em;
        }
        
        .btn-sm {
            height: 36px;
            padding: 0 1rem;
            font-size: 0.85rem;
            border-radius: 8px;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: #fff;
            border: none;
            box-shadow: 0 4px 14px rgba(27, 60, 83, 0.25);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(27, 60, 83, 0.35);
            color: #fff;
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
        }
        
        .btn-success {
            background: var(--gradient-success);
            color: #fff;
            border: none;
            box-shadow: 0 4px 14px rgba(35, 76, 106, 0.25);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(35, 76, 106, 0.35);
            color: #fff;
        }
        
        .btn-outline-secondary {
            border-color: var(--secondary);
            color: var(--secondary);
            background: transparent;
        }
        
        .btn-outline-secondary:hover {
            background: var(--secondary);
            color: #fff;
            border-color: var(--secondary);
        }

        /* ================================================================
           FORM CONTROLS
           ================================================================ */
        .form-control, .form-select {
            background: var(--input-bg);
            border: 1.5px solid var(--input-border);
            border-radius: 10px;
            padding: 0.6rem 1rem;
            transition: all 0.25s ease;
            font-size: 0.93rem;
            color: var(--text-primary);
            height: 44px;
        }
        
        .form-control:focus, .form-select:focus {
            background: var(--input-bg);
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(27, 60, 83, 0.1);
            color: var(--text-primary);
            outline: none;
        }
        
        .form-control::placeholder {
            color: #a0a8b0;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.88rem;
            margin-bottom: 0.4rem;
            letter-spacing: -0.01em;
        }

        /* ================================================================
           TABLES
           ================================================================ */
        .table {
            --bs-table-bg: transparent;
            border-collapse: separate;
            border-spacing: 0 6px;
            color: var(--text-primary);
        }
        
        .table th {
            border: none;
            font-weight: 600;
            color: var(--primary);
            padding: 0.85rem 1rem;
            background: rgba(27, 60, 83, 0.04);
            text-transform: uppercase;
            font-size: 0.73rem;
            letter-spacing: 0.05em;
            border-radius: 8px 8px 0 0;
        }
        
        .table tbody tr {
            background: var(--table-row-bg);
            border-radius: 12px;
            transition: all 0.25s ease;
            box-shadow: var(--shadow-light);
        }
        
        .table tbody tr:hover {
            transform: translateX(3px);
            box-shadow: var(--shadow);
            background: var(--hover-bg);
        }
        
        .table td {
            padding: 1rem 1rem;
            vertical-align: middle;
            border: none;
            color: var(--text-primary);
        }
        
        .table td:first-child { border-radius: 10px 0 0 10px; }
        .table td:last-child { border-radius: 0 10px 10px 0; }

        /* ================================================================
           GRADE BADGES
           ================================================================ */
        .grade-badge {
            padding: 0.3rem 0.85rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.75rem;
            letter-spacing: 0.03em;
        }
        .grade-a-plus { background: #10b981; color: white; }
        .grade-a { background: #1B3C53; color: white; }
        .grade-b { background: #234C6A; color: white; }
        .grade-c { background: #456882; color: white; }
        .grade-f { background: #c0392b; color: white; }

        /* ================================================================
           PAGE SPECIFIC STYLES
           ================================================================ */
        .page-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.2rem;
            letter-spacing: -0.03em;
        }
        
        .search-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            transition: background 0.3s ease, border-color 0.3s ease;
        }
        
        .search-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
        }
        
        .type-selector {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .type-btn {
            flex: 1;
            padding: 0.75rem 1.25rem;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            background: var(--card-bg);
            color: var(--text-secondary);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            min-width: 100px;
            font-size: 0.88rem;
        }
        
        .type-btn:hover { 
            border-color: var(--accent); 
            transform: translateY(-2px);
            background: rgba(27, 60, 83, 0.02);
        }
        
        .type-btn.active {
            border-color: var(--primary);
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 18px rgba(27, 60, 83, 0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .premium-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .premium-table th {
            background: rgba(27, 60, 83, 0.05);
            color: var(--primary);
            font-weight: 700;
            padding: 1rem 0.75rem;
            text-align: left;
            border-bottom: 2px solid var(--secondary);
            font-size: 0.73rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        
        .premium-table td {
            padding: 1rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.9rem;
        }
        
        .premium-table tfoot tr {
            background: rgba(27, 60, 83, 0.04);
            font-weight: 700;
        }
        
        .premium-table tfoot td {
            border-top: 2px solid var(--primary);
        }
        
        .preview-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-top: 1.5rem;
            transition: background 0.3s ease;
        }

        /* ================================================================
           HEADER & BREADCRUMB
           ================================================================ */
        .breadcrumb-link {
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s ease;
        }
        
        .breadcrumb-link:hover {
            color: var(--primary);
        }

        /* ================================================================
           MARKSHEET PDF STYLES (hidden by default)
           ================================================================ */
        #marksheetContent {
            display: none;
            background: #ffffff;
            padding: 15mm 12mm 15mm 12mm;
            width: 210mm;
            min-height: 297mm;
            position: relative;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #1E293B;
        }
        
        #marksheetContent .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 70px;
            font-weight: 900;
            color: rgba(27, 60, 83, 0.03);
            pointer-events: none;
            z-index: 0;
            letter-spacing: 12px;
            text-transform: uppercase;
        }
        
        #marksheetContent .pdf-header {
            text-align: center;
            border-bottom: 3px solid #1B3C53;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        
        #marksheetContent .pdf-header-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        #marksheetContent .pdf-logo {
            width: 60px;
            height: 60px;
            background: #1B3C53;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            font-weight: 900;
            flex-shrink: 0;
        }
        
        #marksheetContent .pdf-institute-name {
            font-size: 18px;
            font-weight: 800;
            color: #1B3C53;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        
        #marksheetContent .pdf-sub-title {
            font-size: 12px;
            font-weight: 600;
            color: #234C6A;
            letter-spacing: 1px;
        }
        
        #marksheetContent .pdf-doc-title {
            font-size: 14px;
            font-weight: 700;
            color: #1E293B;
            margin-top: 2px;
            letter-spacing: 1px;
        }
        
        #marksheetContent .pdf-qr {
            width: 60px;
            height: 60px;
            border: 2px solid #1B3C53;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 8px;
            color: #1B3C53;
            background: #F8FAFC;
        }
        
        #marksheetContent .pdf-verification {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            background: #fafaf9;
            padding: 5px 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            border: 1px solid #E2E8F0;
            font-size: 9px;
        }
        
        #marksheetContent .pdf-student-card {
            display: flex;
            gap: 15px;
            background: #fafaf9;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 12px;
        }
        
        #marksheetContent .pdf-photo {
            width: 80px;
            height: 100px;
            border: 2px solid #E2E8F0;
            border-radius: 6px;
            background: #F1F5F9;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 32px;
            color: #94A3B8;
        }
        
        #marksheetContent .pdf-details {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2px 20px;
        }
        
        #marksheetContent .pdf-detail-item {
            display: flex;
            padding: 2px 0;
        }
        
        #marksheetContent .pdf-detail-label {
            font-weight: 600;
            color: #64748B;
            min-width: 80px;
            font-size: 9px;
        }
        
        #marksheetContent .pdf-detail-value {
            color: #1E293B;
            font-weight: 500;
            font-size: 9px;
        }
        
        #marksheetContent .pdf-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }
        
        #marksheetContent .pdf-summary-card {
            background: #fafaf9;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 8px 10px;
            text-align: center;
        }
        
        #marksheetContent .pdf-summary-card .icon {
            font-size: 14px;
            margin-bottom: 2px;
        }
        
        #marksheetContent .pdf-summary-card .value {
            font-size: 16px;
            font-weight: 800;
            color: #1B3C53;
        }
        
        #marksheetContent .pdf-summary-card .label {
            font-size: 8px;
            font-weight: 600;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        #marksheetContent .pdf-table-container {
            margin-bottom: 12px;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #E2E8F0;
        }
        
        #marksheetContent .pdf-marks-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        
        #marksheetContent .pdf-marks-table thead {
            background: #1B3C53;
            color: white;
        }
        
        #marksheetContent .pdf-marks-table thead th {
            padding: 6px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        #marksheetContent .pdf-marks-table tbody td {
            padding: 5px 8px;
            text-align: center;
            border-bottom: 1px solid #E2E8F0;
        }
        
        #marksheetContent .pdf-marks-table tbody tr:nth-child(even) {
            background: #fafaf9;
        }
        
        #marksheetContent .pdf-marks-table .total-row {
            background: #f0f4f8 !important;
            font-weight: 700;
        }
        
        #marksheetContent .pdf-marks-table .total-row td {
            border-top: 2px solid #1B3C53;
        }
        
        #marksheetContent .pdf-grade-badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 8px;
            color: white;
            display: inline-block;
        }
        
        #marksheetContent .pdf-result-highlight {
            text-align: center;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 12px;
            border: 2px solid;
        }
        
        #marksheetContent .pdf-result-highlight.pass {
            border-color: #234C6A;
            background: #f0f4f8;
        }
        
        #marksheetContent .pdf-result-highlight.fail {
            border-color: #c0392b;
            background: #FEF2F2;
        }
        
        #marksheetContent .pdf-result-highlight .status {
            font-size: 20px;
            font-weight: 800;
        }
        
        #marksheetContent .pdf-result-highlight .percentage {
            font-size: 14px;
            font-weight: 700;
        }
        
        #marksheetContent .pdf-result-highlight.pass .status { color: #234C6A; }
        #marksheetContent .pdf-result-highlight.fail .status { color: #c0392b; }
        
        #marksheetContent .pdf-meter-bar {
            width: 100%;
            height: 10px;
            background: #E2E8F0;
            border-radius: 20px;
            overflow: hidden;
            margin-top: 2px;
        }
        
        #marksheetContent .pdf-meter-bar .fill {
            height: 100%;
            background: linear-gradient(90deg, #234C6A, #1B3C53);
            border-radius: 20px;
        }
        
        #marksheetContent .pdf-grade-scale {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 4px;
            margin-bottom: 12px;
        }
        
        #marksheetContent .pdf-grade-item {
            text-align: center;
            padding: 3px 4px;
            border-radius: 4px;
            border: 1px solid #E2E8F0;
            background: #fafaf9;
        }
        
        #marksheetContent .pdf-grade-item .grade {
            font-weight: 700;
            font-size: 12px;
        }
        
        #marksheetContent .pdf-grade-item .range {
            font-size: 7px;
            color: #64748B;
            display: block;
        }
        
        #marksheetContent .pdf-signatures {
            display: flex;
            justify-content: space-between;
            padding: 0 10px;
            margin: 15px 0 10px 0;
        }
        
        #marksheetContent .pdf-sig-box {
            text-align: center;
        }
        
        #marksheetContent .pdf-sig-line {
            width: 100px;
            height: 1px;
            background: #1B3C53;
            margin: 4px auto;
        }
        
        #marksheetContent .pdf-sig-label {
            font-size: 8px;
            font-weight: 600;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        #marksheetContent .pdf-footer {
            text-align: center;
            padding-top: 10px;
            border-top: 2px solid #1B3C53;
            font-size: 8px;
            color: #64748B;
        }
        
        #marksheetContent .pdf-footer .contact {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 3px;
            font-weight: 500;
        }
        
        #marksheetContent .pdf-footer .generated {
            color: #94A3B8;
            font-size: 7px;
        }

        /* ================================================================
           ALERT STYLING
           ================================================================ */
        .alert-warning {
            background: #fdfaf5;
            border: 1px solid #e5d5c0;
            border-radius: 12px;
            padding: 1.25rem;
            color: var(--text-secondary);
        }
        
        .alert-warning i {
            color: #456882;
        }

        /* ================================================================
           RESPONSIVE
           ================================================================ */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .type-selector { flex-direction: column; }
            .type-btn { min-width: 100%; }
            .page-title { font-size: 1.5rem; }
            .main-content { padding: 12px; }
        }
        
        @media (max-width: 480px) { 
            .stats-grid { grid-template-columns: 1fr; } 
        }
        
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--body-bg);
        }
        ::-webkit-scrollbar-thumb {
            background: #456882;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #1B3C53;
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Breadcrumb -->
        <nav class="d-flex align-items-center text-sm mb-4" style="font-size: 0.85rem;">
            <a href="dashboard.php" class="breadcrumb-link">Dashboard</a>
            <i class="fas fa-chevron-right mx-2" style="font-size: 0.65rem; color: var(--text-muted);"></i>
            <span class="fw-semibold" style="color: var(--primary);">Marksheet Generator</span>
        </nav>
        
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
            <div>
                <h1 class="page-title">Marksheet Generator</h1>
                <p class="d-flex align-items-center" style="color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-file-alt me-2" style="color: var(--primary);"></i>
                    Generate professional institute-level marksheets
                </p>
            </div>
        </div>

        <!-- Search Card -->
        <div class="search-card">
            <form method="GET" action="">
                <div class="type-selector">
                    <button type="button" class="type-btn <?php echo $marksheet_type == 'batch' ? 'active' : ''; ?>" onclick="setType('batch')">
                        <i class="fas fa-layer-group"></i> Batch Wise
                    </button>
                    <button type="button" class="type-btn <?php echo $marksheet_type == 'exam' ? 'active' : ''; ?>" onclick="setType('exam')">
                        <i class="fas fa-file-alt"></i> Exam Wise
                    </button>
                    <button type="button" class="type-btn <?php echo $marksheet_type == 'consolidated' ? 'active' : ''; ?>" onclick="setType('consolidated')">
                        <i class="fas fa-star"></i> Consolidated
                    </button>
                </div>
                
                <input type="hidden" name="type" id="typeInput" value="<?php echo $marksheet_type; ?>">
                
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-user-graduate me-2"></i>Student ID</label>
                        <input type="text" class="form-control" name="student_id" 
                               value="<?php echo htmlspecialchars($student_id); ?>" 
                               placeholder="Enter student ID" required>
                    </div>
                    
                    <div class="col-md-4" id="batchField">
                        <label class="form-label"><i class="fas fa-users me-2"></i>Select Batch</label>
                        <select class="form-select" name="batch_id">
                            <option value="">All Batches</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?php echo $batch['batch_id']; ?>" 
                                        <?php echo ($batch['batch_id'] == $batch_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($batch['batch_name'] . ' - ' . $batch['course_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4" id="examField" style="display: <?php echo ($marksheet_type == 'exam' || count($exams) > 1) ? 'block' : 'none'; ?>">
                        <label class="form-label"><i class="fas fa-pencil-alt me-2"></i>Select Exam</label>
                        <select class="form-select" name="exam_id">
                            <option value="">All Exams</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?php echo $exam['exam_id']; ?>" 
                                        <?php echo ($exam['exam_id'] == $exam_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($exam['exam_name'] . ' (' . $exam['exam_type'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-magic me-2"></i>Generate Marksheet
                    </button>
                </div>
            </form>
        </div>

        <?php if ($student_info && !empty($results)): ?>
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase text-muted small fw-bold">Total Subjects</div>
                            <div class="h2 mb-0"><?php echo count($results); ?></div>
                        </div>
                        <div class="p-3 rounded-circle" style="background: rgba(27, 60, 83, 0.06);">
                            <i class="fas fa-book fa-2x" style="color: var(--primary);"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase text-muted small fw-bold">Marks Obtained</div>
                            <div class="h2 mb-0"><?php echo number_format($overall_obtained, 0); ?></div>
                        </div>
                        <div class="p-3 rounded-circle" style="background: rgba(35, 76, 106, 0.06);">
                            <i class="fas fa-trophy fa-2x" style="color: var(--secondary);"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase text-muted small fw-bold">Percentage</div>
                            <div class="h2 mb-0"><?php echo number_format($overall_percentage, 1); ?>%</div>
                        </div>
                        <div class="p-3 rounded-circle" style="background: rgba(69, 104, 130, 0.06);">
                            <i class="fas fa-chart-line fa-2x" style="color: var(--accent);"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase text-muted small fw-bold">Result</div>
                            <div class="h2 mb-0">
                                <span class="badge <?php echo $result_status == 'PASS' ? 'bg-success' : 'bg-danger'; ?> fs-6 px-3 py-2" 
                                      style="<?php echo $result_status == 'PASS' ? 'background: #234C6A !important;' : 'background: #c0392b !important;'; ?>">
                                    <?php echo $result_status; ?>
                                </span>
                            </div>
                        </div>
                        <div class="p-3 rounded-circle" style="background: rgba(210, 193, 182, 0.2);">
                            <i class="fas fa-medal fa-2x" style="color: <?php echo $result_status == 'PASS' ? '#234C6A' : '#c0392b'; ?>;"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Table -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-table me-2" style="color: var(--primary);"></i>
                    Subject-wise Marks
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="premium-table table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Batch</th>
                                    <th>Exam</th>
                                    <th>Subject</th>
                                    <th>Marks</th>
                                    <th>Total</th>
                                    <th>%</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $index => $result): 
                                    $percentage = ($result['total_marks'] > 0) ? ($result['obtained_marks'] / $result['total_marks']) * 100 : 0;
                                    $grade = getGrade($percentage);
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($result['batch_name']); ?></td>
                                        <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                        <td><?php echo htmlspecialchars($result['subject']); ?></td>
                                        <td><strong><?php echo number_format($result['obtained_marks'], 0); ?></strong></td>
                                        <td><?php echo number_format($result['total_marks'], 0); ?></td>
                                        <td><strong><?php echo number_format($percentage, 1); ?>%</strong></td>
                                        <td><span class="grade-badge grade-<?php echo str_replace('+', '-plus', strtolower($grade)); ?>"><?php echo $grade; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4"><strong>Overall Total</strong></td>
                                    <td><strong><?php echo number_format($overall_obtained, 0); ?></strong></td>
                                    <td><strong><?php echo number_format($overall_total, 0); ?></strong></td>
                                    <td><strong><?php echo number_format($overall_percentage, 1); ?>%</strong></td>
                                    <td><strong><?php echo $result_status; ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex gap-3 mb-4 flex-wrap">
                <button onclick="window.print()" class="btn btn-outline-secondary">
                    <i class="fas fa-print me-2"></i> Print
                </button>
                <button onclick="downloadPDF()" class="btn btn-success">
                    <i class="fas fa-file-pdf me-2"></i> Download PDF
                </button>
                <button onclick="exportExcel()" class="btn btn-primary">
                    <i class="fas fa-file-excel me-2"></i> Export Excel
                </button>
            </div>

            <!-- Hidden Marksheet Content for PDF -->
            <div id="marksheetContent">
                <div class="watermark">ASD ACADEMY</div>
                
                <div class="pdf-header">
                    <div class="pdf-header-content">
                        <div class="pdf-logo">ASD</div>
                        <div>
                            <div class="pdf-institute-name">ASD Cybernetics Inc.</div>
                            <div class="pdf-sub-title">ASD Academy</div>
                            <div class="pdf-doc-title">DETAILED MARKSHEET CERTIFICATE</div>
                        </div>
                        <div class="pdf-qr">
                            <div style="text-align:center;">
                                <div style="font-size:24px;">⬛</div>
                                <div style="font-size:5px;font-weight:600;">VERIFY</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="pdf-verification">
                    <span><span style="font-weight:600;color:#64748B;">Course:</span> <span style="color:#1E293B;"><?php echo htmlspecialchars($student_info['course_name'] ?? 'N/A'); ?></span></span>
                    <span><span style="font-weight:600;color:#64748B;">Batch:</span> <span style="color:#1E293B;"><?php echo htmlspecialchars($student_info['batch_name'] ?? 'N/A'); ?></span></span>
                    <span><span style="font-weight:600;color:#64748B;">Session:</span> <span style="color:#1E293B;"><?php echo date('Y') . '-' . (date('Y') + 1); ?></span></span>
                    <span><span style="font-weight:600;color:#64748B;">Issue Date:</span> <span style="color:#1E293B;"><?php echo date('d M Y'); ?></span></span>
                    <span><span style="font-weight:600;color:#64748B;">Verification ID:</span> <span style="color:#1E293B;"><?php echo $verification_id; ?></span></span>
                </div>
                
                <div class="pdf-student-card">
                    <div class="pdf-photo">👤</div>
                    <div class="pdf-details">
                        <div class="pdf-detail-item">
                            <span class="pdf-detail-label">Student Name</span>
                            <span class="pdf-detail-value"><?php echo htmlspecialchars($student_info['first_name'] . ' ' . ($student_info['last_name'] ?? '')); ?></span>
                        </div>
                        <div class="pdf-detail-item">
                            <span class="pdf-detail-label">Student ID</span>
                            <span class="pdf-detail-value"><?php echo htmlspecialchars($student_info['student_id']); ?></span>
                        </div>
                        <div class="pdf-detail-item">
                            <span class="pdf-detail-label">Father Name</span>
                            <span class="pdf-detail-value"><?php echo htmlspecialchars($student_info['father_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="pdf-detail-item">
                            <span class="pdf-detail-label">Mother Name</span>
                            <span class="pdf-detail-value"><?php echo htmlspecialchars($student_info['mother_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="pdf-detail-item">
                            <span class="pdf-detail-label">Date of Birth</span>
                            <span class="pdf-detail-value"><?php echo $student_info['date_of_birth'] ? date('d M Y', strtotime($student_info['date_of_birth'])) : 'N/A'; ?></span>
                        </div>
                        <div class="pdf-detail-item">
                            <span class="pdf-detail-label">Enrollment Date</span>
                            <span class="pdf-detail-value"><?php echo $student_info['enrollment_date'] ? date('d M Y', strtotime($student_info['enrollment_date'])) : 'N/A'; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="pdf-summary-grid">
                    <div class="pdf-summary-card">
                        <div class="icon">📚</div>
                        <div class="value"><?php echo $overall_total; ?></div>
                        <div class="label">Total Marks</div>
                    </div>
                    <div class="pdf-summary-card">
                        <div class="icon">⭐</div>
                        <div class="value"><?php echo $overall_obtained; ?></div>
                        <div class="label">Obtained Marks</div>
                    </div>
                    <div class="pdf-summary-card">
                        <div class="icon">📊</div>
                        <div class="value"><?php echo number_format($overall_percentage, 2); ?>%</div>
                        <div class="label">Percentage</div>
                    </div>
                    <div class="pdf-summary-card" style="border-color: <?php echo $result_status == 'PASS' ? '#234C6A' : '#c0392b'; ?>;">
                        <div class="icon">🏆</div>
                        <div class="value" style="color: <?php echo $result_status == 'PASS' ? '#234C6A' : '#c0392b'; ?>;"><?php echo $result_status; ?></div>
                        <div class="label">Result</div>
                    </div>
                </div>
                
                <div class="pdf-table-container">
                    <table class="pdf-marks-table">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Subject</th>
                                <th>Exam Name</th>
                                <th>Exam Type</th>
                                <th>Obtained</th>
                                <th>Max</th>
                                <th>%</th>
                                <th>Grade</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; foreach ($results as $result): 
                                $percentage = ($result['total_marks'] > 0) ? ($result['obtained_marks'] / $result['total_marks']) * 100 : 0;
                                $grade = getGrade($percentage);
                                $gradeColor = getGradeColor($grade);
                            ?>
                            <tr>
                                <td><?php echo $counter; ?></td>
                                <td><?php echo htmlspecialchars($result['subject']); ?></td>
                                <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $result['exam_type'])); ?></td>
                                <td><?php echo number_format($result['obtained_marks'], 0); ?></td>
                                <td><?php echo number_format($result['total_marks'], 0); ?></td>
                                <td><?php echo number_format($percentage, 1); ?>%</td>
                                <td>
                                    <span class="pdf-grade-badge" style="background: <?php echo $gradeColor; ?>;">
                                        <?php echo $grade; ?>
                                    </span>
                                </td>
                                <td><?php echo $percentage >= 40 ? 'PASS' : 'FAIL'; ?></td>
                            </tr>
                            <?php $counter++; endforeach; ?>
                            <tr class="total-row">
                                <td colspan="3"><strong>Overall Total</strong></td>
                                <td></td>
                                <td><strong><?php echo number_format($overall_obtained, 0); ?></strong></td>
                                <td><strong><?php echo number_format($overall_total, 0); ?></strong></td>
                                <td><strong><?php echo number_format($overall_percentage, 2); ?>%</strong></td>
                                <td><strong><?php echo getGrade($overall_percentage); ?></strong></td>
                                <td><strong><?php echo $result_status; ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="pdf-result-highlight <?php echo strtolower($result_status); ?>">
                    <div class="status"><?php echo $result_status; ?></div>
                    <div class="percentage">Overall Percentage: <?php echo number_format($overall_percentage, 2); ?>%</div>
                    <div style="font-size:10px;font-weight:500;margin-top:2px;">
                        Grade: <?php echo getGrade($overall_percentage); ?> 
                        <?php if ($overall_percentage >= 75): ?>
                            - With Distinction 🎖️
                        <?php elseif ($overall_percentage >= 60): ?>
                            - First Division 🏅
                        <?php elseif ($overall_percentage >= 40): ?>
                            - Second Division 📘
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;font-weight:600;font-size:9px;">
                        <span>Performance Meter</span>
                        <span><?php echo number_format($overall_percentage, 1); ?>%</span>
                    </div>
                    <div class="pdf-meter-bar">
                        <div class="fill" style="width: <?php echo min($overall_percentage, 100); ?>%;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:7px;color:#94A3B8;margin-top:2px;">
                        <span>0%</span>
                        <span>40%</span>
                        <span>60%</span>
                        <span>75%</span>
                        <span>90%</span>
                        <span>100%</span>
                    </div>
                </div>
                
                <div class="pdf-grade-scale">
                    <div class="pdf-grade-item">
                        <div class="grade" style="color:#10B981;">A+</div>
                        <div class="range">90-100%</div>
                    </div>
                    <div class="pdf-grade-item">
                        <div class="grade" style="color:#1B3C53;">A</div>
                        <div class="range">75-89%</div>
                    </div>
                    <div class="pdf-grade-item">
                        <div class="grade" style="color:#234C6A;">B</div>
                        <div class="range">60-74%</div>
                    </div>
                    <div class="pdf-grade-item">
                        <div class="grade" style="color:#456882;">C</div>
                        <div class="range">40-59%</div>
                    </div>
                    <div class="pdf-grade-item">
                        <div class="grade" style="color:#c0392b;">F</div>
                        <div class="range">Below 40%</div>
                    </div>
                </div>
                
                <div class="pdf-signatures">
                    <div class="pdf-sig-box">
                        <div class="pdf-sig-line"></div>
                        <div class="pdf-sig-label">Semester Coordinator</div>
                    </div>
                    <div class="pdf-sig-box">
                        <div class="pdf-sig-line"></div>
                        <div class="pdf-sig-label">Principal</div>
                    </div>
                    <div class="pdf-sig-box">
                        <div class="pdf-sig-line"></div>
                        <div class="pdf-sig-label">Registrar</div>
                    </div>
                </div>
                
                <div class="pdf-footer">
                    <div class="contact">
                        <span>🌐 www.asdacademy.com</span>
                        <span>📧 info@asdacademy.com</span>
                        <span>📞 +91-9680100687</span>
                        <span>📍 ASD Cybernetics Inc.</span>
                    </div>
                    <div class="generated">
                        Generated from ASD ERP System · <?php echo date('d M Y, H:i'); ?> · Verification ID: <?php echo $verification_id; ?>
                    </div>
                </div>
            </div>

            <!-- Preview Section -->
            <div class="preview-section">
                <div style="text-align: center;">
                    <h4 style="color: var(--primary); margin-bottom: 0.5rem; font-weight: 700;">Marksheet Ready</h4>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">
                        <strong>Student:</strong> <?php echo htmlspecialchars($student_info['first_name'] . ' ' . ($student_info['last_name'] ?? '')); ?>
                        | <strong>ID:</strong> <?php echo htmlspecialchars($student_info['student_id']); ?>
                        | <strong>Type:</strong> <?php echo ucfirst($marksheet_type); ?>
                        | <strong>Overall:</strong> <?php echo number_format($overall_percentage, 1); ?>%
                    </p>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">
                        <i class="fas fa-info-circle me-2" style="color: var(--accent);"></i>
                        Click "Download PDF" to generate a professional institute-level marksheet
                    </p>
                </div>
            </div>
        <?php elseif (!empty($student_id)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>No results found.</strong> Please check the Student ID and selections.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
        // ===== TYPE SELECTOR =====
        function setType(type) {
            document.getElementById('typeInput').value = type;
            document.querySelectorAll('.type-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.textContent.toLowerCase().includes(type)) {
                    btn.classList.add('active');
                }
            });
            const examField = document.getElementById('examField');
            if (type === 'consolidated') {
                examField.style.display = 'none';
            } else {
                examField.style.display = 'block';
            }
        }

        // ===== EXPORT FUNCTIONS =====
        function exportExcel() {
            const table = document.querySelector('.premium-table');
            if (!table) return;
            const wb = XLSX.utils.table_to_book(table, {sheet: "Marksheet"});
            XLSX.writeFile(wb, 'marksheet_<?php echo $student_id; ?>_<?php echo date('Y-m-d'); ?>.xlsx');
        }

        function downloadPDF() {
            const element = document.getElementById('marksheetContent');
            element.style.display = 'block';
            
            const opt = {
                margin: [10, 10, 10, 10],
                filename: 'marksheet_<?php echo $student_id; ?>_<?php echo date('Y-m-d'); ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2, 
                    useCORS: true, 
                    logging: false,
                    width: 794,
                    height: 1123
                },
                jsPDF: { 
                    unit: 'mm', 
                    format: 'a4', 
                    orientation: 'portrait' 
                },
                pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
            };
            
            html2pdf().set(opt).from(element).save().then(function() {
                element.style.display = 'none';
            }).catch(function(error) {
                console.error('PDF Generation Error:', error);
                element.style.display = 'none';
                alert('Error generating PDF. Please try again.');
            });
        }
    </script>
</body>
</html>