<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

// Get student information with proper joins
$student_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT 
        s.*, 
        c.name as course_name
    FROM students s
    LEFT JOIN courses c ON s.course = c.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get all batches assigned to this student (primary and additional batches)
$batch_ids = [];
if (!empty($student['batch_name'])) $batch_ids[] = $student['batch_name'];
if (!empty($student['batch_name_2'])) $batch_ids[] = $student['batch_name_2'];
if (!empty($student['batch_name_3'])) $batch_ids[] = $student['batch_name_3'];
if (!empty($student['batch_name_4'])) $batch_ids[] = $student['batch_name_4'];

$batches = [];
if (!empty($batch_ids)) {
    // Create placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    
    $batches_query = $db->prepare("
        SELECT 
            b.batch_id,
            b.batch_name,
            b.start_date,
            b.end_date,
            b.time_slot,
            b.mode,
            b.status,
            b.meeting_link,
            b.platform,
            GROUP_CONCAT(c.name SEPARATOR ', ') as course_name,
            t.name as trainer_name,
            b.current_enrollment,
            b.max_students
        FROM batches b
        LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
        LEFT JOIN courses c ON bc.course_id = c.id
        LEFT JOIN trainers t ON b.batch_mentor_id = t.id
        WHERE b.batch_id IN ($placeholders)
        GROUP BY b.batch_id
    ");
    
    $batches_query->execute($batch_ids);
    $batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize batches by type (primary vs additional)
    $primary_batch = null;
    $additional_batches = [];
    
    foreach ($batches as $batch) {
        if ($batch['batch_id'] == $student['batch_name']) {
            $primary_batch = $batch;
        } else {
            $additional_batches[] = $batch;
        }
    }
}
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<style>
/* ─── CSS Variables (Palette) ─── */
:root {
    --primary: #1B3C53;
    --primary-dark: #0f2838;
    --secondary: #234C6A;
    --accent: #456882;
    --neutral: #D2C1B6;
    --neutral-light: #E8E0D9;
    --neutral-bg: #F5F0EB;
    --white: #ffffff;
    --shadow: 0 8px 30px rgba(27, 60, 83, 0.10);
    --shadow-hover: 0 16px 48px rgba(27, 60, 83, 0.18);
    --radius: 1.25rem;
    --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ── Animations ── */
@keyframes fadeUp   { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideIn  { from{transform:translateX(-100%)} to{transform:translateX(0)} }
@keyframes pulse-ring { 0%,100%{box-shadow:0 0 0 0 rgba(27,60,83,.35)} 60%{box-shadow:0 0 0 16px rgba(27,60,83,0)} }
@keyframes float    { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
@keyframes shimmer  { 0%{background-position:-200% 0} 100%{background-position:200% 0} }

.fade-up{ animation: fadeUp .6s ease both; }
.d1{animation-delay:.05s} .d2{animation-delay:.10s} .d3{animation-delay:.15s}
.d4{animation-delay:.20s} .d5{animation-delay:.25s} .d6{animation-delay:.30s}

/* ── Page background ── */
.sp-page {
    background: linear-gradient(165deg, var(--neutral-bg) 0%, #fcf9f7 50%, var(--neutral-light) 100%);
    min-height:100vh;
}

/* ── Mobile Header ── */
.sp-mob-header {
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 60%, var(--accent) 100%);
    box-shadow: 0 4px 24px rgba(27,60,83,.35);
}

/* ── Desktop Header ── */
.sp-desk-header {
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(12px);
    border-bottom: 2px solid var(--neutral-light);
    box-shadow: 0 2px 24px rgba(35,76,106,.06);
}
.sp-desk-title {
    background: linear-gradient(90deg, var(--primary), var(--accent), var(--neutral));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* ── Mobile drawer ── */
.sp-mob-drawer {
    background: linear-gradient(180deg, #0f2838 0%, var(--primary) 50%, #0f2838 100%);
}
.mob-nav-link {
    display:flex;align-items:center;gap:.75rem;
    padding:.75rem 1rem;
    border-radius:.875rem;
    transition:var(--transition);
    color:#9AB5C9;
    font-weight:500;
}
.mob-nav-link:hover { background:rgba(255,255,255,.12); color:#fff; }
.mob-nav-link.mnl-active {
    background:rgba(255,255,255,.18);
    color:#fff;
    font-weight:700;
    box-shadow:0 2px 16px rgba(0,0,0,.25);
}

/* ── Hero profile banner ── */
.sp-hero {
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 45%, var(--accent) 75%, var(--neutral) 100%);
    border-radius: var(--radius);
    position: relative;
    overflow: hidden;
    padding: 2rem 2rem 2.2rem;
    box-shadow: 0 8px 32px rgba(27,60,83,.20);
}
.sp-hero::before {
    content:'';
    position:absolute;inset:0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23fff' fill-opacity='.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity:0.4;
}
.sp-hero::after {
    content:'';
    position:absolute;
    top:-80px;right:-80px;
    width:320px;height:320px;
    border-radius:50%;
    background:rgba(255,255,255,.06);
}
.hero-avatar-wrap { position:relative;z-index:1; }
.hero-avatar {
    width:100px;height:100px;border-radius:50%;
    border:4px solid rgba(255,255,255,.7);
    animation: pulse-ring 3s ease infinite;
    object-fit:cover;
    box-shadow:0 4px 20px rgba(0,0,0,.15);
}
.hero-avatar-placeholder {
    width:100px;height:100px;border-radius:50%;
    background:rgba(255,255,255,.15);
    border:4px solid rgba(255,255,255,.7);
    display:flex;align-items:center;justify-content:center;
    font-size:2.6rem;color:#fff;
    animation: pulse-ring 3s ease infinite;
    box-shadow:0 4px 20px rgba(0,0,0,.10);
}
.hero-badge {
    display:inline-flex;align-items:center;gap:.5rem;
    background:rgba(255,255,255,.18);
    border:1px solid rgba(255,255,255,.25);
    color:#fff;
    font-size:.75rem;font-weight:600;
    padding:.3rem .9rem;
    border-radius:9999px;
    backdrop-filter:blur(6px);
    letter-spacing:0.01em;
    transition:var(--transition);
}
.hero-badge:hover { background:rgba(255,255,255,.25); transform:scale(1.02); }

/* ── Cards ── */
.sp-card {
    background:var(--white);
    border-radius:var(--radius);
    border:1px solid var(--neutral-light);
    box-shadow: var(--shadow);
    transition: var(--transition);
    overflow:hidden;
}
.sp-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
    border-color: var(--accent);
}

.sp-card-header {
    padding: 1rem 1.5rem;
    display:flex;
    align-items:center;
    gap:.75rem;
    font-weight:700;
    font-size:1rem;
    color:var(--white);
    letter-spacing:0.01em;
}
.sp-card-body { padding:1.5rem; }

/* Header colour variants – using our palette */
.ch-violet  { background:linear-gradient(135deg, var(--secondary), var(--primary)); }
.ch-indigo  { background:linear-gradient(135deg, var(--primary), var(--secondary)); }
.ch-cyan    { background:linear-gradient(135deg, var(--accent), var(--secondary)); }
.ch-rose    { background:linear-gradient(135deg, var(--primary), var(--accent)); }
.ch-emerald { background:linear-gradient(135deg, #2A5A3D, var(--accent)); } /* keep green accent */
.ch-amber   { background:linear-gradient(135deg, #8B6F47, #6B4C2A); }      /* keep warm */
.ch-fuchsia { background:linear-gradient(135deg, var(--secondary), var(--neutral)); }

/* ── Section title inside card ── */
.sec-title {
    display:flex;
    align-items:center;
    gap:.6rem;
    font-size:.9rem;
    font-weight:700;
    color:var(--primary);
    padding-bottom:.6rem;
    margin-bottom:1.2rem;
    border-bottom:2px solid var(--neutral-light);
}
.sec-icon {
    width:2rem;height:2rem;border-radius:.6rem;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:.75rem;
    background:var(--neutral-light);
    color:var(--secondary);
}

/* ── Info rows ── */
.info-row {
    display:flex;
    align-items:center;
    gap:.75rem;
    padding:.75rem .9rem;
    border-radius:.875rem;
    border:1px solid #f0ece8;
    transition:var(--transition);
    background:#faf8f6;
}
.info-row:hover {
    border-color:var(--accent);
    background:var(--neutral-bg);
    box-shadow:0 4px 16px rgba(35,76,106,.08);
}
.ir-icon {
    width:2.4rem;height:2.4rem;
    border-radius:.6rem;
    flex-shrink:0;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:.8rem;
}
.ir-label { font-size:.70rem; color:#8b8a88; font-weight:500; letter-spacing:0.02em; margin-bottom:.1rem; }
.ir-val   { font-size:.9rem; color:var(--primary-dark); font-weight:600; }

/* ── Status badges ── */
.sbadge {
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    padding:.25rem .85rem;
    border-radius:9999px;
    font-size:.7rem;
    font-weight:700;
    letter-spacing:0.01em;
}
.sb-green  { background:#d1fae5; color:#065f46; }
.sb-blue   { background:#dbeafe; color:#1e40af; }
.sb-red    { background:#fee2e2; color:#991b1b; }
.sb-yellow { background:#fef3c7; color:#92400e; }
.sb-gray   { background:#f3f4f6; color:#374151; }

/* ── Batch cards ── */
.batch-primary {
    border-radius:1rem;
    background:linear-gradient(135deg, var(--neutral-bg), #fcf9f7);
    border:2px solid var(--accent);
    padding:1.25rem 1.5rem;
    box-shadow:0 2px 12px rgba(69,104,130,.08);
}
.badge-primary-pill {
    display:inline-flex;
    align-items:center;
    gap:.3rem;
    font-size:.65rem;
    font-weight:800;
    letter-spacing:0.05em;
    background:linear-gradient(135deg, var(--primary), var(--accent));
    color:#fff;
    padding:.25rem .8rem;
    border-radius:9999px;
    flex-shrink:0;
    white-space:nowrap;
    text-transform:uppercase;
}
.batch-extra {
    border-radius:1rem;
    background:var(--white);
    border:1.5px solid var(--neutral-light);
    padding:1rem 1.25rem;
    transition:var(--transition);
}
.batch-extra:hover {
    border-color:var(--accent);
    box-shadow:0 8px 24px rgba(69,104,130,.10);
}
.batch-chip {
    background:var(--white);
    border-radius:.6rem;
    padding:.5rem .75rem;
    border:1px solid #e5e2de;
    transition:var(--transition);
}
.batch-chip:hover { background:var(--neutral-bg); border-color:var(--neutral); }
.bc-label { font-size:.65rem; color:#8b8a88; display:block; margin-bottom:.1rem; text-transform:uppercase; letter-spacing:0.02em; }
.bc-val   { font-size:.85rem; color:var(--primary-dark); font-weight:600; }

/* ── Field box (parent/fee) ── */
.field-box {
    display:flex;
    align-items:center;
    gap:.75rem;
    background:var(--white);
    border:1.5px solid #e5e2de;
    border-radius:.875rem;
    padding:.7rem 1rem;
    font-size:.88rem;
    color:var(--primary-dark);
    transition:var(--transition);
}
.field-box:hover { border-color:var(--accent); background:var(--neutral-bg); }

/* ── Join class button ── */
.join-btn {
    display:inline-flex;
    align-items:center;
    gap:.4rem;
    padding:.45rem 1.2rem;
    border-radius:9999px;
    background:linear-gradient(135deg, var(--primary), var(--accent));
    color:#fff;
    font-size:.8rem;
    font-weight:700;
    transition:opacity .2s,transform .15s,box-shadow .2s;
    box-shadow:0 2px 8px rgba(27,60,83,.20);
}
.join-btn:hover { opacity:.92; transform:scale(1.04); box-shadow:0 4px 16px rgba(27,60,83,.30); }

/* ── Remarks ── */
.remarks-box {
    background:linear-gradient(135deg, var(--neutral-bg), var(--neutral-light));
    border:1.5px solid var(--neutral);
    border-radius:1rem;
    padding:1.2rem 1.5rem;
    position:relative;
}
.remarks-box::before {
    content:'"';
    position:absolute;
    top:.25rem;
    left:.9rem;
    font-size:3rem;
    color:var(--neutral);
    line-height:1;
    opacity:0.6;
}

/* ── Password box ── */
.pwd-box {
    display:flex;
    align-items:flex-start;
    gap:.75rem;
    background:linear-gradient(135deg, var(--neutral-bg), #fcf9f7);
    border:1.5px solid var(--neutral);
    border-radius:1rem;
    padding:1rem 1.25rem;
}

/* ── Empty state ── */
.empty-state { text-align:center; padding:2.5rem 1rem; }
.empty-icon  { font-size:3.2rem; margin-bottom:.75rem; animation:float 3s ease infinite; }

/* ── Scrollbar ── */
::-webkit-scrollbar{width:6px}
::-webkit-scrollbar-track{background:var(--neutral-bg)}
::-webkit-scrollbar-thumb{background:var(--accent); border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--secondary)}

/* ── Responsive tweaks ── */
@media(max-width:768px){
    .sp-hero{padding:1.25rem;}
    .hero-avatar,.hero-avatar-placeholder{width:76px;height:76px;}
    .sp-card-body { padding:1rem; }
}
</style>

<!-- ═══════════════════════════════════════════ MAIN WRAPPER ═══ -->
<div class="flex-1 ml-0 md:ml-64 sp-page">

    <!-- ── Mobile Header ── -->
    <header class="sp-mob-header px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden">
        <button class="text-white text-xl" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-white font-bold text-base flex items-center gap-2">
            <i class="fas fa-user-circle"></i> My Profile
        </h1>
        <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
            <i class="fas fa-user-graduate text-white text-sm"></i>
        </div>
    </header>

    <!-- ── Desktop Header ── -->
    <header class="sp-desk-header hidden md:flex px-6 py-3 justify-between items-center sticky top-0 z-30">
        <div class="flex-1"></div>
        <h1 class="text-xl font-extrabold sp-desk-title flex items-center gap-2">
            <i class="fas fa-user-circle" style="color:#234C6A;-webkit-text-fill-color:#234C6A;"></i>
            My Profile
        </h1>
        <div class="flex-1 flex justify-end items-center gap-3">
            <span class="text-xs font-semibold px-3 py-1.5 rounded-full" style="background:#E8E0D9;color:#1B3C53;">
                <i class="fas fa-calendar-day mr-1"></i><?= date('l, F j, Y') ?>
            </span>
            <div class="w-9 h-9 rounded-full flex items-center justify-center" style="background:linear-gradient(135deg,#234C6A,#D2C1B6);">
                <i class="fas fa-user text-white text-sm"></i>
            </div>
        </div>
    </header>

    <!-- ── Mobile Navigation Drawer ── -->
    <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-60 z-40 hidden md:hidden">
        <div class="sp-mob-drawer absolute left-0 top-0 h-full w-4/5 max-w-xs shadow-2xl transform transition-transform duration-300 -translate-x-full">

            <!-- Drawer header -->
            <div class="p-4 border-b border-white/10">
                <div class="flex items-center justify-between">
                    <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                    <button onclick="toggleSidebar()" class="text-white/70 hover:text-white text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mt-4 flex items-center gap-3">
                    <div class="w-11 h-11 rounded-full flex items-center justify-center text-white font-bold text-lg" style="background:linear-gradient(135deg,#456882,#D2C1B6);">
                        <?= strtoupper(substr($student['first_name'] ?? 'S', 0, 1)) ?>
                    </div>
                    <div>
                        <p class="font-semibold text-white text-sm"><?= htmlspecialchars($student['first_name'] ?? 'Student') ?> <?= htmlspecialchars($student['last_name'] ?? '') ?></p>
                        <p class="text-xs" style="color:#9AB5C9;">Student</p>
                    </div>
                </div>
            </div>

            <!-- Nav links -->
            <nav class="p-4 space-y-1 overflow-y-auto">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>

                <a href="../stu_dash/dashboard.php" onclick="toggleSidebar()"
                   class="mob-nav-link <?= $current_page=='dashboard.php'?'mnl-active':'' ?>">
                    <i class="fas fa-tachometer-alt w-5 text-center"></i><span>Dashboard</span>
                </a>
                <a href="../stu_dash/my_batches.php" onclick="toggleSidebar()"
                   class="mob-nav-link <?= $current_page=='my_batches.php'?'mnl-active':'' ?>">
                    <i class="fas fa-users w-5 text-center"></i><span>My Batches</span>
                </a>
                <a href="../stu_dash/upcoming.php" onclick="toggleSidebar()"
                   class="mob-nav-link <?= $current_page=='upcoming.php'?'mnl-active':'' ?>">
                    <i class="fas fa-calendar-alt w-5 text-center"></i><span>Upcoming Schedule</span>
                </a>
                <a href="../stu_dash/my_content.php" onclick="toggleSidebar()"
                   class="mob-nav-link <?= $current_page=='my_content.php'?'mnl-active':'' ?>">
                    <i class="fas fa-book w-5 text-center"></i><span>My Content</span>
                </a>
                <a href="../student_test/student_dashboard.php" onclick="toggleSidebar()"
                   class="mob-nav-link <?= $current_page=='student_dashboard.php'?'mnl-active':'' ?>">
                    <i class="fas fa-vial w-5 text-center"></i><span>Test</span>
                </a>
                <a href="../stu_dash/my_performance.php" onclick="toggleSidebar()"
                   class="mob-nav-link <?= $current_page=='my_performance.php'?'mnl-active':'' ?>">
                    <i class="fas fa-chart-line w-5 text-center"></i><span>My Performance</span>
                </a>
                <a href="../stu_dash/student_feedback.php" onclick="toggleSidebar()"
                   class="mob-nav-link <?= $current_page=='student_feedback.php'?'mnl-active':'' ?>">
                    <i class="fas fa-comment-dots w-5 text-center"></i><span>Feedback</span>
                </a>
                <a href="../stu_dash/student_profile.php" onclick="toggleSidebar()"
                   class="mob-nav-link <?= $current_page=='student_profile.php'?'mnl-active':'' ?>">
                    <i class="fas fa-user-circle w-5 text-center"></i><span>My Profile</span>
                </a>
                <div class="border-t border-white/10 pt-3 mt-3">
                    <a href="../logout.php" onclick="toggleSidebar()" class="mob-nav-link" style="color:#fca5a5;">
                        <i class="fas fa-sign-out-alt w-5 text-center"></i><span>Logout</span>
                    </a>
                </div>
            </nav>
        </div>
    </div>

    <!-- ═══════════════════ PAGE CONTENT ═══════════════════ -->
    <div class="p-4 md:p-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ════════ LEFT / MAIN COLUMN ════════ -->
            <div class="lg:col-span-2 space-y-6">

                <!-- ── Hero Profile Card ── -->
                <div class="sp-hero fade-up d1">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5 relative z-10">
                        <!-- Avatar -->
                        <div class="hero-avatar-wrap">
                            <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                <img src="<?= htmlspecialchars($student['profile_picture']) ?>"
                                     alt="Profile Picture" class="hero-avatar">
                            <?php else: ?>
                                <div class="hero-avatar-placeholder">
                                    <?= strtoupper(substr($student['first_name'] ?? 'S', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Name / meta -->
                        <div class="flex-1">
                            <h2 class="text-2xl md:text-3xl font-extrabold text-white leading-tight">
                                <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                            </h2>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <span class="hero-badge">
                                    <i class="fas fa-id-badge"></i>
                                    <?= htmlspecialchars($student['student_id']) ?>
                                </span>
                                <span class="hero-badge">
                                    <i class="fas fa-book-open"></i>
                                    <?= htmlspecialchars($student['course_name'] ?? 'No course') ?>
                                </span>
                                <?php
                                    $st = $student['current_status'] ?? '';
                                    $stColor = $st==='active'?'#d1fae5;color:#065f46':($st==='completed'?'#dbeafe;color:#1e40af':($st==='dropped'?'#fee2e2;color:#991b1b':'#fef3c7;color:#92400e'));
                                ?>
                                <span class="hero-badge" style="background:<?= $stColor ?>;">
                                    <i class="fas fa-circle" style="font-size:.5rem;"></i>
                                    <?= ucfirst($st) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Personal & Academic Info Card ── -->
                <div class="sp-card fade-up d2">
                    <div class="sp-card-header ch-indigo">
                        <i class="fas fa-address-card"></i>
                        <span>Personal & Academic Information</span>
                    </div>
                    <div class="sp-card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                            <!-- Personal Info -->
                            <div>
                                <div class="sec-title">
                                    <span class="sec-icon"><i class="fas fa-user-tag"></i></span>
                                    Personal Information
                                </div>
                                <div class="space-y-3">
                                    <div class="info-row">
                                        <span class="ir-icon" style="background:#E8E0D9;color:#234C6A;"><i class="fas fa-envelope"></i></span>
                                        <div><div class="ir-label">Email</div><div class="ir-val"><?= htmlspecialchars($student['email']) ?></div></div>
                                    </div>
                                    <div class="info-row">
                                        <span class="ir-icon" style="background:#DAE8E0;color:#2A5A3D;"><i class="fas fa-phone"></i></span>
                                        <div><div class="ir-label">Phone</div><div class="ir-val"><?= htmlspecialchars($student['phone_number'] ?? 'Not set') ?></div></div>
                                    </div>
                                    <div class="info-row">
                                        <span class="ir-icon" style="background:#EDE3D4;color:#8B6F47;"><i class="fas fa-birthday-cake"></i></span>
                                        <div><div class="ir-label">Date of Birth</div><div class="ir-val"><?= $student['date_of_birth'] ? date('M j, Y', strtotime($student['date_of_birth'])) : 'Not set' ?></div></div>
                                    </div>
                                    <div class="info-row">
                                        <span class="ir-icon" style="background:#D4E2ED;color:#234C6A;"><i class="fas fa-calendar-plus"></i></span>
                                        <div><div class="ir-label">Enrollment Date</div><div class="ir-val"><?= date('M j, Y', strtotime($student['enrollment_date'])) ?></div></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Academic Info -->
                            <div>
                                <div class="sec-title">
                                    <span class="sec-icon"><i class="fas fa-graduation-cap"></i></span>
                                    Academic Information
                                </div>
                                <div class="space-y-3">
                                    <div class="info-row">
                                        <span class="ir-icon" style="background:#EDE3D4;color:#6B4C2A;"><i class="fas fa-id-card"></i></span>
                                        <div><div class="ir-label">Student ID</div><div class="ir-val"><?= htmlspecialchars($student['student_id']) ?></div></div>
                                    </div>
                                    <div class="info-row">
                                        <span class="ir-icon" style="background:#DAE8E0;color:#2A5A3D;"><i class="fas fa-chart-line"></i></span>
                                        <div>
                                            <div class="ir-label">Current Status</div>
                                            <div class="ir-val">
                                                <?php
                                                    $status = $student['current_status'];
                                                    $cls = $status==='active'?'sb-green':($status==='completed'?'sb-blue':($status==='dropped'?'sb-red':'sb-yellow'));
                                                ?>
                                                <span class="sbadge <?= $cls ?>">
                                                    <i class="fas fa-circle" style="font-size:.45rem;"></i>
                                                    <?= ucfirst($status) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <span class="ir-icon" style="background:#E8E0D9;color:#234C6A;"><i class="fas fa-book"></i></span>
                                        <div><div class="ir-label">Course</div><div class="ir-val"><?= htmlspecialchars($student['course_name'] ?? 'Not assigned') ?></div></div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ── Batches Card ── -->
                <?php if (!empty($batches)): ?>
                <div class="sp-card fade-up d3">
                    <div class="sp-card-header ch-fuchsia">
                        <i class="fas fa-layer-group"></i>
                        <span>My Batches <span style="background:rgba(255,255,255,.25);padding:.1rem .55rem;border-radius:9999px;font-size:.8rem;"><?= count($batches) ?></span></span>
                    </div>
                    <div class="sp-card-body space-y-4">

                        <!-- Primary Batch -->
                        <?php if ($primary_batch): ?>
                        <div class="batch-primary">
                            <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
                                <h4 class="font-bold flex items-center gap-2 text-sm" style="color:#1B3C53;">
                                    <i class="fas fa-star" style="color:#8B6F47;"></i> Primary Batch
                                </h4>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="badge-primary-pill">★ PRIMARY</span>
                                    <?php
                                        $bs = $primary_batch['status'];
                                        $bc = $bs=='ongoing'?'sb-green':($bs=='upcoming'?'sb-yellow':($bs=='completed'?'sb-blue':'sb-gray'));
                                    ?>
                                    <span class="sbadge <?= $bc ?>"><?= ucfirst($bs) ?></span>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                <div class="batch-chip">
                                    <span class="bc-label">Batch Name</span>
                                    <span class="bc-val"><?= htmlspecialchars($primary_batch['batch_name']) ?></span>
                                </div>
                                <div class="batch-chip">
                                    <span class="bc-label">Batch ID</span>
                                    <span class="bc-val"><?= htmlspecialchars($primary_batch['batch_id']) ?></span>
                                </div>
                                <div class="batch-chip">
                                    <span class="bc-label">Course</span>
                                    <span class="bc-val"><?= htmlspecialchars($primary_batch['course_name'] ?: '—') ?></span>
                                </div>
                                <div class="batch-chip">
                                    <span class="bc-label">Schedule</span>
                                    <span class="bc-val"><?= htmlspecialchars($primary_batch['time_slot'] ?: 'Not Specified') ?></span>
                                </div>
                                <div class="batch-chip">
                                    <span class="bc-label">Mode</span>
                                    <span class="bc-val"><?= ucfirst($primary_batch['mode']) ?></span>
                                </div>
                                <div class="batch-chip">
                                    <span class="bc-label">Trainer</span>
                                    <span class="bc-val"><?= htmlspecialchars($primary_batch['trainer_name'] ?? 'Not assigned') ?></span>
                                </div>
                                <div class="batch-chip col-span-2">
                                    <span class="bc-label">Duration</span>
                                    <span class="bc-val"><?= date('M j, Y', strtotime($primary_batch['start_date'])) ?> — <?= date('M j, Y', strtotime($primary_batch['end_date'])) ?></span>
                                </div>
                                <div class="batch-chip">
                                    <span class="bc-label">Platform</span>
                                    <span class="bc-val"><?= htmlspecialchars($primary_batch['platform'] ?? '—') ?></span>
                                </div>
                                <?php if ($primary_batch['meeting_link']): ?>
                                <div class="batch-chip col-span-2 md:col-span-3 flex items-center gap-3">
                                    <span class="bc-label" style="margin:0;">Meeting</span>
                                    <a href="<?= htmlspecialchars($primary_batch['meeting_link']) ?>" target="_blank" class="join-btn">
                                        <i class="fas fa-video"></i> Join Class
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Additional Batches -->
                        <?php if (!empty($additional_batches)): ?>
                        <div>
                            <div class="sec-title mt-2">
                                <span class="sec-icon"><i class="fas fa-plus-circle"></i></span>
                                Additional Batches
                            </div>
                            <div class="space-y-3">
                            <?php foreach ($additional_batches as $index => $batch): ?>
                            <div class="batch-extra">
                                <div class="flex items-center justify-between mb-3">
                                    <h5 class="font-bold text-sm" style="color:#1B3C53;">
                                        Batch <?= $index + 1 ?>: <?= htmlspecialchars($batch['batch_name']) ?>
                                    </h5>
                                    <?php
                                        $bs2 = $batch['status'];
                                        $bc2 = $bs2=='ongoing'?'sb-green':($bs2=='upcoming'?'sb-yellow':($bs2=='completed'?'sb-blue':'sb-gray'));
                                    ?>
                                    <span class="sbadge <?= $bc2 ?>"><?= ucfirst($bs2) ?></span>
                                </div>
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm">
                                    <div class="batch-chip">
                                        <span class="bc-label">Batch ID</span>
                                        <span class="bc-val"><?= htmlspecialchars($batch['batch_id']) ?></span>
                                    </div>
                                    <div class="batch-chip">
                                        <span class="bc-label">Schedule</span>
                                        <span class="bc-val"><?= htmlspecialchars($batch['time_slot'] ?: 'Not Specified') ?></span>
                                    </div>
                                    <div class="batch-chip">
                                        <span class="bc-label">Mode</span>
                                        <span class="bc-val"><?= ucfirst($batch['mode']) ?></span>
                                    </div>
                                    <div class="batch-chip">
                                        <span class="bc-label">Trainer</span>
                                        <span class="bc-val"><?= htmlspecialchars($batch['trainer_name'] ?? 'Not assigned') ?></span>
                                    </div>
                                    <div class="batch-chip col-span-2">
                                        <span class="bc-label">Duration</span>
                                        <span class="bc-val"><?= date('M j, Y', strtotime($batch['start_date'])) ?> — <?= date('M j, Y', strtotime($batch['end_date'])) ?></span>
                                    </div>
                                    <?php if ($batch['meeting_link']): ?>
                                    <div class="batch-chip col-span-2 md:col-span-3 flex items-center gap-3">
                                        <span class="bc-label" style="margin:0;">Meeting</span>
                                        <a href="<?= htmlspecialchars($batch['meeting_link']) ?>" target="_blank" class="join-btn">
                                            <i class="fas fa-video"></i> Join Class
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
                <?php else: ?>
                <div class="sp-card fade-up d3">
                    <div class="sp-card-header ch-fuchsia"><i class="fas fa-layer-group"></i><span>My Batches</span></div>
                    <div class="sp-card-body">
                        <div class="empty-state">
                            <div class="empty-icon">🎓</div>
                            <p class="text-gray-400 font-medium">No batches assigned yet.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /left column -->

            <!-- ════════ RIGHT COLUMN ════════ -->
            <div class="space-y-6">

                <!-- ── Parent Information ── -->
                <div class="sp-card fade-up d4">
                    <div class="sp-card-header ch-cyan">
                        <i class="fas fa-users"></i>
                        <span>Parent Information</span>
                    </div>
                    <div class="sp-card-body space-y-3">
                        <div>
                            <label class="block text-xs font-semibold mb-1.5" style="color:#234C6A;">Father's Name</label>
                            <div class="field-box"><i class="fas fa-user-friends" style="color:#456882;"></i><?= htmlspecialchars($student['father_name'] ?? 'Not provided') ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1.5" style="color:#234C6A;">Father's Phone</label>
                            <div class="field-box"><i class="fas fa-phone" style="color:#456882;"></i><?= htmlspecialchars($student['father_phone_number'] ?? 'Not provided') ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1.5" style="color:#234C6A;">Father's Email</label>
                            <div class="field-box"><i class="fas fa-envelope" style="color:#234C6A;"></i><?= htmlspecialchars($student['father_email'] ?? 'Not provided') ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1.5" style="color:#234C6A;">State</label>
                            <div class="field-box"><i class="fas fa-map-marker-alt" style="color:#1B3C53;"></i><?= htmlspecialchars($student['state'] ?? 'Not provided') ?></div>
                        </div>
                    </div>
                </div>

                <!-- ── Fee Information ── -->
                <div class="sp-card fade-up d5">
                    <div class="sp-card-header ch-emerald">
                        <i class="fas fa-rupee-sign"></i>
                        <span>Fee Information</span>
                    </div>
                    <div class="sp-card-body space-y-3">
                        <div>
                            <label class="block text-xs font-semibold mb-1.5" style="color:#2A5A3D;">Enrollment Fees</label>
                            <div class="field-box"><i class="fas fa-money-bill-wave" style="color:#2A5A3D;"></i>
                                <span class="font-bold" style="color:#065f46;">₹<?= number_format($student['enrollment_fees'] ?? 0, 2) ?></span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1.5" style="color:#2A5A3D;">Fees Status</label>
                            <div class="field-box">
                                <?php
                                    $fee_status = $student['fees_status'] ?? 'unpaid';
                                    $fsc = $fee_status==='fully_paid'?'sb-green':($fee_status==='partially_paid'?'sb-blue':($fee_status==='overdue'?'sb-red':'sb-yellow'));
                                ?>
                                <span class="sbadge <?= $fsc ?>">
                                    <i class="fas fa-circle" style="font-size:.45rem;"></i>
                                    <?= ucfirst(str_replace('_', ' ', $fee_status)) ?>
                                </span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1.5" style="color:#2A5A3D;">Total Fees Paid</label>
                            <div class="field-box"><i class="fas fa-check-circle" style="color:#2A5A3D;"></i>
                                <span class="font-bold" style="color:#065f46;">₹<?= number_format($student['total_fees_paid'] ?? 0, 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Remarks & Progress ── -->
                <?php if (!empty($student['bio'])): ?>
                <div class="sp-card fade-up d5">
                    <div class="sp-card-header ch-rose">
                        <i class="fas fa-comment-dots"></i>
                        <span>Remarks & Progress</span>
                    </div>
                    <div class="sp-card-body">
                        <div class="remarks-box">
                            <p class="text-gray-700 whitespace-pre-wrap leading-relaxed text-sm pt-6"><?= htmlspecialchars($student['bio']) ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ── Password Information ── -->
                <div class="sp-card fade-up d6">
                    <div class="sp-card-header ch-violet">
                        <i class="fas fa-lock"></i>
                        <span>Password Information</span>
                    </div>
                    <div class="sp-card-body">
                        <div class="pwd-box">
                            <i class="fas fa-info-circle text-blue-500 mt-0.5 text-lg flex-shrink-0"></i>
                            <p class="text-blue-800 text-sm leading-relaxed">
                                To change your password, please contact the administration.
                            </p>
                        </div>
                    </div>
                </div>

            </div><!-- /right column -->
        </div>
    </div><!-- /page content -->

</div><!-- /main wrapper -->

<script>
function toggleMobileMenu() {
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileMenuContent = mobileMenu.querySelector('div');
    if (mobileMenu.classList.contains('hidden')) {
        mobileMenu.classList.remove('hidden');
        setTimeout(() => { mobileMenuContent.classList.remove('-translate-x-full'); }, 10);
    } else {
        mobileMenuContent.classList.add('-translate-x-full');
        setTimeout(() => { mobileMenu.classList.add('hidden'); }, 300);
    }
}

document.getElementById('mobileMenu').addEventListener('click', function(e) {
    if (e.target.id === 'mobileMenu') toggleMobileMenu();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const m = document.getElementById('mobileMenu');
        if (!m.classList.contains('hidden')) toggleMobileMenu();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const currentPage = '<?= basename($_SERVER['PHP_SELF']) ?>';
    document.querySelectorAll('.mob-nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href && href.includes(currentPage)) link.classList.add('mnl-active');
    });
});

function toggleSidebar() {
    const sidebar = document.querySelector('.md\\:ml-64');
    if (sidebar.classList.contains('ml-0')) {
        sidebar.classList.remove('ml-0');
        sidebar.classList.add('ml-64');
    } else {
        sidebar.classList.remove('ml-64');
        sidebar.classList.add('ml-0');
    }
}
</script>

<?php include '../footer.php'; ?>