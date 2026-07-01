<?php
// create_test.php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        $totalMarks = 0;
        if (isset($_POST['questions']) && is_array($_POST['questions'])) {
            foreach ($_POST['questions'] as $i => $qText) {
                if (!empty($qText)) {
                    $totalMarks += intval($_POST['marks'][$i] ?? 0);
                }
            }
        }
        $course_id = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
        $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] === 'specific' ? 'specific' : 'all';
        $selected_student_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? $_POST['student_ids'] : [];
        $test_category = !empty($_POST['test_category']) ? $_POST['test_category'] : null;
        $chapter_id = (!empty($_POST['chapter_id']) && $test_category === 'chapter_wise') ? intval($_POST['chapter_id']) : null;
        $stmt = $db->prepare("
            INSERT INTO tests (title, description, batch_id, subject, total_marks, passing_marks,
                             duration_minutes, max_attempts, start_date, end_date, created_by, course_id, assigned_to, test_category, chapter_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $stmt->execute([
            $_POST['title'], $_POST['description'], $_POST['batch_id'], $_POST['subject'],
            $totalMarks, $_POST['passing_marks'], $_POST['duration_minutes'], $_POST['max_attempts'],
            $startDate, $endDate, $_SESSION['user_id'], $course_id, $assigned_to, $test_category, $chapter_id
        ]);
        $testId = $db->lastInsertId();
        if ($assigned_to === 'specific' && !empty($selected_student_ids)) {
            $studentStmt = $db->prepare("INSERT INTO test_students (test_id, student_id) VALUES (?, ?)");
            foreach ($selected_student_ids as $sid) { $studentStmt->execute([$testId, $sid]); }
        }
        $questionCount = count($_POST['questions']);
        $totalMarksCalculated = 0;
        $questionStmt = $db->prepare("
            INSERT INTO test_questions (test_id, question_text, option_a, option_b, option_c, option_d,
                                       correct_answer, marks, explanation, question_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        for ($i = 0; $i < $questionCount; $i++) {
            if (!empty($_POST['questions'][$i])) {
                $questionStmt->execute([
                    $testId, $_POST['questions'][$i], $_POST['options_a'][$i], $_POST['options_b'][$i],
                    $_POST['options_c'][$i], $_POST['options_d'][$i], $_POST['correct_answers'][$i],
                    $_POST['marks'][$i], $_POST['explanations'][$i] ?? '', $i + 1
                ]);
                $totalMarksCalculated += $_POST['marks'][$i];
            }
        }
        if ($totalMarksCalculated !== $totalMarks) {
            $db->prepare("UPDATE tests SET total_marks = ? WHERE id = ?")->execute([$totalMarksCalculated, $testId]);
        }
        $db->commit();
        $success = "Test created successfully with $questionCount questions!";
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error creating test: " . $e->getMessage();
    }
}

$batchStmt = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name");
$batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
$courseStmt = $db->query("SELECT id, name FROM courses ORDER BY name");
$all_courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create MCQ Test - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }

        /* ── Rich Dark Background ── */
        body {
            background-color: #d4cdc8;
            background-image:
                radial-gradient(ellipse 80% 60% at 0% 0%, rgba(27,60,83,0.25) 0%, transparent 55%),
                radial-gradient(ellipse 60% 50% at 100% 0%, rgba(35,76,106,0.20) 0%, transparent 50%),
                radial-gradient(ellipse 60% 50% at 50% 100%, rgba(27,60,83,0.18) 0%, transparent 55%);
            min-height: 100vh;
        }
        body::before {
            content: '';
            position: fixed; inset: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='1' fill='%231B3C53' opacity='0.08'/%3E%3C/svg%3E");
            pointer-events: none; z-index: -1;
        }

        /* ── Page Header Banner ── */
        .page-header-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 55%, #456882 100%);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(27,60,83,0.30), 0 6px 18px rgba(35,76,106,0.18);
            border: 2px solid rgba(210,193,182,0.35);
            position: relative; overflow: hidden;
        }
        .page-header-banner::before {
            content: ''; position: absolute; inset: 0;
            background-image:
                radial-gradient(circle at 20% 30%, rgba(255,255,255,0.12) 0%, transparent 8%),
                radial-gradient(circle at 80% 70%, rgba(255,255,255,0.10) 0%, transparent 12%),
                radial-gradient(circle at 70% 15%, rgba(255,255,255,0.14) 0%, transparent 14%);
            pointer-events: none;
        }
        .page-header-banner .bubble {
            position: absolute; border-radius: 50%;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.07);
            pointer-events: none;
        }
        .page-header-banner .bubble:nth-child(1){ width:70px; height:70px; top:10%; left:5%; animation: floatBubble 12s infinite ease-in-out; }
        .page-header-banner .bubble:nth-child(2){ width:110px; height:110px; bottom:5%; right:8%; animation: floatBubble 18s infinite ease-in-out reverse; }
        .page-header-banner .bubble:nth-child(3){ width:50px; height:50px; top:55%; left:78%; animation: floatBubble 14s infinite ease-in-out 2s; }
        @keyframes floatBubble {
            0%   { transform: translate(0,0) scale(1); }
            33%  { transform: translate(18px,-28px) scale(1.05); }
            66%  { transform: translate(-10px,18px) scale(0.95); }
            100% { transform: translate(0,0) scale(1); }
        }
        .hero-pill {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 14px; border-radius: 999px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.28);
            color: #f7f5f3; font-size: 0.76rem; font-weight: 700;
            letter-spacing: 0.05em; text-transform: uppercase;
        }

        /* ── Section Cards — Sandy/Warm Tinted ── */
        .glass-card {
            background: #f2eeea;
            border: 1.5px solid rgba(69,104,130,0.20);
            box-shadow: 0 6px 24px rgba(27,60,83,0.09);
            border-radius: 20px;
        }
        .glass-effect {
            background: #f7f4f1;
            border: 1.5px solid rgba(69,104,130,0.18);
            box-shadow: 0 8px 28px rgba(27,60,83,0.08);
            border-radius: 20px;
        }

        /* ── Section Icon Badges ── */
        .section-icon {
            width: 48px; height: 48px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; color: #fff;
            box-shadow: 0 6px 18px rgba(27,60,83,0.22);
            flex-shrink: 0;
        }
        .section-icon-config  { background: linear-gradient(135deg, #1B3C53, #234C6A); }
        .section-icon-csv     { background: linear-gradient(135deg, #234C6A, #456882); }
        .section-icon-qa      { background: linear-gradient(135deg, #1B3C53, #456882); }

        /* ── Form Labels & Inputs ── */
        .form-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 0.78rem; font-weight: 700; color: #234C6A;
            text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 7px;
        }
        .form-input {
            width: 100%; padding: 11px 16px;
            background: #ffffff;
            border: 2px solid rgba(69,104,130,0.28);
            border-radius: 12px; font-size: 0.875rem;
            font-weight: 500; color: #1B3C53;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            outline: none;
        }
        .form-input:focus {
            border-color: #234C6A;
            box-shadow: 0 0 0 4px rgba(35,76,106,0.12);
        }
        .form-input::placeholder { color: #94a3b8; font-weight: 400; }

        /* ── Question Cards — NO HOVER TRANSFORMS ── */
        .question-card {
            background: #eee9e4;
            border: 1.5px solid rgba(69,104,130,0.22);
            border-radius: 18px;
            box-shadow: 0 4px 16px rgba(27,60,83,0.07);
            /* No transform on hover, no ::before pseudo-element */
            position: relative;
        }
        /* Left accent bar — static, no hover animation */
        .question-card-accent {
            position: absolute;
            top: 0; left: 0;
            width: 5px; height: 100%;
            background: linear-gradient(180deg, #1B3C53, #456882);
            border-radius: 18px 0 0 18px;
            pointer-events: none;
        }
        .question-card.csv-imported { border-left: 5px solid #456882; }
        .question-card.csv-imported .question-number { background: linear-gradient(135deg, #456882, #D2C1B6); color: #1B3C53; }

        /* ── Question Number Badge ── */
        .question-number {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: white; width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 0.95rem;
            box-shadow: 0 4px 12px rgba(27,60,83,0.25);
            flex-shrink: 0;
        }

        /* ── Option correct highlight ── */
        .option-correct .form-input {
            background: rgba(35,76,106,0.08) !important;
            border-color: #456882 !important;
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15) !important;
        }

        /* ── Stats badges ── */
        .stats-badge {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            border-radius: 14px; padding: 10px 18px; color: #fff;
            box-shadow: 0 6px 18px rgba(27,60,83,0.20);
        }
        .stats-badge-warm {
            background: linear-gradient(135deg, #D2C1B6, #c4b0a2);
            border-radius: 14px; padding: 10px 18px; color: #1B3C53;
            box-shadow: 0 4px 14px rgba(210,193,182,0.30);
        }

        /* ── Progress Bar ── */
        #progressBar { background: linear-gradient(90deg, #D2C1B6, #ffffff); }

        /* ── Buttons ── */
        .btn-primary {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: #fff; border: none; border-radius: 12px;
            padding: 11px 22px; font-weight: 700; font-size: 0.875rem;
            display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
            transition: all 0.22s ease;
            box-shadow: 0 6px 18px rgba(27,60,83,0.22);
        }
        .btn-primary:hover { background: linear-gradient(135deg, #153043, #1B3C53); transform: translateY(-2px); box-shadow: 0 10px 26px rgba(27,60,83,0.28); }
        .btn-secondary {
            background: linear-gradient(135deg, #456882, #D2C1B6);
            color: #1B3C53; border: none; border-radius: 12px;
            padding: 11px 22px; font-weight: 700; font-size: 0.875rem;
            display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
            transition: all 0.22s ease; box-shadow: 0 4px 14px rgba(69,104,130,0.18);
        }
        .btn-secondary:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(69,104,130,0.25); }
        .btn-success {
            background: linear-gradient(135deg, #234C6A, #456882);
            color: #fff; border: none; border-radius: 12px;
            padding: 13px 30px; font-weight: 700; font-size: 0.95rem;
            display: inline-flex; align-items: center; gap: 10px; cursor: pointer;
            transition: all 0.22s ease; box-shadow: 0 8px 24px rgba(27,60,83,0.22);
            animation: floatBtn 3s ease-in-out infinite;
        }
        .btn-success:hover { background: linear-gradient(135deg, #1B3C53, #234C6A); box-shadow: 0 14px 34px rgba(27,60,83,0.30); }
        @keyframes floatBtn { 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(-5px); } }
        .btn-danger {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: #fff; border: none; border-radius: 10px;
            width: 38px; height: 38px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(220,38,38,0.25);
            pointer-events: all; /* ensure it's always clickable */
            position: relative; z-index: 5;
        }
        .btn-danger:hover { transform: translateY(-1px) scale(1.06); box-shadow: 0 7px 18px rgba(220,38,38,0.35); }
        .btn-outline {
            background: #f2eeea; color: #456882;
            border: 2px solid rgba(69,104,130,0.30);
            border-radius: 12px; padding: 11px 22px; font-weight: 600; font-size: 0.875rem;
            display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-outline:hover { border-color: #234C6A; color: #1B3C53; background: #e8e2dc; }
        .btn-green {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: #fff; border: none; border-radius: 12px;
            padding: 10px 20px; font-weight: 700; font-size: 0.85rem;
            display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
            transition: all 0.2s ease; box-shadow: 0 5px 16px rgba(22,163,74,0.22);
        }
        .btn-green:hover { transform: translateY(-2px); box-shadow: 0 9px 24px rgba(22,163,74,0.30); }

        /* ── File Drop Zone ── */
        .file-drop-zone {
            border: 2.5px dashed rgba(69,104,130,0.40);
            border-radius: 18px; padding: 55px 40px;
            text-align: center; cursor: pointer;
            transition: all 0.25s ease;
            background: #ebe5df;
        }
        .file-drop-zone:hover { border-color: #234C6A; background: #e4ddd6; box-shadow: 0 6px 20px rgba(27,60,83,0.09); }
        .file-drop-zone.active { border-color: #456882; background: rgba(69,104,130,0.05); }
        .file-drop-zone.dragover { border-color: #234C6A; background: rgba(35,76,106,0.07); }

        /* ── CSV Preview ── */
        .csv-preview { max-height: 300px; overflow-y: auto; border: 1px solid rgba(210,193,182,0.5); border-radius: 12px; margin-top: 14px; }
        .csv-table { width: 100%; border-collapse: collapse; }
        .csv-table th {
            background: linear-gradient(135deg, #e8e2dc, #ddd7d0);
            position: sticky; top: 0; font-weight: 700;
            text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em;
            color: #234C6A; border-bottom: 2px solid #D2C1B6; padding: 10px 14px;
        }
        .csv-table td { padding: 9px 14px; border-bottom: 1px solid rgba(210,193,182,0.3); font-size: 0.82rem; color: #1B3C53; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
        .csv-table tbody tr:hover { background: rgba(69,104,130,0.05); }

        /* ── Animations ── */
        .card-enter { animation: cardEnter 0.45s cubic-bezier(0.4, 0, 0.2, 1) forwards; opacity: 0; transform: translateY(16px); }
        @keyframes cardEnter { to { opacity: 1; transform: translateY(0); } }
        .float { animation: floatAnim 6s ease-in-out infinite; }
        @keyframes floatAnim { 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(-10px); } }
        .shake { animation: shakeAnim 0.5s ease-in-out; }
        @keyframes shakeAnim {
            0%,100%             { transform: translateX(0); }
            10%,30%,50%,70%,90% { transform: translateX(-5px); }
            20%,40%,60%,80%     { transform: translateX(5px); }
        }
        .success-check { animation: successCheck 0.5s ease-in-out forwards; }
        @keyframes successCheck { 0%{ transform:scale(0); } 50%{ transform:scale(1.2); } 100%{ transform:scale(1); } }

        /* ── Gradient Text ── */
        .gradient-text { background: linear-gradient(135deg, #1B3C53, #456882); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .highlight { position: relative; }
        .highlight::after {
            content: ''; position: absolute; bottom: -2px; left: 0;
            width: 100%; height: 3px;
            background: linear-gradient(90deg, #1B3C53, #456882);
            transform: scaleX(0); transform-origin: left; transition: transform 0.3s ease;
        }
        .highlight:hover::after { transform: scaleX(1); }

        /* ── Modal ── */
        .modal { display: none; position: fixed; z-index: 1000; inset: 0; background-color: rgba(0,0,0,0.70); animation: modalBgFade 0.3s ease; }
        @keyframes modalBgFade { from{ opacity:0; } to{ opacity:1; } }
        .modal-content {
            background: #f2eeea; margin: 6% auto; padding: 0;
            border-radius: 20px; width: 90%; max-width: 520px;
            transform: translateY(-20px);
            animation: modalSlide 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            box-shadow: 0 30px 60px rgba(0,0,0,0.22);
            border: 1.5px solid rgba(210,193,182,0.4);
        }
        .template-modal-content { max-width: 820px; max-height: 82vh; overflow-y: auto; }
        @keyframes modalSlide { to{ transform:translateY(0); } }

        /* ── Template Table ── */
        .template-table th { background: #e8e2dc; color: #234C6A; font-weight: 700; padding: 10px 14px; border-bottom: 2px solid #D2C1B6; }
        .template-table td { padding: 10px 14px; border-bottom: 1px solid rgba(210,193,182,0.4); font-size: 0.85rem; color: #1B3C53; }
        .template-table tbody tr:hover { background: rgba(69,104,130,0.04); }

        /* ── Student Selection ── */
        .student-label {
            display: flex; align-items: center; padding: 10px 12px;
            background: #f2eeea; border: 1.5px solid rgba(210,193,182,0.5);
            border-radius: 12px; cursor: pointer; transition: all 0.2s;
        }
        .student-label:hover { border-color: #456882; box-shadow: 0 4px 12px rgba(27,60,83,0.08); }

        /* ── Scrollbar ── */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #e8e2dc; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #D2C1B6; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #456882; }

        /* ── Radio & Checkbox ── */
        input[type="radio"], input[type="checkbox"] { accent-color: #234C6A; }
    </style>
</head>
<body class="min-h-screen">
    <div class="fixed top-0 right-0 w-96 h-96 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse pointer-events-none" style="background:radial-gradient(circle,#D2C1B6,transparent);"></div>
    <div class="fixed bottom-0 left-0 w-96 h-96 rounded-full mix-blend-multiply filter blur-3xl opacity-15 float pointer-events-none" style="background:radial-gradient(circle,#456882,transparent);"></div>

    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="ml-0 md:ml-64 min-h-screen p-4 md:p-8">
        <div class="max-w-7xl mx-auto relative z-10">

            <!-- ── Page Header ── -->
            <div class="page-header-banner p-6 md:p-8 mb-7">
                <div class="bubble"></div><div class="bubble"></div><div class="bubble"></div>
                <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <div class="hero-pill mb-3"><i class="fas fa-puzzle-piece"></i> Test Builder</div>
                        <h1 class="text-3xl md:text-4xl font-black text-white leading-tight" style="font-family:'Poppins',sans-serif;text-shadow:0 2px 4px rgba(0,0,0,0.12);">Create New MCQ Test</h1>
                        <p class="text-white/80 mt-1 text-base max-w-xl">Design comprehensive tests with detailed questions and analytics</p>
                    </div>
                    <a href="admin_dashboard.php" class="inline-flex items-center gap-2 bg-white/20 hover:bg-white/30 border border-white/25 text-white px-5 py-2.5 rounded-xl font-semibold text-sm backdrop-blur-sm transition-all hover:-translate-y-0.5">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
                <div class="mt-6 relative z-10">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-xs font-bold text-white/70 uppercase tracking-wider">Test Creation Progress</span>
                        <span class="text-sm font-bold text-white" id="progressPercentage">0%</span>
                    </div>
                    <div class="w-full bg-white/20 rounded-full h-2">
                        <div id="progressBar" class="h-2 rounded-full transition-all duration-500" style="width:0%;"></div>
                    </div>
                </div>
            </div>

            <!-- ── Alerts ── -->
            <?php if ($error): ?>
            <div class="bg-gradient-to-r from-red-500 to-rose-500 text-white px-6 py-4 rounded-xl mb-6 shadow-lg flex items-center gap-3">
                <i class="fas fa-exclamation-triangle text-xl flex-shrink-0"></i>
                <span class="font-medium"><?= htmlspecialchars($error) ?></span>
                <button onclick="this.parentElement.remove()" class="ml-auto text-white/70 hover:text-white"><i class="fas fa-times"></i></button>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="bg-gradient-to-r from-green-500 to-emerald-500 text-white px-6 py-4 rounded-xl mb-6 shadow-lg flex items-center gap-3">
                <i class="fas fa-check-circle text-xl flex-shrink-0 success-check"></i>
                <span class="font-medium"><?= htmlspecialchars($success) ?></span>
                <button onclick="this.parentElement.remove()" class="ml-auto text-white/70 hover:text-white"><i class="fas fa-times"></i></button>
            </div>
            <?php endif; ?>

            <!-- ── Form ── -->
            <form method="POST" id="testForm" class="space-y-6" enctype="multipart/form-data">

                <!-- Section 1: Test Config -->
                <div class="glass-card p-6 md:p-8">
                    <div class="flex items-center gap-4 mb-6 pb-4" style="border-bottom:1.5px solid rgba(69,104,130,0.15);">
                        <div class="section-icon section-icon-config"><i class="fas fa-file-alt"></i></div>
                        <div>
                            <h2 class="text-xl font-bold text-[#1B3C53] highlight">Test Configuration</h2>
                            <p class="text-[#456882] text-sm mt-0.5">Configure basic test settings and parameters</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="form-label"><i class="fas fa-heading text-[#456882]"></i> Test Title <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                            <input type="text" name="title" required class="form-input" placeholder="Enter test title">
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-book text-[#456882]"></i> Subject</label>
                            <input type="text" name="subject" class="form-input" placeholder="e.g., Mathematics, Physics">
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-users text-[#456882]"></i> Batch Assignment</label>
                            <?php $selected_batch_id = isset($_GET['batch_id']) ? trim($_GET['batch_id']) : ''; ?>
                            <select name="batch_id" id="batch_id" onchange="fetchBatchDetails(this.value)" class="form-input">
                                <option value="">All Batches (Public Test)</option>
                                <?php foreach ($batches as $batch): ?>
                                <option value="<?= $batch['batch_id'] ?>" <?= $selected_batch_id === $batch['batch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($batch['batch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-layer-group text-[#456882]"></i> Test Category</label>
                            <select name="test_category" id="test_category" onchange="handleCategoryChange()" class="form-input">
                                <option value="">-- Select Category --</option>
                                <option value="chapter_wise">Chapter Wise Test</option>
                                <option value="weekly">Weekly Test</option>
                            </select>
                        </div>
                        <div id="course_link_container">
                            <label class="form-label"><i class="fas fa-graduation-cap text-[#456882]"></i> Course Link <span class="normal-case font-normal text-[#456882] text-[11px] ml-1">(Optional)</span></label>
                            <select name="course_id" id="course_id" onchange="fetchChapters(this.value)" class="form-input">
                                <option value="">-- No specific course link --</option>
                                <?php foreach ($all_courses as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="chapter_link_container" style="display:none;">
                            <label class="form-label"><i class="fas fa-book-open text-[#456882]"></i> Chapter</label>
                            <select name="chapter_id" id="chapter_id" class="form-input">
                                <option value="">-- Select Chapter --</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-user-tag text-[#456882]"></i> Assign To</label>
                            <div class="flex items-center gap-6 py-3 px-4 rounded-xl" style="background:#ebe5df;border:2px solid rgba(69,104,130,0.22);">
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="assigned_to" value="all" checked onchange="toggleTargeting(this.value)" class="w-4 h-4">
                                    <span class="text-[#1B3C53] font-semibold text-sm">All Students</span>
                                </label>
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="assigned_to" value="specific" onchange="toggleTargeting(this.value)" class="w-4 h-4">
                                    <span class="text-[#1B3C53] font-semibold text-sm">Specific Students</span>
                                </label>
                            </div>
                        </div>
                        <!-- Students Container -->
                        <div id="student_targeting_container" class="hidden md:col-span-2 rounded-2xl p-5" style="background:#ebe5df;border:1.5px solid rgba(69,104,130,0.20);">
                            <div class="flex justify-between items-center mb-4 pb-3" style="border-bottom:1px solid rgba(69,104,130,0.15);">
                                <span class="text-sm font-bold text-[#1B3C53] flex items-center gap-2"><i class="fas fa-list-check text-[#456882]"></i> Select Target Students</span>
                                <div class="flex gap-2">
                                    <button type="button" onclick="selectAllTargetStudents(true)" class="text-xs px-3 py-1.5 rounded-lg font-semibold transition-all" style="background:rgba(35,76,106,0.12);color:#234C6A;">Select All</button>
                                    <button type="button" onclick="selectAllTargetStudents(false)" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1.5 rounded-lg font-semibold transition-all">Clear All</button>
                                </div>
                            </div>
                            <div id="student_list_grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 max-h-64 overflow-y-auto pr-1"></div>
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-clock text-[#456882]"></i> Duration (Minutes)</label>
                            <input type="number" name="duration_minutes" value="60" min="1" class="form-input">
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-redo text-[#456882]"></i> Maximum Attempts</label>
                            <input type="number" name="max_attempts" value="1" min="1" class="form-input">
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-trophy text-[#456882]"></i> Passing Marks</label>
                            <input type="number" name="passing_marks" value="40" min="0" class="form-input">
                        </div>
                        <div id="start_date_container">
                            <label class="form-label"><i class="fas fa-calendar-plus text-[#456882]"></i> Start Date <span id="start_date_req" class="text-red-500 hidden normal-case font-normal">*</span> <span class="normal-case font-normal text-[#456882] text-[11px] ml-1">(Optional)</span></label>
                            <input type="datetime-local" name="start_date" id="start_date" class="form-input">
                        </div>
                        <div id="end_date_container">
                            <label class="form-label"><i class="fas fa-calendar-minus text-[#456882]"></i> End Date <span id="end_date_req" class="text-red-500 hidden normal-case font-normal">*</span> <span class="normal-case font-normal text-[#456882] text-[11px] ml-1">(Optional)</span></label>
                            <input type="datetime-local" name="end_date" id="end_date" class="form-input">
                        </div>
                    </div>
                    <div class="mt-5">
                        <label class="form-label"><i class="fas fa-align-left text-[#456882]"></i> Description</label>
                        <textarea name="description" rows="3" class="form-input" style="resize:vertical;" placeholder="Provide a brief description of the test..."></textarea>
                    </div>
                </div>

                <!-- Section 2: CSV Upload -->
                <div class="glass-card p-6 md:p-8">
                    <div class="flex items-center gap-4 mb-6 pb-4 flex-wrap" style="border-bottom:1.5px solid rgba(69,104,130,0.15);">
                        <div class="section-icon section-icon-csv"><i class="fas fa-file-csv"></i></div>
                        <div class="flex-1">
                            <h2 class="text-xl font-bold text-[#1B3C53] highlight">Bulk Upload Questions</h2>
                            <p class="text-[#456882] text-sm mt-0.5">Upload a CSV file to add questions in bulk (Optional)</p>
                        </div>
                        <button type="button" onclick="showTemplateModal()" class="btn-secondary">
                            <i class="fas fa-download"></i> Download Template
                        </button>
                    </div>
                    <div class="file-drop-zone" id="fileDropZone">
                        <div class="mb-4">
                            <i class="fas fa-cloud-upload-alt text-5xl mb-4" style="color:#456882;"></i>
                            <h3 class="text-xl font-bold text-[#1B3C53] mb-2">Drag &amp; Drop CSV File</h3>
                            <p class="text-[#456882] mb-4">or click to browse your files</p>
                            <div class="text-sm text-gray-500 mb-5"><i class="fas fa-info-circle mr-1"></i> Supported format: CSV (Max 5MB)</div>
                        </div>
                        <input type="file" name="csv_file" id="csvFileInput" accept=".csv" class="hidden" onchange="handleFileSelect(event)">
                        <button type="button" onclick="document.getElementById('csvFileInput').click()" class="btn-primary">
                            <i class="fas fa-folder-open"></i> Browse Files
                        </button>
                    </div>
                    <div id="filePreview" class="hidden mt-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-base font-bold text-[#1B3C53] flex items-center gap-2"><i class="fas fa-file-csv text-[#456882]"></i> File Preview</h3>
                            <div class="flex items-center gap-3">
                                <button type="button" onclick="addCSVQuestionsToForm()" class="btn-green"><i class="fas fa-plus"></i> Add to Form</button>
                                <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-700 text-sm font-semibold flex items-center gap-1 transition-colors"><i class="fas fa-times"></i> Remove</button>
                            </div>
                        </div>
                        <div id="previewContent" class="csv-preview"></div>
                        <div class="mt-3 flex items-center text-sm text-[#456882]">
                            <i class="fas fa-info-circle text-[#234C6A] mr-2"></i>
                            <span id="questionCountFromCSV">0 questions</span> detected. Click "Add to Form" to add them.
                        </div>
                    </div>
                </div>

                <!-- Section 3: Questions -->
                <div class="glass-card p-6 md:p-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
                        <div class="flex items-center gap-4">
                            <div class="section-icon section-icon-qa"><i class="fas fa-question-circle"></i></div>
                            <div>
                                <h2 class="text-xl font-bold text-[#1B3C53] highlight">Questions &amp; Answers</h2>
                                <p class="text-[#456882] text-sm mt-0.5">Add questions with multiple choice options</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="stats-badge text-center min-w-[80px]">
                                <div class="text-xs font-bold uppercase tracking-wider text-white/70">Questions</div>
                                <div class="text-2xl font-black text-white" id="questionCountDisplay">1</div>
                            </div>
                            <div class="stats-badge-warm text-center min-w-[80px]">
                                <div class="text-xs font-bold uppercase tracking-wider text-[#456882]">Total Marks</div>
                                <div class="text-2xl font-black text-[#1B3C53]" id="totalMarksDisplay">1</div>
                            </div>
                        </div>
                    </div>

                    <div id="questionsContainer" class="space-y-5">
                        <!-- Default Question 1 -->
                        <div class="question-card p-6 card-enter" data-question-index="1">
                            <div class="question-card-accent"></div>
                            <div class="flex items-center justify-between mb-5 pl-3">
                                <div class="flex items-center gap-3">
                                    <div class="question-number">1</div>
                                    <div>
                                        <h3 class="text-base font-bold text-[#1B3C53]">Question #1</h3>
                                        <p class="text-xs text-[#456882]">Required field</p>
                                    </div>
                                </div>
                                <button type="button" onclick="removeQuestion(this)" class="btn-danger" title="Remove question">
                                    <i class="fas fa-trash text-sm"></i>
                                </button>
                            </div>
                            <div class="pl-3">
                                <div class="mb-4">
                                    <label class="form-label"><i class="fas fa-question text-[#456882]"></i> Question Text <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                                    <textarea name="questions[]" rows="3" required class="form-input" style="resize:vertical;" placeholder="Enter your question here..."></textarea>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div class="option-input">
                                        <label class="form-label"><i class="fas fa-dot-circle text-[#1B3C53]"></i> Option A <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                                        <input type="text" name="options_a[]" required class="form-input" placeholder="Enter option A">
                                    </div>
                                    <div class="option-input">
                                        <label class="form-label"><i class="fas fa-dot-circle text-[#234C6A]"></i> Option B <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                                        <input type="text" name="options_b[]" required class="form-input" placeholder="Enter option B">
                                    </div>
                                    <div class="option-input">
                                        <label class="form-label"><i class="fas fa-dot-circle text-[#456882]"></i> Option C <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                                        <input type="text" name="options_c[]" required class="form-input" placeholder="Enter option C">
                                    </div>
                                    <div class="option-input">
                                        <label class="form-label"><i class="fas fa-dot-circle" style="color:#7a8e9e;"></i> Option D <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                                        <input type="text" name="options_d[]" required class="form-input" placeholder="Enter option D">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="form-label"><i class="fas fa-check-circle text-green-600"></i> Correct Answer <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                                        <select name="correct_answers[]" required onchange="highlightCorrectOption(this)" class="form-input">
                                            <option value="">Select Answer</option>
                                            <option value="a">Option A</option>
                                            <option value="b">Option B</option>
                                            <option value="c">Option C</option>
                                            <option value="d">Option D</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="fas fa-star text-yellow-500"></i> Marks</label>
                                        <input type="number" name="marks[]" value="1" min="1" onchange="updateTotalMarks()" class="form-input">
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="fas fa-lightbulb text-[#456882]"></i> Explanation <span class="normal-case font-normal text-[#456882] text-[11px] ml-1">(Optional)</span></label>
                                        <input type="text" name="explanations[]" class="form-input" placeholder="Brief explanation">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-7 text-center">
                        <button type="button" onclick="addQuestion()" class="btn-success">
                            <i class="fas fa-plus-circle text-lg"></i> Add Another Question <i class="fas fa-arrow-down"></i>
                        </button>
                    </div>
                </div>

                <!-- Section 4: Actions -->
                <div class="glass-card p-6 md:p-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div>
                            <div class="text-sm text-[#456882] mb-1.5">Ready to create your test?</div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-full bg-[#456882] animate-pulse"></div>
                                <div class="font-semibold text-[#1B3C53]">Review all fields before submitting</div>
                            </div>
                        </div>
                        <div class="flex gap-3 flex-wrap">
                            <button type="reset" class="btn-outline"><i class="fas fa-redo"></i> Reset Form</button>
                            <button type="submit" class="btn-primary text-base px-8 py-3"><i class="fas fa-save"></i> Create Test <i class="fas fa-rocket"></i></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- CSV Template Modal -->
    <div id="templateModal" class="modal">
        <div class="modal-content template-modal-content">
            <div class="p-6 rounded-t-2xl" style="background:linear-gradient(135deg,#1B3C53,#234C6A);">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 bg-white/20 rounded-full flex items-center justify-center border border-white/30">
                        <i class="fas fa-file-csv text-white text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold text-white">CSV Template Format</h3>
                        <p class="text-white/70 mt-1 text-sm">Download the template and fill in your questions</p>
                    </div>
                </div>
            </div>
            <div class="p-6">
                <div class="mb-5">
                    <h4 class="text-base font-bold text-[#1B3C53] mb-3 flex items-center gap-2"><i class="fas fa-info-circle text-[#456882]"></i> CSV Format Requirements</h4>
                    <div class="p-4 rounded-xl" style="background:rgba(69,104,130,0.06);border:1px solid rgba(210,193,182,0.4);">
                        <ul class="space-y-2 text-[#234C6A] text-sm">
                            <li class="flex items-start gap-2"><i class="fas fa-check text-[#456882] mt-0.5 flex-shrink-0"></i><span>File must be in CSV format (Comma Separated Values)</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-check text-[#456882] mt-0.5 flex-shrink-0"></i><span>Include all columns in the exact order shown below</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-check text-[#456882] mt-0.5 flex-shrink-0"></i><span>Correct answer should be one of: a, b, c, d (lowercase)</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-check text-[#456882] mt-0.5 flex-shrink-0"></i><span>Use double quotes (&quot;) to wrap text containing commas</span></li>
                        </ul>
                    </div>
                </div>
                <div class="mb-5">
                    <h4 class="text-base font-bold text-[#1B3C53] mb-3 flex items-center gap-2"><i class="fas fa-table text-[#456882]"></i> CSV Structure</h4>
                    <div class="overflow-x-auto rounded-xl" style="border:1px solid rgba(210,193,182,0.5);">
                        <table class="min-w-full template-table">
                            <thead><tr><th class="text-left">Column</th><th class="text-left">Description</th><th class="text-left">Required</th><th class="text-left">Example</th></tr></thead>
                            <tbody>
                                <tr><td class="font-mono text-xs">question_text</td><td>The question text</td><td><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-bold">Required</span></td><td>"What is 2+2?"</td></tr>
                                <tr><td class="font-mono text-xs">option_a</td><td>Option A text</td><td><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-bold">Required</span></td><td>"3"</td></tr>
                                <tr><td class="font-mono text-xs">option_b</td><td>Option B text</td><td><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-bold">Required</span></td><td>"4"</td></tr>
                                <tr><td class="font-mono text-xs">option_c</td><td>Option C text</td><td><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-bold">Required</span></td><td>"5"</td></tr>
                                <tr><td class="font-mono text-xs">option_d</td><td>Option D text</td><td><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-bold">Required</span></td><td>"6"</td></tr>
                                <tr><td class="font-mono text-xs">correct_answer</td><td>Correct option (a,b,c,d)</td><td><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-bold">Required</span></td><td>b</td></tr>
                                <tr><td class="font-mono text-xs">marks</td><td>Marks for this question</td><td><span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-bold">Optional</span></td><td>1</td></tr>
                                <tr><td class="font-mono text-xs">explanation</td><td>Explanation for answer</td><td><span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-bold">Optional</span></td><td>"2+2 equals 4"</td></tr>
                                <tr><td class="font-mono text-xs">question_order</td><td>Display order</td><td><span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-bold">Optional</span></td><td>1</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mb-5">
                    <h4 class="text-base font-bold text-[#1B3C53] mb-3 flex items-center gap-2"><i class="fas fa-code text-[#456882]"></i> Sample CSV</h4>
                    <div class="p-4 rounded-xl font-mono text-xs overflow-x-auto leading-relaxed" style="background:#1B3C53;color:#D2C1B6;">
                        question_text,option_a,option_b,option_c,option_d,correct_answer,marks,explanation,question_order<br>
                        "What is 2+2?","3","4","5","6","b",1,"2+2 equals 4",1<br>
                        "Capital of France?","London","Paris","Berlin","Madrid","b",1,"Paris is the capital",2
                    </div>
                </div>
                <div class="flex justify-between items-center pt-4" style="border-top:1.5px solid rgba(69,104,130,0.18);">
                    <button onclick="downloadCSVTemplate()" class="btn-primary"><i class="fas fa-download"></i> Download Template CSV</button>
                    <button onclick="closeModal('templateModal')" class="btn-outline"><i class="fas fa-times"></i> Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="p-7 rounded-t-2xl" style="background:linear-gradient(135deg,#234C6A,#456882);">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center border border-white/30">
                        <i class="fas fa-check text-white text-2xl success-check"></i>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold text-white">Test Created Successfully!</h3>
                        <p id="successMessage" class="text-white/70 mt-1 text-sm"></p>
                    </div>
                </div>
            </div>
            <div class="p-7">
                <div class="p-4 rounded-xl mb-6" style="border-left:4px solid #456882;background:rgba(69,104,130,0.07);">
                    <div class="flex gap-3">
                        <i class="fas fa-info-circle text-[#456882] mt-0.5 flex-shrink-0"></i>
                        <div>
                            <h4 class="font-bold text-[#1B3C53] mb-1">What's next?</h4>
                            <p class="text-[#456882] text-sm">Your test is now available. Manage it from the dashboard or share it with students.</p>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end">
                    <a href="admin_dashboard.php" class="btn-primary text-base px-6 py-3"><i class="fas fa-th-large"></i> Go to Dashboard</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        let questionCount = 1;
        let totalMarks = 1;
        let csvData = [];

        function updateProgress() {
            const form = document.getElementById('testForm');
            const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
            const filledInputs = Array.from(inputs).filter(input => input.value.trim() !== '').length;
            const progress = inputs.length > 0 ? (filledInputs / inputs.length) * 100 : 0;
            document.getElementById('progressBar').style.width = `${progress}%`;
            document.getElementById('progressPercentage').textContent = `${Math.round(progress)}%`;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('testForm');
            form.querySelectorAll('input, textarea, select').forEach(input => {
                input.addEventListener('input', updateProgress);
                input.addEventListener('change', updateProgress);
            });
            updateProgress();
            updateTotalMarks();

            const fileDropZone = document.getElementById('fileDropZone');
            const fileInput = document.getElementById('csvFileInput');
            fileDropZone.addEventListener('click', (e) => { if (e.target === fileDropZone || e.target.tagName !== 'BUTTON') fileInput.click(); });
            fileDropZone.addEventListener('dragover', (e) => { e.preventDefault(); fileDropZone.classList.add('dragover'); });
            fileDropZone.addEventListener('dragleave', () => fileDropZone.classList.remove('dragover'));
            fileDropZone.addEventListener('drop', (e) => {
                e.preventDefault(); fileDropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; handleFileSelect({ target: fileInput }); }
            });
        });

        function parseCSVLine(line) {
            const result = []; let current = ''; let inQuotes = false;
            for (let i = 0; i < line.length; i++) {
                const char = line[i];
                if (char === '"') { inQuotes = !inQuotes; }
                else if (char === ',' && !inQuotes) { result.push(current.trim()); current = ''; }
                else { current += char; }
            }
            result.push(current.trim());
            return result;
        }

        function parseCSVContent(content) {
            const lines = content.split('\n').filter(line => line.trim() !== '');
            if (lines.length === 0) return [];
            const headers = parseCSVLine(lines[0]);
            const data = [];
            for (let i = 1; i < lines.length; i++) {
                const values = parseCSVLine(lines[i]);
                if (values.length >= 6) {
                    const row = {};
                    headers.forEach((header, index) => {
                        if (index < values.length) {
                            let value = values[index];
                            if (value.startsWith('"') && value.endsWith('"')) value = value.substring(1, value.length - 1);
                            value = value.replace(/""/g, '"');
                            row[header.trim()] = value;
                        }
                    });
                    data.push(row);
                }
            }
            return data;
        }

        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (!file) return;
            if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) { alert('Please upload a CSV file.'); return; }
            if (file.size > 5 * 1024 * 1024) { alert('File size should be less than 5MB.'); return; }
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const parsedData = parseCSVContent(e.target.result);
                    if (parsedData.length === 0) { alert('No valid data found in CSV file.'); return; }
                    csvData = parsedData.map(row => ({
                        question: row.question_text || '',
                        option_a: row.option_a || '', option_b: row.option_b || '',
                        option_c: row.option_c || '', option_d: row.option_d || '',
                        correct_answer: row.correct_answer ? row.correct_answer.toLowerCase().trim() : '',
                        marks: parseInt(row.marks) || 1,
                        explanation: row.explanation || '',
                        question_order: parseInt(row.question_order) || 0
                    })).filter(q => q.question && q.option_a && q.option_b && q.option_c && q.option_d && q.correct_answer);

                    if (csvData.length === 0) { alert('No valid questions found. Check required fields.'); return; }

                    document.getElementById('fileDropZone').classList.add('active');
                    document.getElementById('fileDropZone').innerHTML = `
                        <div class="mb-4">
                            <i class="fas fa-file-csv text-5xl mb-4" style="color:#456882;"></i>
                            <h3 class="text-xl font-bold text-[#1B3C53] mb-2">${file.name}</h3>
                            <p class="text-[#456882] mb-3">${(file.size / 1024).toFixed(2)} KB</p>
                            <div class="text-sm font-semibold" style="color:#234C6A;"><i class="fas fa-check-circle mr-2"></i>CSV file uploaded successfully</div>
                        </div>`;

                    document.getElementById('filePreview').classList.remove('hidden');
                    document.getElementById('questionCountFromCSV').textContent = `${csvData.length} questions`;

                    let tableHTML = `<table class="csv-table"><thead><tr><th>Question</th><th>A</th><th>B</th><th>C</th><th>D</th><th>Correct</th><th>Marks</th></tr></thead><tbody>`;
                    for (let i = 0; i < Math.min(5, csvData.length); i++) {
                        const q = csvData[i];
                        const qPrev = q.question.length > 50 ? q.question.substring(0, 50) + '...' : q.question;
                        tableHTML += `<tr>
                            <td title="${q.question.replace(/"/g, '&quot;')}">${qPrev}</td>
                            <td>${q.option_a.substring(0, 18)}${q.option_a.length > 18 ? '...' : ''}</td>
                            <td>${q.option_b.substring(0, 18)}${q.option_b.length > 18 ? '...' : ''}</td>
                            <td>${q.option_c.substring(0, 18)}${q.option_c.length > 18 ? '...' : ''}</td>
                            <td>${q.option_d.substring(0, 18)}${q.option_d.length > 18 ? '...' : ''}</td>
                            <td class="text-center"><span class="px-2 py-0.5 rounded-full text-xs font-bold" style="background:rgba(35,76,106,0.12);color:#234C6A;">${q.correct_answer.toUpperCase()}</span></td>
                            <td class="text-center font-bold" style="color:#1B3C53;">${q.marks}</td>
                        </tr>`;
                    }
                    tableHTML += '</tbody></table>';
                    document.getElementById('previewContent').innerHTML = tableHTML;
                    if (csvData.length > 5) {
                        document.getElementById('previewContent').innerHTML += `<div class="text-center py-2 text-sm" style="color:#456882;">... and ${csvData.length - 5} more questions</div>`;
                    }
                } catch (error) { console.error('Error parsing CSV:', error); alert('Error parsing CSV file. Please check the format.'); }
            };
            reader.readAsText(file);
        }

        /* ── Build Question Card HTML ── */
        function buildQuestionHTML(qNum, question, isCSV) {
            const esc = (str) => str ? str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;') : '';
            const csvBadge = isCSV ? `<span class="text-xs font-bold px-2 py-0.5 rounded-full ml-2" style="background:rgba(69,104,130,0.14);color:#456882;">CSV Import</span>` : '';
            return `
                <div class="question-card-accent"></div>
                <div class="flex items-center justify-between mb-5 pl-3">
                    <div class="flex items-center gap-3">
                        <div class="question-number">${qNum}</div>
                        <div>
                            <h3 class="text-base font-bold" style="color:#1B3C53;">Question #${qNum} ${csvBadge}</h3>
                            <p class="text-xs" style="color:#456882;">${isCSV ? 'Imported from CSV' : 'Required field'}</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeQuestion(this)" class="btn-danger" title="Remove question" style="position:relative;z-index:10;">
                        <i class="fas fa-trash text-sm"></i>
                    </button>
                </div>
                <div class="pl-3">
                    <div class="mb-4">
                        <label class="form-label"><i class="fas fa-question" style="color:#456882;"></i> Question Text <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                        <textarea name="questions[]" rows="3" required class="form-input" style="resize:vertical;" placeholder="Enter your question here..." oninput="updateProgress()">${question ? esc(question.question) : ''}</textarea>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="option-input">
                            <label class="form-label"><i class="fas fa-dot-circle" style="color:#1B3C53;"></i> Option A <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                            <input type="text" name="options_a[]" required class="form-input" placeholder="Enter option A" oninput="updateProgress()" value="${question ? esc(question.option_a) : ''}">
                        </div>
                        <div class="option-input">
                            <label class="form-label"><i class="fas fa-dot-circle" style="color:#234C6A;"></i> Option B <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                            <input type="text" name="options_b[]" required class="form-input" placeholder="Enter option B" oninput="updateProgress()" value="${question ? esc(question.option_b) : ''}">
                        </div>
                        <div class="option-input">
                            <label class="form-label"><i class="fas fa-dot-circle" style="color:#456882;"></i> Option C <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                            <input type="text" name="options_c[]" required class="form-input" placeholder="Enter option C" oninput="updateProgress()" value="${question ? esc(question.option_c) : ''}">
                        </div>
                        <div class="option-input">
                            <label class="form-label"><i class="fas fa-dot-circle" style="color:#7a8e9e;"></i> Option D <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                            <input type="text" name="options_d[]" required class="form-input" placeholder="Enter option D" oninput="updateProgress()" value="${question ? esc(question.option_d) : ''}">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="form-label"><i class="fas fa-check-circle" style="color:#16a34a;"></i> Correct Answer <span class="text-red-500 normal-case font-normal ml-1">*</span></label>
                            <select name="correct_answers[]" required onchange="highlightCorrectOption(this); updateProgress()" class="form-input">
                                <option value="">Select Answer</option>
                                <option value="a" ${question && question.correct_answer === 'a' ? 'selected' : ''}>Option A</option>
                                <option value="b" ${question && question.correct_answer === 'b' ? 'selected' : ''}>Option B</option>
                                <option value="c" ${question && question.correct_answer === 'c' ? 'selected' : ''}>Option C</option>
                                <option value="d" ${question && question.correct_answer === 'd' ? 'selected' : ''}>Option D</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-star" style="color:#eab308;"></i> Marks</label>
                            <input type="number" name="marks[]" value="${question ? (question.marks || 1) : 1}" min="1" onchange="updateTotalMarks()" class="form-input">
                        </div>
                        <div>
                            <label class="form-label"><i class="fas fa-lightbulb" style="color:#456882;"></i> Explanation <span class="normal-case font-normal text-[#456882] text-[11px] ml-1">(Optional)</span></label>
                            <input type="text" name="explanations[]" class="form-input" placeholder="Brief explanation" value="${question ? esc(question.explanation || '') : ''}">
                        </div>
                    </div>
                </div>`;
        }

        /* ── Add CSV Questions to Form ──
           FIX: if question 1 is empty, fill it first, then add the rest */
        function addCSVQuestionsToForm() {
            if (csvData.length === 0) { alert('No CSV data to add. Please upload a CSV file first.'); return; }
            const container = document.getElementById('questionsContainer');
            let addedCount = 0;
            let startIndex = 0;

            // Check if question 1 is empty (all fields blank)
            const firstCard = container.querySelector('.question-card');
            if (firstCard) {
                const firstTextarea = firstCard.querySelector('textarea[name="questions[]"]');
                const firstOptA    = firstCard.querySelector('input[name="options_a[]"]');
                if (firstTextarea && firstOptA && firstTextarea.value.trim() === '' && firstOptA.value.trim() === '') {
                    // Reuse question 1 slot for the first CSV question
                    const q = csvData[0];
                    const esc = (str) => str ? str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;') : '';
                    firstTextarea.value = q.question;
                    firstCard.querySelector('input[name="options_a[]"]').value = q.option_a;
                    firstCard.querySelector('input[name="options_b[]"]').value = q.option_b;
                    firstCard.querySelector('input[name="options_c[]"]').value = q.option_c;
                    firstCard.querySelector('input[name="options_d[]"]').value = q.option_d;
                    const sel = firstCard.querySelector('select[name="correct_answers[]"]');
                    sel.value = q.correct_answer;
                    firstCard.querySelector('input[name="marks[]"]').value = q.marks || 1;
                    const expInp = firstCard.querySelector('input[name="explanations[]"]');
                    if (expInp) expInp.value = q.explanation || '';
                    firstCard.classList.add('csv-imported');
                    highlightCorrectOption(sel);
                    addedCount++;
                    startIndex = 1;
                }
            }

            // Add remaining CSV questions
            for (let idx = startIndex; idx < csvData.length; idx++) {
                const question = csvData[idx];
                if (!question.question || !question.option_a || !question.option_b || !question.option_c || !question.option_d || !question.correct_answer) continue;
                questionCount++;
                const newQuestion = document.createElement('div');
                newQuestion.className = 'question-card p-6 card-enter csv-imported';
                newQuestion.setAttribute('data-question-index', questionCount);
                newQuestion.innerHTML = buildQuestionHTML(questionCount, question, true);
                container.appendChild(newQuestion);
                addedCount++;
                setTimeout(() => {
                    const select = newQuestion.querySelector('select[name="correct_answers[]"]');
                    if (select) highlightCorrectOption(select);
                }, 100);
            }

            updateTotalQuestionCount(); updateTotalMarks(); updateProgress();
            if (addedCount > 0) {
                csvData = []; clearFile();
                const questions = document.querySelectorAll('.question-card');
                if (questions.length > 0) questions[questions.length - 1].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                showNotification(`${addedCount} question(s) loaded from CSV!`, 'success');
            } else {
                alert('No valid questions found in the CSV file. Please check the format.');
            }
        }

        function clearFile() {
            document.getElementById('csvFileInput').value = ''; csvData = [];
            const fz = document.getElementById('fileDropZone');
            fz.classList.remove('active');
            fz.innerHTML = `
                <div class="mb-4">
                    <i class="fas fa-cloud-upload-alt text-5xl mb-4" style="color:#456882;"></i>
                    <h3 class="text-xl font-bold text-[#1B3C53] mb-2">Drag &amp; Drop CSV File</h3>
                    <p class="text-[#456882] mb-4">or click to browse your files</p>
                    <div class="text-sm text-gray-500 mb-5"><i class="fas fa-info-circle mr-1"></i> Supported format: CSV (Max 5MB)</div>
                </div>
                <button type="button" onclick="document.getElementById('csvFileInput').click()" class="btn-primary">
                    <i class="fas fa-folder-open"></i> Browse Files
                </button>`;
            document.getElementById('filePreview').classList.add('hidden');
        }

        function showTemplateModal() { document.getElementById('templateModal').style.display = 'block'; document.body.style.overflow = 'hidden'; }

        function downloadCSVTemplate() {
            const csvContent = `question_text,option_a,option_b,option_c,option_d,correct_answer,marks,explanation,question_order
"What is 2+2, and why?","3, but it's wrong","4, the correct one","5, too high","6, too low","b",1,"2+2 equals 4, always",1
"Capital of France?","London","Paris","Berlin","Madrid","b",1,"Paris is the capital",2
"Red Planet?","Earth","Mars","Venus","Jupiter","b",2,"Mars appears red due to iron oxide",3`;
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a'); a.href = url; a.download = 'test_questions_template.csv';
            document.body.appendChild(a); a.click(); document.body.removeChild(a); window.URL.revokeObjectURL(url);
        }

        function addQuestion() {
            questionCount++;
            const container = document.getElementById('questionsContainer');
            const newQuestion = document.createElement('div');
            newQuestion.className = 'question-card p-6 card-enter';
            newQuestion.setAttribute('data-question-index', questionCount);
            newQuestion.innerHTML = buildQuestionHTML(questionCount, null, false);
            container.appendChild(newQuestion);
            updateTotalQuestionCount(); updateProgress();
            newQuestion.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function removeQuestion(button) {
            const allCards = document.querySelectorAll('.question-card');
            if (allCards.length > 1) {
                const questionCard = button.closest('.question-card');
                const marksInput = questionCard.querySelector('input[name="marks[]"]');
                if (marksInput) totalMarks -= parseInt(marksInput.value) || 0;
                questionCard.style.transition = 'all 0.3s ease';
                questionCard.style.transform = 'translateX(-30px)';
                questionCard.style.opacity = '0';
                setTimeout(() => {
                    questionCard.remove(); questionCount--;
                    updateTotalQuestionCount(); updateTotalMarks(); updateProgress();
                    document.querySelectorAll('.question-card').forEach((card, index) => {
                        const num = index + 1;
                        const numDiv = card.querySelector('.question-number');
                        const titleEl = card.querySelector('h3');
                        if (numDiv) numDiv.textContent = num;
                        if (titleEl) {
                            const badge = titleEl.querySelector('span');
                            titleEl.textContent = `Question #${num} `;
                            if (badge) titleEl.appendChild(badge);
                        }
                        card.setAttribute('data-question-index', num);
                    });
                }, 280);
            } else {
                const questionCard = button.closest('.question-card');
                questionCard.classList.add('shake');
                setTimeout(() => questionCard.classList.remove('shake'), 500);
                showNotification('At least one question is required!', 'warning');
            }
        }

        function updateTotalQuestionCount() {
            document.getElementById('questionCountDisplay').textContent = document.querySelectorAll('.question-card').length;
        }
        function updateTotalMarks() {
            totalMarks = 0;
            document.querySelectorAll('input[name="marks[]"]').forEach(input => totalMarks += parseInt(input.value) || 0);
            document.getElementById('totalMarksDisplay').textContent = totalMarks;
        }
        function highlightCorrectOption(select) {
            const card = select.closest('.question-card');
            const optionInputs = card.querySelectorAll('.option-input');
            optionInputs.forEach(o => o.classList.remove('option-correct'));
            if (select.value) {
                const map = { 'a': 0, 'b': 1, 'c': 2, 'd': 3 };
                const optionInput = optionInputs[map[select.value]];
                if (optionInput) optionInput.classList.add('option-correct');
            }
        }
        function showNotification(message, type = 'info') {
            const n = document.createElement('div');
            n.style.cssText = `position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:14px;font-size:.875rem;font-weight:600;display:flex;align-items:center;gap:10px;max-width:360px;animation:cardEnter 0.4s ease;box-shadow:0 10px 28px rgba(27,60,83,0.25);`;
            if (type === 'success') n.style.background = 'linear-gradient(135deg,#234C6A,#456882)', n.style.color = '#fff';
            else if (type === 'warning') n.style.background = 'linear-gradient(135deg,#d97706,#f59e0b)', n.style.color = '#fff';
            else n.style.background = 'linear-gradient(135deg,#1B3C53,#234C6A)', n.style.color = '#fff';
            const icon = type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle';
            n.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span><button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:inherit;opacity:0.7;cursor:pointer;"><i class="fas fa-times"></i></button>`;
            document.body.appendChild(n);
            setTimeout(() => { if (n.parentNode) n.remove(); }, 5000);
        }
        function closeModal(modalId) { document.getElementById(modalId).style.display = 'none'; document.body.style.overflow = 'auto'; }
        function toggleTargeting(val) {
            const container = document.getElementById('student_targeting_container');
            if (val === 'specific') container.classList.remove('hidden');
            else { container.classList.add('hidden'); document.querySelectorAll('.student-target-checkbox').forEach(cb => cb.checked = false); }
        }
        function selectAllTargetStudents(checked) { document.querySelectorAll('.student-target-checkbox').forEach(cb => cb.checked = checked); }
        function fetchBatchDetails(batchId) {
            const courseSelect = document.getElementById('course_id');
            const studentGrid = document.getElementById('student_list_grid');
            courseSelect.innerHTML = '<option value="">-- No specific course link --</option>';
            studentGrid.innerHTML = '<div class="col-span-full py-4 text-center italic" style="color:#456882;"><i class="fas fa-spinner fa-spin mr-2"></i>Loading students...</div>';
            fetch(`../actions/get_batch_details.php?batch_id=${encodeURIComponent(batchId)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        data.courses.forEach(course => { const opt = document.createElement('option'); opt.value = course.id; opt.textContent = course.name; courseSelect.appendChild(opt); });
                        studentGrid.innerHTML = '';
                        if (data.students.length > 0) {
                            data.students.forEach(student => {
                                const label = document.createElement('label');
                                label.className = 'student-label';
                                label.innerHTML = `<input type="checkbox" name="student_ids[]" value="${escapeHtml(student.student_id)}" class="student-target-checkbox mr-3 w-4 h-4"><div class="text-sm"><div class="font-semibold" style="color:#1B3C53;">${escapeHtml(student.first_name)} ${escapeHtml(student.last_name)}</div><div class="text-xs" style="color:#456882;">${escapeHtml(student.student_id)}</div></div>`;
                                studentGrid.appendChild(label);
                            });
                        } else { studentGrid.innerHTML = '<div class="col-span-full py-4 text-center italic" style="color:#456882;">No active students found in this batch.</div>'; }
                    } else { studentGrid.innerHTML = `<div class="col-span-full py-4 text-center text-red-500 italic">Error: ${escapeHtml(data.error)}</div>`; }
                }).catch(err => { console.error(err); studentGrid.innerHTML = '<div class="col-span-full py-4 text-center text-red-500 italic">Failed to fetch details.</div>'; });
        }
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");
        }
        function handleCategoryChange() {
            const category = document.getElementById('test_category').value;
            const courseContainer = document.getElementById('course_link_container');
            const chapterContainer = document.getElementById('chapter_link_container');
            const startDateContainer = document.getElementById('start_date_container');
            const endDateContainer = document.getElementById('end_date_container');
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');
            if (category === 'chapter_wise') {
                courseContainer.style.display = 'block'; chapterContainer.style.display = 'block';
                startDateContainer.style.display = 'none'; endDateContainer.style.display = 'none';
                startDate.required = false; endDate.required = false; startDate.value = ''; endDate.value = '';
                document.getElementById('start_date_req').classList.add('hidden'); document.getElementById('end_date_req').classList.add('hidden');
            } else if (category === 'weekly') {
                courseContainer.style.display = 'block'; chapterContainer.style.display = 'none';
                startDateContainer.style.display = 'block'; endDateContainer.style.display = 'block';
                document.getElementById('start_date_req').classList.remove('hidden'); document.getElementById('end_date_req').classList.remove('hidden');
                startDate.required = true; endDate.required = true;
            } else {
                courseContainer.style.display = 'block'; chapterContainer.style.display = 'none';
                startDateContainer.style.display = 'block'; endDateContainer.style.display = 'block';
                document.getElementById('start_date_req').classList.add('hidden'); document.getElementById('end_date_req').classList.add('hidden');
                startDate.required = false; endDate.required = false;
            }
        }
        function fetchChapters(courseId) {
            const chapterSelect = document.getElementById('chapter_id');
            chapterSelect.innerHTML = '<option value="">-- Loading Chapters --</option>';
            if (!courseId) { chapterSelect.innerHTML = '<option value="">-- Select Chapter --</option>'; return; }
            fetch(`get_chapters.php?course_id=${courseId}`)
                .then(response => response.json())
                .then(data => {
                    chapterSelect.innerHTML = '<option value="">-- Select Chapter --</option>';
                    data.forEach(chapter => { const option = document.createElement('option'); option.value = chapter.id; option.textContent = `Chapter ${chapter.chapter} - ${chapter.topic_name}`; chapterSelect.appendChild(option); });
                }).catch(err => { console.error('Error fetching chapters:', err); chapterSelect.innerHTML = '<option value="">-- Error loading chapters --</option>'; });
        }
        window.addEventListener('click', e => { document.querySelectorAll('.modal').forEach(m => { if (e.target === m) closeModal(m.id); }); });
    </script>
</body>
</html>