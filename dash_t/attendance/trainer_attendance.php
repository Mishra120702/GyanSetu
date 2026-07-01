<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit;
}

require_once '../db_connection.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $user_id = $_SESSION['user_id'];
    
    // Get trainer details with proper validation
    $trainer_stmt = $db->prepare("
        SELECT t.*, u.name, u.email, u.role 
        FROM trainers t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.user_id = ? AND u.status = 'active'
    ");
    $trainer_stmt->execute([$user_id]);
    $trainer = $trainer_stmt->fetch(PDO::FETCH_ASSOC);

    if ($trainer === false) {
        // If trainer not found in trainers table, check if user exists
        $user_stmt = $db->prepare("SELECT name, email, role FROM users WHERE id = ? AND status = 'active'");
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $trainer = [
                'id' => 0,
                'user_id' => $user_id,
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ];
        } else {
            header("Location: ../../logout_t.php");
            exit;
        }
    }
    
    // Get batches and courses assigned to this trainer
    $batch_stmt = $db->prepare("
        SELECT 
            b.batch_id, 
            b.batch_name,
            c.id as course_id,
            c.name as course_name,
            b.start_date, 
            b.end_date, 
            b.status, 
            b.time_slot, 
            b.mode, 
            b.max_students, 
            b.current_enrollment,
            COUNT(DISTINCT s.student_id) as student_count
        FROM batch_courses bc
        JOIN batches b ON bc.batch_id = b.batch_id
        JOIN courses c ON bc.course_id = c.id
        LEFT JOIN students s ON (
            s.batch_name = b.batch_id OR 
            s.batch_name_2 = b.batch_id OR 
            s.batch_name_3 = b.batch_id
        ) AND s.current_status = 'active'
        WHERE bc.trainer_id = ? 
        AND b.status IN ('ongoing', 'upcoming')
        GROUP BY b.batch_id, c.id
        ORDER BY 
            CASE b.status WHEN 'ongoing' THEN 1 WHEN 'upcoming' THEN 2 ELSE 3 END,
            b.created_at DESC
    ");
    $batch_stmt->execute([$trainer['id']]);
    $batches = $batch_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $preselected_class = isset($_GET['class_id']) ? $_GET['class_id'] : '';
    $preselected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    
    // Verify class belongs to trainer
    if (!empty($preselected_class)) {
        $class_exists = false;
        foreach ($batches as $batch) {
            if ($batch['batch_id'] . '|' . $batch['course_id'] === $preselected_class) {
                $class_exists = true;
                break;
            }
        }
        if (!$class_exists) $preselected_class = '';
    }

} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error_message'] = "Database error occurred. Please try again later.";
    $batches = [];
    $trainer = ['id' => 0, 'name' => $_SESSION['user_name'] ?? 'Trainer'];
}

// UI summary helpers for the enhanced dashboard
$ongoing_batches_count = 0;
$upcoming_batches_count = 0;
$total_roster_students = 0;
$total_capacity = 0;
foreach ($batches as $ui_batch) {
    if (($ui_batch['status'] ?? '') === 'ongoing') $ongoing_batches_count++;
    if (($ui_batch['status'] ?? '') === 'upcoming') $upcoming_batches_count++;
    $total_roster_students += (int)($ui_batch['student_count'] ?? 0);
    $total_capacity += (int)($ui_batch['max_students'] ?? 0);
}
$roster_fill_rate = $total_capacity > 0 ? round(($total_roster_students / $total_capacity) * 100) : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Attendance Tracking | Trainer Panel | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'sans-serif'],
                    },
                    boxShadow: {
                        glow: '0 18px 45px rgba(35,76,106, 0.22)',
                        soft: '0 18px 45px rgba(15, 23, 42, 0.08)',
                        card: '0 10px 30px rgba(15, 23, 42, 0.07)'
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');

        :root {
            --grad-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 43%, #456882 100%);
            --grad-blue: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --grad-green: linear-gradient(135deg, #10b981 0%, #14b8a6 100%);
            --grad-orange: linear-gradient(135deg, #f97316 0%, #f59e0b 100%);
            --ink: #172033;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(circle at 18% 8%, rgba(35,76,106, 0.18), transparent 28%),
                radial-gradient(circle at 90% 12%, rgba(69,104,130, 0.16), transparent 25%),
                linear-gradient(145deg, #f8fbff 0%, #eef4ff 48%, #f7f0ff 100%);
            color: var(--ink);
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(35,76,106, 0.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(35,76,106, 0.045) 1px, transparent 1px);
            background-size: 46px 46px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.9), rgba(0,0,0,.25));
            z-index: -2;
        }

        .page-shell { position: relative; }
        .page-shell::after {
            content: '';
            position: fixed;
            right: -180px;
            bottom: -220px;
            width: 520px;
            height: 520px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(35,76,106, 0.18), transparent 68%);
            z-index: -1;
            filter: blur(8px);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.90);
            border: 1px solid rgba(226, 232, 240, 0.90);
            border-radius: 24px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.075);
            backdrop-filter: blur(18px);
        }

        .gradient-text {
            background: var(--grad-main);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hero-card {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            background: var(--grad-main);
            color: white;
            box-shadow: 0 22px 50px rgba(35,76,106, 0.28);
        }
        .hero-card::before {
            content: '';
            position: absolute;
            inset: -90px -120px auto auto;
            width: 360px;
            height: 360px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
        }
        .hero-card::after {
            content: '';
            position: absolute;
            right: 9%;
            bottom: -95px;
            width: 260px;
            height: 260px;
            border-radius: 999px;
            background: rgba(255,255,255,0.10);
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .48rem .75rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, .15);
            border: 1px solid rgba(255,255,255,.22);
            font-weight: 800;
            font-size: .72rem;
            letter-spacing: .02em;
            backdrop-filter: blur(12px);
        }

        .hero-mini-card {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.24);
            border-radius: 18px;
            padding: 1rem;
            backdrop-filter: blur(14px);
        }

        .metric-card {
            position: relative;
            background: rgba(255,255,255,.94);
            border: 1px solid rgba(226,232,240,.95);
            border-radius: 20px;
            padding: 1rem 1.1rem;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .06);
            overflow: hidden;
            transition: .22s ease;
        }
        .metric-card:hover { transform: translateY(-3px); box-shadow: 0 18px 38px rgba(15, 23, 42, .10); }
        .metric-card::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--grad-main);
        }
        .metric-icon {
            width: 42px;
            height: 42px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            color: white;
            box-shadow: 0 12px 24px rgba(35,76,106,.20);
        }

        .control-input {
            width: 100%;
            border: 1px solid #e2e8f0;
            background: rgba(248,250,252,.95);
            color: #172033;
            border-radius: 16px;
            padding: .85rem 1rem;
            transition: .2s ease;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.6);
        }
        .control-input:focus {
            outline: none;
            border-color: #234C6A;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, .14);
            background: white;
        }

        .action-btn {
            border-radius: 16px;
            padding: .9rem 1rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .55rem;
            transition: all .22s ease;
            min-height: 48px;
        }
        .action-btn:hover { transform: translateY(-2px); filter: brightness(1.02); }
        .btn-green { background: var(--grad-green); color: white; box-shadow: 0 14px 26px rgba(16,185,129,.18); }
        .btn-purple { background: var(--grad-main); color: white; box-shadow: 0 14px 26px rgba(35,76,106,.22); }
        .btn-blue { background: var(--grad-blue); color: white; box-shadow: 0 14px 26px rgba(35,76,106,.18); }
        .btn-soft { background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.24); color: white; }

        .summary-card {
            position: relative;
            overflow: hidden;
            background: white;
            border-radius: 22px;
            border: 1px solid #EEF3F6;
            padding: 1.05rem 1.15rem;
            box-shadow: 0 12px 28px rgba(15,23,42,.065);
        }
        .summary-card::after {
            content: '';
            position: absolute;
            right: -28px;
            top: -28px;
            width: 82px;
            height: 82px;
            border-radius: 999px;
            background: rgba(35,76,106,.08);
        }

        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .data-table thead th {
            background: linear-gradient(90deg, #f8fbff, #f4f7ff);
            padding: 1rem 1.1rem;
            font-size: .73rem;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .data-table tbody td {
            padding: .95rem 1.1rem;
            border-bottom: 1px solid #f1f5f9;
            color: #172033;
            font-size: .88rem;
            vertical-align: middle;
        }
        .data-table tbody tr:hover td { background: rgba(139, 92, 246, .045); }

        .table-card-top { background: linear-gradient(90deg, rgba(255,255,255,.94), rgba(247,242,255,.92)); }

        .badge-batch {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: linear-gradient(135deg, #F6F1ED 0%, #ede9fe 100%);
            color: #6d28d9;
            padding: .35rem .75rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 900;
            border: 1px solid #ddd6fe;
            white-space: nowrap;
        }

        .switch { position: relative; display: inline-block; width: 58px; height: 32px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #f43f5e;
            transition: .25s ease;
            border-radius: 999px;
            box-shadow: inset 0 2px 4px rgba(15,23,42,.12);
        }
        .slider:before {
            position: absolute;
            content: '';
            height: 26px;
            width: 26px;
            left: 3px;
            top: 3px;
            background: #fff;
            transition: .25s ease;
            border-radius: 999px;
            box-shadow: 0 4px 10px rgba(15,23,42,.16);
        }
        input:checked + .slider { background: #10b981; }
        input:checked + .slider:before { transform: translateX(26px); }
        .camera-slider input:checked + .slider { background: #234C6A; }
        .camera-slider.disabled { opacity: .52; cursor: not-allowed; }
        .camera-slider.disabled .slider { cursor: not-allowed; background: #cbd5e1; }
        .status-label {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            font-weight: 1000;
            color: white;
            pointer-events: none;
            transition: opacity .2s;
        }
        .status-present-label { left: 10px; opacity: 0; }
        .status-absent-label { right: 10px; opacity: 1; }
        input:checked + .slider .status-present-label { opacity: 1; }
        input:checked + .slider .status-absent-label { opacity: 0; }

        .toast-notification {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1000;
            animation: slideUp .28s ease;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .spinner { width: 44px; height: 44px; border: 4px solid #ede9fe; border-top-color: #234C6A; border-radius: 999px; animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .empty-state {
            background: linear-gradient(135deg, rgba(248,250,252,.95), rgba(245,243,255,.95));
        }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #EEF3F6; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #234C6A, #456882); border-radius: 999px; }


        /* ===== Visual-only polish: highlighted section/card theme, no new workflow ===== */
        .feature-shell {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(255,255,255,.94), rgba(248,250,255,.97));
            border: 1px solid rgba(226,232,240,.92);
            box-shadow: 0 18px 42px rgba(15,23,42,.075);
        }

        .feature-shell::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--grad-main));
        }

        .feature-shell::after {
            content: '';
            position: absolute;
            width: 210px;
            height: 210px;
            right: -70px;
            top: -70px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(10px);
            opacity: .8;
            pointer-events: none;
        }

        .feature-shell > * { position: relative; z-index: 1; }

        .feature-control {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.16), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-summary {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.16), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-records {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(59,130,246,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .36rem .72rem;
            border-radius: 999px;
            margin-bottom: .8rem;
            background: rgba(255,255,255,.82);
            border: 1px solid rgba(226,232,240,.9);
            color: #1B3C53;
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            box-shadow: 0 6px 18px rgba(15,23,42,.05);
        }

        .attendance-row td {
            background: rgba(255,255,255,.88);
        }

        .attendance-row {
            position: relative;
            overflow: hidden;
        }

        .attendance-row:hover td {
            background: linear-gradient(90deg, rgba(245,243,255,.95), rgba(255,241,248,.88));
        }

        .attendance-row td:first-child {
            border-left: 4px solid rgba(139,92,246,.45);
        }

        .table-action-footer {
            background: linear-gradient(90deg, rgba(255,255,255,.92), rgba(248,250,255,.92));
        }

        .summary-card {
            transition: all .22s ease;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 38px rgba(15,23,42,.10);
        }

        @media (max-width: 768px) {
            .hero-card { border-radius: 22px; }
            .glass-card { border-radius: 20px; }
            .switch { width: 50px; height: 28px; }
            .slider:before { height: 22px; width: 22px; }
            input:checked + .slider:before { transform: translateX(22px); }
            .toast-notification { right: 1rem; bottom: 1rem; left: 1rem; }
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
<style>

/* ===== Company Source Safe UI Patch: Attendance page approved theme ===== */
/* CSS-only patch. PHP queries, API calls, fetch URLs, IDs, form fields, JS handlers and DB logic untouched. */

/* Same clean page background */
body {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,83,.10), transparent 28%),
        radial-gradient(circle at 88% 4%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 46%, #FBFAF8 100%) !important;
    color: #1B3C53 !important;
}

/* Header theme */
header.sticky,
.lg\:hidden.sticky {
    background: rgba(255,253,250,.86) !important;
    backdrop-filter: blur(18px) !important;
    border-bottom: 1px solid rgba(210,193,182,.56) !important;
    box-shadow: 0 12px 34px rgba(27,60,83,.08) !important;
}

header.sticky h1,
header.sticky p,
header.sticky span {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

header.sticky .h-11,
header.sticky .w-10,
.lg\:hidden.sticky .w-10 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.35), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    box-shadow: 0 12px 26px rgba(27,60,83,.18) !important;
}

/* Generic glass panels */
.glass-card,
.feature-shell {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.09), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.55px solid rgba(210,193,182,.66) !important;
    border-radius: 24px !important;
    box-shadow:
        0 18px 44px rgba(27,60,83,.11),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    backdrop-filter: blur(16px) !important;
}

.feature-shell {
    position: relative !important;
    overflow: hidden !important;
    border-radius: 26px !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.feature-shell::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 0 auto 0 !important;
    height: 5px !important;
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882) !important;
    z-index: 1 !important;
}

.feature-shell::after {
    content: "" !important;
    position: absolute !important;
    width: 190px !important;
    height: 190px !important;
    right: -60px !important;
    top: -60px !important;
    border-radius: 999px !important;
    background: radial-gradient(circle, rgba(69,104,130,.13), rgba(210,193,182,.08) 58%, transparent 72%) !important;
    filter: blur(7px) !important;
    pointer-events: none !important;
}

.feature-shell > * {
    position: relative !important;
    z-index: 2 !important;
}

.feature-shell:hover {
    border-color: rgba(35,76,106,.42) !important;
    box-shadow:
        0 26px 58px rgba(27,60,83,.16),
        inset 0 1px 0 rgba(255,255,255,.90) !important;
}

/* Hero card: same navy approved banner */
.hero-card {
    background:
        radial-gradient(circle at 92% 20%, rgba(255,255,255,.18), transparent 26%),
        radial-gradient(circle at 70% 110%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    border: 1.6px solid rgba(255,255,255,.24) !important;
    border-radius: 28px !important;
    box-shadow:
        0 24px 64px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
}

.hero-card h2,
.hero-card p,
.hero-card span,
.hero-card i,
.hero-card .hero-pill,
.hero-card .hero-mini-card {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 7px rgba(0,0,0,.16) !important;
}

.hero-pill,
.hero-mini-card {
    background: rgba(255,255,255,.16) !important;
    border: 1.4px solid rgba(255,255,255,.32) !important;
    box-shadow:
        0 10px 22px rgba(15,23,42,.12),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    backdrop-filter: blur(12px) !important;
}

/* Workspace metrics: green, purple, blue, orange exactly like approved look */
main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card {
    position: relative !important;
    overflow: hidden !important;
    min-height: 88px !important;
    color: #ffffff !important;
    border-radius: 18px !important;
    border: 1.5px solid rgba(255,255,255,.38) !important;
    box-shadow:
        0 18px 38px rgba(27,60,83,.16),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    height: auto !important;
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.20), transparent 34%),
        radial-gradient(circle at 4% 100%, rgba(255,255,255,.10), transparent 32%) !important;
    pointer-events: none !important;
}

main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card::after {
    content: "" !important;
    position: absolute !important;
    right: -34px !important;
    top: -42px !important;
    width: 112px !important;
    height: 112px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.14) !important;
    pointer-events: none !important;
}

main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card > * {
    position: relative !important;
    z-index: 2 !important;
}

/* Ongoing = green */
main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card:nth-child(1) {
    background: linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%) !important;
}

/* Upcoming = purple */
main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card:nth-child(2) {
    background: linear-gradient(135deg, #6d28d9 0%, #7c3aed 54%, #a78bfa 100%) !important;
}

/* Total Roster = blue */
main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card:nth-child(3) {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 54%, #60a5fa 100%) !important;
}

/* Capacity = orange */
main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card:nth-child(4) {
    background: linear-gradient(135deg, #b45309 0%, #d97706 54%, #f59e0b 100%) !important;
}

main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card:hover {
    transform: translateY(-4px) !important;
    filter: brightness(1.06) !important;
    box-shadow:
        0 26px 55px rgba(27,60,83,.24),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card p,
main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card span,
main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.16) !important;
}

main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card .metric-icon {
    width: 46px !important;
    height: 46px !important;
    min-width: 46px !important;
    min-height: 46px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.4px solid rgba(255,255,255,.46) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow:
        0 12px 24px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.25) !important;
    transition: transform .22s ease, box-shadow .22s ease !important;
}

main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card:hover .metric-icon {
    transform: translateY(-2px) scale(1.10) rotate(3deg) !important;
    box-shadow:
        0 16px 34px rgba(0,0,0,.25),
        0 0 0 8px rgba(255,255,255,.14),
        inset 0 1px 0 rgba(255,255,255,.30) !important;
}

/* Section labels / chips */
.section-kicker {
    background:
        linear-gradient(135deg, rgba(255,253,250,.96), rgba(238,243,246,.90)) !important;
    border: 1.3px solid rgba(210,193,182,.72) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    box-shadow: 0 8px 20px rgba(27,60,83,.08) !important;
}

/* Roster Control and Daily Records shades */
.feature-control,
.feature-records,
.feature-summary {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.08), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
}

/* Control form inputs */
.control-input {
    background: rgba(255,255,255,.96) !important;
    border: 1.35px solid rgba(69,104,130,.28) !important;
    color: #102A3A !important;
    -webkit-text-fill-color: #102A3A !important;
    border-radius: 15px !important;
    font-weight: 800 !important;
    box-shadow:
        0 8px 20px rgba(27,60,83,.045),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
}

.control-input:focus {
    border-color: #234C6A !important;
    box-shadow:
        0 0 0 4px rgba(35,76,106,.13),
        0 12px 24px rgba(27,60,83,.09) !important;
}

/* Action buttons */
.btn-purple,
.btn-blue {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.20) !important;
}

.btn-green {
    background: linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow: 0 14px 28px rgba(5,150,105,.20) !important;
}

.action-btn:hover {
    transform: translateY(-3px) !important;
    filter: brightness(1.06) !important;
}

/* Attendance summary cards after loading */
#attendanceSummary .summary-card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.07), transparent 34%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.35px solid rgba(210,193,182,.66) !important;
    box-shadow: 0 12px 28px rgba(27,60,83,.075) !important;
}

#attendanceSummary .summary-card:hover {
    transform: translateY(-3px) !important;
    border-color: rgba(35,76,106,.40) !important;
    box-shadow: 0 20px 40px rgba(27,60,83,.13) !important;
}

#attendanceSummary .summary-card i {
    width: 42px !important;
    height: 42px !important;
    border-radius: 999px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.42), transparent 34%),
        rgba(35,76,106,.10) !important;
    box-shadow: 0 10px 22px rgba(27,60,83,.10) !important;
}

/* Attendance table */
.table-card-top {
    background:
        linear-gradient(135deg, rgba(238,243,246,.95), rgba(246,241,237,.88)) !important;
    border-bottom: 1px solid rgba(210,193,182,.60) !important;
}

.data-table thead th {
    background:
        linear-gradient(135deg, rgba(238,243,246,.94), rgba(210,193,182,.30)) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

.data-table tbody td {
    color: #1B3C53 !important;
}

.data-table tbody tr:hover td,
.attendance-row:hover td {
    background:
        linear-gradient(90deg, rgba(238,243,246,.92), rgba(246,241,237,.82)) !important;
}

.attendance-row td:first-child {
    border-left: 4px solid rgba(35,76,106,.54) !important;
}

/* Empty state */
.empty-state {
    background:
        radial-gradient(circle at 50% 0%, rgba(69,104,130,.09), transparent 40%),
        linear-gradient(135deg, rgba(255,253,250,.97), rgba(238,243,246,.88)) !important;
}

.empty-state .w-20 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.54), transparent 36%),
        linear-gradient(135deg, #EEF3F6, #F6F1ED) !important;
    color: #1B3C53 !important;
    border: 1.3px solid rgba(210,193,182,.68) !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.10) !important;
}

.empty-state i {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

/* Switches still work, just polished visually */
.switch .slider {
    box-shadow:
        inset 0 2px 4px rgba(15,23,42,.14),
        0 8px 18px rgba(27,60,83,.10) !important;
}

input:checked + .slider {
    background: linear-gradient(135deg, #047857, #10b981) !important;
}

.camera-slider input:checked + .slider {
    background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
}

/* Batch badge */
.badge-batch {
    background:
        linear-gradient(135deg, rgba(238,243,246,.94), rgba(210,193,182,.30)) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    border-color: rgba(210,193,182,.66) !important;
}

/* Footer */
footer {
    background: rgba(255,253,250,.56) !important;
    border-top: 1px solid rgba(210,193,182,.50) !important;
}

@media (max-width: 768px) {
    .hero-card,
    .feature-shell,
    .glass-card {
        border-radius: 20px !important;
    }

    main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card {
        min-height: 82px !important;
    }

    main > section.grid.grid-cols-2.lg\:grid-cols-4 > .metric-card .metric-icon {
        width: 42px !important;
        height: 42px !important;
        min-width: 42px !important;
        min-height: 42px !important;
    }
}

</style>

<style>
/* ===== DOM-safe topbar avatar sync ===== */
/* Visual-only patch. It copies the already-working sidebar profile photo into top-right header avatar. */
.topbar-synced-profile-img {
    width: 42px !important;
    height: 42px !important;
    min-width: 42px !important;
    min-height: 42px !important;
    border-radius: 999px !important;
    object-fit: cover !important;
    border: 2px solid rgba(255,255,255,.82) !important;
    box-shadow: 0 10px 22px rgba(27,60,83,.18) !important;
    background: rgba(255,255,255,.22) !important;
    display: block !important;
}

.topbar-synced-profile-img.mobile {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
}
</style>

</head>
<body>
    <?php
    $all_batches = $batches;
    include '../t_sidebar.php';
    ?>

    <div class="page-shell ml-0 lg:ml-64 transition-all duration-300 min-h-screen" id="main-content">
        <!-- Mobile Header -->
        <div class="lg:hidden sticky top-0 z-40 bg-white/90 backdrop-blur-xl border-b border-slate-200 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <button id="mobileSidebarToggle" class="p-2 text-slate-600 hover:bg-slate-100 rounded-xl transition-colors">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-lg font-black gradient-text">Attendance</h1>
                    <p class="text-xs text-slate-500">Track daily class presence</p>
                </div>
            </div>
            <div class="w-10 h-10 bg-gradient-to-br from-violet-500 to-fuchsia-500 rounded-2xl flex items-center justify-center text-white font-black shadow-card">
                <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
            </div>
        </div>

        <!-- Desktop Header -->
        <header class="hidden lg:block bg-white/80 backdrop-blur-xl border-b border-slate-200 sticky top-0 z-40">
            <div class="px-8 py-4 flex justify-between items-center">
                <div class="flex items-center gap-3">
                    <div class="h-11 w-11 rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 flex items-center justify-center text-white shadow-card">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-black gradient-text">Attendance Tracking</h1>
                        <p class="text-slate-500 text-sm">Mark presence, camera status, remarks, and export daily records</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 bg-white border border-slate-200 px-4 py-2 rounded-full shadow-sm">
                    <div class="w-10 h-10 bg-gradient-to-br from-violet-500 to-fuchsia-500 rounded-full flex items-center justify-center text-white font-black">
                        <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                    </div>
                    <div>
                        <p class="font-bold text-slate-800 leading-tight"><?php echo htmlspecialchars($trainer['name']); ?></p>
                        <p class="text-xs text-slate-500">Trainer</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6 lg:p-8">
            <!-- Hero Section -->
            <section class="hero-card p-6 md:p-8 mb-5 md:mb-6">
                <div class="relative z-10 grid grid-cols-1 xl:grid-cols-3 gap-6 items-center">
                    <div class="xl:col-span-2">
                        <div class="flex flex-wrap gap-2 mb-4">
                            <span class="hero-pill"><i class="fas fa-user-check"></i> Attendance Workspace</span>
                            <span class="hero-pill"><i class="fas fa-user"></i> <?php echo htmlspecialchars($trainer['name']); ?></span>
                            <span class="hero-pill"><i class="fas fa-calendar-day"></i> <?php echo date('l, d M Y'); ?></span>
                        </div>
                        <h2 class="text-3xl md:text-4xl font-black tracking-tight mb-3">Daily Attendance Command Center</h2>
                        <p class="text-white/85 max-w-3xl text-sm md:text-base leading-relaxed">
                            Select a batch, load the roster, mark attendance, control camera status, add remarks, and export records from one clean workspace.
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="hero-mini-card">
                            <p class="text-xs uppercase font-black text-white/70">Active Batches</p>
                            <p class="text-3xl font-black mt-1"><?php echo count($batches); ?></p>
                        </div>
                        <div class="hero-mini-card">
                            <p class="text-xs uppercase font-black text-white/70">Linked Students</p>
                            <p class="text-3xl font-black mt-1"><?php echo (int)$total_roster_students; ?></p>
                        </div>
                        <div class="hero-mini-card col-span-2">
                            <div class="flex justify-between items-end gap-3">
                                <div>
                                    <p class="text-xs uppercase font-black text-white/70">Roster Fill</p>
                                    <p class="text-3xl font-black mt-1"><?php echo $roster_fill_rate; ?>%</p>
                                </div>
                                <div class="flex-1 h-2 bg-white/20 rounded-full overflow-hidden mb-2">
                                    <div class="h-full bg-white rounded-full" style="width: <?php echo min(100, $roster_fill_rate); ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Workspace Metrics -->
            <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5 md:mb-6">
                <div class="metric-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[11px] uppercase font-black text-slate-400 tracking-wider">Ongoing</p>
                            <p class="text-2xl font-black text-emerald-600"><?php echo (int)$ongoing_batches_count; ?></p>
                        </div>
                        <div class="metric-icon" style="background: var(--grad-green);"><i class="fas fa-play"></i></div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[11px] uppercase font-black text-slate-400 tracking-wider">Upcoming</p>
                            <p class="text-2xl font-black text-sky-600"><?php echo (int)$upcoming_batches_count; ?></p>
                        </div>
                        <div class="metric-icon" style="background: var(--grad-blue);"><i class="fas fa-clock"></i></div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[11px] uppercase font-black text-slate-400 tracking-wider">Total Roster</p>
                            <p class="text-2xl font-black text-violet-600"><?php echo (int)$total_roster_students; ?></p>
                        </div>
                        <div class="metric-icon" style="background: var(--grad-main);"><i class="fas fa-users"></i></div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[11px] uppercase font-black text-slate-400 tracking-wider">Capacity</p>
                            <p class="text-2xl font-black text-orange-600"><?php echo (int)$total_capacity; ?></p>
                        </div>
                        <div class="metric-icon" style="background: var(--grad-orange);"><i class="fas fa-layer-group"></i></div>
                    </div>
                </div>
            </section>

            <div id="attendanceSection">
                <!-- Filter Card -->
                <section class="glass-card feature-shell feature-control p-5 md:p-6 mb-5 md:mb-6">
                    <div class="section-kicker"><i class="fas fa-sliders-h"></i> Roster Control</div>
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-violet-100 rounded-2xl flex items-center justify-center">
                                <i class="fas fa-sliders-h text-violet-600"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-slate-800">Select Batch & Date</h3>
                                <p class="text-sm text-slate-500">Load roster before saving attendance changes.</p>
                            </div>
                        </div>
                        <div id="batchInfo" class="hidden">
                            <div class="inline-flex items-center gap-2 bg-violet-50 border border-violet-100 px-4 py-2 rounded-full">
                                <i class="fas fa-layer-group text-violet-500 text-sm"></i>
                                <span class="text-sm font-black text-slate-700" id="selectedBatchName"></span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-black uppercase tracking-wider text-slate-500 mb-2">Batch</label>
                            <select id="batchFilter" class="control-input">
                                <option value="">Select a class</option>
                                <?php if (!empty($batches)): ?>
                                    <?php foreach ($batches as $batch): ?>
                                    <?php $class_val = $batch['batch_id'] . '|' . $batch['course_id']; ?>
                                    <option value="<?= htmlspecialchars($class_val) ?>" <?= ($preselected_class === $class_val) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['course_name']) ?> - <?= htmlspecialchars($batch['batch_name']) ?> (<?= htmlspecialchars($batch['batch_id']) ?>)<?= $batch['status'] === 'ongoing' ? ' - Active' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No classes assigned</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase tracking-wider text-slate-500 mb-2">Date</label>
                            <input type="text" id="dateFilter" class="control-input date-picker" value="<?= htmlspecialchars($preselected_date) ?>">
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase tracking-wider text-slate-500 mb-2">Bulk Action</label>
                            <button id="markAllPresent" class="action-btn btn-green w-full">
                                <i class="fas fa-check-double"></i> Mark All Present
                            </button>
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase tracking-wider text-slate-500 mb-2">Roster Action</label>
                            <button id="loadAttendance" class="action-btn btn-purple w-full">
                                <i class="fas fa-sync-alt"></i> Load Attendance
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Attendance Summary -->
                <section id="attendanceSummary" class="feature-shell feature-summary grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5 md:mb-6 hidden p-3 md:p-4">
                    <div class="section-kicker col-span-2 lg:col-span-4"><i class="fas fa-chart-pie"></i> Attendance Snapshot</div>
                    <div class="summary-card">
                        <div class="flex items-center justify-between relative z-10">
                            <div>
                                <p class="text-[11px] font-black text-slate-400 uppercase tracking-wider">Total Students</p>
                                <p class="text-3xl font-black text-slate-800" id="totalStudents">0</p>
                            </div>
                            <i class="fas fa-users text-violet-400 text-xl"></i>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="flex items-center justify-between relative z-10">
                            <div>
                                <p class="text-[11px] font-black text-slate-400 uppercase tracking-wider">Present</p>
                                <p class="text-3xl font-black text-emerald-600" id="presentCount">0</p>
                            </div>
                            <i class="fas fa-check-circle text-emerald-400 text-xl"></i>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="flex items-center justify-between relative z-10">
                            <div>
                                <p class="text-[11px] font-black text-slate-400 uppercase tracking-wider">Absent</p>
                                <p class="text-3xl font-black text-rose-500" id="absentCount">0</p>
                            </div>
                            <i class="fas fa-times-circle text-rose-400 text-xl"></i>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="flex items-center justify-between relative z-10">
                            <div>
                                <p class="text-[11px] font-black text-slate-400 uppercase tracking-wider">Attendance Rate</p>
                                <p class="text-3xl font-black text-blue-600" id="attendancePercentage">0%</p>
                            </div>
                            <i class="fas fa-chart-line text-blue-400 text-xl"></i>
                        </div>
                    </div>
                </section>

                <!-- Attendance Table Card -->
                <section class="glass-card feature-shell feature-records overflow-hidden">
                    <div class="section-kicker mx-5 mt-5"><i class="fas fa-table-list"></i> Daily Records</div>
                    <div class="table-card-top px-5 py-4 border-b border-slate-200 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-indigo-100 rounded-2xl flex items-center justify-center">
                                <i class="fas fa-table-list text-indigo-600"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-slate-800">Attendance Records</h3>
                                <p class="text-sm text-slate-500">Toggle status, update camera, add remarks, then save.</p>
                            </div>
                        </div>
                        <div id="attendanceError" class="hidden text-sm text-red-700 bg-red-50 border border-red-100 px-4 py-2 rounded-xl">
                            <i class="fas fa-exclamation-circle mr-2"></i><span id="errorMessage"></span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Batch</th>
                                    <th class="hidden lg:table-cell">Date</th>
                                    <th>Status</th>
                                    <th>Camera</th>
                                    <th class="hidden md:table-cell">Remarks</th>
                                </tr>
                            </thead>
                            <tbody id="attendanceTableBody">
                                <tr id="noDataRow">
                                    <td colspan="7" class="text-center py-14 empty-state">
                                        <div class="flex flex-col items-center justify-center max-w-md mx-auto">
                                            <div class="w-20 h-20 bg-white rounded-3xl flex items-center justify-center mb-4 shadow-card border border-slate-100">
                                                <i class="fas fa-calendar-check text-violet-400 text-3xl"></i>
                                            </div>
                                            <h4 class="font-black text-slate-800 text-lg mb-1">Attendance roster not loaded</h4>
                                            <p class="text-slate-500 text-sm">Select a batch and date, then click <span class="font-bold text-violet-600">Load Attendance</span>.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-action-footer px-5 py-4 border-t border-slate-200 flex flex-col sm:flex-row justify-end gap-3">
                        <button id="saveAttendance" class="action-btn btn-purple px-5">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <button id="exportAttendanceBtn" class="action-btn btn-green px-5">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </div>
                </section>
            </div>
        </main>

        <footer class="mt-auto py-6 text-center border-t border-slate-200/70">
            <p class="text-slate-400 text-sm">ASD Academy Trainer Portal © <?php echo date('Y'); ?>. All rights reserved.</p>
        </footer>
    </div>

    <div id="loadingOverlay" class="fixed inset-0 bg-slate-950/55 backdrop-blur-sm z-50 hidden items-center justify-center">
        <div class="bg-white rounded-3xl p-7 shadow-2xl flex flex-col items-center border border-slate-100">
            <div class="spinner"></div>
            <p class="text-slate-600 text-sm mt-4 font-semibold">Loading attendance...</p>
        </div>
    </div>

    <div id="toastContainer" class="fixed bottom-6 right-6 z-50"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // DOM Elements
        const loadingOverlay = document.getElementById('loadingOverlay');
        const toastContainer = document.getElementById('toastContainer');
        
        // Show/Hide Loading
        function showLoading() { loadingOverlay.classList.remove('hidden'); loadingOverlay.classList.add('flex'); }
        function hideLoading() { loadingOverlay.classList.add('hidden'); loadingOverlay.classList.remove('flex'); }
        
        // Toast Notification
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            const bgColor = type === 'success' ? 'bg-emerald-500' : type === 'error' ? 'bg-red-500' : 'bg-amber-500';
            const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            toast.className = `toast-notification ${bgColor} text-white px-4 py-3 rounded-xl shadow-lg flex items-center gap-3 min-w-[200px]`;
            toast.innerHTML = `
                <i class="fas ${icon} text-lg"></i>
                <span class="text-sm font-medium">${message}</span>
            `;
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Show Error
        function showError(message) {
            const errorDiv = document.getElementById('attendanceError');
            document.getElementById('errorMessage').textContent = message;
            errorDiv.classList.remove('hidden');
        }
        
        function hideError() {
            document.getElementById('attendanceError').classList.add('hidden');
        }
        
        // Update Camera State
        function updateCameraState(statusToggle, cameraToggle) {
            const isPresent = statusToggle.checked;
            const cameraSlider = cameraToggle.closest('.camera-slider');
            
            if (!isPresent) {
                cameraToggle.checked = false;
                cameraToggle.disabled = true;
                cameraSlider.classList.add('disabled');
            } else {
                cameraToggle.disabled = false;
                cameraSlider.classList.remove('disabled');
            }
        }
        
        // Load Attendance Data
        function loadAttendanceData() {
            const classValue = document.getElementById('batchFilter').value;
            const date = document.getElementById('dateFilter').value;
            
            if (!date) { showError('Please select a date'); return; }
            if (!classValue) { showError('Please select a class'); return; }
            
            const [batchId, courseId] = classValue.split('|');
            
            showLoading();
            hideError();
            
            fetch(`trainer_attendance_api.php?action=fetch&batch_id=${encodeURIComponent(batchId)}&course_id=${encodeURIComponent(courseId)}&date=${encodeURIComponent(date)}`)
                .then(res => res.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        renderAttendanceTable(data.data);
                        updateAttendanceSummary(data.data);
                        updateBatchInfo(classValue);
                        showToast('Attendance records loaded', 'success');
                    } else {
                        showError(data.message || 'Failed to load attendance');
                        if (data.message?.includes('Access denied')) showToast('Access denied to this class', 'error');
                    }
                })
                .catch(err => {
                    hideLoading();
                    console.error(err);
                    showError('Network error occurred');
                });
        }
        
        // Update Summary
        function updateAttendanceSummary(data) {
            const summaryDiv = document.getElementById('attendanceSummary');
            const total = data.length;
            const present = data.filter(r => r.status === 'Present').length;
            const absent = total - present;
            const percent = total > 0 ? Math.round((present / total) * 100) : 0;
            
            document.getElementById('totalStudents').textContent = total;
            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = absent;
            document.getElementById('attendancePercentage').textContent = percent + '%';
            
            summaryDiv.classList.remove('hidden');
        }
        
        function updateAttendanceSummaryFromTable() {
            const rows = document.querySelectorAll('#attendanceTableBody tr:not([colspan])');
            const total = rows.length;
            let present = 0;
            rows.forEach(row => {
                const toggle = row.querySelector('.status-toggle');
                if (toggle && toggle.checked) present++;
            });
            const percent = total > 0 ? Math.round((present / total) * 100) : 0;
            
            document.getElementById('totalStudents').textContent = total;
            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = total - present;
            document.getElementById('attendancePercentage').textContent = percent + '%';
        }
        
        function updateBatchInfo(batchId) {
            const select = document.getElementById('batchFilter');
            const option = select.options[select.selectedIndex];
            document.getElementById('selectedBatchName').textContent = option.text;
            document.getElementById('batchInfo').classList.remove('hidden');
        }
        
        // Escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Render Table
        function renderAttendanceTable(data) {
            const tbody = document.getElementById('attendanceTableBody');
            const noDataRow = document.getElementById('noDataRow');
            if (noDataRow) noDataRow.remove();
            
            if (!data.length) {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center py-14 empty-state"><div class="flex flex-col items-center"><div class="w-20 h-20 bg-white rounded-3xl flex items-center justify-center mb-4 shadow-card border border-slate-100"><i class="fas fa-user-slash text-violet-400 text-3xl"></i></div><h4 class="font-black text-slate-800 text-lg mb-1">No students found</h4><p class="text-slate-500 text-sm">This batch has no roster records for the selected date.</p></div></td></tr>`;
                return;
            }
            
            let html = '';
            data.forEach(row => {
                const isPresent = row.status === 'Present';
                const isCameraOn = row.camera_status === 'On';
                const batchName = row.batch_name || row.current_batch_name || row.batch_id;
                
                html += `
                    <tr class="attendance-row hover:bg-violet-50/70 transition-all">
                        <td><span class="font-mono text-xs text-slate-600 bg-slate-100 px-2 py-1 rounded-lg">${escapeHtml(row.student_id)}</span></td>
                        <td><div class="flex items-center gap-3"><div class="h-9 w-9 rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-xs font-black shadow-sm">${escapeHtml(row.student_name).slice(0,1).toUpperCase()}</div><span class="font-semibold text-slate-800">${escapeHtml(row.student_name)}</span></div></td>
                        <td><span class="badge-batch"><i class="fas fa-layer-group mr-1"></i>${escapeHtml(batchName)}</span></td>
                        <td class="hidden lg:table-cell text-gray-500 text-sm">${row.date}</td>
                        <td>
                            <label class="switch">
                                <input type="checkbox" class="status-toggle" data-id="${row.id}" ${isPresent ? 'checked' : ''}>
                                <span class="slider">
                                    <span class="status-label status-present-label">P</span>
                                    <span class="status-label status-absent-label">A</span>
                                </span>
                            </label>
                        </td>
                        <td>
                            <label class="switch camera-slider ${!isPresent ? 'disabled' : ''}">
                                <input type="checkbox" class="camera-toggle" data-id="${row.id}" ${isCameraOn ? 'checked' : ''} ${!isPresent ? 'disabled' : ''}>
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td class="hidden md:table-cell">
                            <input type="text" class="w-full px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-1 focus:ring-purple-500 focus:border-purple-500" data-id="${row.id}" value="${escapeHtml(row.remarks || '')}" placeholder="Add remarks...">
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
            
            // Add event listeners
            document.querySelectorAll('.status-toggle').forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const row = this.closest('tr');
                    const cameraToggle = row.querySelector('.camera-toggle');
                    updateCameraState(this, cameraToggle);
                    updateAttendanceSummaryFromTable();
                });
                const row = toggle.closest('tr');
                const cameraToggle = row.querySelector('.camera-toggle');
                updateCameraState(toggle, cameraToggle);
            });
        }
        
        // Save Attendance
        function saveAttendance() {
            const changes = [];
            document.querySelectorAll('#attendanceTableBody tr').forEach(row => {
                const statusToggle = row.querySelector('.status-toggle');
                if (!statusToggle) return;
                
                const cameraToggle = row.querySelector('.camera-toggle');
                const remarksInput = row.querySelector('input[type="text"]');
                
                changes.push({
                    id: statusToggle.dataset.id,
                    status: statusToggle.checked ? 'Present' : 'Absent',
                    camera_status: cameraToggle && cameraToggle.checked ? 'On' : 'Off',
                    remarks: remarksInput ? remarksInput.value : ''
                });
            });
            
            if (!changes.length) { showToast('No changes to save', 'warning'); return; }
            
            showLoading();
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('changes', JSON.stringify(changes));
            
            fetch('trainer_attendance_api.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    hideLoading();
                    if (data.success) showToast(data.message, 'success');
                    else showToast(data.message || 'Update failed', 'error');
                })
                .catch(err => {
                    hideLoading();
                    console.error(err);
                    showToast('Network error', 'error');
                });
        }
        
        // Export CSV
        function exportCSV() {
            const rows = document.querySelectorAll('#attendanceTableBody tr');
            if (!rows.length || (rows.length === 1 && rows[0].querySelector('td[colspan]'))) {
                showToast('No data to export', 'warning');
                return;
            }
            
            let csv = 'Student ID,Student Name,Batch,Date,Status,Camera Status,Remarks\n';
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length < 6) return;
                
                const studentId = cells[0]?.textContent?.trim() || '';
                const studentName = cells[1]?.textContent?.trim() || '';
                const batch = cells[2]?.textContent?.trim() || '';
                const date = cells[3]?.textContent?.trim() || '';
                const statusToggle = row.querySelector('.status-toggle');
                const cameraToggle = row.querySelector('.camera-toggle');
                const remarksInput = row.querySelector('input[type="text"]');
                
                const status = statusToggle?.checked ? 'Present' : 'Absent';
                const camera = cameraToggle?.checked ? 'On' : 'Off';
                const remarks = (remarksInput?.value || '').replace(/,/g, ';');
                
                csv += `"${studentId}","${studentName}","${batch}","${date}","${status}","${camera}","${remarks}"\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `attendance_${document.getElementById('batchFilter').value}_${document.getElementById('dateFilter').value}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            showToast('Export completed', 'success');
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar
            const mobileToggle = document.getElementById('mobileSidebarToggle');
            const sidebar = document.querySelector('aside');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('-translate-x-full');
                    document.body.classList.toggle('overflow-hidden');
                });
            }
            
            // Date picker
            flatpickr(".date-picker", {
                dateFormat: "Y-m-d",
                maxDate: "today",
                defaultDate: "<?= $preselected_date ?>"
            });
            
            // Event listeners
            document.getElementById('loadAttendance').addEventListener('click', loadAttendanceData);
            document.getElementById('saveAttendance').addEventListener('click', saveAttendance);
            document.getElementById('exportAttendanceBtn').addEventListener('click', exportCSV);
            document.getElementById('markAllPresent').addEventListener('click', () => {
                document.querySelectorAll('.status-toggle').forEach(toggle => {
                    toggle.checked = true;
                    const row = toggle.closest('tr');
                    const cameraToggle = row.querySelector('.camera-toggle');
                    updateCameraState(toggle, cameraToggle);
                    if (cameraToggle) cameraToggle.checked = true;
                });
                updateAttendanceSummaryFromTable();
                showToast('All students marked present', 'success');
            });
            
            // Auto-load if preselected
            if (document.getElementById('dateFilter').value && document.getElementById('batchFilter').value) {
                setTimeout(loadAttendanceData, 300);
            }
        });
    </script>

<script>
/* ===== Topbar avatar DOM sync =====
   PHP untouched. Sidebar profile image is already working, so we reuse that src
   instead of poking the database again like a bored raccoon. */
(function () {
    function getSidebarProfileSrc() {
        const img = document.querySelector('.sidebar-profile-photo');
        if (!img) return '';
        const src = img.getAttribute('src') || '';
        if (!src || img.style.display === 'none') return '';
        return src;
    }

    function replaceBoxWithImage(box, src, isMobile) {
        if (!box || !src) return;
        if (box.closest('#trainer-notif-container')) return;

        const existingImg = box.querySelector('img.topbar-synced-profile-img');
        if (existingImg) {
            existingImg.src = src;
            return;
        }

        box.innerHTML = '';
        box.className = '';
        box.style.cssText = '';

        const img = document.createElement('img');
        img.src = src;
        img.alt = 'Profile Picture';
        img.className = 'topbar-synced-profile-img' + (isMobile ? ' mobile' : '');
        img.onerror = function () {
            this.remove();
        };
        box.appendChild(img);
    }

    function syncTopbarAvatar() {
        const src = getSidebarProfileSrc();
        if (!src) return false;

        // Desktop profile pill: right side with trainer name + Trainer role
        document.querySelectorAll('header .flex.items-center.gap-3, header .flex.items-center.gap-2, header .flex.items-center.space-x-2').forEach(function (profileWrap) {
            const text = profileWrap.textContent || '';
            if (!text.includes('Trainer')) return;

            const avatarBox = profileWrap.querySelector('div:first-child');
            replaceBoxWithImage(avatarBox, src, false);
        });

        // Mobile header: hardcoded rounded icon on right
        document.querySelectorAll('.lg\\:hidden div').forEach(function (box) {
            const txt = (box.textContent || '').trim();
            const cls = box.className || '';
            if (
                txt.length <= 2 &&
                cls.includes('rounded') &&
                (cls.includes('font-black') || cls.includes('font-bold')) &&
                !box.querySelector('i') &&
                !box.closest('#trainer-notif-container')
            ) {
                replaceBoxWithImage(box, src, true);
            }
        });

        // Extra fallback: any header circular initial box
        document.querySelectorAll('header div').forEach(function (box) {
            const txt = (box.textContent || '').trim();
            const cls = box.className || '';
            if (
                txt.length <= 2 &&
                cls.includes('rounded') &&
                (cls.includes('font-black') || cls.includes('font-bold')) &&
                !box.querySelector('i') &&
                !box.closest('#trainer-notif-container')
            ) {
                replaceBoxWithImage(box, src, false);
            }
        });

        return true;
    }

    function runWithRetries() {
        let tries = 0;
        const timer = setInterval(function () {
            tries++;
            const done = syncTopbarAvatar();
            if (done || tries >= 20) {
                clearInterval(timer);
            }
        }, 150);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runWithRetries);
    } else {
        runWithRetries();
    }

    window.addEventListener('load', syncTopbarAvatar);
    window.addEventListener('pageshow', syncTopbarAvatar);
})();
</script>

</body>
</html>