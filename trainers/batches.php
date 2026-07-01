<?php
require_once '../db_connection.php';
require_once 'functions.php';

// Check admin permissions
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$trainerId = (int)$_GET['id'];

// Fetch trainer data
$stmt = $db->prepare("SELECT name FROM trainers WHERE id = ?");
$stmt->execute([$trainerId]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    header('Location: index.php');
    exit;
}

// Get pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Get batches count
$stmt = $db->prepare("SELECT COUNT(*) as total 
                       FROM batches 
                       WHERE batch_mentor_id = ?");
$stmt->execute([$trainerId]);
$totalBatches = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalBatches / $perPage);

// Get batches
$stmt = $db->prepare("
    SELECT b.*,
           GROUP_CONCAT(c.name SEPARATOR ', ') AS course_name
    FROM batches b
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
    LEFT JOIN courses c ON bc.course_id = c.id
    WHERE b.batch_mentor_id = ?
    GROUP BY b.batch_id
    ORDER BY b.start_date DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$trainerId, $perPage, $offset]);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute summary counts
$upcoming = 0;
$ongoing = 0;
$completed = 0;
$totalStudents = 0;
foreach ($batches as $b) {
    if ($b['status'] === 'upcoming') $upcoming++;
    elseif ($b['status'] === 'ongoing') $ongoing++;
    else $completed++;
    $totalStudents += ($b['current_enrollment'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($trainer['name']) ?> — Batches | ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════════════════════
           DESIGN SYSTEM — Navy/Sand Theme (matches admin_dashboard.php)
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

        .layout { display: flex; min-height: 100vh; }
        .main-area { flex: 1; margin-left: var(--sidebar-w); display: flex; flex-direction: column; }

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
        .topbar-back {
            display: flex; align-items: center; gap: 6px;
            font-size: 13px; font-weight: 500; color: var(--text-muted);
            text-decoration: none; transition: color 0.15s;
        }
        .topbar-back:hover { color: var(--navy-deep); }
        .topbar-divider { color: var(--border-medium); font-size: 16px; }
        .topbar-title {
            font-size: 15px; font-weight: 700; color: var(--text-primary);
            letter-spacing: -0.01em;
        }
        .topbar-right { display: flex; gap: 8px; }
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 13.5px; font-weight: 500; font-family: 'Inter', sans-serif;
            padding: 0 16px; height: 36px; border-radius: var(--radius-sm);
            cursor: pointer; transition: all 0.18s ease;
            text-decoration: none; border: none; white-space: nowrap;
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

        .page-content { padding: 28px 32px; flex: 1; }

        /* ── Hero Banner (copied from admin_dashboard) ── */
        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 30%, #456882 60%, #D2C1B6 100%);
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(27,60,83,0.35), 0 6px 20px rgba(35,76,106,0.25);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(210,193,182,0.20);
            padding: 20px 28px;
            margin-bottom: 24px;
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 20% 30%, rgba(255,255,255,0.15) 0%, transparent 8%),
                radial-gradient(circle at 80% 70%, rgba(255,255,255,0.12) 0%, transparent 12%),
                radial-gradient(circle at 40% 80%, rgba(255,255,255,0.10) 0%, transparent 10%),
                radial-gradient(circle at 10% 90%, rgba(255,255,255,0.08) 0%, transparent 6%),
                radial-gradient(circle at 70% 20%, rgba(255,255,255,0.18) 0%, transparent 14%),
                radial-gradient(circle at 50% 50%, rgba(255,255,255,0.06) 0%, transparent 20%);
            pointer-events: none;
            z-index: 1;
        }
        .hero-banner .bubble {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.08);
            pointer-events: none;
            z-index: 0;
        }
        .hero-banner .bubble:nth-child(1) { width: 80px; height: 80px; top: 10%; left: 5%; animation: floatBubble 12s infinite ease-in-out; }
        .hero-banner .bubble:nth-child(2) { width: 120px; height: 120px; bottom: 5%; right: 10%; animation: floatBubble 18s infinite ease-in-out reverse; }
        .hero-banner .bubble:nth-child(3) { width: 60px; height: 60px; top: 60%; left: 80%; animation: floatBubble 14s infinite ease-in-out 2s; }
        .hero-banner .bubble:nth-child(4) { width: 40px; height: 40px; top: 20%; right: 25%; animation: floatBubble 10s infinite ease-in-out 1s; }
        @keyframes floatBubble {
            0% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(20px, -30px) scale(1.05); }
            66% { transform: translate(-10px, 20px) scale(0.95); }
            100% { transform: translate(0, 0) scale(1); }
        }
        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.20);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.25);
            color: #f7f5f3;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .hero-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .hero-content h1 {
            font-size: 1.8rem;
            font-weight: 800;
            color: #fff;
            font-family: 'Poppins', sans-serif;
            text-shadow: 0 2px 4px rgba(0,0,0,0.10);
            letter-spacing: -0.02em;
            margin: 0;
        }
        .hero-content p {
            color: rgba(255,255,255,0.85);
            font-size: 1rem;
            margin: 0;
            text-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 6px;
        }
        .hero-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,0.8);
            font-size: 0.85rem;
            font-weight: 500;
        }
        .hero-meta-item i { color: rgba(255,255,255,0.5); }
        .hero-meta-item strong { color: #fff; }

        .timezone-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 0.7rem;
            font-weight: 600;
            color: #fff;
            letter-spacing: 0.03em;
        }

        /* ── KPI Cards (same as admin_dashboard) ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 14px 16px 12px;
            border: 2px solid rgba(69,104,130,0.25);
            box-shadow: 0 4px 20px rgba(27,60,83,0.08), inset 0 1px 0 rgba(255,255,255,0.7);
            transition: transform .3s cubic-bezier(.4,0,.2,1), box-shadow .3s;
            position: relative;
            overflow: hidden;
            cursor: default;
            color: #1B3C53;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: linear-gradient(180deg, #1B3C53, #234C6A);
            transition: width 0.4s ease;
            border-radius: 18px 0 0 18px;
        }
        .kpi-card:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px rgba(27,60,83,0.18), inset 0 1px 0 rgba(255,255,255,0.7);
        }
        .kpi-card:hover::before { width: 100%; opacity: 0.08; }

        .kpi-icon {
            position: absolute; top: 12px; right: 12px;
            width: 40px; height: 40px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .kpi-icon-blue   { background: linear-gradient(135deg,#234C6A,#456882); }
        .kpi-icon-green  { background: linear-gradient(135deg,#D2C1B6,#456882); }
        .kpi-icon-purple { background: linear-gradient(135deg,#1B3C53,#234C6A); }
        .kpi-icon-violet { background: linear-gradient(135deg,#456882,#D2C1B6); }
        .kpi-icon-pink   { background: linear-gradient(135deg,#1B3C53,#456882); }

        .kpi-label { font-size: .65rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #456882; }
        .kpi-value { font-size: 1.6rem; font-weight: 900; line-height: 1.1; color: #1B3C53; }
        .kpi-sub  { font-size: .7rem; color: #456882; font-weight: 500; }

        .kpi-bar-wrap { height: 4px; border-radius: 99px; background: #e2e8f0; margin-top: 6px; overflow: hidden; }
        .kpi-bar { height: 100%; border-radius: 99px; background: linear-gradient(90deg,#1B3C53,#234C6A,#456882); }

        /* ── Section Header ── */
        .section-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 18px;
        }
        .section-title-group { display: flex; align-items: center; gap: 10px; }
        .section-title {
            font-size: 16px; font-weight: 700; color: var(--text-primary);
        }
        .section-count {
            font-size: 12px; font-weight: 600; color: var(--text-muted);
            background: rgba(255,255,255,0.7);
            border: 1px solid var(--border-light);
            border-radius: 20px; padding: 2px 10px;
        }

        /* ── Batch Cards ── */
        .batch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
        }
        .batch-card {
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            overflow: hidden;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-sm);
        }
        .batch-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-medium);
        }
        .batch-card-accent { height: 4px; }
        .batch-card-body { padding: 20px; }
        .batch-card-top {
            display: flex; align-items: flex-start; justify-content: space-between;
            margin-bottom: 14px;
        }
        .batch-name {
            font-size: 15px; font-weight: 700; color: var(--text-primary);
            letter-spacing: -0.01em; margin-bottom: 3px;
        }
        .batch-course {
            font-size: 12.5px; color: var(--text-muted);
        }
        .status-pill {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700; padding: 4px 10px;
            border-radius: 20px; flex-shrink: 0;
        }
        .status-pill-dot { width: 6px; height: 6px; border-radius: 50%; }
        .status-upcoming { background: #EEF4F8; color: #234C6A; }
        .status-upcoming .status-pill-dot { background: #234C6A; }
        .status-ongoing { background: #ECFDF5; color: #15803D; }
        .status-ongoing .status-pill-dot { background: #10b981; }
        .status-completed { background: #F3F4F6; color: #64748B; }
        .status-completed .status-pill-dot { background: #94a3b8; }

        .batch-meta {
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
            margin-bottom: 16px;
        }
        .batch-meta-item {
            background: #F8FAFC;
            border: 1px solid #EDF2F7;
            border-radius: var(--radius-sm);
            padding: 10px 12px;
        }
        .batch-meta-label {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 3px;
        }
        .batch-meta-val {
            font-size: 13px; font-weight: 600; color: var(--text-primary);
        }

        .batch-students {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 12px; background: #F8FAFC;
            border: 1px solid #EDF2F7; border-radius: var(--radius-sm);
            margin-bottom: 16px;
        }
        .batch-students-icon {
            width: 28px; height: 28px; border-radius: 7px;
            background: #EEF4F8; color: #234C6A;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px;
        }
        .batch-students-val { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .batch-students-label { font-size: 12px; color: var(--text-muted); }

        .batch-card-footer {
            padding: 12px 20px; border-top: 1px solid var(--border-light);
            display: flex; gap: 8px; background: #FAFBFC;
        }
        .batch-action {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 12.5px; font-weight: 500; padding: 6px 12px;
            border-radius: var(--radius-sm); text-decoration: none;
            transition: all 0.14s; border: 1px solid var(--border-light);
            background: var(--surface); color: var(--text-secondary);
        }
        .batch-action:hover {
            border-color: var(--navy-light);
            color: var(--navy-deep);
            background: rgba(69,104,130,0.08);
        }
        .batch-action.primary {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: #fff;
            border: none;
        }
        .batch-action.primary:hover {
            background: linear-gradient(135deg, #234C6A, #456882);
        }

        /* ── Empty State ── */
        .empty-state-card {
            background: #fff;
            border: 2px dashed var(--sand);
            border-radius: var(--radius-xl);
            padding: 60px 32px;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        .empty-icon { font-size: 36px; color: var(--navy-light); margin-bottom: 14px; }
        .empty-title { font-size: 16px; font-weight: 700; color: var(--text-secondary); margin-bottom: 6px; }
        .empty-sub { font-size: 13.5px; color: var(--text-muted); }

        /* ── Pagination ── */
        .pagination {
            display: flex; align-items: center; gap: 4px;
            margin-top: 28px; justify-content: center;
        }
        .page-btn {
            width: 36px; height: 36px; border-radius: var(--radius-sm);
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
        .page-info { font-size: 13px; color: var(--text-muted); margin: 0 8px; }

        @media (max-width: 768px) {
            .main-area { margin-left: 0; }
            .page-content { padding: 16px; }
            .topbar { padding: 0 16px; }
            .batch-grid { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .hero-banner { padding: 16px; }
            .hero-content h1 { font-size: 1.4rem; }
        }
    </style>
</head>
<body>
<?php include '../header.php'; ?>

<div class="layout">
    <?php include '../sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <a href="view.php?id=<?= $trainerId ?>" class="topbar-back"><i class="fas fa-arrow-left"></i> Profile</a>
                <span class="topbar-divider">/</span>
                <span class="topbar-title"><?= htmlspecialchars($trainer['name']) ?> — Batches</span>
            </div>
            <div class="topbar-right">
                <a href="view.php?id=<?= $trainerId ?>" class="btn btn-ghost"><i class="fas fa-user"></i> Profile</a>
                <a href="index.php" class="btn btn-ghost"><i class="fas fa-users"></i> All Trainers</a>
            </div>
        </header>

        <div class="page-content">

            <!-- ── HERO BANNER ── -->
            <div class="hero-banner">
                <div class="bubble"></div>
                <div class="bubble"></div>
                <div class="bubble"></div>
                <div class="bubble"></div>
                <div class="hero-content">
                    <div class="hero-pill">
                        <i class="fas fa-chalkboard-teacher"></i> Trainer Batches
                    </div>
                    <h1><?= htmlspecialchars($trainer['name']) ?></h1>
                    <p>Manage and monitor all batches assigned to this trainer</p>
                    <div class="hero-meta">
                        <span class="hero-meta-item"><i class="fas fa-layer-group"></i> <strong><?= $totalBatches ?></strong> total batches</span>
                        <span class="hero-meta-item"><i class="fas fa-user-graduate"></i> <strong><?= $totalStudents ?></strong> students</span>
                        <span class="timezone-pill"><i class="far fa-clock"></i> IST (UTC+5:30)</span>
                    </div>
                </div>
            </div>

            <!-- ── KPI CARDS ── -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-blue"><i class="fas fa-layer-group text-white"></i></div>
                    <div class="kpi-label">Total Batches</div>
                    <div class="kpi-value"><?= $totalBatches ?></div>
                    <div class="kpi-sub">Assigned to this trainer</div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:100%"></div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-green"><i class="fas fa-play-circle text-white"></i></div>
                    <div class="kpi-label">Ongoing</div>
                    <div class="kpi-value"><?= $ongoing ?></div>
                    <div class="kpi-sub">Currently active</div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= $totalBatches > 0 ? round(($ongoing/$totalBatches)*100) : 0 ?>%"></div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-purple"><i class="fas fa-clock text-white"></i></div>
                    <div class="kpi-label">Upcoming</div>
                    <div class="kpi-value"><?= $upcoming ?></div>
                    <div class="kpi-sub">Scheduled</div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= $totalBatches > 0 ? round(($upcoming/$totalBatches)*100) : 0 ?>%"></div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-violet"><i class="fas fa-check-circle text-white"></i></div>
                    <div class="kpi-label">Completed</div>
                    <div class="kpi-value"><?= $completed ?></div>
                    <div class="kpi-sub">Finished</div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= $totalBatches > 0 ? round(($completed/$totalBatches)*100) : 0 ?>%"></div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-pink"><i class="fas fa-users text-white"></i></div>
                    <div class="kpi-label">Students</div>
                    <div class="kpi-value"><?= $totalStudents ?></div>
                    <div class="kpi-sub">Enrolled across batches</div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= min(100, $totalStudents * 2) ?>%"></div></div>
                </div>
            </div>

            <!-- ── Section Header ── -->
            <div class="section-header">
                <div class="section-title-group">
                    <span class="section-title">Assigned Batches</span>
                    <span class="section-count"><?= $totalBatches ?> total</span>
                </div>
                <div style="font-size:13px; color:var(--text-muted);">
                    Page <?= $page ?> of <?= max(1, $totalPages) ?>
                </div>
            </div>

            <!-- ── Batch Cards ── -->
            <?php if (empty($batches)): ?>
                <div class="empty-state-card">
                    <div class="empty-icon"><i class="fas fa-layer-group"></i></div>
                    <div class="empty-title">No batches assigned</div>
                    <div class="empty-sub"><?= htmlspecialchars($trainer['name']) ?> has not been assigned to any batches yet.</div>
                </div>
            <?php else: ?>
                <div class="batch-grid">
                    <?php foreach ($batches as $batch):
                        $studentCount = $batch['current_enrollment'] ?? 0;
                        $status = $batch['status'] ?? 'unknown';
                        $accentColor = $status === 'ongoing' ? '#10B981' : ($status === 'upcoming' ? '#234C6A' : '#94A3B8');
                    ?>
                    <div class="batch-card">
                        <div class="batch-card-accent" style="background: <?= $accentColor ?>;"></div>
                        <div class="batch-card-body">
                            <div class="batch-card-top">
                                <div>
                                    <div class="batch-name"><?= htmlspecialchars($batch['batch_name']) ?></div>
                                    <div class="batch-course"><i class="fas fa-book-open" style="margin-right:4px; font-size:10px;"></i><?= htmlspecialchars($batch['course_name'] ?? 'N/A') ?></div>
                                </div>
                                <span class="status-pill status-<?= $status ?>">
                                    <span class="status-pill-dot"></span>
                                    <?= ucfirst($status) ?>
                                </span>
                            </div>

                            <div class="batch-meta">
                                <div class="batch-meta-item">
                                    <div class="batch-meta-label">Start Date</div>
                                    <div class="batch-meta-val"><?= date('M d, Y', strtotime($batch['start_date'])) ?></div>
                                </div>
                                <div class="batch-meta-item">
                                    <div class="batch-meta-label">End Date</div>
                                    <div class="batch-meta-val"><?= $batch['end_date'] ? date('M d, Y', strtotime($batch['end_date'])) : '—' ?></div>
                                </div>
                            </div>

                            <div class="batch-students">
                                <div class="batch-students-icon"><i class="fas fa-users"></i></div>
                                <div>
                                    <div class="batch-students-val"><?= $studentCount ?></div>
                                    <div class="batch-students-label">Enrolled <?= $studentCount === 1 ? 'student' : 'students' ?></div>
                                </div>
                                <?php if ($batch['end_date'] && $status === 'ongoing'): ?>
                                <?php
                                    $endDate = new DateTime($batch['end_date']);
                                    $now = new DateTime();
                                    $daysLeft = $now < $endDate ? $now->diff($endDate)->days : 0;
                                ?>
                                <div style="margin-left:auto; text-align:right;">
                                    <div style="font-size:13px; font-weight:700; color:#f59e0b;"><?= $daysLeft ?>d</div>
                                    <div style="font-size:11px; color:var(--text-muted);">remaining</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="batch-card-footer">
                            <a href="/admin/batches/view.php?id=<?= $batch['batch_id'] ?>" class="batch-action primary">
                                <i class="fas fa-eye"></i> View Batch
                            </a>
                            <a href="/admin/batches/students.php?id=<?= $batch['batch_id'] ?>" class="batch-action">
                                <i class="fas fa-users"></i> Students
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- ── Pagination ── -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?id=<?= $trainerId ?>&page=<?= $page - 1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?id=<?= $trainerId ?>&page=<?= $i ?>" class="page-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?id=<?= $trainerId ?>&page=<?= $page + 1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                    <span class="page-info">of <?= $totalPages ?> pages</span>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
