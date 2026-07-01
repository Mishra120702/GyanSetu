<?php
/**
 * admin_settings.php
 * Admin Settings Dashboard - Overview and Navigation Hub
 * Modified with Skeuomorphic Design - Original Color Theme
 */

include '../db_connection.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get system statistics
$stats = $db->query("SELECT 
    COUNT(*) as total_users,
    SUM(account_locked = 1) as locked_users,
    SUM(account_locked = 0) as active_users,
    SUM(failed_login_attempts > 0) as failed_attempt_users,
    SUM(role = 'admin') as admin_count,
    SUM(role = 'mentor') as mentor_count,
    SUM(role = 'student') as student_count
    FROM users")->fetch(PDO::FETCH_ASSOC);

// Get recent activities
$recent_activities = $db->query("SELECT 
    u.name as user_name,
    u.role,
    a.action,
    a.reason,
    a.performed_at,
    admin.name as performed_by
    FROM user_lock_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN users admin ON a.performed_by = admin.id
    ORDER BY a.performed_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Get system health
$system_health = $db->query("SELECT 
    (SELECT COUNT(*) FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as active_last_week,
    (SELECT COUNT(*) FROM user_lock_logs WHERE DATE(performed_at) = CURDATE()) as today_locks,
    (SELECT AVG(failed_login_attempts) FROM users) as avg_failed_attempts,
    (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_users_month
    FROM DUAL")->fetch(PDO::FETCH_ASSOC);

// Get batch statistics
$batch_stats = $db->query("
    SELECT 
        COUNT(DISTINCT b.batch_id) as total_batches,
        SUM(CASE WHEN b.status = 'ongoing' THEN 1 ELSE 0 END) as ongoing_batches,
        SUM(CASE WHEN b.status = 'upcoming' THEN 1 ELSE 0 END) as upcoming_batches,
        SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed_batches
    FROM batches b
")->fetch(PDO::FETCH_ASSOC);

// Get terms acceptance statistics
$terms_stats = $db->query("
    SELECT 
        COUNT(*) as total_students,
        SUM(terms_accepted = 1) as accepted_count,
        SUM(terms_accepted = 0) as pending_count
    FROM students
    WHERE current_status = 'active'
")->fetch(PDO::FETCH_ASSOC);

$acceptance_rate = $terms_stats['total_students'] > 0 
    ? round(($terms_stats['accepted_count'] / $terms_stats['total_students']) * 100) 
    : 0;

// Modal detail data
$all_users = $db->query("
    SELECT u.id, u.name, u.email, u.role, u.account_locked, u.failed_login_attempts, u.last_login, u.created_at,
           s.student_id, s.phone_number, s.current_status as student_status
    FROM users u
    LEFT JOIN students s ON u.id = s.user_id
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$locked_users_list = $db->query("SELECT u.id, u.name, u.email, u.role, u.account_locked, u.failed_login_attempts, u.last_login, u.created_at, l.performed_at as locked_at, l.reason, s.student_id, s.phone_number FROM users u LEFT JOIN user_lock_logs l ON u.id = l.user_id AND l.action='locked' LEFT JOIN students s ON u.id = s.user_id WHERE u.account_locked=1 ORDER BY l.performed_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$failed_users_list = $db->query("SELECT u.id, u.name, u.email, u.role, u.account_locked, u.failed_login_attempts, u.last_login, u.created_at, s.student_id, s.phone_number FROM users u LEFT JOIN students s ON u.id = s.user_id WHERE u.failed_login_attempts > 0 ORDER BY u.failed_login_attempts DESC")->fetchAll(PDO::FETCH_ASSOC);
$active_users_list = $db->query("SELECT u.id, u.name, u.email, u.role, u.account_locked, u.failed_login_attempts, u.last_login, u.created_at, s.student_id, s.phone_number FROM users u LEFT JOIN students s ON u.id = s.user_id WHERE u.account_locked=0 ORDER BY u.last_login DESC")->fetchAll(PDO::FETCH_ASSOC);
$admin_list = $db->query("SELECT u.id, u.name, u.email, u.role, u.account_locked, u.failed_login_attempts, u.last_login, u.created_at FROM users u WHERE u.role='admin' ORDER BY u.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$lock_logs_list = $db->query("SELECT l.id, l.user_id, u.name as user_name, u.email, u.role, u.account_locked, u.failed_login_attempts, u.last_login, u.created_at, l.action, l.reason, l.performed_at, a.name as performed_by, s.student_id, s.phone_number FROM user_lock_logs l LEFT JOIN users u ON l.user_id=u.id LEFT JOIN users a ON l.performed_by=a.id LEFT JOIN students s ON u.id=s.user_id ORDER BY l.performed_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$all_batches = $db->query("
    SELECT b.batch_id, b.batch_name, b.start_date, b.end_date, b.max_students, b.current_enrollment, b.mode, b.status,
           u.name as mentor_name
    FROM batches b
    LEFT JOIN users u ON b.batch_mentor_id = u.id
    ORDER BY b.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Brand Colour Theme: #1B3C53 · #234C6A · #456882 · #D2C1B6 */
        :root {
            --brand-darkest:  #1B3C53;
            --brand-dark:     #234C6A;
            --brand-mid:      #456882;
            --brand-light:    #A4C4D4;
            --brand-sand:     #D2C1B6;
            --primary-gradient: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
            --accent-gradient:  linear-gradient(135deg, #456882 0%, #234C6A 100%);
            --sand-gradient:    linear-gradient(135deg, #D2C1B6 0%, #b8a89b 100%);
            --teal-gradient:    linear-gradient(135deg, #2d7a8a 0%, #1B3C53 100%);
            --steel-gradient:   linear-gradient(135deg, #456882 0%, #2a4f6a 100%);
            --warm-gradient:    linear-gradient(135deg, #c9b8ae 0%, #D2C1B6 100%);
            --navy-gradient:    linear-gradient(135deg, #1B3C53 0%, #0f2435 100%);
            --slate-gradient:   linear-gradient(135deg, #3a6278 0%, #1B3C53 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(145deg, #e8eef3 0%, #d6e4ed 30%, #e4edf4 60%, #dce8f0 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Soft decorative blobs matching sidebar blue/indigo/purple palette */
        body::before {
            content: '';
            position: fixed;
            top: -120px; left: -120px;
            width: 420px; height: 420px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(27,60,83,0.1) 0%, transparent 70%);
            animation: driftOrb1 20s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -100px; right: -100px;
            width: 380px; height: 380px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(69,104,130,0.09) 0%, transparent 70%);
            animation: driftOrb2 25s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        @keyframes driftOrb1 {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(60px,50px) scale(1.1); }
        }
        @keyframes driftOrb2 {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(-50px,-60px) scale(1.12); }
        }

        /* Content always above decorative blobs */
        .content-wrapper { position: relative; z-index: 1; }

        /* Restore module cards to light glass style */
        .module-card {
            background: rgba(255,255,255,0.88) !important;
            border: 1px solid rgba(27,60,83,0.10) !important;
            box-shadow: 0 8px 24px rgba(27,60,83,0.08) !important;
            backdrop-filter: blur(8px);
        }
        .module-card .module-card-body {
            background: rgba(255,255,255,0.92) !important;
        }
        .module-title { color: #1B3C53 !important; }
        .module-description { color: #64748b !important; }

        /* Health card — brand gradient */
        .health-card {
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
            border: none;
        }

        /* White panels restore */
        .bg-white {
            background: rgba(255,255,255,0.90) !important;
            border: 1px solid rgba(27,60,83,0.08) !important;
            box-shadow: 0 6px 20px rgba(27,60,83,0.07) !important;
        }
        .bg-white h5, .bg-white h6, .bg-white strong { color: #1B3C53 !important; }
        .bg-white p, .bg-white small, .bg-white .text-muted { color: #64748b !important; }

        /* === VIBRANT GRADIENT METRIC CARDS === */
        .metric-card {
            transition: all 0.35s cubic-bezier(0.4,0,0.2,1);
            position: relative;
            overflow: hidden;
            border-radius: 20px;
            width: 100%;
            height: 140px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            color: white;
            text-align: center;
            padding: 16px;
        }
        /* Shimmer sweep on hover */
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0; left: -75%;
            width: 60%; height: 100%;
            background: linear-gradient(120deg, transparent, rgba(255,255,255,0.18), transparent);
            transform: skewX(-20deg);
            transition: left 0.55s ease;
            pointer-events: none;
        }
        .metric-card:hover::before { left: 140%; }

        /* Subtle inner glow ring */
        .metric-card::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 22px;
            border: 1.5px solid rgba(255,255,255,0.3);
            pointer-events: none;
        }
        .metric-card:hover {
            transform: translateY(-7px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0,0,0,0.22);
        }

        /* Per-card gradients — Brand Palette */
        .mc-purple  { background: linear-gradient(135deg,#234C6A 0%,#1B3C53 100%); box-shadow: 0 8px 24px rgba(27,60,83,0.40); }
        .mc-red     { background: linear-gradient(135deg,#c0392b 0%,#922b21 100%); box-shadow: 0 8px 24px rgba(192,57,43,0.40); }
        .mc-orange  { background: linear-gradient(135deg,#b6876a 0%,#9c6f55 100%); box-shadow: 0 8px 24px rgba(182,135,106,0.40); }
        .mc-teal    { background: linear-gradient(135deg,#2d7a8a 0%,#1B3C53 100%); box-shadow: 0 8px 24px rgba(45,122,138,0.40); }
        .mc-cyan    { background: linear-gradient(135deg,#456882 0%,#234C6A 100%); box-shadow: 0 8px 24px rgba(69,104,130,0.40); }
        .mc-slate   { background: linear-gradient(135deg,#3a6278 0%,#1B3C53 100%); box-shadow: 0 8px 24px rgba(58,98,120,0.40); }
        .mc-violet  { background: linear-gradient(135deg,#5a7fa0 0%,#234C6A 100%); box-shadow: 0 8px 24px rgba(90,127,160,0.40); }
        .mc-green   { background: linear-gradient(135deg,#2e8b7a 0%,#1d5c6e 100%); box-shadow: 0 8px 24px rgba(46,139,122,0.40); }

        .mc-purple:hover  { box-shadow: 0 24px 48px rgba(27,60,83,0.55); }
        .mc-red:hover     { box-shadow: 0 24px 48px rgba(192,57,43,0.55); }
        .mc-orange:hover  { box-shadow: 0 24px 48px rgba(182,135,106,0.55); }
        .mc-teal:hover    { box-shadow: 0 24px 48px rgba(45,122,138,0.55); }
        .mc-cyan:hover    { box-shadow: 0 24px 48px rgba(69,104,130,0.55); }
        .mc-slate:hover   { box-shadow: 0 24px 48px rgba(58,98,120,0.55); }
        .mc-violet:hover  { box-shadow: 0 24px 48px rgba(90,127,160,0.55); }
        .mc-green:hover   { box-shadow: 0 24px 48px rgba(46,139,122,0.55); }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.92;
            transition: transform 0.35s ease;
            filter: drop-shadow(0 3px 6px rgba(0,0,0,0.25));
            color: white;
        }
        .metric-card:hover .stat-icon { transform: scale(1.15) rotate(-5deg); }

        .stat-number {
            font-size: 2.4rem;
            font-weight: 900;
            margin-bottom: 5px;
            line-height: 1;
            color: white;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .stat-label {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.9);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            line-height: 1.3;
        }

        /* Module Card - Skeuomorphic with Original Gradients */
        .module-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 8px 8px 16px rgba(0, 0, 0, 0.08),
                        -4px -4px 12px rgba(255, 255, 255, 0.6);
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            height: 100%;
            position: relative;
        }

        .module-card:hover {
            transform: translateY(-8px);
            box-shadow: 12px 14px 24px rgba(0, 0, 0, 0.12),
                        -6px -6px 14px rgba(255, 255, 255, 0.7);
        }

        .module-card-header {
            padding: 20px;
            font-weight: 700;
            font-size: 1.1rem;
            color: white;
            position: relative;
            overflow: hidden;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.3);
        }

        .module-card-header::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 100%);
            pointer-events: none;
        }

        .module-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: white;
            filter: drop-shadow(2px 4px 6px rgba(0,0,0,0.2));
            transition: all 0.3s ease;
        }

        .module-card:hover .module-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .module-card-body {
            padding: 20px;
            background: white;
        }

        .module-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #2c3e50;
        }

        .module-description {
            color: #7f8c8d;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .module-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255,255,255,0.25);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            backdrop-filter: blur(8px);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.3), 0 1px 2px rgba(0,0,0,0.1);
        }

        /* Dashboard Header - Brand Gradient */
        .dashboard-header {
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            box-shadow: 12px 12px 24px rgba(27,60,83,0.30),
                        -4px -4px 12px rgba(255,255,255,0.15),
                        inset 0 1px 0 rgba(255,255,255,0.12);
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }

        .welcome-text {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .welcome-subtext {
            font-size: 1.1rem;
            opacity: 0.95;
            margin-bottom: 0;
        }

        /* Health Card - Brand Style */
        .health-card {
            background: linear-gradient(135deg, #456882 0%, #1B3C53 100%);
            color: white;
            border-radius: 24px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 12px 12px 24px rgba(27,60,83,0.30),
                        -4px -4px 12px rgba(255,255,255,0.12),
                        inset 0 1px 0 rgba(255,255,255,0.12);
        }

        .health-indicator {
            height: 8px;
            background: rgba(255,255,255,0.25);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
        }

        .health-fill {
            height: 100%;
            border-radius: 4px;
            background: white;
            transition: width 1s ease;
            box-shadow: 0 0 8px rgba(255,255,255,0.5);
        }

        /* Activity Timeline */
        .activity-timeline {
            position: relative;
            padding-left: 30px;
        }

        .activity-timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, #e2e8f0 0%, #cbd5e0 100%);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.8), 0 1px 0 rgba(0,0,0,0.05);
        }

        .activity-item {
            position: relative;
            margin-bottom: 20px;
            padding-left: 20px;
        }

        .activity-item::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 5px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 2px;
        }

        .activity-locked::before {
            background: #e53e3e;
            box-shadow: 0 0 0 2px #e53e3e;
        }

        .activity-unlocked::before {
            background: #38a169;
            box-shadow: 0 0 0 2px #38a169;
        }

        /* Quick Action Buttons - Skeuomorphic and Brand Palette */
        .quick-action {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(27, 60, 83, 0.12) !important;
            background: white !important;
            box-shadow: 4px 4px 8px rgba(0, 0, 0, 0.05),
                       -2px -2px 6px rgba(255, 255, 255, 0.7) !important;
            border-radius: 12px !important;
            padding: 8px 14px !important;
        }
        
        .quick-action i {
            transition: all 0.3s ease;
            font-size: 1.15rem !important;
        }

        .quick-action .fw-bold {
            transition: color 0.3s ease;
            font-size: 0.95rem !important;
            margin-bottom: 1px;
        }

        .quick-action small {
            transition: color 0.3s ease;
            font-size: 0.75rem !important;
            display: block;
        }

        /* Color classes */
        .qa-navy i { color: #1B3C53 !important; }
        .qa-navy .fw-bold { color: #1B3C53 !important; }
        .qa-navy small { color: #456882 !important; }

        .qa-mid i { color: #234C6A !important; }
        .qa-mid .fw-bold { color: #234C6A !important; }
        .qa-mid small { color: #456882 !important; }

        .qa-steel i { color: #456882 !important; }
        .qa-steel .fw-bold { color: #456882 !important; }
        .qa-steel small { color: #456882 !important; }

        .qa-sand i { color: #b6876a !important; }
        .qa-sand .fw-bold { color: #b6876a !important; }
        .qa-sand small { color: #b6876a !important; }

        .qa-red i { color: #c0392b !important; }
        .qa-red .fw-bold { color: #c0392b !important; }
        .qa-red small { color: #b6876a !important; }

        /* Hover states */
        .quick-action:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 10px 20px rgba(27, 60, 83, 0.15) !important;
        }

        .qa-navy:hover {
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
            border-color: #1B3C53 !important;
        }
        .qa-mid:hover {
            background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
            border-color: #234C6A !important;
        }
        .qa-steel:hover {
            background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
            border-color: #234C6A !important;
        }
        .qa-sand:hover {
            background: linear-gradient(135deg, #D2C1B6 0%, #b8a89b 100%) !important;
            border-color: #b8a89b !important;
        }
        .qa-red:hover {
            background: linear-gradient(135deg, #c0392b 0%, #922b21 100%) !important;
            border-color: #922b21 !important;
        }

        .quick-action:hover i,
        .quick-action:hover .fw-bold,
        .quick-action:hover small {
            color: white !important;
        }

        .quick-action:hover i {
            transform: scale(1.15);
        }

        /* Card Background for Activity Section */
        .bg-white {
            background: white !important;
            border-radius: 24px;
            box-shadow: 8px 8px 16px rgba(0, 0, 0, 0.08),
                       -4px -4px 12px rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(255,255,255,0.6);
        }

        .rounded-xl {
            border-radius: 24px;
        }

        .shadow {
            box-shadow: none !important;
        }

        /* Avatar styles */
        .avatar-sm {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #f0f0f0;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.05), 0 2px 4px rgba(0,0,0,0.1);
        }

        .bg-danger-subtle {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        }

        .bg-success-subtle {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        }

        /* Progress ring */
        .progress-ring-circle {
            transition: stroke-dashoffset 0.35s;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }

        /* Floating animation */
        .floating-icon {
            animation: floatIcon 3s ease-in-out infinite;
        }

        @keyframes floatIcon {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(27,60,83,0.45); }
            70% { box-shadow: 0 0 0 10px rgba(27,60,83,0); }
            100% { box-shadow: 0 0 0 0 rgba(27,60,83,0); }
        }

        .pulse-animation {
            animation: pulse 2s infinite;
        }

        /* Metric card clickable */
        .metric-card {
            cursor: pointer;
        }
        .metric-card .click-hint {
            position: absolute;
            bottom: 8px;
            right: 12px;
            font-size: 0.7rem;
            color: #a0aec0;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .metric-card:hover .click-hint {
            opacity: 1;
        }

        /* Detail Modal Styles */
        .detail-modal .modal-dialog {
            max-width: 820px;
        }
        .detail-modal .modal-content {
            border-radius: 24px;
            overflow: hidden;
            border: none;
            box-shadow: 0 30px 60px rgba(0,0,0,0.2);
        }
        .detail-modal .modal-header {
            padding: 24px 28px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }
        .detail-modal .modal-body {
            padding: 24px 28px;
            max-height: 65vh;
            overflow-y: auto;
            background: #f8fafc;
        }
        .detail-modal .modal-body::-webkit-scrollbar { width: 6px; }
        .detail-modal .modal-body::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 3px; }
        .detail-table thead th {
            background: rgba(27,60,83,0.07);
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #1B3C53;
            border: none;
            padding: 12px 14px;
        }
        .detail-table tbody tr {
            transition: background 0.2s;
            border-bottom: 1px solid #edf2f7;
        }
        .detail-table tbody tr:hover { background: #dde8f0; }
        .detail-table td { padding: 11px 14px; vertical-align: middle; font-size: 0.88rem; border: none; }
        .modal-stat-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .modal-hero-number {
            font-size: 3.5rem;
            font-weight: 900;
            line-height: 1;
            text-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .search-box-modal {
            border-radius: 12px;
            border: 2px solid #b8ccd8;
            padding: 8px 14px;
            transition: border-color 0.2s;
            font-size: 0.9rem;
        }
        .search-box-modal:focus {
            border-color: #456882;
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
            outline: none;
        }

        /* Profile modal always on top */
        #userProfileModal {
            z-index: 1200 !important;
        }
        #userProfileModal .modal-dialog {
            z-index: 1201 !important;
        }
        .profile-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 1199;
            backdrop-filter: blur(3px);
            animation: fadeInBd 0.2s ease;
        }
        @keyframes fadeInBd {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 20px;
            }
            .welcome-text {
                font-size: 1.8rem;
            }
            .stat-number {
                font-size: 1.8rem;
            }
        }

        .header-mobile {
            display: none;
        }

        @media (max-width: 768px) {
            .header-mobile {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 1rem;
                background: white;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                position: sticky;
                top: 0;
                z-index: 100;
            }
            .content-wrapper {
                margin-left: 0 !important;
                padding-top: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <div class="header-mobile">
        <button class="text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-user-shield text-blue-500"></i>
            <span>Admin Security</span>
        </h1>
        <div class="flex items-center space-x-3">
            <a href="../logout.php" class="text-sm text-red-600 hover:underline flex items-center space-x-1">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
    
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="content-wrapper ml-0 md:ml-64 min-h-screen p-4 md:p-6">
        <!-- Dashboard Header -->
        <div class="dashboard-header animate__animated animate__fadeIn">
            <div class="row align-items-center">
                <div class="col-lg-12">
                    <h1 class="welcome-text">
                        <i class="fas fa-user-shield me-3 floating-icon"></i>
                        Admin Security Dashboard
                    </h1>
                    <p class="welcome-subtext">
                        Welcome back, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?>! 
                        Monitor and manage all security settings from this centralized dashboard.
                    </p>
                </div>
            </div>
        </div>

        <!-- Statistics Cards - Row 1 -->
        <div class="row mb-3 g-4 animate__animated animate__fadeInUp">
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
                <div class="metric-card mc-purple" onclick="openModal('totalUsersModal')" title="Click for details">
                    <i class="fas fa-users stat-icon"></i>
                    <h2 class="stat-number"><?php echo $stats['total_users']; ?></h2>
                    <p class="stat-label">Total Users</p>
                    <span class="click-hint"><i class="fas fa-expand-alt"></i> Details</span>
                </div>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
                <div class="metric-card mc-red" onclick="openModal('lockedUsersModal')" title="Click for details">
                    <i class="fas fa-lock stat-icon"></i>
                    <h2 class="stat-number"><?php echo $stats['locked_users']; ?></h2>
                    <p class="stat-label">Locked Users</p>
                    <span class="click-hint"><i class="fas fa-expand-alt"></i> Details</span>
                </div>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
                <div class="metric-card mc-orange" onclick="openModal('failedAttemptsModal')" title="Click for details">
                    <i class="fas fa-exclamation-triangle stat-icon"></i>
                    <h2 class="stat-number"><?php echo $stats['failed_attempt_users']; ?></h2>
                    <p class="stat-label">Failed Attempts</p>
                    <span class="click-hint"><i class="fas fa-expand-alt"></i> Details</span>
                </div>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
                <div class="metric-card mc-teal" onclick="openModal('activeUsersModal')" title="Click for details">
                    <i class="fas fa-user-check stat-icon"></i>
                    <h2 class="stat-number"><?php echo $stats['active_users']; ?></h2>
                    <p class="stat-label">Active Users</p>
                    <span class="click-hint"><i class="fas fa-expand-alt"></i> Details</span>
                </div>
            </div>
        </div>

        <!-- Statistics Cards - Row 2 -->
        <div class="row mb-4 g-4 animate__animated animate__fadeInUp">
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
                <div class="metric-card mc-cyan" onclick="openModal('adminsModal')" title="Click for details">
                    <i class="fas fa-crown stat-icon"></i>
                    <h2 class="stat-number"><?php echo $stats['admin_count']; ?></h2>
                    <p class="stat-label">Admins</p>
                    <span class="click-hint"><i class="fas fa-expand-alt"></i> Details</span>
                </div>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
                <div class="metric-card mc-slate pulse-animation" onclick="openModal('lockActionsModal')" title="Click for details">
                    <i class="fas fa-history stat-icon"></i>
                    <?php $total_locks = $db->query("SELECT COUNT(*) FROM user_lock_logs")->fetchColumn(); ?>
                    <h2 class="stat-number"><?php echo $total_locks; ?></h2>
                    <p class="stat-label">Lock Actions</p>
                    <span class="click-hint"><i class="fas fa-expand-alt"></i> Details</span>
                </div>
            </div>
            <!-- Terms Acceptance Rate Card -->
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
                <div class="metric-card mc-violet" onclick="openModal('termsModal')" title="Click for details">
                    <i class="fas fa-file-signature stat-icon"></i>
                    <h2 class="stat-number"><?php echo $acceptance_rate; ?>%</h2>
                    <p class="stat-label">Terms Acceptance</p>
                    <span class="click-hint"><i class="fas fa-expand-alt"></i> Details</span>
                </div>
            </div>
            <!-- Batch Statistics Card -->
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
                <div class="metric-card mc-green" onclick="openModal('batchesModal')" title="Click for details">
                    <i class="fas fa-layer-group stat-icon"></i>
                    <h2 class="stat-number"><?php echo $batch_stats['total_batches']; ?></h2>
                    <p class="stat-label">Total Batches</p>
                    <span class="click-hint"><i class="fas fa-expand-alt"></i> Details</span>
                </div>
            </div>
        </div>

        <!-- System Health -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="health-card animate__animated animate__fadeIn">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3 class="fw-bold mb-3"><i class="fas fa-heartbeat me-2"></i> System Health</h3>
                            <div class="row">
                                <div class="col-md-3 col-6 mb-3">
                                    <p class="mb-1">Active Users (7 days)</p>
                                    <div class="health-indicator">
                                        <div class="health-fill" style="width: <?php echo min(100, ($system_health['active_last_week'] / $stats['total_users']) * 100); ?>%"></div>
                                    </div>
                                    <p class="mb-0 mt-2"><?php echo $system_health['active_last_week']; ?> users</p>
                                </div>
                                <div class="col-md-3 col-6 mb-3">
                                    <p class="mb-1">Today's Lock Actions</p>
                                    <div class="health-indicator">
                                        <div class="health-fill" style="width: <?php echo min(100, $system_health['today_locks'] * 10); ?>%"></div>
                                    </div>
                                    <p class="mb-0 mt-2"><?php echo $system_health['today_locks']; ?> actions</p>
                                </div>
                                <div class="col-md-3 col-6 mb-3">
                                    <p class="mb-1">Avg Failed Attempts</p>
                                    <div class="health-indicator">
                                        <div class="health-fill" style="width: <?php echo min(100, $system_health['avg_failed_attempts'] * 20); ?>%"></div>
                                    </div>
                                    <p class="mb-0 mt-2"><?php echo number_format($system_health['avg_failed_attempts'], 1); ?> avg</p>
                                </div>
                                <div class="col-md-3 col-6 mb-3">
                                    <p class="mb-1">New Users (30 days)</p>
                                    <div class="health-indicator">
                                        <div class="health-fill" style="width: <?php echo min(100, ($system_health['new_users_month'] / max(1, $stats['total_users'])) * 100); ?>%"></div>
                                    </div>
                                    <p class="mb-0 mt-2"><?php echo $system_health['new_users_month']; ?> new</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end d-none d-md-block">
                            <div class="d-inline-block position-relative">
                                <svg class="progress-ring" width="120" height="120">
                                    <circle class="progress-ring-circle" stroke="white" stroke-width="8" 
                                            fill="transparent" r="52" cx="60" cy="60"
                                            stroke-dasharray="327" stroke-dashoffset="327"
                                            style="stroke-dashoffset: <?php echo 327 - (($stats['active_users'] / $stats['total_users']) * 327); ?>;">
                                    </circle>
                                </svg>
                                <div class="position-absolute top-50 start-50 translate-middle text-center">
                                    <h3 class="fw-bold mb-0"><?php echo round(($stats['active_users'] / $stats['total_users']) * 100); ?>%</h3>
                                    <small>Active Rate</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Modules - Only Selected Modules -->
        <div class="row g-4 mb-4">
            <!-- User Lock Management -->
            <div class="col-xl-3 col-lg-4 col-md-6">
                <a href="user_lock_management.php" class="text-decoration-none">
                    <div class="module-card animate__animated animate__fadeInLeft">
                        <div class="module-card-header" style="background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);">
                            <span class="module-badge"><i class="fas fa-lock me-1"></i> Secure</span>
                            <div class="text-center">
                                <i class="fas fa-user-lock module-icon"></i>
                            </div>
                        </div>
                        <div class="module-card-body">
                            <h5 class="module-title">User Lock Management</h5>
                            <p class="module-description">
                                Manage user locks, unlocks, and monitor failed login attempts with detailed history.
                            </p>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <span class="badge bg-danger"><?php echo $stats['locked_users']; ?> Locked</span>
                                <span class="text-primary"><i class="fas fa-arrow-right"></i></span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Admin Credentials -->
            <div class="col-xl-3 col-lg-4 col-md-6">
                <a href="admin_credentials.php" class="text-decoration-none">
                    <div class="module-card animate__animated animate__fadeInUp">
                        <div class="module-card-header" style="background: linear-gradient(135deg, #456882 0%, #234C6A 100%);">
                            <span class="module-badge"><i class="fas fa-key me-1"></i> Admin</span>
                            <div class="text-center">
                                <i class="fas fa-user-shield module-icon"></i>
                            </div>
                        </div>
                        <div class="module-card-body">
                            <h5 class="module-title">Admin Credentials</h5>
                            <p class="module-description">
                                Change admin username and password with strong password requirements and validation.
                            </p>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <span class="badge bg-success">Secure</span>
                                <span class="text-success"><i class="fas fa-arrow-right"></i></span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- System Logs -->
            <div class="col-xl-3 col-lg-4 col-md-6">
                <a href="system_logs.php" class="text-decoration-none">
                    <div class="module-card animate__animated animate__fadeInRight">
                        <div class="module-card-header" style="background: linear-gradient(135deg, #3a6278 0%, #1B3C53 100%);">
                            <span class="module-badge"><i class="fas fa-clipboard-list me-1"></i> Audit</span>
                            <div class="text-center">
                                <i class="fas fa-history module-icon"></i>
                            </div>
                        </div>
                        <div class="module-card-body">
                            <h5 class="module-title">System Logs</h5>
                            <p class="module-description">
                                View system audit logs, user activities, and security events with filtering options.
                            </p>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <span class="badge bg-info">Logs</span>
                                <span class="text-info"><i class="fas fa-arrow-right"></i></span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Payment Verification Settings -->
            <div class="col-xl-3 col-lg-4 col-md-6">
                <a href="payment_verification_settings.php" class="text-decoration-none">
                    <div class="module-card animate__animated animate__fadeInUp">
                        <div class="module-card-header" style="background: linear-gradient(135deg, #2e8b7a 0%, #1B3C53 100%);">
                            <span class="module-badge"><i class="fas fa-money-check me-1"></i> Fees</span>
                            <div class="text-center">
                                <i class="fas fa-credit-card module-icon"></i>
                            </div>
                        </div>
                        <div class="module-card-body">
                            <h5 class="module-title">Payment Verification</h5>
                            <p class="module-description">
                                Configure which batches require payment verification before student login access.
                            </p>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <span class="badge bg-success">Security</span>
                                <span class="text-success"><i class="fas fa-arrow-right"></i></span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- Batch Terms Settings -->
            <div class="col-xl-3 col-lg-4 col-md-6">
                <a href="batch_terms_settings.php" class="text-decoration-none">
                    <div class="module-card animate__animated animate__fadeInUp">
                        <div class="module-card-header" style="background: linear-gradient(135deg, #5a7fa0 0%, #234C6A 100%);">
                            <span class="module-badge"><i class="fas fa-file-contract me-1"></i> Terms</span>
                            <div class="text-center">
                                <i class="fas fa-clipboard-check module-icon"></i>
                            </div>
                        </div>
                        <div class="module-card-body">
                            <h5 class="module-title">Batch Terms Settings</h5>
                            <p class="module-description">
                                Configure which batches require students to accept terms and conditions.
                            </p>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <span class="badge" style="background: #234C6A;"><?php echo $batch_stats['total_batches']; ?> Batches</span>
                                <span style="color: #234C6A;"><i class="fas fa-arrow-right"></i></span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Recent Activity & Quick Actions -->
        <div class="row g-4">
            <!-- Recent Activity -->
            <div class="col-lg-8">
                <div class="bg-white rounded-xl shadow p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0 fw-bold"><i class="fas fa-history text-primary me-2"></i> Recent Security Activities</h4>
                        <a href="system_logs.php" class="text-sm text-primary">View All</a>
                    </div>
                    <div class="activity-timeline">
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item activity-<?php echo $activity['action']; ?>">
                            <div class="bg-gray-50 rounded-lg p-3 mb-3 border border-gray-200" style="background: #faf9f7; border-radius: 16px; box-shadow: inset 0 1px 0 rgba(255,255,255,0.8), 0 2px 4px rgba(0,0,0,0.05);">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <div class="avatar-sm bg-<?php echo $activity['action'] === 'locked' ? 'danger' : 'success'; ?>-subtle rounded-circle p-2">
                                                    <i class="fas fa-<?php echo $activity['action'] === 'locked' ? 'lock' : 'unlock'; ?> text-<?php echo $activity['action'] === 'locked' ? 'danger' : 'success'; ?> fs-4"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h5 class="mb-1"><?php echo htmlspecialchars($activity['user_name']); ?></h5>
                                                <p class="text-muted mb-0">
                                                    Account <?php echo $activity['action']; ?> 
                                                    <?php if ($activity['reason']): ?>
                                                        - <?php echo htmlspecialchars($activity['reason']); ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <p class="text-muted mb-1">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo date('M d, H:i', strtotime($activity['performed_at'])); ?>
                                        </p>
                                        <small class="text-muted">By: <?php echo htmlspecialchars($activity['performed_by']); ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-lg-4">
                <div class="bg-white rounded-xl shadow p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0 fw-bold"><i class="fas fa-bolt text-warning me-2"></i> Quick Actions</h4>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn d-flex align-items-center justify-content-start quick-action qa-navy" onclick="location.href='user_lock_management.php'">
                            <i class="fas fa-user-lock me-3"></i>
                            <div class="text-start">
                                <div class="fw-bold">Manage User Locks</div>
                                <small class="text-muted">View and manage locked accounts</small>
                            </div>
                        </button>
                        
                        <button class="btn d-flex align-items-center justify-content-start quick-action qa-mid" onclick="location.href='admin_credentials.php'">
                            <i class="fas fa-key me-3"></i>
                            <div class="text-start">
                                <div class="fw-bold">Change Credentials</div>
                                <small class="text-muted">Update admin username/password</small>
                            </div>
                        </button>
                        
                        <button class="btn d-flex align-items-center justify-content-start quick-action qa-steel" onclick="location.href='login_settings.php'">
                            <i class="fas fa-cog me-3"></i>
                            <div class="text-start">
                                <div class="fw-bold">Security Settings</div>
                                <small class="text-muted">Configure login security</small>
                            </div>
                        </button>
                        
                        <button class="btn d-flex align-items-center justify-content-start quick-action qa-sand" onclick="location.href='system_logs.php'">
                            <i class="fas fa-history me-3"></i>
                            <div class="text-start">
                                <div class="fw-bold">View Logs</div>
                                <small class="text-muted">Check system audit logs</small>
                            </div>
                        </button>
                        
                        <button class="btn d-flex align-items-center justify-content-start quick-action qa-red" onclick="showEmergencyLock()">
                            <i class="fas fa-shield-alt me-3"></i>
                            <div class="text-start">
                                <div class="fw-bold">Emergency Lock</div>
                                <small class="text-muted">Lock all non-admin accounts</small>
                            </div>
                        </button>
                        
                        <button class="btn d-flex align-items-center justify-content-start quick-action qa-mid" onclick="location.href='batch_terms_settings.php'">
                            <i class="fas fa-file-contract me-3"></i>
                            <div class="text-start">
                                <div class="fw-bold">Batch Terms</div>
                                <small class="text-muted">Configure terms requirements</small>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== DETAIL MODALS ===== -->

    <!-- Total Users Modal -->
    <div class="modal fade detail-modal" id="totalUsersModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg,#234C6A,#1B3C53);">
                    <div class="d-flex align-items-center gap-3 w-100">
                        <div class="modal-hero-number"><?php echo $stats['total_users']; ?></div>
                        <div>
                            <h4 class="mb-0 fw-bold"><i class="fas fa-users me-2"></i>Total Users</h4>
                            <small class="opacity-75">All registered users in the system</small>
                        </div>
                        <div class="ms-auto d-flex gap-2">
                            <span class="modal-stat-badge bg-white text-primary"><?php echo $stats['admin_count']; ?> Admins</span>
                            <span class="modal-stat-badge bg-white text-success"><?php echo $stats['active_users']; ?> Active</span>
                            <span class="modal-stat-badge bg-white text-danger"><?php echo $stats['locked_users']; ?> Locked</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="search-box-modal w-100 mb-3" placeholder="🔍 Search users by name, email or role..." onkeyup="filterTable(this,'totalUsersTable')">
                    <table class="table detail-table" id="totalUsersTable">
                        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Failed Attempts</th><th>Last Login</th><th>Joined</th></tr></thead>
                        <tbody>
                        <?php foreach($all_users as $i=>$u):
                            $uData = json_encode([
                                'id'           => $u['id'],
                                'name'         => $u['name'],
                                'email'        => $u['email'],
                                'role'         => $u['role'],
                                'locked'       => (bool)$u['account_locked'],
                                'failed'       => (int)$u['failed_login_attempts'],
                                'last_login'   => $u['last_login'] ? date('d M Y, H:i', strtotime($u['last_login'])) : null,
                                'created_at'   => date('d M Y', strtotime($u['created_at'])),
                                'reg_number'   => $u['student_id'] ?? null,
                                'phone'        => $u['phone_number'] ?? null,
                                'stu_status'   => $u['student_status'] ?? null,
                            ], JSON_HEX_QUOT | JSON_HEX_APOS);
                        ?>
                        <tr class="user-row" onclick="showUserProfile(<?php echo htmlspecialchars($uData, ENT_QUOTES); ?>)" style="cursor:pointer;" title="Click to view full profile">
                            <td><?php echo $i+1; ?></td>
                            <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><span class="badge" style="background:<?php echo $u['role']==='admin'?'#1B3C53':($u['role']==='mentor'?'#456882':'#b6876a'); ?>"><?php echo ucfirst($u['role']); ?></span></td>
                            <td><?php if($u['account_locked']): ?><span class="badge bg-danger">Locked</span><?php else: ?><span class="badge bg-success">Active</span><?php endif; ?></td>
                            <td><?php echo $u['failed_login_attempts']>0?'<span class="text-danger fw-bold">'.$u['failed_login_attempts'].'</span>':'<span class="text-muted">0</span>'; ?></td>
                            <td><?php echo $u['last_login']?date('d M Y, H:i',strtotime($u['last_login'])):'<span class="text-muted">Never</span>'; ?></td>
                            <td><?php echo date('d M Y',strtotime($u['created_at'])); ?></td>
                            <td><span class="text-muted" style="font-size:0.75rem;"><i class="fas fa-eye"></i></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- User Profile Modal (triggered from Total Users table rows) -->
    <div class="modal fade" id="userProfileModal" tabindex="-1" aria-labelledby="userProfileModalLabel">
        <div class="modal-dialog modal-dialog-centered" style="max-width:540px;">
            <div class="modal-content" style="border-radius:24px;overflow:hidden;border:none;box-shadow:0 30px 60px rgba(0,0,0,0.25);">
                <!-- Gradient header -->
                <div id="upHeader" style="padding:32px 28px 24px;position:relative;">
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="position:absolute;top:16px;right:16px;"></button>
                    <div class="d-flex align-items-center gap-4">
                        <!-- Avatar -->
                        <div id="upAvatar" style="width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,0.25);display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:white;flex-shrink:0;border:3px solid rgba(255,255,255,0.5);backdrop-filter:blur(4px);"></div>
                        <div>
                            <h4 id="upName" class="mb-1 fw-bold text-white" style="font-size:1.4rem;"></h4>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span id="upRoleBadge" class="badge" style="font-size:0.8rem;padding:5px 12px;border-radius:20px;background:rgba(255,255,255,0.25);color:white;"></span>
                                <span id="upStatusBadge" class="badge" style="font-size:0.8rem;padding:5px 12px;border-radius:20px;"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Body -->
                <div class="modal-body p-0" style="background:#f8fafc;">
                    <div class="p-4">
                        <!-- Info grid -->
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="p-3 rounded-3 bg-white" style="box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="d-flex align-items-start gap-2">
                                                <i class="fas fa-envelope mt-1" style="color:#234C6A;font-size:0.9rem;"></i>
                                                <div><div style="font-size:0.72rem;color:#a0aec0;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Email</div><div id="upEmail" style="font-size:0.88rem;font-weight:600;color:#2d3748;word-break:break-all;"></div></div>
                                            </div>
                                        </div>
                                        <div class="col-6" id="upPhoneWrap">
                                            <div class="d-flex align-items-start gap-2">
                                                <i class="fas fa-phone mt-1" style="color:#456882;font-size:0.9rem;"></i>
                                                <div><div style="font-size:0.72rem;color:#a0aec0;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Phone</div><div id="upPhone" style="font-size:0.88rem;font-weight:600;color:#2d3748;"></div></div>
                                            </div>
                                        </div>
                                        <div class="col-6" id="upRegWrap">
                                            <div class="d-flex align-items-start gap-2">
                                                <i class="fas fa-id-badge mt-1" style="color:#b6876a;font-size:0.9rem;"></i>
                                                <div><div style="font-size:0.72rem;color:#a0aec0;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Reg. No.</div><div id="upRegNo" style="font-size:0.88rem;font-weight:600;color:#2d3748;"></div></div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="d-flex align-items-start gap-2">
                                                <i class="fas fa-calendar-plus mt-1" style="color:#456882;font-size:0.9rem;"></i>
                                                <div><div style="font-size:0.72rem;color:#a0aec0;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Joined</div><div id="upJoined" style="font-size:0.88rem;font-weight:600;color:#2d3748;"></div></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Security section -->
                            <div class="col-12">
                                <div class="p-3 rounded-3 bg-white" style="box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                                    <div style="font-size:0.75rem;color:#a0aec0;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px;"><i class="fas fa-shield-alt me-2" style="color:#234C6A;"></i>Security Info</div>
                                    <div class="row g-2">
                                        <div class="col-4 text-center">
                                            <div id="upFailed" style="font-size:1.6rem;font-weight:800;color:#e53e3e;"></div>
                                            <div style="font-size:0.72rem;color:#718096;">Failed Attempts</div>
                                        </div>
                                        <div class="col-4 text-center" style="border-left:1px solid #edf2f7;border-right:1px solid #edf2f7;">
                                            <div id="upLockStatus" style="font-size:0.85rem;font-weight:700;"></div>
                                            <div style="font-size:0.72rem;color:#718096;">Lock Status</div>
                                        </div>
                                        <div class="col-4 text-center">
                                            <div id="upLastLogin" style="font-size:0.78rem;font-weight:600;color:#4a5568;"></div>
                                            <div style="font-size:0.72rem;color:#718096;">Last Login</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Footer actions -->
                    <div class="px-4 pb-4" id="upActions"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Locked Users Modal -->
    <div class="modal fade detail-modal" id="lockedUsersModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg,#c0392b,#922b21);">
                    <div class="d-flex align-items-center gap-3 w-100">
                        <div class="modal-hero-number"><?php echo $stats['locked_users']; ?></div>
                        <div>
                            <h4 class="mb-0 fw-bold"><i class="fas fa-lock me-2"></i>Locked Users</h4>
                            <small class="opacity-75">Accounts currently locked out</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="search-box-modal w-100 mb-3" placeholder="🔍 Search locked users..." onkeyup="filterTable(this,'lockedUsersTable')">
                    <?php if(empty($locked_users_list)): ?>
                    <div class="text-center py-5"><i class="fas fa-lock-open fa-3x text-success mb-3"></i><h5 class="text-muted">No locked users — all accounts are active!</h5></div>
                    <?php else: ?>
                    <table class="table detail-table" id="lockedUsersTable">
                        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Failed Attempts</th><th>Locked At</th><th>Reason</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach($locked_users_list as $i=>$u):
                            $uData = json_encode([
                                'id'         => $u['id'],
                                'name'       => $u['name'],
                                'email'      => $u['email'],
                                'role'       => $u['role'],
                                'locked'     => true,
                                'failed'     => (int)$u['failed_login_attempts'],
                                'last_login' => $u['last_login'] ? date('d M Y, H:i', strtotime($u['last_login'])) : null,
                                'created_at' => isset($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '-',
                                'reg_number' => $u['student_id'] ?? null,
                                'phone'      => $u['phone_number'] ?? null,
                                'stu_status' => null,
                            ], JSON_HEX_QUOT | JSON_HEX_APOS);
                        ?>
                        <tr class="user-row" onclick="showUserProfile(<?php echo htmlspecialchars($uData, ENT_QUOTES); ?>)" style="cursor:pointer;" title="Click to view profile">
                            <td><?php echo $i+1; ?></td>
                            <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo ucfirst($u['role']); ?></span></td>
                            <td><span class="text-danger fw-bold"><?php echo $u['failed_login_attempts']; ?></span></td>
                            <td><?php echo $u['locked_at']?date('d M Y, H:i',strtotime($u['locked_at'])):'<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo $u['reason']?htmlspecialchars($u['reason']):'<span class="text-muted">-</span>'; ?></td>
                            <td><a href="user_lock_management.php" class="btn btn-sm btn-outline-success" onclick="event.stopPropagation()">Unlock</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Failed Attempts Modal -->
    <div class="modal fade detail-modal" id="failedAttemptsModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg,#b6876a,#9c6f55);">
                    <div class="d-flex align-items-center gap-3 w-100">
                        <div class="modal-hero-number"><?php echo $stats['failed_attempt_users']; ?></div>
                        <div>
                            <h4 class="mb-0 fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Failed Attempts</h4>
                            <small class="opacity-75">Users with one or more failed login attempts</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="search-box-modal w-100 mb-3" placeholder="🔍 Search users..." onkeyup="filterTable(this,'failedTable')">
                    <?php if(empty($failed_users_list)): ?>
                    <div class="text-center py-5"><i class="fas fa-check-circle fa-3x text-success mb-3"></i><h5 class="text-muted">No failed login attempts — great security!</h5></div>
                    <?php else: ?>
                    <table class="table detail-table" id="failedTable">
                        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Failed Attempts</th><th>Risk Level</th><th>Last Login</th></tr></thead>
                        <tbody>
                        <?php foreach($failed_users_list as $i=>$u): $risk=$u['failed_login_attempts']>=3?'High':($u['failed_login_attempts']>=2?'Medium':'Low'); $rc=$risk==='High'?'danger':($risk==='Medium'?'warning':'info');
                            $uData = json_encode([
                                'id'         => $u['id'],
                                'name'       => $u['name'],
                                'email'      => $u['email'],
                                'role'       => $u['role'],
                                'locked'     => (bool)$u['account_locked'],
                                'failed'     => (int)$u['failed_login_attempts'],
                                'last_login' => $u['last_login'] ? date('d M Y, H:i', strtotime($u['last_login'])) : null,
                                'created_at' => isset($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '-',
                                'reg_number' => $u['student_id'] ?? null,
                                'phone'      => $u['phone_number'] ?? null,
                                'stu_status' => null,
                            ], JSON_HEX_QUOT | JSON_HEX_APOS);
                        ?>
                        <tr class="user-row" onclick="showUserProfile(<?php echo htmlspecialchars($uData, ENT_QUOTES); ?>)" style="cursor:pointer;" title="Click to view profile">
                            <td><?php echo $i+1; ?></td>
                            <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo ucfirst($u['role']); ?></span></td>
                            <td><span class="fw-bold text-danger"><?php echo $u['failed_login_attempts']; ?></span></td>
                            <td><span class="badge bg-<?php echo $rc; ?>"><?php echo $risk; ?></span></td>
                            <td><?php echo $u['last_login']?date('d M Y, H:i',strtotime($u['last_login'])):'<span class="text-muted">Never</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Users Modal -->
    <div class="modal fade detail-modal" id="activeUsersModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg,#2d7a8a,#1B3C53);">
                    <div class="d-flex align-items-center gap-3 w-100">
                        <div class="modal-hero-number"><?php echo $stats['active_users']; ?></div>
                        <div>
                            <h4 class="mb-0 fw-bold"><i class="fas fa-user-check me-2"></i>Active Users</h4>
                            <small class="opacity-75">All unlocked, accessible accounts</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="search-box-modal w-100 mb-3" placeholder="🔍 Search active users..." onkeyup="filterTable(this,'activeTable')">
                    <table class="table detail-table" id="activeTable">
                        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Last Login</th></tr></thead>
                        <tbody>
                        <?php foreach($active_users_list as $i=>$u):
                            $uData = json_encode([
                                'id'         => $u['id'],
                                'name'       => $u['name'],
                                'email'      => $u['email'],
                                'role'       => $u['role'],
                                'locked'     => false,
                                'failed'     => (int)$u['failed_login_attempts'],
                                'last_login' => $u['last_login'] ? date('d M Y, H:i', strtotime($u['last_login'])) : null,
                                'created_at' => isset($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '-',
                                'reg_number' => $u['student_id'] ?? null,
                                'phone'      => $u['phone_number'] ?? null,
                                'stu_status' => null,
                            ], JSON_HEX_QUOT | JSON_HEX_APOS);
                        ?>
                        <tr class="user-row" onclick="showUserProfile(<?php echo htmlspecialchars($uData, ENT_QUOTES); ?>)" style="cursor:pointer;" title="Click to view profile">
                            <td><?php echo $i+1; ?></td>
                            <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><span class="badge" style="background:<?php echo $u['role']==='admin'?'#1B3C53':($u['role']==='mentor'?'#456882':'#b6876a'); ?>"><?php echo ucfirst($u['role']); ?></span></td>
                            <td><?php echo $u['last_login']?date('d M Y, H:i',strtotime($u['last_login'])):'<span class="text-muted">Never</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Admins Modal -->
    <div class="modal fade detail-modal" id="adminsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg,#456882,#234C6A);">
                    <div class="d-flex align-items-center gap-3 w-100">
                        <div class="modal-hero-number"><?php echo $stats['admin_count']; ?></div>
                        <div>
                            <h4 class="mb-0 fw-bold"><i class="fas fa-crown me-2"></i>Administrators</h4>
                            <small class="opacity-75">Users with admin privileges</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table detail-table" id="adminsTable">
                        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Last Login</th><th>Member Since</th></tr></thead>
                        <tbody>
                        <?php foreach($admin_list as $i=>$u):
                            $uData = json_encode([
                                'id'         => $u['id'],
                                'name'       => $u['name'],
                                'email'      => $u['email'],
                                'role'       => 'admin',
                                'locked'     => (bool)($u['account_locked'] ?? false),
                                'failed'     => (int)($u['failed_login_attempts'] ?? 0),
                                'last_login' => $u['last_login'] ? date('d M Y, H:i', strtotime($u['last_login'])) : null,
                                'created_at' => date('d M Y', strtotime($u['created_at'])),
                                'reg_number' => null,
                                'phone'      => null,
                                'stu_status' => null,
                            ], JSON_HEX_QUOT | JSON_HEX_APOS);
                        ?>
                        <tr class="user-row" onclick="showUserProfile(<?php echo htmlspecialchars($uData, ENT_QUOTES); ?>)" style="cursor:pointer;" title="Click to view profile">
                            <td><?php echo $i+1; ?></td>
                            <td><i class="fas fa-crown text-warning me-2"></i><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><?php echo $u['last_login']?date('d M Y, H:i',strtotime($u['last_login'])):'<span class="text-muted">Never</span>'; ?></td>
                            <td><?php echo date('d M Y',strtotime($u['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Lock Actions Modal -->
    <div class="modal fade detail-modal" id="lockActionsModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg,#3a6278,#1B3C53);">
                    <div class="d-flex align-items-center gap-3 w-100">
                        <div class="modal-hero-number"><?php echo $total_locks; ?></div>
                        <div>
                            <h4 class="mb-0 fw-bold"><i class="fas fa-history me-2"></i>Lock Action History</h4>
                            <small class="opacity-75">Recent 50 lock/unlock events</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="search-box-modal w-100 mb-3" placeholder="🔍 Search lock history..." onkeyup="filterTable(this,'lockTable')">
                    <?php if(empty($lock_logs_list)): ?>
                    <div class="text-center py-5"><i class="fas fa-history fa-3x text-muted mb-3"></i><h5 class="text-muted">No lock actions recorded yet.</h5></div>
                    <?php else: ?>
                    <table class="table detail-table" id="lockTable">
                        <thead><tr><th>#</th><th>User</th><th>Action</th><th>Reason</th><th>Performed By</th><th>Date & Time</th></tr></thead>
                        <tbody>
                        <?php foreach($lock_logs_list as $i=>$l):
                            $uData = json_encode([
                                'id'         => $l['user_id'] ?? 0,
                                'name'       => $l['user_name'] ?? 'N/A',
                                'email'      => $l['email'] ?? '',
                                'role'       => $l['role'] ?? 'user',
                                'locked'     => (bool)($l['account_locked'] ?? false),
                                'failed'     => (int)($l['failed_login_attempts'] ?? 0),
                                'last_login' => isset($l['last_login']) && $l['last_login'] ? date('d M Y, H:i', strtotime($l['last_login'])) : null,
                                'created_at' => isset($l['created_at']) && $l['created_at'] ? date('d M Y', strtotime($l['created_at'])) : '-',
                                'reg_number' => $l['student_id'] ?? null,
                                'phone'      => $l['phone_number'] ?? null,
                                'stu_status' => null,
                            ], JSON_HEX_QUOT | JSON_HEX_APOS);
                        ?>
                        <tr class="user-row" onclick="showUserProfile(<?php echo htmlspecialchars($uData, ENT_QUOTES); ?>)" style="cursor:pointer;" title="Click to view profile">
                            <td><?php echo $i+1; ?></td>
                            <td><strong><?php echo htmlspecialchars($l['user_name']??'N/A'); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($l['email']??''); ?></small></td>
                            <td><?php if($l['action']==='locked'): ?><span class="badge bg-danger"><i class="fas fa-lock me-1"></i>Locked</span><?php else: ?><span class="badge bg-success"><i class="fas fa-unlock me-1"></i>Unlocked</span><?php endif; ?></td>
                            <td><?php echo $l['reason']?htmlspecialchars($l['reason']):'<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo htmlspecialchars($l['performed_by']??'System'); ?></td>
                            <td><?php echo date('d M Y, H:i',strtotime($l['performed_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Terms Modal -->
    <div class="modal fade detail-modal" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg,#5a7fa0,#234C6A);">
                    <div class="d-flex align-items-center gap-3 w-100">
                        <div class="modal-hero-number"><?php echo $acceptance_rate; ?>%</div>
                        <div>
                            <h4 class="mb-0 fw-bold"><i class="fas fa-file-signature me-2"></i>Terms Acceptance</h4>
                            <small class="opacity-75"><?php echo $terms_stats['accepted_count']; ?> accepted · <?php echo $terms_stats['pending_count']; ?> pending</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-4 g-3">
                        <div class="col-4"><div class="p-3 rounded-3 text-center" style="background:#f0fdf4"><div class="fw-bold fs-4 text-success"><?php echo $terms_stats['accepted_count']; ?></div><small class="text-muted">Accepted</small></div></div>
                        <div class="col-4"><div class="p-3 rounded-3 text-center" style="background:#fffbeb"><div class="fw-bold fs-4 text-warning"><?php echo $terms_stats['pending_count']; ?></div><small class="text-muted">Pending</small></div></div>
                        <div class="col-4"><div class="p-3 rounded-3 text-center" style="background:#dce8f0"><div class="fw-bold fs-4" style="color:#234C6A"><?php echo $terms_stats['total_students']; ?></div><small class="text-muted">Total Active Students</small></div></div>
                    </div>
                    <div class="mb-2"><strong>Acceptance Rate Progress</strong></div>
                    <div class="progress" style="height:14px;border-radius:8px;">
                        <div class="progress-bar" style="width:<?php echo $acceptance_rate; ?>%;background:linear-gradient(90deg,#456882,#1B3C53);border-radius:8px;" role="progressbar"><?php echo $acceptance_rate; ?>%</div>
                    </div>
                    <div class="mt-3 text-center"><a href="batch_terms_settings.php" class="btn btn-sm" style="background:#234C6A;color:white;border-radius:12px;padding:8px 20px;"><i class="fas fa-cog me-2"></i>Manage Terms Settings</a></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Batches Modal -->
    <div class="modal fade detail-modal" id="batchesModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg,#2e8b7a,#1B3C53);">
                    <div class="d-flex align-items-center gap-3 w-100">
                        <div class="modal-hero-number"><?php echo $batch_stats['total_batches']; ?></div>
                        <div>
                            <h4 class="mb-0 fw-bold"><i class="fas fa-layer-group me-2"></i>Total Batches</h4>
                            <small class="opacity-75">All batches across every status</small>
                        </div>
                        <div class="ms-auto d-flex gap-2">
                            <span class="modal-stat-badge bg-white text-success"><?php echo $batch_stats['ongoing_batches']; ?> Ongoing</span>
                            <span class="modal-stat-badge bg-white text-warning"><?php echo $batch_stats['upcoming_batches']; ?> Upcoming</span>
                            <span class="modal-stat-badge bg-white text-secondary"><?php echo $batch_stats['completed_batches']; ?> Completed</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="search-box-modal w-100 mb-3" placeholder="🔍 Search batches by ID, course, mentor or status..." onkeyup="filterTable(this,'batchesTable')">
                    <?php if(empty($all_batches)): ?>
                    <div class="text-center py-5"><i class="fas fa-layer-group fa-3x text-muted mb-3"></i><h5 class="text-muted">No batches found.</h5></div>
                    <?php else: ?>
                    <table class="table detail-table" id="batchesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Batch ID</th>
                                <th>Batch Name</th>
                                <th>Mentor</th>
                                <th>Mode</th>
                                <th>Enrolled / Max</th>
                                <th>Status</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($all_batches as $i=>$b):
                            $statusColor = $b['status']==='ongoing' ? 'success' : ($b['status']==='upcoming' ? 'warning' : 'secondary');
                            $statusIcon  = $b['status']==='ongoing' ? 'play-circle' : ($b['status']==='upcoming' ? 'clock' : 'check-circle');
                        ?>
                        <tr>
                            <td><?php echo $i+1; ?></td>
                            <td><strong><?php echo htmlspecialchars($b['batch_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($b['batch_name']); ?></td>
                            <td>
                                <?php if($b['mentor_name']): ?>
                                <i class="fas fa-chalkboard-teacher me-1 text-muted"></i><?php echo htmlspecialchars($b['mentor_name']); ?>
                                <?php else: ?><span class="text-muted">Unassigned</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if($b['mode']): ?>
                                <span class="badge" style="background:<?php echo strtolower($b['mode'])==='online'?'#234C6A':'#2e8b7a'; ?>">
                                    <i class="fas fa-<?php echo strtolower($b['mode'])==='online'?'wifi':'building'; ?> me-1"></i>
                                    <?php echo ucfirst($b['mode']); ?>
                                </span>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-bold"><?php echo (int)$b['current_enrollment']; ?></span>
                                <span class="text-muted"> / <?php echo $b['max_students'] ? (int)$b['max_students'] : '∞'; ?></span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $statusColor; ?>">
                                    <i class="fas fa-<?php echo $statusIcon; ?> me-1"></i>
                                    <?php echo ucfirst($b['status']); ?>
                                </span>
                            </td>
                            <td><?php echo $b['start_date'] ? date('d M Y', strtotime($b['start_date'])) : '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo $b['end_date']   ? date('d M Y', strtotime($b['end_date']))   : '<span class="text-muted">-</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Emergency Lock Modal -->
    <div class="modal fade" id="emergencyLockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i> Emergency Lock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <h6 class="alert-heading"><i class="fas fa-exclamation-circle me-2"></i>Warning</h6>
                        This action will lock ALL non-admin accounts immediately. This should only be used in emergency situations.
                    </div>
                    <p>Are you sure you want to proceed? This action cannot be undone automatically.</p>
                    <div class="mb-3">
                        <label class="form-label">Emergency Reason</label>
                        <textarea class="form-control" rows="3" placeholder="Enter reason for emergency lock..." id="emergencyReason"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="executeEmergencyLock()">
                        <i class="fas fa-shield-alt me-2"></i>Lock All Non-Admin Accounts
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Open a detail modal by ID
        function openModal(modalId) {
            const modal = new bootstrap.Modal(document.getElementById(modalId));
            modal.show();
        }

        // Live search/filter for detail tables
        function filterTable(input, tableId) {
            const filter = input.value.toLowerCase();
            const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
            rows.forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
            });
        }

        // Show user profile modal
        function showUserProfile(data) {
            if (typeof data === 'string') data = JSON.parse(data);

            const roleColors = { admin:'#1B3C53', mentor:'#456882', student:'#b6876a' };
            const color = roleColors[data.role] || '#234C6A';

            // Header gradient
            document.getElementById('upHeader').style.background = `linear-gradient(135deg, ${color}, ${color}cc)`;

            // Avatar initials
            const initials = data.name.split(' ').map(w=>w[0]).join('').toUpperCase().substring(0,2);
            document.getElementById('upAvatar').textContent = initials;

            // Name & badges
            document.getElementById('upName').textContent = data.name;
            document.getElementById('upRoleBadge').textContent = data.role.charAt(0).toUpperCase() + data.role.slice(1);

            const sb = document.getElementById('upStatusBadge');
            if (data.locked) {
                sb.textContent = '🔒 Locked';
                sb.style.background = '#fed7d7'; sb.style.color = '#c53030';
            } else {
                sb.textContent = '✅ Active';
                sb.style.background = '#c6f6d5'; sb.style.color = '#276749';
            }

            // Info fields
            document.getElementById('upEmail').textContent = data.email;
            document.getElementById('upJoined').textContent = data.created_at;

            // Phone
            const phoneWrap = document.getElementById('upPhoneWrap');
            if (data.phone) {
                phoneWrap.style.display = '';
                document.getElementById('upPhone').textContent = data.phone;
            } else {
                phoneWrap.style.display = 'none';
            }

            // Registration number
            const regWrap = document.getElementById('upRegWrap');
            if (data.reg_number) {
                regWrap.style.display = '';
                document.getElementById('upRegNo').textContent = data.reg_number;
            } else {
                regWrap.style.display = 'none';
            }

            // Security
            document.getElementById('upFailed').textContent = data.failed;
            document.getElementById('upFailed').style.color = data.failed > 0 ? '#e53e3e' : '#38a169';
            document.getElementById('upLockStatus').innerHTML = data.locked
                ? '<span style="color:#e53e3e"><i class="fas fa-lock"></i> Locked</span>'
                : '<span style="color:#38a169"><i class="fas fa-unlock"></i> Active</span>';
            document.getElementById('upLastLogin').textContent = data.last_login || 'Never';

            // Action buttons
            let actionsHTML = '<div class="d-flex gap-2 flex-wrap">';
            if (data.role === 'student' && data.reg_number) {
                actionsHTML += `<a href="../student/student_view.php?id=${encodeURIComponent(data.reg_number)}" class="btn btn-sm flex-fill fw-bold text-white" style="background:${color};border-radius:12px;padding:9px 0;"><i class="fas fa-external-link-alt me-2"></i>View Full Profile</a>`;
            }
            actionsHTML += `<a href="user_lock_management.php" class="btn btn-sm flex-fill fw-bold" style="background:#f8fafc;color:#4a5568;border:1.5px solid #e2e8f0;border-radius:12px;padding:9px 0;"><i class="fas fa-user-lock me-2"></i>Manage Lock</a>`;
            actionsHTML += '</div>';
            document.getElementById('upActions').innerHTML = actionsHTML;

            // Show modal (always on top of everything)
            const profileModal = new bootstrap.Modal(document.getElementById('userProfileModal'), { backdrop: false, keyboard: true });

            // Remove any existing custom backdrop
            document.querySelectorAll('.profile-modal-backdrop').forEach(el => el.remove());

            // Add custom backdrop behind profile modal
            const bd = document.createElement('div');
            bd.className = 'profile-modal-backdrop';
            bd.addEventListener('click', () => {
                profileModal.hide();
                bd.remove();
            });
            document.body.appendChild(bd);

            // Clean up backdrop when modal hides
            document.getElementById('userProfileModal').addEventListener('hidden.bs.modal', () => {
                bd.remove();
            }, { once: true });

            profileModal.show();

            // Force z-index after Bootstrap renders it
            requestAnimationFrame(() => {
                const el = document.getElementById('userProfileModal');
                el.style.zIndex = '1200';
                el.style.display = 'block';
            });
        }

        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const content = document.querySelector('.content-wrapper');
            
            if (sidebar.style.display === 'none' || sidebar.style.display === '') {
                sidebar.style.display = 'block';
                content.style.marginLeft = '0';
            } else {
                sidebar.style.display = 'none';
                content.style.marginLeft = '0';
            }
        }

        // Animate statistics on load
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stat numbers
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(number => {
                const finalValue = parseInt(number.textContent);
                let startValue = 0;
                const duration = 2000;
                const increment = finalValue / (duration / 16);
                
                const timer = setInterval(() => {
                    startValue += increment;
                    if (startValue >= finalValue) {
                        number.textContent = finalValue;
                        clearInterval(timer);
                    } else {
                        number.textContent = Math.floor(startValue);
                    }
                }, 16);
            });

            // Add hover effects to module cards
            const moduleCards = document.querySelectorAll('.module-card');
            moduleCards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.transform = 'translateY(-8px)';
                });
                card.addEventListener('mouseleave', () => {
                    card.style.transform = 'translateY(0)';
                });
            });

            // Floating animation for header icon
            const floatingIcon = document.querySelector('.floating-icon');
            if (floatingIcon) {
                setInterval(() => {
                    floatingIcon.style.transform = `translateY(${Math.sin(Date.now() / 1000) * 8}px)`;
                }, 50);
            }
        });

        // Show emergency lock modal
        function showEmergencyLock() {
            const modal = new bootstrap.Modal(document.getElementById('emergencyLockModal'));
            modal.show();
        }

        // Execute emergency lock
        function executeEmergencyLock() {
            const reason = document.getElementById('emergencyReason').value;
            if (!reason.trim()) {
                alert('Please enter a reason for the emergency lock.');
                return;
            }
            
            if (confirm('Are you absolutely sure? This will lock ALL non-admin accounts immediately.')) {
                // Show loading
                const modal = bootstrap.Modal.getInstance(document.getElementById('emergencyLockModal'));
                modal.hide();
                
                // Execute via AJAX
                fetch('emergency_lock.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `reason=${encodeURIComponent(reason)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Emergency lock executed successfully! ' + data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error);
                });
            }
        }

        // Auto-refresh dashboard every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);

        // Responsive adjustments
        window.addEventListener('resize', function() {
            if (window.innerWidth < 768) {
                document.querySelector('.content-wrapper').style.marginLeft = '0';
            } else {
                document.querySelector('.content-wrapper').style.marginLeft = '256px';
            }
        });
    </script>
</body>
</html>