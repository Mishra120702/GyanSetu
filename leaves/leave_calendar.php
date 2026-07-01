<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../db_connection.php';

// Auth check – same as leave_management.php
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'mentor'])) {
    header("Location: ../login.php");
    exit();
}

$user_role = $_SESSION['user_role'];

// ── Month / year navigation ───────────────────────────────────────────────────
$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));

// Wrap around
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

// ── Filters ───────────────────────────────────────────────────────────────────
$batch_filter    = $_GET['batch']     ?? 'all';
$usertype_filter = $_GET['user_type'] ?? 'all';

// ── Date helpers ──────────────────────────────────────────────────────────────
$firstDay    = new DateTime("$year-$month-01");
$lastDay     = (clone $firstDay)->modify('last day of this month');
$firstDayStr = $firstDay->format('Y-m-d');
$lastDayStr  = $lastDay->format('Y-m-d');
$daysInMonth = (int)$lastDay->format('d');
$today       = date('Y-m-d');

// Day-of-week offset for Monday-start grid (1=Mon…7=Sun → offset 0…6)
$startOffset = (int)$firstDay->format('N') - 1;

// Prev / next month params
$prevDate  = (clone $firstDay)->modify('-1 month');
$nextDate  = (clone $firstDay)->modify('+1 month');

$buildNav = function(DateTime $d) use ($batch_filter, $usertype_filter) {
    return http_build_query(array_filter([
        'month'     => $d->format('n'),
        'year'      => $d->format('Y'),
        'batch'     => $batch_filter !== 'all'    ? $batch_filter    : '',
        'user_type' => $usertype_filter !== 'all' ? $usertype_filter : '',
    ]));
};

// ── Main query – approved leaves overlapping this month ───────────────────────
$sql = "
    SELECT
        l.id,
        l.application_no,
        l.start_date,
        l.end_date,
        l.total_days,
        l.reason_category,
        l.batch_id,
        b.batch_name  AS batch_title,
        CASE
            WHEN s.student_id IS NOT NULL THEN 'student'
            WHEN t.user_id    IS NOT NULL THEN 'trainer'
            ELSE 'unknown'
        END AS applicant_type,
        CASE
            WHEN s.student_id IS NOT NULL THEN CONCAT(s.first_name, ' ', s.last_name)
            WHEN t.user_id    IS NOT NULL THEN t.name
            ELSE l.student_name
        END AS applicant_name
    FROM leave_applications l
    LEFT JOIN batches  b ON l.batch_id  = b.batch_id
    LEFT JOIN students s ON l.student_id = s.student_id
    LEFT JOIN trainers t ON l.student_id = t.user_id
    WHERE l.status     = 'approved'
      AND l.start_date <= :last_day
      AND l.end_date   >= :first_day
";

$params = [':first_day' => $firstDayStr, ':last_day' => $lastDayStr];

if ($batch_filter !== 'all') {
    $sql .= " AND l.batch_id = :batch_id";
    $params[':batch_id'] = $batch_filter;
}
if ($usertype_filter === 'student') {
    $sql .= " AND s.student_id IS NOT NULL";
} elseif ($usertype_filter === 'trainer') {
    $sql .= " AND t.user_id IS NOT NULL";
}

$sql .= " ORDER BY l.start_date ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Build day-indexed map ─────────────────────────────────────────────────────
$calendarData = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $calendarData[sprintf('%04d-%02d-%02d', $year, $month, $d)] = [];
}

foreach ($leaves as $leave) {
    $start   = new DateTime($leave['start_date']);
    $end     = new DateTime($leave['end_date']);
    $clampS  = $start < $firstDay ? clone $firstDay : clone $start;
    $clampE  = $end   > $lastDay  ? clone $lastDay  : clone $end;

    for ($cur = clone $clampS; $cur <= $clampE; $cur->modify('+1 day')) {
        $key = $cur->format('Y-m-d');
        if (isset($calendarData[$key])) {
            $calendarData[$key][] = $leave;
        }
    }
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$uniqueApps     = array_unique(array_column($leaves, 'application_no'));
$totalOnLeave   = count($uniqueApps);
$studentLeaves  = count(array_filter($leaves, fn($l) => $l['applicant_type'] === 'student'));
$trainerLeaves  = count(array_filter($leaves, fn($l) => $l['applicant_type'] === 'trainer'));
$peakCount      = max(array_map('count', $calendarData) ?: [0]);

// Peak day label
$peakDay = '';
foreach ($calendarData as $date => $dayLeaves) {
    if (count($dayLeaves) === $peakCount && $peakCount > 0) {
        $peakDay = date('d M', strtotime($date));
        break;
    }
}

// ── Batch list for filter ─────────────────────────────────────────────────────
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name ASC")
              ->fetchAll(PDO::FETCH_ASSOC);

$dayNames  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$monthName = $firstDay->format('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Calendar – <?= $monthName ?> – ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(.95); }
            to   { opacity: 1; transform: scale(1); }
        }

        .animate-fade-up   { animation: fadeInUp .55s ease-out forwards; }
        .animate-scale-in  { animation: scaleIn .25s ease-out forwards; }
        .delay-100 { animation-delay: .10s; }
        .delay-200 { animation-delay: .20s; }
        .delay-300 { animation-delay: .30s; }

        /* ── Stat cards – enhanced hover effects ── */
        .stat-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 2px 12px rgba(27,60,83,0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(69,104,130,0.12);
            padding: 1.25rem 1rem;
            position: relative;
            overflow: hidden;
            cursor: default;
        }
        /* Left accent line */
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
        /* Background overlay on hover */
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
            border-color: var(--navy-light, #456882);
        }

        .stat-card .stat-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
            color: #fff;
            position: relative;
            z-index: 2;
        }
        .stat-card:hover .stat-icon-wrap {
            transform: scale(1.08) rotate(-2deg) translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }

        .stat-card .stat-content {
            position: relative;
            z-index: 2;
        }
        .stat-card .stat-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            color: #64748b;
            transition: color 0.3s ease;
        }
        .stat-card:hover .stat-label {
            color: var(--accent-color, #234C6A);
        }
        .stat-card .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1B3C53;
            line-height: 1.2;
            transition: color 0.3s ease;
        }
        .stat-card:hover .stat-number {
            color: var(--accent-color, #1B3C53);
        }
        .stat-card .stat-sub {
            font-size: 0.6rem;
            color: #94a3b8;
            transition: color 0.3s ease;
        }
        .stat-card:hover .stat-sub {
            color: var(--accent-color, #456882);
        }

        /* Icon variants – set accent color */
        .stat-icon-total { background: linear-gradient(135deg, #1B3C53, #456882); }
        .stat-total { --accent-color: #1B3C53; }
        .stat-icon-students { background: linear-gradient(135deg, #234C6A, #456882); }
        .stat-students { --accent-color: #234C6A; }
        .stat-icon-trainers { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .stat-trainers { --accent-color: #7c3aed; }
        .stat-icon-peak { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .stat-peak { --accent-color: #f59e0b; }

        /* ── Calendar ── */
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 4px;
        }
        .cal-header-cell {
            text-align: center;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            padding: 10px 0 8px;
            color: #4a5b6b;
        }
        .cal-header-cell.weekend { color: #6b4b7a; }

        .day-cell {
            background: #ffffff;
            border: 1px solid #dce3e9;
            border-radius: 12px;
            min-height: 110px;
            padding: 8px;
            transition: box-shadow .2s, border-color .2s;
            display: flex;
            flex-direction: column;
            gap: 3px;
            position: relative;
        }
        .day-cell:hover {
            border-color: #9bb0c0;
            box-shadow: 0 4px 14px rgba(27,60,83,0.10);
        }
        .day-cell.today-cell {
            border-color: #1B3C53;
            background: linear-gradient(135deg, #f0f4f8, #e6ecf3);
            box-shadow: 0 0 0 2px rgba(27,60,83,0.18);
        }
        .day-cell.weekend-cell {
            background: #f7f6f4;
        }
        .day-cell.empty-cell {
            background: transparent;
            border: 1px dashed #dce3e9;
            min-height: 110px;
        }

        .day-number {
            font-size: .78rem;
            font-weight: 700;
            color: #2d3f4e;
            line-height: 1;
            margin-bottom: 2px;
        }
        .today-cell .day-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            background: #1B3C53;
            color: #fff;
            border-radius: 50%;
        }
        .day-cell.weekend-cell .day-number {
            color: #6b4b7a;
        }

        /* Name chips – student & trainer */
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: .65rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 999px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            cursor: pointer;
            transition: filter .15s;
        }
        .chip:hover { filter: brightness(.92); }
        .chip-student {
            background: #e6edf4;
            color: #1B3C53;
            border: 1px solid #bccfd8;
        }
        .chip-trainer {
            background: #ede7f0;
            color: #5b3d6e;
            border: 1px solid #d5c8df;
        }

        .more-link {
            font-size: .65rem;
            font-weight: 600;
            color: #456882;
            cursor: pointer;
            padding: 1px 0;
            margin-top: auto;
        }
        .more-link:hover { text-decoration: underline; }

        /* ── Modal ── */
        #day-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            align-items: center;
            justify-content: center;
            background: rgba(15,23,42,.45);
            backdrop-filter: blur(4px);
        }
        #day-modal.open { display: flex; }
        #modal-box {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 24px 60px rgba(0,0,0,.2);
            width: 90%;
            max-width: 460px;
            max-height: 80vh;
            overflow-y: auto;
            animation: scaleIn .2s ease-out forwards;
        }
        .modal-header-theme {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: #fff;
            border-radius: 20px 20px 0 0;
        }

        /* ── Filter bar – enhanced border & shadow ── */
        .filter-card {
            background: rgba(255,255,255,0.92);
            border: 1px solid rgba(69,104,130,0.25);
            border-radius: 16px;
            backdrop-filter: blur(6px);
            box-shadow: 0 2px 16px rgba(27,60,83,0.08);
            transition: box-shadow 0.2s;
        }
        .filter-card:hover {
            box-shadow: 0 4px 24px rgba(27,60,83,0.12);
        }
        .form-select, .form-input {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: .85rem;
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
            width: 100%;
        }
        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: #456882;
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
        }
        .btn-nav {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: .85rem;
            font-weight: 600;
            background: #fff;
            border: 1px solid #d1d9e0;
            color: #2d3f4e;
            transition: all .15s;
            text-decoration: none;
        }
        .btn-nav:hover {
            background: #f0f4f8;
            border-color: #456882;
            color: #1B3C53;
        }
        .btn-today {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 10px;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: opacity .15s;
        }
        .btn-today:hover { opacity: .88; }

        /* Legend */
        .legend-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .75rem;
            font-weight: 500;
            padding: 4px 12px;
            border-radius: 999px;
        }
        .legend-student {
            background: #e6edf4;
            color: #1B3C53;
            border: 1px solid #bccfd8;
        }
        .legend-trainer {
            background: #ede7f0;
            color: #5b3d6e;
            border: 1px solid #d5c8df;
        }
        .legend-today {
            background: #eef2f7;
            color: #1B3C53;
            border: 1px solid #1B3C53;
        }
        .legend-weekend {
            background: #f7f6f4;
            color: #6b4b7a;
            border: 1px solid #d5c8df;
        }

        /* modal manage link */
        .btn-manage {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: #fff;
        }
        .btn-manage:hover {
            opacity: .9;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-100 via-blue-50 to-indigo-50 min-h-screen">
    <?php include '../header.php'; ?>

    <div class="flex">
        <?php include '../sidebar.php'; ?>

        <div class="flex-1 ml-64 p-8">

            <!-- ── Page header – navy/sand theme ─────────────────────────── -->
            <div class="mb-6 animate-fade-up">
                <div class="rounded-2xl shadow-xl p-6 overflow-hidden relative"
                     style="background: linear-gradient(135deg, #1B3C53 0%, #234C6A 60%, #456882 100%);">
                    <div class="absolute -top-6 -right-6 w-36 h-36 rounded-full opacity-10"
                         style="background: rgba(210,193,182,0.3);"></div>
                    <div class="absolute -bottom-8 -left-4 w-28 h-28 rounded-full opacity-10"
                         style="background: rgba(210,193,182,0.2);"></div>

                    <div class="flex items-center justify-between flex-wrap gap-4 relative z-10">
                        <div class="flex items-center space-x-4">
                            <div class="w-16 h-16 rounded-2xl shadow-lg flex items-center justify-center"
                                 style="background:rgba(210,193,182,0.20);border:2px solid rgba(210,193,182,0.30);">
                                <i class="fas fa-calendar-week text-white text-3xl"></i>
                            </div>
                            <div>
                                <h1 class="text-3xl font-bold text-white">Leave Calendar</h1>
                                <p class="text-blue-100 mt-1">Visual overview of approved leaves for <?= $monthName ?></p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <!-- Back to leave management -->
                            <a href="leave_management.php"
                               class="flex items-center gap-2 px-4 py-2 rounded-xl text-white text-sm font-semibold transition-all"
                               style="background:rgba(210,193,182,0.15);border:1px solid rgba(210,193,182,0.30);"
                               onmouseover="this.style.background='rgba(210,193,182,0.28)'"
                               onmouseout="this.style.background='rgba(210,193,182,0.15)'">
                                <i class="fas fa-list-alt"></i> All Applications
                            </a>
                            <div class="px-4 py-2 rounded-xl"
                                 style="background:rgba(210,193,182,0.20);border:1px solid rgba(210,193,182,0.30);">
                                <i class="fas fa-user-shield text-white mr-2"></i>
                                <span class="text-sm text-white font-semibold"><?= ucfirst($user_role) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Stats ───────────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 animate-fade-up delay-100">

                <div class="stat-card stat-total p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="stat-icon-wrap stat-icon-total">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Total</span>
                    </div>
                    <div class="stat-content">
                        <p class="stat-number"><?= $totalOnLeave ?></p>
                        <p class="stat-sub">approved leaves this month</p>
                    </div>
                </div>

                <div class="stat-card stat-students p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="stat-icon-wrap stat-icon-students">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Students</span>
                    </div>
                    <div class="stat-content">
                        <p class="stat-number"><?= $studentLeaves ?></p>
                        <p class="stat-sub">student leave requests</p>
                    </div>
                </div>

                <div class="stat-card stat-trainers p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="stat-icon-wrap stat-icon-trainers">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Trainers</span>
                    </div>
                    <div class="stat-content">
                        <p class="stat-number"><?= $trainerLeaves ?></p>
                        <p class="stat-sub">trainer leave requests</p>
                    </div>
                </div>

                <div class="stat-card stat-peak p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="stat-icon-wrap stat-icon-peak">
                            <i class="fas fa-fire"></i>
                        </div>
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Peak day</span>
                    </div>
                    <div class="stat-content">
                        <p class="stat-number"><?= $peakCount ?></p>
                        <p class="stat-sub"><?= $peakDay ? "most on " . $peakDay : "no leaves yet" ?></p>
                    </div>
                </div>

            </div>

            <!-- ── Filter + navigation row ────────────────────────────────── -->
            <div class="filter-card p-5 mb-6 animate-fade-up delay-200">
                <div class="flex flex-wrap items-center gap-4">

                    <!-- Month navigation -->
                    <div class="flex items-center gap-2">
                        <a href="?<?= $buildNav($prevDate) ?>" class="btn-nav">
                            <i class="fas fa-chevron-left text-xs"></i>
                        </a>
                        <span class="text-base font-bold text-gray-800 min-w-[140px] text-center">
                            <?= $monthName ?>
                        </span>
                        <a href="?<?= $buildNav($nextDate) ?>" class="btn-nav">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                    </div>

                    <!-- Today button -->
                    <a href="?<?= http_build_query(array_filter([
                        'month'     => date('n'),
                        'year'      => date('Y'),
                        'batch'     => $batch_filter !== 'all' ? $batch_filter : '',
                        'user_type' => $usertype_filter !== 'all' ? $usertype_filter : '',
                    ])) ?>" class="btn-today">
                        <i class="fas fa-crosshairs mr-1"></i> Today
                    </a>

                    <div class="flex-1"></div>

                    <!-- Batch filter -->
                    <form method="GET" class="flex items-center gap-3">
                        <input type="hidden" name="month" value="<?= $month ?>">
                        <input type="hidden" name="year"  value="<?= $year ?>">

                        <div>
                            <select name="user_type" class="form-select" onchange="this.form.submit()">
                                <option value="all"     <?= $usertype_filter === 'all'     ? 'selected' : '' ?>>All users</option>
                                <option value="student" <?= $usertype_filter === 'student' ? 'selected' : '' ?>>Students only</option>
                                <option value="trainer" <?= $usertype_filter === 'trainer' ? 'selected' : '' ?>>Trainers only</option>
                            </select>
                        </div>

                        <div>
                            <select name="batch" class="form-select" onchange="this.form.submit()">
                                <option value="all">All batches</option>
                                <?php foreach ($batches as $b): ?>
                                    <option value="<?= htmlspecialchars($b['batch_id']) ?>"
                                            <?= $batch_filter === (string)$b['batch_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($batch_filter !== 'all' || $usertype_filter !== 'all'): ?>
                            <a href="?month=<?= $month ?>&year=<?= $year ?>"
                               class="text-xs font-semibold text-red-400 hover:text-red-600 transition-colors flex items-center gap-1 whitespace-nowrap">
                                <i class="fas fa-times-circle"></i> Clear filters
                            </a>
                        <?php endif; ?>
                    </form>

                </div>
            </div>

            <!-- ── Calendar grid ──────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-md p-5 animate-fade-up delay-300">

                <!-- Day-of-week headers -->
                <div class="cal-grid mb-1">
                    <?php foreach ($dayNames as $i => $dn): ?>
                        <div class="cal-header-cell <?= $i >= 5 ? 'weekend' : '' ?>">
                            <?= $dn ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar cells -->
                <div class="cal-grid">
                    <?php
                    // Empty leading cells
                    for ($e = 0; $e < $startOffset; $e++): ?>
                        <div class="day-cell empty-cell"></div>
                    <?php endfor;

                    // Day cells
                    for ($d = 1; $d <= $daysInMonth; $d++):
                        $dateStr   = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $dayLeaves = $calendarData[$dateStr];
                        $isToday   = $dateStr === $today;
                        $dow       = (int)(new DateTime($dateStr))->format('N'); // 1=Mon…7=Sun
                        $isWeekend = $dow >= 6;
                        $classes   = 'day-cell';
                        if ($isToday)   $classes .= ' today-cell';
                        elseif ($isWeekend) $classes .= ' weekend-cell';
                    ?>
                        <div class="<?= $classes ?>" 
                             id="cell-<?= $dateStr ?>"
                             <?= count($dayLeaves) > 0 ? 'onclick="openModal(\'' . $dateStr . '\')" style="cursor:pointer;"' : '' ?>>

                            <div class="day-number"><?= $d ?></div>

                            <?php
                            $max       = 2; // chips shown before "+N more"
                            $shown     = 0;
                            $overflow  = 0;

                            foreach ($dayLeaves as $idx => $leave):
                                if ($shown < $max):
                                    $chipClass = $leave['applicant_type'] === 'trainer' ? 'chip-trainer' : 'chip-student';
                                    $icon      = $leave['applicant_type'] === 'trainer' ? 'fa-chalkboard-teacher' : 'fa-user-graduate';
                                    $name      = $leave['applicant_name'];
                                    // Truncate long names
                                    $display   = mb_strlen($name) > 14 ? mb_substr($name, 0, 13) . '…' : $name;
                            ?>
                                <span class="chip <?= $chipClass ?>" title="<?= htmlspecialchars($name) ?> · <?= htmlspecialchars($leave['batch_title'] ?? '') ?>">
                                    <i class="fas <?= $icon ?>" style="font-size:.55rem"></i>
                                    <?= htmlspecialchars($display) ?>
                                </span>
                            <?php
                                    $shown++;
                                else:
                                    $overflow++;
                                endif;
                            endforeach;

                            if ($overflow > 0):
                            ?>
                                <span class="more-link">+<?= $overflow ?> more</span>
                            <?php endif; ?>

                            <?php if (count($dayLeaves) === 0 && !$isWeekend): ?>
                                <span style="font-size:.6rem;color:#b0c4d0;margin-top:auto;">No leaves</span>
                            <?php endif; ?>
                        </div>
                    <?php endfor;

                    // Trailing empty cells to complete the last row
                    $totalCells  = $startOffset + $daysInMonth;
                    $trailingCells = (7 - ($totalCells % 7)) % 7;
                    for ($e = 0; $e < $trailingCells; $e++): ?>
                        <div class="day-cell empty-cell"></div>
                    <?php endfor; ?>

                </div>

                <!-- Legend -->
                <div class="flex flex-wrap items-center gap-3 mt-5 pt-4 border-t border-gray-100">
                    <span class="text-xs font-semibold text-gray-500 mr-1">Legend:</span>
                    <span class="legend-pill legend-student">
                        <i class="fas fa-user-graduate" style="font-size:.65rem"></i> Student
                    </span>
                    <span class="legend-pill legend-trainer">
                        <i class="fas fa-chalkboard-teacher" style="font-size:.65rem"></i> Trainer
                    </span>
                    <span class="legend-pill legend-today">
                        <i class="fas fa-circle" style="font-size:.45rem"></i> Today
                    </span>
                    <span class="legend-pill legend-weekend">
                        <i class="fas fa-calendar-day" style="font-size:.6rem"></i> Weekend
                    </span>
                    <span class="ml-auto text-xs text-gray-400">Click any day to see full details</span>
                </div>

            </div>
            <!-- /calendar -->

        </div><!-- /main content -->
    </div><!-- /flex -->


    <!-- ── Day detail modal ───────────────────────────────────────────────── -->
    <div id="day-modal" onclick="closeModal(event)">
        <div id="modal-box" onclick="event.stopPropagation()">

            <!-- Modal header – navy theme -->
            <div class="modal-header-theme flex items-center justify-between p-5">
                <div>
                    <p class="text-xs font-semibold text-blue-200 uppercase tracking-wide mb-0.5">Leave details</p>
                    <h2 class="text-xl font-bold text-white" id="modal-date-title"></h2>
                </div>
                <button onclick="closeModal()" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition-colors text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Modal body -->
            <div class="p-5" id="modal-body"></div>

            <!-- Modal footer -->
            <div class="px-5 pb-5">
                <a id="modal-manage-link" href="leave_management.php"
                   class="btn-manage block w-full text-center py-2.5 px-4 rounded-xl font-semibold text-sm text-white transition-opacity hover:opacity-90">
                    <i class="fas fa-list-alt mr-1"></i> Manage all applications
                </a>
            </div>

        </div>
    </div>

    <!-- ── JS data + modal logic ─────────────────────────────────────────── -->
    <script>
    // Build full calendar data for JS modal
    const calData = <?php
        $jsData = [];
        foreach ($calendarData as $date => $dayLeaves) {
            $jsData[$date] = array_map(fn($l) => [
                'name'     => $l['applicant_name'],
                'type'     => $l['applicant_type'],
                'batch'    => $l['batch_title'] ?? '',
                'category' => $l['reason_category'] ?? '',
                'start'    => date('d M Y', strtotime($l['start_date'])),
                'end'      => date('d M Y', strtotime($l['end_date'])),
                'days'     => $l['total_days'],
                'app_no'   => $l['application_no'],
            ], $dayLeaves);
        }
        echo json_encode($jsData, JSON_HEX_TAG);
    ?>;

    const dayLabels = <?= json_encode(array_reduce(
        array_keys($calendarData),
        function($carry, $date) {
            $carry[$date] = date('l, d F Y', strtotime($date));
            return $carry;
        },
        []
    )) ?>;

    function openModal(date) {
        const leaves = calData[date] || [];
        if (!leaves.length) return;

        document.getElementById('modal-date-title').textContent = dayLabels[date] || date;

        let html = '';
        if (!leaves.length) {
            html = '<p class="text-gray-400 text-sm text-center py-6">No leaves on this day.</p>';
        } else {
            html = `<p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">${leaves.length} person${leaves.length > 1 ? 's' : ''} on leave</p>`;
            html += '<div class="space-y-3">';
            leaves.forEach(l => {
                const isTrainer = l.type === 'trainer';
                const bgClass   = isTrainer
                    ? 'background:#f5f0f8;border:1px solid #e3d7ed;'
                    : 'background:#ecf2f7;border:1px solid #d4e0e9;';
                const nameColor = isTrainer ? 'color:#5b3d6e;' : 'color:#1B3C53;';
                const icon      = isTrainer ? 'fa-chalkboard-teacher' : 'fa-user-graduate';
                const badge     = isTrainer
                    ? '<span style="background:#ede7f0;color:#5b3d6e;font-size:.65rem;padding:2px 8px;border-radius:999px;font-weight:600;">Trainer</span>'
                    : '<span style="background:#e6edf4;color:#1B3C53;font-size:.65rem;padding:2px 8px;border-radius:999px;font-weight:600;">Student</span>';

                html += `
                <div style="${bgClass}border-radius:12px;padding:12px 14px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <i class="fas ${icon}" style="${nameColor}font-size:.9rem;"></i>
                            <span style="${nameColor}font-weight:700;font-size:.9rem;">${escHtml(l.name)}</span>
                        </div>
                        ${badge}
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:.75rem;color:#5a6f7e;">
                        <span><i class="fas fa-layer-group" style="margin-right:4px;"></i>${escHtml(l.batch || '—')}</span>
                        <span><i class="fas fa-tag" style="margin-right:4px;"></i>${escHtml(l.category || '—')}</span>
                        <span><i class="fas fa-calendar-alt" style="margin-right:4px;"></i>${escHtml(l.start)}</span>
                        <span><i class="fas fa-calendar-check" style="margin-right:4px;"></i>${escHtml(l.end)}</span>
                        <span><i class="fas fa-sun" style="margin-right:4px;"></i>${l.days} day${l.days != 1 ? 's' : ''}</span>
                        <span style="color:#7a8f9f;">${escHtml(l.app_no)}</span>
                    </div>
                </div>`;
            });
            html += '</div>';
        }

        document.getElementById('modal-body').innerHTML = html;
        document.getElementById('day-modal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(e) {
        if (e && e.target !== document.getElementById('day-modal') && !e.target.closest('#day-modal')) return;
        document.getElementById('day-modal').classList.remove('open');
        document.body.style.overflow = '';
    }

    // Close on Escape
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    </script>

</body>
</html>