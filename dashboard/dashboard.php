<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}

// Get current month and last month dates
$currentMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));

// Prepare all database queries with parameterized statements to prevent SQL injection
// Running Batches
$stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE status = 'ongoing'");
$stmt->execute();
$running_batches = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE status = 'ongoing' AND 
                     (start_date <= :lastMonthEnd AND (end_date >= :lastMonthStart OR end_date IS NULL))");
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->bindParam(':lastMonthStart', $lastMonthStart);
$stmt->execute();
$last_month_running = $stmt->fetchColumn();
$running_diff = $running_batches - $last_month_running;

// Upcoming Batches
$stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE status = 'upcoming'");
$stmt->execute();
$upcoming_batches = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM batches WHERE status = 'upcoming' AND 
                     created_at BETWEEN :lastMonthStart AND :lastMonthEnd");
$stmt->bindParam(':lastMonthStart', $lastMonthStart);
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->execute();
$last_month_upcoming = $stmt->fetchColumn();
$upcoming_diff = $upcoming_batches - $last_month_upcoming;

// Total Enrolled Students
$stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE current_status = 'active'");
$stmt->execute();
$total_students = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE current_status = 'active' AND 
                     enrollment_date <= :lastMonthEnd");
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->execute();
$last_month_students = $stmt->fetchColumn();
$students_diff = $total_students - $last_month_students;

// Classes Occurred
$stmt = $db->prepare("SELECT COUNT(DISTINCT date) FROM attendance");
$stmt->execute();
$classes_occurred = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(DISTINCT date) FROM attendance WHERE date BETWEEN :lastMonthStart AND :lastMonthEnd");
$stmt->bindParam(':lastMonthStart', $lastMonthStart);
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->execute();
$last_month_classes = $stmt->fetchColumn();
$classes_diff = $classes_occurred - $last_month_classes;

// Upcoming Live Classes
$stmt = $db->prepare("
    SELECT schedule_date, start_time, end_time, topic, batch_id 
    FROM schedule 
    WHERE schedule_date >= CURDATE() AND is_cancelled = 0 
    ORDER BY schedule_date ASC, start_time ASC 
    LIMIT 5
");
$stmt->execute();
$upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Absentees
$stmt = $db->prepare("
    SELECT student_name, date, batch_id 
    FROM attendance 
    WHERE status = 'Absent' 
    ORDER BY date DESC 
    LIMIT 2
");
$stmt->execute();
$recent_absentees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unaddressed Feedbacks
$stmt = $db->prepare("
    SELECT COUNT(*) FROM feedback 
    WHERE action_taken IS NULL OR action_taken = ''
");
$stmt->execute();
$unaddressed_feedbacks = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(*) FROM feedback 
    WHERE (action_taken IS NULL OR action_taken = '') AND date <= :lastMonthEnd
");
$stmt->bindParam(':lastMonthEnd', $lastMonthEnd);
$stmt->execute();
$last_month_feedbacks = $stmt->fetchColumn();
$feedbacks_diff = $unaddressed_feedbacks - $last_month_feedbacks;

// ==================== LEAVE INFORMATION ====================
$stmt = $db->prepare("SELECT COUNT(*) FROM leave_applications WHERE status = 'pending'");
$stmt->execute();
$pending_leaves = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM leave_applications WHERE status = 'approved' AND approved_at >= :monthStart");
$stmt->bindParam(':monthStart', $currentMonthStart);
$stmt->execute();
$approved_leaves_month = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM leave_applications WHERE status IN ('approved', 'pending')");
$stmt->execute();
$total_leaves = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT l.id, l.application_no, l.student_name, l.batch_id, l.start_date, l.end_date, l.status, l.created_at
    FROM leave_applications l
    WHERE l.status IN ('pending', 'approved')
    ORDER BY l.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== PAYMENT INFORMATION ====================
$stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
$stmt->execute();
$pending_payments = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(*) FROM transactions 
    WHERE status = 'verified' AND verified_at >= :monthStart
");
$stmt->bindParam(':monthStart', $currentMonthStart);
$stmt->execute();
$verified_payments_month = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT SUM(amount) FROM transactions 
    WHERE status = 'verified' AND verified_at >= :monthStart
");
$stmt->bindParam(':monthStart', $currentMonthStart);
$stmt->execute();
$monthly_revenue = $stmt->fetchColumn() ?: 0;

$stmt = $db->prepare("SELECT SUM(amount) FROM transactions WHERE status = 'verified'");
$stmt->execute();
$total_revenue = $stmt->fetchColumn() ?: 0;

$stmt = $db->prepare("
    SELECT t.id, t.transaction_id, t.student_name, t.batch_id, t.amount, t.transaction_date, t.status, t.uploaded_at
    FROM transactions t
    WHERE t.status IN ('pending', 'verified')
    ORDER BY t.uploaded_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT 
        SUM(CASE WHEN fees_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid,
        SUM(CASE WHEN fees_status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid,
        SUM(CASE WHEN fees_status = 'fully_paid' THEN 1 ELSE 0 END) as fully_paid,
        SUM(CASE WHEN fees_status = 'overdue' THEN 1 ELSE 0 END) as overdue
    FROM students 
    WHERE current_status = 'active'
");
$stmt->execute();
$fee_status_summary = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE fees_status = 'overdue' AND current_status = 'active'");
$stmt->execute();
$overdue_payments = $stmt->fetchColumn();

// Recent Messages
$stmt = $db->prepare("
    SELECT m.message, m.created_at as sent_at, u.name as sender_name, m.conversation_id
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.conversation_id IN (
        SELECT conversation_id FROM conversation_members 
        WHERE user_id = :user_id AND is_active = TRUE
    )
    ORDER BY m.created_at DESC 
    LIMIT 3
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$recent_msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread notifications count
$stmt = $db->prepare("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = :user_id AND is_read = 0
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$unread_notifications = $stmt->fetchColumn();

// Pending feedback notifications
$stmt = $db->prepare("
    SELECT f.id, f.student_name, f.batch_id, f.feedback_text, f.date
    FROM feedback f
    WHERE (f.action_taken IS NULL OR f.action_taken = '')
    ORDER BY f.date DESC
    LIMIT 5
");
$stmt->execute();
$pending_feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages
$stmt = $db->prepare("
    SELECT m.id, m.message, m.created_at as sent_at, u.name as sender_name, m.conversation_id
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.conversation_id IN (
        SELECT conversation_id FROM conversation_members 
        WHERE user_id = :user_id AND is_active = TRUE
    )
    AND m.sender_id != :current_user_id
    AND m.is_read = 0
    ORDER BY m.created_at DESC
    LIMIT 5
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->bindParam(':current_user_id', $_SESSION['user_id']);
$stmt->execute();
$unread_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch status data for statistics cards
$ongoing_batches = $db->query("SELECT COUNT(*) FROM batches WHERE status='ongoing'")->fetchColumn();
$upcoming_batches_count = $db->query("SELECT COUNT(*) FROM batches WHERE status='upcoming'")->fetchColumn();
$completed_batches = $db->query("SELECT COUNT(*) FROM batches WHERE status='completed'")->fetchColumn();
$cancelled_batches = $db->query("SELECT COUNT(*) FROM batches WHERE status='cancelled'")->fetchColumn();
$total_batches = $ongoing_batches + $upcoming_batches_count + $completed_batches + $cancelled_batches;

// Check for new notifications since last visit
$last_notification_check = $_SESSION['last_notification_check'] ?? 0;
$stmt = $db->prepare("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = :user_id AND is_read = 0 AND created_at > FROM_UNIXTIME(:last_check)
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->bindParam(':last_check', $last_notification_check);
$stmt->execute();
$new_notifications_count = $stmt->fetchColumn();

$_SESSION['last_notification_check'] = time();
$play_notification_sound = $new_notifications_count > 0;

// Success messages
if (isset($_GET['success'])) {
    $success_messages = [
        'batch_created' => "Batch created successfully!",
        'notification_marked' => "Notifications marked as read!",
        'payment_verified' => "Payment verified successfully!",
        'leave_approved' => "Leave application approved!"
    ];
    
    if (array_key_exists($_GET['success'], $success_messages)) {
        $success_message = htmlspecialchars($success_messages[$_GET['success']]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary:    #1B3C53;
            --secondary:  #234C6A;
            --accent:     #456882;
            --neutral:    #D2C1B6;
            --bg:         #F2EDE9;
            --surface:    #FDFAF8;
            --text-head:  #1B3C53;
            --text-body:  #3A5068;
            --text-muted: #6A8EA3;
            --border:     rgba(210,193,182,0.50);
            --shadow-sm:  0 1px 4px rgba(27,60,83,0.08);
            --shadow-md:  0 4px 16px rgba(27,60,83,0.12);
            --shadow-lg:  0 12px 40px rgba(27,60,83,0.16);
            --shadow-xl:  0 24px 64px rgba(27,60,83,0.18);
            --radius-sm:  8px;
            --radius-md:  12px;
            --radius-lg:  18px;
            --radius-xl:  24px;
            /* Status */
            --ongoing-bg:   rgba(34,197,94,0.1);
            --ongoing-clr:  #15803d;
            --upcoming-bg:  rgba(234,179,8,0.12);
            --upcoming-clr: #a16207;
            --completed-bg: rgba(69,104,130,0.12);
            --completed-clr:#234C6A;
            --cancelled-bg: rgba(239,68,68,0.1);
            --cancelled-clr:#b91c1c;
            --pending-bg:   rgba(234,179,8,0.12);
            --pending-clr:  #a16207;
            --approved-bg:  rgba(34,197,94,0.1);
            --approved-clr: #15803d;
            --verified-bg:  rgba(34,197,94,0.1);
            --verified-clr: #15803d;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            color: var(--text-body);
            -webkit-font-smoothing: antialiased;
        }

        /* ── SCROLLBAR ── */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 99px; }

        /* ── LAYOUT ── */
        .main-content {
            margin-left: 16rem;
            min-height: 100vh;
        }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }

        /* ── TOP BAR ── */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(242,237,233,0.92);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            padding: 0 28px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar-icon {
            width: 40px; height: 40px;
            border-radius: var(--radius-md);
            background: var(--primary);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1rem;
            flex-shrink: 0;
        }
        .topbar-title { font-size: 1.15rem; font-weight: 700; color: var(--text-head); line-height: 1.2; }
        .topbar-sub { font-size: 0.78rem; color: var(--text-muted); margin-top: 1px; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .mobile-menu-btn {
            display: none; background: none; border: none; cursor: pointer;
            color: var(--text-head); font-size: 1.2rem; padding: 6px;
        }
        @media (max-width: 768px) { .mobile-menu-btn { display: flex; } }

        /* ── NOTIFICATION BELL ── */
        .notif-btn {
            position: relative; background: none; border: none;
            width: 40px; height: 40px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--text-head);
            transition: background .2s;
        }
        .notif-btn:hover { background: rgba(27,60,83,0.07); }
        .notif-badge {
            position: absolute; top: 5px; right: 5px;
            background: #ef4444; color: #fff;
            border-radius: 99px; min-width: 17px; height: 17px;
            font-size: 10px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            padding: 0 3px;
            animation: badge-pop .3s ease;
        }
        @keyframes badge-pop {
            from { transform: scale(0); }
            to   { transform: scale(1); }
        }
        .pulse-ring {
            animation: pulse-r 1.8s ease-in-out infinite;
        }
        @keyframes pulse-r {
            0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
            50%      { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
        }

        /* ── NOTIFICATION DROPDOWN ── */
        #notificationDropdown {
            position: absolute; right: 0; top: calc(100% + 8px);
            width: 360px; max-width: 90vw;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            transform-origin: top right;
            transition: opacity .2s, transform .2s;
            opacity: 0; transform: scale(.95) translateY(-8px);
            visibility: hidden;
            z-index: 200;
        }
        #notificationDropdown.show {
            opacity: 1; transform: scale(1) translateY(0);
            visibility: visible;
        }
        .notif-header {
            padding: 16px 18px 12px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .notif-header-title {
            font-weight: 700; font-size: 0.9rem; color: var(--text-head);
            display: flex; align-items: center; gap: 8px;
        }
        .notif-mark-btn {
            font-size: 0.72rem; color: var(--accent); background: none;
            border: 1px solid var(--border); padding: 4px 10px;
            border-radius: 99px; cursor: pointer; font-weight: 500;
            transition: background .2s;
        }
        .notif-mark-btn:hover { background: var(--bg); }
        .notif-list { max-height: 340px; overflow-y: auto; }
        .notif-item {
            display: flex; gap: 12px; padding: 13px 18px;
            border-bottom: 1px solid var(--border);
            transition: background .15s; cursor: pointer;
        }
        .notif-item:hover { background: var(--bg); }
        .notif-item:last-child { border-bottom: none; }
        .notif-item-icon {
            width: 36px; height: 36px; border-radius: var(--radius-sm);
            flex-shrink: 0; display: flex; align-items: center; justify-content: center;
            font-size: .85rem;
        }
        .notif-item-icon.danger  { background: rgba(239,68,68,.1);  color: #ef4444; }
        .notif-item-icon.info    { background: rgba(69,104,130,.1); color: var(--accent); }
        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title { font-size: .82rem; font-weight: 600; color: var(--text-head); }
        .notif-item-sub { font-size: .75rem; color: var(--text-muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-action-tag {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: .68rem; font-weight: 600; color: #ef4444;
            background: rgba(239,68,68,.08); padding: 2px 8px;
            border-radius: 99px; margin-top: 5px;
        }
        .notif-time { font-size: .7rem; color: var(--text-muted); white-space: nowrap; flex-shrink: 0; align-self: flex-start; margin-top: 1px; }
        .notif-footer {
            padding: 12px 18px; border-top: 1px solid var(--border);
            text-align: center;
        }
        .notif-footer a {
            font-size: .8rem; font-weight: 600; color: var(--accent);
            text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
        }
        .notif-footer a:hover { color: var(--primary); }
        .notif-empty {
            padding: 40px 20px; text-align: center; color: var(--text-muted);
        }
        .notif-empty i { font-size: 2rem; margin-bottom: 10px; display: block; }
        .notif-empty p { font-size: .82rem; }

        /* ── LOGOUT BTN ── */
        .logout-btn {
            display: inline-flex; align-items: center; gap: 7px;
            background: var(--primary); color: #fff;
            border: none; padding: 8px 16px; border-radius: var(--radius-md);
            font-size: .82rem; font-weight: 600; cursor: pointer;
            text-decoration: none; transition: background .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 2px 8px rgba(27,60,83,.2);
        }
        .logout-btn:hover { background: var(--secondary); transform: translateY(-1px); box-shadow: var(--shadow-md); }

        /* ── PAGE BODY ── */
        .page-body { padding: 28px; }
        @media (max-width: 640px) { .page-body { padding: 16px; } }

        /* ── SUCCESS ALERT ── */
        .alert-success-bar {
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: #fff;
            border-radius: var(--radius-md);
            padding: 14px 18px;
            margin-bottom: 24px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 4px 16px rgba(22,163,74,.25);
            animation: slide-down .4s ease;
        }
        @keyframes slide-down {
            from { opacity: 0; transform: translateY(-12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .alert-success-bar .icon-wrap {
            width: 36px; height: 36px; background: rgba(255,255,255,.2);
            border-radius: var(--radius-sm); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .alert-success-bar .msg-title { font-weight: 700; font-size: .88rem; }
        .alert-success-bar .msg-sub   { font-size: .78rem; opacity: .85; }

        /* ── KPI CARDS ── */
         .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px)  { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px)  { .kpi-grid { grid-template-columns: 1fr; } }

        .kpi-card {
            background: var(--primary);
            border-radius: var(--radius-lg);
            padding: 22px 20px;
            text-decoration: none;
            position: relative;
            overflow: hidden;
            transition: transform .25s, box-shadow .25s;
            box-shadow: var(--shadow-md);
            display: block;
        }
        .kpi-card::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,.10) 0%, rgba(210,193,182,.08) 100%);
            pointer-events: none;
        }
        .kpi-card::after {
            content: '';
            position: absolute; bottom: -20px; right: -20px;
            width: 90px; height: 90px;
            border-radius: 50%;
            background: rgba(210,193,182,.08);
            pointer-events: none;
        }
        .kpi-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-xl); }

        .kpi-card.var-2 { background: var(--secondary); }
        .kpi-card.var-3 { background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%); }
        .kpi-card.var-4 { background: linear-gradient(135deg, #234C6A 0%, #345E80 100%); }
        .kpi-card.var-5 { background: linear-gradient(135deg, #456882 0%, #1B3C53 100%); }

        .kpi-top {
            display: flex; align-items: flex-start;
            justify-content: space-between; margin-bottom: 14px;
        }
        .kpi-icon-wrap {
            width: 42px; height: 42px; border-radius: var(--radius-md);
            background: rgba(255,255,255,.15);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.05rem;
        }
        .kpi-number {
            font-size: 2.4rem; font-weight: 800;
            color: #fff; line-height: 1; text-align: right;
        }
        .kpi-label { font-size: .78rem; font-weight: 500; color: rgba(255,255,255,.7); }
        .kpi-delta {
            margin-top: 10px; display: flex; align-items: center; gap: 5px;
            font-size: .72rem; font-weight: 600;
        }
        .kpi-delta.up   { color: #86efac; }
        .kpi-delta.down { color: #fca5a5; }
        .kpi-delta.flat { color: rgba(255,255,255,.5); }

        /* ── SECTION HEADER ── */
        .section-hdr {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 18px;
        }
        .section-hdr-left { display: flex; align-items: center; gap: 10px; }
        .section-dot {
            width: 8px; height: 8px; border-radius: 50%;
            flex-shrink: 0;
        }
        .section-title {
            font-size: 1rem; font-weight: 700; color: var(--text-head);
        }
        .section-link {
            font-size: .75rem; font-weight: 600; color: var(--accent);
            text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 99px;
            border: 1px solid var(--border);
            transition: background .2s, color .2s;
        }
        .section-link:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

        /* ── PANEL CARD ── */
        .panel {
            background: linear-gradient(160deg, #FFFFFF 60%, rgba(210,193,182,0.12) 100%);
            border-radius: var(--radius-lg);
            border: 2px solid #1B3C53;
            box-shadow: var(--shadow-sm);
            padding: 22px;
            transition: box-shadow .25s;
        }
        .panel:hover{
    border-color:#234C6A;
    box-shadow:0 8px 24px rgba(27,60,83,.18);
}

        /* ── STATUS BADGES ── */
        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 99px;
            font-size: .7rem; font-weight: 700; letter-spacing: .3px;
            text-transform: capitalize;
        }
        .badge i { font-size: .55rem; }
        .badge-ongoing   { background: var(--ongoing-bg);   color: var(--ongoing-clr); }
        .badge-upcoming  { background: var(--upcoming-bg);  color: var(--upcoming-clr); }
        .badge-completed { background: var(--completed-bg); color: var(--completed-clr); border: 1px solid rgba(69,104,130,0.18); }
        .badge-cancelled { background: var(--cancelled-bg); color: var(--cancelled-clr); }
        .badge-pending   { background: var(--pending-bg);   color: var(--pending-clr); }
        .badge-approved  { background: var(--approved-bg);  color: var(--approved-clr); }
        .badge-verified  { background: var(--verified-bg);  color: var(--verified-clr); }

        /* ── STAT MINI CARDS (leave / payment stats) ── */
        .mini-stats { display: grid; gap: 10px; margin-bottom: 18px; }
        .mini-stats-3 { grid-template-columns: repeat(3, 1fr); }
        .mini-stats-2 { grid-template-columns: repeat(2, 1fr); }
        @media (max-width: 480px) { .mini-stats-3, .mini-stats-2 { grid-template-columns: 1fr 1fr; } }

        .mini-stat {
            border-radius: var(--radius-md);
            padding: 14px 12px; text-align: center;
        }
        .mini-stat.orange { background: rgba(234,179,8,.08); border:2px solid #1B3C53;}
        .mini-stat.green  { background: rgba(34,197,94,.08);  border:2px solid #1B3C53; }
        .mini-stat.blue   { background: rgba(210,193,182,0.20); border:2px solid #1B3C53; }
        .mini-stat.red    { background: rgba(239,68,68,.07);  border:2px solid #1B3C53; }
        .mini-stat .sval  { font-size: 1.6rem; font-weight: 800; line-height: 1; color: var(--text-head); }
        .mini-stat .slbl  { font-size: .7rem; color: var(--text-muted); margin-top: 4px; }

        /* ── TRANSACTION / LEAVE LIST ── */
        .list-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 11px 14px; border-radius: var(--radius-md);
            background: rgba(210,193,182,0.12); margin-bottom: 8px;
            transition: background .15s;
        }
        .list-item:last-child { margin-bottom: 0; }
        .list-item:hover { background: rgba(27,60,83,0.06); border-color: rgba(69,104,130,0.25); }
        .list-item-left { flex: 1; min-width: 0; }
        .list-item-name { font-size: .84rem; font-weight: 600; color: var(--text-head); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .list-item-meta { font-size: .72rem; color: var(--text-muted); margin-top: 3px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .list-item-meta span { display: inline-flex; align-items: center; gap: 3px; }
        .list-item-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .eye-link { color: var(--accent); font-size: .85rem; text-decoration: none; opacity: .7; transition: opacity .15s; }
        .eye-link:hover { opacity: 1; }
        .sub-title {
            font-size: .75rem; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: .5px;
            display: flex; align-items: center; gap: 6px;
            margin-bottom: 10px;
        }

        /* ── REVENUE STRIP ── */
        .revenue-strip {
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;
        }
        .rev-card {
            border-radius: var(--radius-md); padding: 16px 14px; text-align: center;
        }
        .rev-card.green { background: linear-gradient(135deg, rgba(22,163,74,.08), rgba(34,197,94,.05)); border:2px solid #1B3C53; }
        .rev-card.blue  { background: linear-gradient(135deg, rgba(210,193,182,0.20), rgba(210,193,182,0.08)); border: 1px solid rgba(210,193,182,0.50); }
        .rev-card .ramt { font-size: 1.25rem; font-weight: 800; color: var(--text-head); }
        .rev-card .rlbl { font-size: .7rem; color: var(--text-muted); margin-top: 3px; }

        /* ── BATCH STATUS OVERVIEW ── */
        .batch-overview-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
        }
        @media (max-width: 768px) { .batch-overview-grid { grid-template-columns: repeat(2, 1fr); } }

        .batch-stat {
            background: linear-gradient(160deg, #FFFFFF 40%, rgba(210,193,182,0.18) 100%);
            border:2px solid #1B3C53;
            border-radius: var(--radius-md);
            padding: 18px; text-align: center;
            transition: transform .2s, box-shadow .2s;
        }
        .batch-stat:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(27,60,83,0.14); border-color: rgba(69,104,130,0.30); }
        .batch-stat-icon {
            width: 44px; height: 44px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; margin: 0 auto 10px;
        }
        .batch-stat-icon.blue    { background: rgba(27,60,83,.12);  color: var(--primary); border: 1px solid rgba(27,60,83,.08); }
        .batch-stat-icon.amber   { background: rgba(234,179,8,.12); color: #a16207; }
        .batch-stat-icon.green   { background: rgba(34,197,94,.1);  color: #15803d; }
        .batch-stat-icon.rose    { background: rgba(239,68,68,.1);  color: #b91c1c; }
        .batch-stat-num  { font-size: 1.9rem; font-weight: 800; color: var(--text-head); line-height: 1; }
        .batch-stat-lbl  { font-size: .75rem; color: var(--text-muted); margin-top: 4px; font-weight: 500; }
        .batch-stat-pct  {
            font-size: .7rem; color: var(--text-muted); margin-top: 8px;
            padding-top: 8px; border-top: 1px solid var(--border);
        }

        /* ── FEE STATUS ── */
        .fee-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 16px;
        }
        @media (max-width: 640px) { .fee-grid { grid-template-columns: repeat(2, 1fr); } }
        .fee-card {
            background: linear-gradient(135deg, rgba(210,193,182,0.18) 0%, rgba(255,255,255,0.9) 100%);
            border-radius: var(--radius-md);
            border: 1px solid rgba(210,193,182,0.50); padding: 12px; text-align: center;
            transition: border-color .2s;
        }
        .fee-card .fn { font-size: 1.4rem; font-weight: 800; color: var(--text-head); }
        .fee-card .fl { font-size: .7rem; color: var(--text-muted); margin-top: 3px; }

        /* ── QUICK CLASSES / ABSENTEES ── */
        .upcoming-class-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px; border-radius: var(--radius-md);
            background: linear-gradient(135deg, rgba(210,193,182,0.18) 0%, rgba(255,255,255,0.95) 100%);
            margin-bottom: 8px; border: 1px solid rgba(210,193,182,0.48);
            transition: border-color .15s, background .15s;
        }
        .upcoming-class-item:hover { border-color: var(--accent); }
        .class-date-chip {
            flex-shrink: 0; text-align: center; background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff; border-radius: var(--radius-sm);
            padding: 6px 10px; min-width: 44px;
        }
        .class-date-chip .day  { font-size: 1.1rem; font-weight: 800; line-height: 1; }
        .class-date-chip .mon  { font-size: .62rem; font-weight: 500; text-transform: uppercase; opacity: .8; }
        .class-info .topic     { font-size: .84rem; font-weight: 600; color: var(--text-head); }
        .class-info .meta      { font-size: .72rem; color: var(--text-muted); margin-top: 3px; }

        /* ── MESSAGES ── */
        .msg-item {
            display: flex; gap: 10px; padding: 11px;
            border-radius: var(--radius-md);
            background: rgba(210,193,182,0.14);
            margin-bottom: 8px; border: 1px solid rgba(210,193,182,0.42);
        }
        .msg-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--primary)); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: .75rem; font-weight: 700; flex-shrink: 0;
        }
        .msg-text { font-size: .82rem; color: var(--text-muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .msg-sender { font-size: .82rem; font-weight: 600; color: var(--text-head); }
        .msg-time   { font-size: .7rem; color: var(--text-muted); }

        /* ── QUICK ACTIONS ── */
        .quick-actions-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;
        }
        .quick-action {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; padding: 18px 12px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, rgba(210,193,182,0.15) 0%, #FFFFFF 100%);
            border:2px solid #1B3C53;
            text-decoration: none; transition: all .2s;
            position: relative; overflow: hidden;
            cursor: pointer;
        }
        .quick-action::before {
            content: '';
            position: absolute; inset: 0;
            background: var(--primary); opacity: 0;
            transition: opacity .2s;
            pointer-events: none;
        }
        .quick-action:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--accent); }
        .quick-action:hover::before { opacity: .04; }
        .quick-action:hover .qa-icon { background: var(--primary); color: #fff; }
        .qa-icon {
            width: 42px; height: 42px; border-radius: var(--radius-md);
            background: rgba(27,60,83,.10); color: var(--primary);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; margin-bottom: 8px;
            transition: background .2s, color .2s;
        }
        .qa-label { font-size: .75rem; font-weight: 600; color: var(--text-head); text-align: center; }

        /* ── QUICK STATS BAR ── */
        .qs-bar {
            margin-top: 16px; padding-top: 16px;
            border-top: 1px solid var(--border);
            display: flex; flex-direction: column; gap: 8px;
        }
        .qs-row {
            display: flex; justify-content: space-between; align-items: center;
            font-size: .8rem;
        }
        .qs-row .ql { color: var(--text-muted); }
        .qs-row .qv { font-weight: 700; color: var(--text-head); }
        .qs-row .qv.green { color: #15803d; }

        /* ── TWO-COL GRID ── */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        @media (max-width: 960px) { .two-col { grid-template-columns: 1fr; } }

        .three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        @media (max-width: 1100px) { .three-col { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 640px)  { .three-col { grid-template-columns: 1fr; } }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center; padding: 32px 16px; color: var(--text-muted);
        }
        .empty-state i { font-size: 2rem; display: block; margin-bottom: 10px; opacity: .35; }
        .empty-state p { font-size: .8rem; }

        /* ── RIPPLE ── */
        .ripple {
            position: absolute; border-radius: 50%;
            background: rgba(255,255,255,.4); transform: scale(0);
            animation: ripple-anim .6s ease-out; pointer-events: none;
        }
        @keyframes ripple-anim { to { transform: scale(4); opacity: 0; } }

        /* ── FADE-OUT ── */
        .fade-out { animation: fadeOut 1s ease forwards; }
        @keyframes fadeOut { from { opacity:1; } to { opacity:0; } }

        /* ── PALETTE ENHANCEMENT: Sand accent dividers ── */
        .section-hdr {
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(210,193,182,0.55);
        }

        /* ── Page body bg: cream wash ── */
        body {
            background: linear-gradient(160deg, #F2EDE9 0%, #F5F0EC 100%);
        }

        /* ── Notification dropdown: cream tint ── */
        #notificationDropdown {
            background: #FDFAF8;
            border-color: rgba(210,193,182,0.50);
        }

        /* ── Notif items hover ── */
        .notif-item:hover { background: rgba(210,193,182,0.14); }

        /* ── Quick stats bar: cream divider ── */
        .qs-bar {
            border-top: 1px solid rgba(210,193,182,0.55);
        }

        /* ── QS rows subtle sep ── */
        .qs-row + .qs-row {
            border-top: 1px solid rgba(210,193,182,0.30);
            padding-top: 8px;
        }

        /* ── KPI card inner glow ── */
        .kpi-card {
            border: 1px solid rgba(255,255,255,0.06);
        }
        .kpi-card:hover {
            box-shadow: 0 16px 48px rgba(27,60,83,0.28), 0 4px 12px rgba(27,60,83,0.12);
        }

        /* ── Stat mini card — palette blue variant ── */
        .mini-stat.blue {
            background: rgba(35,76,106,0.07);
            border: 2px solid #1B3C53;

        }

        /* ── Rev card blue — palette derived ── */
        .rev-card.blue {
            background: linear-gradient(135deg, rgba(27,60,83,0.07), rgba(69,104,130,0.04));
            border:2px solid #1B3C53;
        }

        /* ── Upcoming class item hover ── */
        .upcoming-class-item:hover {
            border-color: #456882;
            background: rgba(27,60,83,0.04);
        }

        /* ── Quick action icon default subtle gradient ── */
        .qa-icon {
            background: linear-gradient(135deg, rgba(27,60,83,0.09), rgba(69,104,130,0.06));
        }

        /* ── Scrollbar: use palette navy ── */
        ::-webkit-scrollbar-thumb { background: #456882; }

        /* ── Section link hover refined ── */
        .section-link:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(27,60,83,0.2);
        }

        /* ── Batch stat: subtle left accent bar on hover ── */
        .batch-stat {
            border-left: 3px solid transparent;
            transition: transform .2s, box-shadow .2s, border-color .2s;
        }
        .batch-stat.blue:hover   { border-left-color: #1B3C53; }
        .batch-stat.amber:hover  { border-left-color: #b45309; }
        .batch-stat.green:hover  { border-left-color: #15803d; }
        .batch-stat.rose:hover   { border-left-color: #b91c1c; }

        /* ── topbar icon: gradient ── */
        .topbar-icon {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }
    </style>
</head>
<body>
<?php
include '../header.php';
include '../sidebar.php';
?>

<audio id="notificationSound" preload="auto">
    <source src="../assets/sounds/notification.mp3" type="audio/mpeg">
    <source src="../assets/sounds/notification.ogg" type="audio/ogg">
</audio>

<div class="main-content">

    <!-- ── TOP BAR ── -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="mobile-menu-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
            <div class="topbar-icon"><i class="fas fa-th-large"></i></div>
            <div>
                <div class="topbar-title">Admin Dashboard</div>
                <div class="topbar-sub">Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></div>
            </div>
        </div>

        <div class="topbar-right">
            

            <a href="../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <!-- ── PAGE BODY ── -->
    <div class="page-body">

        <?php if (isset($success_message)): ?>
            <div class="alert-success-bar">
                <div class="icon-wrap"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="msg-title">Success</div>
                    <div class="msg-sub"><?= $success_message ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── KPI STRIP ── -->
        <div class="kpi-grid">
            <!-- Running Batches -->
            <a href="../dashboard/running_batches.php" class="kpi-card">
                <div class="kpi-top">
                    <div class="kpi-icon-wrap"><i class="fas fa-play-circle"></i></div>
                    <div class="kpi-number"><?= $running_batches ?></div>
                </div>
                <div class="kpi-label">Running Batches</div>
                <div class="kpi-delta <?= $running_diff > 0 ? 'up' : ($running_diff < 0 ? 'down' : 'flat') ?>">
                    <?php if ($running_diff > 0): ?><i class="fas fa-arrow-up"></i> +<?= $running_diff ?> vs last month
                    <?php elseif ($running_diff < 0): ?><i class="fas fa-arrow-down"></i> <?= $running_diff ?> vs last month
                    <?php else: ?><i class="fas fa-minus"></i> No change
                    <?php endif; ?>
                </div>
            </a>

            <!-- Upcoming Batches -->
            <a href="../dashboard/upcoming_batches.php" class="kpi-card var-2">
                <div class="kpi-top">
                    <div class="kpi-icon-wrap"><i class="fas fa-calendar-alt"></i></div>
                    <div class="kpi-number"><?= $upcoming_batches ?></div>
                </div>
                <div class="kpi-label">Upcoming Batches</div>
                <div class="kpi-delta <?= $upcoming_diff > 0 ? 'up' : ($upcoming_diff < 0 ? 'down' : 'flat') ?>">
                    <?php if ($upcoming_diff > 0): ?><i class="fas fa-arrow-up"></i> +<?= $upcoming_diff ?> vs last month
                    <?php elseif ($upcoming_diff < 0): ?><i class="fas fa-arrow-down"></i> <?= $upcoming_diff ?> vs last month
                    <?php else: ?><i class="fas fa-minus"></i> No change
                    <?php endif; ?>
                </div>
            </a>

            <!-- Enrolled Students -->
            <a href="../dashboard/enrolled_students.php" class="kpi-card var-3">
                <div class="kpi-top">
                    <div class="kpi-icon-wrap"><i class="fas fa-users"></i></div>
                    <div class="kpi-number"><?= $total_students ?></div>
                </div>
                <div class="kpi-label">Enrolled Students</div>
                <div class="kpi-delta <?= $students_diff > 0 ? 'up' : ($students_diff < 0 ? 'down' : 'flat') ?>">
                    <?php if ($students_diff > 0): ?><i class="fas fa-arrow-up"></i> +<?= $students_diff ?> vs last month
                    <?php elseif ($students_diff < 0): ?><i class="fas fa-arrow-down"></i> <?= $students_diff ?> vs last month
                    <?php else: ?><i class="fas fa-minus"></i> No change
                    <?php endif; ?>
                </div>
            </a>

            <!-- Classes Occurred -->
            <a href="../dashboard/classes_occurred.php" class="kpi-card var-4">
                <div class="kpi-top">
                    <div class="kpi-icon-wrap"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="kpi-number"><?= $classes_occurred ?></div>
                </div>
                <div class="kpi-label">Classes Occurred</div>
                <div class="kpi-delta <?= $classes_diff > 0 ? 'up' : ($classes_diff < 0 ? 'down' : 'flat') ?>">
                    <?php if ($classes_diff > 0): ?><i class="fas fa-arrow-up"></i> +<?= $classes_diff ?> vs last month
                    <?php elseif ($classes_diff < 0): ?><i class="fas fa-arrow-down"></i> <?= $classes_diff ?> vs last month
                    <?php else: ?><i class="fas fa-minus"></i> No change
                    <?php endif; ?>
                </div>
            </a>

            
        </div>

        <!-- ── LEAVE + PAYMENT ROW ── -->
        <div class="two-col">

            <!-- Leave Applications -->
            <div class="panel">
                <div class="section-hdr">
                    <div class="section-hdr-left">
                        <div class="section-dot" style="background:#b45309;"></div>
                        <div class="section-title">Leave Applications</div>
                    </div>
                    <a href="../leaves/leave_management.php" class="section-link">Manage <i class="fas fa-arrow-right" style="font-size:.65rem;"></i></a>
                </div>

                <div class="mini-stats mini-stats-3">
                    <div class="mini-stat orange">
                        <div class="sval"><?= $pending_leaves ?></div>
                        <div class="slbl">Pending</div>
                    </div>
                    <div class="mini-stat green">
                        <div class="sval"><?= $approved_leaves_month ?></div>
                        <div class="slbl">Approved (Month)</div>
                    </div>
                    <div class="mini-stat blue">
                        <div class="sval"><?= $total_leaves ?></div>
                        <div class="slbl">Total</div>
                    </div>
                </div>

                <div class="sub-title"><i class="fas fa-clock"></i> Recent Applications</div>
                <div style="max-height:240px; overflow-y:auto;">
                    <?php if (count($recent_leaves) > 0): ?>
                        <?php foreach ($recent_leaves as $leave): ?>
                            <div class="list-item">
                                <div class="list-item-left">
                                    <div class="list-item-name"><?= htmlspecialchars($leave['student_name']) ?></div>
                                    <div class="list-item-meta">
                                        <span><i class="far fa-calendar-alt"></i> <?= date('M d', strtotime($leave['start_date'])) ?> – <?= date('M d', strtotime($leave['end_date'])) ?></span>
                                        <span><i class="fas fa-tag"></i> Batch #<?= $leave['batch_id'] ?></span>
                                    </div>
                                </div>
                                <div class="list-item-right">
                                    <span class="badge badge-<?= $leave['status'] == 'pending' ? 'pending' : 'approved' ?>">
                                        <i class="fas fa-circle"></i> <?= ucfirst($leave['status']) ?>
                                    </span>
                                    <a href="../leave/view_application.php?id=<?= $leave['id'] ?>" class="eye-link"><i class="fas fa-eye"></i></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-check-circle"></i><p>No pending leave applications</p></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment & Revenue -->
            <div class="panel">
                <div class="section-hdr">
                    <div class="section-hdr-left">
                        <div class="section-dot" style="background:#1a7f5a;"></div>
                        <div class="section-title">Payment &amp; Revenue</div>
                    </div>
                    <a href="../payment/payment_dash.php" class="section-link">View All <i class="fas fa-arrow-right" style="font-size:.65rem;"></i></a>
                </div>

                <div class="revenue-strip">
                    <div class="rev-card green">
                        <div class="ramt">₹<?= number_format($monthly_revenue, 2) ?></div>
                        <div class="rlbl">This Month Revenue</div>
                    </div>
                    <div class="rev-card blue">
                        <div class="ramt">₹<?= number_format($total_revenue, 2) ?></div>
                        <div class="rlbl">Total Revenue</div>
                    </div>
                </div>

                <div class="mini-stats mini-stats-3" style="margin-bottom:16px;">
                    <div class="mini-stat orange">
                        <div class="sval"><?= $pending_payments ?></div>
                        <div class="slbl">Pending Verification</div>
                    </div>
                    <div class="mini-stat green">
                        <div class="sval"><?= $verified_payments_month ?></div>
                        <div class="slbl">Verified (Month)</div>
                    </div>
                    <div class="mini-stat red">
                        <div class="sval"><?= $overdue_payments ?></div>
                        <div class="slbl">Overdue</div>
                    </div>
                </div>

                <div class="sub-title"><i class="fas fa-history"></i> Recent Transactions</div>
                <div style="max-height:200px; overflow-y:auto;">
                    <?php if (count($recent_payments) > 0): ?>
                        <?php foreach ($recent_payments as $payment): ?>
                            <div class="list-item">
                                <div class="list-item-left">
                                    <div class="list-item-name"><?= htmlspecialchars($payment['student_name']) ?></div>
                                    <div class="list-item-meta">
                                        <span><i class="fas fa-rupee-sign"></i> ₹<?= number_format($payment['amount'], 2) ?></span>
                                        <span><i class="fas fa-tag"></i> Batch #<?= $payment['batch_id'] ?></span>
                                        <span><i class="far fa-calendar-alt"></i> <?= date('M d, Y', strtotime($payment['transaction_date'])) ?></span>
                                    </div>
                                </div>
                                <div class="list-item-right">
                                    <span class="badge badge-<?= $payment['status'] == 'pending' ? 'pending' : 'verified' ?>">
                                        <i class="fas fa-circle"></i> <?= ucfirst($payment['status']) ?>
                                    </span>
                                    <a href="../payments/view_transaction.php?id=<?= $payment['id'] ?>" class="eye-link"><i class="fas fa-eye"></i></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-receipt"></i><p>No recent transactions</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── BATCH STATUS OVERVIEW ── -->
        <div class="panel" style="margin-bottom:24px;">
            <div class="section-hdr">
                <div class="section-hdr-left">
                    <div class="section-dot" style="background:var(--primary);"></div>
                    <div class="section-title">Batch Status Overview</div>
                </div>
                <a href="../batch/batch_list.php" class="section-link">Manage Batches <i class="fas fa-arrow-right" style="font-size:.65rem;"></i></a>
            </div>
            <div class="batch-overview-grid">
                <div class="batch-stat">
                    <div class="batch-stat-icon blue"><i class="fas fa-play-circle"></i></div>
                    <div class="batch-stat-num"><?= $ongoing_batches ?></div>
                    <div class="batch-stat-lbl">Ongoing</div>
                    <div class="batch-stat-pct"><?= $total_batches > 0 ? round(($ongoing_batches/$total_batches)*100) : 0 ?>% of total</div>
                </div>
                <div class="batch-stat">
                    <div class="batch-stat-icon amber"><i class="fas fa-calendar-alt"></i></div>
                    <div class="batch-stat-num"><?= $upcoming_batches_count ?></div>
                    <div class="batch-stat-lbl">Upcoming</div>
                    <div class="batch-stat-pct"><?= $total_batches > 0 ? round(($upcoming_batches_count/$total_batches)*100) : 0 ?>% of total</div>
                </div>
                <div class="batch-stat">
                    <div class="batch-stat-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="batch-stat-num"><?= $completed_batches ?></div>
                    <div class="batch-stat-lbl">Completed</div>
                    <div class="batch-stat-pct"><?= $total_batches > 0 ? round(($completed_batches/$total_batches)*100) : 0 ?>% of total</div>
                </div>
                <div class="batch-stat">
                    <div class="batch-stat-icon rose"><i class="fas fa-times-circle"></i></div>
                    <div class="batch-stat-num"><?= $cancelled_batches ?></div>
                    <div class="batch-stat-lbl">Cancelled</div>
                    <div class="batch-stat-pct"><?= $total_batches > 0 ? round(($cancelled_batches/$total_batches)*100) : 0 ?>% of total</div>
                </div>
            </div>
        </div>

        <!-- ── BOTTOM 3-COL ── -->
        <div class="three-col">

            <!-- Upcoming Live Classes -->
            <div class="panel">
                <div class="section-hdr">
                    <div class="section-hdr-left">
                        <div class="section-dot" style="background:#456882;"></div>
                        <div class="section-title">Upcoming Classes</div>
                    </div>
                </div>
                <?php if (count($upcoming_classes) > 0): ?>
                    <?php foreach ($upcoming_classes as $cls): ?>
                        <div class="upcoming-class-item">
                            <div class="class-date-chip">
                                <div class="day"><?= date('d', strtotime($cls['schedule_date'])) ?></div>
                                <div class="mon"><?= date('M', strtotime($cls['schedule_date'])) ?></div>
                            </div>
                            <div class="class-info">
                                <div class="topic"><?= htmlspecialchars($cls['topic'] ?? 'Class') ?></div>
                                <div class="meta">
                                    <i class="far fa-clock"></i> <?= date('g:i A', strtotime($cls['start_time'])) ?> –
                                    <?= date('g:i A', strtotime($cls['end_time'])) ?>
                                    &nbsp;·&nbsp; Batch #<?= htmlspecialchars($cls['batch_id']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-calendar-times"></i><p>No upcoming classes scheduled</p></div>
                <?php endif; ?>
            </div>

            <!-- Recent Messages -->
            <div class="panel">
                <div class="section-hdr">
                    <div class="section-hdr-left">
                        <div class="section-dot" style="background:#234C6A;"></div>
                        <div class="section-title">Recent Messages</div>
                    </div>
                    <a href="../chat/index.php" class="section-link">Open Chat <i class="fas fa-arrow-right" style="font-size:.65rem;"></i></a>
                </div>
                <?php if (count($recent_msgs) > 0): ?>
                    <?php foreach ($recent_msgs as $msg): ?>
                        <div class="msg-item">
                            <div class="msg-avatar"><?= strtoupper(substr($msg['sender_name'], 0, 1)) ?></div>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <div class="msg-sender"><?= htmlspecialchars($msg['sender_name']) ?></div>
                                    <div class="msg-time"><?= date('g:i A', strtotime($msg['sent_at'])) ?></div>
                                </div>
                                <div class="msg-text"><?= htmlspecialchars(substr($msg['message'], 0, 70)) ?>…</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-comment-slash"></i><p>No recent messages</p></div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="panel">
                <div class="section-hdr">
                    <div class="section-hdr-left">
                        <div class="section-dot" style="background:var(--accent);"></div>
                        <div class="section-title">Quick Actions</div>
                    </div>
                </div>
                <div class="quick-actions-grid">
                    <a href="../batch/batch_list.php" class="quick-action">
                        <div class="qa-icon"><i class="fas fa-layer-group"></i></div>
                        <div class="qa-label">Manage Batches</div>
                    </a>
                    <a href="../student/add_student.php" class="quick-action">
                        <div class="qa-icon"><i class="fas fa-user-plus"></i></div>
                        <div class="qa-label">Add Student</div>
                    </a>
                    <a href="../attendance/attendance.php" class="quick-action">
                        <div class="qa-icon"><i class="fas fa-clipboard-check"></i></div>
                        <div class="qa-label">Mark Attendance</div>
                    </a>
                    <a href="../payment/payment_dash.php" class="quick-action">
                        <div class="qa-icon"><i class="fas fa-credit-card"></i></div>
                        <div class="qa-label">Verify Payments</div>
                    </a>
                    <a href="../leaves/leave_management.php" class="quick-action">
                        <div class="qa-icon"><i class="fas fa-calendar-check"></i></div>
                        <div class="qa-label">Manage Leaves</div>
                    </a>
                    
                </div>

                <div class="qs-bar">
                    <div class="qs-row">
                        <div class="ql">Total Batches</div>
                        <div class="qv"><?= $total_batches ?></div>
                    </div>
                    <div class="qs-row">
                        <div class="ql">Active Students</div>
                        <div class="qv"><?= $total_students ?></div>
                    </div>
                    <div class="qs-row">
                        <div class="ql">Completion Rate</div>
                        <div class="qv green"><?= $total_batches > 0 ? round(($completed_batches/$total_batches)*100) : 0 ?>%</div>
                    </div>
                </div>
            </div>

        </div>

        

    </div><!-- /page-body -->
</div><!-- /main-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {

    <?php if ($play_notification_sound): ?>
    const ns = document.getElementById('notificationSound');
    if (ns) { ns.volume = 0.3; ns.play().catch(()=>{}); }
    <?php endif; ?>

    // Auto-hide success alert
    const alert = document.querySelector('.alert-success-bar');
    if (alert) {
        setTimeout(() => { alert.classList.add('fade-out'); setTimeout(() => alert.remove(), 1000); }, 5000);
    }

    // Notification dropdown
    const btn = document.getElementById('notificationButton');
    const dd  = document.getElementById('notificationDropdown');

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (dd.classList.contains('show')) {
            dd.classList.remove('show');
        } else {
            dd.classList.add('show');
            const badge = document.querySelector('.notif-badge');
            if (badge) badge.style.display = 'none';
            fetch('../notifications/mark_notifications_seen.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:'{}' }).catch(()=>{});
        }
    });

    document.addEventListener('click', function(e) {
        if (!btn.contains(e.target) && !dd.contains(e.target)) {
            dd.classList.remove('show');
        }
    });

    dd.addEventListener('click', e => e.stopPropagation());

    // Ripple on quick actions
    document.querySelectorAll('.quick-action').forEach(el => {
        el.addEventListener('click', function(e) {
            const r = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const sz = Math.max(rect.width, rect.height);
            r.className = 'ripple';
            r.style.cssText = `width:${sz}px;height:${sz}px;left:${e.clientX-rect.left-sz/2}px;top:${e.clientY-rect.top-sz/2}px`;
            const old = this.querySelector('.ripple');
            if (old) old.remove();
            this.appendChild(r);
            setTimeout(() => r.remove(), 600);
        });
    });
});
</script>

<?php include '../footer.php'; ?>