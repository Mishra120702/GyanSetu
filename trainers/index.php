<?php
require_once '../db_connection.php';
require_once 'functions.php';
require_once 'filters.php';

// Check admin permissions
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['user_role'] !== 'admin') {
    header("Location: ../unauthorized.php");
    exit;
}

// Get filters from request
$filters = getTrainerFilters($_GET);

// Get pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get trainers with filters
$trainers = getFilteredTrainers($filters, $perPage, $offset);
$totalTrainers = getTotalFilteredTrainers($filters);
$totalPages = ceil($totalTrainers / $perPage);

// Get performance stats for all trainers
$performanceStats = getTrainersPerformanceStats();

// Get all specializations for filter dropdown
$allSpecializations = getTrainerSpecializations();

// Get status distribution for chart
$statusDistribution = getTrainerStatusDistribution();
function getTrainerStatusDistribution(): array {
    global $db;
    $active = 0;
    $inactive = 0;

    $stmt = $db->query("SELECT is_active, COUNT(*) as count FROM trainers GROUP BY is_active");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['is_active']) {
            $active = (int)$row['count'];
        } else {
            $inactive = (int)$row['count'];
        }
    }
    return [
        'active' => $active,
        'inactive' => $inactive
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainer Management — ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════════════════════
           DESIGN SYSTEM — Navy/Sand Theme
           ═══════════════════════════════════════════════════════════════════════════ */

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy-deep:   #1B3C53;
            --navy-mid:    #234C6A;
            --navy-light:  #456882;
            --sand:        #D2C1B6;
            --sand-light:  #e8ddd8;
            --sand-faint:  #f5f0ee;
            --white:       #ffffff;
            --text-primary: #1B3C53;
            --text-secondary: #456882;
            --text-muted:  #7a9ab0;
            --border-light: rgba(69,104,130,0.18);
            --border-medium: rgba(69,104,130,0.30);
            --shadow-sm: 0 2px 8px rgba(27,60,83,0.06);
            --shadow-md: 0 4px 20px rgba(27,60,83,0.10);
            --shadow-lg: 0 12px 36px rgba(27,60,83,0.14);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 18px;
            --radius-xl: 24px;
            --sidebar-w: 260px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(160deg, var(--sand-faint) 0%, var(--sand-light) 100%);
            color: var(--text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── Layout ── */
        .layout { display: flex; min-height: 100vh; }
        .main-area { flex: 1; margin-left: var(--sidebar-w); display: flex; flex-direction: column; min-height: 100vh; background: transparent; }

        /* ── Top Bar ── */
        .topbar {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,253,248,0.92);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-light);
            padding: 0 32px;
            height: 64px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 1px 0 0 rgba(69,104,130,0.08);
        }
        .topbar-left { display: flex; align-items: center; gap: 12px; }
        .topbar-title { font-size: 18px; font-weight: 700; color: var(--navy-deep); letter-spacing: -0.02em; }
        .topbar-breadcrumb { font-size: 13px; color: var(--text-muted); }
        .topbar-right { display: flex; align-items: center; gap: 10px; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 13.5px; font-weight: 500; font-family: 'Inter', sans-serif;
            padding: 0 16px; height: 36px; border-radius: var(--radius-sm);
            cursor: pointer; transition: all 0.18s ease;
            text-decoration: none; border: none; white-space: nowrap;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            color: #fff;
            box-shadow: 0 2px 10px rgba(27,60,83,0.25);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
            box-shadow: 0 6px 20px rgba(27,60,83,0.35);
            transform: translateY(-1px);
            color: #fff;
        }
        .btn-ghost {
            background: rgba(255,255,255,0.7);
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
            backdrop-filter: blur(4px);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.9);
            color: var(--navy-deep);
            border-color: var(--border-medium);
        }
        .btn-danger-ghost { background: transparent; color: #b91c1c; border: 1px solid #fca5a5; }
        .btn-danger-ghost:hover { background: #fee2e2; }
        .btn-sm { height: 30px; padding: 0 12px; font-size: 12.5px; gap: 5px; }
        .btn-icon { width: 32px; height: 32px; padding: 0; justify-content: center; }

        /* ── Page Content ── */
        .page-content { padding: 28px 32px; flex: 1; }

        /* ═══════════════════════════════════════════════════════════════════════════
           HERO BANNER (simplified)
           ═══════════════════════════════════════════════════════════════════════════ */

        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 40%, #456882 75%, #D2C1B6 100%);
            border-radius: var(--radius-xl);
            padding: 20px 32px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(27,60,83,0.20);
            border: 1px solid rgba(210,193,182,0.20);
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 20% 50%, rgba(255,255,255,0.10) 0%, transparent 50%),
                        radial-gradient(ellipse at 80% 30%, rgba(210,193,182,0.15) 0%, transparent 40%);
            pointer-events: none;
        }
        .hero-banner .hero-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
        }
        .hero-banner .hero-left {
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .hero-banner .hero-icon {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.15);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: #fff;
            border: 1.5px solid rgba(255,255,255,0.20);
            backdrop-filter: blur(4px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }
        .hero-banner .hero-text h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
            margin: 0;
            line-height: 1.2;
            text-shadow: 0 2px 4px rgba(0,0,0,0.10);
        }
        .hero-banner .hero-text p {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.80);
            margin: 2px 0 0;
            text-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }

        /* ── Decorative bubbles ── */
        .hero-banner .bubble {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.06);
            pointer-events: none;
        }
        .hero-banner .bubble:nth-child(1) { width: 80px; height: 80px; top: -20px; right: 10%; animation: floatBubble 12s infinite ease-in-out; }
        .hero-banner .bubble:nth-child(2) { width: 120px; height: 120px; bottom: -40px; left: 20%; animation: floatBubble 18s infinite ease-in-out reverse; }
        .hero-banner .bubble:nth-child(3) { width: 50px; height: 50px; top: 40%; right: 25%; animation: floatBubble 10s infinite ease-in-out 2s; }

        @keyframes floatBubble {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(20px, -30px) scale(1.05); }
            66% { transform: translate(-10px, 20px) scale(0.95); }
        }

        /* ═══════════════════════════════════════════════════════════════════════════
           KPI STAT CARDS — Compact & Colorful
           ═══════════════════════════════════════════════════════════════════════════ */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #ffffff;
            border-radius: var(--radius);
            padding: 14px 18px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            cursor: default;
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        /* Accent line on top – colored per card */
        .stat-card .accent-line {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: var(--radius) var(--radius) 0 0;
            transition: height 0.3s ease;
        }
        .stat-card:hover .accent-line {
            height: 4px;
        }

        /* Background gradient overlay on hover */
        .stat-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(27,60,83,0.02), rgba(69,104,130,0.04));
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: inherit;
            pointer-events: none;
        }
        .stat-card:hover::after { opacity: 1; }

        .stat-card .stat-content {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .stat-card .stat-icon-wrap {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            color: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s;
        }
        .stat-card:hover .stat-icon-wrap {
            transform: scale(1.08) rotate(-3deg);
            box-shadow: 0 6px 16px rgba(0,0,0,0.14);
        }

        .stat-card .stat-info {
            flex: 1;
            min-width: 0;
        }
        .stat-card .stat-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
            color: var(--text-muted);
        }
        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--navy-deep);
            line-height: 1.1;
            margin-top: 1px;
        }
        .stat-card .stat-sub {
            font-size: 0.65rem;
            color: var(--text-muted);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .stat-card .stat-sub .up { color: #059669; }
        .stat-card .stat-sub .down { color: #b91c1c; }

        /* Progress bar at bottom */
        .stat-card .stat-bar {
            width: 100%;
            height: 3px;
            background: var(--border-light);
            border-radius: 99px;
            margin-top: 8px;
            overflow: hidden;
            transition: height 0.3s ease;
        }
        .stat-card:hover .stat-bar {
            height: 4px;
        }
        .stat-card .stat-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.6s ease, background 0.3s ease;
        }
        .stat-card:hover .stat-bar-fill {
            background: linear-gradient(90deg, var(--navy-light), #5a7f98);
        }

        /* Variants */
        .stat-total .accent-line { background: linear-gradient(90deg, var(--navy-deep), var(--navy-mid)); }
        .stat-total .stat-icon-wrap { background: linear-gradient(135deg, var(--navy-deep), var(--navy-light)); }
        .stat-total .stat-bar-fill { background: linear-gradient(90deg, var(--navy-deep), var(--navy-light)); width: 100%; }

        .stat-active .accent-line { background: linear-gradient(90deg, #059669, #34d399); }
        .stat-active .stat-icon-wrap { background: linear-gradient(135deg, #059669, #34d399); }
        .stat-active .stat-bar-fill { background: linear-gradient(90deg, #059669, #34d399); width: 78%; }

        .stat-rating .accent-line { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .stat-rating .stat-icon-wrap { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .stat-rating .stat-bar-fill { background: linear-gradient(90deg, #f59e0b, #fbbf24); width: 64%; }

        .stat-batches .accent-line { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
        .stat-batches .stat-icon-wrap { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
        .stat-batches .stat-bar-fill { background: linear-gradient(90deg, #8b5cf6, #a78bfa); width: 42%; }

        /* ── Filter Bar ── */
        .filter-bar {
            background: rgba(255,253,248,0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.2s;
        }
        .filter-bar:hover { box-shadow: var(--shadow-md); }

        .filter-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
        .filter-group { flex: 1; min-width: 140px; }
        .filter-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 5px;
        }
        .filter-input {
            width: 100%; height: 36px; padding: 0 12px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            background: rgba(255,255,255,0.7);
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            appearance: none; -webkit-appearance: none;
        }
        .filter-input:focus {
            outline: none;
            border-color: var(--navy-light);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
            background: #fff;
        }
        .filter-search-wrap { position: relative; }
        .filter-search-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
            pointer-events: none;
            z-index: 2;
        }
        .filter-search-wrap input { padding-left: 38px; }

        .filter-actions { display: flex; gap: 8px; align-items: center; }

        /* ── Bulk Bar ── */
        .bulk-bar {
            display: none;
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            color: #fff;
            border-radius: var(--radius-lg);
            padding: 12px 20px;
            margin-bottom: 16px;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 18px rgba(27,60,83,0.3);
        }
        .bulk-bar.active { display: flex; }
        .bulk-bar-label { font-size: 13.5px; font-weight: 600; }
        .bulk-bar-actions { display: flex; gap: 8px; }
        .bulk-btn {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 12px; font-weight: 500; padding: 4px 12px;
            border-radius: 6px; cursor: pointer; border: none;
            font-family: 'Inter', sans-serif;
            background: rgba(255,255,255,0.15); color: #fff;
            transition: background 0.15s;
        }
        .bulk-btn:hover { background: rgba(255,255,255,0.25); }
        .bulk-btn.bulk-danger { background: rgba(239,68,68,0.2); }
        .bulk-btn.bulk-danger:hover { background: rgba(239,68,68,0.35); }

        /* ── Section Header ── */
        .section-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .section-title {
            font-size: 15px; font-weight: 600; color: var(--text-primary);
        }
        .section-count {
            font-size: 12px; font-weight: 500; color: var(--text-muted);
            background: rgba(255,255,255,0.7);
            border: 1px solid var(--border-light);
            border-radius: 20px; padding: 2px 10px;
        }

        /* ── Trainer Card — with gradient header ── */
        .trainer-card {
            background: #ffffff;
            border-radius: var(--radius-xl);
            overflow: hidden;
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 12px rgba(27,60,83,0.07), 0 1px 3px rgba(27,60,83,0.05);
            border: 1px solid rgba(69,104,130,0.15);
        }
        .trainer-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 40px rgba(27,60,83,0.14);
            border-color: rgba(69,104,130,0.30);
        }

        /* Left accent stripe */
        .trainer-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 4px;
            border-radius: var(--radius-xl) 0 0 var(--radius-xl);
            transition: width 0.3s ease;
            z-index: 2;
        }
        .trainer-card.is-active::before {
            background: linear-gradient(180deg, var(--navy-deep), var(--navy-mid));
        }
        .trainer-card.is-inactive::before {
            background: linear-gradient(180deg, #94a3b8, #cbd5e1);
        }
        .trainer-card:hover::before {
            width: 6px;
        }

        /* Gradient header background */
        .trainer-card-header-bg {
            background: linear-gradient(135deg, var(--navy-deep) 0%, var(--navy-mid) 60%, var(--navy-light) 100%);
            padding: 16px 20px 14px;
            position: relative;
            overflow: hidden;
        }
        .trainer-card-header-bg::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 20px;
            background: #ffffff;
            border-radius: 50% 50% 0 0 / 100% 100% 0 0;
        }
        /* Subtle sparkle overlay */
        .trainer-card-header-bg::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 80% 20%, rgba(255,255,255,0.10) 0%, transparent 60%);
            pointer-events: none;
        }

        .trainer-card-header {
            display: flex;
            gap: 14px;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        .trainer-avatar-wrap {
            flex-shrink: 0;
            position: relative;
        }
        .trainer-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.3);
            background: var(--sand-faint);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .trainer-status-dot {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2.5px solid #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.15);
        }
        .trainer-status-dot.active  { background: #10b981; }
        .trainer-status-dot.inactive { background: #94a3b8; }

        .trainer-info {
            flex: 1;
            min-width: 0;
        }
        .trainer-name {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.01em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }
        .trainer-email {
            font-size: 12px;
            color: rgba(255,255,255,0.75);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin: 2px 0 6px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.10);
        }
        .trainer-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 20px;
            letter-spacing: 0.02em;
        }
        .badge-active {
            background: rgba(255,255,255,0.20);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.25);
            backdrop-filter: blur(4px);
        }
        .badge-inactive {
            background: rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.8);
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(4px);
        }
        .badge-spec {
            background: rgba(210,193,182,0.25);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(4px);
        }

        /* Card body (below header) */
        .trainer-card-body {
            padding: 16px 20px 14px;
        }

        /* Meta row */
        .trainer-card-meta {
            display: flex;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
            padding: 8px 12px;
            background: var(--sand-faint);
            border-radius: 10px;
            border: 1px solid rgba(69,104,130,0.08);
        }
        .trainer-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .trainer-meta-item i {
            color: var(--navy-light);
            font-size: 11px;
        }

        /* Stats grid */
        .trainer-card-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-bottom: 14px;
        }
        .trainer-stat {
            background: linear-gradient(145deg, #f8fafc, #f1f5f9);
            border: 1px solid rgba(69,104,130,0.10);
            border-radius: 12px;
            padding: 10px 8px;
            text-align: center;
            transition: all 0.2s ease;
        }
        .trainer-stat:hover {
            background: linear-gradient(145deg, #e8f0f5, #d5e4ed);
            border-color: rgba(69,104,130,0.3);
            transform: scale(1.03);
        }
        .trainer-stat-val {
            font-size: 18px;
            font-weight: 800;
            color: var(--navy-deep);
            letter-spacing: -0.03em;
            line-height: 1;
        }
        .trainer-stat-key {
            font-size: 9.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-top: 3px;
        }
        .star-row {
            display: flex;
            align-items: center;
            gap: 1px;
            justify-content: center;
            margin-top: 3px;
        }
        .star-row i {
            font-size: 9px;
        }
        .star-filled { color: #f59e0b; }
        .star-empty  { color: #e2e8f0; }

        /* ── Card Footer — light background, blue borders ── */
        .trainer-card-footer {
            padding: 0;
            border-top: 2px solid rgba(69,104,130,0.20);
            background: #fafbfc;
            border-radius: 0 0 var(--radius-xl) var(--radius-xl);
        }

        /* Primary actions row — outlined buttons */
        .footer-primary {
            display: flex;
            gap: 6px;
            padding: 10px 14px;
            border-bottom: 1px solid rgba(69,104,130,0.08);
            flex-wrap: wrap;
        }
        .footer-action-btn {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            padding: 8px 8px;
            border-radius: 8px;
            border: 1.5px solid rgba(69,104,130,0.20);
            background: transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.18s ease;
            min-width: 50px;
        }
        .footer-action-btn i {
            font-size: 12px;
        }
        .footer-action-btn:hover {
            border-color: var(--navy-mid);
            color: var(--navy-deep);
            background: rgba(69,104,130,0.06);
            transform: translateY(-1px);
        }
        .footer-action-btn.view-btn:hover {
            border-color: var(--navy-mid);
            background: rgba(69,104,130,0.08);
        }
        .footer-action-btn.edit-btn:hover {
            border-color: var(--navy-light);
            background: rgba(69,104,130,0.08);
        }
        .footer-action-btn.perf-btn:hover {
            border-color: #f59e0b;
            background: rgba(245,158,11,0.08);
            color: #b45309;
        }
        .footer-action-btn.batch-btn:hover {
            border-color: #8b5cf6;
            background: rgba(139,92,246,0.08);
            color: #6d28d9;
        }

        /* Secondary actions — toggle + delete */
        .footer-secondary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px 12px;
            gap: 10px;
        }
        .footer-toggle-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            padding: 5px 14px;
            border-radius: 20px;
            border: 1.5px solid rgba(69,104,130,0.25);
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.18s ease;
            text-transform: uppercase;
        }
        .footer-toggle-btn:hover {
            border-color: var(--navy-mid);
            color: var(--navy-deep);
            background: rgba(69,104,130,0.06);
        }
        .footer-toggle-btn.deactivate:hover {
            border-color: #dc2626;
            color: #dc2626;
            background: rgba(220,38,38,0.06);
        }
        .footer-toggle-btn.activate:hover {
            border-color: #10b981;
            color: #10b981;
            background: rgba(16,185,129,0.06);
        }

        .footer-delete-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1.5px solid rgba(220,38,38,0.20);
            background: transparent;
            color: #dc2626;
            cursor: pointer;
            transition: all 0.18s ease;
            font-size: 13px;
        }
        .footer-delete-btn:hover {
            background: rgba(220,38,38,0.08);
            border-color: #dc2626;
            color: #b91c1c;
            transform: scale(1.08);
        }

        /* ── Empty State ── */
        .empty-state {
            text-align: center; padding: 64px 24px;
            background: rgba(255,253,248,0.6);
            backdrop-filter: blur(8px);
            border: 2px dashed var(--border-light);
            border-radius: var(--radius-xl);
        }
        .empty-icon { font-size: 40px; color: var(--border-light); margin-bottom: 16px; }
        .empty-title { font-size: 16px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
        .empty-sub { font-size: 13.5px; color: var(--text-muted); margin-bottom: 20px; }

        /* ── Pagination ── */
        .pagination {
            display: flex; align-items: center; gap: 4px;
            margin-top: 24px; justify-content: center;
        }
        .page-btn {
            width: 34px; height: 34px; border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 500; text-decoration: none;
            color: var(--text-secondary); border: 1px solid var(--border-light);
            background: rgba(255,255,255,0.7); transition: all 0.14s; cursor: pointer;
        }
        .page-btn:hover {
            border-color: var(--navy-light);
            color: var(--navy-deep);
            background: rgba(255,255,255,0.9);
        }
        .page-btn.active {
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 10px rgba(27,60,83,0.25);
        }

        /* ── Loading ── */
        .loading-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(255,253,248,0.85); backdrop-filter: blur(4px);
            z-index: 9999; align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spin-ring {
            width: 44px; height: 44px; border-radius: 50%;
            border: 3px solid var(--border-light);
            border-top-color: var(--navy-deep);
            animation: spin 0.75s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Toast ── */
        .toast-wrap {
            position: fixed; top: 20px; right: 24px; z-index: 10000;
            display: flex; flex-direction: column; gap: 8px;
        }
        .toast {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 18px; border-radius: var(--radius);
            font-size: 13.5px; font-weight: 500;
            box-shadow: var(--shadow-lg);
            animation: slideInRight 0.25s ease;
            min-width: 260px;
            background: #fff;
            border-left: 3px solid var(--navy-light);
        }
        .toast-success { border-left-color: #10b981; }
        .toast-error { border-left-color: #ef4444; }
        @keyframes slideInRight {
            from { transform: translateX(16px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .main-area { margin-left: 0; }
            .page-content { padding: 16px; }
            .topbar { padding: 0 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .trainer-grid { grid-template-columns: 1fr; }
            .filter-row { flex-direction: column; }
            .filter-group { min-width: 100%; }
            .hero-banner .hero-content { flex-direction: column; align-items: flex-start; }
            .hero-banner .hero-stats { width: 100%; justify-content: space-around; }
            .hero-banner .hero-cta { width: 100%; }
        }

        /* ── Trainer Grid ── */
        .trainer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
        }

        /* ── Section Border ── */
        .section-border {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 4px;
            background: rgba(255,253,248,0.3);
            box-shadow: var(--shadow-sm);
        }
        .section-border .section-content {
            padding: 4px;
        }

        /* ── Filter bar bottom border accent ── */
        .filter-bar {
            border-bottom: 3px solid var(--navy-light);
        }
    </style>
</head>
<body>
<?php include '../header.php'; ?>

<div class="layout">
    <?php include '../sidebar.php'; ?>

    <div class="main-area">
        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar-left">
                <span class="topbar-title">Trainer Management</span>
                <span class="topbar-breadcrumb">/ <?= $totalTrainers ?> trainers</span>
            </div>
            <div class="topbar-right">
                <a href="add.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Trainer
                </a>
            </div>
        </header>

        <div class="page-content">
            <!-- ═══════════════════════════════════════════════════════════════════════
                 HERO BANNER (simplified)
                 ═══════════════════════════════════════════════════════════════════════ -->
            <div class="hero-banner">
                <div class="bubble"></div>
                <div class="bubble"></div>
                <div class="bubble"></div>

                <div class="hero-content">
                    <div class="hero-left">
                        <div class="hero-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="hero-text">
                            <h1>Trainer Management</h1>
                            <p>Manage and monitor all trainers across the academy</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 KPI STATS — Compact & Colorful
                 ═══════════════════════════════════════════════════════════════════════ -->
            <div class="stats-grid">
                <!-- Total Trainers -->
                <div class="stat-card stat-total">
                    <div class="accent-line"></div>
                    <div class="stat-content">
                        <div class="stat-icon-wrap"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Total Trainers</div>
                            <div class="stat-value"><?= $totalTrainers ?></div>
                            <div class="stat-sub"><i class="fas fa-users"></i> All registered</div>
                        </div>
                    </div>
                    <div class="stat-bar"><div class="stat-bar-fill"></div></div>
                </div>

                <!-- Active Trainers -->
                <div class="stat-card stat-active">
                    <div class="accent-line"></div>
                    <div class="stat-content">
                        <div class="stat-icon-wrap"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Active Trainers</div>
                            <div class="stat-value"><?= $performanceStats['active_count'] ?></div>
                            <div class="stat-sub"><i class="fas fa-circle" style="color:#10b981;font-size:8px;"></i> Currently active</div>
                        </div>
                    </div>
                    <div class="stat-bar"><div class="stat-bar-fill" style="width:<?= $totalTrainers > 0 ? round(($performanceStats['active_count']/$totalTrainers)*100) : 0 ?>%;"></div></div>
                </div>

                <!-- Avg Rating -->
                <div class="stat-card stat-rating">
                    <div class="accent-line"></div>
                    <div class="stat-content">
                        <div class="stat-icon-wrap"><i class="fas fa-star"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Avg. Rating</div>
                            <div class="stat-value"><?= $performanceStats['avg_rating'] ? number_format($performanceStats['avg_rating'], 1) : '—' ?></div>
                            <div class="stat-sub <?= ($performanceStats['rating_change'] ?? 0) >= 0 ? 'up' : 'down' ?>">
                                <i class="fas fa-arrow-<?= ($performanceStats['rating_change'] ?? 0) >= 0 ? 'up' : 'down' ?>"></i>
                                <?= abs($performanceStats['rating_change'] ?? 0) ?>% this month
                            </div>
                        </div>
                    </div>
                    <div class="stat-bar"><div class="stat-bar-fill" style="width:<?= ($performanceStats['avg_rating'] ?? 0) * 20 ?>%;"></div></div>
                </div>

                <!-- Active Batches -->
                <div class="stat-card stat-batches">
                    <div class="accent-line"></div>
                    <div class="stat-content">
                        <div class="stat-icon-wrap"><i class="fas fa-layer-group"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Active Batches</div>
                            <div class="stat-value"><?= $performanceStats['total_batches'] ?></div>
                            <div class="stat-sub"><i class="fas fa-graduation-cap"></i> Across all trainers</div>
                        </div>
                    </div>
                    <div class="stat-bar"><div class="stat-bar-fill" style="width:<?= min(100, ($performanceStats['total_batches'] ?? 0) * 10) ?>%;"></div></div>
                </div>
            </div>

            <!-- Bulk Actions Bar -->
            <div class="bulk-bar" id="bulkBar">
                <div class="bulk-bar-label"><span id="selectedCount">0</span> trainers selected</div>
                <div class="bulk-bar-actions">
                    <button class="bulk-btn" id="bulkActivateBtn"><i class="fas fa-check-circle"></i> Activate</button>
                    <button class="bulk-btn" id="bulkDeactivateBtn"><i class="fas fa-ban"></i> Deactivate</button>
                    <button class="bulk-btn bulk-danger" id="bulkDeleteBtn"><i class="fas fa-trash-alt"></i> Delete</button>
                    <button class="bulk-btn" id="clearSelectionBtn"><i class="fas fa-times"></i> Clear</button>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="GET" action="" id="filterForm">
                    <div class="filter-row">
                        <div class="filter-group filter-search-wrap" style="min-width:220px; flex:2;">
                            <label>Search</label>
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" class="filter-input" placeholder="Name, email, specialization…" value="<?= htmlspecialchars($filters['search'] ?? '') ?>" id="searchInput">
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status" class="filter-input" id="statusSelect">
                                <option value="">All</option>
                                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Specialization</label>
                            <select name="specialization" class="filter-input" id="specializationSelect">
                                <option value="">All</option>
                                <?php foreach ($allSpecializations as $spec): ?>
                                    <option value="<?= htmlspecialchars($spec) ?>" <?= ($filters['specialization'] ?? '') === $spec ? 'selected' : '' ?>><?= htmlspecialchars($spec) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Experience</label>
                            <select name="experience" class="filter-input" id="experienceSelect">
                                <option value="">Any</option>
                                <option value="1-3" <?= ($filters['experience'] ?? '') === '1-3' ? 'selected' : '' ?>>1–3 years</option>
                                <option value="4-6" <?= ($filters['experience'] ?? '') === '4-6' ? 'selected' : '' ?>>4–6 years</option>
                                <option value="7+" <?= ($filters['experience'] ?? '') === '7+' ? 'selected' : '' ?>>7+ years</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Rating</label>
                            <select name="rating" class="filter-input" id="ratingSelect">
                                <option value="">Any</option>
                                <option value="4+" <?= ($filters['rating'] ?? '') === '4+' ? 'selected' : '' ?>>4+ Stars</option>
                                <option value="3-4" <?= ($filters['rating'] ?? '') === '3-4' ? 'selected' : '' ?>>3–4 Stars</option>
                                <option value="1-3" <?= ($filters['rating'] ?? '') === '1-3' ? 'selected' : '' ?>>1–3 Stars</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Sort By</label>
                            <select name="sort" class="filter-input" id="sortSelect">
                                <option value="">Default</option>
                                <option value="name_asc" <?= ($filters['sort'] ?? '') === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
                                <option value="name_desc" <?= ($filters['sort'] ?? '') === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
                                <option value="rating_high" <?= ($filters['sort'] ?? '') === 'rating_high' ? 'selected' : '' ?>>Highest Rating</option>
                                <option value="newest" <?= ($filters['sort'] ?? '') === 'newest' ? 'selected' : '' ?>>Newest First</option>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                            <a href="index.php" class="btn btn-ghost"><i class="fas fa-undo"></i></a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Section Header -->
            <div class="section-header">
                <span class="section-title">Trainers</span>
                <span class="section-count"><?= $totalTrainers ?> results</span>
            </div>

            <!-- Trainer Cards -->
            <?php if (empty($trainers)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
                    <div class="empty-title">No trainers found</div>
                    <div class="empty-sub">Try adjusting your filters or add a new trainer.</div>
                    <a href="index.php" class="btn btn-ghost">Reset Filters</a>
                </div>
            <?php else: ?>
                <div class="trainer-grid" id="trainerGrid">
                    <?php foreach ($trainers as $trainer):
                        $batchCount = getTrainerBatchCount($trainer['id']);
                        $avgRating = getTrainerAverageRating($trainer['id']);
                        $joinDate = isset($trainer['join_date']) && $trainer['join_date'] ? new DateTime($trainer['join_date']) : null;
                        $isActive = $trainer['is_active'];
                    ?>
                    <div class="trainer-card <?= $isActive ? 'is-active' : 'is-inactive' ?>" data-id="<?= $trainer['id'] ?>">
                        <!-- Gradient header -->
                        <div class="trainer-card-header-bg">
                            <div class="trainer-card-header">
                                <div class="trainer-avatar-wrap">
                                    <img src="<?= getTrainerPhoto($trainer) ?>"
                                         class="trainer-avatar"
                                         alt="<?= htmlspecialchars($trainer['name']) ?>"
                                         onerror="this.src='../assets/images/default-avatar.svg'">
                                    <span class="trainer-status-dot <?= $isActive ? 'active' : 'inactive' ?>"></span>
                                </div>
                                <div class="trainer-info">
                                    <div class="trainer-name"><?= htmlspecialchars($trainer['name']) ?></div>
                                    <div class="trainer-email"><?= htmlspecialchars($trainer['email']) ?></div>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:4px;">
                                        <span class="trainer-badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= $isActive ? 'Active' : 'Inactive' ?>
                                        </span>
                                        <?php if ($trainer['specialization']): ?>
                                            <span class="trainer-badge badge-spec"><?= htmlspecialchars($trainer['specialization']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Body: meta + stats -->
                        <div class="trainer-card-body">
                            <div class="trainer-card-meta">
                                <div class="trainer-meta-item">
                                    <i class="fas fa-briefcase"></i>
                                    <?= $trainer['years_of_experience'] ?? 0 ?> yr<?= ($trainer['years_of_experience'] ?? 0) != 1 ? 's' : '' ?> exp.
                                </div>
                                <?php if ($joinDate): ?>
                                <div class="trainer-meta-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    Since <?= $joinDate->format('M Y') ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="trainer-card-stats">
                                <div class="trainer-stat">
                                    <div class="trainer-stat-val"><?= $batchCount ?></div>
                                    <div class="trainer-stat-key">Batches</div>
                                </div>
                                <div class="trainer-stat">
                                    <?php if ($avgRating): ?>
                                        <div class="trainer-stat-val"><?= number_format($avgRating, 1) ?></div>
                                        <div class="star-row">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star <?= $i <= round($avgRating) ? 'star-filled' : 'star-empty' ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="trainer-stat-val" style="color:var(--text-muted);">—</div>
                                        <div class="trainer-stat-key">Rating</div>
                                    <?php endif; ?>
                                </div>
                                <div class="trainer-stat">
                                    <div class="trainer-stat-val"><?= $trainer['years_of_experience'] ?? 0 ?></div>
                                    <div class="trainer-stat-key">Years</div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="trainer-card-footer">
                            <div class="footer-primary">
                                <a href="view.php?id=<?= $trainer['id'] ?>" class="footer-action-btn view-btn">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="edit.php?id=<?= $trainer['id'] ?>" class="footer-action-btn edit-btn">
                                    <i class="fas fa-pen"></i> Edit
                                </a>
                                <a href="performance.php?id=<?= $trainer['id'] ?>" class="footer-action-btn perf-btn" title="Performance">
                                    <i class="fas fa-chart-line"></i>
                                </a>
                                <a href="batches.php?id=<?= $trainer['id'] ?>" class="footer-action-btn batch-btn" title="Batches">
                                    <i class="fas fa-layer-group"></i>
                                </a>
                            </div>
                            <div class="footer-secondary">
                                <button class="footer-toggle-btn <?= $isActive ? 'deactivate' : 'activate' ?> toggle-status"
                                        data-id="<?= $trainer['id'] ?>"
                                        data-status="<?= $isActive ? 1 : 0 ?>"
                                        title="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                                    <i class="fas fa-power-off"></i>
                                    <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                </button>
                                <button class="footer-delete-btn delete-trainer"
                                        data-id="<?= $trainer['id'] ?>"
                                        data-name="<?= htmlspecialchars($trainer['name']) ?>"
                                        title="Delete trainer">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>" class="page-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spin-ring"></div>
</div>

<!-- Toast Container -->
<div class="toast-wrap" id="toastWrap"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function() {
    function showLoading() { $('#loadingOverlay').addClass('active'); }
    function hideLoading() { $('#loadingOverlay').removeClass('active'); }

    function showToast(msg, type) {
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        const color = type === 'success' ? 'var(--success)' : 'var(--danger)';
        const toast = $(`<div class="toast toast-${type}"><i class="fas ${icon}" style="color:${color}"></i>${msg}</div>`);
        $('#toastWrap').append(toast);
        setTimeout(() => toast.fadeOut(300, function() { $(this).remove(); }), 3200);
    }

    // Toggle Status
    $('.toggle-status').on('click', function() {
        const btn = $(this);
        const trainerId = btn.data('id');
        const currentStatus = btn.data('status');
        const newStatus = currentStatus ? 0 : 1;
        showLoading();
        $.ajax({
            url: 'status.php', method: 'POST',
            data: { id: trainerId, status: newStatus },
            success: function(res) {
                hideLoading();
                if (res.success) {
                    btn.data('status', newStatus);
                    const card = btn.closest('.trainer-card');
                    const dot = card.find('.trainer-status-dot');
                    const badge = card.find('.badge-active, .badge-inactive').first();
                    if (newStatus) {
                        dot.removeClass('inactive').addClass('active');
                        badge.removeClass('badge-inactive').addClass('badge-active').text('Active');
                        btn.removeClass('success').addClass('danger');
                    } else {
                        dot.removeClass('active').addClass('inactive');
                        badge.removeClass('badge-active').addClass('badge-inactive').text('Inactive');
                        btn.removeClass('danger').addClass('success');
                    }
                    showToast('Status updated successfully.', 'success');
                } else {
                    showToast('Error: ' + res.message, 'error');
                }
            },
            error: function() { hideLoading(); showToast('Failed to update status.', 'error'); }
        });
    });

    // Delete Trainer
    $('.delete-trainer').on('click', function() {
        const btn = $(this);
        const trainerId = btn.data('id');
        const trainerName = btn.data('name');
        if (!confirm(`Delete "${trainerName}"? This cannot be undone.`)) return;
        showLoading();
        $.ajax({
            url: 'delete.php', method: 'POST',
            data: { id: trainerId },
            success: function(res) {
                hideLoading();
                if (res.success) {
                    btn.closest('.trainer-card').fadeOut(300, function() { $(this).remove(); });
                    showToast('Trainer deleted successfully.', 'success');
                } else {
                    showToast('Error: ' + res.message, 'error');
                }
            },
            error: function() { hideLoading(); showToast('Failed to delete trainer.', 'error'); }
        });
    });

    // Bulk Actions
    const selectedIds = new Set();
    function updateBulkBar() {
        const count = selectedIds.size;
        if (count > 0) {
            $('#bulkBar').addClass('active');
            $('#selectedCount').text(count);
        } else {
            $('#bulkBar').removeClass('active');
        }
    }

    // Bulk clear
    $('#clearSelectionBtn').on('click', function() {
        selectedIds.clear();
        updateBulkBar();
    });
});
</script>
</body>
</html>
