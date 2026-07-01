<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// ----------------------------------------------------
// 1. Core Quick Stats
// ----------------------------------------------------

$batch_stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_batches,
        SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming_batches,
        SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing_batches,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_batches
    FROM batches
");
$batch_stats = $batch_stats_stmt->fetch();

$course_stats_stmt = $db->query("SELECT COUNT(*) as total_courses FROM courses");
$total_courses = $course_stats_stmt->fetchColumn();

$trainer_stats_stmt = $db->query("SELECT COUNT(*) as active_trainers FROM trainers WHERE is_active = 1");
$active_trainers = $trainer_stats_stmt->fetchColumn();

$student_stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_students,
        SUM(CASE WHEN current_status = 'active' THEN 1 ELSE 0 END) as active_students,
        SUM(CASE WHEN current_status = 'dropped' THEN 1 ELSE 0 END) as dropped_students,
        SUM(CASE WHEN current_status = 'completed' THEN 1 ELSE 0 END) as graduated_students,
        SUM(CASE WHEN fees_status = 'fully_paid' THEN 1 ELSE 0 END) as fully_paid,
        SUM(CASE WHEN fees_status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid,
        SUM(CASE WHEN fees_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid,
        SUM(CASE WHEN fees_status = 'overdue' THEN 1 ELSE 0 END) as overdue
    FROM students
");
$student_stats = $student_stats_stmt->fetch();

$ticket_stats_stmt = $db->query("
    SELECT 
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets
    FROM tickets
");
$ticket_stats = $ticket_stats_stmt->fetch();

// ----------------------------------------------------
// 2. Chart Data Queries
// ----------------------------------------------------

// Enrollment Trend (Last 6 Months)
$enrollment_trend_stmt = $db->query("
    SELECT DATE_FORMAT(enrollment_date, '%b %Y') as month, COUNT(*) as count
    FROM students
    WHERE enrollment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(enrollment_date, '%Y-%m')
    ORDER BY MIN(enrollment_date) ASC
");
$enrollment_trend = $enrollment_trend_stmt->fetchAll();
$enrollment_labels = array_column($enrollment_trend, 'month');
$enrollment_data = array_column($enrollment_trend, 'count');

// Attendance Trend (Last 14 Days)
$attendance_trend_stmt = $db->query("
    SELECT DATE_FORMAT(date, '%d %b') as day, COUNT(id) as total_records, SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count
    FROM attendance
    WHERE date >= DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY)
    GROUP BY date ORDER BY date ASC
");
$attendance_trend = $attendance_trend_stmt->fetchAll();
$attendance_labels = array_column($attendance_trend, 'day');
$attendance_data = [];
foreach ($attendance_trend as $row) {
    $attendance_data[] = $row['total_records'] > 0 ? round(($row['present_count'] / $row['total_records']) * 100, 1) : 0;
}

// Course Popularity (Top 5)
$course_pop_stmt = $db->query("
    SELECT c.name, COUNT(s.student_id) as student_count
    FROM courses c LEFT JOIN students s ON c.id = s.course AND s.current_status = 'active'
    GROUP BY c.id ORDER BY student_count DESC LIMIT 5
");
$course_popularity = $course_pop_stmt->fetchAll();
$course_labels = array_column($course_popularity, 'name');
$course_data = array_column($course_popularity, 'student_count');

// Feedback Ratings Distribution (1 to 5 Stars)
$feedback_stmt = $db->query("
    SELECT rating, COUNT(*) as count 
    FROM feedback 
    WHERE rating IS NOT NULL 
    GROUP BY rating ORDER BY rating ASC
");
$feedback_raw = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);
$feedback_distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
foreach($feedback_raw as $f) {
    $r = intval($f['rating']);
    if($r >= 1 && $r <= 5) {
        $feedback_distribution[$r] = $f['count'];
    }
}
$feedback_labels = ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'];
$feedback_data = array_values($feedback_distribution);

// Ongoing Batches & Courses
$ongoing_batches_stmt = $db->query("
    SELECT b.batch_name, b.start_date, b.mode, GROUP_CONCAT(c.name SEPARATOR '||') as course_names 
    FROM batches b 
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id 
    LEFT JOIN courses c ON bc.course_id = c.id 
    WHERE b.status = 'ongoing' OR (b.start_date <= CURRENT_DATE AND (b.end_date IS NULL OR b.end_date >= CURRENT_DATE))
    GROUP BY b.batch_id 
    ORDER BY b.start_date DESC LIMIT 5
");
$ongoing_batches = $ongoing_batches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Upcoming Batches & Courses
$upcoming_batches_stmt = $db->query("
    SELECT b.batch_name, b.start_date, b.mode, GROUP_CONCAT(c.name SEPARATOR '||') as course_names 
    FROM batches b 
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id 
    LEFT JOIN courses c ON bc.course_id = c.id 
    WHERE b.status = 'upcoming' OR b.start_date > CURRENT_DATE
    GROUP BY b.batch_id 
    ORDER BY b.start_date ASC LIMIT 5
");
$upcoming_batches = $upcoming_batches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Recently Completed Batches & Courses (Last 30 Days)
$completed_batches_stmt = $db->query("
    SELECT b.batch_name, b.end_date, b.mode, GROUP_CONCAT(c.name SEPARATOR '||') as course_names 
    FROM batches b 
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id 
    LEFT JOIN courses c ON bc.course_id = c.id 
    WHERE b.status = 'completed' OR (b.end_date <= CURRENT_DATE AND b.end_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY))
    GROUP BY b.batch_id 
    ORDER BY b.end_date DESC LIMIT 5
");
$completed_batches = $completed_batches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Ongoing Courses
$ongoing_courses_stmt = $db->query("
    SELECT c.name, GROUP_CONCAT(DISTINCT b.batch_name SEPARATOR '||') as batch_names 
    FROM courses c
    JOIN batch_courses bc ON c.id = bc.course_id
    JOIN batches b ON bc.batch_id = b.batch_id
    WHERE b.status = 'ongoing' OR (b.start_date <= CURRENT_DATE AND (b.end_date IS NULL OR b.end_date >= CURRENT_DATE))
    GROUP BY c.id
    ORDER BY c.name ASC LIMIT 5
");
$ongoing_courses = $ongoing_courses_stmt->fetchAll(PDO::FETCH_ASSOC);

// Upcoming Courses
$upcoming_courses_stmt = $db->query("
    SELECT c.name, GROUP_CONCAT(DISTINCT b.batch_name SEPARATOR '||') as batch_names 
    FROM courses c
    JOIN batch_courses bc ON c.id = bc.course_id
    JOIN batches b ON bc.batch_id = b.batch_id
    WHERE b.status = 'upcoming' OR b.start_date > CURRENT_DATE
    GROUP BY c.id
    ORDER BY c.name ASC LIMIT 5
");
$upcoming_courses = $upcoming_courses_stmt->fetchAll(PDO::FETCH_ASSOC);

// Completed Courses
$completed_courses_stmt = $db->query("
    SELECT c.name, GROUP_CONCAT(DISTINCT b.batch_name SEPARATOR '||') as batch_names 
    FROM courses c
    JOIN batch_courses bc ON c.id = bc.course_id
    JOIN batches b ON bc.batch_id = b.batch_id
    WHERE b.status = 'completed' OR (b.end_date <= CURRENT_DATE AND b.end_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY))
    GROUP BY c.id
    ORDER BY c.name ASC LIMIT 5
");
$completed_courses = $completed_courses_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprehensive Analytics - ASD Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* =========================================================
           DESIGN TOKENS — ASD Academy Palette
           ========================================================= */
        :root {
            --navy-900:  #1B3C53;
            --navy-700:  #234C6A;
            --navy-500:  #456882;
            --sand-300:  #D2C1B6;
            --surface:   #F2EDE9;

            --navy-900-10: rgba(27,60,83,0.10);
            --navy-900-06: rgba(27,60,83,0.05);
            --navy-700-15: rgba(35,76,106,0.15);
            --navy-700-25: rgba(35,76,106,0.25);
            --sand-300-40: rgba(210,193,182,0.55);

            /* semantic status */
            --clr-ongoing:    #1a7f5a;
            --clr-ongoing-bg: rgba(26,127,90,0.10);
            --clr-upcoming:   #b45309;
            --clr-upcoming-bg:rgba(180,83,9,0.10);
            --clr-completed:  #456882;
            --clr-completed-bg:rgba(69,104,130,0.10);

            --radius-sm:   8px;
            --radius-md:   12px;
            --radius-lg:   16px;
            --radius-xl:   20px;
            --radius-pill: 999px;

            --shadow-card:       0 2px 12px rgba(27,60,83,0.09), 0 1px 4px rgba(27,60,83,0.05);
            --shadow-card-hover: 0 10px 28px rgba(27,60,83,0.15), 0 4px 10px rgba(27,60,83,0.08);
            --shadow-btn:        0 2px 8px rgba(35,76,106,0.22);
            --shadow-btn-hover:  0 5px 16px rgba(35,76,106,0.30);

            --font-body:    'Inter', sans-serif;
            --font-display: 'Plus Jakarta Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: linear-gradient(160deg, #F2EDE9 0%, #F5F0EC 100%);
            font-family: var(--font-body);
            color: var(--navy-900);
            min-height: 100vh;
        }

        /* =========================================================
           LAYOUT
           ========================================================= */
        .analytics-main {
    margin-left: 0;
    min-height: 100vh;
    padding: 20px 24px 40px;
    transition: margin 0.3s ease;
}

        @media (min-width: 768px) {
            .analytics-main { margin-left: 256px; }
        }

        .container-inner {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* =========================================================
           PAGE HEADER
           ========================================================= */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 36px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(210,193,182,0.65);
        }

        @media (min-width: 640px) {
            .page-header { flex-direction: row; align-items: flex-start; justify-content: space-between; }
        }

        .page-header h1 {
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 1.75rem;
            color: var(--navy-900);
            letter-spacing: -0.4px;
            line-height: 1.2;
        }

        .page-header p {
            font-size: 0.875rem;
            color: var(--navy-500);
            margin-top: 6px;
            line-height: 1.5;
        }

        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--navy-900);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            padding: 10px 20px;
            font-family: var(--font-body);
            font-size: 0.8375rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: var(--shadow-btn);
            transition: all 0.22s ease;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn-export:hover {
            background: var(--navy-700);
            transform: translateY(-1px);
            box-shadow: var(--shadow-btn-hover);
        }

        /* =========================================================
           SECTION TITLES
           ========================================================= */
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: var(--font-display);
            font-size: 1rem;
            font-weight: 700;
            color: var(--navy-900);
            margin-bottom: 20px;
            margin-top: 40px;
            padding-bottom: 14px;
            border-bottom: 2px solid #1B3C53;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section-title:first-of-type { margin-top: 0; }

        .section-title-icon {
            width: 34px; height: 34px;
            background: rgba(210,193,182,0.35);
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            color: var(--navy-700);
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* =========================================================
           STAT CARDS ROW
           ========================================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-bottom: 36px;
        }

        @media (min-width: 640px)  { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1024px) { .stats-grid { grid-template-columns: repeat(6, 1fr); } }

        .stat-card {
            background: linear-gradient(160deg, #FFFFFF 50%, rgba(210,193,182,0.18) 100%);
            border: 2px solid #1B3C53;
            border-radius: var(--radius-xl);
            padding: 20px 14px;
            text-align: center;
            box-shadow: var(--shadow-card);
            transition: transform 0.22s ease, box-shadow 0.22s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 3px;
            background: linear-gradient(90deg, var(--navy-900), var(--navy-700), var(--navy-500));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }

        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-card-hover); }
        .stat-card:hover::after { transform: scaleX(1); }

        .stat-icon {
            width: 46px; height: 46px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem;
            margin: 0 auto 12px;
        }

        .stat-icon.navy    { background: rgba(210,193,182,0.28); color: var(--navy-700); }
        .stat-icon.green   { background: rgba(26,127,90,0.10); color: #1a7f5a; }
        .stat-icon.amber   { background: rgba(180,83,9,0.10);  color: #b45309; }
        .stat-icon.purple  { background: rgba(76,53,117,0.10); color: #4c3575; }
        .stat-icon.teal    { background: rgba(20,111,75,0.10); color: #146346; }
        .stat-icon.crimson { background: rgba(153,27,27,0.10); color: #991b1b; }

        .stat-label {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 6px;
        }

        .stat-value {
            font-family: var(--font-display);
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--navy-900);
            line-height: 1;
        }

        /* =========================================================
           CHART / CONTENT CARDS
           ========================================================= */
        .chart-card {
            background: linear-gradient(160deg, #FFFFFF 60%, rgba(210,193,182,0.14) 100%);
            border: 2px solid #1B3C53;
            border-top: 3px solid #456882;
            transition: box-shadow .22s, transform .22s;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            padding: 22px;
        }

        .chart-card-title {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--navy-700);
            text-transform: uppercase;
            letter-spacing: 0.7px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-card-title::before {
            content: '';
            display: inline-block;
            width: 4px; height: 16px;
            background: linear-gradient(180deg, var(--navy-900), var(--navy-500));
            border-radius: var(--radius-pill);
            flex-shrink: 0;
        }

        .chart-wrap {
            position: relative;
        }

        .chart-wrap-lg  { height: 280px; }
        .chart-wrap-md  { height: 220px; }
        .chart-wrap-sm  { height: 200px; }

        /* Center-align doughnut/pie wraps */
        .chart-wrap-center {
            display: flex;
            justify-content: center;
        }

        /* =========================================================
           GRID LAYOUTS
           ========================================================= */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media (min-width: 1024px) { .grid-2 { grid-template-columns: 1fr 1fr; } }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media (min-width: 768px)  { .grid-3 { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 1024px) { .grid-3 { grid-template-columns: 1fr 1fr 1fr; } }

        .grid-2x2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* Right column of engagement row */
        .engagement-right {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 640px) { .engagement-right { grid-template-columns: 1fr; } }

        /* =========================================================
           TRACKER TABLES (Batch & Course Schedule)
           ========================================================= */
        .tracker-card {
            background: linear-gradient(160deg, #FFFFFF 55%, rgba(210,193,182,0.16) 100%);
            border: 2px solid #1B3C53;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* Top colored accent */
        .tracker-card.ongoing   { border-top: 3px solid var(--clr-ongoing);   }
        .tracker-card.upcoming  { border-top: 3px solid var(--clr-upcoming);  }
        .tracker-card.completed { border-top: 3px solid var(--clr-completed); }
        .tracker-card.indigo    { border-top: 3px solid #4c3575; }
        .tracker-card.amber     { border-top: 3px solid #b45309; }
        .tracker-card.teal      { border-top: 3px solid #146346; }

        .tracker-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            background: rgba(210,193,182,0.18);
            border-bottom: 2px solid rgba(27,60,83,.18);
        }

        .tracker-header-icon {
            width: 28px; height: 28px;
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .tracker-card.ongoing   .tracker-header-icon { background: var(--clr-ongoing-bg);   color: var(--clr-ongoing);   }
        .tracker-card.upcoming  .tracker-header-icon { background: var(--clr-upcoming-bg);   color: var(--clr-upcoming);  }
        .tracker-card.completed .tracker-header-icon { background: var(--clr-completed-bg); color: var(--clr-completed); }
        .tracker-card.indigo    .tracker-header-icon { background: rgba(76,53,117,0.10);    color: #4c3575;              }
        .tracker-card.amber     .tracker-header-icon { background: rgba(180,83,9,0.10);     color: #b45309;              }
        .tracker-card.teal      .tracker-header-icon { background: rgba(20,111,75,0.10);    color: #146346;              }

        .tracker-header-title {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--navy-900);
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .tracker-body { flex: 1; overflow-x: auto; }

        .tracker-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tracker-table tbody tr {
            border-bottom: 1px solid var(--sand-300-40);
            transition: background 0.15s ease;
        }

        .tracker-table tbody tr:last-child { border-bottom: none; }
        .tracker-table tbody tr:hover { background: rgba(210,193,182,0.22); }

        .tracker-table td {
            padding: 14px 18px;
            vertical-align: top;
        }

        .tracker-batch-name {
            font-family: var(--font-display);
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--navy-900);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tracker-mode-badge {
            display: inline-block;
            font-size: 0.65rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: var(--radius-pill);
            background: rgba(35,76,106,0.10);
            color: var(--navy-700);
            border: 1px solid rgba(210,193,182,0.55);
            text-transform: capitalize;
        }

        .tracker-date {
            font-size: 0.78rem;
            font-weight: 500;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .tracker-card.ongoing   .tracker-date { color: var(--clr-ongoing);   }
        .tracker-card.upcoming  .tracker-date { color: var(--clr-upcoming);  }
        .tracker-card.completed .tracker-date { color: var(--clr-completed); }
        .tracker-card.indigo    .tracker-date { color: #4c3575; }
        .tracker-card.amber     .tracker-date { color: #b45309; }
        .tracker-card.teal      .tracker-date { color: #146346; }

        .tags-row { display: flex; flex-wrap: wrap; gap: 5px; }

        .tag {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 500;
            padding: 3px 9px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--sand-300-40);
            background: rgba(210,193,182,0.28);
            color: var(--navy-700);
        }

        .tag.indigo { background: var(--navy-900-06); color: var(--navy-700); border-color: var(--navy-900-10); }
        .tag.amber  { background: rgba(180,83,9,0.06); color: #b45309; border-color: rgba(180,83,9,0.15); }
        .tag.teal   { background: rgba(20,111,75,0.07); color: #146346; border-color: rgba(20,111,75,0.15); }

        .course-name-row {
            font-family: var(--font-display);
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--navy-900);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .course-name-row i { font-size: 0.8rem; color: var(--navy-500); }

        .tracker-sub-label {
            font-size: 0.7rem;
            color: var(--navy-500);
            margin-bottom: 6px;
            font-weight: 500;
        }

        .empty-row td {
            padding: 24px 18px;
            text-align: center;
            font-size: 0.85rem;
            color: var(--navy-500);
            font-style: italic;
        }

        /* =========================================================
           SPACING
           ========================================================= */
        .section-block { margin-bottom: 32px; }

        /* =========================================================
           PRINT
           ========================================================= */
        @media print {
            .analytics-main { margin-left: 0; padding-top: 20px; }
            .btn-export { display: none; }
        }

        /* ── PALETTE ENHANCEMENT ── */

        /* Chart card: hover lift */
        .chart-card:hover {
            box-shadow: 0 8px 28px rgba(27,60,83,0.13);
            transform: translateY(-2px);
        }


        /* ── Stat card bottom sand accent ── */
        .stat-card::before {
            content: '';
            position: absolute;
            bottom: 0; left: 0;
            width: 100%; height: 4px;
            background: linear-gradient(90deg, rgba(210,193,182,0.8), rgba(210,193,182,0.2));
        }

        /* ── Tracker card row hover: cream ── */
        .tracker-table tbody tr:last-child:hover { background: rgba(210,193,182,0.22); }

        /* ── Container inner: subtle card shadow context ── */
        .container-inner {
            background: transparent;
        }

        /* ── Section block separator ── */
        .section-block + .section-block {
            border-top: 1px solid rgba(210,193,182,0.40);
            padding-top: 4px;
        }
        /* Stat card: palette tinted hover */
        .stat-card:hover {
            background: rgba(27,60,83,0.02);
        }

        /* Section title icon: richer */
        .section-title-icon {
            background: rgba(35,76,106,0.12);
            color: var(--navy-700);
        }

        /* Chart cards: sand accent top border */
        .chart-card {
            border-top: 3px solid rgba(210,193,182,0.6);
        }

        /* Tracker: sand row separator */
        .tracker-table tbody tr {
            border-bottom: 1px solid rgba(210,193,182,0.38);
        }

        /* btn-export hover: deeper navy */
        .btn-export:hover {
            background: #152E41;
            box-shadow: 0 6px 20px rgba(27,60,83,0.32);
        }

        /* Page header title: slight gradient text effect */
        .page-header h1 {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Tracker card hover: subtle lift */
        .tracker-card {
            transition: box-shadow .22s, transform .22s;
        }
        .tracker-card:hover {
            box-shadow: 0 8px 24px rgba(27,60,83,0.13);
            transform: translateY(-2px);
        }

        /* Tag colors: more palette-consistent */
        .tag {
            transition: background .15s, color .15s;
        }
        .tag:hover {
            background: rgba(69,104,130,0.12);
            color: #1B3C53;
        }

        /* Stats grid: body bg tint */
        /* body bg already set above */
    </style>
</head>
<body>

    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <main class="analytics-main">
        <div class="container-inner">

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Comprehensive Analytics</h1>
                    <p>A detailed 360-degree view of Academy Operations, Engagement &amp; Performance.</p>
                </div>
                <button onclick="window.print()" class="btn-export">
                    <i class="fas fa-file-pdf"></i> Export Report
                </button>
            </div>

            <!-- ================================================
                 QUICK STATS
                 ================================================ -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon navy"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="stat-label">Ongoing Batches</div>
                    <div class="stat-value"><?= $batch_stats['ongoing_batches'] ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon amber"><i class="fas fa-calendar-plus"></i></div>
                    <div class="stat-label">Upcoming Batches</div>
                    <div class="stat-value"><?= $batch_stats['upcoming_batches'] ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-label">Active Students</div>
                    <div class="stat-value"><?= $student_stats['active_students'] ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-book-open"></i></div>
                    <div class="stat-label">Total Courses</div>
                    <div class="stat-value"><?= $total_courses ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon teal"><i class="fas fa-users-cog"></i></div>
                    <div class="stat-label">Active Trainers</div>
                    <div class="stat-value"><?= $active_trainers ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon crimson"><i class="fas fa-ticket-alt"></i></div>
                    <div class="stat-label">Open Tickets</div>
                    <div class="stat-value"><?= $ticket_stats['open_tickets'] ?? 0 ?></div>
                </div>
            </div>

            <!-- ================================================
                 SECTION 1: GROWTH & ACADEMICS
                 ================================================ -->
            <h2 class="section-title">
                <span class="section-title-icon"><i class="fas fa-chart-line"></i></span>
                Growth &amp; Academics
            </h2>

            <div class="grid-2 section-block">
                <!-- Enrollment Trend -->
                <div class="chart-card">
                    <div class="chart-card-title">Student Enrollment Growth (6 Months)</div>
                    <div class="chart-wrap chart-wrap-lg">
                        <canvas id="enrollmentChart"></canvas>
                    </div>
                </div>

                <!-- Course Popularity -->
                <div class="chart-card">
                    <div class="chart-card-title">Top 5 Most Popular Courses</div>
                    <div class="chart-wrap chart-wrap-lg">
                        <canvas id="courseChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- ================================================
                 SECTION 2: ENGAGEMENT & OPERATIONS
                 ================================================ -->
            <h2 class="section-title">
                <span class="section-title-icon"><i class="fas fa-users"></i></span>
                Engagement &amp; Operations
            </h2>

            <div class="grid-2 section-block">
                <!-- Attendance Trend -->
                <div class="chart-card">
                    <div class="chart-card-title">Academy Attendance Rate (Last 14 Days)</div>
                    <div class="chart-wrap chart-wrap-lg">
                        <canvas id="attendanceChart"></canvas>
                    </div>
                </div>

                <!-- Batch + Ticket mini doughnuts -->
                <div class="engagement-right">
                    <div class="chart-card">
                        <div class="chart-card-title">Batch Status</div>
                        <div class="chart-wrap chart-wrap-md chart-wrap-center">
                            <canvas id="batchStatusChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-title">Support Tickets</div>
                        <div class="chart-wrap chart-wrap-md chart-wrap-center">
                            <canvas id="ticketStatusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================================================
                 SECTION 3: STUDENT METRICS
                 ================================================ -->
            <h2 class="section-title">
                <span class="section-title-icon"><i class="fas fa-id-card"></i></span>
                Student Metrics
            </h2>

            <div class="grid-2 section-block">
                <!-- Student Journey Status -->
                <div class="chart-card">
                    <div class="chart-card-title">Student Journey Status</div>
                    <div class="chart-wrap chart-wrap-lg chart-wrap-center">
                        <canvas id="studentStatusChart"></canvas>
                    </div>
                </div>

                <!-- Feedback Distribution -->
                <div class="chart-card">
                    <div class="chart-card-title">Feedback Ratings Distribution</div>
                    <div class="chart-wrap chart-wrap-lg">
                        <canvas id="feedbackChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- ================================================
                 SECTION 4: BATCH SCHEDULE TRACKER
                 ================================================ -->
            <h2 class="section-title">
                <span class="section-title-icon"><i class="fas fa-calendar-check"></i></span>
                Batch &amp; Course Schedule Tracker
            </h2>

            <div class="grid-3 section-block">

                <!-- Ongoing Batches -->
                <div class="tracker-card ongoing">
                    <div class="tracker-header">
                        <div class="tracker-header-icon"><i class="fas fa-play-circle"></i></div>
                        <div class="tracker-header-title">Ongoing Batches</div>
                    </div>
                    <div class="tracker-body">
                        <table class="tracker-table">
                            <tbody>
                                <?php if (empty($ongoing_batches)): ?>
                                    <tr class="empty-row"><td>No ongoing batches found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($ongoing_batches as $ob): ?>
                                    <tr>
                                        <td>
                                            <div class="tracker-batch-name">
                                                <?= htmlspecialchars($ob['batch_name']) ?>
                                                <span class="tracker-mode-badge"><?= ucfirst($ob['mode'] ?? 'Online') ?></span>
                                            </div>
                                            <div class="tracker-date">
                                                <i class="fas fa-calendar-alt"></i>
                                                Started: <?= $ob['start_date'] ? date('d M Y', strtotime($ob['start_date'])) : 'TBA' ?>
                                            </div>
                                            <div class="tags-row">
                                                <?php
                                                $courses = $ob['course_names'] ? explode('||', $ob['course_names']) : [];
                                                if (empty($courses)) {
                                                    echo '<span class="tag" style="font-style:italic;">No courses</span>';
                                                }
                                                foreach ($courses as $c): ?>
                                                    <span class="tag"><?= htmlspecialchars($c) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Upcoming Batches -->
                <div class="tracker-card upcoming">
                    <div class="tracker-header">
                        <div class="tracker-header-icon"><i class="fas fa-hourglass-start"></i></div>
                        <div class="tracker-header-title">Upcoming Batches</div>
                    </div>
                    <div class="tracker-body">
                        <table class="tracker-table">
                            <tbody>
                                <?php if (empty($upcoming_batches)): ?>
                                    <tr class="empty-row"><td>No upcoming batches found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($upcoming_batches as $ub): ?>
                                    <tr>
                                        <td>
                                            <div class="tracker-batch-name">
                                                <?= htmlspecialchars($ub['batch_name']) ?>
                                                <span class="tracker-mode-badge"><?= ucfirst($ub['mode'] ?? 'Online') ?></span>
                                            </div>
                                            <div class="tracker-date">
                                                <i class="fas fa-calendar-plus"></i>
                                                Starts: <?= $ub['start_date'] ? date('d M Y', strtotime($ub['start_date'])) : 'TBA' ?>
                                            </div>
                                            <div class="tags-row">
                                                <?php
                                                $courses = $ub['course_names'] ? explode('||', $ub['course_names']) : [];
                                                if (empty($courses)) {
                                                    echo '<span class="tag" style="font-style:italic;">No courses</span>';
                                                }
                                                foreach ($courses as $c): ?>
                                                    <span class="tag"><?= htmlspecialchars($c) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Completed Batches -->
                <div class="tracker-card completed">
                    <div class="tracker-header">
                        <div class="tracker-header-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="tracker-header-title">Completed (Last 30 Days)</div>
                    </div>
                    <div class="tracker-body">
                        <table class="tracker-table">
                            <tbody>
                                <?php if (empty($completed_batches)): ?>
                                    <tr class="empty-row"><td>No batches completed in the last month.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($completed_batches as $cb): ?>
                                    <tr>
                                        <td>
                                            <div class="tracker-batch-name">
                                                <?= htmlspecialchars($cb['batch_name']) ?>
                                                <span class="tracker-mode-badge"><?= ucfirst($cb['mode'] ?? 'Online') ?></span>
                                            </div>
                                            <div class="tracker-date">
                                                <i class="fas fa-calendar-check"></i>
                                                Ended: <?= $cb['end_date'] ? date('d M Y', strtotime($cb['end_date'])) : 'N/A' ?>
                                            </div>
                                            <div class="tags-row">
                                                <?php
                                                $courses = $cb['course_names'] ? explode('||', $cb['course_names']) : [];
                                                if (empty($courses)) {
                                                    echo '<span class="tag" style="font-style:italic;">No courses</span>';
                                                }
                                                foreach ($courses as $c): ?>
                                                    <span class="tag"><?= htmlspecialchars($c) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- ================================================
                 SECTION 5: COURSE ACTIVITY TRACKER
                 ================================================ -->
            <h2 class="section-title">
                <span class="section-title-icon"><i class="fas fa-layer-group"></i></span>
                Course Activity Tracker
            </h2>

            <div class="grid-3 section-block">

                <!-- Ongoing Courses -->
                <div class="tracker-card indigo">
                    <div class="tracker-header">
                        <div class="tracker-header-icon"><i class="fas fa-spinner fa-spin"></i></div>
                        <div class="tracker-header-title">Ongoing Courses</div>
                    </div>
                    <div class="tracker-body">
                        <table class="tracker-table">
                            <tbody>
                                <?php if (empty($ongoing_courses)): ?>
                                    <tr class="empty-row"><td>No ongoing courses found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($ongoing_courses as $oc): ?>
                                    <tr>
                                        <td>
                                            <div class="course-name-row">
                                                <i class="fas fa-book-open"></i>
                                                <?= htmlspecialchars($oc['name']) ?>
                                            </div>
                                            <div class="tracker-sub-label">Active in Batches:</div>
                                            <div class="tags-row">
                                                <?php
                                                $batches = $oc['batch_names'] ? explode('||', $oc['batch_names']) : [];
                                                if (empty($batches)) {
                                                    echo '<span class="tag" style="font-style:italic;">None</span>';
                                                }
                                                foreach ($batches as $b): ?>
                                                    <span class="tag indigo"><?= htmlspecialchars($b) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Upcoming Courses -->
                <div class="tracker-card amber">
                    <div class="tracker-header">
                        <div class="tracker-header-icon"><i class="fas fa-rocket"></i></div>
                        <div class="tracker-header-title">Upcoming Courses</div>
                    </div>
                    <div class="tracker-body">
                        <table class="tracker-table">
                            <tbody>
                                <?php if (empty($upcoming_courses)): ?>
                                    <tr class="empty-row"><td>No upcoming courses found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($upcoming_courses as $uc): ?>
                                    <tr>
                                        <td>
                                            <div class="course-name-row">
                                                <i class="fas fa-book-open"></i>
                                                <?= htmlspecialchars($uc['name']) ?>
                                            </div>
                                            <div class="tracker-sub-label">Scheduled in Batches:</div>
                                            <div class="tags-row">
                                                <?php
                                                $batches = $uc['batch_names'] ? explode('||', $uc['batch_names']) : [];
                                                if (empty($batches)) {
                                                    echo '<span class="tag" style="font-style:italic;">None</span>';
                                                }
                                                foreach ($batches as $b): ?>
                                                    <span class="tag amber"><?= htmlspecialchars($b) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Completed Courses -->
                <div class="tracker-card teal">
                    <div class="tracker-header">
                        <div class="tracker-header-icon"><i class="fas fa-award"></i></div>
                        <div class="tracker-header-title">Completed Courses</div>
                    </div>
                    <div class="tracker-body">
                        <table class="tracker-table">
                            <tbody>
                                <?php if (empty($completed_courses)): ?>
                                    <tr class="empty-row"><td>No completed courses recently.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($completed_courses as $cc): ?>
                                    <tr>
                                        <td>
                                            <div class="course-name-row">
                                                <i class="fas fa-book-open"></i>
                                                <?= htmlspecialchars($cc['name']) ?>
                                            </div>
                                            <div class="tracker-sub-label">Finished via Batches:</div>
                                            <div class="tags-row">
                                                <?php
                                                $batches = $cc['batch_names'] ? explode('||', $cc['batch_names']) : [];
                                                if (empty($batches)) {
                                                    echo '<span class="tag" style="font-style:italic;">None</span>';
                                                }
                                                foreach ($batches as $b): ?>
                                                    <span class="tag teal"><?= htmlspecialchars($b) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div><!-- /.container-inner -->
    </main>

    <!-- ============================================================
         CHART.JS — All logic identical to original
         ============================================================ -->
    <script>
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#456882';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.backgroundColor = '#1B3C53';
        Chart.defaults.plugins.tooltip.titleColor = '#F5F7FA';
        Chart.defaults.plugins.tooltip.bodyColor = '#D2C1B6';

        // ── 1. Enrollment Trend (Line) ──────────────────────────
        const ctxEnroll = document.getElementById('enrollmentChart').getContext('2d');
        const gradEnroll = ctxEnroll.createLinearGradient(0, 0, 0, 400);
        gradEnroll.addColorStop(0, 'rgba(27,60,83,0.30)');
        gradEnroll.addColorStop(1, 'rgba(27,60,83,0.00)');

        new Chart(ctxEnroll, {
            type: 'line',
            data: {
                labels: <?= json_encode($enrollment_labels) ?>,
                datasets: [{
                    label: 'New Enrollments',
                    data: <?= json_encode($enrollment_data) ?>,
                    borderColor: '#1B3C53',
                    backgroundColor: gradEnroll,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#1B3C53',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(210,193,182,0.4)', borderDash: [4, 4] },
                        ticks: { color: '#456882', font: { size: 11 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#456882', font: { size: 11 } }
                    }
                }
            }
        });

        // ── 2. Course Popularity (Horizontal Bar) ───────────────
        new Chart(document.getElementById('courseChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($course_labels) ?>,
                datasets: [{
                    label: 'Active Students',
                    data: <?= json_encode($course_data) ?>,
                    backgroundColor: '#456882',
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(210,193,182,0.4)', borderDash: [4, 4] },
                        ticks: { color: '#456882', font: { size: 11 } }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#1B3C53', font: { size: 11, weight: '500' } }
                    }
                }
            }
        });

        // ── 3. Attendance Trend (Bar) ────────────────────────────
        new Chart(document.getElementById('attendanceChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($attendance_labels) ?>,
                datasets: [{
                    label: 'Present %',
                    data: <?= json_encode($attendance_data) ?>,
                    backgroundColor: '#1a7f5a',
                    borderRadius: 5,
                    barThickness: 'flex',
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: { color: 'rgba(210,193,182,0.4)', borderDash: [4, 4] },
                        ticks: { callback: v => v + '%', color: '#456882', font: { size: 11 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#456882', font: { size: 10 } }
                    }
                }
            }
        });

        // ── 4. Batch Status (Doughnut) ───────────────────────────
        new Chart(document.getElementById('batchStatusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Ongoing', 'Upcoming', 'Completed'],
                datasets: [{
                    data: [
                        <?= $batch_stats['ongoing_batches']   ?? 0 ?>,
                        <?= $batch_stats['upcoming_batches']  ?? 0 ?>,
                        <?= $batch_stats['completed_batches'] ?? 0 ?>
                    ],
                    backgroundColor: ['#1B3C53', '#b45309', '#456882'],
                    borderWidth: 0,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 14, font: { size: 11 }, color: '#456882' }
                    }
                }
            }
        });

        // ── 5. Support Tickets (Doughnut) ───────────────────────
        new Chart(document.getElementById('ticketStatusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Open', 'In Progress', 'Resolved'],
                datasets: [{
                    data: [
                        <?= $ticket_stats['open_tickets']       ?? 0 ?>,
                        <?= $ticket_stats['in_progress_tickets'] ?? 0 ?>,
                        <?= $ticket_stats['resolved_tickets']   ?? 0 ?>
                    ],
                    backgroundColor: ['#991b1b', '#b45309', '#1a7f5a'],
                    borderWidth: 0,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 14, font: { size: 11 }, color: '#456882' }
                    }
                }
            }
        });

        // ── 6. Student Lifecycle Status (Pie) ───────────────────
        new Chart(document.getElementById('studentStatusChart'), {
            type: 'pie',
            data: {
                labels: ['Active', 'Dropped', 'Completed'],
                datasets: [{
                    data: [
                        <?= $student_stats['active_students']    ?? 0 ?>,
                        <?= $student_stats['dropped_students']   ?? 0 ?>,
                        <?= $student_stats['graduated_students'] ?? 0 ?>
                    ],
                    backgroundColor: ['#1a7f5a', '#991b1b', '#234C6A'],
                    borderWidth: 0,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 16, font: { size: 11 }, color: '#456882' }
                    }
                }
            }
        });

        // ── 7. Feedback Distribution (Bar) ──────────────────────
        new Chart(document.getElementById('feedbackChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($feedback_labels) ?>,
                datasets: [{
                    label: 'Number of Ratings',
                    data: <?= json_encode($feedback_data) ?>,
                    backgroundColor: ['#991b1b', '#b45309', '#456882', '#234C6A', '#1a7f5a'],
                    borderRadius: 5,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(210,193,182,0.4)', borderDash: [4, 4] },
                        ticks: { color: '#456882', font: { size: 11 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#1B3C53', font: { size: 11, weight: '500' } }
                    }
                }
            }
        });
    </script>

</body>
</html>