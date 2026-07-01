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
$stmt = $db->prepare("SELECT t.*, u.email
                      FROM trainers t
                      JOIN users u ON t.user_id = u.id
                      WHERE t.id = ?");

$stmt->execute([$trainerId]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    header('Location: index.php');
    exit;
}

// Get performance stats
$batchCount = getTrainerBatchCount($trainerId);
$avgRating = getTrainerAverageRating($trainerId);

// Get rating distribution
$stmt = $db->prepare("SELECT rating, COUNT(*) as count 
                       FROM feedback 
                       WHERE batch_id IN (SELECT batch_id FROM batches WHERE batch_mentor_id = ?)
                       GROUP BY rating
                       ORDER BY rating DESC");

$stmt->execute([$trainerId]);
$ratingDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get performance over time
$stmt = $db->prepare("SELECT 
                           DATE_FORMAT(f.date, '%Y-%m') as month,
                           AVG(f.rating) as avg_rating,
                           COUNT(f.id) as feedback_count
                       FROM feedback f
                       JOIN batches b ON f.batch_id = b.batch_id
                       WHERE b.batch_mentor_id = ?
                       GROUP BY DATE_FORMAT(f.date, '%Y-%m')
                       ORDER BY month DESC
                       LIMIT 12");

$stmt->execute([$trainerId]);
$performanceOverTime = $stmt->fetchAll(PDO::FETCH_ASSOC);
$performanceOverTime = array_reverse($performanceOverTime);

$totalFeedback = array_sum(array_column($ratingDistribution, 'count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($trainer['name']) ?> — Analytics | ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ═══════════════════════════════════════════════════════════════════════════
           DESIGN SYSTEM — Navy/Sand Theme (matches admin_dashboard)
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

        .page-content { padding: 28px 32px; flex: 1; }

        /* ── Hero Banner (same as view/batches) ── */
        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 30%, #456882 60%, #D2C1B6 100%);
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(27,60,83,0.35), 0 6px 20px rgba(35,76,106,0.25);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(210,193,182,0.20);
            padding: 18px 28px;
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
            padding: 5px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.20);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.25);
            color: #f7f5f3;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .hero-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
        }
        .hero-avatar-wrap {
            flex-shrink: 0;
            position: relative;
        }
        .hero-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.25);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            background: var(--sand-faint);
        }
        .hero-status {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.6);
        }
        .hero-status.active { background: #10b981; }
        .hero-status.inactive { background: #94a3b8; }

        .hero-text {
            flex: 1;
            min-width: 180px;
        }
        .hero-text h1 {
            font-size: 1.4rem;
            font-weight: 800;
            color: #fff;
            font-family: 'Poppins', sans-serif;
            text-shadow: 0 2px 4px rgba(0,0,0,0.10);
            letter-spacing: -0.02em;
            margin: 0;
        }
        .hero-text .hero-sub {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 4px;
        }
        .hero-text .hero-sub span {
            color: rgba(255,255,255,0.85);
            font-size: 0.85rem;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        .hero-text .hero-sub .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: rgba(255,255,255,0.20);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
        }
        .hero-text .hero-sub .badge-spec {
            background: rgba(210,193,182,0.25);
        }
        .hero-exp {
            text-align: right;
            color: #fff;
            flex-shrink: 0;
        }
        .hero-exp-val {
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .hero-exp-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            opacity: 0.7;
        }
        .hero-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-left: auto;
        }
        .hero-actions .btn {
            height: 32px;
            padding: 0 14px;
            font-size: 12px;
        }
        .hero-actions .btn-outline-light {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.20);
            backdrop-filter: blur(4px);
        }
        .hero-actions .btn-outline-light:hover {
            background: rgba(255,255,255,0.25);
        }

        /* ── KPI Cards (colorful, reduced height) ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 12px 16px 10px;
            border: 2px solid rgba(69,104,130,0.25);
            box-shadow: 0 4px 16px rgba(27,60,83,0.08), inset 0 1px 0 rgba(255,255,255,0.7);
            transition: transform .3s cubic-bezier(.4,0,.2,1), box-shadow .3s;
            position: relative;
            overflow: hidden;
            cursor: default;
            color: #1B3C53;
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-height: 72px;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, #1B3C53, #234C6A);
            transition: width 0.4s ease;
            border-radius: 16px 0 0 16px;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 32px rgba(27,60,83,0.18);
        }
        .kpi-card:hover::before { width: 100%; opacity: 0.07; }

        .kpi-card .kpi-icon-wrap {
            position: absolute;
            top: 10px;
            right: 12px;
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            box-shadow: 0 3px 12px rgba(0,0,0,0.10);
        }
        /* Color variants */
        .kpi-card.card-navy .kpi-icon-wrap { background: linear-gradient(135deg, #1B3C53, #234C6A); color: #fff; }
        .kpi-card.card-green .kpi-icon-wrap { background: linear-gradient(135deg, #10b981, #34d399); color: #fff; }
        .kpi-card.card-amber .kpi-icon-wrap { background: linear-gradient(135deg, #f59e0b, #fbbf24); color: #fff; }

        .kpi-card.card-navy::before { background: linear-gradient(180deg, #1B3C53, #234C6A); }
        .kpi-card.card-green::before { background: linear-gradient(180deg, #10b981, #34d399); }
        .kpi-card.card-amber::before { background: linear-gradient(180deg, #f59e0b, #fbbf24); }

        .kpi-label {
            font-size: 0.6rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .kpi-value {
            font-size: 1.4rem;
            font-weight: 900;
            line-height: 1.1;
            color: var(--text-primary);
        }
        .kpi-sub {
            font-size: 0.65rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        .kpi-bar-wrap {
            height: 3px;
            border-radius: 99px;
            background: #e2e8f0;
            margin-top: 4px;
            overflow: hidden;
        }
        .kpi-bar {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, #1B3C53, #234C6A);
        }

        .star-row-kpi {
            display: flex;
            gap: 2px;
            margin-top: 1px;
        }
        .star-row-kpi i { font-size: 10px; }
        .star-filled-kpi { color: #f59e0b; }
        .star-empty-kpi { color: #d1d5db; }

        /* ── Chart Cards ── */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .chart-card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.2s;
        }
        .chart-card:hover {
            box-shadow: var(--shadow-md);
        }
        .chart-card-header {
            padding: 14px 20px 12px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--sand-faint);
        }
        .chart-card-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-card-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            background: rgba(69,104,130,0.12);
            color: var(--navy-mid);
        }
        .chart-card-body {
            padding: 18px 20px 20px;
        }
        .chart-wrap {
            position: relative;
            height: 220px;
        }

        /* ── Rating Table ── */
        .rating-table-card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
        }
        .rating-table-header {
            padding: 14px 20px 12px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--sand-faint);
        }
        .rating-table-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .rating-table-body { padding: 0; }
        .rating-row {
            display: grid;
            grid-template-columns: 80px 50px 1fr 80px;
            align-items: center;
            gap: 14px;
            padding: 10px 20px;
            border-bottom: 1px solid var(--border-light);
            transition: background 0.14s;
        }
        .rating-row:last-child { border-bottom: none; }
        .rating-row:hover { background: var(--sand-faint); }
        .rating-stars {
            display: flex;
            gap: 3px;
        }
        .rating-stars i { font-size: 11px; }
        .star-on { color: #f59e0b; }
        .star-off { color: #d1d5db; }
        .rating-count {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .rating-bar-wrap {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }
        .rating-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.6s ease;
        }
        .rating-pct {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            text-align: right;
        }

        /* ── Empty State ── */
        .empty-analytics {
            text-align: center;
            padding: 40px 20px;
        }
        .empty-analytics-icon {
            font-size: 32px;
            color: var(--border-medium);
            margin-bottom: 12px;
        }
        .empty-analytics-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }
        .empty-analytics-sub {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .charts-grid { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 768px) {
            .main-area { margin-left: 0; }
            .page-content { padding: 16px; }
            .topbar { padding: 0 16px; }
            .kpi-grid { grid-template-columns: 1fr; }
            .hero-content { flex-direction: column; align-items: flex-start; }
            .hero-exp { text-align: left; width: 100%; }
            .hero-actions { margin-left: 0; width: 100%; }
            .rating-row { grid-template-columns: 70px 40px 1fr 60px; gap: 10px; }
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
                <span class="topbar-title">Analytics</span>
            </div>
            <div class="topbar-right">
                <a href="view.php?id=<?= $trainerId ?>" class="btn btn-ghost"><i class="fas fa-user"></i> View Profile</a>
                <a href="index.php" class="btn btn-ghost"><i class="fas fa-users"></i> All Trainers</a>
            </div>
        </header>

        <div class="page-content">

            <!-- ── Hero Banner ── -->
            <div class="hero-banner">
                <div class="bubble"></div>
                <div class="bubble"></div>
                <div class="bubble"></div>
                <div class="bubble"></div>

                <div class="hero-content">
                    <div class="hero-avatar-wrap">
                        <img src="<?= getTrainerPhoto($trainer) ?>" class="hero-avatar"
                             alt="<?= htmlspecialchars($trainer['name']) ?>"
                             onerror="this.src='../assets/images/default-avatar.svg'">
                        <span class="hero-status <?= $trainer['is_active'] ? 'active' : 'inactive' ?>"></span>
                    </div>
                    <div class="hero-text">
                        <h1><?= htmlspecialchars($trainer['name']) ?></h1>
                        <div class="hero-sub">
                            <span><?= htmlspecialchars($trainer['email']) ?></span>
                            <span class="badge <?= $trainer['is_active'] ? '' : '' ?>" style="background:<?= $trainer['is_active'] ? 'rgba(16,185,129,0.25)' : 'rgba(148,163,184,0.25)' ?>; border-color:<?= $trainer['is_active'] ? 'rgba(16,185,129,0.3)' : 'rgba(148,163,184,0.3)' ?>;">
                                <?= $trainer['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                            <?php if ($trainer['specialization']): ?>
                            <span class="badge badge-spec"><?= htmlspecialchars($trainer['specialization']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="hero-exp">
                        <div class="hero-exp-val"><?= $trainer['years_of_experience'] ?? 0 ?></div>
                        <div class="hero-exp-label">Years Experience</div>
                    </div>
                    <div class="hero-actions">
                        <a href="batches.php?id=<?= $trainerId ?>" class="btn btn-outline-light"><i class="fas fa-layer-group"></i> Batches</a>
                    </div>
                </div>
            </div>

            <!-- ── KPI Cards (colorful, compact) ── -->
            <div class="kpi-grid">
                <div class="kpi-card card-navy">
                    <div class="kpi-icon-wrap"><i class="fas fa-layer-group"></i></div>
                    <div class="kpi-label">Total Batches</div>
                    <div class="kpi-value"><?= $batchCount ?></div>
                    <div class="kpi-sub">Assigned to trainer</div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= min(100, $batchCount * 10) ?>%"></div></div>
                </div>
                <div class="kpi-card card-green">
                    <div class="kpi-icon-wrap"><i class="fas fa-star"></i></div>
                    <div class="kpi-label">Average Rating</div>
                    <div class="kpi-value"><?= $avgRating ? number_format($avgRating, 1) : '—' ?></div>
                    <?php if ($avgRating): ?>
                    <div class="star-row-kpi">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($avgRating) ? 'star-filled-kpi' : 'star-empty-kpi' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <?php else: ?>
                    <div class="kpi-sub">No ratings yet</div>
                    <?php endif; ?>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= $avgRating ? ($avgRating/5)*100 : 0 ?>%; background:linear-gradient(90deg,#10b981,#34d399);"></div></div>
                </div>
                <div class="kpi-card card-amber">
                    <div class="kpi-icon-wrap"><i class="fas fa-comments"></i></div>
                    <div class="kpi-label">Total Feedback</div>
                    <div class="kpi-value"><?= $totalFeedback ?></div>
                    <div class="kpi-sub">
                        <?php
                            $fiveStars = 0;
                            foreach ($ratingDistribution as $r) { if ($r['rating'] >= 4) $fiveStars += $r['count']; }
                            $positivePct = $totalFeedback > 0 ? round(($fiveStars / $totalFeedback) * 100) : 0;
                        ?>
                        <i class="fas fa-thumbs-up" style="color:#10b981;"></i> <?= $positivePct ?>% positive
                    </div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= $positivePct ?>%; background:linear-gradient(90deg,#f59e0b,#fbbf24);"></div></div>
                </div>
            </div>

            <?php if (empty($ratingDistribution)): ?>
                <div class="rating-table-card">
                    <div class="empty-analytics">
                        <div class="empty-analytics-icon"><i class="fas fa-chart-bar"></i></div>
                        <div class="empty-analytics-title">No analytics data yet</div>
                        <div class="empty-analytics-sub">Performance data will appear here once students submit feedback for this trainer's batches.</div>
                    </div>
                </div>
            <?php else: ?>

                <!-- ── Charts ── -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div class="chart-card-title">
                                <div class="chart-card-icon"><i class="fas fa-star"></i></div>
                                Rating Distribution
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <div class="chart-wrap"><canvas id="ratingChart"></canvas></div>
                        </div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div class="chart-card-title">
                                <div class="chart-card-icon"><i class="fas fa-chart-line"></i></div>
                                Rating Over Time
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <div class="chart-wrap">
                                <?php if (!empty($performanceOverTime)): ?>
                                    <canvas id="performanceChart"></canvas>
                                <?php else: ?>
                                    <div class="empty-analytics" style="padding:20px 0;">
                                        <div class="empty-analytics-icon" style="font-size:24px;"><i class="fas fa-chart-line"></i></div>
                                        <div class="empty-analytics-sub">Not enough time-series data yet.</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Detailed Rating Table ── -->
                <div class="rating-table-card">
                    <div class="rating-table-header">
                        <div class="chart-card-icon" style="background:rgba(69,104,130,0.12);color:var(--navy-mid);"><i class="fas fa-table"></i></div>
                        <div class="rating-table-title">Detailed Feedback Breakdown</div>
                    </div>
                    <div class="rating-table-body">
                        <?php foreach ($ratingDistribution as $r):
                            $pct = $totalFeedback > 0 ? ($r['count'] / $totalFeedback) * 100 : 0;
                            $barColor = $r['rating'] >= 4 ? '#10B981' : ($r['rating'] >= 3 ? '#F59E0B' : '#EF4444');
                        ?>
                        <div class="rating-row">
                            <div class="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?= $i <= $r['rating'] ? 'star-on' : 'star-off' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <div class="rating-count"><?= $r['count'] ?></div>
                            <div>
                                <div class="rating-bar-wrap">
                                    <div class="rating-bar-fill" style="width: <?= round($pct) ?>%; background: <?= $barColor ?>;"></div>
                                </div>
                            </div>
                            <div class="rating-pct"><?= number_format($pct, 1) ?>%</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#7a9ab0';

// Rating Distribution Chart
const ratingCtx = document.getElementById('ratingChart');
if (ratingCtx) {
    const labels = <?= json_encode(array_map(fn($r) => $r['rating'] . ' Star' . ($r['rating'] != 1 ? 's' : ''), $ratingDistribution)) ?>;
    const values = <?= json_encode(array_column($ratingDistribution, 'count')) ?>;
    const ratings = <?= json_encode(array_column($ratingDistribution, 'rating')) ?>;
    const bgColors = ratings.map(r => r >= 4 ? 'rgba(16,185,129,0.85)' : r >= 3 ? 'rgba(245,158,11,0.85)' : 'rgba(239,68,68,0.85)');

    new Chart(ratingCtx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Feedback Count',
                data: values,
                backgroundColor: bgColors,
                borderRadius: 6,
                borderWidth: 0,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } },
                x: { grid: { display: false } }
            }
        }
    });
}

// Performance Over Time Chart
const perfCtx = document.getElementById('performanceChart');
if (perfCtx) {
    const months = <?= json_encode(array_column($performanceOverTime, 'month')) ?>;
    const ratings = <?= json_encode(array_map(fn($r) => round((float)$r['avg_rating'], 2), $performanceOverTime)) ?>;

    new Chart(perfCtx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [{
                label: 'Avg. Rating',
                data: ratings,
                borderColor: '#234C6A',
                backgroundColor: 'rgba(35,76,106,0.08)',
                borderWidth: 2.5,
                pointBackgroundColor: '#234C6A',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                tension: 0.35,
                fill: true,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    min: 1, max: 5,
                    grid: { color: '#f1f5f9' },
                    ticks: { stepSize: 1, callback: v => v + '★' }
                },
                x: { grid: { display: false } }
            }
        }
    });
}
</script>
</body>
</html>
