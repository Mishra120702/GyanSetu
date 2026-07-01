<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_user_id = $_SESSION['user_id'];

// Get student information
$student_query = $db->prepare("
    SELECT s.*, u.email 
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get student's leave applications
$applications_query = $db->prepare("
    SELECT * FROM leave_applications 
    WHERE student_id = :student_id 
    ORDER BY created_at DESC
");
$applications_query->execute([':student_id' => $student['student_id']]);
$applications = $applications_query->fetchAll(PDO::FETCH_ASSOC);

// Get pending count
$pending_count = 0;
foreach ($applications as $app) {
    if ($app['status'] === 'pending') $pending_count++;
}

// Get approved count
$approved_count = 0;
foreach ($applications as $app) {
    if ($app['status'] === 'approved') $approved_count++;
}

// Get rejected count
$rejected_count = 0;
foreach ($applications as $app) {
    if ($app['status'] === 'rejected') $rejected_count++;
}

// Get cancelled count
$cancelled_count = 0;
foreach ($applications as $app) {
    if ($app['status'] === 'cancelled') $cancelled_count++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Leave Applications - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ─── Color Palette Variables ─── */
        :root {
            --primary-dark: #1B3C53;
            --primary: #234C6A;
            --primary-light: #456882;
            --neutral: #D2C1B6;
            --neutral-bg: #F5F0EB;
            --neutral-light: #E8E0D9;
            --white: #ffffff;
            --shadow: 0 8px 32px rgba(27, 60, 83, 0.10);
            --shadow-hover: 0 16px 48px rgba(27, 60, 83, 0.18);
            --radius: 1.25rem;
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ── Keyframes ─────────────────────────────────── */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(22px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                transform: translateX(-28px);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes floatIcon {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-7px);
            }
        }

        @keyframes headerShine {
            0% {
                background-position: -300% center;
            }

            100% {
                background-position: 300% center;
            }
        }

        .animate-fade-in {
            animation: fadeInUp 0.7s ease-out both;
        }

        .animate-slide-in {
            animation: slideInLeft 0.6s ease-out both;
        }

        .delay-100 {
            animation-delay: 0.10s;
        }

        .delay-150 {
            animation-delay: 0.15s;
        }

        .delay-200 {
            animation-delay: 0.20s;
        }

        .delay-300 {
            animation-delay: 0.30s;
        }

        .delay-400 {
            animation-delay: 0.40s;
        }

        .delay-500 {
            animation-delay: 0.50s;
        }

        /* ── Body / background ─────────────────────────── */
        body {
            background: linear-gradient(150deg, #f8f5f0 0%, #f0ebe6 45%, #e8e0d9 100%);
            min-height: 100vh;
        }

        /* ── Stat cards ────────────────────────────────── */
        .stat-card {
            position: relative;
            overflow: hidden;
            border-radius: 1.25rem;
            transition: transform 0.28s ease, box-shadow 0.28s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 22px 44px rgba(0, 0, 0, 0.19);
        }

        /* Decorative circles */
        .stat-card .orb-a {
            position: absolute;
            bottom: -28px;
            right: -28px;
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.13);
            pointer-events: none;
        }

        .stat-card .orb-b {
            position: absolute;
            top: -14px;
            left: 45%;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }

        /* Gloss sheen overlay */
        .stat-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.20) 0%, transparent 55%);
            pointer-events: none;
        }

        /* Stat numbers – tabular digits */
        .stat-num {
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum";
        }

        /* Progress bar inside stat card */
        .stat-bar-track {
            height: 4px;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 14px;
        }

        .stat-bar-fill {
            height: 100%;
            border-radius: 2px;
            background: rgba(255, 255, 255, 0.72);
            transition: width 1s ease;
        }

        /* ── Section card (applications list) ─────────── */
        .section-card {
            background: #ffffff;
            border-radius: 1.25rem;
            box-shadow: 0 4px 28px rgba(27, 60, 83, 0.08), 0 1px 4px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .section-card-header {
            padding: 1.4rem 1.75rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title-accent {
            width: 4px;
            height: 22px;
            border-radius: 2px;
            background: linear-gradient(180deg, #1B3C53, #456882);
            display: inline-block;
            margin-right: 10px;
            flex-shrink: 0;
        }

        /* ── New application button ────────────────────── */
        .btn-new-app {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 20px;
            background: linear-gradient(135deg, #1B3C53 0%, #456882 100%);
            color: #fff;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.85rem;
            box-shadow: 0 4px 14px rgba(27, 60, 83, 0.38);
            transition: all 0.25s ease;
            text-decoration: none;
        }

        .btn-new-app:hover {
            background: linear-gradient(135deg, #0f2838 0%, #234C6A 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(27, 60, 83, 0.46);
        }

        /* ── Application cards ─────────────────────────── */
        .app-card {
            position: relative;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.25s ease;
        }

        .app-card:hover {
            box-shadow: 0 12px 34px rgba(0, 0, 0, 0.10);
            transform: translateY(-3px);
            border-color: #D2C1B6;
        }

        /* Coloured left strip */
        .app-card .status-strip {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
        }

        .strip-pending {
            background: linear-gradient(180deg, #f59e0b, #f97316);
        }

        .strip-approved {
            background: linear-gradient(180deg, #10b981, #059669);
        }

        .strip-rejected {
            background: linear-gradient(180deg, #f43f5e, #dc2626);
        }

        .strip-cancelled {
            background: linear-gradient(180deg, #94a3b8, #64748b);
        }

        /* Inner padding accounts for the strip */
        .app-card-body {
            padding: 1.1rem 1.2rem 1.1rem 1.4rem;
        }

        /* Status icon bubble */
        .status-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
        }

        .icon-pending {
            background: #fef3c7;
            color: #d97706;
        }

        .icon-approved {
            background: #d1fae5;
            color: #059669;
        }

        .icon-rejected {
            background: #fee2e2;
            color: #dc2626;
        }

        .icon-cancelled {
            background: #f1f5f9;
            color: #64748b;
        }

        /* App number chip */
        .app-chip {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 100px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            background: #E8E0D9;
            color: #1B3C53;
        }

        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 0.70rem;
            font-weight: 700;
            transition: transform 0.2s ease;
        }

        .status-badge:hover {
            transform: translateY(-1px);
        }

        .badge-pending {
            background: #fef9c3;
            color: #92400e;
        }

        .badge-approved {
            background: #dcfce7;
            color: #166534;
        }

        .badge-rejected {
            background: #ffe4e6;
            color: #9f1239;
        }

        .badge-cancelled {
            background: #f1f5f9;
            color: #475569;
        }

        /* Meta info pills */
        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 100px;
            font-size: 0.70rem;
            color: #64748b;
            font-weight: 500;
        }

        .meta-pill i {
            font-size: 0.65rem;
        }

        /* Days highlight pill */
        .days-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 100px;
            background: #f0f4ff;
            color: #1B3C53;
            font-size: 0.70rem;
            font-weight: 700;
            border: 1px solid #D2C1B6;
        }

        /* Approval / rejection note */
        .note-approved {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            background: #f0fdf4;
            border-left: 3px solid #22c55e;
            color: #15803d;
            padding: 5px 10px;
            border-radius: 0 7px 7px 0;
            font-size: 0.70rem;
            margin-top: 8px;
        }

        .note-rejected {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            background: #fff1f2;
            border-left: 3px solid #f43f5e;
            color: #be123c;
            padding: 5px 10px;
            border-radius: 0 7px 7px 0;
            font-size: 0.70rem;
            margin-top: 8px;
        }

        /* Action buttons with tooltips */
        .action-wrap {
            position: relative;
            display: inline-block;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-view {
            background: #eff6ff;
            color: #1B3C53;
        }

        .btn-view:hover {
            background: #1B3C53;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(27, 60, 83, 0.3);
        }

        .btn-cancel {
            background: #fff1f2;
            color: #dc2626;
        }

        .btn-cancel:hover {
            background: #dc2626;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.3);
        }

        .btn-dl {
            background: #f0fdf4;
            color: #16a34a;
        }

        .btn-dl:hover {
            background: #16a34a;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(22, 163, 74, 0.3);
        }

        .action-wrap .tip {
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.18s ease;
        }

        .action-wrap:hover .tip {
            opacity: 1;
        }

        /* ── Empty state ───────────────────────────────── */
        .empty-icon-wrap {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(135deg, #E8E0D9, #D2C1B6);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: floatIcon 4s ease-in-out infinite;
        }

        /* ── Header ────────────────────────────────────── */
        .page-header {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 55%, #456882 100%);
            position: relative;
            overflow: visible;
        }

        /* subtle cross-hatch overlay */
        .page-header::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M0 20 L40 20 M20 0 L20 40' stroke='%23ffffff' stroke-width='0.4' stroke-opacity='0.08'/%3E%3C/svg%3E");
            clip-path: inset(0);
        }

        /* ── Modal ─────────────────────────────────────── */
        .modal-header {
            background: linear-gradient(135deg, #1B3C53, #456882);
            border-radius: 1.25rem 1.25rem 0 0;
            padding: 1.4rem 1.75rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../s_sidebar.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">

        <!-- ── Mobile Header ─────────────────────────── -->
        <header class="page-header shadow-lg px-4 py-4 flex justify-between items-center sticky top-0 z-30 md:hidden">
            <button class="text-white text-xl" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-base font-bold text-white flex items-center gap-2">
                <span class="bg-white bg-opacity-20 w-8 h-8 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-alt text-white text-sm"></i>
                </span>
                My Leave Applications
            </h1>
            <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center shadow">
                <i class="fas fa-user-graduate text-[#1B3C53] text-sm"></i>
            </div>
        </header>

        <!-- ── Desktop Header ────────────────────────── -->
        <header class="page-header hidden md:flex shadow-lg px-8 py-5 justify-between items-center sticky top-0 z-30">
            <div class="flex-1"></div>

            <div class="flex items-center gap-3">
                <span class="bg-white bg-opacity-20 p-2.5 rounded-xl">
                    <i class="fas fa-calendar-alt text-white text-xl"></i>
                </span>
                <h1 class="text-2xl font-bold text-white tracking-tight">My Leave Applications</h1>
            </div>

            <div class="flex-1 flex justify-end items-center gap-4">
                <div class="relative group">
                    <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center cursor-pointer shadow-md group-hover:scale-110 transition-transform">
                        <i class="fas fa-user-graduate" style="color: #1B3C53;"></i>
                    </div>
                    <!-- Dropdown -->
                    <div class="absolute right-0 mt-2 w-52 bg-white rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-250 translate-y-2 group-hover:translate-y-0 z-[200] border border-gray-100">
                        <div class="p-4 border-b border-gray-100">
                            <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                            <p class="text-xs font-medium mt-0.5" style="color: #1B3C53;"><?= htmlspecialchars($student['student_id']) ?></p>
                        </div>
                        <a href="../stu_dash/student_profile.php" class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 hover:bg-[#F5F0EB] hover:text-[#1B3C53] transition-colors">
                            <i class="fas fa-user w-4 text-center"></i> Profile
                        </a>
                        <a href="../logout.php" class="flex items-center gap-2 px-4 py-2.5 text-sm text-rose-600 hover:bg-rose-50 transition-colors rounded-b-xl">
                            <i class="fas fa-sign-out-alt w-4 text-center"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- ── Main Content ───────────────────────────── -->
        <div class="p-4 md:p-8">

            <!-- Stats Cards -->
            <?php $total = count($applications); ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-7">

                <!-- Total -->
                <div class="stat-card text-white p-5 shadow-lg animate-fade-in delay-100" style="background: linear-gradient(135deg, #1B3C53, #234C6A);">
                    <div class="orb-a"></div><div class="orb-b"></div>
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <p class="text-blue-100 text-xs font-semibold uppercase tracking-wider">Total</p>
                            <h3 class="stat-num text-4xl font-extrabold mt-2 leading-none"><?= $total ?></h3>
                            <p class="text-blue-200 text-xs mt-1">Applications</p>
                        </div>
                        <div class="bg-white bg-opacity-25 p-3 rounded-xl">
                            <i class="fas fa-layer-group text-xl"></i>
                        </div>
                    </div>
                    <div class="stat-bar-track relative z-10">
                        <div class="stat-bar-fill" style="width:100%"></div>
                    </div>
                </div>

                <!-- Pending -->
                <div class="stat-card bg-gradient-to-br from-amber-400 via-orange-400 to-orange-500 text-white p-5 shadow-lg animate-fade-in delay-200">
                    <div class="orb-a"></div><div class="orb-b"></div>
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <p class="text-amber-100 text-xs font-semibold uppercase tracking-wider">Pending</p>
                            <h3 class="stat-num text-4xl font-extrabold mt-2 leading-none"><?= $pending_count ?></h3>
                            <p class="text-amber-100 text-xs mt-1">Awaiting review</p>
                        </div>
                        <div class="bg-white bg-opacity-25 p-3 rounded-xl">
                            <i class="fas fa-hourglass-half text-xl"></i>
                        </div>
                    </div>
                    <div class="stat-bar-track relative z-10">
                        <div class="stat-bar-fill" style="width:<?= $total > 0 ? round($pending_count / $total * 100) : 0 ?>%"></div>
                    </div>
                </div>

                <!-- Approved -->
                <div class="stat-card bg-gradient-to-br from-emerald-400 via-green-500 to-teal-500 text-white p-5 shadow-lg animate-fade-in delay-300">
                    <div class="orb-a"></div><div class="orb-b"></div>
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <p class="text-emerald-100 text-xs font-semibold uppercase tracking-wider">Approved</p>
                            <h3 class="stat-num text-4xl font-extrabold mt-2 leading-none"><?= $approved_count ?></h3>
                            <p class="text-emerald-100 text-xs mt-1">Granted leaves</p>
                        </div>
                        <div class="bg-white bg-opacity-25 p-3 rounded-xl">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                    </div>
                    <div class="stat-bar-track relative z-10">
                        <div class="stat-bar-fill" style="width:<?= $total > 0 ? round($approved_count / $total * 100) : 0 ?>%"></div>
                    </div>
                </div>

                <!-- Rejected -->
                <div class="stat-card bg-gradient-to-br from-rose-400 via-red-500 to-pink-600 text-white p-5 shadow-lg animate-fade-in delay-400">
                    <div class="orb-a"></div><div class="orb-b"></div>
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <p class="text-rose-100 text-xs font-semibold uppercase tracking-wider">Rejected</p>
                            <h3 class="stat-num text-4xl font-extrabold mt-2 leading-none"><?= $rejected_count ?></h3>
                            <p class="text-rose-100 text-xs mt-1">Declined leaves</p>
                        </div>
                        <div class="bg-white bg-opacity-25 p-3 rounded-xl">
                            <i class="fas fa-times-circle text-xl"></i>
                        </div>
                    </div>
                    <div class="stat-bar-track relative z-10">
                        <div class="stat-bar-fill" style="width:<?= $total > 0 ? round($rejected_count / $total * 100) : 0 ?>%"></div>
                    </div>
                </div>

            </div><!-- /grid -->

            <!-- Applications List -->
            <div class="section-card animate-fade-in delay-500">

                <!-- Card Header -->
                <div class="section-card-header">
                    <div class="flex items-center">
                        <span class="section-title-accent"></span>
                        <div>
                            <h3 class="text-base font-bold text-gray-800 leading-tight">All Leave Applications</h3>
                            <p class="text-xs text-gray-400 mt-0.5">Your complete leave history</p>
                        </div>
                    </div>
                    <a href="leaves/apply_leave.php" class="btn-new-app">
                        <i class="fas fa-plus"></i> New Application
                    </a>
                </div>

                <div class="p-5">
                    <?php if (empty($applications)): ?>
                        <!-- Empty state -->
                        <div class="text-center py-14">
                            <div class="empty-icon-wrap">
                                <i class="fas fa-calendar-times text-[#234C6A] text-3xl"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800 mb-1">No Applications Yet</h3>
                            <p class="text-gray-500 text-sm max-w-xs mx-auto">You haven't submitted any leave applications. Start by clicking the button below.</p>
                            <a href="leaves/apply_leave.php" class="btn-new-app inline-flex mt-6">
                                <i class="fas fa-plus"></i> Apply for Leave
                            </a>
                        </div>

                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($applications as $i => $app):
                                $st = $app['status'];
                            ?>
                                <div class="app-card animate-fade-in" style="animation-delay:<?= 0.05 * $i ?>s">
                                    <!-- Status strip -->
                                    <div class="status-strip strip-<?= $st ?>"></div>

                                    <div class="app-card-body">
                                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">

                                            <!-- Left: icon + info -->
                                            <div class="flex items-start gap-3">
                                                <!-- Icon bubble -->
                                                <div class="status-icon icon-<?= $st ?>">
                                                    <?php if ($st === 'approved'): ?>
                                                        <i class="fas fa-check-circle"></i>
                                                    <?php elseif ($st === 'rejected'): ?>
                                                        <i class="fas fa-times-circle"></i>
                                                    <?php elseif ($st === 'cancelled'): ?>
                                                        <i class="fas fa-ban"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-hourglass-half"></i>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Text info -->
                                                <div class="flex-1 min-w-0">
                                                    <!-- Row 1: app number + status badge -->
                                                    <div class="flex items-center flex-wrap gap-2 mb-1.5">
                                                        <span class="app-chip">
                                                            <i class="fas fa-hashtag" style="font-size:0.6rem"></i>
                                                            <?= htmlspecialchars($app['application_no']) ?>
                                                        </span>
                                                        <span class="status-badge badge-<?= $st ?>">
                                                            <?php if ($st === 'approved'): ?><i class="fas fa-check" style="font-size:0.6rem"></i>
                                                            <?php elseif ($st === 'rejected'): ?><i class="fas fa-times" style="font-size:0.6rem"></i>
                                                            <?php elseif ($st === 'cancelled'): ?><i class="fas fa-ban" style="font-size:0.6rem"></i>
                                                            <?php else: ?><i class="fas fa-clock" style="font-size:0.6rem"></i><?php endif; ?>
                                                            <?= ucfirst($st) ?>
                                                        </span>
                                                    </div>

                                                    <!-- Row 2: date range + days + category -->
                                                    <div class="flex items-center flex-wrap gap-2">
                                                        <span class="meta-pill">
                                                            <i class="fas fa-calendar-alt"></i>
                                                            <?= date('d M Y', strtotime($app['start_date'])) ?> &ndash; <?= date('d M Y', strtotime($app['end_date'])) ?>
                                                        </span>
                                                        <span class="days-pill">
                                                            <i class="fas fa-sun" style="font-size:0.6rem"></i>
                                                            <?= $app['total_days'] ?> day<?= $app['total_days'] != 1 ? 's' : '' ?>
                                                        </span>
                                                        <span class="meta-pill">
                                                            <i class="fas fa-tag"></i>
                                                            <?= htmlspecialchars($app['reason_category']) ?>
                                                        </span>
                                                    </div>

                                                    <!-- Row 3: approval / rejection note -->
                                                    <?php if ($st === 'approved' && $app['approved_at']): ?>
                                                        <div class="note-approved">
                                                            <i class="fas fa-check-circle mt-0.5"></i>
                                                            <span>Approved on <?= date('d M Y, h:i A', strtotime($app['approved_at'])) ?></span>
                                                        </div>
                                                    <?php elseif ($st === 'rejected' && $app['rejection_reason']): ?>
                                                        <div class="note-rejected">
                                                            <i class="fas fa-times-circle mt-0.5"></i>
                                                            <span>Reason: <?= htmlspecialchars($app['rejection_reason']) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Right: action buttons -->
                                            <div class="flex items-center gap-2 mt-2 md:mt-0 flex-shrink-0">
                                                <div class="action-wrap">
                                                    <button onclick="viewApplication(<?= $app['id'] ?>)" class="action-btn btn-view">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <span class="tip">View</span>
                                                </div>

                                                <?php if ($st === 'pending'): ?>
                                                    <div class="action-wrap">
                                                        <button onclick="cancelApplication(<?= $app['id'] ?>)" class="action-btn btn-cancel">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                        <span class="tip">Cancel</span>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="action-wrap">
                                                    <button onclick="downloadApplication(<?= $app['id'] ?>)" class="action-btn btn-dl">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                    <span class="tip">Download</span>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- /section-card -->

        </div><!-- /main content -->
    </div><!-- /flex-1 -->

    <!-- ── View Application Modal ──────────────────────── -->
    <div id="viewModal" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-y-auto">
            <div class="modal-header">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-bold text-white flex items-center gap-3">
                        <span class="bg-white bg-opacity-20 w-9 h-9 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-alt"></i>
                        </span>
                        Application Details
                    </h2>
                    <button onclick="closeViewModal()" class="w-9 h-9 rounded-lg bg-white bg-opacity-20 hover:bg-opacity-30 text-white flex items-center justify-center transition-colors">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="p-6" id="applicationDetails">
                <!-- Dynamic content loaded here -->
            </div>
        </div>
    </div>

    <script>
        // View application
        function viewApplication(id) {
            fetch(`leaves/get_application.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('applicationDetails').innerHTML = data.html;
                        document.getElementById('viewModal').classList.remove('hidden');
                    } else {
                        showToast('Failed to load application details', 'error');
                    }
                });
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.add('hidden');
        }

        // Cancel application
        function cancelApplication(id) {
            if (confirm('Are you sure you want to cancel this application?')) {
                fetch('leaves/cancel_application.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Application cancelled successfully', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast(data.message || 'Failed to cancel application', 'error');
                    }
                });
            }
        }

        // Download application
        function downloadApplication(id) {
            window.location.href = `leaves/download_application.php?id=${id}`;
        }

        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 p-4 rounded-xl shadow-2xl text-white z-50 transform transition-all duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-emerald-500' : type === 'error' ? 'bg-rose-500' : 'bg-blue-500'}`;
            toast.innerHTML = `
                <div class="flex items-center gap-2">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                    <span class="text-sm font-medium">${message}</span>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => { toast.classList.add('translate-x-0', 'opacity-100'); }, 10);
            setTimeout(() => {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => document.body.removeChild(toast), 300);
            }, 3000);
        }

        // Toggle mobile menu
        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        }
    </script>
</body>
</html>