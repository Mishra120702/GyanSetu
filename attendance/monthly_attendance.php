<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Set default filters with proper validation
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_batch = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'monthly';

// Validate and sanitize inputs
$selected_month = preg_match('/^\d{4}-\d{2}$/', $selected_month) ? $selected_month : date('Y-m');
$selected_batch = htmlspecialchars(trim($selected_batch));
$start_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ? $start_date : '';
$end_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) ? $end_date : '';

// Validate custom date range
$date_range_error = '';
if ($report_type === 'custom' && $start_date && $end_date) {
    if (strtotime($start_date) > strtotime($end_date)) {
        $date_range_error = "Start date cannot be after end date";
        $start_date = '';
        $end_date = '';
    } elseif (strtotime($end_date) - strtotime($start_date) > 365 * 86400) {
        $date_range_error = "Date range cannot exceed 1 year";
        $start_date = '';
        $end_date = '';
    }
}

// Get all batches for the filter dropdown
try {
    $stmt = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching batches: " . $e->getMessage());
    $batches = [];
}

// Get available months with attendance data
try {
    $stmt = $db->query("SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month FROM attendance ORDER BY month DESC");
    $available_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching months: " . $e->getMessage());
    $available_months = [];
}

// Get attendance statistics based on report type
$attendance_stats = [];
$attendance_data = [];
$total_days = 0;
$date_range_label = '';

if ($report_type === 'monthly' && $selected_month) {
    try {
        // Calculate first and last day of the month
        $first_day = date('Y-m-01', strtotime($selected_month));
        $last_day = date('Y-m-t', strtotime($selected_month));
        $total_days = date('t', strtotime($selected_month));
        $date_range_label = date('F Y', strtotime($selected_month));
        
        list($attendance_stats, $attendance_data) = fetchAttendanceData($db, $first_day, $last_day, $selected_batch);
        
    } catch (PDOException $e) {
        error_log("Database error fetching attendance stats: " . $e->getMessage());
        $attendance_stats = [];
        $attendance_data = [];
    }
} elseif ($report_type === 'weekly') {
    try {
        $start_date = date('Y-m-d', strtotime('-6 days'));
        $end_date = date('Y-m-d');
        $total_days = 7;
        $date_range_label = 'Last 7 Days (' . date('M j', strtotime($start_date)) . ' - ' . date('M j', strtotime($end_date)) . ')';
        
        list($attendance_stats, $attendance_data) = fetchAttendanceData($db, $start_date, $end_date, $selected_batch);
        
    } catch (PDOException $e) {
        error_log("Database error fetching weekly attendance stats: " . $e->getMessage());
        $attendance_stats = [];
        $attendance_data = [];
    }
} elseif ($report_type === 'custom' && $start_date && $end_date && !$date_range_error) {
    try {
        $total_days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
        $date_range_label = date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date));
        
        list($attendance_stats, $attendance_data) = fetchAttendanceData($db, $start_date, $end_date, $selected_batch);
        
    } catch (PDOException $e) {
        error_log("Database error fetching custom range attendance stats: " . $e->getMessage());
        $attendance_stats = [];
        $attendance_data = [];
    }
}

/**
 * Fetch attendance data for a given date range
 */
function fetchAttendanceData($db, $start_date, $end_date, $selected_batch) {
    // Build query for attendance statistics
    $query = "
        SELECT 
            a.student_id,
            s.first_name,
            s.last_name,
            a.batch_id as attendance_batch,
            b.batch_name as attendance_batch_name,
            s.batch_name as current_batch,
            (SELECT batch_name FROM batches WHERE batch_id = s.batch_name) as current_batch_name,
            COUNT(*) as total_days,
            SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
            ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as attendance_percentage,
            CASE 
                WHEN COUNT(DISTINCT a.batch_id) > 1 THEN 1
                ELSE 0
            END as has_multiple_batches,
            GROUP_CONCAT(DISTINCT a.batch_id ORDER BY a.batch_id SEPARATOR ', ') as all_batches_attended
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN batches b ON a.batch_id = b.batch_id
        WHERE DATE(a.date) BETWEEN ? AND ?
    ";
    
    $params = [$start_date, $end_date];
    
    if ($selected_batch) {
        $query .= " AND a.batch_id = ?";
        $params[] = $selected_batch;
    }
    
    $query .= " GROUP BY a.student_id, s.first_name, s.last_name, s.batch_name 
               ORDER BY s.first_name, s.last_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $attendance_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get detailed attendance data for the calendar view
    $detail_query = "
        SELECT 
            a.student_id,
            a.date,
            a.status,
            a.camera_status,
            a.remarks,
            a.batch_id
        FROM attendance a
        WHERE DATE(a.date) BETWEEN ? AND ?
    ";
    
    $detail_params = [$start_date, $end_date];
    
    if ($selected_batch) {
        $detail_query .= " AND a.batch_id = ?";
        $detail_params[] = $selected_batch;
    }
    
    $detail_query .= " ORDER BY a.student_id, a.date";
    
    $stmt = $db->prepare($detail_query);
    $stmt->execute($detail_params);
    $attendance_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize details by student and date
    $attendance_data = [];
    foreach ($attendance_details as $detail) {
        $attendance_data[$detail['student_id']][$detail['date']] = [
            'status' => $detail['status'],
            'camera_status' => $detail['camera_status'],
            'remarks' => $detail['remarks'],
            'batch_id' => $detail['batch_id']
        ];
    }
    
    return [$attendance_stats, $attendance_data];
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($attendance_stats, $report_type, $selected_month, $start_date, $end_date, $selected_batch, $date_range_label);
    exit;
}

/**
 * Export attendance data to CSV
 */
function exportToCSV($attendance_stats, $report_type, $selected_month, $start_date, $end_date, $selected_batch, $date_range_label) {
    header('Content-Type: text/csv; charset=utf-8');
    
    if ($report_type === 'monthly') {
        $filename = "monthly_attendance_" . $selected_month;
    } elseif ($report_type === 'weekly') {
        $filename = "weekly_attendance_" . date('Y-m-d');
    } else {
        $filename = "custom_attendance_" . $start_date . "_to_" . $end_date;
    }
    
    if ($selected_batch) {
        $filename .= "_batch_" . $selected_batch;
    }
    
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    // Add headers
    fputcsv($output, ['Attendance Report - ' . $date_range_label]);
    fputcsv($output, []); // Empty row
    
    // Column headers
    $headers = ['Student ID', 'Student Name', 'Current Batch', 'Attendance Batch(s)', 'Total Days', 'Present Days', 'Absent Days', 'Attendance %', 'Status'];
    fputcsv($output, $headers);
    
    // Data rows
    foreach ($attendance_stats as $stat) {
        $status = '';
        if ($stat['attendance_percentage'] >= 90) {
            $status = 'Excellent';
        } elseif ($stat['attendance_percentage'] >= 75) {
            $status = 'Good';
        } else {
            $status = 'Needs Improvement';
        }
        
        $batch_info = $stat['has_multiple_batches'] ? $stat['all_batches_attended'] : $stat['attendance_batch'];
        $current_batch_display = $stat['current_batch_name'] ?: $stat['current_batch'];
        
        $row = [
            $stat['student_id'],
            $stat['first_name'] . ' ' . $stat['last_name'],
            $current_batch_display,
            $batch_info,
            $stat['total_days'],
            $stat['present_days'],
            $stat['absent_days'],
            $stat['attendance_percentage'] . '%',
            $status
        ];
        fputcsv($output, $row);
    }
    
    fclose($output);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report - ASD Academy</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        :root {
            /* ── Brand palette ── */
            --navy-900:  #1B3C53;   /* deepest navy          */
            --navy-800:  #234C6A;   /* dark steel blue        */
            --teal-700:  #234C6A;   /* header / hero base     */
            --teal-500:  #456882;   /* mid blue-grey          */
            --cyan-400:  #A8C4D0;   /* light sky accent       */
            /* ── Added harmonious tones ── */
            --sand-50:   #FAF6F3;   /* almost-white warm      */
            --sand-100:  #F2EAE4;   /* very light warm beige  */
            --sand-200:  #E4D4CB;   /* light beige            */
            --sand-400:  #D2C1B6;   /* given warm sand        */
            --steel-300: #6B8FA3;   /* lighter complement     */
            --warm-accent: #B07A60; /* copper — derived from sand */
            --warm-accent-hover: #965F48;
            /* ── Semantic / action ── */
            --primary:          #456882;
            --primary-hover:    #234C6A;
            --primary-soft:     #EBF3F8;
            --teal-action:      #234C6A;
            --teal-action-hover:#1B3C53;
            --purple-action:    #1B3C53;
            --purple-action-hover:#0E2535;
            --success: #10b981;
            --danger:  #ef4444;
            --warning: #eab308;
            /* ── Neutral warm grays ── */
            --gray-100: #F2EAE4;
            --gray-200: #E0D3CB;
            --gray-300: #C8B8AE;
            --gray-600: #6B5F57;
            --gray-700: #4A3D37;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(160deg, #EBF3F8 0%, #F2EAE4 55%, #EDE5DF 100%);
            min-height: 100vh;
        }

        /* ════════════════════════════════
           HEADER
        ════════════════════════════════ */
        header {
            background: white;
            color: var(--navy-900);
            box-shadow: 0 1px 8px rgba(15,42,54,0.08);
            border-bottom: 2px solid transparent;
            background-clip: padding-box;
            position: relative;
        }
        header::after {
            content:'';
            position:absolute;
            bottom:0; left:0; right:0;
            height:3px;
            background: linear-gradient(90deg, var(--navy-900), var(--teal-500), var(--sand-400), var(--cyan-400));
        }

        /* ════════════════════════════════
           CARDS
        ════════════════════════════════ */
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 2px 8px rgba(15,42,54,0.05), 0 12px 28px rgba(15,42,54,0.05);
            padding: 1.75rem;
            margin-bottom: 1.75rem;
            border: 1px solid rgba(15,42,54,0.04);
            border-left: 5px solid var(--teal-500);
            transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            position: relative;
            overflow: hidden;
        }
        .card::before {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 120px; height: 120px;
            background: radial-gradient(circle at top right, rgba(168,196,208,0.13) 0%, transparent 70%);
            pointer-events: none;
        }
        .card:hover {
            box-shadow: 0 8px 24px rgba(15,42,54,0.10), 0 20px 40px rgba(15,42,54,0.07);
            transform: translateY(-3px);
        }
        .card > h2 { color: var(--teal-700); }

        /* ════════════════════════════════
           PAGE HERO
        ════════════════════════════════ */
        .page-hero {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 50%, #2A5875 100%);
            border-radius: 20px;
            padding: 2.2rem 2rem 1.75rem;
            margin-bottom: 1.75rem;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 36px rgba(27,60,83,0.30);
        }
        .page-hero::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 300px; height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(168,196,208,0.22) 0%, transparent 70%);
            pointer-events: none;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            bottom: -60px; left: 30%;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(210,193,182,0.15) 0%, transparent 70%);
            pointer-events: none;
        }
        .page-hero-eyebrow {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--cyan-400);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .page-hero-eyebrow::before {
            content: '';
            display: inline-block;
            width: 20px; height: 2px;
            background: var(--cyan-400);
            border-radius: 2px;
        }
        .page-hero h1 {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.2;
            color: white;
        }
        .page-hero h1 .accent { color: var(--cyan-400); }
        .page-hero p {
            color: rgba(255,255,255,0.72);
            font-size: 0.9rem;
            margin-top: 0.4rem;
            max-width: 38rem;
        }
        .hero-chip {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.14);
            backdrop-filter: blur(6px);
            border-radius: 14px;
            padding: 0.75rem 1.1rem;
            display: inline-flex;
            flex-direction: column;
            min-width: 110px;
            transition: all 0.25s ease;
        }
        .hero-chip:hover {
            border-color: var(--sand-400);
            background: rgba(210,193,182,0.20);
            transform: translateY(-2px);
        }
        .hero-chip .chip-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: white;
        }
        .hero-chip .chip-label {
            font-size: 0.65rem;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* ════════════════════════════════
           SUMMARY CARDS
        ════════════════════════════════ */
        .summary-card {
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
            color: white;
            border-radius: 18px;
            padding: 1.5rem;
            box-shadow: 0 6px 24px rgba(12,36,48,0.20);
            border-left: none;
            transition: all 0.28s ease;
            position: relative;
            overflow: hidden;
        }
        .summary-card::after {
            content: '';
            position: absolute;
            bottom: -20px; right: -20px;
            width: 90px; height: 90px;
            border-radius: 50%;
            background: rgba(255,255,255,0.07);
            pointer-events: none;
        }
        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 32px rgba(12,36,48,0.26);
        }
        .summary-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: rgba(255,255,255,0.15);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 0.9rem;
        }
        .summary-value {
            font-size: 2.1rem;
            font-weight: 800;
            margin-bottom: 0.2rem;
            line-height: 1;
        }
        .summary-label {
            font-size: 0.82rem;
            opacity: 0.85;
            font-weight: 500;
            letter-spacing: 0.01em;
        }
        .progress-bar {
            height: 6px;
            background-color: rgba(255,255,255,0.25);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.65rem;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, rgba(255,255,255,0.7), white);
            border-radius: 4px;
            transition: width 0.8s cubic-bezier(0.4,0,0.2,1);
        }

        /* ════════════════════════════════
           REPORT TYPE TABS  (pill style)
        ════════════════════════════════ */
        .report-type-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.4rem;
            background: var(--sand-100);
            border-radius: 14px;
            padding: 0.35rem;
            border-bottom: none;
            flex-wrap: wrap;
        }
        .report-type-tab {
            padding: 0.6rem 1.3rem;
            border: none;
            background: transparent;
            cursor: pointer;
            border-bottom: none;
            margin-bottom: 0;
            font-weight: 600;
            font-size: 0.88rem;
            color: var(--gray-600);
            transition: all 0.25s ease;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .report-type-tab.active {
            background: linear-gradient(135deg, #1B3C53 0%, #456882 100%);
            color: white;
            box-shadow: 0 3px 12px rgba(27,60,83,0.35);
        }
        .report-type-tab:hover:not(.active) {
            background: var(--sand-200);
            color: var(--navy-900);
        }

        /* ════════════════════════════════
           INPUTS
        ════════════════════════════════ */
        .minimal-input {
            border: 1.5px solid var(--gray-200);
            border-radius: 10px;
            padding: 0.65rem 1rem;
            font-size: 0.93rem;
            transition: all 0.25s ease;
            background-color: var(--sand-50);
            width: 100%;
            box-shadow: 0 1px 3px rgba(27,60,83,0.06);
            color: var(--navy-900);
        }
        .minimal-input:focus {
            border-color: var(--teal-500);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.18);
            outline: none;
            background: white;
        }
        .minimal-input.error { border-color: var(--danger); }
        .error-message {
            color: var(--danger);
            font-size: 0.75rem;
            margin-top: 0.3rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* ════════════════════════════════
           BUTTONS
        ════════════════════════════════ */
        .btn-primary {
            background: linear-gradient(135deg, #456882 0%, #234C6A 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 0.65rem 1.4rem;
            font-weight: 700;
            font-size: 0.88rem;
            transition: all 0.22s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            box-shadow: 0 3px 10px rgba(27,60,83,0.32);
            text-decoration: none;
            cursor: pointer;
            letter-spacing: 0.01em;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
            transform: translateY(-2px);
            box-shadow: 0 7px 18px rgba(27,60,83,0.42);
        }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        .btn-secondary {
            background: white;
            color: var(--navy-800);
            border: 1.5px solid var(--sand-200);
            border-radius: 10px;
            padding: 0.65rem 1.4rem;
            font-weight: 600;
            font-size: 0.88rem;
            transition: all 0.22s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 1px 4px rgba(27,60,83,0.08);
        }
        .btn-secondary:hover {
            background: var(--sand-100);
            border-color: var(--sand-400);
            color: var(--navy-900);
            transform: translateY(-1px);
        }
        .btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-teal {
            background: linear-gradient(135deg, #456882 0%, #234C6A 100%);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            box-shadow: 0 2px 8px rgba(27,60,83,0.28);
            cursor: pointer;
            width: 100%;
            margin-bottom: 0.35rem;
        }
        .btn-teal:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(27,60,83,0.40);
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
        }
        .btn-purple {
            background: linear-gradient(135deg, #B07A60 0%, #955E46 100%);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            box-shadow: 0 2px 8px rgba(176,122,96,0.30);
            cursor: pointer;
            width: 100%;
            margin-top: 0.35rem;
        }
        .btn-purple:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(176,122,96,0.42);
            background: linear-gradient(135deg, #955E46 0%, #7D4A34 100%);
        }

        /* ════════════════════════════════
           STATUS BADGES
        ════════════════════════════════ */
        .status-badge {
            padding: 0.3rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.73rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            min-width: 62px;
            justify-content: center;
            letter-spacing: 0.01em;
        }
        .status-present {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .status-absent {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .status-badge-excellent {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .status-badge-good {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #78350f;
            border: 1px solid #fcd34d;
        }
        .status-badge-poor {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* ════════════════════════════════
           ATTENDANCE % CELL
        ════════════════════════════════ */
        .attendance-high { color: #059669; font-weight: 800; }
        .attendance-medium { color: #b45309; font-weight: 800; }
        .attendance-low { color: #dc2626; font-weight: 800; }

        .pct-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .pct-bar-bg {
            height: 5px;
            border-radius: 4px;
            background: var(--gray-200);
            overflow: hidden;
            min-width: 60px;
        }
        .pct-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.6s ease;
        }
        .pct-bar-high  { background: linear-gradient(90deg, #10b981, #059669); }
        .pct-bar-med   { background: linear-gradient(90deg, #fbbf24, #d97706); }
        .pct-bar-low   { background: linear-gradient(90deg, #f87171, #dc2626); }

        /* ════════════════════════════════
           BATCH INFO
        ════════════════════════════════ */
        .batch-info { display: flex; flex-direction: column; gap: 0.3rem; }
        .current-batch {
            font-weight: 700;
            color: var(--teal-700);
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .current-batch::before {
            content: '';
            display: inline-block;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--teal-500);
            flex-shrink: 0;
        }
        .attendance-batches {
            font-size: 0.78rem;
            color: var(--gray-600);
        }
        .multiple-batches {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            border: 1px solid #fcd34d;
            font-size: 0.72rem;
            font-weight: 600;
            color: #78350f;
        }
        .batch-transfer-indicator {
            background: linear-gradient(135deg, #fff7ed, #fed7aa);
            border: 1px solid #fb923c;
            border-radius: 6px;
            padding: 0.2rem 0.55rem;
            font-size: 0.72rem;
            color: #7c2d12;
            font-weight: 700;
            margin-top: 0.15rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* ════════════════════════════════
           DATATABLE OVERRIDES
        ════════════════════════════════ */
        .dataTables_wrapper { margin-top: 1rem; }
        table.dataTable thead tr {
            background: linear-gradient(90deg, var(--navy-900) 0%, var(--teal-700) 100%);
        }
        table.dataTable thead th {
            color: #fff !important;
            border-bottom: none !important;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding-top: 0.9rem;
            padding-bottom: 0.9rem;
        }
        table.dataTable thead th:first-child { border-radius: 10px 0 0 0; }
        table.dataTable thead th:last-child  { border-radius: 0 10px 0 0; }

        table.dataTable tbody tr:nth-child(even) { background-color: #f3fafb; }
        table.dataTable tbody tr:hover { background-color: #e0f5f4 !important; }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, var(--teal-500), var(--teal-700)) !important;
            color: white !important;
            border-color: var(--teal-700) !important;
            border-radius: 8px !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--gray-100) !important;
            color: var(--navy-900) !important;
            border-radius: 8px !important;
        }
        .dataTables_wrapper .dataTables_filter input {
            border: 1.5px solid var(--gray-200);
            border-radius: 9999px;
            padding: 0.45rem 1rem;
            margin-left: 0.5rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--teal-500);
            box-shadow: 0 0 0 3px rgba(45,126,143,0.15);
        }

        /* ════════════════════════════════
           MODALS
        ════════════════════════════════ */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(12,36,48,0.55);
            backdrop-filter: blur(3px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 0;
            max-width: 90%;
            max-height: 90%;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(12,36,48,0.30);
        }
        .modal-header {
            background: linear-gradient(135deg, var(--teal-700) 0%, var(--navy-900) 100%);
            border-radius: 20px 20px 0 0;
            padding: 1.2rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            color: white !important;
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0;
        }
        .modal-close-btn {
            color: rgba(255,255,255,0.75);
            background: rgba(255,255,255,0.12);
            border: none;
            width: 30px; height: 30px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 1rem;
        }
        .modal-close-btn:hover {
            background: rgba(255,255,255,0.22);
            color: white;
        }
        .modal-body { padding: 1.5rem; }

        /* ════════════════════════════════
           SPINNER
        ════════════════════════════════ */
        .spinner {
            width: 42px; height: 42px;
            border: 4px solid rgba(45,126,143,0.15);
            border-radius: 50%;
            border-top-color: var(--cyan-400);
            animation: spin 0.9s ease-in-out infinite;
            margin: 0 auto;
        }

        /* ════════════════════════════════
           ALERTS
        ════════════════════════════════ */
        .alert { padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1rem; display: flex; align-items: flex-start; gap: 0.75rem; }
        .alert-danger  { background: linear-gradient(135deg,#fef2f2,#fee2e2); border:1.5px solid #fca5a5; color:#991b1b; }
        .alert-warning { background: linear-gradient(135deg,#fffbeb,#fef3c7); border:1.5px solid #fde68a; color:#92400e; }

        /* ════════════════════════════════
           EMPTY STATE
        ════════════════════════════════ */
        .empty-icon-wrap {
            width: 88px; height: 88px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(45,126,143,0.1), rgba(29,85,102,0.08));
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
            font-size: 2.2rem;
            color: var(--teal-500);
        }

        /* ════════════════════════════════
           LABEL COLOR
        ════════════════════════════════ */
        label { color: var(--teal-700) !important; font-weight: 600; }

        /* ════════════════════════════════
           FIELD GROUP HEADER
        ════════════════════════════════ */
        .field-section-title {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--teal-500);
            margin-bottom: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .field-section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, var(--gray-200), transparent);
        }

        /* ════════════════════════════════
           ANIMATIONS
        ════════════════════════════════ */
        @keyframes fadeIn  { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
        @keyframes spin    { to   { transform: rotate(360deg); } }

        .date-range-fields { display:none; animation: fadeIn 0.3s ease; }
        .date-range-fields.active { display:block; }
        .month-selector { display:none; animation: fadeIn 0.3s ease; }
        .month-selector.active { display:block; }

        /* ════════════════════════════════
           RESPONSIVE
        ════════════════════════════════ */
        @media (max-width: 768px) {
            .card { padding: 1.1rem; }
            .modal-content { max-width: 96%; }
            .report-type-tabs { flex-direction: column; gap: 0.3rem; padding: 0.3rem; }
            .report-type-tab { justify-content: flex-start; }
            .summary-value { font-size: 1.6rem; }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-3 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600 hover:text-blue-500 transition-colors" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="flex items-center gap-2 text-sm font-semibold" style="color: var(--navy-900);">
                <i class="fas fa-chart-bar" style="color: var(--teal-500);"></i>
                <span>Attendance Report</span>
            </div>
            <a href="attendance.php" class="btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i> Back to Attendance
            </a>
        </header>

        <div class="p-4 md:p-6">
            <!-- Page Hero -->
            <div class="page-hero">
                <div class="flex flex-wrap items-end justify-between gap-5">
                    <div>
                        <div class="page-hero-eyebrow">Attendance &middot; Reports</div>
                        <h1><span class="accent">Attendance</span> Report</h1>
                        <p>Analyse student attendance by week, month, or custom date range — drill into individual calendars and export detailed records.</p>
                    </div>
                    <div class="flex gap-3">
                        <div class="hero-chip">
                            <span class="chip-value"><?= count($batches) ?></span>
                            <span class="chip-label">Batches</span>
                        </div>
                        <div class="hero-chip">
                            <span class="chip-value"><?= count($available_months) ?></span>
                            <span class="chip-label">Months on record</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Card -->
            <div class="card">
                <h2 class="text-xl font-bold mb-4"><i class="fas fa-sliders-h mr-2" style="color: var(--teal-500);"></i>Report Filters</h2>
                
                <!-- Report Type Tabs -->
                <div class="report-type-tabs flex flex-wrap">
                    <button type="button" class="report-type-tab <?= $report_type === 'weekly' ? 'active' : '' ?>" data-type="weekly">
                        <i class="fas fa-calendar-week mr-2"></i> Weekly Report
                    </button>
                    <button type="button" class="report-type-tab <?= $report_type === 'monthly' ? 'active' : '' ?>" data-type="monthly">
                        <i class="fas fa-calendar-month mr-2"></i> Monthly Report
                    </button>
                    <button type="button" class="report-type-tab <?= $report_type === 'custom' ? 'active' : '' ?>" data-type="custom">
                        <i class="fas fa-calendar-day mr-2"></i> Custom Date Range
                    </button>
                </div>

                <?php if ($date_range_error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($date_range_error) ?>
                </div>
                <?php endif; ?>

                <form method="GET" action="" id="reportForm" novalidate>
                    <!-- Monthly Report Fields -->
                    <div class="month-selector <?= $report_type === 'monthly' ? 'active' : '' ?>">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="monthFilter" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar-alt mr-1"></i> Select Month
                                </label>
                                <select id="monthFilter" name="month" class="minimal-input">
                                    <option value="">-- Select Month --</option>
                                    <?php foreach ($available_months as $month): ?>
                                    <option value="<?= htmlspecialchars($month['month']) ?>" 
                                        <?= ($selected_month === $month['month']) ? 'selected' : '' ?>>
                                        <?= date('F Y', strtotime($month['month'])) ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php if (empty($available_months)): ?>
                                    <option value="<?= date('Y-m') ?>" selected><?= date('F Y') ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="batchFilterMonthly" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-layer-group mr-1"></i> Select Batch (Optional)
                                </label>
                                <select id="batchFilterMonthly" name="batch_id" class="minimal-input">
                                    <option value="">All Batches</option>
                                    <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                        <?= ($selected_batch === $batch['batch_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Date Range Fields -->
                    <div class="date-range-fields <?= $report_type === 'custom' ? 'active' : '' ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="startDate" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar-day mr-1"></i> Start Date
                                </label>
                                <input type="date" id="startDate" name="start_date" 
                                       value="<?= htmlspecialchars($start_date) ?>" 
                                       class="minimal-input" 
                                       min="2000-01-01"
                                       max="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div>
                                <label for="endDate" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar-check mr-1"></i> End Date
                                </label>
                                <input type="date" id="endDate" name="end_date" 
                                       value="<?= htmlspecialchars($end_date) ?>" 
                                       class="minimal-input"
                                       min="2000-01-01"
                                       max="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div>
                                <label for="batchFilterCustom" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-layer-group mr-1"></i> Select Batch (Optional)
                                </label>
                                <select id="batchFilterCustom" name="batch_id" class="minimal-input">
                                    <option value="">All Batches</option>
                                    <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                        <?= ($selected_batch === $batch['batch_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div id="dateRangeError" class="error-message" style="display: none;"></div>
                    </div>

                    <input type="hidden" name="report_type" id="reportType" value="<?= $report_type ?>">
                    
                    <div class="flex flex-wrap items-center gap-3 mt-4">
                        <button type="submit" id="applyFiltersBtn" class="btn-primary">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                        <?php if ((($report_type === 'monthly' && $selected_month) || ($report_type === 'weekly') || ($report_type === 'custom' && $start_date && $end_date && !$date_range_error)) && !empty($attendance_stats)): ?>
                        <button type="submit" name="export" value="csv" class="btn-secondary">
                            <i class="fas fa-download mr-2"></i> Export CSV
                        </button>
                        <?php endif; ?>
                        <button type="button" id="resetFiltersBtn" class="btn-secondary">
                            <i class="fas fa-undo-alt mr-2"></i> Reset
                        </button>
                    </div>
                </form>
            </div>

            <?php if ((($report_type === 'monthly' && $selected_month) || ($report_type === 'weekly') || ($report_type === 'custom' && $start_date && $end_date && !$date_range_error)) && !empty($attendance_stats)): ?>
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="summary-card">
                    <div class="summary-icon"><i class="fas fa-users"></i></div>
                    <div class="summary-value"><?= count($attendance_stats) ?></div>
                    <div class="summary-label">Total Students</div>
                </div>
                
                <div class="summary-card" style="background: linear-gradient(135deg, #059669 0%, #065f46 100%);">
                    <?php
                    $total_present = array_sum(array_column($attendance_stats, 'present_days'));
                    $total_absent = array_sum(array_column($attendance_stats, 'absent_days'));
                    $total_records = $total_present + $total_absent;
                    $overall_percentage = $total_records > 0 ? round(($total_present / $total_records) * 100, 2) : 0;
                    ?>
                    <div class="summary-icon"><i class="fas fa-chart-pie"></i></div>
                    <div class="summary-value"><?= $overall_percentage ?>%</div>
                    <div class="summary-label">Overall Attendance</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $overall_percentage ?>%"></div>
                    </div>
                </div>
                
                <div class="summary-card" style="background: linear-gradient(135deg, #d97706 0%, #92400e 100%);">
                    <div class="summary-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="summary-value"><?= $total_days ?></div>
                    <div class="summary-label">Working Days</div>
                </div>
                
                <div class="summary-card" style="background: linear-gradient(135deg, #7c3aed 0%, #4c1d95 100%);">
                    <?php
                    // Count students with multiple batches
                    $multiple_batch_students = array_filter($attendance_stats, function($stat) {
                        return $stat['has_multiple_batches'] == 1;
                    });
                    ?>
                    <div class="summary-icon"><i class="fas fa-exchange-alt"></i></div>
                    <div class="summary-value"><?= count($multiple_batch_students) ?></div>
                    <div class="summary-label">Batch Transfers</div>
                </div>
            </div>

            <!-- Detailed Report -->
            <div class="card">
                <h2 class="text-xl font-bold mb-4">
                    <i class="fas fa-chart-line text-blue-500 mr-2"></i>
                    Detailed Report - <?= htmlspecialchars($date_range_label) ?>
                    <?php if ($selected_batch): ?>
                    <span class="text-sm font-normal text-gray-500 ml-2">
                        (Batch: <?= htmlspecialchars($selected_batch) ?>)
                    </span>
                    <?php endif; ?>
                </h2>
                
                <div class="overflow-x-auto">
                    <table id="attendanceReportTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Batch Information</th>
                                <th>Total Days</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Attendance %</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_stats as $stat): 
                                $attendance_class = '';
                                if ($stat['attendance_percentage'] >= 90) {
                                    $attendance_class = 'attendance-high';
                                } elseif ($stat['attendance_percentage'] >= 75) {
                                    $attendance_class = 'attendance-medium';
                                } else {
                                    $attendance_class = 'attendance-low';
                                }

                                // Determine batch information display
                                $current_batch_display = $stat['current_batch_name'] ?: $stat['current_batch'];
                                $attendance_batch_display = $stat['has_multiple_batches'] ? 
                                    '<span class="multiple-batches" title="Attended multiple batches: ' . htmlspecialchars($stat['all_batches_attended']) . '"><i class="fas fa-exchange-alt mr-1"></i>Multiple Batches</span>' : 
                                    htmlspecialchars($stat['attendance_batch']);
                            ?>
                            <tr class="student-row">
                                <td><?= htmlspecialchars($stat['student_id']) ?></td>
                                <td><?= htmlspecialchars($stat['first_name'] . ' ' . $stat['last_name']) ?></td>
                                <td>
                                    <div class="batch-info">
                                        <div class="current-batch">Current: <?= htmlspecialchars($current_batch_display) ?></div>
                                        <div class="attendance-batches">Attendance: <?= $attendance_batch_display ?></div>
                                        <?php if ($stat['has_multiple_batches']): ?>
                                        <div class="batch-transfer-indicator">
                                            <i class="fas fa-exchange-alt mr-1"></i>
                                            Transferred Student
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= $stat['total_days'] ?></td>
                                <td>
                                    <span class="status-badge status-present"><?= $stat['present_days'] ?></span>
                                </td>
                                <td>
                                    <span class="status-badge status-absent"><?= $stat['absent_days'] ?></span>
                                </td>
                                <td>
                                    <div class="pct-cell">
                                        <span class="<?= $attendance_class ?>"><?= $stat['attendance_percentage'] ?>%</span>
                                        <div class="pct-bar-bg">
                                            <div class="pct-bar-fill <?= $stat['attendance_percentage'] >= 90 ? 'pct-bar-high' : ($stat['attendance_percentage'] >= 75 ? 'pct-bar-med' : 'pct-bar-low') ?>" style="width:<?= $stat['attendance_percentage'] ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($stat['attendance_percentage'] >= 90): ?>
                                        <span class="status-badge status-badge-excellent"><i class="fas fa-star" style="font-size:0.6rem;"></i> Excellent</span>
                                    <?php elseif ($stat['attendance_percentage'] >= 75): ?>
                                        <span class="status-badge status-badge-good"><i class="fas fa-thumbs-up" style="font-size:0.6rem;"></i> Good</span>
                                    <?php else: ?>
                                        <span class="status-badge status-badge-poor"><i class="fas fa-exclamation-triangle" style="font-size:0.6rem;"></i> Needs Improvement</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="view-calendar-btn btn-teal" 
                                            data-student-id="<?= htmlspecialchars($stat['student_id']) ?>"
                                            data-student-name="<?= htmlspecialchars($stat['first_name'] . ' ' . $stat['last_name']) ?>"
                                            data-month="<?= $report_type === 'monthly' ? $selected_month : '' ?>"
                                            data-start-date="<?= $report_type === 'custom' ? $start_date : '' ?>"
                                            data-end-date="<?= $report_type === 'custom' ? $end_date : '' ?>"
                                            data-report-type="<?= $report_type ?>">
                                        <i class="fas fa-calendar-day"></i> Calendar
                                    </button>
                                    <button class="view-history-btn btn-purple" 
                                            data-student-id="<?= htmlspecialchars($stat['student_id']) ?>"
                                            data-student-name="<?= htmlspecialchars($stat['first_name'] . ' ' . $stat['last_name']) ?>">
                                        <i class="fas fa-history"></i> Full History
                                    </button>
                                 </td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif (($report_type === 'monthly' && $selected_month) || ($report_type === 'custom' && $start_date && $end_date && !$date_range_error)): ?>
            <!-- No Data Message -->
            <div class="card text-center py-8">
                <div class="empty-icon-wrap">
                    <i class="fas fa-calendar-times"></i>
                </div>
                <h3 class="text-xl font-semibold mb-2" style="color:var(--navy-900);">No Attendance Data Found</h3>
                <p class="mb-5" style="color:var(--gray-600);">
                    <?php if ($report_type === 'monthly'): ?>
                        No attendance records found for <?= date('F Y', strtotime($selected_month)) ?> with the selected filters.
                    <?php else: ?>
                        No attendance records found for the date range <?= date('M j, Y', strtotime($start_date)) ?> to <?= date('M j, Y', strtotime($end_date)) ?> with the selected filters.
                    <?php endif; ?>
                </p>
                <a href="attendance.php" class="btn-primary inline-block">
                    <i class="fas fa-clipboard-check"></i> Go to Attendance Tracking
                </a>
            </div>
            <?php else: ?>
            <!-- Initial State -->
            <div class="card text-center py-8">
                <div class="empty-icon-wrap">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <h3 class="text-xl font-semibold mb-2" style="color:var(--navy-900);">Attendance Report</h3>
                <p style="color:var(--gray-600);">Select a report type and apply filters to generate the attendance report.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Calendar Modal -->
    <div id="calendarModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-calendar-day mr-2"></i>Student Attendance Calendar</h3>
                <button id="closeModal" class="modal-close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="calendarContainer">
                <div class="text-center py-8">
                    <div class="spinner mb-4"></div>
                    <p style="color:var(--gray-600);">Loading calendar...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 id="historyModalTitle"><i class="fas fa-history mr-2"></i>Student Attendance History</h3>
                <button id="closeHistoryModal" class="modal-close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="historyContainer">
                <div class="text-center py-8">
                    <div class="spinner mb-4"></div>
                    <p style="color:var(--gray-600);">Loading history...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    $(document).ready(function() {
        let dataTable = null;
        
        // Initialize DataTable if table exists
        if ($('#attendanceReportTable').length && $('#attendanceReportTable tbody tr').length) {
            dataTable = $('#attendanceReportTable').DataTable({
                responsive: true,
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-download mr-2"></i> Export CSV',
                        className: 'btn-secondary',
                        filename: function() {
                            const reportType = $('#reportType').val();
                            let filename = '';
                            if (reportType === 'monthly') {
                                filename = 'monthly_attendance_<?= $selected_month ?>';
                            } else {
                                filename = 'custom_attendance_<?= $start_date ?>_to_<?= $end_date ?>';
                            }
                            
                            if ('<?= $selected_batch ?>') {
                                filename += '_batch_<?= $selected_batch ?>';
                            }
                            return filename;
                        }
                    }
                ],
                pageLength: 25,
                order: [[6, 'desc']],
                columnDefs: [
                    { targets: [2, 8], orderable: false }
                ],
                language: {
                    emptyTable: "No attendance data available for the selected filters"
                }
            });
        }

        // Report type tab switching
        $('.report-type-tab').click(function() {
            const reportType = $(this).data('type');
            
            // Update active tab
            $('.report-type-tab').removeClass('active');
            $(this).addClass('active');
            
            // Update hidden field
            $('#reportType').val(reportType);
            
            // Show/hide appropriate fields
            if (reportType === 'monthly') {
                $('.month-selector').addClass('active');
                $('.date-range-fields').removeClass('active');
                $('#monthFilter').prop('required', true);
                $('#startDate').prop('required', false);
                $('#endDate').prop('required', false);
            } else {
                $('.month-selector').removeClass('active');
                $('.date-range-fields').addClass('active');
                $('#monthFilter').prop('required', false);
                $('#startDate').prop('required', true);
                $('#endDate').prop('required', true);
            }
        });

        // Synchronize batch selection between monthly and custom filters
        function syncBatchFilters() {
            $('#batchFilterMonthly').off('change').on('change', function() {
                $('#batchFilterCustom').val($(this).val());
            });
            
            $('#batchFilterCustom').off('change').on('change', function() {
                $('#batchFilterMonthly').val($(this).val());
            });
        }
        
        syncBatchFilters();

        // Custom date range validation
        function validateDateRange() {
            const startDate = $('#startDate').val();
            const endDate = $('#endDate').val();
            const errorDiv = $('#dateRangeError');
            
            errorDiv.hide().empty();
            
            if (!startDate || !endDate) {
                return true;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                errorDiv.text('Start date cannot be after end date').show();
                return false;
            }
            
            // Check if date range exceeds 1 year
            const diffTime = Math.abs(new Date(endDate) - new Date(startDate));
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            if (diffDays > 365) {
                errorDiv.text('Date range cannot exceed 1 year (365 days)').show();
                return false;
            }
            
            return true;
        }
        
        // Validate dates on change
        $('#startDate, #endDate').on('change', function() {
            validateDateRange();
            
            // Set min/max constraints
            if ($('#startDate').val()) {
                $('#endDate').attr('min', $('#startDate').val());
            }
            if ($('#endDate').val()) {
                $('#startDate').attr('max', $('#endDate').val());
            }
        });
        
        // Form validation before submit
        $('#reportForm').on('submit', function(e) {
            const reportType = $('#reportType').val();
            
            if (reportType === 'monthly') {
                const month = $('#monthFilter').val();
                if (!month) {
                    alert('Please select a month');
                    e.preventDefault();
                    return false;
                }
            } else {
                const startDate = $('#startDate').val();
                const endDate = $('#endDate').val();
                
                if (!startDate || !endDate) {
                    alert('Please select both start and end dates');
                    e.preventDefault();
                    return false;
                }
                
                if (!validateDateRange()) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Show loading state
            const submitBtn = $(this).find('button[type="submit"]');
            const originalText = submitBtn.html();
            submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i> Loading...').prop('disabled', true);
            
            // Re-enable after timeout (in case of slow response)
            setTimeout(() => {
                submitBtn.html(originalText).prop('disabled', false);
            }, 5000);
        });
        
        // Reset filters
        $('#resetFiltersBtn').on('click', function() {
            const reportType = $('#reportType').val();
            
            if (reportType === 'monthly') {
                $('#monthFilter').val('');
                $('#batchFilterMonthly').val('');
            } else {
                $('#startDate').val('');
                $('#endDate').val('');
                $('#batchFilterCustom').val('');
            }
            
            // Submit the form to reset
            $('#reportForm').submit();
        });

        // Set initial date constraints
        if ($('#startDate').val()) {
            $('#endDate').attr('min', $('#startDate').val());
        }
        if ($('#endDate').val()) {
            $('#startDate').attr('max', $('#endDate').val());
        }

        // View calendar functionality
        $('.view-calendar-btn').click(function() {
            const studentId = $(this).data('student-id');
            const studentName = $(this).data('student-name');
            const reportType = $(this).data('report-type');
            let month = $(this).data('month');
            let startDate = $(this).data('start-date');
            let endDate = $(this).data('end-date');
            
            // Show loading in modal
            $('#calendarContainer').html('<div class="text-center py-8"><div class="spinner mb-4"></div><p>Loading calendar...</p></div>');
            
            let modalTitle = 'Attendance Calendar - ' + studentName;
            if (reportType === 'monthly') {
                modalTitle += ' (' + month + ')';
            } else {
                modalTitle += ' (' + startDate + ' to ' + endDate + ')';
            }
            $('#modalTitle').text(modalTitle);
            $('#calendarModal').show();
            
            // Load calendar via AJAX
            $.ajax({
                url: 'attendance_api.php',
                type: 'GET',
                data: {
                    action: 'student_calendar',
                    student_id: studentId,
                    month: month,
                    start_date: startDate,
                    end_date: endDate,
                    report_type: reportType
                },
                success: function(response) {
                    if (response.success) {
                        $('#calendarContainer').html(response.html);
                    } else {
                        $('#calendarContainer').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading calendar data: ' + (response.message || 'Unknown error') + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#calendarContainer').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading calendar data. Please try again.</p></div>');
                }
            });
        });

        // View full history functionality
        $('.view-history-btn').click(function() {
            const studentId = $(this).data('student-id');
            const studentName = $(this).data('student-name');
            
            // Show loading in modal
            $('#historyContainer').html('<div class="text-center py-8"><div class="spinner mb-4"></div><p>Loading attendance history...</p></div>');
            $('#historyModalTitle').text('Complete Attendance History - ' + studentName);
            $('#historyModal').show();
            
            // Load history via AJAX
            $.ajax({
                url: 'attendance_api.php',
                type: 'GET',
                data: {
                    action: 'student_full_history',
                    student_id: studentId
                },
                success: function(response) {
                    if (response.success) {
                        $('#historyContainer').html(response.html);
                    } else {
                        $('#historyContainer').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading attendance history: ' + (response.message || 'Unknown error') + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#historyContainer').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading attendance history. Please try again.</p></div>');
                }
            });
        });

        // Close modals
        $('#closeModal, #closeHistoryModal').click(function() {
            $(this).closest('.modal-overlay').hide();
        });

        // Close modals when clicking outside
        $(window).click(function(event) {
            if ($(event.target).is('#calendarModal')) {
                $('#calendarModal').hide();
            }
            if ($(event.target).is('#historyModal')) {
                $('#historyModal').hide();
            }
        });

        // Mobile sidebar toggle
        window.toggleSidebar = function() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.flex-1');
            if (sidebar) {
                sidebar.classList.toggle('-translate-x-full');
            }
            if (mainContent) {
                mainContent.classList.toggle('ml-0');
                mainContent.classList.toggle('md:ml-64');
            }
        };
        
        // Prevent form double submission
        let isSubmitting = false;
        $('#applyFiltersBtn').on('click', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            isSubmitting = true;
            setTimeout(() => { isSubmitting = false; }, 3000);
        });
        
        // Initialize flatpickr for better date picker experience (optional)
        if (typeof flatpickr !== 'undefined') {
            flatpickr("#startDate, #endDate", {
                dateFormat: "Y-m-d",
                maxDate: "today",
                allowInput: true
            });
        }
    });
    </script>
</body>
</html>