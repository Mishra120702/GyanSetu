<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get batch ID from URL
$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : null;

if (!$batch_id) {
    header("Location: ../batch/batch_list.php");
    exit();
}

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get batch details with mentor information
    $stmt = $db->prepare("SELECT 
                            b.batch_id,
                            b.batch_name,
                            b.course_description,
                            b.start_date,
                            b.end_date,
                            b.time_slot,
                            b.platform,
                            b.meeting_link,
                            b.thumbnail_path,
                            b.max_students,
                            b.current_enrollment,
                            b.mode,
                            b.status,
                            b.academic_year,
                            t.name as mentor_name,
                            t.email as mentor_email,
                            t.profile_picture as mentor_avatar
                        FROM batches b
                        LEFT JOIN trainers t ON b.batch_mentor_id = t.id
                        WHERE b.batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$batch) {
        header("Location: ../batch/batch_list.php");
        exit();
    }
    
    // Get students in this batch
    if ($batch['status'] === 'completed') {
        $stmt = $db->prepare("
            SELECT DISTINCT
                s.student_id,
                s.first_name,
                s.last_name,
                s.email,
                s.phone_number,
                s.enrollment_date,
                s.current_status,
                s.batch_name as current_batch,
                s.batch_name_2,
                s.batch_name_3,
                s.batch_name_4,
                CASE 
                    WHEN s.batch_name = :batch_id OR s.batch_name_2 = :batch_id OR s.batch_name_3 = :batch_id OR s.batch_name_4 = :batch_id THEN 'current'
                    WHEN EXISTS (
                        SELECT 1 FROM student_batch_history h 
                        WHERE h.student_id = s.student_id AND h.from_batch_id = :batch_id
                    ) THEN 'transferred'
                    ELSE 'historical'
                END as student_type,
                (SELECT h2.to_batch_id FROM student_batch_history h2 
                 WHERE h2.student_id = s.student_id AND h2.from_batch_id = :batch_id2 
                 ORDER BY h2.transfer_date DESC LIMIT 1) as transferred_to_batch,
                CASE 
                    WHEN s.batch_name = :batch_id3 THEN 'batch_name'
                    WHEN s.batch_name_2 = :batch_id4 THEN 'batch_name_2'
                    WHEN s.batch_name_3 = :batch_id5 THEN 'batch_name_3'
                    WHEN s.batch_name_4 = :batch_id6 THEN 'batch_name_4'
                    ELSE NULL
                END as batch_field
            FROM students s
            WHERE s.student_id IN (
                SELECT student_id FROM student_batch_history WHERE from_batch_id = :batch_id7
                UNION
                SELECT student_id FROM students WHERE batch_name = :batch_id8 OR batch_name_2 = :batch_id9 OR batch_name_3 = :batch_id10 OR batch_name_4 = :batch_id11
            )
            ORDER BY s.first_name, s.last_name
        ");
        $stmt->execute([
            'batch_id' => $batch_id,
            'batch_id2' => $batch_id,
            'batch_id3' => $batch_id,
            'batch_id4' => $batch_id,
            'batch_id5' => $batch_id,
            'batch_id6' => $batch_id,
            'batch_id7' => $batch_id,
            'batch_id8' => $batch_id,
            'batch_id9' => $batch_id,
            'batch_id10' => $batch_id,
            'batch_id11' => $batch_id
        ]);
    } else {
        $stmt = $db->prepare("
            SELECT 
                s.student_id,
                s.first_name,
                s.last_name,
                s.email,
                s.phone_number,
                s.enrollment_date,
                s.current_status,
                s.batch_name as current_batch,
                s.batch_name_2,
                s.batch_name_3,
                s.batch_name_4,
                'current' as student_type,
                NULL as transferred_to_batch,
                CASE 
                    WHEN s.batch_name = :batch_id THEN 'batch_name'
                    WHEN s.batch_name_2 = :batch_id2 THEN 'batch_name_2'
                    WHEN s.batch_name_3 = :batch_id3 THEN 'batch_name_3'
                    WHEN s.batch_name_4 = :batch_id4 THEN 'batch_name_4'
                    ELSE NULL
                END as batch_field
            FROM students s
            WHERE s.batch_name = :batch_id5 OR s.batch_name_2 = :batch_id6 OR s.batch_name_3 = :batch_id7 OR s.batch_name_4 = :batch_id8
            ORDER BY s.first_name, s.last_name
        ");
        $stmt->execute([
            'batch_id' => $batch_id,
            'batch_id2' => $batch_id,
            'batch_id3' => $batch_id,
            'batch_id4' => $batch_id,
            'batch_id5' => $batch_id,
            'batch_id6' => $batch_id,
            'batch_id7' => $batch_id,
            'batch_id8' => $batch_id
        ]);
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $current_students_count   = 0;
    $transferred_students_count = 0;
    $batch_name_count   = 0;
    $batch_name_2_count = 0;
    $batch_name_3_count = 0;
    $batch_name_4_count = 0;
    
    foreach ($students as $student) {
        if ($student['student_type'] === 'current' && $student['current_status'] === 'active') {
            $current_students_count++;
            if ($student['batch_field'] === 'batch_name')   $batch_name_count++;
            elseif ($student['batch_field'] === 'batch_name_2') $batch_name_2_count++;
            elseif ($student['batch_field'] === 'batch_name_3') $batch_name_3_count++;
            elseif ($student['batch_field'] === 'batch_name_4') $batch_name_4_count++;
        } elseif ($student['student_type'] === 'transferred') {
            $transferred_students_count++;
        }
    }
    
    // Transfer history
    $stmt = $db->prepare("
        SELECT h.*, s.first_name, s.last_name, s.email,
               fb.batch_name as from_batch_name,
               tb.batch_name as to_batch_name,
               u.name as transferred_by_name
        FROM student_batch_history h
        JOIN students s ON h.student_id = s.student_id
        LEFT JOIN batches fb ON h.from_batch_id = fb.batch_id
        LEFT JOIN batches tb ON h.to_batch_id = tb.batch_id
        LEFT JOIN users u ON h.transferred_by = u.id
        WHERE h.from_batch_id = ?
        ORDER BY h.transfer_date DESC
    ");
    $stmt->execute([$batch_id]);
    $transfer_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Available batches for transfer
    $stmt = $db->prepare("SELECT batch_id, batch_name 
                          FROM batches 
                          WHERE batch_id != ? AND status IN ('upcoming', 'ongoing')
                          ORDER BY start_date ASC");
    $stmt->execute([$batch_id]);
    $available_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Attendance stats
    $attendance_stats  = [];
    $recent_attendance = [];
    
    $stmt = $db->prepare("SELECT COUNT(*) as total_sessions FROM schedule WHERE batch_id = ? AND is_cancelled = 0");
    $stmt->execute([$batch_id]);
    $sessions_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_scheduled_sessions = $sessions_result['total_sessions'] ?: 0;
    
    if ($total_scheduled_sessions > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) as total_possible_attendance FROM attendance WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $total_possible = $stmt->fetch(PDO::FETCH_ASSOC)['total_possible_attendance'];
        
        $stmt = $db->prepare("SELECT COUNT(*) as total_records,
                                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
                                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count
                              FROM attendance WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $attendance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($attendance_stats['total_records'] > 0) {
            $attendance_stats['present_percentage'] = round(($attendance_stats['present_count'] / $attendance_stats['total_records']) * 100, 1);
            $attendance_stats['absent_percentage']  = round(($attendance_stats['absent_count']  / $attendance_stats['total_records']) * 100, 1);
        } else {
            $attendance_stats['present_percentage'] = 0;
            $attendance_stats['absent_percentage']  = 0;
        }
        
        $stmt = $db->prepare("SELECT COUNT(DISTINCT date) as sessions_with_attendance FROM attendance WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $coverage = $stmt->fetch(PDO::FETCH_ASSOC);
        $attendance_stats['sessions_with_attendance'] = $coverage['sessions_with_attendance'] ?: 0;
        $attendance_stats['attendance_coverage']      = round(($attendance_stats['sessions_with_attendance'] / $total_scheduled_sessions) * 100, 1);
    } else {
        $attendance_stats = [
            'total_records' => 0, 'present_count' => 0, 'absent_count' => 0,
            'present_percentage' => 0, 'absent_percentage' => 0,
            'sessions_with_attendance' => 0, 'attendance_coverage' => 0
        ];
    }
    
    // Per-student attendance
    $student_attendance = [];
    if ($current_students_count > 0 && $total_scheduled_sessions > 0) {
        $student_ids = [];
        foreach ($students as $student) {
            if ($student['student_type'] === 'current' && $student['current_status'] === 'active') {
                $student_ids[] = $student['student_id'];
            }
        }
        if (!empty($student_ids)) {
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            $stmt = $db->prepare("
                SELECT s.student_id, s.first_name, s.last_name,
                       COUNT(a.id) as total_attendance_records,
                       SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_count,
                       SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                       ROUND(COALESCE(SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0) * 100, 0), 1) as attendance_percentage
                FROM students s
                LEFT JOIN attendance a ON s.student_id = a.student_id AND a.batch_id = ?
                WHERE s.student_id IN ($placeholders)
                GROUP BY s.student_id, s.first_name, s.last_name
                ORDER BY attendance_percentage DESC
            ");
            $params = array_merge([$batch_id], $student_ids);
            $stmt->execute($params);
            $student_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Recent attendance (last 5 sessions)
    if ($attendance_stats['sessions_with_attendance'] > 0) {
        $stmt = $db->prepare("
            SELECT date,
                   COUNT(*) as total_students,
                   SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
                   SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                   ROUND(SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as attendance_percentage
            FROM attendance
            WHERE batch_id = ?
            GROUP BY date
            ORDER BY date DESC
            LIMIT 5
        ");
        $stmt->execute([$batch_id]);
        $recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Assigned courses
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.thumbnail, bc.status
        FROM batch_courses bc
        JOIN courses c ON bc.course_id = c.id
        WHERE bc.batch_id = ?
        ORDER BY bc.id ASC
    ");
    $stmt->execute([$batch_id]);
    $assigned_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_courses    = count($assigned_courses);
    $completed_courses = 0;
    foreach ($assigned_courses as $c) {
        if ($c['status'] === 'completed') $completed_courses++;
    }
    $course_progress_percentage = $total_courses > 0 ? round(($completed_courses / $total_courses) * 100) : 0;
    
    // Verification stats per chapter
    $verif_stats_stmt = $db->prepare("
        SELECT tv.main_topic_id,
               SUM(CASE WHEN tv.status = 'verified'  THEN 1 ELSE 0 END) as verified_count,
               SUM(CASE WHEN tv.status = 'rejected'  THEN 1 ELSE 0 END) as rejected_count,
               SUM(CASE WHEN tv.status = 'pending'   THEN 1 ELSE 0 END) as pending_count,
               COUNT(tv.id) as total_count
        FROM topic_verifications tv
        WHERE tv.batch_id = ?
        GROUP BY tv.main_topic_id
    ");
    $verif_stats_stmt->execute([$batch_id]);
    $verif_stats_raw = $verif_stats_stmt->fetchAll(PDO::FETCH_ASSOC);
    $verif_stats = [];
    foreach ($verif_stats_raw as $vs) {
        $verif_stats[$vs['main_topic_id']] = $vs;
    }

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch <?= htmlspecialchars($batch['batch_id']) ?> | ASD Academy</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        /* =========================================================
           DESIGN TOKENS
           ========================================================= */
        :root {
            --navy-900:    #1B3C53;
            --navy-700:    #234C6A;
            --navy-500:    #456882;
            --sand-300:    #D2C1B6;
            --surface:     #F5F7FA;

            --navy-900-10: rgba(27,60,83,0.10);
            --navy-900-06: rgba(27,60,83,0.06);
            --navy-700-15: rgba(35,76,106,0.15);
            --sand-300-40: rgba(210,193,182,0.40);

            --clr-green:     #1a7f5a;
            --clr-green-bg:  rgba(26,127,90,0.10);
            --clr-amber:     #b45309;
            --clr-amber-bg:  rgba(180,83,9,0.10);
            --clr-crimson:   #991b1b;
            --clr-crimson-bg:rgba(153,27,27,0.10);
            --clr-purple:    #4c3575;
            --clr-purple-bg: rgba(76,53,117,0.10);
            --clr-teal:      #146346;
            --clr-teal-bg:   rgba(20,111,75,0.10);

            --radius-sm:   8px;
            --radius-md:   12px;
            --radius-lg:   16px;
            --radius-xl:   20px;
            --radius-pill: 999px;

            --shadow-card:       0 2px 14px rgba(27,60,83,0.09), 0 1px 4px rgba(27,60,83,0.05);
            --shadow-card-hover: 0 10px 28px rgba(27,60,83,0.15), 0 3px 10px rgba(27,60,83,0.07);
            --shadow-btn:        0 2px 8px rgba(35,76,106,0.22);
            --shadow-btn-hover:  0 5px 16px rgba(35,76,106,0.30);

            --font-body:    'Inter', sans-serif;
            --font-display: 'Plus Jakarta Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--surface);
            font-family: var(--font-body);
            color: var(--navy-900);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* =========================================================
           LAYOUT
           ========================================================= */
        .view-main { margin-left: 0; min-height: 100vh; }
        @media (min-width: 768px) { .view-main { margin-left: 256px; } }

        /* =========================================================
           STICKY HEADER
           ========================================================= */
        .page-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: var(--navy-900);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 2px 12px rgba(27,60,83,0.22);
        }

        .page-header-left { display: flex; align-items: center; gap: 14px; }

        .page-header-title {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 1.05rem;
            color: #fff;
        }

        .page-header-sub {
            font-size: 0.75rem;
            color: rgba(210,193,182,0.80);
            margin-top: 2px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 0.8125rem;
            font-weight: 500;
            color: rgba(210,193,182,0.85);
            text-decoration: none;
            padding: 7px 14px;
            border: 1px solid rgba(210,193,182,0.30);
            border-radius: var(--radius-md);
            transition: all 0.2s ease;
        }

        .back-link:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }

        /* sidebar toggle for mobile */
        .sidebar-toggle {
            background: none;
            border: none;
            color: rgba(210,193,182,0.85);
            font-size: 1.1rem;
            cursor: pointer;
            padding: 6px;
        }

        /* =========================================================
           CONTENT WRAP
           ========================================================= */
        .content-wrap {
            padding: 24px 20px 56px;
            max-width: 1280px;
            margin: 0 auto;
        }

        /* =========================================================
           ANIMATIONS (identical to original)
           ========================================================= */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50%       { transform: translateY(-5px); }
        }

        @keyframes pulse-ring {
            0%   { box-shadow: 0 0 0 0 rgba(27,60,83,0.35); }
            70%  { box-shadow: 0 0 0 10px rgba(27,60,83,0); }
            100% { box-shadow: 0 0 0 0 rgba(27,60,83,0); }
        }

        @keyframes pulse-icon {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%       { transform: scale(1.1); opacity: 0.8; }
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }

        @keyframes sparkle {
            0%   { opacity: 0; transform: translateY(0) rotate(0deg); }
            50%  { opacity: 1; }
            100% { opacity: 0; transform: translateY(-20px) rotate(360deg); }
        }

        .fade-in { animation: fadeInUp 0.45s ease both; }

        /* =========================================================
           THUMBNAIL SECTION
           ========================================================= */
        .batch-thumbnail-container {
            position: relative;
            width: 100%;
            height: 380px;
            overflow: hidden;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card-hover);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            margin-bottom: 24px;
        }

        .batch-thumbnail-container:hover {
            transform: translateY(-6px) scale(1.01);
            box-shadow: 0 25px 50px rgba(27,60,83,0.25);
        }

        .batch-thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s ease;
        }

        .batch-thumbnail-container:hover .batch-thumbnail { transform: scale(1.08); }

        .thumbnail-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to bottom, rgba(27,60,83,0) 0%, rgba(27,60,83,0.80) 100%);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 32px;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .batch-thumbnail-container:hover .thumbnail-overlay { opacity: 1; }

        .thumbnail-badge {
            position: absolute;
            top: 20px; right: 20px;
            z-index: 10;
            background: rgba(27,60,83,0.55);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(210,193,182,0.30);
            border-radius: var(--radius-pill);
            padding: 10px 22px;
            color: #fff;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.20);
            animation: float 3s ease-in-out infinite;
        }

        .thumbnail-title {
            color: #fff;
            font-family: var(--font-display);
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 6px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.4);
            transform: translateY(20px);
            transition: transform 0.4s ease 0.1s;
        }

        .batch-thumbnail-container:hover .thumbnail-title { transform: translateY(0); }

        .thumbnail-subtitle {
            color: rgba(210,193,182,0.9);
            font-size: 1rem;
            text-shadow: 0 1px 4px rgba(0,0,0,0.3);
            transform: translateY(20px);
            transition: transform 0.4s ease 0.2s;
        }

        .batch-thumbnail-container:hover .thumbnail-subtitle { transform: translateY(0); }

        .thumbnail-stats {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
            transform: translateY(20px);
            transition: transform 0.4s ease 0.3s;
        }

        .batch-thumbnail-container:hover .thumbnail-stats { transform: translateY(0); }

        .thumbnail-stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            background: rgba(27,60,83,0.40);
            backdrop-filter: blur(5px);
            padding: 7px 14px;
            border-radius: var(--radius-pill);
            border: 1px solid rgba(210,193,182,0.20);
            font-size: 0.84rem;
        }

        /* Placeholder (no thumbnail) */
        .thumbnail-placeholder {
            width: 100%;
            height: 380px;
            background: linear-gradient(135deg, var(--navy-900) 0%, var(--navy-500) 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
            border-radius: var(--radius-xl);
            position: relative;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .thumbnail-placeholder::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 60%);
            animation: rotate 20s linear infinite;
        }

        .thumbnail-placeholder i {
            font-size: 5rem;
            margin-bottom: 16px;
            animation: pulse-icon 2s ease-in-out infinite;
        }

        .thumbnail-placeholder .placeholder-text {
            font-family: var(--font-display);
            font-size: 1.5rem;
            font-weight: 700;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .thumbnail-placeholder .placeholder-sub {
            font-size: 0.9rem;
            color: rgba(210,193,182,0.80);
            margin-top: 8px;
        }

        /* Sparkles */
        .sparkle {
            position: absolute;
            background: rgba(255,255,255,0.75);
            border-radius: 50%;
            animation: sparkle 3s infinite;
        }

        /* =========================================================
           BATCH HEADER STRIP
           ========================================================= */
        .batch-header-card {
            background: #fff;
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .batch-header-strip {
            background: linear-gradient(135deg, var(--navy-900) 0%, var(--navy-500) 100%);
            padding: 24px 28px;
            position: relative;
            overflow: hidden;
            min-height: 100px;
        }

        .batch-header-strip::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0) 60%);
            transform: rotate(30deg);
        }

        .batch-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .batch-header-title {
            font-family: var(--font-display);
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.3px;
        }

        .batch-header-id {
            font-size: 0.8rem;
            color: rgba(210,193,182,0.80);
            margin-top: 4px;
        }

        /* Status badges */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            border-radius: var(--radius-pill);
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-ongoing   { background: var(--clr-green-bg); color: var(--clr-green); border: 1px solid rgba(26,127,90,0.25); }
        .status-completed { background: var(--navy-900-10);  color: var(--navy-500);  border: 1px solid var(--navy-900-10); }
        .status-upcoming  { background: var(--clr-amber-bg); color: var(--clr-amber); border: 1px solid rgba(180,83,9,0.25); }
        .status-cancelled { background: var(--clr-crimson-bg); color: var(--clr-crimson); border: 1px solid rgba(153,27,27,0.25); }

        /* =========================================================
           STATS CARDS
           ========================================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            padding: 24px 28px 0;
            margin-bottom: 24px;
        }

        @media (min-width: 640px)  { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1024px) { .stats-grid { grid-template-columns: repeat(5, 1fr); } }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--sand-300-40);
            border-left: 4px solid var(--navy-700);
            border-radius: var(--radius-lg);
            padding: 16px;
            transition: transform 0.22s ease, box-shadow 0.22s ease;
        }

        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-card-hover); }

        .stat-card-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
        }

        .stat-card-value {
            font-family: var(--font-display);
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--navy-900);
            line-height: 1.1;
        }

        .stat-card-sub {
            font-size: 0.72rem;
            color: var(--navy-500);
            margin-top: 4px;
        }

        /* Batch distribution dots */
        .batch-distribution {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 8px;
        }

        .distribution-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            color: var(--navy-500);
            background: #fff;
            padding: 2px 8px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--sand-300-40);
        }

        .distribution-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
        }

        .dot-b1 { background: var(--navy-900); }
        .dot-b2 { background: var(--navy-500); }
        .dot-b3 { background: var(--clr-amber); }
        .dot-b4 { background: var(--clr-purple); }

        /* =========================================================
           INFO GRID (Platform + Actions)
           ========================================================= */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            padding: 0 28px 28px;
        }

        @media (min-width: 768px) { .info-grid { grid-template-columns: 1fr 1fr; } }

        .info-panel {
            background: var(--surface);
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-lg);
            padding: 20px;
        }

        .info-panel-title {
            font-family: var(--font-display);
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-panel-title::before {
            content: '';
            width: 4px; height: 16px;
            background: var(--navy-700);
            border-radius: var(--radius-pill);
            flex-shrink: 0;
        }

        .info-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
        .info-row:last-child { margin-bottom: 0; }

        .info-row-label {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .info-row-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--navy-900);
        }

        .info-row-value a {
            color: var(--navy-700);
            text-decoration: none;
            word-break: break-all;
        }

        .info-row-value a:hover { text-decoration: underline; }

        /* Action buttons in panel */
        .action-btn-list { display: flex; flex-direction: column; gap: 8px; }

        .action-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: #fff;
            border: 1.5px solid var(--sand-300-40);
            border-radius: var(--radius-md);
            font-size: 0.84rem;
            font-weight: 500;
            color: var(--navy-700);
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .action-link i { width: 16px; text-align: center; color: var(--navy-500); font-size: 0.85rem; }
        .action-link:hover { background: var(--navy-900-06); border-color: var(--navy-500); color: var(--navy-900); transform: translateX(3px); }

        /* =========================================================
           TAB NAVIGATION
           ========================================================= */
        .tabs-card {
            background: #fff;
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .tabs-nav {
            display: flex;
            overflow-x: auto;
            border-bottom: 1px solid var(--sand-300-40);
            background: var(--surface);
            scrollbar-width: none;
        }

        .tabs-nav::-webkit-scrollbar { display: none; }

        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 20px;
            border: none;
            background: none;
            font-family: var(--font-body);
            font-size: 0.84rem;
            font-weight: 500;
            color: var(--navy-500);
            cursor: pointer;
            white-space: nowrap;
            border-bottom: 2.5px solid transparent;
            transition: all 0.2s ease;
        }

        .tab-btn:hover { color: var(--navy-700); background: var(--navy-900-06); }

        .tab-btn.active {
            color: var(--navy-900);
            border-bottom-color: var(--navy-900);
            font-weight: 600;
            background: #fff;
        }

        .tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: var(--navy-900-10);
            color: var(--navy-700);
            border-radius: var(--radius-pill);
            font-size: 0.68rem;
            font-weight: 700;
        }

        .tab-btn.active .tab-badge { background: var(--navy-900); color: #fff; }

        /* =========================================================
           TAB CONTENT
           ========================================================= */
        .tab-content { display: none; padding: 24px; }
        .tab-content.active { display: block; }

        /* =========================================================
           STUDENTS TABLE
           ========================================================= */
        .students-table-wrap { overflow-x: auto; }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead tr {
            background: var(--surface);
            border-bottom: 1px solid var(--sand-300-40);
        }

        .data-table th {
            padding: 11px 16px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--navy-500);
            text-align: left;
            white-space: nowrap;
        }

        .data-table tbody tr {
            border-bottom: 1px solid var(--sand-300-40);
            transition: background 0.15s ease;
        }

        .data-table tbody tr:last-child { border-bottom: none; }
        .data-table tbody tr:hover { background: var(--navy-900-06); }

        .data-table td {
            padding: 12px 16px;
            font-size: 0.875rem;
            color: var(--navy-900);
            vertical-align: middle;
        }

        /* Student avatar */
        .student-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--navy-900-10);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--navy-700);
            font-size: 0.8rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .student-name-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-name-primary {
            font-weight: 600;
            color: var(--navy-900);
            font-size: 0.875rem;
        }

        .student-name-secondary {
            font-size: 0.75rem;
            color: var(--navy-500);
        }

        /* Type badges */
        .type-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: var(--radius-pill);
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .type-current     { background: var(--clr-green-bg);  color: var(--clr-green); }
        .type-transferred { background: var(--navy-900-10);    color: var(--navy-700);  }
        .type-historical  { background: var(--clr-amber-bg);   color: var(--clr-amber); }

        .batch-field-pill {
            display: inline-flex;
            align-items: center;
            padding: 2px 7px;
            border-radius: var(--radius-pill);
            font-size: 0.62rem;
            font-weight: 700;
            margin-left: 5px;
        }

        .field-b1 { background: var(--navy-900); color: #fff; }
        .field-b2 { background: var(--navy-500); color: #fff; }
        .field-b3 { background: var(--clr-amber); color: #fff; }
        .field-b4 { background: var(--clr-purple); color: #fff; }

        /* Status pill in table */
        .student-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: var(--radius-pill);
            font-size: 0.72rem;
            font-weight: 600;
        }

        .student-status.active    { background: var(--clr-green-bg);   color: var(--clr-green); }
        .student-status.dropped   { background: var(--clr-crimson-bg); color: var(--clr-crimson); }
        .student-status.on_hold   { background: var(--clr-amber-bg);   color: var(--clr-amber); }
        .student-status.completed { background: var(--navy-900-10);    color: var(--navy-500); }

        /* Table action icons */
        .table-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--sand-300-40);
            background: var(--surface);
            color: var(--navy-500);
            font-size: 0.8rem;
            text-decoration: none;
            transition: all 0.18s ease;
        }

        .table-action:hover { background: var(--navy-900-10); color: var(--navy-900); border-color: var(--navy-500); }

        /* =========================================================
           ATTENDANCE TAB
           ========================================================= */
        .att-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        @media (min-width: 640px) { .att-stats-grid { grid-template-columns: repeat(4, 1fr); } }

        .att-stat-box {
            background: var(--surface);
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-lg);
            padding: 18px;
            text-align: center;
        }

        .att-stat-number {
            font-family: var(--font-display);
            font-size: 2rem;
            font-weight: 800;
            color: var(--navy-900);
            line-height: 1;
        }

        .att-stat-label {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 6px;
        }

        /* Attendance percentage circle */
        .att-circle {
            width: 72px; height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 1.05rem;
            margin: 0 auto 8px;
            border: 3px solid transparent;
        }

        .att-circle.high   { background: var(--clr-green-bg);   border-color: var(--clr-green);  color: var(--clr-green); }
        .att-circle.medium { background: var(--clr-amber-bg);   border-color: var(--clr-amber);  color: var(--clr-amber); }
        .att-circle.low    { background: var(--clr-crimson-bg); border-color: var(--clr-crimson); color: var(--clr-crimson); }

        /* Coverage badge */
        .coverage-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 9px;
            border-radius: var(--radius-pill);
            font-size: 0.72rem;
            font-weight: 600;
        }

        .coverage-good { background: var(--clr-green-bg);   color: var(--clr-green); }
        .coverage-fair { background: var(--clr-amber-bg);   color: var(--clr-amber); }
        .coverage-poor { background: var(--clr-crimson-bg); color: var(--clr-crimson); }

        /* Progress bar */
        .progress-track {
            height: 8px;
            background: var(--navy-900-10);
            border-radius: var(--radius-pill);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: var(--radius-pill);
            transition: width 1s ease-in-out;
        }

        .progress-navy  { background: linear-gradient(90deg, var(--navy-900), var(--navy-500)); }
        .progress-green { background: linear-gradient(90deg, var(--clr-green), #2da870); }
        .progress-amber { background: linear-gradient(90deg, var(--clr-amber), #d97706); }
        .progress-crimson { background: linear-gradient(90deg, var(--clr-crimson), #dc2626); }

        /* =========================================================
           COURSES TAB
           ========================================================= */
        .courses-progress-wrap { margin-bottom: 20px; }

        .courses-progress-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .courses-progress-label span {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--navy-500);
        }

        .courses-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        @media (min-width: 640px)  { .courses-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1024px) { .courses-grid { grid-template-columns: repeat(4, 1fr); } }

        .course-card {
            background: var(--surface);
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }

        .course-card:hover { box-shadow: var(--shadow-card-hover); transform: translateY(-2px); }

        .course-thumb {
            width: 100%;
            height: 80px;
            object-fit: cover;
            background: var(--navy-900-10);
        }

        .course-thumb-placeholder {
            width: 100%;
            height: 80px;
            background: linear-gradient(135deg, var(--navy-900), var(--navy-500));
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.6);
            font-size: 1.5rem;
        }

        .course-card-body { padding: 12px; }

        .course-card-name {
            font-weight: 600;
            font-size: 0.84rem;
            color: var(--navy-900);
            margin-bottom: 8px;
            line-height: 1.3;
        }

        .course-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: var(--radius-pill);
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .course-status-ongoing   { background: var(--clr-green-bg);  color: var(--clr-green); }
        .course-status-completed { background: var(--navy-900-10);    color: var(--navy-500); }
        .course-status-pending   { background: var(--clr-amber-bg);   color: var(--clr-amber); }

        /* =========================================================
           TRANSFER HISTORY TAB
           ========================================================= */
        /* reuses .data-table styles */

        /* =========================================================
           CHART WRAPPER
           ========================================================= */
        .chart-wrap {
            position: relative;
            height: 260px;
            width: 100%;
        }

        .chart-wrap-sm { height: 200px; }

        /* =========================================================
           SECTION HEADING inside tabs
           ========================================================= */
        .tab-section-title {
            font-family: var(--font-display);
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-section-title::before {
            content: '';
            width: 4px; height: 14px;
            background: var(--navy-700);
            border-radius: var(--radius-pill);
            flex-shrink: 0;
        }

        /* =========================================================
           CARD inside tab
           ========================================================= */
        .inner-card {
            background: var(--surface);
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-lg);
            padding: 18px;
            margin-bottom: 20px;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        @media (min-width: 768px) { .two-col { grid-template-columns: 1fr 1fr; } }

        /* =========================================================
           MENTOR INFO
           ========================================================= */
        .mentor-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .mentor-avatar-img {
            width: 44px; height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--sand-300);
        }

        .mentor-avatar-placeholder {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: var(--navy-900-10);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--navy-700);
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        /* =========================================================
           EMPTY STATE
           ========================================================= */
        .empty-state {
            padding: 40px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 2.5rem;
            color: var(--sand-300);
            margin-bottom: 12px;
            display: block;
        }

        .empty-state p {
            font-size: 0.9rem;
            color: var(--navy-500);
        }

        /* =========================================================
           MODAL (Transfer)
           ========================================================= */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(27,60,83,0.55);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open { display: flex; }

        .modal-box {
            background: #fff;
            border-radius: var(--radius-xl);
            box-shadow: 0 24px 60px rgba(27,60,83,0.30);
            padding: 28px;
            width: 100%;
            max-width: 480px;
            margin: 16px;
            animation: fadeInUp 0.3s ease;
        }

        .modal-title {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--navy-900);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--navy-500);
            cursor: pointer;
            font-size: 1rem;
            padding: 4px;
            transition: color 0.2s ease;
        }

        .modal-close:hover { color: var(--navy-900); }

        .modal-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }

        .modal-field label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .modal-field select,
        .modal-field textarea,
        .modal-field input {
            width: 100%;
            background: var(--surface);
            border: 1.5px solid var(--sand-300);
            border-radius: var(--radius-md);
            padding: 10px 14px;
            font-family: var(--font-body);
            font-size: 0.875rem;
            color: var(--navy-900);
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            appearance: none;
        }

        .modal-field select:focus,
        .modal-field textarea:focus { border-color: var(--navy-700); box-shadow: 0 0 0 3px var(--navy-700-15); }

        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 20px;
            border-radius: var(--radius-md);
            font-family: var(--font-body);
            font-size: 0.8375rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-navy {
            background: var(--navy-900);
            color: #fff;
            box-shadow: var(--shadow-btn);
        }

        .btn-navy:hover { background: var(--navy-700); box-shadow: var(--shadow-btn-hover); }

        .btn-ghost {
            background: transparent;
            color: var(--navy-700);
            border: 1.5px solid var(--sand-300);
        }

        .btn-ghost:hover { background: var(--navy-900-06); border-color: var(--navy-500); }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="view-main">

        <!-- Sticky Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <button class="sidebar-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <div class="page-header-title">
                        <i class="fas fa-layer-group" style="opacity:.8;margin-right:8px;"></i>Batch Details
                    </div>
                    <div class="page-header-sub">
                        <?= htmlspecialchars($batch['batch_id']) ?> &mdash; <?= htmlspecialchars($batch['batch_name']) ?>
                    </div>
                </div>
            </div>
            <a href="../batch/batch_list.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Batches
            </a>
        </div>

        <!-- Content -->
        <div class="content-wrap">

            <!-- ── Thumbnail ── -->
            <div class="fade-in">
                <?php if (!empty($batch['thumbnail_path'])): ?>
                    <div class="batch-thumbnail-container">
                        <img src="../<?= htmlspecialchars($batch['thumbnail_path']) ?>"
                             alt="<?= htmlspecialchars($batch['batch_name']) ?>"
                             class="batch-thumbnail">
                        <div class="thumbnail-badge">
                            <i class="fas fa-crown" style="margin-right:6px;"></i>
                            <?= htmlspecialchars($batch['batch_id']) ?>
                        </div>
                        <div class="thumbnail-overlay">
                            <div class="thumbnail-title"><?= htmlspecialchars($batch['batch_name']) ?></div>
                            <div class="thumbnail-subtitle">
                                <?= !empty($batch['course_description'])
                                    ? substr(htmlspecialchars($batch['course_description']), 0, 100) . '…'
                                    : 'No description available' ?>
                            </div>
                            <div class="thumbnail-stats">
                                <div class="thumbnail-stat-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?= date('M j, Y', strtotime($batch['start_date'])) ?></span>
                                </div>
                                <div class="thumbnail-stat-item">
                                    <i class="fas fa-calendar-check"></i>
                                    <span><?= date('M j, Y', strtotime($batch['end_date'])) ?></span>
                                </div>
                                <div class="thumbnail-stat-item">
                                    <i class="fas fa-users"></i>
                                    <span><?= $current_students_count ?>/<?= htmlspecialchars($batch['max_students'] ?? 'N/A') ?> Students</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="thumbnail-placeholder">
                        <div class="sparkle" style="top:20%;left:10%;width:5px;height:5px;animation-delay:0s;"></div>
                        <div class="sparkle" style="top:60%;left:80%;width:8px;height:8px;animation-delay:1s;"></div>
                        <div class="sparkle" style="top:30%;left:50%;width:6px;height:6px;animation-delay:2s;"></div>
                        <i class="fas fa-chalkboard-teacher"></i>
                        <div class="placeholder-text"><?= htmlspecialchars($batch['batch_name']) ?></div>
                        <div class="placeholder-sub">
                            <i class="fas fa-calendar-alt" style="margin-right:6px;"></i>
                            <?= date('M j, Y', strtotime($batch['start_date'])) ?> &mdash;
                            <?= date('M j, Y', strtotime($batch['end_date'])) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Batch Header Card ── -->
            <div class="batch-header-card fade-in">
                <div class="batch-header-strip">
                    <!-- sparkles -->
                    <div class="sparkle" style="top:20%;left:10%;width:5px;height:5px;animation-delay:0s;"></div>
                    <div class="sparkle" style="top:60%;left:80%;width:8px;height:8px;animation-delay:1s;"></div>
                    <div class="sparkle" style="top:35%;left:50%;width:6px;height:6px;animation-delay:2s;"></div>

                    <div class="batch-header-content">
                        <div>
                            <div class="batch-header-title"><?= htmlspecialchars($batch['batch_name']) ?></div>
                            <div class="batch-header-id">Batch ID: <?= htmlspecialchars($batch['batch_id']) ?></div>
                        </div>
                        <span class="status-pill status-<?= htmlspecialchars($batch['status']) ?>">
                            <?= htmlspecialchars(ucfirst($batch['status'])) ?>
                        </span>
                    </div>
                </div>

                <!-- Stats Row -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-label"><i class="fas fa-calendar-plus"></i> Start Date</div>
                        <div class="stat-card-value"><?= date('d M Y', strtotime($batch['start_date'])) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label"><i class="fas fa-calendar-check"></i> End Date</div>
                        <div class="stat-card-value"><?= date('d M Y', strtotime($batch['end_date'])) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label"><i class="fas fa-laptop-code"></i> Mode</div>
                        <div class="stat-card-value"><?= htmlspecialchars(ucfirst($batch['mode'])) ?></div>
                    </div>
                    <div class="stat-card" style="animation: pulse-ring 2s infinite;">
                        <div class="stat-card-label"><i class="fas fa-user-graduate"></i> Active Students</div>
                        <div class="stat-card-value" id="studentCounter"><?= $current_students_count ?></div>
                        <div class="stat-card-sub">of <?= htmlspecialchars($batch['max_students'] ?? 'N/A') ?> max</div>
                        <?php if ($batch_name_count > 0 || $batch_name_2_count > 0 || $batch_name_3_count > 0 || $batch_name_4_count > 0): ?>
                        <div class="batch-distribution">
                            <?php if ($batch_name_count > 0): ?>
                                <div class="distribution-item"><span class="distribution-dot dot-b1"></span> B1: <?= $batch_name_count ?></div>
                            <?php endif; ?>
                            <?php if ($batch_name_2_count > 0): ?>
                                <div class="distribution-item"><span class="distribution-dot dot-b2"></span> B2: <?= $batch_name_2_count ?></div>
                            <?php endif; ?>
                            <?php if ($batch_name_3_count > 0): ?>
                                <div class="distribution-item"><span class="distribution-dot dot-b3"></span> B3: <?= $batch_name_3_count ?></div>
                            <?php endif; ?>
                            <?php if ($batch_name_4_count > 0): ?>
                                <div class="distribution-item"><span class="distribution-dot dot-b4"></span> B4: <?= $batch_name_4_count ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($batch['status'] === 'completed' && $transferred_students_count > 0): ?>
                    <div class="stat-card" style="border-left-color: var(--navy-500);">
                        <div class="stat-card-label"><i class="fas fa-exchange-alt"></i> Transferred Out</div>
                        <div class="stat-card-value"><?= $transferred_students_count ?></div>
                        <div class="stat-card-sub">Students moved to other batches</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Platform + Actions -->
                <div class="info-grid">

                    <!-- Platform Details -->
                    <div class="info-panel">
                        <div class="info-panel-title"><i class="fas fa-laptop"></i> Platform Details</div>
                        <?php if ($batch['mode'] === 'online'): ?>
                            <div class="info-row">
                                <div class="info-row-label">Platform</div>
                                <div class="info-row-value"><?= htmlspecialchars($batch['platform'] ?? 'Not specified') ?></div>
                            </div>
                            <?php if (!empty($batch['meeting_link'])): ?>
                            <div class="info-row">
                                <div class="info-row-label">Meeting Link</div>
                                <div class="info-row-value">
                                    <a href="<?= htmlspecialchars($batch['meeting_link']) ?>" target="_blank">
                                        <?= htmlspecialchars($batch['meeting_link']) ?>
                                        <i class="fas fa-external-link-alt" style="font-size:.75rem;margin-left:4px;"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="info-row">
                                <div class="info-row-value" style="color:var(--navy-500);">
                                    <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                                    Offline batch — no platform information
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($batch['time_slot'])): ?>
                        <div class="info-row">
                            <div class="info-row-label">Time Slot</div>
                            <div class="info-row-value"><?= htmlspecialchars($batch['time_slot']) ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($batch['academic_year'])): ?>
                        <div class="info-row">
                            <div class="info-row-label">Academic Year</div>
                            <div class="info-row-value"><?= htmlspecialchars($batch['academic_year']) ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($batch['mentor_name'])): ?>
                        <div class="info-row" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--sand-300-40);">
                            <div class="info-row-label" style="margin-bottom:10px;">Batch Mentor</div>
                            <div class="mentor-row">
                                <?php if (!empty($batch['mentor_avatar'])): ?>
                                    <img src="../<?= htmlspecialchars($batch['mentor_avatar']) ?>" alt="mentor" class="mentor-avatar-img">
                                <?php else: ?>
                                    <div class="mentor-avatar-placeholder"><i class="fas fa-user-tie"></i></div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:600;font-size:.875rem;color:var(--navy-900);"><?= htmlspecialchars($batch['mentor_name']) ?></div>
                                    <div style="font-size:.75rem;color:var(--navy-500);"><?= htmlspecialchars($batch['mentor_email'] ?? '') ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="info-panel">
                        <div class="info-panel-title"><i class="fas fa-cog"></i> Quick Actions</div>
                        <div class="action-btn-list">

                            <a href="../exam/exams.php?batch_id=<?= urlencode($batch_id) ?>" class="action-link">
                                <i class="fas fa-upload"></i> Upload Result
                            </a>
                            <a href="manage_courses.php?batch_id=<?= $batch['batch_id'] ?>" class="action-link">
                                <i class="fas fa-book-open"></i> Manage Courses
                            </a>
                            <a href="manage_assignments.php?batch_id=<?= $batch['batch_id'] ?>" class="action-link">
                                <i class="fas fa-tasks"></i> Manage Assignments
                            </a>
                            <a href="../admin_test/create_test.php?batch_id=<?= urlencode($batch['batch_id']) ?>" class="action-link">
                                <i class="fas fa-file-alt"></i> Manage Tests
                            </a>
                            <a href="manage_student.php?batch_id=<?= $batch['batch_id'] ?>" class="action-link">
                                <i class="fas fa-users"></i> Manage Students
                            </a>
                            <a href="../schedule/schedule.php?batch_id=<?= $batch['batch_id'] ?>" class="action-link">
                                <i class="fas fa-calendar-alt"></i> View Schedule
                            </a>
                        </div>
                    </div>

                </div>
            </div><!-- /.batch-header-card -->

            <!-- ── Tab Card ── -->
            <div class="tabs-card fade-in">
                <div class="tabs-nav" id="batchTabs">
                    <button class="tab-btn active" data-tab="students">
                        <i class="fas fa-users"></i> Students
                        <span class="tab-badge"><?= count($students) ?></span>
                    </button>
                    <button class="tab-btn" data-tab="attendance">
                        <i class="fas fa-clipboard-check"></i> Attendance
                    </button>
                    <button class="tab-btn" data-tab="courses">
                        <i class="fas fa-layer-group"></i> Courses
                        <span class="tab-badge"><?= $total_courses ?></span>
                    </button>
                    <?php if (!empty($transfer_history)): ?>
                    <button class="tab-btn" data-tab="transfers">
                        <i class="fas fa-exchange-alt"></i> Transfer History
                        <span class="tab-badge"><?= count($transfer_history) ?></span>
                    </button>
                    <?php endif; ?>
                </div>

                <!-- ── STUDENTS TAB ── -->
                <div id="tab-students" class="tab-content active">
                    <?php if (empty($students)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <p>No students found for this batch.</p>
                        </div>
                    <?php else: ?>
                        <div class="students-table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student</th>
                                        <th>Contact</th>
                                        <th>Enrolled</th>
                                        <th>Status</th>
                                        <th>Type</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $i => $student): ?>
                                    <tr>
                                        <td style="color:var(--navy-500);font-size:.8rem;"><?= $i + 1 ?></td>
                                        <td>
                                            <div class="student-name-cell">
                                                <div class="student-avatar">
                                                    <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="student-name-primary">
                                                        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                                        <?php if ($student['batch_field'] === 'batch_name'): ?>
                                                            <span class="batch-field-pill field-b1">B1</span>
                                                        <?php elseif ($student['batch_field'] === 'batch_name_2'): ?>
                                                            <span class="batch-field-pill field-b2">B2</span>
                                                        <?php elseif ($student['batch_field'] === 'batch_name_3'): ?>
                                                            <span class="batch-field-pill field-b3">B3</span>
                                                        <?php elseif ($student['batch_field'] === 'batch_name_4'): ?>
                                                            <span class="batch-field-pill field-b4">B4</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="student-name-secondary"><?= htmlspecialchars($student['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="color:var(--navy-500);font-size:.84rem;"><?= htmlspecialchars($student['phone_number'] ?? '—') ?></td>
                                        <td style="color:var(--navy-500);font-size:.84rem;">
                                            <?= $student['enrollment_date'] ? date('d M Y', strtotime($student['enrollment_date'])) : '—' ?>
                                        </td>
                                        <td>
                                            <span class="student-status <?= htmlspecialchars($student['current_status']) ?>">
                                                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $student['current_status']))) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="type-badge type-<?= htmlspecialchars($student['student_type']) ?>">
                                                <?= htmlspecialchars(ucfirst($student['student_type'])) ?>
                                            </span>
                                            <?php if ($student['student_type'] === 'transferred' && !empty($student['transferred_to_batch'])): ?>
                                                <div style="font-size:.7rem;color:var(--navy-500);margin-top:3px;">
                                                    → <?= htmlspecialchars($student['transferred_to_batch']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display:flex;gap:6px;align-items:center;">
                                                <?php if ($batch['status'] !== 'completed' && !empty($available_batches)): ?>
                                                <button class="table-action transfer-btn"
                                                        data-id="<?= $student['student_id'] ?>"
                                                        data-name="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>"
                                                        title="Transfer Student">
                                                    <i class="fas fa-exchange-alt"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ── ATTENDANCE TAB ── -->
                <div id="tab-attendance" class="tab-content">
                    <!-- Overview Stats -->
                    <div class="att-stats-grid">
                        <div class="att-stat-box">
                            <div class="att-stat-number"><?= $total_scheduled_sessions ?></div>
                            <div class="att-stat-label">Total Sessions</div>
                        </div>
                        <div class="att-stat-box">
                            <div class="att-stat-number"><?= $attendance_stats['sessions_with_attendance'] ?></div>
                            <div class="att-stat-label">Sessions Recorded</div>
                            <?php
                            $cov = $attendance_stats['attendance_coverage'];
                            $covClass = $cov >= 70 ? 'coverage-good' : ($cov >= 40 ? 'coverage-fair' : 'coverage-poor');
                            ?>
                            <span class="coverage-badge <?= $covClass ?>" style="margin-top:6px;"><?= $cov ?>% coverage</span>
                        </div>
                        <div class="att-stat-box">
                            <div class="att-stat-number" style="color:var(--clr-green);"><?= $attendance_stats['present_count'] ?></div>
                            <div class="att-stat-label">Present Count</div>
                        </div>
                        <div class="att-stat-box">
                            <div class="att-stat-number" style="color:var(--clr-crimson);"><?= $attendance_stats['absent_count'] ?></div>
                            <div class="att-stat-label">Absent Count</div>
                        </div>
                    </div>

                    <!-- Overall Rate Progress -->
                    <div class="inner-card">
                        <div class="tab-section-title">Overall Attendance Rate</div>
                        <div style="margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:.84rem;color:var(--navy-500);">Present</span>
                            <span style="font-size:.9rem;font-weight:700;color:var(--clr-green);"><?= $attendance_stats['present_percentage'] ?>%</span>
                        </div>
                        <div class="progress-track" style="height:10px;margin-bottom:14px;">
                            <div class="progress-fill progress-green" style="width:<?= $attendance_stats['present_percentage'] ?>%;"></div>
                        </div>
                        <div style="margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:.84rem;color:var(--navy-500);">Absent</span>
                            <span style="font-size:.9rem;font-weight:700;color:var(--clr-crimson);"><?= $attendance_stats['absent_percentage'] ?>%</span>
                        </div>
                        <div class="progress-track" style="height:10px;">
                            <div class="progress-fill progress-crimson" style="width:<?= $attendance_stats['absent_percentage'] ?>%;"></div>
                        </div>
                    </div>

                    <!-- Charts row -->
                    <div class="two-col">
                        <!-- Attendance Donut -->
                        <div class="inner-card">
                            <div class="tab-section-title">Attendance Split</div>
                            <div class="chart-wrap" style="height:220px;">
                                <canvas id="attendanceDonutChart"></canvas>
                            </div>
                        </div>
                        <!-- Recent Sessions Bar -->
                        <div class="inner-card">
                            <div class="tab-section-title">Last 5 Sessions</div>
                            <div class="chart-wrap" style="height:220px;">
                                <canvas id="recentAttendanceChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Per-Student Attendance -->
                    <?php if (!empty($student_attendance)): ?>
                    <div class="tab-section-title">Per-Student Attendance</div>
                    <div class="students-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Rate</th>
                                    <th style="min-width:120px;">Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($student_attendance as $sa): ?>
                                <?php
                                $pct = floatval($sa['attendance_percentage']);
                                $circleClass = $pct >= 75 ? 'high' : ($pct >= 50 ? 'medium' : 'low');
                                $barClass = $pct >= 75 ? 'progress-green' : ($pct >= 50 ? 'progress-amber' : 'progress-crimson');
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-name-cell">
                                            <div class="student-avatar" style="width:32px;height:32px;font-size:.75rem;">
                                                <?= strtoupper(substr($sa['first_name'],0,1) . substr($sa['last_name'],0,1)) ?>
                                            </div>
                                            <span style="font-weight:600;font-size:.875rem;">
                                                <?= htmlspecialchars($sa['first_name'] . ' ' . $sa['last_name']) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td style="color:var(--clr-green);font-weight:600;"><?= $sa['present_count'] ?></td>
                                    <td style="color:var(--clr-crimson);font-weight:600;"><?= $sa['absent_count'] ?></td>
                                    <td>
                                        <span class="att-circle <?= $circleClass ?>" style="width:48px;height:48px;font-size:.85rem;margin:0;">
                                            <?= $pct ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress-track">
                                            <div class="progress-fill <?= $barClass ?>" style="width:<?= $pct ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ── COURSES TAB ── -->
                <div id="tab-courses" class="tab-content">
                    <!-- Progress -->
                    <div class="inner-card">
                        <div class="courses-progress-label">
                            <div class="tab-section-title" style="margin-bottom:0;">Overall Course Progress</div>
                            <span style="font-family:var(--font-display);font-weight:800;font-size:1.1rem;color:var(--navy-900);">
                                <?= $course_progress_percentage ?>%
                            </span>
                        </div>
                        <div class="progress-track" style="height:10px;margin-top:10px;">
                            <div class="progress-fill progress-navy" style="width:<?= $course_progress_percentage ?>%;"></div>
                        </div>
                        <div style="font-size:.78rem;color:var(--navy-500);margin-top:8px;">
                            <?= $completed_courses ?> of <?= $total_courses ?> courses completed
                        </div>
                    </div>

                    <?php if (empty($assigned_courses)): ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <p>No courses assigned to this batch yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="courses-grid">
                            <?php foreach ($assigned_courses as $course): ?>
                            <a href="batch_course_view.php?batch_id=<?= urlencode($batch_id) ?>&course_id=<?= urlencode($course['id']) ?>" class="course-card" style="text-decoration:none; color:inherit; display:block;">
                                <?php if (!empty($course['thumbnail'])): ?>
                                    <img src="../<?= htmlspecialchars($course['thumbnail']) ?>" alt="<?= htmlspecialchars($course['name']) ?>" class="course-thumb">
                                <?php else: ?>
                                    <div class="course-thumb-placeholder">
                                        <i class="fas fa-book"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="course-card-body">
                                    <div class="course-card-name"><?= htmlspecialchars($course['name']) ?></div>
                                    <?php
                                    $cs = $course['status'] ?? 'pending';
                                    $csClass = $cs === 'completed' ? 'course-status-completed' : ($cs === 'ongoing' ? 'course-status-ongoing' : 'course-status-pending');
                                    ?>
                                    <span class="course-status-badge <?= $csClass ?>">
                                        <?= htmlspecialchars(ucfirst($cs)) ?>
                                    </span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ── TRANSFER HISTORY TAB ── -->
                <?php if (!empty($transfer_history)): ?>
                <div id="tab-transfers" class="tab-content">
                    <div class="students-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>From Batch</th>
                                    <th>To Batch</th>
                                    <th>Transfer Date</th>
                                    <th>Reason</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transfer_history as $th): ?>
                                <tr>
                                    <td>
                                        <div class="student-name-cell">
                                            <div class="student-avatar" style="width:32px;height:32px;font-size:.72rem;">
                                                <?= strtoupper(substr($th['first_name'],0,1) . substr($th['last_name'],0,1)) ?>
                                            </div>
                                            <div>
                                                <div class="student-name-primary"><?= htmlspecialchars($th['first_name'] . ' ' . $th['last_name']) ?></div>
                                                <div class="student-name-secondary"><?= htmlspecialchars($th['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="font-size:.84rem;color:var(--navy-500);"><?= htmlspecialchars($th['from_batch_name'] ?? $th['from_batch_id']) ?></td>
                                    <td style="font-size:.84rem;color:var(--clr-green);font-weight:600;"><?= htmlspecialchars($th['to_batch_name'] ?? $th['to_batch_id']) ?></td>
                                    <td style="font-size:.84rem;color:var(--navy-500);"><?= date('d M Y', strtotime($th['transfer_date'])) ?></td>
                                    <td style="font-size:.84rem;color:var(--navy-500);max-width:200px;"><?= htmlspecialchars($th['reason'] ?? '—') ?></td>
                                    <td style="font-size:.84rem;color:var(--navy-500);"><?= htmlspecialchars($th['transferred_by_name'] ?? '—') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /.tabs-card -->

        </div><!-- /.content-wrap -->
    </div><!-- /.view-main -->

    <!-- ── Transfer Student Modal ── -->
    <div id="transferModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-title">
                <span><i class="fas fa-exchange-alt" style="margin-right:8px;"></i> Transfer Student</span>
                <button class="modal-close" id="closeTransferModal"><i class="fas fa-times"></i></button>
            </div>
            <form id="transferForm" method="POST" action="transfer_student.php">
                <input type="hidden" name="student_id" id="transfer_student_id">
                <input type="hidden" name="from_batch_id" value="<?= htmlspecialchars($batch_id) ?>">

                <div class="modal-field">
                    <label>Student</label>
                    <input type="text" id="transfer_student_name" readonly>
                </div>
                <div class="modal-field">
                    <label>Transfer To Batch</label>
                    <select name="to_batch_id" required>
                        <option value="">Select Batch</option>
                        <?php foreach ($available_batches as $ab): ?>
                            <option value="<?= htmlspecialchars($ab['batch_id']) ?>">
                                <?= htmlspecialchars($ab['batch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-field">
                    <label>Reason (optional)</label>
                    <textarea name="reason" rows="3" placeholder="Reason for transfer…"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" id="cancelTransfer">Cancel</button>
                    <button type="submit" class="btn btn-navy">
                        <i class="fas fa-exchange-alt"></i> Confirm Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        /* ============================================================
           TAB SWITCHING
           ============================================================ */
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                const target = 'tab-' + this.dataset.tab;
                document.getElementById(target).classList.add('active');
            });
        });

        /* ============================================================
           STUDENT COUNTER ANIMATION
           ============================================================ */
        (function() {
            const el = document.getElementById('studentCounter');
            if (!el) return;
            const target = parseInt(el.textContent) || 0;
            let current = 0;
            const step = Math.ceil(target / 30);
            const timer = setInterval(() => {
                current = Math.min(current + step, target);
                el.textContent = current;
                if (current >= target) clearInterval(timer);
            }, 40);
        })();

        /* ============================================================
           CHART.JS — Attendance Donut
           ============================================================ */
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#456882';
        Chart.defaults.plugins.tooltip.backgroundColor = '#1B3C53';
        Chart.defaults.plugins.tooltip.titleColor = '#F5F7FA';
        Chart.defaults.plugins.tooltip.bodyColor = '#D2C1B6';
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.padding = 10;

        const donutCtx = document.getElementById('attendanceDonutChart');
        if (donutCtx) {
            new Chart(donutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent'],
                    datasets: [{
                        data: [<?= $attendance_stats['present_count'] ?>, <?= $attendance_stats['absent_count'] ?>],
                        backgroundColor: ['#1a7f5a', '#991b1b'],
                        borderWidth: 0,
                        hoverOffset: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, padding: 16, font: { size: 11 } }
                        }
                    }
                }
            });
        }

        /* ============================================================
           CHART.JS — Recent Sessions Bar
           ============================================================ */
        const recentCtx = document.getElementById('recentAttendanceChart');
        if (recentCtx) {
            const recentLabels = <?= json_encode(array_column(array_reverse($recent_attendance), 'date')) ?>;
            const recentData   = <?= json_encode(array_column(array_reverse($recent_attendance), 'attendance_percentage')) ?>;

            new Chart(recentCtx, {
                type: 'bar',
                data: {
                    labels: recentLabels.map(d => {
                        const dt = new Date(d);
                        return dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
                    }),
                    datasets: [{
                        label: 'Attendance %',
                        data: recentData,
                        backgroundColor: '#234C6A',
                        borderRadius: 5,
                        maxBarThickness: 32
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true, max: 100,
                            grid: { color: 'rgba(210,193,182,0.4)', borderDash: [4,4] },
                            ticks: { callback: v => v + '%', font: { size: 11 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 11 } }
                        }
                    }
                }
            });
        }

        /* ============================================================
           TRANSFER MODAL
           ============================================================ */
        document.querySelectorAll('.transfer-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('transfer_student_id').value   = this.dataset.id;
                document.getElementById('transfer_student_name').value = this.dataset.name;
                document.getElementById('transferModal').classList.add('open');
            });
        });

        document.getElementById('closeTransferModal').addEventListener('click', () => {
            document.getElementById('transferModal').classList.remove('open');
        });

        document.getElementById('cancelTransfer').addEventListener('click', () => {
            document.getElementById('transferModal').classList.remove('open');
        });

        document.getElementById('transferModal').addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('open');
        });

        // Toast notification system
        function showTransferToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : '#ef4444'};
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                font-weight: 600;
                font-family: 'Inter', sans-serif;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 99999;
                transform: translateY(100px);
                opacity: 0;
                transition: all 0.3s ease;
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.transform = 'translateY(0)';
                toast.style.opacity = '1';
            }, 10);
            
            setTimeout(() => {
                toast.style.transform = 'translateY(100px)';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Handle transfer form submit via AJAX
        document.getElementById('transferForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Transferring...';
            btn.disabled = true;
            
            const formData = new FormData(this);
            
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                
                if(data.success) {
                    showTransferToast(data.message, 'success');
                    document.getElementById('transferModal').classList.remove('open');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showTransferToast(data.message || 'Error transferring student', 'error');
                }
            })
            .catch(err => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                showTransferToast('An unexpected error occurred', 'error');
            });
        });
    </script>
</body>
</html>