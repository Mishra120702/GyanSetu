<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../db_connection.php';

// Check if admin/mentor is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'mentor'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Handle application approval/rejection
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_application'])) {
        $application_id = $_POST['application_id'];
        $remarks = $_POST['admin_remarks'] ?? '';
        
        $db->beginTransaction();
        
        try {
            // Update application
            $stmt = $db->prepare("
                UPDATE leave_applications 
                SET status = 'approved', 
                    approved_by = :approved_by, 
                    approved_at = NOW(),
                    admin_remarks = :remarks
                WHERE id = :id AND status = 'pending'
            ");
            $stmt->execute([
                ':approved_by' => $user_id,
                ':remarks' => $remarks,
                ':id' => $application_id
            ]);
            
            // Add to history
            $history_stmt = $db->prepare("
                INSERT INTO leave_application_history (application_id, action, action_by, remarks)
                VALUES (:application_id, 'approved', :action_by, :remarks)
            ");
            $history_stmt->execute([
                ':application_id' => $application_id,
                ':action_by' => $user_id,
                ':remarks' => $remarks
            ]);
            
            $db->commit();
            $success_message = "Application approved successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Failed to approve application: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_application'])) {
        $application_id = $_POST['application_id'];
        $rejection_reason = $_POST['rejection_reason'];
        $remarks = $_POST['admin_remarks'] ?? '';
        
        if (empty($rejection_reason)) {
            $error_message = "Please provide a reason for rejection.";
        } else {
            $db->beginTransaction();
            
            try {
                // Update application
                $stmt = $db->prepare("
                    UPDATE leave_applications 
                    SET status = 'rejected', 
                        rejected_by = :rejected_by, 
                        rejected_at = NOW(),
                        rejection_reason = :rejection_reason,
                        admin_remarks = :remarks
                    WHERE id = :id AND status = 'pending'
                ");
                $stmt->execute([
                    ':rejected_by' => $user_id,
                    ':rejection_reason' => $rejection_reason,
                    ':remarks' => $remarks,
                    ':id' => $application_id
                ]);
                
                // Add to history
                $history_stmt = $db->prepare("
                    INSERT INTO leave_application_history (application_id, action, action_by, remarks)
                    VALUES (:application_id, 'rejected', :action_by, :remarks)
                ");
                $history_stmt->execute([
                    ':application_id' => $application_id,
                    ':action_by' => $user_id,
                    ':remarks' => $rejection_reason
                ]);
                
                $db->commit();
                $success_message = "Application rejected successfully!";
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Failed to reject application: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$status_filter    = $_GET['status']    ?? 'all';
$user_type_filter = $_GET['user_type'] ?? 'all';
$search_query     = trim($_GET['search'] ?? '');
$date_from        = $_GET['date_from'] ?? '';
$date_to          = $_GET['date_to']   ?? '';
$sort             = $_GET['sort']      ?? 'newest';

// Detect which quick-date preset is currently active
$activePreset = '';
if ($date_from && $date_to) {
    $now = new DateTime();

    // Today
    $today = $now->format('Y-m-d');
    if ($date_from === $today && $date_to === $today) {
        $activePreset = 'today';
    }
    // This week (Mon → Sun)
    $dowISO  = (int)$now->format('N');          // 1=Mon … 7=Sun
    $monDate = (clone $now)->modify('-' . ($dowISO - 1) . ' days');
    $sunDate = (clone $monDate)->modify('+6 days');
    if ($date_from === $monDate->format('Y-m-d') && $date_to === $sunDate->format('Y-m-d')) {
        $activePreset = 'week';
    }
    // This month
    if ($date_from === $now->format('Y-m-01') && $date_to === $now->format('Y-m-t')) {
        $activePreset = 'month';
    }
    // Last month
    $lastFirst = (clone $now)->modify('first day of last month')->format('Y-m-d');
    $lastLast  = (clone $now)->modify('last day of last month')->format('Y-m-d');
    if ($date_from === $lastFirst && $date_to === $lastLast) {
        $activePreset = 'last_month';
    }
}

// Build query - Differentiate between students and trainers
$query = "
    SELECT l.*, 
           b.batch_name as batch_title,
           u.name as approved_by_name,
           u2.name as rejected_by_name,
           CASE 
               WHEN s.student_id IS NOT NULL THEN 'student'
               WHEN t.user_id IS NOT NULL THEN 'trainer'
               ELSE 'unknown'
           END as applicant_type,
           CASE 
               WHEN s.student_id IS NOT NULL THEN CONCAT(s.first_name, ' ', s.last_name)
               WHEN t.user_id IS NOT NULL THEN t.name
               ELSE l.student_name
           END as applicant_full_name,
           CASE 
               WHEN s.student_id IS NOT NULL THEN s.email
               WHEN t.user_id IS NOT NULL THEN t.email
               ELSE l.email
           END as applicant_email,
           CASE 
               WHEN s.student_id IS NOT NULL THEN s.phone_number
               WHEN t.user_id IS NOT NULL THEN t.phone
               ELSE NULL
           END as applicant_phone
    FROM leave_applications l
    LEFT JOIN batches b ON l.batch_id = b.batch_id
    LEFT JOIN users u ON l.approved_by = u.id
    LEFT JOIN users u2 ON l.rejected_by = u2.id
    LEFT JOIN students s ON l.student_id = s.student_id
    LEFT JOIN trainers t ON l.student_id = t.user_id
    WHERE 1=1
";

$params = [];

if ($status_filter !== 'all') {
    $query .= " AND l.status = :status";
    $params[':status'] = $status_filter;
}

// User type filter (student or trainer)
if ($user_type_filter === 'student') {
    $query .= " AND s.student_id IS NOT NULL";
} elseif ($user_type_filter === 'trainer') {
    $query .= " AND t.user_id IS NOT NULL";
}

// Search by name (student or trainer name) - FIXED: Using different parameter names
if (!empty($search_query)) {
    $query .= " AND (
        l.application_no LIKE :search1 
        OR l.student_name LIKE :search2
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE :search3
        OR t.name LIKE :search4
        OR s.email LIKE :search5
        OR t.email LIKE :search6
        OR l.reason_category LIKE :search7
    )";
    $search_param = "%$search_query%";
    $params[':search1'] = $search_param;
    $params[':search2'] = $search_param;
    $params[':search3'] = $search_param;
    $params[':search4'] = $search_param;
    $params[':search5'] = $search_param;
    $params[':search6'] = $search_param;
    $params[':search7'] = $search_param;
}

if (!empty($date_from)) {
    $query .= " AND DATE(l.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(l.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$query .= match($sort) {
    'oldest'       => " ORDER BY l.created_at ASC",
    'longest'      => " ORDER BY l.total_days DESC, l.created_at DESC",
    'waiting'      => " ORDER BY CASE l.status WHEN 'pending' THEN 1 ELSE 2 END ASC, l.created_at ASC",
    default        => " ORDER BY CASE l.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'rejected' THEN 3 WHEN 'cancelled' THEN 4 END, l.created_at DESC",
};

try {
    $applications_stmt = $db->prepare($query);
    $applications_stmt->execute($params);
    $applications = $applications_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Query Error: " . $e->getMessage());
    error_log("Query: " . $query);
    error_log("Params: " . print_r($params, true));
    $applications = [];
    $error_message = "Database query error: " . $e->getMessage();
}

// ── CSV Export ──────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'leave_applications_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel opens it correctly
    fputs($out, "\xEF\xBB\xBF");

    // Header row
    fputcsv($out, [
        'Application No',
        'Applicant Type',
        'Applicant Name',
        'Email',
        'Phone',
        'Batch',
        'Leave From',
        'Leave To',
        'Total Days',
        'Reason Category',
        'Reason Detail',
        'Status',
        'Applied On',
        'Approved / Rejected By',
        'Admin Remarks',
        'Rejection Reason',
    ]);

    foreach ($applications as $app) {
        $actionBy = '';
        if ($app['status'] === 'approved')  $actionBy = $app['approved_by_name']  ?? '';
        if ($app['status'] === 'rejected')  $actionBy = $app['rejected_by_name']   ?? '';

        // Format dates as d-M-Y (e.g. 05-Jun-2025) so Excel cannot
        // misinterpret them as numeric date serials showing "#####".
        $fmt_date = function($val) {
            if (empty($val)) return '';
            $ts = strtotime($val);
            return $ts ? date('d-M-Y', $ts) : $val;
        };
        $fmt_datetime = function($val) {
            if (empty($val)) return '';
            $ts = strtotime($val);
            return $ts ? date('d-M-Y h:i A', $ts) : $val;
        };

        fputcsv($out, [
            $app['application_no']                                   ?? '',
            ucfirst($app['applicant_type']                           ?? ''),
            $app['applicant_full_name'] ?? $app['student_name']      ?? '',
            $app['applicant_email']     ?? $app['email']             ?? '',
            $app['applicant_phone']                                  ?? '',
            $app['batch_title']         ?? $app['batch_id']          ?? '',
            $fmt_date($app['start_date']                             ?? ''),
            $fmt_date($app['end_date']                               ?? ''),
            $app['total_days']                                       ?? '',
            $app['reason_category']                                  ?? '',
            $app['reason_detail']                                    ?? '',
            ucfirst($app['status']                                   ?? ''),
            $fmt_datetime($app['created_at']                         ?? ''),
            $actionBy,
            $app['admin_remarks']                                    ?? '',
            $app['rejection_reason']                                 ?? '',
        ]);
    }

    fclose($out);
    exit();
}
// ────────────────────────────────────────────────────────────────────────────

// Get statistics with user type breakdown - respects date / search / user_type filters
// (intentionally does NOT filter by status so every card stays meaningful)
try {
    $stats_where  = [];
    $stats_params = [];

    // ── User-type filter ──────────────────────────────────────────────────────
    if ($user_type_filter === 'student') {
        $stats_where[] = "s.student_id IS NOT NULL";
    } elseif ($user_type_filter === 'trainer') {
        $stats_where[] = "t.user_id IS NOT NULL";
    }

    // ── Search filter ─────────────────────────────────────────────────────────
    if (!empty($search_query)) {
        $stats_where[] = "(
            l.application_no LIKE :s1
            OR l.student_name LIKE :s2
            OR CONCAT(s.first_name, ' ', s.last_name) LIKE :s3
            OR t.name LIKE :s4
            OR s.email LIKE :s5
            OR t.email LIKE :s6
            OR l.reason_category LIKE :s7
        )";
        $sp = "%{$search_query}%";
        $stats_params += [':s1'=>$sp,':s2'=>$sp,':s3'=>$sp,':s4'=>$sp,':s5'=>$sp,':s6'=>$sp,':s7'=>$sp];
    }

    // ── Date filter ───────────────────────────────────────────────────────────
    if (!empty($date_from)) {
        $stats_where[]            = "DATE(l.created_at) >= :sf";
        $stats_params[':sf']      = $date_from;
    }
    if (!empty($date_to)) {
        $stats_where[]            = "DATE(l.created_at) <= :st";
        $stats_params[':st']      = $date_to;
    }

    $stats_where_sql = $stats_where ? ("WHERE " . implode(" AND ", $stats_where)) : "";

    $stats_sql = "
        SELECT
            COUNT(DISTINCT l.id)                                              AS total,
            SUM(CASE WHEN l.status = 'pending'   THEN 1 ELSE 0 END)          AS pending,
            SUM(CASE WHEN l.status = 'approved'  THEN 1 ELSE 0 END)          AS approved,
            SUM(CASE WHEN l.status = 'rejected'  THEN 1 ELSE 0 END)          AS rejected,
            SUM(CASE WHEN l.status = 'cancelled' THEN 1 ELSE 0 END)          AS cancelled,
            SUM(CASE WHEN s.student_id IS NOT NULL THEN 1 ELSE 0 END)        AS student_applications,
            SUM(CASE WHEN t.user_id   IS NOT NULL THEN 1 ELSE 0 END)         AS trainer_applications
        FROM leave_applications l
        LEFT JOIN students s ON l.student_id = s.student_id
        LEFT JOIN trainers t ON l.student_id = t.user_id
        {$stats_where_sql}
    ";

    $stats_stmt = $db->prepare($stats_sql);
    $stats_stmt->execute($stats_params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Stats Query Error: " . $e->getMessage());
    $stats = [
        'total'                => 0,
        'pending'              => 0,
        'approved'             => 0,
        'rejected'             => 0,
        'cancelled'            => 0,
        'student_applications' => 0,
        'trainer_applications' => 0,
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .animate-fade-up { animation: fadeInUp 0.6s ease-out forwards; }
        .animate-slide-left { animation: slideInLeft 0.5s ease-out forwards; }
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }
        .delay-500 { animation-delay: 0.5s; }
        
        /* ── Stat cards – enhanced hover effects ── */
        .stat-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(27,60,83,0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(69,104,130,0.12);
            padding: 1.25rem 1rem;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-height: 80px;
            cursor: default;
        }

        /* Left accent line – hidden by default, animates on hover */
        .stat-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: transparent;
            transition: background 0.4s ease, transform 0.4s ease;
            transform: scaleY(0);
            transform-origin: top;
        }

        .stat-card:hover::before {
            background: var(--accent-color, #456882);
            transform: scaleY(1);
        }

        /* Background gradient overlay on hover */
        .stat-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(27,60,83,0.03), rgba(69,104,130,0.06));
            opacity: 0;
            transition: opacity 0.4s ease;
            border-radius: inherit;
            pointer-events: none;
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(27,60,83,0.14);
            border-color: var(--color-accent, #456882);
        }

        .stat-card .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(0,0,0,0.10);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
            position: relative;
            z-index: 2;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.08) rotate(-2deg) translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }

        .stat-card .stat-content {
            flex: 1;
            min-width: 0;
            position: relative;
            z-index: 2;
        }

        .stat-card .stat-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color 0.3s ease;
        }

        .stat-card:hover .stat-label {
            color: var(--color-secondary, #234C6A);
        }

        .stat-card .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1B3C53;
            line-height: 1.2;
            transition: color 0.3s ease;
        }

        .stat-card:hover .stat-number {
            color: var(--color-primary, #1B3C53);
        }

        .stat-card .stat-sub {
            font-size: 0.6rem;
            color: #94a3b8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color 0.3s ease;
        }

        .stat-card:hover .stat-sub {
            color: var(--color-accent, #456882);
        }

        /* Icon color variants – assign a custom property for the accent */
        .stat-total .stat-icon { background: linear-gradient(135deg, #1B3C53, #456882); }
        .stat-total { --accent-color: #1B3C53; }
        .stat-pending .stat-icon { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .stat-pending { --accent-color: #f59e0b; }
        .stat-approved .stat-icon { background: linear-gradient(135deg, #059669, #10b981); }
        .stat-approved { --accent-color: #10b981; }
        .stat-rejected .stat-icon { background: linear-gradient(135deg, #dc2626, #ef4444); }
        .stat-rejected { --accent-color: #ef4444; }
        .stat-cancelled .stat-icon { background: linear-gradient(135deg, #6b7280, #9ca3af); }
        .stat-cancelled { --accent-color: #6b7280; }
        .stat-students .stat-icon { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }
        .stat-students { --accent-color: #7c3aed; }
        .stat-trainers .stat-icon { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .stat-trainers { --accent-color: #ec4899; }

        /* ── Status badges ── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-approved { background-color: #d1fae5; color: #065f46; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; }
        .status-cancelled { background-color: #f3f4f6; color: #374151; }
        
        .user-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .user-student { background-color: #dbeafe; color: #1e40af; }
        .user-trainer { background-color: #fce7f3; color: #9d174d; }

        /* ── Days-waiting badge ── */
        .waiting-badge {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.7rem; font-weight: 700;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            animation: pulse-badge 2s ease-in-out infinite;
            box-shadow: 0 2px 8px rgba(239,68,68,0.4);
        }
        @keyframes pulse-badge {
            0%, 100% { box-shadow: 0 2px 8px rgba(239,68,68,0.4); }
            50%       { box-shadow: 0 2px 16px rgba(239,68,68,0.7); }
        }

        /* ── Sort buttons ── */
        .sort-btn {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.4rem 0.85rem;
            border-radius: 8px;
            font-size: 0.78rem; font-weight: 600;
            border: 1.5px solid #e2e8f0;
            background: white; color: #374151;
            text-decoration: none; transition: all 0.15s;
            white-space: nowrap;
        }
        .sort-btn:hover { background: #f0f9ff; border-color: #93c5fd; color: #1d4ed8; }
        .sort-btn.active { background: linear-gradient(135deg, #1B3C53, #234C6A); color: white; border-color: transparent; box-shadow: 0 2px 8px rgba(27,60,83,0.3); }
        .sort-btn.active-waiting { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border-color: transparent; box-shadow: 0 2px 8px rgba(239,68,68,0.3); }

        /* ── Export CSV button ── */
        .export-csv-btn {
            background: linear-gradient(135deg, #059669, #047857);
            color: white !important;
            border-color: transparent;
            box-shadow: 0 2px 8px rgba(5,150,105,0.25);
        }
        .export-csv-btn:hover {
            background: linear-gradient(135deg, #047857, #065f46);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 14px rgba(5,150,105,0.4);
            transform: translateY(-1px);
        }
        .export-csv-btn .export-count {
            font-size: 0.7rem;
            opacity: 0.85;
            margin-left: 0.1rem;
        }
        
        /* ── Date preset buttons ── */
        .preset-btn {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 8px;
            font-size: 0.75rem; font-weight: 600;
            border: 1.5px solid #bae6fd;
            background: white; color: #0369a1;
            cursor: pointer; transition: all 0.15s;
            white-space: nowrap;
        }
        .preset-btn:hover { background: #e0f2fe; border-color: #7dd3fc; color: #075985; }
        .preset-btn.active-preset {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: white; border-color: transparent;
            box-shadow: 0 2px 8px rgba(27,60,83,0.3);
        }

        .filter-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(69,104,130,0.12);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            transition: all 0.2s ease;
            color: white;
            border: none;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(27,60,83,0.3); }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            transition: all 0.2s ease;
            color: white;
        }
        .btn-success:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            transition: all 0.2s ease;
            color: white;
        }
        .btn-danger:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
        
        .btn-secondary { 
            background: #6b7280; 
            transition: all 0.2s ease;
            color: white;
        }
        .btn-secondary:hover { background: #4b5563; }
        
        .form-input, .form-select {
            transition: all 0.2s ease;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.6rem 1rem;
            background: #f8fafc;
            color: #1e293b;
        }
        
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #456882;
            box-shadow: 0 0 0 3px rgba(69,104,130,0.12);
            background: white;
        }

        /* ── Duration pill ── */
        .duration-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            margin-top: 3px;
        }
        /* 1–2 days → green */
        .duration-short {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        /* 3–5 days → yellow/amber */
        .duration-medium {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
            border: 1px solid #fcd34d;
        }
        /* 6+ days → red */
        .duration-long {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* Header banner gradient */
        .banner-header {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 60%, #456882 100%);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-100 via-blue-50 to-indigo-50 min-h-screen">
    <?php include '../header.php'; ?>
    
    <div class="flex">
        <?php include '../sidebar.php'; ?>
        
        <div class="flex-1 ml-64 p-8">
            <!-- Header -->
            <div class="mb-8 animate-fade-up">
                <div class="banner-header rounded-2xl shadow-xl p-6 overflow-hidden relative">
                    <!-- decorative circles -->
                    <div class="absolute -top-6 -right-6 w-32 h-32 rounded-full opacity-20" style="background:rgba(255,255,255,0.15);"></div>
                    <div class="absolute -bottom-8 -left-4 w-24 h-24 rounded-full opacity-10" style="background:rgba(255,255,255,0.2);"></div>
                    <div class="flex items-center justify-between flex-wrap gap-4 relative z-10">
                        <div class="flex items-center space-x-4">
                            <div class="w-16 h-16 bg-white bg-opacity-20 border-2 border-white border-opacity-30 rounded-2xl shadow-lg flex items-center justify-center">
                                <i class="fas fa-calendar-alt text-white text-3xl"></i>
                            </div>
                            <div>
                                <h1 class="text-3xl font-bold text-white">Leave Management</h1>
                                <p class="text-blue-100 mt-1">Manage and process leave applications from students and trainers</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="px-4 py-2 bg-white bg-opacity-20 border border-white border-opacity-30 rounded-xl">
                                <i class="fas fa-user-shield text-white mr-2"></i>
                                <span class="text-sm text-white font-semibold"><?= ucfirst($user_role) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-lg animate-fade-up">
                    <div class="flex items-center"><i class="fas fa-check-circle mr-2"></i><span><?= htmlspecialchars($success_message) ?></span></div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg animate-fade-up">
                    <div class="flex items-center"><i class="fas fa-exclamation-circle mr-2"></i><span><?= htmlspecialchars($error_message) ?></span></div>
                </div>
            <?php endif; ?>

            <!-- Pending Alert Banner -->
            <?php if (($stats['pending'] ?? 0) >= 5): ?>
            <div class="mb-6 animate-fade-up" id="pendingAlertBanner">
                <div class="relative overflow-hidden rounded-2xl shadow-lg px-6 py-4" style="background: linear-gradient(135deg, #b45309 0%, #d97706 50%, #f59e0b 100%);">
                    <div class="absolute -top-4 -right-4 w-24 h-24 rounded-full opacity-20" style="background:rgba(255,255,255,0.4);"></div>
                    <div class="absolute -bottom-6 left-8 w-16 h-16 rounded-full opacity-10" style="background:rgba(255,255,255,0.5);"></div>

                    <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="relative flex-shrink-0">
                                <span class="absolute inline-flex h-12 w-12 rounded-full bg-white opacity-30 animate-ping"></span>
                                <div class="relative w-12 h-12 bg-white bg-opacity-25 border-2 border-white border-opacity-40 rounded-xl flex items-center justify-center shadow">
                                    <i class="fas fa-hourglass-half text-white text-xl"></i>
                                </div>
                            </div>
                            <div>
                                <p class="text-white font-bold text-lg leading-tight">
                                    <?= $stats['pending'] ?> application<?= $stats['pending'] !== 1 ? 's' : '' ?> need<?= $stats['pending'] === 1 ? 's' : '' ?> your review
                                </p>
                                <p class="text-amber-100 text-sm mt-0.5">
                                    These are awaiting approval or rejection — don't leave applicants waiting.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <a href="?status=pending&sort=waiting"
                               class="inline-flex items-center gap-2 px-5 py-2.5 bg-white text-amber-700 font-bold text-sm rounded-xl shadow hover:shadow-md hover:-translate-y-0.5 transition-all">
                                <i class="fas fa-list-check"></i> Review Now
                            </a>
                            <button onclick="document.getElementById('pendingAlertBanner').remove()"
                                    class="w-9 h-9 flex items-center justify-center bg-white bg-opacity-20 hover:bg-opacity-30 text-white rounded-xl transition-all"
                                    title="Dismiss">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <?php
                // Build a human-readable label for the active date/filter context
                $stats_context_parts = [];
                if (!empty($date_from) || !empty($date_to)) {
                    if ($activePreset === 'today') {
                        $stats_context_parts[] = '<i class="fas fa-calendar-day mr-1"></i>Today (' . date('d M Y') . ')';
                    } elseif ($activePreset === 'week') {
                        $stats_context_parts[] = '<i class="fas fa-calendar-week mr-1"></i>This Week';
                    } elseif ($activePreset === 'month') {
                        $stats_context_parts[] = '<i class="fas fa-calendar-alt mr-1"></i>This Month (' . date('F Y') . ')';
                    } elseif ($activePreset === 'last_month') {
                        $stats_context_parts[] = '<i class="fas fa-history mr-1"></i>Last Month';
                    } else {
                        $from_label = $date_from ? date('d M Y', strtotime($date_from)) : '…';
                        $to_label   = $date_to   ? date('d M Y', strtotime($date_to))   : '…';
                        $stats_context_parts[] = '<i class="fas fa-filter mr-1"></i>' . $from_label . ' → ' . $to_label;
                    }
                }
                if ($user_type_filter !== 'all') {
                    $stats_context_parts[] = '<i class="fas fa-users mr-1"></i>' . ucfirst($user_type_filter) . 's only';
                }
                if (!empty($search_query)) {
                    $stats_context_parts[] = '<i class="fas fa-search mr-1"></i>"' . htmlspecialchars($search_query) . '"';
                }
            ?>
            <?php if (!empty($stats_context_parts)): ?>
            <div class="flex items-center gap-2 mb-3 -mt-4 flex-wrap">
                <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Stats for:</span>
                <?php foreach ($stats_context_parts as $part): ?>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-200">
                        <?= $part ?>
                    </span>
                <?php endforeach; ?>
                <a href="?status=<?= urlencode($status_filter) ?>&user_type=<?= urlencode($user_type_filter) ?>&sort=<?= urlencode($sort) ?>"
                   class="text-xs text-gray-400 hover:text-red-500 transition-colors ml-1">
                    <i class="fas fa-times-circle mr-0.5"></i>clear
                </a>
            </div>
            <?php endif; ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7 gap-4 mb-8">
                <!-- Total -->
                <div class="stat-card stat-total animate-slide-left delay-100">
                    <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Total</div>
                        <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
                        <div class="stat-sub">All applications</div>
                    </div>
                </div>
                
                <!-- Pending -->
                <div class="stat-card stat-pending animate-slide-left delay-200">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Pending</div>
                        <div class="stat-number"><?= $stats['pending'] ?? 0 ?></div>
                        <div class="stat-sub">Awaiting review</div>
                    </div>
                </div>
                
                <!-- Approved -->
                <div class="stat-card stat-approved animate-slide-left delay-300">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Approved</div>
                        <div class="stat-number"><?= $stats['approved'] ?? 0 ?></div>
                        <div class="stat-sub">Granted</div>
                    </div>
                </div>
                
                <!-- Rejected -->
                <div class="stat-card stat-rejected animate-slide-left delay-400">
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Rejected</div>
                        <div class="stat-number"><?= $stats['rejected'] ?? 0 ?></div>
                        <div class="stat-sub">Declined</div>
                    </div>
                </div>
                
                <!-- Cancelled -->
                <div class="stat-card stat-cancelled animate-slide-left delay-500">
                    <div class="stat-icon"><i class="fas fa-ban"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Cancelled</div>
                        <div class="stat-number"><?= $stats['cancelled'] ?? 0 ?></div>
                        <div class="stat-sub">Withdrawn</div>
                    </div>
                </div>
                
                <!-- Students -->
                <div class="stat-card stat-students animate-slide-left delay-100">
                    <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Students</div>
                        <div class="stat-number"><?= $stats['student_applications'] ?? 0 ?></div>
                        <div class="stat-sub">Student leaves</div>
                    </div>
                </div>
                
                <!-- Trainers -->
                <div class="stat-card stat-trainers animate-slide-left delay-200">
                    <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Trainers</div>
                        <div class="stat-number"><?= $stats['trainer_applications'] ?? 0 ?></div>
                        <div class="stat-sub">Trainer leaves</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card p-6 mb-8 animate-fade-up">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select name="status" class="form-select w-full" onchange="this.form.submit()">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">User Type</label>
                        <select name="user_type" class="form-select w-full" onchange="this.form.submit()">
                            <option value="all" <?= $user_type_filter === 'all' ? 'selected' : '' ?>>All Users</option>
                            <option value="student" <?= $user_type_filter === 'student' ? 'selected' : '' ?>>Students Only</option>
                            <option value="trainer" <?= $user_type_filter === 'trainer' ? 'selected' : '' ?>>Trainers Only</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Name, Email, Application No..." class="form-input w-full">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                        <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input w-full">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                        <div class="flex gap-2" style="min-width:0">
                            <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input" style="min-width:0;flex:1 1 0%">
                            <button type="submit" class="btn-primary text-white rounded-lg flex-shrink-0" style="width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;padding:0">
                                <i class="fas fa-search"></i>
                            </button>
                            <a href="leave_management.php" class="btn-secondary text-white rounded-lg flex-shrink-0" style="width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;padding:0">
                                <i class="fas fa-sync-alt"></i>
                            </a>
                        </div>
                    </div>
                </form>

                <!-- Quick date presets (outside the 5-col grid, full width) -->
                <div class="mt-4 pt-4 border-t border-gray-200 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold text-gray-600 mr-1">
                        <i class="fas fa-bolt mr-1"></i>Quick date:
                    </span>

                    <button type="button"
                            onclick="applyPreset('today')"
                            class="preset-btn <?= $activePreset === 'today' ? 'active-preset' : '' ?>">
                        <i class="fas fa-calendar-day"></i> Today
                    </button>

                    <button type="button"
                            onclick="applyPreset('week')"
                            class="preset-btn <?= $activePreset === 'week' ? 'active-preset' : '' ?>">
                        <i class="fas fa-calendar-week"></i> This Week
                    </button>

                    <button type="button"
                            onclick="applyPreset('month')"
                            class="preset-btn <?= $activePreset === 'month' ? 'active-preset' : '' ?>">
                        <i class="fas fa-calendar-alt"></i> This Month
                    </button>

                    <button type="button"
                            onclick="applyPreset('last_month')"
                            class="preset-btn <?= $activePreset === 'last_month' ? 'active-preset' : '' ?>">
                        <i class="fas fa-history"></i> Last Month
                    </button>

                    <?php if ($date_from || $date_to): ?>
                    <a href="?status=<?= urlencode($status_filter) ?>&user_type=<?= urlencode($user_type_filter) ?>&search=<?= urlencode($search_query) ?>&sort=<?= urlencode($sort) ?>"
                       class="ml-2 text-xs font-semibold text-red-400 hover:text-red-600 transition-colors flex items-center gap-1">
                        <i class="fas fa-times-circle"></i> Clear dates
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sort controls + Result count -->
            <?php
                // Build base URL preserving all current filters except sort
                $base_params = http_build_query(array_filter([
                    'status'    => $status_filter,
                    'user_type' => $user_type_filter,
                    'search'    => $search_query,
                    'date_from' => $date_from,
                    'date_to'   => $date_to,
                ]));
                $total_filtered  = count($applications);
                $total_all       = $stats['total'] ?? 0;
            ?>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">

                <!-- Result count -->
                <p class="text-sm text-gray-600">
                    Showing
                    <span class="font-bold text-gray-900"><?= $total_filtered ?></span>
                    of
                    <span class="font-bold text-gray-900"><?= $total_all ?></span>
                    application<?= $total_all !== 1 ? 's' : '' ?>
                    <?php if ($status_filter !== 'all'): ?>
                        <span class="ml-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold"><?= ucfirst($status_filter) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($search_query)): ?>
                        <span class="ml-1 text-gray-400">· "<em><?= htmlspecialchars($search_query) ?></em>"</span>
                    <?php endif; ?>
                </p>

                <!-- Sort buttons + Export -->
                <div class="flex flex-wrap gap-2 items-center">
                    <span class="text-xs text-gray-400 font-semibold uppercase tracking-wide mr-1">Sort:</span>
                    <?php
                    // Newest/Oldest toggle: clicking toggles between the two
                    $isDateActive = in_array($sort, ['newest', 'oldest']);
                    $toggleTarget = ($sort === 'newest') ? 'oldest' : 'newest';
                    $toggleLabel  = ($sort === 'oldest') ? 'Oldest' : 'Newest';
                    $toggleIcon   = ($sort === 'oldest') ? 'fa-arrow-up-short-wide' : 'fa-arrow-down-short-wide';
                    $dateCls      = $isDateActive ? 'sort-btn active' : 'sort-btn';
                    ?>
                    <a href="?<?= $base_params ?>&sort=<?= $toggleTarget ?>" class="<?= $dateCls ?>">
                        <i class="fas <?= $toggleIcon ?>"></i> <?= $toggleLabel ?>
                    </a>
                    <?php
                    $sorts = [
                        'longest' => ['label' => 'Longest Leave',    'icon' => 'fa-calendar-week'],
                        'waiting' => ['label' => 'Most Days Waiting', 'icon' => 'fa-hourglass-half'],
                    ];
                    foreach ($sorts as $key => $s):
                        $isActive = $sort === $key;
                        $cls = $isActive ? ($key === 'waiting' ? 'sort-btn active-waiting' : 'sort-btn active') : 'sort-btn';
                    ?>
                    <a href="?<?= $base_params ?>&sort=<?= $key ?>" class="<?= $cls ?>">
                        <i class="fas <?= $s['icon'] ?>"></i> <?= $s['label'] ?>
                    </a>
                    <?php endforeach; ?>

                    <!-- Export to CSV -->
                    <?php
                        $csv_params = http_build_query(array_filter([
                            'status'    => $status_filter,
                            'user_type' => $user_type_filter,
                            'search'    => $search_query,
                            'date_from' => $date_from,
                            'date_to'   => $date_to,
                            'sort'      => $sort,
                            'export'    => 'csv',
                        ]));
                    ?>
                    <a href="?<?= $csv_params ?>"
                       id="exportCsvBtn"
                       title="Download the currently filtered list as a CSV file"
                       class="sort-btn export-csv-btn <?= empty($applications) ? 'opacity-50 pointer-events-none' : '' ?>">
                        <i class="fas fa-file-csv"></i> Export CSV
                        <span class="export-count">(<?= $total_filtered ?>)</span>
                    </a>

                    <a href="leave_calendar.php?month=<?= date('n') ?>&year=<?= date('Y') ?>"
                       title="View approved leaves on a monthly calendar"
                       class="sort-btn">
                        <i class="fas fa-calendar-week"></i> Calendar View
                    </a>
                </div>
            </div>

            <!-- Applications List -->
            <div class="space-y-4">
                <?php if (empty($applications)): ?>
                    <div class="rounded-2xl shadow-lg p-12 text-center" style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe);">
                        <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-calendar-times text-gray-400 text-4xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">No Applications Found</h3>
                        <p class="text-gray-500">No leave applications match your filter criteria.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($applications as $index => $app): ?>
                        <div class="application-card p-6" style="animation: fadeInUp 0.5s ease-out forwards; animation-delay: <?= $index * 0.05 ?>s; border-left: 5px solid <?= $app['status'] === 'approved' ? '#10b981' : ($app['status'] === 'rejected' ? '#ef4444' : ($app['status'] === 'cancelled' ? '#6b7280' : '#f59e0b')) ?>; background: <?= $app['status'] === 'approved' ? 'linear-gradient(to right, #f0fdf4, #ffffff)' : ($app['status'] === 'rejected' ? 'linear-gradient(to right, #fff5f5, #ffffff)' : ($app['status'] === 'cancelled' ? 'linear-gradient(to right, #f9fafb, #ffffff)' : 'linear-gradient(to right, #fffbeb, #ffffff)')) ?>">
                            <div class="flex flex-col lg:flex-row lg:items-start justify-between">
                                <!-- Application Info -->
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center gap-3 mb-4">
                                        <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($app['application_no']) ?></h3>
                                        <span class="status-badge <?= 
                                            $app['status'] === 'approved' ? 'status-approved' : 
                                            ($app['status'] === 'rejected' ? 'status-rejected' : 
                                            ($app['status'] === 'cancelled' ? 'status-cancelled' : 'status-pending')) ?>">
                                            <i class="fas <?= $app['status'] === 'approved' ? 'fa-check-circle' : ($app['status'] === 'rejected' ? 'fa-times-circle' : ($app['status'] === 'cancelled' ? 'fa-ban' : 'fa-clock')) ?> mr-1"></i>
                                            <?= ucfirst($app['status']) ?>
                                        </span>
                                        <span class="user-badge <?= $app['applicant_type'] === 'student' ? 'user-student' : 'user-trainer' ?>">
                                            <i class="fas <?= $app['applicant_type'] === 'student' ? 'fa-user-graduate' : 'fa-chalkboard-teacher' ?> mr-1"></i>
                                            <?= ucfirst($app['applicant_type']) ?>
                                        </span>
                                        <?php
                                            if ($app['status'] === 'pending') {
                                                $submitted = new DateTime($app['created_at']);
                                                $now       = new DateTime();
                                                $daysWaiting = (int)$submitted->diff($now)->days;
                                                if ($daysWaiting >= 3):
                                        ?>
                                        <span class="waiting-badge">
                                            <i class="fas fa-hourglass-half"></i>
                                            Waiting <?= $daysWaiting ?> day<?= $daysWaiting !== 1 ? 's' : '' ?>
                                        </span>
                                        <?php endif; } ?>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                        <div>
                                            <p class="text-xs text-gray-500">Applicant Name</p>
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($app['applicant_full_name'] ?? $app['student_name']) ?></p>
                                            <p class="text-xs text-gray-500">ID: <?= htmlspecialchars($app['student_id']) ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Email</p>
                                            <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($app['applicant_email'] ?? $app['email']) ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Phone</p>
                                            <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($app['applicant_phone'] ?? 'N/A') ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Batch</p>
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($app['batch_title'] ?? $app['batch_id']) ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Leave Period</p>
                                            <p class="font-semibold text-gray-800">
                                                <?= date('d M Y', strtotime($app['start_date'])) ?> - <?= date('d M Y', strtotime($app['end_date'])) ?>
                                            </p>
                                            <?php
                                                $days = (int)$app['total_days'];
                                                if ($days <= 2) {
                                                    $pillClass = 'duration-short';
                                                    $pillIcon  = 'fa-leaf';
                                                } elseif ($days <= 5) {
                                                    $pillClass = 'duration-medium';
                                                    $pillIcon  = 'fa-sun';
                                                } else {
                                                    $pillClass = 'duration-long';
                                                    $pillIcon  = 'fa-fire';
                                                }
                                            ?>
                                            <span class="duration-pill <?= $pillClass ?>">
                                                <i class="fas <?= $pillIcon ?>"></i>
                                                <?= $days ?> day<?= $days !== 1 ? 's' : '' ?>
                                            </span>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Reason Category</p>
                                            <p class="font-semibold text-gray-800"><?= htmlspecialchars($app['reason_category']) ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-xs text-gray-500">Applied On</p>
                                            <p class="font-semibold text-gray-800"><?= date('d M Y', strtotime($app['created_at'])) ?></p>
                                            <p class="text-xs text-gray-500"><?= date('h:i A', strtotime($app['created_at'])) ?></p>
                                        </div>
                                    </div>
                                    
                                    <!-- Detailed Reason -->
                                    <div class="mt-4 p-3 rounded-lg" style="background: linear-gradient(135deg, #f0f5f9, #e8edf5); border-left: 3px solid #456882;">
                                        <p class="text-xs text-gray-500 mb-1">Reason:</p>
                                        <p class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($app['reason_detail'])) ?></p>
                                    </div>
                                    
                                    <?php if ($app['status'] === 'rejected' && !empty($app['rejection_reason'])): ?>
                                        <div class="mt-3 p-3 bg-red-50 rounded-lg border-l-4 border-red-500">
                                            <p class="text-xs text-red-800 font-medium">Rejection Reason:</p>
                                            <p class="text-sm text-red-700"><?= htmlspecialchars($app['rejection_reason']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($app['status'] === 'approved' && !empty($app['admin_remarks'])): ?>
                                        <div class="mt-3 p-3 bg-green-50 rounded-lg border-l-4 border-green-500">
                                            <p class="text-xs text-green-800 font-medium">Admin Remarks:</p>
                                            <p class="text-sm text-green-700"><?= htmlspecialchars($app['admin_remarks']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="mt-4 lg:mt-0 lg:ml-6 flex flex-col space-y-2 min-w-[200px]">
                                    <a href="view_application.php?id=<?= $app['id'] ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all text-center">
                                        <i class="fas fa-eye mr-2"></i> View Details
                                    </a>
                                    <?php if ($app['status'] === 'pending'): ?>
                                        <a href="accept_leave.php?id=<?= $app['id'] ?>" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all text-center">
                                            <i class="fas fa-check mr-2"></i> Approve
                                        </a>
                                        <a href="reject_leave.php?id=<?= $app['id'] ?>" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all text-center">
                                            <i class="fas fa-times mr-2"></i> Reject
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // ── Quick date preset helper ──────────────────────────────────────────
        function applyPreset(type) {
            const now  = new Date();
            let from, to;

            function fmt(d) {
                // Returns YYYY-MM-DD in LOCAL time (avoids UTC off-by-one)
                const y  = d.getFullYear();
                const m  = String(d.getMonth() + 1).padStart(2, '0');
                const dy = String(d.getDate()).padStart(2, '0');
                return `${y}-${m}-${dy}`;
            }

            if (type === 'today') {
                from = to = fmt(now);

            } else if (type === 'week') {
                // ISO week: Monday → Sunday
                const dow = now.getDay();                  // 0=Sun … 6=Sat
                const diffToMon = (dow === 0) ? -6 : 1 - dow;
                const mon = new Date(now);
                mon.setDate(now.getDate() + diffToMon);
                const sun = new Date(mon);
                sun.setDate(mon.getDate() + 6);
                from = fmt(mon);
                to   = fmt(sun);

            } else if (type === 'month') {
                from = fmt(new Date(now.getFullYear(), now.getMonth(), 1));
                to   = fmt(new Date(now.getFullYear(), now.getMonth() + 1, 0));

            } else if (type === 'last_month') {
                from = fmt(new Date(now.getFullYear(), now.getMonth() - 1, 1));
                to   = fmt(new Date(now.getFullYear(), now.getMonth(), 0));
            }

            document.querySelector('input[name="date_from"]').value = from;
            document.querySelector('input[name="date_to"]').value   = to;

            // Submit the filter form so the page reloads with the chosen range
            document.querySelector('form[method="GET"]').submit();
        }
        // ─────────────────────────────────────────────────────────────────────

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-20 right-4 p-4 rounded-lg shadow-lg text-white z-50 transform transition-all duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500'}`;
            toast.style.minWidth = '300px';
            toast.innerHTML = `<div class="flex items-center"><i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2 text-xl"></i><span>${message}</span></div>`;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('translate-x-0', 'opacity-100'), 10);
            setTimeout(() => {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('success')) {
                showToast(urlParams.get('message') || 'Operation completed successfully!', 'success');
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            if (urlParams.has('error')) {
                showToast(urlParams.get('message') || 'An error occurred!', 'error');
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            // CSV export feedback
            const csvBtn = document.getElementById('exportCsvBtn');
            if (csvBtn && !csvBtn.classList.contains('pointer-events-none')) {
                csvBtn.addEventListener('click', function() {
                    showToast('Preparing CSV download…', 'info');
                });
            }
        });
    </script>
</body>
</html>