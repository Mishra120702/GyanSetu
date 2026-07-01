<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// PAGINATION CONFIGURATION
// ============================================================
// Get records per page from session or use default
if (!isset($_SESSION['content_records_per_page'])) {
    $_SESSION['content_records_per_page'] = 8;
}

// Allow user to change records per page
if (isset($_POST['records_per_page'])) {
    $_SESSION['content_records_per_page'] = (int)$_POST['records_per_page'];
    header("Location: upload_content.php?page=1");
    exit;
}

$records_per_page = $_SESSION['content_records_per_page'];
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $records_per_page;

// Handle Content Visibility Assignment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_visibility') {
    $course_id = $_POST['course_id'];
    $batch_ids = isset($_POST['batch_ids']) ? $_POST['batch_ids'] : [];

    try {
        $db->beginTransaction();

        // 1. Update course_content_visibility table
        $stmt = $db->prepare("DELETE FROM course_content_visibility WHERE course_id = ?");
        $stmt->execute([$course_id]);

        if (!empty($batch_ids)) {
            $insert_stmt = $db->prepare("INSERT INTO course_content_visibility (course_id, batch_id) VALUES (?, ?)");
            foreach ($batch_ids as $batch_id) {
                $insert_stmt->execute([$course_id, $batch_id]);
            }
        }

        // 2. Sync batch_uploads based on the new visibility
        $stmt_del = $db->prepare("DELETE FROM batch_uploads WHERE course_id = ?");
        $stmt_del->execute([$course_id]);

        if (!empty($batch_ids)) {
            $sync_stmt = $db->prepare("
                INSERT INTO batch_uploads (upload_id, batch_id, course_id)
                SELECT u.id, ccv.batch_id, u.course_id
                FROM uploads u
                JOIN course_content_visibility ccv ON u.course_id = ccv.course_id
                WHERE u.course_id = ?
            ");
            $sync_stmt->execute([$course_id]);
        }

        $db->commit();
        $_SESSION['success_message'] = "Content visibility updated successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error_message'] = "Error updating visibility: " . $e->getMessage();
    }
    
    header("Location: upload_content.php?page=" . $page);
    exit;
}

// ============================================================
// FETCH COURSES WITH PAGINATION
// ============================================================

// Get total number of courses
$total_stmt = $db->query("SELECT COUNT(*) FROM courses");
$total_courses = $total_stmt->fetchColumn();
$total_pages = ceil($total_courses / $records_per_page);

// Ensure page doesn't exceed total pages
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $records_per_page;
}

// Fetch courses with pagination
$courses_query = $db->prepare("SELECT * FROM courses ORDER BY name ASC LIMIT :limit OFFSET :offset");
$courses_query->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$courses_query->bindValue(':offset', $offset, PDO::PARAM_INT);
$courses_query->execute();
$courses = $courses_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch all batches (for visibility modal)
$batches_query = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name ASC");
$batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing course_content_visibility mapping
$content_visibility_query = $db->query("SELECT batch_id, course_id FROM course_content_visibility");
$content_visibility_raw = $content_visibility_query->fetchAll(PDO::FETCH_ASSOC);

$course_visibility_mapping = [];
foreach ($content_visibility_raw as $mapping) {
    if (!isset($course_visibility_mapping[$mapping['course_id']])) {
        $course_visibility_mapping[$mapping['course_id']] = [];
    }
    $course_visibility_mapping[$mapping['course_id']][] = $mapping['batch_id'];
}

// ── Visual theming: deterministic color/icon per course so folders are distinguishable ──
$folder_themes = [
    ['grad' => 'from-violet-500 to-purple-600',  'soft' => 'bg-violet-50',  'text' => 'text-violet-600',  'ring' => 'border-violet-200', 'icon' => 'fa-folder'],
    ['grad' => 'from-sky-500 to-blue-600',       'soft' => 'bg-sky-50',     'text' => 'text-sky-600',     'ring' => 'border-sky-200',    'icon' => 'fa-folder'],
    ['grad' => 'from-emerald-500 to-teal-600',   'soft' => 'bg-emerald-50','text' => 'text-emerald-600', 'ring' => 'border-emerald-200','icon' => 'fa-folder'],
    ['grad' => 'from-amber-500 to-orange-600',   'soft' => 'bg-amber-50',  'text' => 'text-amber-600',   'ring' => 'border-amber-200',  'icon' => 'fa-folder'],
    ['grad' => 'from-rose-500 to-pink-600',      'soft' => 'bg-rose-50',   'text' => 'text-rose-600',    'ring' => 'border-rose-200',   'icon' => 'fa-folder'],
    ['grad' => 'from-cyan-500 to-sky-600',       'soft' => 'bg-cyan-50',   'text' => 'text-cyan-600',    'ring' => 'border-cyan-200',   'icon' => 'fa-folder'],
    ['grad' => 'from-fuchsia-500 to-purple-600', 'soft' => 'bg-fuchsia-50','text' => 'text-fuchsia-600', 'ring' => 'border-fuchsia-200','icon' => 'fa-folder'],
    ['grad' => 'from-lime-500 to-green-600',     'soft' => 'bg-lime-50',   'text' => 'text-lime-600',    'ring' => 'border-lime-200',   'icon' => 'fa-folder'],
];
function folder_theme($name, $themes) {
    $idx = crc32($name) % count($themes);
    return $themes[$idx];
}

// ── Summary stats ──────────────────────────────────────
$total_batches = count($batches);
$courses_with_visibility = count($course_visibility_mapping);
$courses_without_visibility = $total_courses - $courses_with_visibility;
$total_visibility_links = array_sum(array_map('count', $course_visibility_mapping));

$page_title = "Content Management - Folders";
?>

<?php include '../header.php'; ?>
<?php include '../sidebar.php'; ?>

<!-- ================================================================
   BRAND PALETTE & STYLES - Matching index.php
   ================================================================ -->
<style>
    :root {
        --deepest-navy: #1B3C53;
        --dark-steel: #234C6A;
        --mid-steel: #456882;
        --warm-sand: #D2C1B6;
        --soft-sky: #A4C4D4;
        --white: #ffffff;
        --danger-red: #C0392B;
        --danger-light: #ef4444;
        --terracotta: #C97B50;
        --amber: #f59e0b;
        --success-green: #166534;
        --upload-purple: #7C5CBF;
    }

    * {
        transition: all 0.25s ease;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background:
            radial-gradient(1100px 500px at 100% -8%, rgba(69,104,130,.22), transparent 55%),
            radial-gradient(900px 450px at -10% 108%, rgba(27,60,83,.16), transparent 55%),
            radial-gradient(rgba(27,60,83,.045) 1px, transparent 1px) 0 0 / 22px 22px,
            linear-gradient(165deg, #e8e2db 0%, #e4ddd5 44%, #d9e3ec 100%);
        background-attachment: fixed;
        min-height: 100vh;
        -webkit-font-smoothing: antialiased;
    }

    .glass-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .glass-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 32px rgba(27,60,83,.18);
    }

    .folder-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .folder-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--mid-steel);
        border-radius: 4px 0 0 4px;
        z-index: 2;
    }

    .folder-card::after {
        content: '';
        position: absolute;
        inset: -2px;
        border-radius: 18px;
        background: conic-gradient(
            var(--deepest-navy), var(--dark-steel), var(--mid-steel),
            var(--warm-sand), var(--soft-sky), var(--deepest-navy)
        );
        z-index: -1;
        opacity: 0;
        transition: opacity 0.5s ease;
        animation: conicSpin 6s linear infinite;
        animation-play-state: paused;
    }

    .folder-card:hover::after {
        opacity: 0.35;
        animation-play-state: running;
    }

    @keyframes conicSpin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .folder-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(27,60,83,.15);
    }

    .stat-card {
        background: var(--white);
        border-radius: 16px;
        padding: 1.25rem;
        box-shadow: 0 4px 20px rgba(27,60,83,.13);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--mid-steel);
        border-radius: 4px 0 0 4px;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 32px rgba(27,60,83,.18);
    }

    .stat-card .stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .stat-card .stat-icon.purple {
        background: rgba(99, 102, 241, 0.12);
        color: #6366f1;
    }

    .stat-card .stat-icon.sky {
        background: rgba(56, 189, 248, 0.12);
        color: #0ea5e9;
    }

    .stat-card .stat-icon.emerald {
        background: rgba(52, 211, 153, 0.12);
        color: #10b981;
    }

    .stat-card .stat-icon.amber {
        background: rgba(251, 191, 36, 0.12);
        color: #f59e0b;
    }

    .stat-card .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--deepest-navy);
        line-height: 1.2;
    }

    .stat-card .stat-label {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--mid-steel);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* Hero Banner */
    .hero-banner {
        background: linear-gradient(135deg, var(--deepest-navy) 0%, var(--dark-steel) 45%, var(--mid-steel) 100%);
        color: var(--white);
        border-radius: 16px;
        padding: 1.5rem 2rem;
        box-shadow: 0 4px 24px rgba(27,60,83,.2);
    }

    .hero-banner h1 {
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .badge-brand {
        background: var(--deepest-navy);
        color: var(--white);
        border-radius: 9999px;
        padding: 0.5rem 1.25rem;
        font-weight: 600;
        font-size: 0.85rem;
        box-shadow: 0 3px 10px rgba(27,60,83,.2);
    }

    .btn-brand {
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 0.6rem 1.5rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: none;
        cursor: pointer;
        letter-spacing: 0.01em;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none;
    }

    .btn-brand:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,.15);
        text-decoration: none;
    }

    .btn-primary-brand {
        background: linear-gradient(135deg, var(--amber), var(--terracotta));
        color: var(--white);
        box-shadow: 0 4px 14px rgba(201,123,80,.35);
    }
    .btn-primary-brand:hover {
        box-shadow: 0 8px 24px rgba(201,123,80,.45);
        color: var(--white);
    }

    /* Alert styles matching index.php */
    .alert-brand {
        border-radius: 14px;
        border: none;
        padding: 1rem 1.25rem;
        font-weight: 500;
        box-shadow: 0 4px 16px rgba(0,0,0,.06);
    }

    .alert-success-brand {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        border-left: 5px solid var(--success-green);
        color: var(--success-green);
    }

    .alert-error-brand {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
        border-left: 5px solid var(--danger-red);
        color: var(--danger-red);
    }

    /* Modal styles matching index.php */
    .modal-backdrop-custom {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        z-index: 1040;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .modal-backdrop-custom.active {
        display: flex;
    }

    .modal-brand {
        width: 100%;
        max-width: 500px;
        animation: modalSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: scale(0.9) translateY(20px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }

    .modal-brand .modal-content {
        border-radius: 20px;
        border: none;
        box-shadow: 0 30px 60px rgba(0,0,0,.3);
        overflow: hidden;
        background: var(--white);
    }

    .modal-brand .modal-header {
        background: linear-gradient(135deg, var(--deepest-navy), var(--dark-steel), var(--mid-steel));
        color: var(--white);
        border-bottom: none;
        padding: 1.25rem 1.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .modal-brand .modal-header .btn-close {
        background: transparent;
        border: none;
        font-size: 1.5rem;
        color: var(--white);
        opacity: 0.8;
        cursor: pointer;
        padding: 0.25rem 0.5rem;
        border-radius: 50%;
        transition: all 0.2s ease;
    }

    .modal-brand .modal-header .btn-close:hover {
        opacity: 1;
        background: rgba(255,255,255,0.1);
        transform: rotate(90deg);
    }

    .modal-brand .modal-body {
        padding: 1.5rem 1.75rem;
        max-height: 60vh;
        overflow-y: auto;
    }

    .modal-brand .modal-footer {
        padding: 1rem 1.75rem 1.5rem 1.75rem;
        border-top: 1px solid rgba(210,193,182,.25);
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        background: #faf8f7;
    }

    .modal-brand .btn-secondary-brand {
        background: linear-gradient(135deg, #EAE4E0, var(--warm-sand));
        color: var(--deepest-navy);
        box-shadow: 0 4px 14px rgba(210,193,182,.35);
        border-radius: 9999px;
        font-weight: 600;
        padding: 0.6rem 1.5rem;
        border: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
    }
    .modal-brand .btn-secondary-brand:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(210,193,182,.45);
    }

    .modal-brand .btn-success-brand {
        background: linear-gradient(135deg, var(--mid-steel), var(--dark-steel));
        color: var(--white);
        box-shadow: 0 4px 14px rgba(35,76,106,.35);
        border-radius: 9999px;
        font-weight: 600;
        padding: 0.6rem 1.5rem;
        border: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
    }
    .modal-brand .btn-success-brand:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(35,76,106,.45);
    }

    .modal-brand .btn-success-brand i,
    .modal-brand .btn-secondary-brand i {
        margin-right: 0.5rem;
    }

    .checkbox-custom {
        width: 1.25rem;
        height: 1.25rem;
        border-radius: 4px;
        border: 2px solid var(--warm-sand);
        transition: all 0.2s ease;
        cursor: pointer;
        accent-color: var(--deepest-navy);
        flex-shrink: 0;
    }

    .checkbox-custom:checked {
        border-color: var(--deepest-navy);
    }

    .checkbox-label {
        transition: all 0.2s ease;
        border-radius: 12px;
        border: 2px solid rgba(210,193,182,.3);
        padding: 0.75rem 1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        background: var(--white);
    }

    .checkbox-label:hover {
        border-color: var(--dark-steel);
        background: rgba(35,76,106,.04);
        transform: translateX(2px);
    }

    .checkbox-label:has(input:checked) {
        border-color: var(--deepest-navy);
        background: rgba(27,60,83,.06);
        box-shadow: 0 2px 8px rgba(27,60,83,.08);
    }

    .checkbox-label .batch-text {
        font-size: 0.875rem;
        font-weight: 500;
        color: #374151;
    }

    .checkbox-label:has(input:checked) .batch-text {
        color: var(--deepest-navy);
        font-weight: 600;
    }

    .folder-card .settings-btn {
        background: rgba(255,255,255,0.8);
        backdrop-filter: blur(4px);
        border: 1px solid rgba(210,193,182,.3);
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .folder-card .settings-btn:hover {
        background: var(--deepest-navy);
        color: var(--white);
        border-color: var(--deepest-navy);
        transform: rotate(90deg);
    }

    .visibility-badge {
        border-radius: 9999px;
        padding: 0.25rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
    }

    /* ================================================================
       PAGINATION - Matching index.php
       ================================================================ */
    .pagination-wrapper {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.25rem;
        background: rgba(248,245,242,.3);
        border-radius: 0 0 16px 16px;
        border-top: 1px solid rgba(210,193,182,.25);
        margin-top: 1.5rem;
    }

    .pagination-brand {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .pagination-brand .page-link {
        border-radius: 9999px;
        border: 2px solid rgba(210,193,182,.3);
        padding: 0.5rem 0.9rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--dark-steel);
        background: var(--white);
        transition: all 0.25s ease;
        text-decoration: none;
        min-width: 40px;
        text-align: center;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .pagination-brand .page-link:hover {
        background: var(--dark-steel);
        color: var(--white);
        border-color: var(--dark-steel);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(35,76,106,.25);
    }

    .pagination-brand .page-link.active {
        background: linear-gradient(135deg, var(--deepest-navy), var(--dark-steel));
        color: var(--white);
        border-color: var(--deepest-navy);
        box-shadow: 0 4px 14px rgba(27,60,83,.3);
        cursor: default;
    }

    .pagination-brand .page-link.disabled {
        opacity: 0.4;
        pointer-events: none;
        cursor: default;
    }

    .pagination-brand .page-info {
        color: var(--mid-steel);
        font-weight: 500;
        font-size: 0.85rem;
        padding: 0 0.75rem;
    }

    /* Records per page selector */
    .records-selector {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .records-selector label {
        color: var(--mid-steel);
        font-weight: 600;
        font-size: 0.85rem;
        margin: 0;
    }

    .records-selector select {
        border-radius: 9999px;
        border: 2px solid rgba(210,193,182,.3);
        padding: 0.4rem 1.2rem 0.4rem 1rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--dark-steel);
        background: var(--white);
        cursor: pointer;
        outline: none;
        transition: all 0.25s ease;
        font-family: 'Inter', sans-serif;
        appearance: auto;
    }

    .records-selector select:hover {
        border-color: var(--dark-steel);
        box-shadow: 0 2px 8px rgba(35,76,106,.15);
    }

    .records-selector select:focus {
        border-color: var(--deepest-navy);
        box-shadow: 0 0 0 3px rgba(27,60,83,.1);
    }

    /* Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    ::-webkit-scrollbar-track {
        background: var(--warm-sand);
        border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, var(--mid-steel), var(--dark-steel));
        border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(180deg, var(--dark-steel), var(--deepest-navy));
    }

    /* Modal scrollbar */
    .modal-brand .modal-body::-webkit-scrollbar {
        width: 6px;
    }
    .modal-brand .modal-body::-webkit-scrollbar-track {
        background: #f3f0ed;
        border-radius: 10px;
    }
    .modal-brand .modal-body::-webkit-scrollbar-thumb {
        background: var(--warm-sand);
        border-radius: 10px;
    }
    .modal-brand .modal-body::-webkit-scrollbar-thumb:hover {
        background: var(--mid-steel);
    }

    @media (max-width: 768px) {
        .hero-banner {
            padding: 1rem 1.25rem;
        }
        .stat-card .stat-number {
            font-size: 1.25rem;
        }
        .stat-card {
            padding: 0.75rem;
        }
        .pagination-wrapper {
            flex-direction: column;
            align-items: stretch;
            gap: 0.75rem;
        }
        .records-selector {
            justify-content: center;
        }
        .pagination-brand {
            justify-content: center;
        }
        .pagination-brand .page-link {
            padding: 0.35rem 0.7rem;
            font-size: 0.75rem;
            min-width: 32px;
        }
        .modal-brand {
            max-width: 100%;
            margin: 1rem;
        }
        .modal-brand .modal-header {
            padding: 1rem 1.25rem;
        }
        .modal-brand .modal-body {
            padding: 1rem 1.25rem;
        }
        .modal-brand .modal-footer {
            padding: 0.75rem 1.25rem 1.25rem 1.25rem;
            flex-direction: column;
        }
        .modal-brand .modal-footer .btn-brand {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="p-4 sm:p-6 lg:p-8 ml-0 md:ml-64 pt-20 md:pt-6 min-h-screen">
    
    <!-- Hero Banner -->
    <div class="hero-banner mx-0">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="rounded-3 p-2.5 d-inline-flex align-items-center justify-content-center" style="background: rgba(255,255,255,.15);">
                    <i class="fas fa-folder-open text-white" style="font-size: 1.5rem;"></i>
                </span>
                <div>
                    <h1 class="mb-0" style="font-size: 1.5rem; font-weight: 800;">Content Management</h1>
                    <p class="mb-0 opacity-75" style="font-size: 0.85rem;">Manage course folders and content visibility</p>
                </div>
            </div>
            <span class="badge-brand">
                <i class="fas fa-layer-group me-1.5"></i> <?= $total_courses ?> Courses
            </span>
        </div>
    </div>

    <div class="mt-4 md:mt-6">
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success-brand alert-brand alert-dismissible fade show mb-4" role="alert">
                <i class="fas fa-check-circle me-2"></i> 
                <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error-brand alert-brand alert-dismissible fade show mb-4" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> 
                <?= htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon purple">
                        <i class="fas fa-folder text-lg"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?= $total_courses ?></div>
                        <div class="stat-label">Course Folders</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon sky">
                        <i class="fas fa-users text-lg"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?= $total_batches ?></div>
                        <div class="stat-label">Total Batches</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon emerald">
                        <i class="fas fa-eye text-lg"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?= $total_visibility_links ?></div>
                        <div class="stat-label">Visibility Links</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon amber">
                        <i class="fas fa-exclamation-triangle text-lg"></i>
                    </div>
                    <div>
                        <div class="stat-number"><?= $courses_without_visibility ?></div>
                        <div class="stat-label">Not Yet Configured</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Folders Grid -->
        <?php if (count($courses) > 0): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php foreach ($courses as $course): 
                $visible_batches = isset($course_visibility_mapping[$course['id']]) ? $course_visibility_mapping[$course['id']] : [];
                $visible_count = count($visible_batches);
                $theme = folder_theme($course['name'], $folder_themes);
            ?>
            <div class="folder-card glass-card rounded-2xl p-6 group cursor-pointer" onclick="window.location.href='course_folder.php?course_id=<?= $course['id'] ?>'">
                <div class="flex justify-between items-start mb-4">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br <?= $theme['grad'] ?> flex items-center justify-center shadow-md group-hover:scale-105 transition-transform duration-300">
                        <i class="fas <?= $theme['icon'] ?> text-white text-2xl"></i>
                    </div>
                    <button type="button" 
                            onclick="event.stopPropagation(); openVisibilityModal(<?= $course['id'] ?>, '<?= htmlspecialchars(addslashes($course['name'])) ?>', <?= htmlspecialchars(json_encode($visible_batches)) ?>)"
                            class="settings-btn w-9 h-9 rounded-full flex items-center justify-center text-gray-500 hover:text-white transition-all"
                            title="Content Visibility Settings">
                        <i class="fas fa-cog text-sm"></i>
                    </button>
                </div>
                
                <h3 class="text-lg font-bold text-gray-800 mb-1 truncate" title="<?= htmlspecialchars($course['name']) ?>">
                    <?= htmlspecialchars($course['name']) ?>
                </h3>
                <div class="text-xs font-medium <?= $theme['text'] ?> uppercase tracking-wide mb-4">Course Folder</div>

                <div class="flex items-center justify-between border-t pt-3" style="border-color: rgba(210,193,182,.25);">
                    <?php if ($visible_count > 0): ?>
                    <div class="visibility-badge" style="background: <?= $theme['soft'] ?>; color: <?= $theme['text'] ?>;">
                        <i class="fas fa-eye text-xs"></i>
                        <span><?= $visible_count ?> Visible Batch<?= $visible_count !== 1 ? 'es' : '' ?></span>
                    </div>
                    <?php else: ?>
                    <div class="visibility-badge" style="background: #f3f4f6; color: #9ca3af;">
                        <i class="fas fa-eye-slash text-xs"></i>
                        <span>Not visible</span>
                    </div>
                    <?php endif; ?>
                    <i class="fas fa-arrow-right text-gray-300 group-hover:text-gray-500 group-hover:translate-x-0.5 transition-all text-sm"></i>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1 || $total_courses > $records_per_page): ?>
        <div class="pagination-wrapper">
            <!-- Records per page selector -->
            <div class="records-selector">
                <label for="records_per_page"><i class="fas fa-list-ul me-1"></i> Show:</label>
                <form method="POST" id="recordsPerPageForm" style="display: flex; align-items: center; gap: 0.5rem;">
                    <select name="records_per_page" id="records_per_page" onchange="this.form.submit()">
                        <option value="4" <?= $records_per_page == 4 ? 'selected' : '' ?>>4</option>
                        <option value="8" <?= $records_per_page == 8 ? 'selected' : '' ?>>8</option>
                        <option value="12" <?= $records_per_page == 12 ? 'selected' : '' ?>>12</option>
                        <option value="16" <?= $records_per_page == 16 ? 'selected' : '' ?>>16</option>
                        <option value="20" <?= $records_per_page == 20 ? 'selected' : '' ?>>20</option>
                    </select>
                </form>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-brand">
                <!-- Previous button -->
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="page-link" aria-label="Previous">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
                <?php endif; ?>

                <!-- Page numbers -->
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                if ($start_page > 1) {
                    echo '<a href="?page=1" class="page-link">1</a>';
                    if ($start_page > 2) {
                        echo '<span class="page-link disabled">…</span>';
                    }
                }
                
                for ($i = $start_page; $i <= $end_page; $i++) {
                    $active = $i === $page ? 'active' : '';
                    echo '<a href="?page=' . $i . '" class="page-link ' . $active . '">' . $i . '</a>';
                }
                
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<span class="page-link disabled">…</span>';
                    }
                    echo '<a href="?page=' . $total_pages . '" class="page-link">' . $total_pages . '</a>';
                }
                ?>

                <!-- Next button -->
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="page-link" aria-label="Next">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>

                <!-- Page info -->
                <span class="page-info">
                    Page <?= $page ?> of <?= $total_pages ?>
                    (<?= $total_courses ?> total)
                </span>
            </div>
            <?php else: ?>
            <div class="pagination-brand">
                <span class="page-info">
                    Showing <?= count($courses) ?> of <?= $total_courses ?> courses
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Empty state -->
        <div class="glass-card rounded-2xl p-12 text-center">
            <div class="flex flex-col items-center">
                <div class="w-20 h-20 rounded-full bg-gray-100 flex items-center justify-center mb-4">
                    <i class="fas fa-folder-open text-3xl text-gray-400"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 mb-2">No Courses Found</h3>
                <p class="text-gray-500 mb-4">Create your first course to start managing content.</p>
                <a href="index.php" class="btn-brand btn-primary-brand">
                    <i class="fas fa-plus me-2"></i> Create Course
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Visibility Modal - Fixed backdrop -->
<div id="visibilityModal" class="modal-backdrop-custom">
    <div class="modal-brand">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" style="margin: 0; font-size: 1.25rem;">
                    <i class="fas fa-eye"></i> Content Visibility Settings
                </h5>
                <button type="button" class="btn-close" onclick="closeVisibilityModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="modal-body">
                <p style="color: #6b7280; font-size: 0.875rem; margin-bottom: 1rem;">
                    Select the batches that can see content for: 
                    <span id="modalCourseName" style="font-weight: 700; color: var(--deepest-navy);"></span>
                </p>
                
                <form id="assignVisibilityForm" method="POST" action="">
                    <input type="hidden" name="action" value="assign_visibility">
                    <input type="hidden" name="course_id" id="modalCourseId" value="">
                    
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 300px; overflow-y: auto; padding-right: 4px;">
                        <?php foreach ($batches as $batch): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="batch_ids[]" value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                   class="checkbox-custom batch-checkbox">
                            <span class="batch-text">
                                <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary-brand" onclick="closeVisibilityModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn-success-brand" onclick="document.getElementById('assignVisibilityForm').submit()">
                    <i class="fas fa-save"></i> Save Visibility
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openVisibilityModal(courseId, courseName, visibleBatches) {
    document.getElementById('modalCourseId').value = courseId;
    document.getElementById('modalCourseName').textContent = courseName;
    
    // Uncheck all first
    document.querySelectorAll('.batch-checkbox').forEach(cb => {
        cb.checked = false;
    });
    
    // Check assigned
    visibleBatches.forEach(batchId => {
        const cb = document.querySelector(`.batch-checkbox[value="${batchId}"]`);
        if (cb) cb.checked = true;
    });
    
    document.getElementById('visibilityModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeVisibilityModal() {
    document.getElementById('visibilityModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Close on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeVisibilityModal();
    }
});

// Close on backdrop click
document.getElementById('visibilityModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeVisibilityModal();
    }
});
</script>

<?php include '../footer.php'; ?>