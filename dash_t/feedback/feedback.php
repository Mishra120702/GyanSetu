<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../logout.php");
    exit;
}

// Get current trainer info
$trainer_user_id = $_SESSION['user_id'];
$trainer_stmt = $db->prepare("SELECT t.*, t.id as trainer_id FROM trainers t JOIN users u ON t.user_id = u.id WHERE u.id = ?");
$trainer_stmt->execute([$trainer_user_id]);
$trainer = $trainer_stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    header("Location: ../logout.php");
    exit;
}

$trainer_id = $trainer['trainer_id']; // This is the ID in the trainers table

// Robust trainer matching:
// Some tables store trainer reference as trainers.id, while older data can store users.id.
// This keeps feedback visible for the logged-in trainer without exposing other trainers' data.
$trainer_match_ids = array_values(array_unique(array_filter([
    (int)$trainer_id,
    (int)$trainer_user_id
])));

$trainer_placeholders = implode(',', array_fill(0, count($trainer_match_ids), '?'));

// Get all batches assigned to this trainer using both possible IDs.
$assigned_batch_stmt = $db->prepare("
    SELECT DISTINCT b.batch_id 
    FROM batches b 
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
    WHERE b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders)
");
$assigned_batch_stmt->execute(array_merge($trainer_match_ids, $trainer_match_ids));
$assigned_batch_ids = $assigned_batch_stmt->fetchAll(PDO::FETCH_COLUMN);

$batch_placeholders = !empty($assigned_batch_ids)
    ? implode(',', array_fill(0, count($assigned_batch_ids), '?'))
    : "''";

// Determine active tab
$active_tab = $_GET['tab'] ?? 'student_feedback';

// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$offset = ($page - 1) * $per_page;

// ==================== STUDENT FEEDBACK ====================
// Show feedback shared with trainer for the trainer's assigned batches.
// Uses assigned batch IDs to avoid trainers.id vs users.id mismatch issues.
$student_feedback_params = $assigned_batch_ids;

$student_feedback_query = "
    SELECT 
        f.*, 
        COALESCE(b.batch_name, f.batch_id) as actual_batch_name
    FROM feedback f
    LEFT JOIN batches b ON f.batch_id = b.batch_id
    WHERE f.show_to_trainer = 1
      AND f.batch_id IN ($batch_placeholders)
";

$count_query = "
    SELECT COUNT(*) as total
    FROM feedback f
    WHERE f.show_to_trainer = 1
      AND f.batch_id IN ($batch_placeholders)
";

$count_stmt = $db->prepare($count_query);
$count_stmt->execute($student_feedback_params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

$student_feedback_query .= " ORDER BY f.date DESC LIMIT $offset, $per_page";
$stmt = $db->prepare($student_feedback_query);
$stmt->execute($student_feedback_params);
$student_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

// DEBUG LOGGING
file_put_contents(__DIR__ . '/debug_feedback.log', date('Y-m-d H:i:s') . "\nTrainer Match IDs: " . json_encode($trainer_match_ids) . "\nAssigned Batch IDs: " . json_encode($assigned_batch_ids) . "\nStudent Feedback Params: " . json_encode($student_feedback_params) . "\nStudent Feedback Query: " . $student_feedback_query . "\nStudent Feedback Results Count: " . count($student_feedback) . "\n\n", FILE_APPEND);

$summary_query = "
    SELECT 
        COUNT(*) as total_feedback,
        AVG(f.class_rating) as avg_class_rating,
        AVG(f.assignment_understanding) as avg_assignment_rating,
        AVG(f.practical_understanding) as avg_practical_rating,
        SUM(CASE WHEN f.satisfied = 1 THEN 1 ELSE 0 END) as satisfied_count
    FROM feedback f
    WHERE f.show_to_trainer = 1
      AND f.batch_id IN ($batch_placeholders)
";
$summary = $db->prepare($summary_query);
$summary->execute($student_feedback_params);
$summary_data = $summary->fetch(PDO::FETCH_ASSOC);

// ==================== WEEKLY FEEDBACK ====================
// Weekly feedback can reference either trainers.id or users.id in older data.
// Keep it scoped to this logged-in trainer.
$weekly_page = isset($_GET['weekly_page']) ? (int)$_GET['weekly_page'] : 1;
$weekly_per_page = isset($_GET['weekly_per_page']) ? (int)$_GET['weekly_per_page'] : 20;
$weekly_offset = ($weekly_page - 1) * $weekly_per_page;

$weekly_feedback_query = "
    SELECT wf.*, 
        b.batch_name,
        CONCAT(s.first_name, ' ', s.last_name) as student_full_name,
        DATE_FORMAT(wf.week_start_date, '%Y-%m-%d') as week_start,
        DATE_FORMAT(wf.week_end_date, '%Y-%m-%d') as week_end,
        WEEK(wf.week_start_date) as week_number,
        YEAR(wf.week_start_date) as year
    FROM weekly_feedback wf
    LEFT JOIN batches b ON wf.batch_id = b.batch_id
    LEFT JOIN students s ON wf.student_id = s.student_id
    WHERE wf.trainer_id IN ($trainer_placeholders)
";

$weekly_count_query = "
    SELECT COUNT(*) as total 
    FROM weekly_feedback wf 
    WHERE wf.trainer_id IN ($trainer_placeholders)
";

$weekly_count_stmt = $db->prepare($weekly_count_query);
$weekly_count_stmt->execute($trainer_match_ids);
$weekly_total_records = $weekly_count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$weekly_total_pages = ceil($weekly_total_records / $weekly_per_page);

$weekly_feedback_query .= " ORDER BY wf.week_start_date DESC LIMIT $weekly_offset, $weekly_per_page";
$stmt = $db->prepare($weekly_feedback_query);
$stmt->execute($trainer_match_ids);
$weekly_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

$weekly_summary = $db->prepare(" 
    SELECT 
        COUNT(*) as total_weekly_feedback,
        AVG(rating) as avg_weekly_rating
    FROM weekly_feedback
    WHERE trainer_id IN ($trainer_placeholders)
");
$weekly_summary->execute($trainer_match_ids);
$weekly_summary_data = $weekly_summary->fetch(PDO::FETCH_ASSOC);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainer Feedback Portal | ASD Academy</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
        }
        
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .tab-nav {
            display: flex;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }
        
        .tab-button {
            flex: 1;
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .tab-button.active {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(27,60,83, 0.3);
        }
        
        .tab-button .badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead th {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            color: white;
            font-weight: 600;
            padding: 1rem;
            text-align: left;
        }
        
        .data-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        
        .data-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .data-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }

        .rating-5 { color: #10b981; }
        .rating-4 { color: #34d399; }
        .rating-3 { color: #f59e0b; }
        .rating-2 { color: #f97316; }
        .rating-1 { color: #ef4444; }

        .expand-row { cursor: pointer; }
        .row-details { display: none; background: #f9fafb; }
        .row-details.active { display: table-row; }
        .details-content { padding: 24px; background: white; }
        .details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
        .details-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; }
        .details-section h4 { font-size: 1rem; font-weight: 600; color: #2d3748; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; }
        .detail-item { display: flex; justify-content: space-between; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px dashed #e2e8f0; }
        .detail-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .detail-label { font-weight: 500; color: #4a5568; font-size: 0.875rem; }
        .detail-value { color: #2d3748; font-weight: 600; text-align: right; max-width: 200px; word-break: break-word; }
        .expand-toggle { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; color: #1B3C53; transition: transform 0.3s ease; }
        .expand-toggle.expanded { transform: rotate(180deg); }
    
        /* ===== Same trainer purple/pink dashboard theme ===== */
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

        header.bg-white { display: none !important; }

        .trainer-feedback-hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: clamp(1.2rem, 2.5vw, 1.9rem);
            margin-bottom: 1.5rem;
            color: white;
            background: var(--dash-main);
            box-shadow: 0 24px 58px rgba(27,60,83,.25);
            border: 1px solid rgba(255,255,255,.22);
        }

        .trainer-feedback-hero::before {
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

        .trainer-feedback-hero::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .trainer-feedback-hero > * { position: relative; z-index: 1; }
        .trainer-feedback-hero h1 { color: white !important; font-weight: 900; letter-spacing: -.03em; }
        .trainer-feedback-hero p { color: rgba(255,255,255,.84) !important; font-weight: 600; }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .48rem .76rem;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.25);
            color: white;
            font-size: .75rem;
            font-weight: 900;
            backdrop-filter: blur(12px);
        }

        .glass-card {
            position: relative;
            overflow: hidden;
            background: rgba(255,255,255,.90) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }

        .glass-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--dash-main));
            z-index: 2;
        }

        .glass-card::after {
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

        .glass-card > * { position: relative; z-index: 3; }
        .glass-card:hover { transform: translateY(-3px); box-shadow: 0 22px 48px rgba(15,23,42,.11) !important; }
        .grid .glass-card:nth-child(1) { --feature-accent: var(--dash-blue); --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%); }
        .grid .glass-card:nth-child(2) { --feature-accent: var(--dash-orange); --feature-glow: radial-gradient(circle, rgba(249,115,22,.13), rgba(69,104,130,.05) 60%, transparent 72%); }
        .grid .glass-card:nth-child(3) { --feature-accent: var(--dash-green); --feature-glow: radial-gradient(circle, rgba(16,185,129,.13), rgba(69,104,130,.05) 60%, transparent 72%); }

        .tab-nav {
            background: rgba(255,255,255,.88) !important;
            border: 1px solid rgba(226,232,240,.82);
            border-radius: 22px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }
        .tab-button { border-radius: 17px !important; font-weight: 900 !important; }
        .tab-button.active { background: var(--dash-main) !important; box-shadow: 0 14px 30px rgba(35,76,106,.22) !important; }
        .tab-button .badge { background: rgba(255,255,255,.20) !important; font-weight: 900; }

        .data-table { border-collapse: separate !important; border-spacing: 0; overflow: hidden; }
        .data-table thead th {
            background: linear-gradient(90deg, #EEF3F6, #F6F1ED) !important;
            color: #64748b !important;
            font-size: .72rem;
            font-weight: 900 !important;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid rgba(226,232,240,.9) !important;
        }
        .data-table tbody td { border-color: rgba(226,232,240,.72) !important; }
        .data-table tbody tr:hover { background: linear-gradient(90deg, rgba(245,243,255,.90), rgba(255,241,248,.80)) !important; }

        .avatar { box-shadow: 0 12px 26px rgba(35,76,106,.18); border: 2px solid rgba(255,255,255,.85); }
        .details-content { background: linear-gradient(135deg, rgba(248,250,252,.96), rgba(245,243,255,.90)) !important; }
        .details-section { background: rgba(255,255,255,.86) !important; border: 1px solid rgba(226,232,240,.82) !important; border-radius: 18px !important; box-shadow: 0 12px 26px rgba(15,23,42,.055); }
        .expand-toggle { color: #234C6A !important; }
        .rating-5 { color: #059669 !important; }
        .rating-4 { color: #10b981 !important; }
        .rating-3 { color: #d97706 !important; }
        .rating-2 { color: #f97316 !important; }
        .rating-1 { color: #e11d48 !important; }

        @media (max-width: 768px) {
            .trainer-feedback-hero, .glass-card, .tab-nav { border-radius: 20px !important; }
            .tab-nav { flex-direction: column; }
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

/* ===== FEEDBACK TOP STATS ICONS SAFE PATCH ===== */
/* Only top summary cards/icons polished. PHP, tabs, tables, row expand JS, DB queries untouched. */

/* Keep summary boxes centered like the approved layout */
#student_feedback_tab > .grid,
#weekly_feedback_tab > .grid {
    justify-content: center !important;
    align-items: stretch !important;
}

#student_feedback_tab > .grid {
    max-width: 900px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    grid-template-columns: repeat(3, minmax(230px, 1fr)) !important;
}

#weekly_feedback_tab > .grid {
    max-width: 650px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    grid-template-columns: repeat(2, minmax(230px, 1fr)) !important;
}

/* Top stat cards: same theme, more premium, no layout drama */
#student_feedback_tab > .grid > .glass-card,
#weekly_feedback_tab > .grid > .glass-card {
    min-height: 118px !important;
    padding: 22px 26px !important;
    border-radius: 24px !important;
    background:
        radial-gradient(circle at 96% 8%, rgba(255,255,255,.55), transparent 32%),
        radial-gradient(circle at 7% 95%, rgba(210,193,182,.18), transparent 30%),
        linear-gradient(135deg, rgba(255,255,255,.96), rgba(238,243,246,.86)) !important;
    border: 1.45px solid rgba(210,193,182,.72) !important;
    box-shadow:
        0 18px 42px rgba(27,60,83,.12),
        inset 0 1px 0 rgba(255,255,255,.90) !important;
    overflow: hidden !important;
}

/* Top dark/colored border per card */
#student_feedback_tab > .grid > .glass-card::before,
#weekly_feedback_tab > .grid > .glass-card::before {
    height: 5px !important;
    opacity: 1 !important;
}

/* Card 1: Total Feedback / Total Weekly */
#student_feedback_tab > .grid > .glass-card:nth-child(1)::before,
#weekly_feedback_tab > .grid > .glass-card:nth-child(1)::before {
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882) !important;
}

/* Card 2: Rating */
#student_feedback_tab > .grid > .glass-card:nth-child(2)::before,
#weekly_feedback_tab > .grid > .glass-card:nth-child(2)::before {
    background: linear-gradient(90deg, #b45309, #d97706, #f59e0b) !important;
}

/* Card 3: Satisfaction */
#student_feedback_tab > .grid > .glass-card:nth-child(3)::before {
    background: linear-gradient(90deg, #047857, #059669, #10b981) !important;
}

/* Make the card inner row cleaner */
#student_feedback_tab > .grid > .glass-card > .flex,
#weekly_feedback_tab > .grid > .glass-card > .flex {
    justify-content: flex-start !important;
    gap: 18px !important;
}

/* Icon circles: bigger, brighter, glowing */
#student_feedback_tab > .grid > .glass-card .w-12.h-12,
#weekly_feedback_tab > .grid > .glass-card .w-12.h-12 {
    width: 64px !important;
    height: 64px !important;
    min-width: 64px !important;
    min-height: 64px !important;
    border-radius: 999px !important;
    margin-right: 0 !important;
    border: 1.6px solid rgba(255,255,255,.64) !important;
    box-shadow:
        0 18px 34px rgba(27,60,83,.20),
        0 0 0 10px rgba(255,255,255,.42),
        inset 0 1px 0 rgba(255,255,255,.32) !important;
    position: relative !important;
    overflow: hidden !important;
}

#student_feedback_tab > .grid > .glass-card .w-12.h-12::after,
#weekly_feedback_tab > .grid > .glass-card .w-12.h-12::after {
    content: "" !important;
    position: absolute !important;
    inset: -18px -8px auto auto !important;
    width: 44px !important;
    height: 44px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.22) !important;
}

#student_feedback_tab > .grid > .glass-card .w-12.h-12 i,
#weekly_feedback_tab > .grid > .glass-card .w-12.h-12 i {
    font-size: 24px !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    position: relative !important;
    z-index: 2 !important;
}

/* Exact icon/card colors */
#student_feedback_tab > .grid > .glass-card:nth-child(1) .w-12.h-12,
#weekly_feedback_tab > .grid > .glass-card:nth-child(1) .w-12.h-12 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%) !important;
}

#student_feedback_tab > .grid > .glass-card:nth-child(2) .w-12.h-12,
#weekly_feedback_tab > .grid > .glass-card:nth-child(2) .w-12.h-12 {
    background: linear-gradient(135deg, #b45309 0%, #d97706 52%, #f59e0b 100%) !important;
}

#student_feedback_tab > .grid > .glass-card:nth-child(3) .w-12.h-12 {
    background: linear-gradient(135deg, #047857 0%, #059669 52%, #10b981 100%) !important;
}

/* Text: darker and readable */
#student_feedback_tab > .grid > .glass-card p.text-sm,
#weekly_feedback_tab > .grid > .glass-card p.text-sm {
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
    font-weight: 850 !important;
    letter-spacing: .01em !important;
}

#student_feedback_tab > .grid > .glass-card p.text-2xl,
#weekly_feedback_tab > .grid > .glass-card p.text-2xl {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 950 !important;
    letter-spacing: -.03em !important;
}

/* Hover effect, because apparently cards must also perform interpretive dance */
#student_feedback_tab > .grid > .glass-card:hover,
#weekly_feedback_tab > .grid > .glass-card:hover {
    transform: translateY(-5px) !important;
    box-shadow:
        0 28px 58px rgba(27,60,83,.18),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
}

#student_feedback_tab > .grid > .glass-card:hover .w-12.h-12,
#weekly_feedback_tab > .grid > .glass-card:hover .w-12.h-12 {
    transform: scale(1.08) rotate(-3deg) !important;
}

/* Mobile safe */
@media (max-width: 768px) {
    #student_feedback_tab > .grid,
    #weekly_feedback_tab > .grid {
        max-width: 100% !important;
        grid-template-columns: 1fr !important;
    }

    #student_feedback_tab > .grid > .glass-card .w-12.h-12,
    #weekly_feedback_tab > .grid > .glass-card .w-12.h-12 {
        width: 56px !important;
        height: 56px !important;
        min-width: 56px !important;
        min-height: 56px !important;
    }
}

</style>

</head>
<body>
    
    <!-- Sidebar -->
    <?php include '../t_sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 transition-all duration-300 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-lg sticky top-0 z-40">
            <div class="px-6 py-4 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold" style="background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%); -webkit-background-clip: text; background-clip: text; color: transparent;">
                        Trainer Feedback Portal
                    </h1>
                    <p class="text-gray-600">View student and weekly feedback</p>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6">
            
            <section class="trainer-feedback-hero">
                <h1 class="text-2xl md:text-3xl mb-2">
                    <i class="fas fa-comments mr-2"></i>Trainer Feedback Portal
                </h1>
                <p class="mb-4">View student feedback shared by admin and your weekly feedback records.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="hero-chip"><i class="fas fa-user-graduate"></i><?= $summary_data['total_feedback'] ?? 0 ?> student feedback</span>
                    <span class="hero-chip"><i class="fas fa-calendar-week"></i><?= $weekly_summary_data['total_weekly_feedback'] ?? 0 ?> weekly records</span>
                    <span class="hero-chip"><i class="fas fa-star"></i><?= number_format($summary_data['avg_class_rating'] ?? 0, 1) ?>/5 avg class rating</span>
                </div>
            </section>
            
            <!-- Tab Navigation -->
            <div class="tab-nav">
                <button class="tab-button <?= $active_tab === 'student_feedback' ? 'active' : '' ?>" onclick="switchTab('student_feedback', event)">
                    <i class="fas fa-user-graduate"></i>
                    Student Feedback
                    <span class="badge"><?= $summary_data['total_feedback'] ?? 0 ?></span>
                </button>
                <button class="tab-button <?= $active_tab === 'weekly_feedback' ? 'active' : '' ?>" onclick="switchTab('weekly_feedback', event)">
                    <i class="fas fa-calendar-week"></i>
                    My Weekly Feedback
                    <span class="badge"><?= $weekly_summary_data['total_weekly_feedback'] ?? 0 ?></span>
                </button>
            </div>

            <!-- Student Feedback Tab -->
            <div id="student_feedback_tab" class="tab-content <?= $active_tab === 'student_feedback' ? 'active' : '' ?>">
                <!-- Stats Dashboard -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-comments text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Feedback</p>
                                <p class="text-2xl font-bold text-gray-800"><?= $summary_data['total_feedback'] ?? 0 ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-star text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Avg. Class Rating</p>
                                <p class="text-2xl font-bold text-gray-800"><?= number_format($summary_data['avg_class_rating'] ?? 0, 1) ?>/5.0</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-smile text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Satisfaction Rate</p>
                                <p class="text-2xl font-bold text-gray-800">
                                    <?= $summary_data['total_feedback'] > 0 ? round(($summary_data['satisfied_count'] / $summary_data['total_feedback']) * 100) : 0 ?>%
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Student Feedback Table -->
                <div class="glass-card p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-table mr-2 text-blue-500"></i>
                            Student Feedback Records
                        </h2>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="w-12"></th>
                                    <th>Date</th>
                                    <th>Student</th>
                                    <th>Batch</th>
                                    <th>Course</th>
                                    <th>Class</th>
                                    <th>Assign</th>
                                    <th>Pract</th>
                                    <th>Satis</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (empty($student_feedback)):
                                ?>
                                    <tr><td colspan="9" class="text-center py-8 text-gray-500">No student feedback shared with you yet.</td></tr>
                                <?php 
                                endif;
                                foreach ($student_feedback as $index => $item): 
                                    $initials = '';
                                    if (!empty($item['student_name'])) {
                                        $name_parts = explode(' ', $item['student_name']);
                                        $initials = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : substr($name_parts[0], 1, 1)));
                                    }
                                ?>
                                <!-- Main Row -->
                                <tr class="expand-row" onclick="toggleRowDetails(<?= $item['id']; ?>)">
                                    <td class="w-12">
                                        <div class="expand-toggle" id="expand-icon-<?= $item['id'] ?>">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-sm font-medium"><?= date('M j, Y', strtotime($item['date'])) ?></div>
                                        <div class="text-xs text-gray-500"><?= date('D', strtotime($item['date'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="flex items-center">
                                            <div class="avatar bg-gradient-to-r from-blue-500 to-cyan-500 mr-3">
                                                <?= $initials ?>
                                            </div>
                                            <div>
                                                <div class="font-medium"><?= htmlspecialchars($item['student_name']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><div class="text-sm font-medium text-blue-600"><?= htmlspecialchars($item['actual_batch_name'] ?: $item['batch_id']) ?></div></td>
                                    <td><div class="text-sm font-medium"><?= htmlspecialchars($item['course_name']) ?></div></td>
                                    
                                    <td class="text-center"><span class="font-bold rating-<?= $item['class_rating'] ?>"><?= $item['class_rating'] ?>/5</span></td>
                                    <td class="text-center"><span class="font-bold rating-<?= $item['assignment_understanding'] ?>"><?= $item['assignment_understanding'] ?>/5</span></td>
                                    <td class="text-center"><span class="font-bold rating-<?= $item['practical_understanding'] ?>"><?= $item['practical_understanding'] ?>/5</span></td>
                                    <td class="text-center">
                                        <?php if($item['satisfied']): ?>
                                            <i class="fas fa-check-circle text-green-500 text-lg"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle text-red-500 text-lg"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                
                                <!-- Details Row -->
                                <tr id="details-<?= $item['id'] ?>" class="row-details">
                                    <td colspan="9" class="p-0 border-b-2 border-indigo-200">
                                        <div class="details-content">
                                            <div class="details-grid">
                                                <!-- Text Feedback -->
                                                <div class="details-section col-span-full">
                                                    <h4 class="text-blue-600"><i class="fas fa-comment-alt"></i> Text Feedback</h4>
                                                    
                                                    <?php if(!empty($item['suggestions'])): ?>
                                                    <div class="mb-4">
                                                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Suggestions</div>
                                                        <div class="p-4 bg-white border border-gray-200 rounded-lg text-gray-700 leading-relaxed">
                                                            <?= nl2br(htmlspecialchars($item['suggestions'])) ?>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if(!empty($item['feedback_text'])): ?>
                                                    <div class="mb-4">
                                                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Additional Comments</div>
                                                        <div class="p-4 bg-white border border-gray-200 rounded-lg text-gray-700 leading-relaxed">
                                                            <?= nl2br(htmlspecialchars($item['feedback_text'])) ?>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if(empty($item['suggestions']) && empty($item['feedback_text'])): ?>
                                                    <div class="p-4 bg-gray-50 rounded-lg text-gray-500 text-center italic">
                                                        No text feedback provided.
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Weekly Feedback Tab -->
            <div id="weekly_feedback_tab" class="tab-content <?= $active_tab === 'weekly_feedback' ? 'active' : '' ?>">
                
                <!-- Action Bar -->
                <div class="flex justify-end mb-4">
                    <a href="weekly_feedback.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-6 rounded-lg shadow-md transition-all duration-300 flex items-center gap-2 transform hover:-translate-y-1">
                        <i class="fas fa-plus"></i> Submit New Weekly Feedback
                    </a>
                </div>

                 <!-- Stats Dashboard -->
                 <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-calendar-check text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Weekly Submissions</p>
                                <p class="text-2xl font-bold text-gray-800"><?= $weekly_summary_data['total_weekly_feedback'] ?? 0 ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-star text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Avg. Student Rating Given</p>
                                <p class="text-2xl font-bold text-gray-800"><?= number_format($weekly_summary_data['avg_weekly_rating'] ?? 0, 1) ?>/5.0</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Weekly Feedback Table -->
                <div class="glass-card p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-list mr-2 text-purple-500"></i>
                            Weekly Feedback Records
                        </h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="w-12"></th>
                                    <th>Week Start</th>
                                    <th>Week End</th>
                                    <th>Student</th>
                                    <th>Batch</th>
                                    <th>Rating</th>
                                    <th>Submitted On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (empty($weekly_feedback)):
                                ?>
                                    <tr><td colspan="7" class="text-center py-8 text-gray-500">You haven't submitted any weekly feedback yet.</td></tr>
                                <?php 
                                endif;
                                foreach ($weekly_feedback as $windex => $witem): 
                                    $winitials = '';
                                    if (!empty($witem['student_full_name'])) {
                                        $wname_parts = explode(' ', $witem['student_full_name']);
                                        $winitials = strtoupper(substr($wname_parts[0], 0, 1) . (isset($wname_parts[1]) ? substr($wname_parts[1], 0, 1) : substr($wname_parts[0], 1, 1)));
                                    }
                                ?>
                                <!-- Main Row -->
                                <tr class="expand-row" onclick="toggleWeeklyDetails(<?= $witem['id']; ?>)">
                                    <td class="w-12">
                                        <div class="expand-toggle" id="weekly-expand-icon-<?= $witem['id'] ?>">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-sm font-medium"><?= date('M j, Y', strtotime($witem['week_start'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-sm font-medium"><?= date('M j, Y', strtotime($witem['week_end'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="flex items-center">
                                            <div class="avatar bg-gradient-to-r from-purple-500 to-pink-500 mr-3">
                                                <?= $winitials ?>
                                            </div>
                                            <div class="font-medium"><?= htmlspecialchars($witem['student_full_name']) ?></div>
                                        </div>
                                    </td>
                                    <td><div class="text-sm font-medium text-blue-600"><?= htmlspecialchars($witem['batch_name'] ?: $witem['batch_id']) ?></div></td>
                                    <td class="text-center"><span class="font-bold rating-<?= $witem['rating'] ?>"><?= $witem['rating'] ?>/5</span></td>
                                    <td><div class="text-sm text-gray-500"><?= date('M j, Y h:i A', strtotime($witem['submitted_at'])) ?></div></td>
                                </tr>
                                
                                <!-- Details Row -->
                                <tr id="weekly-details-<?= $witem['id'] ?>" class="row-details">
                                    <td colspan="7" class="p-0 border-b-2 border-indigo-200">
                                        <div class="details-content">
                                            <div class="details-section">
                                                <h4 class="text-purple-600"><i class="fas fa-comment-dots"></i> Remarks</h4>
                                                <?php if(!empty($witem['remarks'])): ?>
                                                    <div class="p-4 bg-white border border-gray-200 rounded-lg text-gray-700 leading-relaxed">
                                                        <?= nl2br(htmlspecialchars($witem['remarks'])) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="p-4 bg-gray-50 rounded-lg text-gray-500 text-center italic">
                                                        No remarks provided.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
        function switchTab(tabId, event) {
            // Update buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            } else {
                document.querySelector(`button[onclick*="${tabId}"]`).classList.add('active');
            }
            
            // Update content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabId + '_tab').classList.add('active');
            
            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);
        }

        function toggleRowDetails(id) {
            const detailsRow = document.getElementById('details-' + id);
            const icon = document.getElementById('expand-icon-' + id);
            
            if (detailsRow.classList.contains('active')) {
                detailsRow.classList.remove('active');
                icon.classList.remove('expanded');
            } else {
                detailsRow.classList.add('active');
                icon.classList.add('expanded');
            }
        }

        function toggleWeeklyDetails(id) {
            const detailsRow = document.getElementById('weekly-details-' + id);
            const icon = document.getElementById('weekly-expand-icon-' + id);
            
            if (detailsRow.classList.contains('active')) {
                detailsRow.classList.remove('active');
                icon.classList.remove('expanded');
            } else {
                detailsRow.classList.add('active');
                icon.classList.add('expanded');
            }
        }
        
        // Hide sidebar overlay logic from t_sidebar if needed
        function hideSidebar() {
            document.querySelector('aside').classList.add('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.add('hidden');
        }
        document.getElementById('mobileSidebarToggle')?.addEventListener('click', () => {
            document.querySelector('aside').classList.remove('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.remove('hidden');
        });
    </script>
</body>
</html>
