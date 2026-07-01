<?php
require_once '../db_connection.php';
session_start();

// Check if user is logged in and is a trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit();
}

$trainer_id = $_SESSION['user_id'];
$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
$exam_type = isset($_GET['exam_type']) ? $_GET['exam_type'] : '';

// Get trainer details
$stmt = $db->prepare("SELECT t.* FROM trainers t WHERE t.user_id = ?");
$stmt->execute([$trainer_id]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

// Robust trainer matching:
$trainer_match_ids = array_values(array_unique(array_filter([
    (int)$trainer['id'],
    (int)$trainer_id
])));
$trainer_placeholders = implode(',', array_fill(0, count($trainer_match_ids), '?'));

// Get assigned batches for filter
$batches_stmt = $db->prepare("
    SELECT DISTINCT b.batch_id, b.batch_name 
    FROM batches b 
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
    WHERE b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders)
    ORDER BY b.batch_name
");
$batches_stmt->execute(array_merge($trainer_match_ids, $trainer_match_ids));
$assigned_batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for exams
$sql = "
    SELECT DISTINCT e.*, b.batch_name,
           (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.exam_id) as results_uploaded,
           (SELECT COUNT(*) FROM students s WHERE s.batch_name = e.batch_id AND s.current_status = 'active') as total_students
    FROM exams e 
    JOIN batches b ON e.batch_id = b.batch_id 
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
    WHERE (b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders))
";

$params = array_merge($trainer_match_ids, $trainer_match_ids);

if (!empty($batch_id)) {
    $sql .= " AND e.batch_id = ?";
    $params[] = $batch_id;
}

if (!empty($exam_type)) {
    $sql .= " AND e.exam_type = ?";
    $params[] = $exam_type;
}

$sql .= " ORDER BY e.exam_date DESC";

$exams_stmt = $db->prepare($sql);
$exams_stmt->execute($params);
$exams = $exams_stmt->fetchAll(PDO::FETCH_ASSOC);

// Lightweight summary chip data (computed from $exams already fetched - no extra queries)
$exams_total_uploaded = array_sum(array_column($exams, 'results_uploaded'));
$exams_total_students = array_sum(array_column($exams, 'total_students'));
$exams_overall_completion = $exams_total_students > 0 ? round(($exams_total_uploaded / $exams_total_students) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Manage Exams - Trainer Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(241, 245, 249, 0.5); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 2px; }

        /* Page gradient background */
        body {
            background: linear-gradient(135deg, #f0f4ff 0%, #f8fafc 40%, #f0fdf4 100%);
            min-height: 100vh;
        }

        /* Header gradient */
        .page-header-gradient {
            background: linear-gradient(135deg, #ffffff 0%, #F6F1ED 100%);
            border-bottom: 1px solid #e2e8f0;
            border-radius: 1.25rem;
            padding: 1.5rem;
            box-shadow: 0 1px 8px 0 rgba(27,60,83,0.06);
        }

        /* Filter panel gradient */
        .filter-gradient {
            background: linear-gradient(120deg, #ffffff 0%, #EEF3F6 100%);
            border: 1px solid #e0e7ff;
        }

        /* Upload hub gradient */
        .upload-gradient {
            background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
            border: 1px solid #d1fae5;
        }

        /* Exam card gradient on hover */
        .exam-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8faff 100%);
            border-left: 4px solid #1B3C53;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .exam-card:hover {
            box-shadow: 0 8px 32px 0 rgba(27,60,83,0.10);
            transform: translateY(-2px);
        }

        /* Progress bar gradient */
        .progress-gradient-full { background: linear-gradient(90deg, #10b981, #059669); }
        .progress-gradient-mid  { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .progress-gradient-low  { background: linear-gradient(90deg, #f43f5e, #e11d48); }

        /* Section title accent */
        .section-label {
            background: linear-gradient(90deg, #1B3C53, #234C6A);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 800;
            font-size: 0.7rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        /* Upload dashed border on hover */
        .upload-drop:hover {
            border-color: #1B3C53;
            background: linear-gradient(135deg, #EEF3F6 0%, #f0fdf4 100%);
        }

        /* Stat chip gradient */
        .stat-chip {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
        }

        /* Apply filter button gradient */
        .btn-gradient {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            box-shadow: 0 4px 14px 0 rgba(27,60,83,0.25);
        }
        .btn-gradient:hover {
            background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);
        }
    
        /* ===== Same trainer purple/pink dashboard theme: visual-only enhancement ===== */
        :root {
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 46%, #456882 100%);
            --dash-blue: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --dash-green: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
            --dash-orange: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            --dash-red: linear-gradient(135deg, #ef4444 0%, #f43f5e 100%);
            --dash-ink: #101827;
        }

        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83,.15), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130,.15), transparent 30%),
                linear-gradient(135deg, #eef4ff 0%, #f7f3ff 48%, #f8fbff 100%) !important;
            color: var(--dash-ink);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(27,60,83,.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(69,104,130,.045) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.82), transparent 84%);
            z-index: -2;
        }

        aside { z-index: 50; }

        .page-header-gradient {
            position: relative;
            overflow: hidden;
            border-radius: 28px !important;
            padding: clamp(1.15rem, 2.5vw, 1.8rem) !important;
            color: white;
            background: var(--dash-main) !important;
            box-shadow: 0 24px 58px rgba(27,60,83,.25) !important;
            border: 1px solid rgba(255,255,255,.22) !important;
        }

        .page-header-gradient::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 430px;
            right: -135px;
            top: -145px;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            filter: blur(2px);
        }

        .page-header-gradient::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .page-header-gradient > * {
            position: relative;
            z-index: 1;
        }

        .page-header-gradient h1,
        .page-header-gradient p {
            color: white !important;
        }

        .page-header-gradient h1 {
            letter-spacing: -.03em;
        }

        .page-header-gradient p {
            color: rgba(255,255,255,.82) !important;
            font-weight: 600;
        }

        .page-header-gradient .bg-indigo-50 {
            background: rgba(255,255,255,.17) !important;
            color: white !important;
            border: 1px solid rgba(255,255,255,.24);
            backdrop-filter: blur(12px);
        }

        .page-header-gradient .stat-chip {
            background: rgba(255,255,255,.17) !important;
            border: 1px solid rgba(255,255,255,.24);
            backdrop-filter: blur(12px);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.18) !important;
        }

        .filter-gradient,
        .upload-gradient,
        .exam-card {
            position: relative;
            overflow: hidden;
            background: rgba(255,255,255,.90) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }

        .filter-gradient::before,
        .upload-gradient::before,
        .exam-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--dash-main));
        }

        .filter-gradient::after,
        .upload-gradient::after,
        .exam-card::after {
            content: "";
            position: absolute;
            width: 190px;
            height: 190px;
            right: -65px;
            top: -65px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(8px);
            opacity: .74;
            pointer-events: none;
        }

        .filter-gradient > *,
        .upload-gradient > *,
        .exam-card > * {
            position: relative;
            z-index: 1;
        }

        .filter-gradient {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .upload-gradient {
            --feature-accent: linear-gradient(90deg, #f59e0b, #f97316, #456882);
            --feature-glow: radial-gradient(circle, rgba(249,115,22,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .exam-card {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
            border-left: 0 !important;
        }

        .exam-card:hover,
        .filter-gradient:hover,
        .upload-gradient:hover {
            transform: translateY(-3px);
            box-shadow: 0 22px 48px rgba(15,23,42,.11) !important;
        }

        .section-label {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            width: fit-content;
            padding: .42rem .82rem;
            border-radius: 999px;
            margin-bottom: .2rem;
            background: rgba(255,255,255,.84);
            border: 1px solid rgba(226,232,240,.9);
            color: #1B3C53 !important;
            -webkit-text-fill-color: #1B3C53 !important;
            font-size: .72rem !important;
            font-weight: 900 !important;
            letter-spacing: .06em !important;
            box-shadow: 0 6px 18px rgba(15,23,42,.05);
        }

        .btn-gradient,
        .stat-chip {
            background: var(--dash-main) !important;
            box-shadow: 0 12px 28px rgba(35,76,106,.20) !important;
        }

        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 34px rgba(35,76,106,.27) !important;
        }

        select,
        .filter-gradient a,
        .exam-card a,
        .upload-drop {
            border-radius: 16px !important;
        }

        select:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 4px rgba(139,92,246,.12) !important;
        }

        .upload-drop {
            border-color: rgba(196,181,253,.65) !important;
            background: linear-gradient(135deg, rgba(248,250,252,.95), rgba(245,243,255,.92)) !important;
        }

        .upload-drop:hover {
            border-color: #234C6A !important;
            background: linear-gradient(135deg, rgba(238,242,255,.96), rgba(253,242,248,.92)) !important;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.7), 0 12px 28px rgba(35,76,106,.10);
        }

        .upload-drop .bg-indigo-50 {
            background: var(--dash-main) !important;
            color: white !important;
            box-shadow: 0 12px 26px rgba(35,76,106,.18);
        }

        .progress-gradient-full,
        .progress-gradient-mid,
        .progress-gradient-low {
            background: var(--dash-main) !important;
        }

        .exam-card .bg-slate-100 {
            background: rgba(248,250,252,.82) !important;
            border: 1px solid rgba(226,232,240,.75);
        }

        .exam-card .rounded-full,
        .exam-card .rounded-md {
            border-radius: 999px !important;
        }

        .exam-card .bg-white.border {
            background: rgba(255,255,255,.86) !important;
            border-color: rgba(226,232,240,.9) !important;
        }

        .exam-card .bg-white.border:hover {
            background: linear-gradient(135deg, rgba(245,243,255,.94), rgba(253,242,248,.88)) !important;
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(15,23,42,.08);
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
        }

        @media (max-width: 768px) {
            .page-header-gradient,
            .filter-gradient,
            .upload-gradient,
            .exam-card {
                border-radius: 20px !important;
            }
        }

    
/* ===== Brand palette update: #1B3C53, #234C6A, #456882, #D2C1B6 ===== */
:root {
    --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --trainer-primary: #234C6A !important;
    --trainer-violet: #1B3C53 !important;
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
}
body {
    background:
        radial-gradient(circle at 14% 10%, rgba(27,60,83,.13), transparent 28%),
        radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
        linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
}
.bg-gradient-to-r.from-purple-500.to-pink-500,
.bg-gradient-to-r.from-indigo-500.to-purple-500,
.bg-gradient-to-r.from-indigo-600.to-purple-600,
.bg-gradient-to-r.from-blue-500.to-cyan-500,
.bg-gradient-to-r.from-blue-500.to-indigo-500,
.bg-gradient-to-r.from-purple-600.to-pink-600,
.bg-gradient-to-br.from-purple-500.to-pink-500,
.bg-gradient-to-br.from-blue-500.to-indigo-500,
.bg-gradient-to-br.from-indigo-500.to-purple-500,
.avatar-gradient,.avatar-gradient-2,.avatar-gradient-3,.avatar-gradient-4,.avatar-gradient-5 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.text-purple-500,.text-purple-600,.text-indigo-500,.text-indigo-600,.text-blue-500,.text-blue-600,.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-purple-200,.border-indigo-200,.border-blue-200 {
    border-color: rgba(69,104,130,.25) !important;
}
button[style*="--primary-gradient"],.btn-primary,.tab-button.active,.view-toggle.active,.page-link.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.gradient-text {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    color: transparent !important;
}
.hero-chip,.section-kicker {
    border-color: rgba(210,193,182,.45) !important;
}

    </style>
</head>
<body class="bg-slate-50 font-sans min-h-screen text-slate-800 antialiased">

    <?php include '../t_sidebar.php'; ?>

    <main class="ml-0 lg:ml-64 min-h-screen p-4 md:p-8 space-y-6 transition-all duration-300">
            
            <header class="page-header-gradient flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                        <span class="p-2 rounded-xl bg-indigo-50 text-indigo-600"><i class="fas fa-file-alt text-xl"></i></span>
                        Manage Course Exams
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">Review test schedules, track class grades, upload assets, and chat with candidates.</p>
                </div>
                <div class="flex items-center gap-3 self-start sm:self-auto">
                    <div class="hidden sm:flex items-center gap-2 px-4 py-2 rounded-xl stat-chip text-white shadow-md">
                        <i class="fas fa-chart-pie text-xs"></i>
                        <span class="text-xs font-bold"><?php echo $exams_overall_completion; ?>% Results Complete</span>
                    </div>
                    <a href="trainer_dashboard.php" class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 px-4 py-2.5 rounded-xl transition-all shadow-sm">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </header>

            <section class="filter-gradient rounded-2xl p-5 shadow-sm">
                <div class="section-label mb-4"><i class="fas fa-filter"></i> Exam Filters</div>
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Filter by Assigned Batch</label>
                        <select name="batch_id" class="w-full text-sm bg-slate-50 border border-slate-200 text-slate-700 rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all">
                            <option value="">All Batches</option>
                            <?php foreach ($assigned_batches as $batch): ?>
                                <option value="<?php echo $batch['batch_id']; ?>" <?php echo $batch_id == $batch['batch_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($batch['batch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Filter by Exam Configuration</label>
                        <select name="exam_type" class="w-full text-sm bg-slate-50 border border-slate-200 text-slate-700 rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all">
                            <option value="">All Types</option>
                            <option value="unit_test" <?php echo $exam_type == 'unit_test' ? 'selected' : ''; ?>>Unit Test</option>
                            <option value="quarterly" <?php echo $exam_type == 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                            <option value="half-yearly" <?php echo $exam_type == 'half-yearly' ? 'selected' : ''; ?>>Half Yearly</option>
                            <option value="final" <?php echo $exam_type == 'final' ? 'selected' : ''; ?>>Final</option>
                            <option value="practice" <?php echo $exam_type == 'practice' ? 'selected' : ''; ?>>Practice</option>
                        </select>
                    </div>

                    <div class="flex gap-2.5">
                        <button type="submit" class="flex-1 inline-flex items-center justify-center gap-2 text-xs font-bold uppercase tracking-wider text-white btn-gradient h-[42px] px-4 rounded-xl transition-all">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="trainer_exams.php" class="inline-flex items-center justify-center text-slate-500 bg-slate-100 hover:bg-slate-200 h-[42px] px-4 rounded-xl transition-all" title="Reset Filters">
                            <i class="fas fa-redo"></i>
                        </a>
                    </div>
                </form>
            </section>

            <div class="space-y-6">
                    
                    <section class="upload-gradient rounded-2xl p-5 shadow-sm">
                        <div class="section-label mb-4"><i class="fas fa-cloud-upload-alt"></i> Resource Hub</div>
                        <div class="mb-4">
                            <h3 class="font-bold text-base text-slate-900">Trainer Resources Upload Hub</h3>
                            <p class="text-xs text-slate-500">Distribute mock sheets, study syllabi, or answer outlines directly into active batches.</p>
                        </div>
                        
                        <div class="upload-drop border-2 border-dashed border-emerald-200 rounded-xl p-6 text-center transition-all cursor-pointer group">
                            <input type="file" id="portalAssetUpload" class="hidden" multiple>
                            <label for="portalAssetUpload" class="cursor-pointer block">
                                <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-full flex items-center justify-center mx-auto mb-2 group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-cloud-arrow-up text-sm"></i>
                                </div>
                                <p class="text-xs font-semibold text-slate-700">Drag & drop files here or <span class="text-indigo-600 underline">browse templates</span></p>
                                <p class="text-[10px] text-slate-400 mt-1">Accepts PDF, DOCX, XLSX matrices up to 45MB</p>
                            </label>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <h2 class="section-label">Current Assigned Exam Matrices</h2>

                        <?php if (count($exams) > 0): ?>
                            <?php foreach ($exams as $exam): 
                                $progress = $exam['total_students'] > 0 ? ($exam['results_uploaded'] / $exam['total_students']) * 100 : 0;
                                $is_past_exam = strtotime($exam['exam_date']) < time();
                                $exam_components = !empty($exam['exam_components']) ? explode(',', $exam['exam_components']) : [];
                            ?>
                                <div class="exam-card rounded-2xl border border-slate-200 p-5 shadow-sm animate__animated animate__fadeIn">
                                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                                        
                                        <div class="flex-1 min-w-0 space-y-3.5">
                                            <div class="flex items-start justify-between gap-4">
                                                <h3 class="text-base font-bold text-slate-900 truncate"><?php echo htmlspecialchars($exam['exam_name']); ?></h3>
                                                <span class="inline-flex items-center text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full shadow-sm <?php echo $is_past_exam ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/10'; ?>">
                                                    <?php echo $is_past_exam ? 'Archived / Past' : 'Live / Active'; ?>
                                                </span>
                                            </div>

                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-xs text-slate-600">
                                                <div class="flex items-center gap-2"><i class="fas fa-users text-slate-400 w-4"></i> <span><strong>Batch:</strong> <?php echo htmlspecialchars($exam['batch_name']); ?></span></div>
                                                <div class="flex items-center gap-2"><i class="fas fa-calendar text-slate-400 w-4"></i> <span><strong>Date:</strong> <?php echo date('M j, Y', strtotime($exam['exam_date'])); ?></span></div>
                                                <div class="flex items-center gap-2"><i class="fas fa-book text-slate-400 w-4"></i> <span><strong>Subject:</strong> <?php echo htmlspecialchars($exam['subject']); ?></span></div>
                                                <div class="flex items-center gap-2">
                                                    <i class="fas fa-chart-bar text-slate-400 w-4"></i> 
                                                    <span><strong>Format:</strong> <span class="bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded text-[11px] font-medium"><?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?></span></span>
                                                </div>
                                            </div>

                                            <?php if (!empty($exam_components)): ?>
                                                <div class="flex flex-wrap items-center gap-1.5 pt-1">
                                                    <span class="text-[11px] font-medium text-slate-400 mr-1">Modules:</span>
                                                    <?php foreach ($exam_components as $component): 
                                                        $badge_style = 'bg-slate-100 text-slate-700';
                                                        if($component === 'mcq') $badge_style = 'bg-blue-50 text-blue-700 border border-blue-200';
                                                        if($component === 'project') $badge_style = 'bg-emerald-50 text-emerald-700 border border-emerald-200';
                                                        if($component === 'viva') $badge_style = 'bg-amber-50 text-amber-700 border border-amber-200';
                                                    ?>
                                                        <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-md <?php echo $badge_style; ?>">
                                                            <?php echo strtoupper($component); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="space-y-1.5 pt-1">
                                                <div class="flex justify-between items-center text-xs">
                                                    <span class="text-slate-500 font-medium">Evaluation Upload Metric</span>
                                                    <span class="font-bold text-slate-700"><?php echo $exam['results_uploaded']; ?> <span class="text-slate-400 font-normal">/ <?php echo $exam['total_students']; ?> Finished</span></span>
                                                </div>
                                                <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                                    <div class="h-full rounded-full transition-all duration-500 <?php echo $progress == 100 ? 'progress-gradient-full' : ($progress > 50 ? 'progress-gradient-mid' : 'progress-gradient-low'); ?>" style="width: <?php echo $progress; ?>%"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex flex-col sm:flex-row lg:flex-col gap-2 shrink-0 w-full lg:w-48">
                                            <a href="upload_results.php?exam_id=<?php echo $exam['exam_id']; ?>" class="flex-1 inline-flex items-center justify-center gap-2 text-xs font-bold text-white btn-gradient py-2.5 px-4 rounded-xl transition-all">
                                                <i class="fas fa-upload"></i> Upload Results
                                            </a>
                                            <a href="trainer_exam_details.php?exam_id=<?php echo $exam['exam_id']; ?>" class="flex-1 inline-flex items-center justify-center gap-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 py-2.5 px-4 rounded-xl transition-all shadow-sm">
                                                <i class="fas fa-eye"></i> View Details
                                            </a>
                                            <a href="trainer_results.php?exam_id=<?php echo $exam['exam_id']; ?>" class="flex-1 inline-flex items-center justify-center gap-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 py-2.5 px-4 rounded-xl transition-all shadow-sm">
                                                <i class="fas fa-chart-line"></i> Analytics Matrix
                                            </a>
                                        </div>

                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-12 bg-white border border-slate-200 rounded-2xl">
                                <div class="w-12 h-12 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mx-auto mb-3"><i class="fas fa-file-alt text-lg"></i></div>
                                <h3 class="text-sm font-bold text-slate-700">No Exam Criteria Found</h3>
                                <p class="text-xs text-slate-400 mt-1 max-w-xs mx-auto">There are no operational test entries assigned matching your filter selections.</p>
                                <a href="trainer_exams.php" class="mt-4 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-white btn-gradient px-4 py-2 rounded-xl transition-all">Reset Filters</a>
                            </div>
                        <?php endif; ?>
                    </section>

            </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>