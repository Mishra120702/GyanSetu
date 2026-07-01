<?php
session_start();

// Check if user is logged in as trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit;
}

require_once '../db_connection.php';

// Get filter parameters
$filter_batch = isset($_GET['batch']) ? trim($_GET['batch']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_course = isset($_GET['course']) ? trim($_GET['course']) : '';
$enrollmentDateFrom = isset($_GET['enrollment_from']) ? trim($_GET['enrollment_from']) : '';
$enrollmentDateTo = isset($_GET['enrollment_to']) ? trim($_GET['enrollment_to']) : '';

// Sorting variables
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 's.first_name';
$sortOrder = isset($_GET['order']) ? $_GET['order'] : 'ASC';

// Validate sort field to prevent SQL injection
$allowedSortFields = ['s.student_id', 'c.name', 's.first_name', 's.email', 'batch_name_display', 's.current_status', 's.enrollment_date'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 's.first_name';
}

// Validate sort order
$sortOrder = strtoupper($sortOrder);
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'ASC';
}

// Pagination variables
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get trainer details
    $trainer_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT t.*, u.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
    $stmt->execute([$trainer_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($trainer === false) {
        $trainer = [
            'id' => $trainer_id,
            'user_id' => $trainer_id,
            'name' => $_SESSION['user_name'] ?? 'Trainer'
        ];
    }

    // Get assigned batches for filter dropdown using batch_courses, because trainer portal assignment lives there.
    $stmt = $db->prepare("\n        SELECT DISTINCT b.batch_id, b.batch_name\n        FROM batches b\n        JOIN batch_courses bc ON b.batch_id = bc.batch_id\n        WHERE bc.trainer_id = ?\n        ORDER BY b.batch_name\n    ");
    $stmt->execute([$trainer['id']]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get courses assigned to this trainer
    $courseStmt = $db->prepare("\n        SELECT DISTINCT c.id, c.name\n        FROM courses c\n        JOIN batch_courses bc ON c.id = bc.course_id\n        WHERE bc.trainer_id = ?\n        ORDER BY c.name\n    ");
    $courseStmt->execute([$trainer['id']]);
    $courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

    $baseJoins = "\n        FROM students s\n        LEFT JOIN batches b1 ON s.batch_name = b1.batch_id\n        LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id\n        LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id\n        LEFT JOIN batches b4 ON s.batch_name_4 = b4.batch_id\n        LEFT JOIN batch_courses bc1 ON b1.batch_id = bc1.batch_id AND bc1.trainer_id = ?\n        LEFT JOIN batch_courses bc2 ON b2.batch_id = bc2.batch_id AND bc2.trainer_id = ?\n        LEFT JOIN batch_courses bc3 ON b3.batch_id = bc3.batch_id AND bc3.trainer_id = ?\n        LEFT JOIN batch_courses bc4 ON b4.batch_id = bc4.batch_id AND bc4.trainer_id = ?\n        LEFT JOIN courses c ON s.course = c.id\n        WHERE (bc1.id IS NOT NULL OR bc2.id IS NOT NULL OR bc3.id IS NOT NULL OR bc4.id IS NOT NULL)\n    ";

    $countQuery = "SELECT COUNT(DISTINCT s.student_id) as total " . $baseJoins;
    $query = "\n        SELECT DISTINCT\n            s.student_id, s.course, c.name as course_name, s.first_name, s.last_name, s.email, s.phone_number,\n            s.date_of_birth, s.enrollment_date, s.current_status, s.profile_picture,\n            s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4,\n            COALESCE(b1.batch_id, b2.batch_id, b3.batch_id, b4.batch_id) as batch_id,\n            COALESCE(b1.batch_name, b2.batch_name, b3.batch_name, b4.batch_name) as batch_name_display,\n            COALESCE(b1.time_slot, b2.time_slot, b3.time_slot, b4.time_slot) as time_slot,\n            COALESCE(b1.platform, b2.platform, b3.platform, b4.platform) as platform,\n            COALESCE(b1.mode, b2.mode, b3.mode, b4.mode) as mode,\n            (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.student_id AND a.status = 'Present') as attendance_count,\n            (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.student_id) as total_attendance,\n            (SELECT AVG(er.obtained_marks) FROM exam_results er WHERE er.student_id = s.student_id) as avg_score\n        " . $baseJoins;

    $params = [$trainer['id'], $trainer['id'], $trainer['id'], $trainer['id']];
    $countParams = [$trainer['id'], $trainer['id'], $trainer['id'], $trainer['id']];

    if (!empty($filter_batch)) {
        $query .= " AND (b1.batch_id = ? OR b2.batch_id = ? OR b3.batch_id = ? OR b4.batch_id = ?)";
        $countQuery .= " AND (b1.batch_id = ? OR b2.batch_id = ? OR b3.batch_id = ? OR b4.batch_id = ?)";
        for ($i = 0; $i < 4; $i++) {
            $params[] = $filter_batch;
            $countParams[] = $filter_batch;
        }
    }

    if (!empty($filter_status)) {
        $query .= " AND s.current_status = ?";
        $countQuery .= " AND s.current_status = ?";
        $params[] = $filter_status;
        $countParams[] = $filter_status;
    }

    if (!empty($filter_course)) {
        $query .= " AND s.course = ?";
        $countQuery .= " AND s.course = ?";
        $params[] = $filter_course;
        $countParams[] = $filter_course;
    }

    if (!empty($search)) {
        $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.phone_number LIKE ? OR s.student_id LIKE ?)";
        $countQuery .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.phone_number LIKE ? OR s.student_id LIKE ?)";
        $search_param = "%$search%";
        for ($i = 0; $i < 5; $i++) {
            $params[] = $search_param;
            $countParams[] = $search_param;
        }
    }

    if (!empty($enrollmentDateFrom)) {
        $query .= " AND s.enrollment_date >= ?";
        $countQuery .= " AND s.enrollment_date >= ?";
        $params[] = $enrollmentDateFrom;
        $countParams[] = $enrollmentDateFrom;
    }

    if (!empty($enrollmentDateTo)) {
        $query .= " AND s.enrollment_date <= ?";
        $countQuery .= " AND s.enrollment_date <= ?";
        $params[] = $enrollmentDateTo;
        $countParams[] = $enrollmentDateTo;
    }

    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($countParams);
    $totalResults = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalResults / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $query .= " ORDER BY $sortField $sortOrder LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_query = "\n        SELECT s.current_status as status, COUNT(DISTINCT s.student_id) as count\n        " . $baseJoins . "\n        AND s.current_status != 'dropped'\n        GROUP BY s.current_status\n    ";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->execute([$trainer['id'], $trainer['id'], $trainer['id'], $trainer['id']]);
    $student_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusCounts = [
        'active' => 0,
        'completed' => 0,
        'on hold' => 0,
        'transferred' => 0,
        'dropped' => 0
    ];
    foreach ($student_stats as $stat) {
        if (array_key_exists($stat['status'], $statusCounts)) {
            $statusCounts[$stat['status']] = (int)$stat['count'];
        }
    }

    $pageAttendanceSum = 0;
    $pageAttendanceCount = 0;
    $pageScoreSum = 0;
    $pageScoreCount = 0;
    foreach ($students as $student) {
        if (!empty($student['total_attendance']) && $student['total_attendance'] > 0) {
            $pageAttendanceSum += ($student['attendance_count'] / $student['total_attendance']) * 100;
            $pageAttendanceCount++;
        }
        if ($student['avg_score'] !== null) {
            $pageScoreSum += (float)$student['avg_score'];
            $pageScoreCount++;
        }
    }
    $avgAttendance = $pageAttendanceCount > 0 ? round($pageAttendanceSum / $pageAttendanceCount) : 0;
    $avgScore = $pageScoreCount > 0 ? round($pageScoreSum / $pageScoreCount) : 0;

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

function statusBadgeClass($status) {
    switch ($status) {
        case 'active': return 'status-active';
        case 'completed': return 'status-completed';
        case 'dropped': return 'status-dropped';
        case 'on hold': return 'status-onhold';
        case 'transferred': return 'status-transferred';
        default: return 'status-default';
    }
}

function safeText($value, $fallback = 'Not available') {
    return htmlspecialchars(($value !== null && $value !== '') ? $value : $fallback, ENT_QUOTES, 'UTF-8');
}

function initials($first, $last) {
    return strtoupper(substr($first ?? 'S', 0, 1) . substr($last ?? '', 0, 1));
}

function sortUrl($field) {
    $params = $_GET;
    $currentSort = $params['sort'] ?? 's.first_name';
    $currentOrder = strtoupper($params['order'] ?? 'ASC');
    $params['sort'] = $field;
    $params['order'] = ($currentSort === $field && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    $params['page'] = 1;
    return '?' . http_build_query($params);
}

function isActiveSort($field, $sortField) {
    return $sortField === $field;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>My Students | Trainer Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 46%, #456882 100%);
            --cool-gradient: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #f43f5e 100%);
            --ink: #111827;
            --muted: #6b7280;
        }

        * { -webkit-tap-highlight-color: transparent; }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 16% 12%, rgba(27,60,83, .13), transparent 30%),
                radial-gradient(circle at 88% 6%, rgba(69,104,130, .16), transparent 34%),
                linear-gradient(135deg, #eef4ff 0%, #f7f3ff 46%, #f8fbff 100%);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--ink);
        }

        .soft-shell {
            background: rgba(255, 255, 255, .82);
            border: 1px solid rgba(148, 163, 184, .2);
            box-shadow: 0 20px 60px rgba(15, 23, 42, .08);
            backdrop-filter: blur(16px);
            border-radius: 24px;
        }

        .hero-shell {
            position: relative;
            overflow: hidden;
            background: var(--primary-gradient);
            border: 1px solid rgba(255, 255, 255, .28);
            box-shadow: 0 22px 55px rgba(27,60,83, .24);
            border-radius: 26px;
        }

        .hero-shell::before {
            content: '';
            position: absolute;
            inset: -55% auto auto 52%;
            width: 680px;
            height: 680px;
            background: radial-gradient(circle, rgba(255,255,255,.22), transparent 58%);
            border-radius: 50%;
        }

        .hero-shell::after {
            content: '';
            position: absolute;
            right: -80px;
            bottom: -120px;
            width: 380px;
            height: 380px;
            background: radial-gradient(circle, rgba(255,255,255,.18), transparent 62%);
            border-radius: 50%;
        }

        .mini-stat {
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .24);
            border-radius: 16px;
            backdrop-filter: blur(14px);
        }

        .quick-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .6rem .85rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .25);
            color: white;
            font-size: .78rem;
            font-weight: 700;
            transition: all .25s ease;
        }

        .quick-chip:hover {
            background: rgba(255, 255, 255, .28);
            transform: translateY(-2px);
        }

        .section-label {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .35rem .65rem;
            border-radius: 999px;
            background: rgba(27,60,83, .1);
            color: #1B3C53;
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(148,163,184,.18);
            border-radius: 20px;
            box-shadow: 0 14px 36px rgba(15, 23, 42, .07);
            transition: all .25s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 44px rgba(15, 23, 42, .1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            color: white;
            box-shadow: 0 10px 22px rgba(15, 23, 42, .16);
        }

        .filter-input {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, .35);
            background: rgba(255, 255, 255, .92);
            padding: .75rem .9rem;
            outline: none;
            transition: all .2s ease;
        }

        .filter-input:focus {
            border-color: #234C6A;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, .12);
            background: white;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .35rem .65rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .025em;
            white-space: nowrap;
        }

        .status-active { background: rgba(16,185,129,.12); color: #059669; border: 1px solid rgba(16,185,129,.22); }
        .status-completed { background: rgba(249,115,22,.13); color: #ea580c; border: 1px solid rgba(249,115,22,.22); }
        .status-dropped { background: rgba(239,68,68,.12); color: #dc2626; border: 1px solid rgba(239,68,68,.22); }
        .status-onhold { background: rgba(59,130,246,.12); color: #234C6A; border: 1px solid rgba(59,130,246,.22); }
        .status-transferred { background: rgba(139,92,246,.12); color: #234C6A; border: 1px solid rgba(139,92,246,.22); }
        .status-default { background: rgba(107,114,128,.12); color: #4b5563; border: 1px solid rgba(107,114,128,.22); }

        .batch-indicator {
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            padding: .22rem .5rem;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 800;
            margin-right: 4px;
            margin-bottom: 4px;
        }
        .batch-primary { background: rgba(79,70,229,.1); color: #1B3C53; border: 1px solid rgba(79,70,229,.18); }
        .batch-secondary { background: rgba(16,185,129,.1); color: #059669; border: 1px solid rgba(16,185,129,.18); }
        .batch-tertiary { background: rgba(245,158,11,.12); color: #d97706; border: 1px solid rgba(245,158,11,.18); }
        .batch-fourth { background: rgba(69,104,130,.11); color: #db2777; border: 1px solid rgba(69,104,130,.18); }

        .progress-line {
            height: 7px;
            border-radius: 999px;
            overflow: hidden;
            background: #e5e7eb;
        }
        .progress-fill { height: 100%; border-radius: inherit; transition: width .8s ease; }

        .view-toggle {
            padding: .65rem .95rem;
            border-radius: 14px;
            color: #64748b;
            font-size: .84rem;
            font-weight: 800;
            transition: all .2s ease;
        }
        .view-toggle.active { background: var(--primary-gradient); color: white; box-shadow: 0 14px 30px rgba(27,60,83,.22); }

        .student-row {
            transition: all .2s ease;
        }
        .student-row:hover {
            background: rgba(27,60,83, .045);
        }

        .student-card {
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(148,163,184,.2);
            border-radius: 22px;
            box-shadow: 0 14px 36px rgba(15, 23, 42, .07);
            overflow: hidden;
            transition: all .25s ease;
        }
        .student-card:hover { transform: translateY(-4px); box-shadow: 0 20px 48px rgba(15,23,42,.11); }

        .avatar-gradient { background: linear-gradient(135deg, #1B3C53, #234C6A); }
        .avatar-gradient-2 { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .avatar-gradient-3 { background: linear-gradient(135deg, #43e97b, #38f9d7); }
        .avatar-gradient-4 { background: linear-gradient(135deg, #fa709a, #fee140); }
        .avatar-gradient-5 { background: linear-gradient(135deg, #4facfe, #00f2fe); }

        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            height: 42px;
            padding: 0 .8rem;
            border-radius: 14px;
            background: white;
            border: 1px solid rgba(148,163,184,.22);
            color: #475569;
            font-weight: 800;
            transition: all .2s ease;
        }
        .page-link:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(15,23,42,.08); }
        .page-link.active { background: var(--primary-gradient); color: white; border-color: transparent; }

        .empty-state {
            border: 1px dashed rgba(148,163,184,.45);
            background: linear-gradient(135deg, rgba(255,255,255,.8), rgba(248,250,252,.9));
            border-radius: 24px;
        }

        .scrollbar-soft::-webkit-scrollbar { height: 8px; width: 8px; }
        .scrollbar-soft::-webkit-scrollbar-track { background: #EEF3F6; border-radius: 999px; }
        .scrollbar-soft::-webkit-scrollbar-thumb { background: linear-gradient(135deg,#234C6A,#456882); border-radius: 999px; }

        /* Visual-only polish: same dashboard theme, no new features */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(27,60,83, .055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(69,104,130, .045) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.85), transparent 82%);
            z-index: -1;
        }

        header.sticky {
            box-shadow: 0 12px 36px rgba(15, 23, 42, .06);
        }

        .hero-shell {
            isolation: isolate;
        }

        .hero-shell .mini-stat {
            transition: transform .22s ease, background .22s ease, box-shadow .22s ease;
        }

        .hero-shell .mini-stat:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, .24);
            box-shadow: 0 14px 32px rgba(15, 23, 42, .14);
        }

        .soft-shell, .stat-card, .student-card {
            position: relative;
        }

        .soft-shell::before, .stat-card::after, .student-card::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(255,255,255,.55), transparent 42%);
            opacity: .55;
        }

        .soft-shell > *, .stat-card > *, .student-card > * {
            position: relative;
            z-index: 1;
        }

        .filter-input:hover {
            border-color: rgba(139, 92, 246, .48);
            box-shadow: 0 8px 20px rgba(15, 23, 42, .04);
        }

        thead tr {
            position: sticky;
            top: 0;
            z-index: 2;
        }

        tbody tr td {
            transition: background .2s ease, transform .2s ease;
        }

        .student-row:hover td:first-child {
            box-shadow: inset 4px 0 0 rgba(27,60,83, .7);
        }

        .page-link.active {
            box-shadow: 0 14px 30px rgba(27,60,83, .22);
        }

        @media (max-width: 768px) {
            .hero-shell { border-radius: 20px; }
            .soft-shell { border-radius: 18px; }
        }
    
        /* Student card header ID overlap fix */
        .student-card .student-id,
        .student-card .student-code,
        .student-card h3,
        .student-card h4 {
            max-width: 68%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .student-card .student-id,
        .student-card .student-code {
            font-size: 1rem !important;
            line-height: 1.15 !important;
            letter-spacing: .02em !important;
        }

        .student-card .status-badge,
        .student-card .student-status,
        .student-card .badge {
            flex-shrink: 0;
        }

        .student-card-header,
        .student-card .card-header,
        .student-card .student-header {
            min-height: 84px;
            padding-right: 1rem !important;
        }

        @media (max-width: 1200px) {
            .student-card .student-id,
            .student-card .student-code,
            .student-card h3,
            .student-card h4 {
                max-width: 60%;
            }

            .student-card .student-id,
            .student-card .student-code {
                font-size: .92rem !important;
            }
        }

        @media (max-width: 576px) {
            .student-card .student-id,
            .student-card .student-code,
            .student-card h3,
            .student-card h4 {
                max-width: 58%;
            }

            .student-card .student-id,
            .student-card .student-code {
                font-size: .82rem !important;
            }
        }

    
        /* Corrected Student ID fix: smaller, visible, NOT hidden */
        .student-card .student-card-id {
            font-size: .98rem !important;
            line-height: 1.12 !important;
            letter-spacing: .01em !important;
            max-width: none !important;
            overflow: visible !important;
            text-overflow: clip !important;
            white-space: nowrap !important;
        }

        .student-card > div:first-child .flex > div:first-child {
            min-width: 0;
            flex: 1 1 auto;
        }

        .student-card > div:first-child .status-badge {
            flex: 0 0 auto;
            font-size: .68rem !important;
            padding: .42rem .7rem !important;
        }

        /* Undo previous over-aggressive rule that was hiding IDs/names */
        .student-card h3,
        .student-card h4 {
            max-width: none !important;
            overflow: visible !important;
            text-overflow: clip !important;
        }

        .student-card h4.truncate {
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
        }

        @media (max-width: 1200px) {
            .student-card .student-card-id {
                font-size: .9rem !important;
            }
            .student-card > div:first-child .status-badge {
                font-size: .62rem !important;
                padding: .38rem .58rem !important;
            }
        }

        @media (max-width: 576px) {
            .student-card .student-card-id {
                font-size: .82rem !important;
            }
        }

    
        /* Final student ID overlap fix: ID visible + smaller, avatar moved down */
        .student-card .student-card-id {
            display: block !important;
            font-size: .72rem !important;
            line-height: 1.05 !important;
            letter-spacing: .02em !important;
            max-width: 150px !important;
            overflow: visible !important;
            text-overflow: clip !important;
            white-space: nowrap !important;
            position: relative !important;
            z-index: 5 !important;
        }

        .student-card > div:first-child {
            min-height: 76px !important;
            padding-top: 1rem !important;
            padding-bottom: 1.15rem !important;
        }

        .student-card > div:first-child .status-badge {
            position: relative !important;
            z-index: 6 !important;
            flex-shrink: 0 !important;
            font-size: .58rem !important;
            padding: .34rem .55rem !important;
        }

        .student-card > div:nth-child(2) {
            position: relative !important;
            z-index: 2 !important;
        }

        @media (max-width: 1200px) {
            .student-card .student-card-id {
                font-size: .68rem !important;
                max-width: 130px !important;
            }
        }

        @media (max-width: 576px) {
            .student-card .student-card-id {
                font-size: .64rem !important;
                max-width: 110px !important;
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
<style>

/* ===== Company Source Safe UI Patch: My Students approved theme ===== */
/* CSS-only patch. PHP queries, filters, sorting, pagination, JS view toggle, IDs, names, and DB logic untouched. */

/* Main background, same clean skin/navy theme */
body {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,83,.10), transparent 28%),
        radial-gradient(circle at 88% 4%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 46%, #FBFAF8 100%) !important;
}

/* Header clean and readable */
header.sticky {
    background: rgba(255,253,250,.82) !important;
    backdrop-filter: blur(18px) !important;
    border-bottom: 1px solid rgba(210,193,182,.55) !important;
    box-shadow: 0 12px 34px rgba(27,60,83,.08) !important;
}

header.sticky h1,
header.sticky p {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

/* Hero banner, same as approved trainer theme */
.hero-shell {
    background:
        radial-gradient(circle at 92% 20%, rgba(255,255,255,.18), transparent 26%),
        radial-gradient(circle at 70% 110%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    border: 1.6px solid rgba(255,255,255,.24) !important;
    box-shadow:
        0 24px 64px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
}

.hero-shell::before {
    background:
        radial-gradient(circle, rgba(255,255,255,.20), transparent 58%) !important;
}

.hero-shell::after {
    background:
        radial-gradient(circle, rgba(255,255,255,.17), transparent 62%) !important;
}

.hero-shell h2,
.hero-shell p,
.hero-shell span,
.hero-shell .quick-chip,
.hero-shell .mini-stat,
.hero-shell .mini-stat p {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

.hero-shell h2 {
    text-shadow: 0 12px 30px rgba(0,0,0,.20) !important;
}

.quick-chip {
    background: rgba(255,255,255,.15) !important;
    border: 1.3px solid rgba(255,255,255,.28) !important;
    box-shadow:
        0 9px 22px rgba(15,23,42,.12),
        inset 0 1px 0 rgba(255,255,255,.24) !important;
}

.mini-stat {
    background: rgba(255,255,255,.15) !important;
    border: 1.4px solid rgba(255,255,255,.27) !important;
    box-shadow:
        0 14px 30px rgba(15,23,42,.12),
        inset 0 1px 0 rgba(255,255,255,.22) !important;
}

.mini-stat:hover {
    transform: translateY(-4px) !important;
    background: rgba(255,255,255,.21) !important;
}

/* Top statistic cards: exact requested mapping */
main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card {
    position: relative !important;
    overflow: hidden !important;
    min-height: 105px !important;
    border-radius: 22px !important;
    border: 1.6px solid rgba(255,255,255,.38) !important;
    color: #ffffff !important;
    box-shadow:
        0 18px 42px rgba(27,60,83,.17),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card::before {
    display: none !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card::after {
    content: "" !important;
    position: absolute !important;
    right: -38px !important;
    top: -42px !important;
    width: 124px !important;
    height: 124px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.14) !important;
    pointer-events: none !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card > * {
    position: relative !important;
    z-index: 2 !important;
}

/* Total = navy */
main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card:nth-child(1) {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
}

/* Active = green */
main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card:nth-child(2) {
    background: linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%) !important;
}

/* Completed = orange */
main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card:nth-child(3) {
    background: linear-gradient(135deg, #b45309 0%, #d97706 54%, #f59e0b 100%) !important;
}

/* Attendance = blue */
main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card:nth-child(4) {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 54%, #60a5fa 100%) !important;
}

/* Avg Score = purple */
main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card:nth-child(5) {
    background: linear-gradient(135deg, #6d28d9 0%, #7c3aed 54%, #a78bfa 100%) !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card:hover {
    transform: translateY(-5px) !important;
    filter: brightness(1.06) !important;
    box-shadow:
        0 28px 62px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card p,
main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card span,
main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.16) !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card .stat-icon {
    width: 50px !important;
    height: 50px !important;
    min-width: 50px !important;
    min-height: 50px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.45px solid rgba(255,255,255,.46) !important;
    box-shadow:
        0 12px 26px rgba(0,0,0,.20),
        inset 0 1px 0 rgba(255,255,255,.26) !important;
    transition: transform .22s ease, box-shadow .22s ease !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card:hover .stat-icon {
    transform: translateY(-2px) scale(1.10) rotate(3deg) !important;
    box-shadow:
        0 17px 36px rgba(0,0,0,.25),
        0 0 0 8px rgba(255,255,255,.14),
        inset 0 1px 0 rgba(255,255,255,.30) !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card .progress-line {
    background: rgba(255,255,255,.30) !important;
    height: 7px !important;
}

main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card .progress-fill {
    background: rgba(255,255,255,.85) !important;
    box-shadow: 0 0 12px rgba(255,255,255,.22) !important;
}

/* Filter section like screenshot, cleaner and aligned */
.soft-shell {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.11), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.88)) !important;
    border: 1.6px solid rgba(210,193,182,.64) !important;
    box-shadow:
        0 18px 44px rgba(27,60,83,.11),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
}

#filterForm .filter-input {
    background: rgba(255,255,255,.96) !important;
    border: 1.35px solid rgba(69,104,130,.28) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 800 !important;
    box-shadow:
        0 8px 20px rgba(27,60,83,.045),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
}

#filterForm .filter-input::placeholder {
    color: #456882 !important;
    opacity: .78 !important;
    -webkit-text-fill-color: #456882 !important;
}

#filterForm .filter-input:focus {
    border-color: #234C6A !important;
    box-shadow:
        0 0 0 4px rgba(35,76,106,.13),
        0 12px 24px rgba(27,60,83,.09) !important;
}

#filterForm label {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

/* Student directory cards / empty state shade */
.student-card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.08), transparent 34%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.5px solid rgba(210,193,182,.66) !important;
    box-shadow:
        0 18px 42px rgba(27,60,83,.12),
        inset 0 1px 0 rgba(255,255,255,.84) !important;
}

.student-card:hover {
    border-color: rgba(35,76,106,.40) !important;
    box-shadow:
        0 26px 58px rgba(27,60,83,.17),
        inset 0 1px 0 rgba(255,255,255,.90) !important;
}

.student-card > div:first-child {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
}

.student-card .avatar-gradient,
.student-card .avatar-gradient-2,
.student-card .avatar-gradient-3,
.student-card .avatar-gradient-4,
.student-card .avatar-gradient-5 {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow:
        0 12px 26px rgba(27,60,83,.18),
        inset 0 1px 0 rgba(255,255,255,.22) !important;
}

.empty-state {
    background:
        radial-gradient(circle at 50% 0%, rgba(69,104,130,.09), transparent 40%),
        linear-gradient(135deg, rgba(255,253,250,.97), rgba(238,243,246,.88)) !important;
    border: 1.6px dashed rgba(69,104,130,.34) !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.86),
        0 12px 30px rgba(27,60,83,.055) !important;
}

/* Toggle buttons */
.view-toggle.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

.section-label {
    background:
        linear-gradient(135deg, rgba(238,243,246,.92), rgba(210,193,182,.30)) !important;
    border: 1px solid rgba(210,193,182,.62) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

/* Tables also get same subtle header */
thead tr,
table thead {
    background:
        linear-gradient(135deg, rgba(238,243,246,.94), rgba(210,193,182,.30)) !important;
}

thead th,
thead th a {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

@media (max-width: 768px) {
    main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card {
        min-height: 96px !important;
        border-radius: 18px !important;
    }

    main > section.grid.grid-cols-1.sm\:grid-cols-2.xl\:grid-cols-5 > .stat-card .stat-icon {
        width: 44px !important;
        height: 44px !important;
        min-width: 44px !important;
        min-height: 44px !important;
    }
}

</style>
<style>

/* ===== My Students: search/filter bar layout fix ===== */
/* CSS-only. Form action, GET names, filters, sorting, pagination and JS untouched. */

/* The form has Search as xl:col-span-2 plus 5 other filters.
   Tailwind grid was xl:grid-cols-6, so one field could wrap weirdly.
   This makes the layout actually match human expectations, a rare event. */
@media (min-width: 1280px) {
    #filterForm .flex-1.grid {
        grid-template-columns: minmax(280px, 2fr) repeat(5, minmax(138px, 1fr)) !important;
        align-items: end !important;
    }

    #filterForm .flex-1.grid > div:first-child {
        grid-column: auto !important;
    }

    #filterForm .flex-1.grid > div {
        min-width: 0 !important;
    }
}

/* Tablet: clean 2-column layout */
@media (min-width: 768px) and (max-width: 1279px) {
    #filterForm .flex-1.grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    #filterForm .flex-1.grid > div:first-child {
        grid-column: 1 / -1 !important;
    }
}

/* Mobile: one clean column */
@media (max-width: 767px) {
    #filterForm .flex-1.grid {
        grid-template-columns: 1fr !important;
    }

    #filterForm .flex-1.grid > div:first-child {
        grid-column: auto !important;
    }
}

/* Search box visibility + spacing */
#filterForm .relative {
    position: relative !important;
}

#filterForm .relative .fa-search {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    opacity: .82 !important;
    z-index: 2 !important;
}

#filterForm input[name="search"] {
    padding-left: 2.75rem !important;
    color: #102A3A !important;
    -webkit-text-fill-color: #102A3A !important;
    font-weight: 900 !important;
    letter-spacing: .01em !important;
}

#filterForm input[name="search"]::placeholder {
    color: #234C6A !important;
    -webkit-text-fill-color: #234C6A !important;
    opacity: .82 !important;
    font-weight: 800 !important;
}

/* Make all filter controls consistent height, because uneven form fields are a crime against eyesight. */
#filterForm .filter-input {
    min-height: 46px !important;
    height: 46px !important;
    line-height: 1.2 !important;
    border-radius: 15px !important;
}

#filterForm select.filter-input {
    padding-right: 2.4rem !important;
}

/* Buttons aligned with controls */
#filterForm .flex.gap-2 {
    align-items: end !important;
}

#filterForm button[type="submit"],
#filterForm button[type="button"] {
    min-height: 46px !important;
    white-space: nowrap !important;
}

/* Prevent the button group from crushing the search/filter row */
@media (min-width: 1280px) {
    #filterForm > .flex {
        align-items: flex-end !important;
    }

    #filterForm > .flex > .flex.gap-2 {
        flex-shrink: 0 !important;
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
    width: 34px !important;
    height: 34px !important;
    min-width: 34px !important;
    min-height: 34px !important;
}
</style>


<style>
/* ===== My Students zoom/responsive filter fix ===== */
/* CSS-only. GET filters, form names, buttons, sorting, pagination, table/card view and PHP untouched. */

html,
body {
    max-width: 100% !important;
    overflow-x: hidden !important;
}

body > div.ml-0,
body > div.lg\:ml-64,
body > .ml-0.lg\:ml-64 {
    min-width: 0 !important;
    max-width: 100% !important;
}

main {
    min-width: 0 !important;
    max-width: 100% !important;
}

/* Keep hero and stats from forcing sideways scroll at browser zoom */
.hero-shell,
.soft-shell,
.stat-card,
.student-card,
#tableView,
#cardView {
    min-width: 0 !important;
    max-width: 100% !important;
}

/* The filter row was overflowing at 110% zoom because 6 fields + buttons were acting like
   a tiny train trying to fit through a doorway. This makes it wrap sanely. */
#filterForm > .flex {
    width: 100% !important;
    min-width: 0 !important;
}

#filterForm .flex-1 {
    min-width: 0 !important;
}

#filterForm .flex-1.grid {
    min-width: 0 !important;
    width: 100% !important;
}

#filterForm .flex-1.grid > div {
    min-width: 0 !important;
}

#filterForm .filter-input {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
}

#filterForm select.filter-input {
    text-overflow: ellipsis !important;
}

/* Large desktop: all fields in one row only when there is actually enough room */
@media (min-width: 1536px) {
    #filterForm > .flex {
        flex-direction: row !important;
        align-items: flex-end !important;
    }

    #filterForm .flex-1.grid {
        grid-template-columns:
            minmax(260px, 1.7fr)
            minmax(130px, 1fr)
            minmax(130px, 1fr)
            minmax(140px, 1fr)
            minmax(135px, 1fr)
            minmax(135px, 1fr) !important;
        gap: 12px !important;
    }

    #filterForm .flex-1.grid > div:first-child {
        grid-column: auto !important;
    }

    #filterForm > .flex > .flex.gap-2 {
        flex: 0 0 auto !important;
        align-items: end !important;
        justify-content: flex-end !important;
        white-space: nowrap !important;
    }
}

/* Normal laptop / zoomed browser: put Apply + Reset on next line, no clipping. */
@media (min-width: 1024px) and (max-width: 1535px) {
    #filterForm > .flex {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 14px !important;
    }

    #filterForm .flex-1.grid {
        grid-template-columns:
            minmax(230px, 1.6fr)
            minmax(130px, 1fr)
            minmax(130px, 1fr)
            minmax(135px, 1fr)
            minmax(132px, 1fr)
            minmax(132px, 1fr) !important;
        gap: 12px !important;
    }

    #filterForm .flex-1.grid > div:first-child {
        grid-column: auto !important;
    }

    #filterForm > .flex > .flex.gap-2 {
        width: 100% !important;
        justify-content: flex-end !important;
        flex-wrap: wrap !important;
        gap: 10px !important;
        padding-right: 0 !important;
    }
}

/* Tablet: 2 columns, search full width */
@media (min-width: 768px) and (max-width: 1023px) {
    #filterForm > .flex {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    #filterForm .flex-1.grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 12px !important;
    }

    #filterForm .flex-1.grid > div:first-child {
        grid-column: 1 / -1 !important;
    }

    #filterForm > .flex > .flex.gap-2 {
        width: 100% !important;
        justify-content: flex-end !important;
        flex-wrap: wrap !important;
    }
}

/* Mobile: one column + full-width buttons */
@media (max-width: 767px) {
    #filterForm > .flex {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    #filterForm .flex-1.grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }

    #filterForm .flex-1.grid > div:first-child {
        grid-column: auto !important;
    }

    #filterForm > .flex > .flex.gap-2 {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
    }

    #filterForm button[type="submit"],
    #filterForm button[type="button"] {
        width: 100% !important;
        padding-left: .8rem !important;
        padding-right: .8rem !important;
    }
}

/* Extra small: buttons stack too, because phones are not cinema screens. */
@media (max-width: 420px) {
    #filterForm > .flex > .flex.gap-2 {
        grid-template-columns: 1fr !important;
    }
}

/* Header right profile/notification should not shove content sideways */
header .flex.items-center.gap-3 {
    min-width: 0 !important;
}

header .hidden.md\:block {
    min-width: 0 !important;
}

header .hidden.md\:block p {
    max-width: 140px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}

/* Keep table scroll inside table card only, not whole page */
#tableView .overflow-x-auto,
.scrollbar-soft {
    max-width: 100% !important;
    overflow-x: auto !important;
}
</style>

</head>
<body class="relative overflow-x-hidden">
    <?php include '../t_sidebar.php'; ?>

    <div class="ml-0 lg:ml-64 transition-all duration-300 min-h-screen">
        <header class="sticky top-0 z-30 bg-white/76 backdrop-blur-xl border-b border-white/60 shadow-sm">
            <div class="px-4 sm:px-6 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-100 text-indigo-600 grid place-items-center shadow-sm">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-extrabold text-gray-900">My Students</h1>
                        <p class="text-xs text-gray-500 hidden sm:block">Track learners, batches, attendance, and scores.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <?php if (file_exists('../trainer_notification_bell.php')) include '../trainer_notification_bell.php'; ?>
                    <div class="flex items-center gap-2 pl-3 border-l border-gray-200">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-r from-purple-500 to-pink-500 grid place-items-center text-white font-extrabold">
                            <?= strtoupper(substr($trainer['name'] ?? 'T', 0, 1)) ?>
                        </div>
                        <div class="hidden md:block leading-tight">
                            <p class="text-sm font-bold text-gray-800"><?= safeText($trainer['name'] ?? 'Trainer', 'Trainer') ?></p>
                            <p class="text-xs text-gray-500">Trainer</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6 space-y-6">
            <section class="hero-shell p-5 sm:p-7" data-aos="fade-up">
                <div class="relative z-10 grid grid-cols-1 xl:grid-cols-3 gap-6 items-center">
                    <div class="xl:col-span-2 text-white">
                        <div class="flex flex-wrap gap-2 mb-4">
                            <span class="quick-chip"><i class="fas fa-users"></i> Student Workspace</span>
                            <span class="quick-chip"><i class="fas fa-user"></i> <?= safeText($trainer['name'] ?? 'Trainer') ?></span>
                            <span class="quick-chip"><i class="fas fa-calendar-day"></i> <?= date('l, d M Y') ?></span>
                        </div>
                        <h2 class="text-3xl sm:text-4xl font-black tracking-tight mb-2">Student Performance Hub</h2>
                        <p class="text-white/85 max-w-2xl text-sm sm:text-base">Review assigned students, filter by batch/course/status, and quickly understand attendance and exam performance from one clean workspace.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="mini-stat p-4 text-white">
                            <p class="text-[11px] uppercase font-black text-white/70">Total Students</p>
                            <p class="text-3xl font-black mt-1"><?= $totalResults ?></p>
                        </div>
                        <div class="mini-stat p-4 text-white">
                            <p class="text-[11px] uppercase font-black text-white/70">Active</p>
                            <p class="text-3xl font-black mt-1"><?= $statusCounts['active'] ?></p>
                        </div>
                        <div class="mini-stat p-4 text-white">
                            <p class="text-[11px] uppercase font-black text-white/70">Avg Attendance</p>
                            <p class="text-3xl font-black mt-1"><?= $avgAttendance ?>%</p>
                        </div>
                        <div class="mini-stat p-4 text-white">
                            <p class="text-[11px] uppercase font-black text-white/70">Avg Score</p>
                            <p class="text-3xl font-black mt-1"><?= $avgScore ?>%</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4" data-aos="fade-up" data-aos-delay="80">
                <div class="stat-card p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500 font-black uppercase">Total</p>
                            <p class="text-3xl font-black text-gray-900 mt-1"><?= $totalResults ?></p>
                        </div>
                        <div class="stat-icon" style="background: var(--primary-gradient)"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="progress-line mt-4"><div class="progress-fill" style="width:100%;background:var(--primary-gradient)"></div></div>
                </div>
                <div class="stat-card p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500 font-black uppercase">Active</p>
                            <p class="text-3xl font-black text-emerald-600 mt-1"><?= $statusCounts['active'] ?></p>
                        </div>
                        <div class="stat-icon" style="background: var(--success-gradient)"><i class="fas fa-user-check"></i></div>
                    </div>
                    <div class="progress-line mt-4"><div class="progress-fill bg-gradient-to-r from-emerald-400 to-green-500" style="width:<?= $totalResults > 0 ? round(($statusCounts['active'] / $totalResults) * 100) : 0 ?>%"></div></div>
                </div>
                <div class="stat-card p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500 font-black uppercase">Completed</p>
                            <p class="text-3xl font-black text-orange-600 mt-1"><?= $statusCounts['completed'] ?></p>
                        </div>
                        <div class="stat-icon" style="background: var(--warning-gradient)"><i class="fas fa-user-graduate"></i></div>
                    </div>
                    <div class="progress-line mt-4"><div class="progress-fill bg-gradient-to-r from-amber-400 to-orange-500" style="width:<?= $totalResults > 0 ? round(($statusCounts['completed'] / $totalResults) * 100) : 0 ?>%"></div></div>
                </div>
                <div class="stat-card p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500 font-black uppercase">Attendance</p>
                            <p class="text-3xl font-black text-sky-600 mt-1"><?= $avgAttendance ?>%</p>
                        </div>
                        <div class="stat-icon" style="background: var(--cool-gradient)"><i class="fas fa-clipboard-check"></i></div>
                    </div>
                    <div class="progress-line mt-4"><div class="progress-fill bg-gradient-to-r from-blue-500 to-cyan-400" style="width:<?= $avgAttendance ?>%"></div></div>
                </div>
                <div class="stat-card p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500 font-black uppercase">Avg Score</p>
                            <p class="text-3xl font-black text-fuchsia-600 mt-1"><?= $avgScore ?>%</p>
                        </div>
                        <div class="stat-icon" style="background: linear-gradient(135deg,#234C6A,#456882)"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="progress-line mt-4"><div class="progress-fill bg-gradient-to-r from-purple-500 to-pink-500" style="width:<?= $avgScore ?>%"></div></div>
                </div>
            </section>

            <section class="soft-shell p-4 sm:p-5" data-aos="fade-up" data-aos-delay="120">
                <form method="GET" action="" id="filterForm">
                    <input type="hidden" name="page" value="1">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortField) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
                    <div class="flex flex-col xl:flex-row xl:items-end gap-4">
                        <div class="flex-1 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
                            <div class="xl:col-span-2">
                                <label class="text-xs font-black text-gray-500 uppercase mb-1 block">Search</label>
                                <div class="relative">
                                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" class="filter-input pl-10" placeholder="Name, email, phone, ID...">
                                </div>
                            </div>
                            <div>
                                <label class="text-xs font-black text-gray-500 uppercase mb-1 block">Batch</label>
                                <select id="batch" name="batch" class="filter-input">
                                    <option value="">All Batches</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $filter_batch == $batch['batch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($batch['batch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-black text-gray-500 uppercase mb-1 block">Status</label>
                                <select id="status" name="status" class="filter-input">
                                    <option value="">All Status</option>
                                    <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="on hold" <?= $filter_status === 'on hold' ? 'selected' : '' ?>>On Hold</option>
                                    <option value="transferred" <?= $filter_status === 'transferred' ? 'selected' : '' ?>>Transferred</option>
                                    <option value="dropped" <?= $filter_status === 'dropped' ? 'selected' : '' ?>>Dropped</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-black text-gray-500 uppercase mb-1 block">Course</label>
                                <select id="course" name="course" class="filter-input">
                                    <option value="">All Courses</option>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?= htmlspecialchars($course['id']) ?>" <?= $filter_course == $course['id'] ? 'selected' : '' ?>><?= htmlspecialchars($course['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-black text-gray-500 uppercase mb-1 block">From</label>
                                <input type="date" id="enrollment_from" name="enrollment_from" value="<?= htmlspecialchars($enrollmentDateFrom) ?>" class="filter-input">
                            </div>
                            <div>
                                <label class="text-xs font-black text-gray-500 uppercase mb-1 block">To</label>
                                <input type="date" id="enrollment_to" name="enrollment_to" value="<?= htmlspecialchars($enrollmentDateTo) ?>" class="filter-input">
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="px-5 py-3 rounded-2xl text-white font-extrabold shadow-lg hover:opacity-95 transition" style="background: var(--primary-gradient)"><i class="fas fa-filter mr-2"></i>Apply</button>
                            <button type="button" onclick="resetFilters()" class="px-5 py-3 rounded-2xl bg-white text-gray-700 border border-gray-200 font-extrabold hover:bg-gray-50 transition"><i class="fas fa-rotate-right mr-2"></i>Reset</button>
                        </div>
                    </div>
                </form>
            </section>

            <section class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <span class="section-label"><i class="fas fa-list"></i> Student Directory</span>
                    <h3 class="text-2xl font-black text-gray-900 mt-2">Assigned Students</h3>
                    <p class="text-sm text-gray-500">Showing <b><?= $totalResults > 0 ? $offset + 1 : 0 ?></b> to <b><?= min($offset + $perPage, $totalResults) ?></b> of <b><?= $totalResults ?></b> students.</p>
                </div>
                <div class="inline-flex bg-white/80 border border-white/60 p-1 rounded-2xl shadow-sm">
                    <button type="button" id="tableViewBtn" class="view-toggle active"><i class="fas fa-table mr-2"></i>Table</button>
                    <button type="button" id="cardViewBtn" class="view-toggle"><i class="fas fa-grip mr-2"></i>Cards</button>
                </div>
            </section>

            <section id="tableView" class="soft-shell overflow-hidden" data-aos="fade-up">
                <?php if (count($students) > 0): ?>
                    <div class="overflow-x-auto scrollbar-soft">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-gradient-to-r from-indigo-50 to-pink-50 text-gray-600 uppercase text-xs tracking-wider">
                                    <th class="px-5 py-4 text-left"><a href="<?= sortUrl('s.student_id') ?>" class="font-black">Student ID <?= isActiveSort('s.student_id', $sortField) ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                                    <th class="px-5 py-4 text-left"><a href="<?= sortUrl('s.first_name') ?>" class="font-black">Student <?= isActiveSort('s.first_name', $sortField) ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                                    <th class="px-5 py-4 text-left"><a href="<?= sortUrl('c.name') ?>" class="font-black">Course <?= isActiveSort('c.name', $sortField) ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                                    <th class="px-5 py-4 text-left"><a href="<?= sortUrl('batch_name_display') ?>" class="font-black">Batch <?= isActiveSort('batch_name_display', $sortField) ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                                    <th class="px-5 py-4 text-left"><a href="<?= sortUrl('s.current_status') ?>" class="font-black">Status <?= isActiveSort('s.current_status', $sortField) ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                                    <th class="px-5 py-4 text-left font-black">Performance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white/80">
                                <?php foreach ($students as $student): ?>
                                    <?php
                                        $attendancePercent = (!empty($student['total_attendance']) && $student['total_attendance'] > 0) ? round(($student['attendance_count'] / $student['total_attendance']) * 100, 1) : null;
                                        $scorePercent = $student['avg_score'] !== null ? round((float)$student['avg_score'], 1) : null;
                                        $avatarIndex = (abs(crc32($student['student_id'])) % 5) + 1;
                                        $avatarClass = $avatarIndex === 1 ? 'avatar-gradient' : 'avatar-gradient-' . $avatarIndex;
                                    ?>
                                    <tr class="student-row">
                                        <td class="px-5 py-4 whitespace-nowrap">
                                            <span class="font-black text-gray-900"><?= safeText($student['student_id']) ?></span>
                                            <p class="text-xs text-gray-500 mt-1">Enrolled <?= !empty($student['enrollment_date']) ? date('M j, Y', strtotime($student['enrollment_date'])) : 'N/A' ?></p>
                                        </td>
                                        <td class="px-5 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                                    <img src="<?= htmlspecialchars($student['profile_picture']) ?>" alt="<?= safeText($student['first_name']) ?>" class="w-11 h-11 rounded-2xl object-cover shadow-sm">
                                                <?php else: ?>
                                                    <div class="w-11 h-11 rounded-2xl <?= $avatarClass ?> text-white grid place-items-center font-black shadow-sm"><?= initials($student['first_name'], $student['last_name']) ?></div>
                                                <?php endif; ?>
                                                <div>
                                                    <p class="font-black text-gray-900"><?= safeText($student['first_name'] . ' ' . $student['last_name']) ?></p>
                                                    <p class="text-xs text-gray-500"><?= safeText($student['email'], 'No email') ?></p>
                                                    <p class="text-xs text-gray-400"><?= safeText($student['phone_number'], 'No phone') ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-gray-800"><?= safeText($student['course_name'], 'No course') ?></p>
                                            <p class="text-xs text-gray-500 mt-1"><?= safeText($student['mode'], 'Mode N/A') ?> · <?= safeText($student['platform'], 'Platform N/A') ?></p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-gray-800"><?= safeText($student['batch_name_display'], 'No batch') ?></p>
                                            <p class="text-xs text-gray-500 mt-1"><?= safeText($student['time_slot'], 'No schedule') ?></p>
                                            <div class="flex flex-wrap gap-1 mt-2">
                                                <?php if (!empty($student['batch_name'])): ?><span class="batch-indicator batch-primary"><i class="fas fa-layer-group"></i>1</span><?php endif; ?>
                                                <?php if (!empty($student['batch_name_2'])): ?><span class="batch-indicator batch-secondary"><i class="fas fa-layer-group"></i>2</span><?php endif; ?>
                                                <?php if (!empty($student['batch_name_3'])): ?><span class="batch-indicator batch-tertiary"><i class="fas fa-layer-group"></i>3</span><?php endif; ?>
                                                <?php if (!empty($student['batch_name_4'])): ?><span class="batch-indicator batch-fourth"><i class="fas fa-layer-group"></i>4</span><?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-5 py-4"><span class="status-badge <?= statusBadgeClass($student['current_status']) ?>"><i class="fas fa-circle text-[7px]"></i><?= ucfirst($student['current_status']) ?></span></td>
                                        <td class="px-5 py-4 min-w-[220px]">
                                            <div class="space-y-3">
                                                <div>
                                                    <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Attendance</span><b><?= $attendancePercent !== null ? $attendancePercent . '%' : 'N/A' ?></b></div>
                                                    <div class="progress-line"><div class="progress-fill bg-gradient-to-r from-emerald-400 to-cyan-400" style="width:<?= $attendancePercent ?? 0 ?>%"></div></div>
                                                </div>
                                                <div>
                                                    <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Average Score</span><b><?= $scorePercent !== null ? $scorePercent . '%' : 'N/A' ?></b></div>
                                                    <div class="progress-line"><div class="progress-fill bg-gradient-to-r from-purple-500 to-pink-500" style="width:<?= $scorePercent ?? 0 ?>%"></div></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state m-6 p-10 text-center">
                        <div class="w-20 h-20 mx-auto rounded-3xl bg-indigo-100 text-indigo-500 grid place-items-center mb-4"><i class="fas fa-user-slash text-3xl"></i></div>
                        <h3 class="text-xl font-black text-gray-900">No students found</h3>
                        <p class="text-gray-500 text-sm mt-2">No students match your current filter criteria or assignment setup.</p>
                        <button onclick="resetFilters()" class="mt-5 px-5 py-3 rounded-2xl text-white font-extrabold" style="background: var(--primary-gradient)"><i class="fas fa-rotate-right mr-2"></i>Clear Filters</button>
                    </div>
                <?php endif; ?>
            </section>

            <section id="cardView" class="hidden grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                <?php if (count($students) > 0): ?>
                    <?php foreach ($students as $student): ?>
                        <?php
                            $attendancePercent = (!empty($student['total_attendance']) && $student['total_attendance'] > 0) ? round(($student['attendance_count'] / $student['total_attendance']) * 100, 1) : null;
                            $scorePercent = $student['avg_score'] !== null ? round((float)$student['avg_score'], 1) : null;
                            $avatarIndex = (abs(crc32($student['student_id'])) % 5) + 1;
                            $avatarClass = $avatarIndex === 1 ? 'avatar-gradient' : 'avatar-gradient-' . $avatarIndex;
                        ?>
                        <article class="student-card" data-aos="fade-up">
                            <div class="p-5 text-white" style="background: var(--primary-gradient)">
                                <div class="flex justify-between gap-3">
                                    <div>
                                        <p class="text-xs uppercase font-black text-white/70">Student ID</p>
                                        <h3 class="student-card-id font-black mt-1"><?= safeText($student['student_id']) ?></h3>
                                    </div>
                                    <span class="status-badge bg-white/20 text-white border border-white/25"><i class="fas fa-circle text-[7px]"></i><?= ucfirst($student['current_status']) ?></span>
                                </div>
                            </div>
                            <div class="p-5">
                                <div class="flex items-center gap-4 -mt-7 mb-4">
                                    <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                        <img src="<?= htmlspecialchars($student['profile_picture']) ?>" alt="<?= safeText($student['first_name']) ?>" class="w-16 h-16 rounded-3xl object-cover border-4 border-white shadow-lg">
                                    <?php else: ?>
                                        <div class="w-16 h-16 rounded-3xl <?= $avatarClass ?> text-white grid place-items-center font-black text-lg border-4 border-white shadow-lg"><?= initials($student['first_name'], $student['last_name']) ?></div>
                                    <?php endif; ?>
                                    <div class="pt-6 min-w-0">
                                        <h4 class="text-lg font-black text-gray-900 truncate"><?= safeText($student['first_name'] . ' ' . $student['last_name']) ?></h4>
                                        <p class="text-xs text-gray-500 truncate"><?= safeText($student['email'], 'No email') ?></p>
                                    </div>
                                </div>

                                <div class="space-y-3 text-sm">
                                    <div class="flex gap-3"><i class="fas fa-book text-indigo-500 w-5 mt-1"></i><div><p class="text-xs text-gray-500 font-bold uppercase">Course</p><p class="font-bold text-gray-800"><?= safeText($student['course_name'], 'No course') ?></p></div></div>
                                    <div class="flex gap-3"><i class="fas fa-layer-group text-purple-500 w-5 mt-1"></i><div><p class="text-xs text-gray-500 font-bold uppercase">Batch</p><p class="font-bold text-gray-800"><?= safeText($student['batch_name_display'], 'No batch') ?></p><p class="text-xs text-gray-500"><?= safeText($student['time_slot'], 'No schedule') ?></p></div></div>
                                    <div class="flex gap-3"><i class="fas fa-phone text-emerald-500 w-5 mt-1"></i><div><p class="text-xs text-gray-500 font-bold uppercase">Contact</p><p class="font-bold text-gray-800"><?= safeText($student['phone_number'], 'No phone') ?></p></div></div>
                                </div>

                                <div class="mt-5 space-y-3">
                                    <div>
                                        <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Attendance</span><b><?= $attendancePercent !== null ? $attendancePercent . '%' : 'N/A' ?></b></div>
                                        <div class="progress-line"><div class="progress-fill bg-gradient-to-r from-emerald-400 to-cyan-400" style="width:<?= $attendancePercent ?? 0 ?>%"></div></div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Average Score</span><b><?= $scorePercent !== null ? $scorePercent . '%' : 'N/A' ?></b></div>
                                        <div class="progress-line"><div class="progress-fill bg-gradient-to-r from-purple-500 to-pink-500" style="width:<?= $scorePercent ?? 0 ?>%"></div></div>
                                    </div>
                                </div>

                                <div class="flex justify-between items-center mt-5 pt-4 border-t border-gray-100 text-xs text-gray-500">
                                    <span><i class="fas fa-calendar-alt mr-1"></i><?= !empty($student['enrollment_date']) ? date('M j, Y', strtotime($student['enrollment_date'])) : 'N/A' ?></span>
                                    <span><i class="fas fa-<?= ($student['mode'] ?? '') === 'online' ? 'video' : 'building' ?> mr-1"></i><?= safeText($student['mode'], 'Mode') ?></span>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="md:col-span-2 xl:col-span-3 empty-state p-10 text-center">
                        <div class="w-20 h-20 mx-auto rounded-3xl bg-indigo-100 text-indigo-500 grid place-items-center mb-4"><i class="fas fa-user-slash text-3xl"></i></div>
                        <h3 class="text-xl font-black text-gray-900">No students found</h3>
                        <p class="text-gray-500 text-sm mt-2">No students match your current filter criteria.</p>
                        <button onclick="resetFilters()" class="mt-5 px-5 py-3 rounded-2xl text-white font-extrabold" style="background: var(--primary-gradient)">Clear Filters</button>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($totalPages > 1): ?>
                <section class="flex flex-wrap justify-center gap-2 py-2">
                    <?php if ($page > 1): ?><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"><i class="fas fa-chevron-left mr-2"></i>Prev</a><?php endif; ?>
                    <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a class="page-link <?= $i == $page ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next<i class="fas fa-chevron-right ml-2"></i></a><?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            AOS.init({ duration: 650, once: true, offset: 70 });

            const tableViewBtn = document.getElementById('tableViewBtn');
            const cardViewBtn = document.getElementById('cardViewBtn');
            const tableView = document.getElementById('tableView');
            const cardView = document.getElementById('cardView');

            const savedView = localStorage.getItem('trainerStudentsView') || 'table';
            setView(savedView);

            tableViewBtn.addEventListener('click', () => setView('table'));
            cardViewBtn.addEventListener('click', () => setView('card'));

            function setView(type) {
                const isTable = type === 'table';
                tableView.classList.toggle('hidden', !isTable);
                cardView.classList.toggle('hidden', isTable);
                tableViewBtn.classList.toggle('active', isTable);
                cardViewBtn.classList.toggle('active', !isTable);
                localStorage.setItem('trainerStudentsView', type);
                setTimeout(() => AOS.refresh(), 80);
            }

            const filterForm = document.getElementById('filterForm');
            filterForm.addEventListener('submit', function() {
                const submitBtn = filterForm.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Applying';
                submitBtn.disabled = true;
            });
        });

        function resetFilters() {
            window.location.href = window.location.pathname;
        }
    </script>

<script>
/* ===== Topbar avatar DOM sync =====
   PHP untouched. Since sidebar profile image is already working, this copies its src
   into the hardcoded initial icon in the page header. Yes, we are politely outsmarting hardcoded UI. */
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

        /* Main header top-right profile wrapper */
        document.querySelectorAll('header .flex.items-center.gap-2, header .flex.items-center.space-x-2').forEach(function (profileWrap) {
            const nameText = profileWrap.textContent || '';
            if (!nameText.includes('Trainer')) return;

            const firstBox = profileWrap.querySelector('div:first-child');
            replaceBoxWithImage(firstBox, src, false);
        });

        /* Fallback: direct hardcoded circular initial boxes near header right */
        document.querySelectorAll('header div').forEach(function (box) {
            const txt = (box.textContent || '').trim();
            const className = box.className || '';
            if (
                txt.length <= 2 &&
                className.includes('rounded-full') &&
                (className.includes('font-extrabold') || className.includes('font-bold')) &&
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
