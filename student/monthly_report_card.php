<?php
// monthly_report_card.php
require_once '../db_connection.php';
session_start();

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Get student ID and month parameters
$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Parse month to get start and end dates
$year = substr($month, 0, 4);
$month_num = substr($month, 5, 2);
$month_start = $year . '-' . $month_num . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

// Redirect if student ID is missing
if (!$student_id) {
    header("Location: ../student_list.php");
    exit();
}

try {
    // Use existing $db from db_connection.php
    // Fetch student details
    $stmt = $db->prepare("
        SELECT s.*, c.name as course
        FROM students s 
        LEFT JOIN courses c ON s.course = c.id
        WHERE s.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        header("Location: ../student_list.php");
        exit();
    }
    
    // Get all batch IDs where student is enrolled
    $batch_ids = [];
    if (!empty($student['batch_name'])) $batch_ids[] = $student['batch_name'];
    if (!empty($student['batch_name_2'])) $batch_ids[] = $student['batch_name_2'];
    if (!empty($student['batch_name_3'])) $batch_ids[] = $student['batch_name_3'];
    $batch_ids = array_filter($batch_ids);
    
    // Fetch batch details
    $all_batches = [];
    $batch_details = [];
    if (!empty($batch_ids)) {
        $placeholders = str_repeat('?,', count($batch_ids) - 1) . '?';
        $stmt = $db->prepare("SELECT * FROM batches WHERE batch_id IN ($placeholders)");
        $stmt->execute($batch_ids);
        $all_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all_batches as $batch) {
            $batch_details[$batch['batch_id']] = $batch;
        }
    }
    
    // Initialize monthly report data
    $report_data = [
        'student' => $student,
        'month' => $month,
        'month_start' => $month_start,
        'month_end' => $month_end,
        'attendance' => [],
        'weekly_feedback' => [],
        'assignments' => [],
        'mcq_tests' => [],
        'overall_scores' => [],
        'weekly_breakdown' => []
    ];
    
    // Get all weeks in the month
    $weeks = [];
    $current_date = $month_start;
    $week_num = 1;
    
    while ($current_date <= $month_end) {
        $week_start = date('Y-m-d', strtotime('monday this week', strtotime($current_date)));
        $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($current_date)));
        
        if ($week_end >= $month_start && $week_start <= $month_end) {
            $weeks[] = [
                'week_num' => $week_num,
                'start' => max($week_start, $month_start),
                'end' => min($week_end, $month_end)
            ];
            $week_num++;
        }
        $current_date = date('Y-m-d', strtotime($current_date . ' +7 days'));
    }
    
    // ============ 1. ATTENDANCE SUMMARY ============
    $total_classes = 0;
    $present_count = 0;
    $attendance_by_batch = [];
    
    foreach ($batch_ids as $batch_id) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present
            FROM attendance 
            WHERE student_name = ? 
            AND batch_id = ?
            AND date BETWEEN ? AND ?
        ");
        $stmt->execute([
            $student['first_name'] . ' ' . $student['last_name'],
            $batch_id,
            $month_start,
            $month_end
        ]);
        $batch_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $batch_total = (int)$batch_stats['total'];
        $batch_present = (int)$batch_stats['present'];
        
        if ($batch_total > 0) {
            $attendance_by_batch[$batch_id] = [
                'batch_name' => $batch_details[$batch_id]['batch_name'] ?? $batch_id,
                'total_classes' => $batch_total,
                'present_count' => $batch_present,
                'absent_count' => $batch_total - $batch_present,
                'attendance_rate' => round(($batch_present / $batch_total) * 100, 1),
                'attendance_score' => round(($batch_present / $batch_total) * 5, 1)
            ];
            
            $total_classes += $batch_total;
            $present_count += $batch_present;
        }
    }
    
    $overall_attendance_rate = $total_classes > 0 ? ($present_count / $total_classes) * 100 : 0;
    $overall_attendance_score = round(($overall_attendance_rate / 100) * 5, 1);
    
    $report_data['attendance_summary'] = [
        'total_classes' => $total_classes,
        'present_count' => $present_count,
        'absent_count' => $total_classes - $present_count,
        'attendance_rate' => round($overall_attendance_rate, 1),
        'attendance_score' => $overall_attendance_score,
        'by_batch' => $attendance_by_batch
    ];
    $report_data['overall_scores']['attendance'] = $overall_attendance_score;
    
    // ============ 2. WEEKLY FEEDBACK SUMMARY ============
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_feedback,
               AVG(rating) as avg_rating,
               SUM(rating) as total_rating,
               COUNT(DISTINCT trainer_id) as unique_trainers,
               COUNT(DISTINCT batch_id) as unique_batches
        FROM weekly_feedback 
        WHERE student_id = ?
        AND week_start_date >= ?
        AND week_end_date <= ?
    ");
    $stmt->execute([$student_id, $month_start, $month_end]);
    $feedback_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $feedback_count = (int)$feedback_stats['total_feedback'];
    $average_feedback_score = $feedback_count > 0 ? round($feedback_stats['avg_rating'], 1) : 0;
    $total_feedback_rating = $feedback_stats['total_rating'] ?? 0;
    
    $report_data['feedback_summary'] = [
        'total_feedback' => $feedback_count,
        'average_rating' => $average_feedback_score,
        'total_rating' => round($total_feedback_rating, 1),
        'unique_trainers' => (int)$feedback_stats['unique_trainers'],
        'unique_batches' => (int)$feedback_stats['unique_batches'],
        'feedback_score' => $average_feedback_score
    ];
    $report_data['overall_scores']['feedback'] = $average_feedback_score;
    
    // ============ 3. ASSIGNMENTS SUMMARY ============
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_assignments,
               COUNT(CASE WHEN grade IS NOT NULL THEN 1 END) as graded_count,
               AVG(CASE WHEN grade > 0 AND max_marks > 0 
                   THEN (grade / max_marks) * 100 END) as avg_percentage,
               SUM(CASE WHEN grade > 0 AND max_marks > 0 
                   THEN (grade / max_marks) * 5 END) as total_score_5pt,
               SUM(grade) as total_obtained,
               SUM(max_marks) as total_max
        FROM assignment_submissions au
        LEFT JOIN uploads a ON au.upload_id = a.id
        WHERE au.student_id = ?
        AND DATE(au.submitted_at) BETWEEN ? AND ?
        AND au.status = 'graded'
    ");
    $stmt->execute([$student_id, $month_start, $month_end]);
    $assignment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $assignment_count = (int)$assignment_stats['total_assignments'];
    $graded_count = (int)$assignment_stats['graded_count'];
    $avg_assignment_percentage = $graded_count > 0 ? round($assignment_stats['avg_percentage'], 1) : 0;
    $total_assignment_score = $assignment_stats['total_score_5pt'] ?? 0;
    $average_assignment_score = $graded_count > 0 ? round($total_assignment_score / $graded_count, 1) : 0;
    
    $report_data['assignment_summary'] = [
        'total_assignments' => $assignment_count,
        'graded_count' => $graded_count,
        'pending_count' => $assignment_count - $graded_count,
        'avg_percentage' => $avg_assignment_percentage,
        'total_obtained' => round($assignment_stats['total_obtained'] ?? 0, 1),
        'total_max' => round($assignment_stats['total_max'] ?? 0, 1),
        'assignment_score' => $average_assignment_score
    ];
    $report_data['overall_scores']['assignments'] = $average_assignment_score;
    
    // ============ 4. MCQ TESTS SUMMARY ============
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_tests,
               COUNT(CASE WHEN obtained_marks > 0 THEN 1 END) as attempted_count,
               AVG(percentage) as avg_percentage,
               SUM(obtained_marks) as total_obtained,
               SUM(t.total_marks) as total_max,
               SUM((ta.obtained_marks / t.total_marks) * 5) as total_score_5pt,
               AVG(ta.obtained_marks) as avg_obtained,
               AVG(t.total_marks) as avg_total
        FROM test_attempts ta
        JOIN tests t ON ta.test_id = t.id
        WHERE ta.student_id = ?
        AND ta.status = 'submitted'
        AND DATE(ta.submitted_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$student_id, $month_start, $month_end]);
    $test_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $test_count = (int)$test_stats['total_tests'];
    $attempted_count = (int)$test_stats['attempted_count'];
    $avg_test_percentage = $test_count > 0 ? round($test_stats['avg_percentage'], 1) : 0;
    $total_test_score = $test_stats['total_score_5pt'] ?? 0;
    $average_test_score = $test_count > 0 ? round($total_test_score / $test_count, 1) : 0;
    
    $report_data['test_summary'] = [
        'total_tests' => $test_count,
        'attempted_count' => $attempted_count,
        'avg_percentage' => $avg_test_percentage,
        'total_obtained' => round($test_stats['total_obtained'] ?? 0, 1),
        'total_max' => round($test_stats['total_max'] ?? 0, 1),
        'avg_obtained' => round($test_stats['avg_obtained'] ?? 0, 1),
        'avg_total' => round($test_stats['avg_total'] ?? 0, 1),
        'test_score' => $average_test_score
    ];
    $report_data['overall_scores']['tests'] = $average_test_score;
    
    // ============ 5. WEEKLY BREAKDOWN ============
    foreach ($weeks as $week) {
        // Attendance for week
        $week_total_classes = 0;
        $week_present_count = 0;
        
        foreach ($batch_ids as $batch_id) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as total, 
                       SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present
                FROM attendance 
                WHERE student_name = ? 
                AND batch_id = ?
                AND date BETWEEN ? AND ?
            ");
            $stmt->execute([
                $student['first_name'] . ' ' . $student['last_name'],
                $batch_id,
                $week['start'],
                $week['end']
            ]);
            $week_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $week_total_classes += (int)$week_stats['total'];
            $week_present_count += (int)$week_stats['present'];
        }
        
        $week_attendance_score = $week_total_classes > 0 ? round(($week_present_count / $week_total_classes) * 5, 1) : 0;
        
        // Feedback for week
        $stmt = $db->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as count
            FROM weekly_feedback 
            WHERE student_id = ?
            AND week_start_date = ?
            AND week_end_date = ?
        ");
        $stmt->execute([$student_id, $week['start'], $week['end']]);
        $week_feedback = $stmt->fetch(PDO::FETCH_ASSOC);
        $week_feedback_score = $week_feedback['count'] > 0 ? round($week_feedback['avg_rating'], 1) : 0;
        
        // Assignments for week
        $stmt = $db->prepare("
            SELECT AVG((grade / max_marks) * 5) as avg_score, COUNT(*) as count
            FROM assignment_submissions au
            LEFT JOIN uploads a ON au.upload_id = a.id
            WHERE au.student_id = ?
            AND DATE(au.submitted_at) BETWEEN ? AND ?
            AND au.status = 'graded'
            AND grade > 0 AND max_marks > 0
        ");
        $stmt->execute([$student_id, $week['start'], $week['end']]);
        $week_assignments = $stmt->fetch(PDO::FETCH_ASSOC);
        $week_assignment_score = $week_assignments['count'] > 0 ? round($week_assignments['avg_score'], 1) : 0;
        
        // Tests for week
        $stmt = $db->prepare("
            SELECT AVG((ta.obtained_marks / t.total_marks) * 5) as avg_score, COUNT(*) as count
            FROM test_attempts ta
            JOIN tests t ON ta.test_id = t.id
            WHERE ta.student_id = ?
            AND ta.status = 'submitted'
            AND DATE(ta.submitted_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$student_id, $week['start'], $week['end']]);
        $week_tests = $stmt->fetch(PDO::FETCH_ASSOC);
        $week_test_score = $week_tests['count'] > 0 ? round($week_tests['avg_score'], 1) : 0;
        
        // Calculate weekly overall score
        $components = [
            'attendance' => $week_attendance_score,
            'feedback' => $week_feedback_score,
            'assignments' => $week_assignment_score,
            'tests' => $week_test_score
        ];
        $weights = ['attendance' => 0.2, 'feedback' => 0.3, 'assignments' => 0.25, 'tests' => 0.25];
        
        $weighted_sum = 0;
        $total_weight = 0;
        
        foreach ($components as $comp => $score) {
            if ($score > 0) {
                $weighted_sum += $score * $weights[$comp];
                $total_weight += $weights[$comp];
            }
        }
        
        $week_overall_score = $total_weight > 0 ? round($weighted_sum / $total_weight, 1) : 0;
        
        $report_data['weekly_breakdown'][] = [
            'week_num' => $week['week_num'],
            'start' => date('M j', strtotime($week['start'])),
            'end' => date('M j', strtotime($week['end'])),
            'attendance_score' => $week_attendance_score,
            'feedback_score' => $week_feedback_score,
            'assignment_score' => $week_assignment_score,
            'test_score' => $week_test_score,
            'overall_score' => $week_overall_score,
            'attendance_present' => $week_present_count,
            'attendance_total' => $week_total_classes,
            'feedback_count' => $week_feedback['count'] ?? 0,
            'assignment_count' => $week_assignments['count'] ?? 0,
            'test_count' => $week_tests['count'] ?? 0
        ];
    }
    
    // ============ 6. CALCULATE OVERALL MONTHLY SCORE ============
    $components = ['attendance', 'feedback', 'assignments', 'tests'];
    $weights = ['attendance' => 0.2, 'feedback' => 0.3, 'assignments' => 0.25, 'tests' => 0.25];
    
    $weighted_sum = 0;
    $total_weight = 0;
    
    foreach ($components as $component) {
        if (isset($report_data['overall_scores'][$component]) && $report_data['overall_scores'][$component] > 0) {
            $weighted_sum += $report_data['overall_scores'][$component] * $weights[$component];
            $total_weight += $weights[$component];
        }
    }
    
    $overall_monthly_score = $total_weight > 0 ? round($weighted_sum / $total_weight, 1) : 0;
    $report_data['overall_scores']['monthly'] = $overall_monthly_score;
    
    // ============ 7. GET PREVIOUS MONTH'S SCORE FOR COMPARISON ============
    $prev_month = date('Y-m', strtotime($month . ' -1 month'));
    $prev_month_start = $prev_month . '-01';
    $prev_month_end = date('Y-m-t', strtotime($prev_month_start));
    
    $stmt = $db->prepare("
        SELECT AVG(rating) as avg_rating 
        FROM weekly_feedback 
        WHERE student_id = ? 
        AND week_start_date >= ? 
        AND week_end_date <= ?
    ");
    $stmt->execute([$student_id, $prev_month_start, $prev_month_end]);
    $prev_feedback = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $prev_monthly_score = 0;
    if ($prev_feedback && $prev_feedback['avg_rating']) {
        $prev_monthly_score = round($prev_feedback['avg_rating'], 1);
    }
    
    $report_data['comparison'] = [
        'previous_score' => $prev_monthly_score,
        'change' => $overall_monthly_score - $prev_monthly_score,
        'trend' => $overall_monthly_score > $prev_monthly_score ? 'up' : 
                  ($overall_monthly_score < $prev_monthly_score ? 'down' : 'stable')
    ];
    
    // ============ 8. GENERATE STRENGTHS AND IMPROVEMENTS ============
    $strengths = [];
    $improvements = [];
    
    if ($overall_attendance_rate >= 90) {
        $strengths[] = "Excellent attendance ({$overall_attendance_rate}%)";
    } elseif ($overall_attendance_rate >= 75) {
        $strengths[] = "Good attendance ({$overall_attendance_rate}%)";
    } elseif ($overall_attendance_rate < 60 && $total_classes > 0) {
        $improvements[] = "Low attendance ({$overall_attendance_rate}%) - " . ($total_classes - $present_count) . " absences";
    }
    
    if ($average_feedback_score >= 4.5) {
        $strengths[] = "Outstanding trainer feedback (" . number_format($average_feedback_score, 1) . "/5)";
    } elseif ($average_feedback_score >= 4.0) {
        $strengths[] = "Very good trainer feedback (" . number_format($average_feedback_score, 1) . "/5)";
    } elseif ($average_feedback_score < 3.0 && $feedback_count > 0) {
        $improvements[] = "Below average trainer feedback (" . number_format($average_feedback_score, 1) . "/5)";
    }
    
    if ($avg_assignment_percentage >= 85) {
        $strengths[] = "Excellent assignment performance ({$avg_assignment_percentage}%)";
    } elseif ($avg_assignment_percentage >= 70) {
        $strengths[] = "Good assignment performance ({$avg_assignment_percentage}%)";
    } elseif ($avg_assignment_percentage < 60 && $graded_count > 0) {
        $improvements[] = "Assignment scores need improvement ({$avg_assignment_percentage}%)";
    }
    
    if ($avg_test_percentage >= 85) {
        $strengths[] = "Excellent test scores ({$avg_test_percentage}%)";
    } elseif ($avg_test_percentage >= 70) {
        $strengths[] = "Good test performance ({$avg_test_percentage}%)";
    } elseif ($avg_test_percentage < 60 && $test_count > 0) {
        $improvements[] = "Test scores need improvement ({$avg_test_percentage}%)";
    }
    
    if ($feedback_count == 0) {
        $improvements[] = "No trainer feedback received this month";
    }
    if ($graded_count == 0 && $assignment_count > 0) {
        $improvements[] = "Assignments pending grading";
    } elseif ($assignment_count == 0) {
        $improvements[] = "No assignments submitted this month";
    }
    if ($test_count == 0) {
        $improvements[] = "No tests attempted this month";
    }
    
    $report_data['strengths'] = $strengths;
    $report_data['improvements'] = $improvements;
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle PDF generation
if (isset($_GET['download']) && $_GET['download'] == 'pdf') {
    $html = generatePDFContent($report_data, true);
    
    require_once('../vendor/autoload.php');
    
    $filename = "Monthly_Report_{$student['first_name']}_{$student['last_name']}_{$month}.pdf";
    
    $html2pdf = new \Spipu\Html2Pdf\Html2Pdf('P', 'A4', 'en', true, 'UTF-8', [10, 10, 10, 10]);
    $html2pdf->setDefaultFont('Arial');
    $html2pdf->writeHTML($html);
    $html2pdf->output($filename, 'D');
    exit;
}

// Function to generate content for both web and PDF (ENHANCED UI with NEW THEME)
function generatePDFContent($data, $is_pdf = false) {
    $student = $data['student'];
    $month = $data['month'];
    $month_name = date('F Y', strtotime($data['month'] . '-01'));
    $month_start = date('F j, Y', strtotime($data['month_start']));
    $month_end = date('F j, Y', strtotime($data['month_end']));
    $overall_score = $data['overall_scores']['monthly'] ?? 0;

    // Helper to determine color class based on score
    $scoreColorClass = function($score, $max = 5) {
        $pct = ($score / $max) * 100;
        if ($pct >= 80) return 'excellent';
        if ($pct >= 60) return 'good';
        if ($pct >= 40) return 'average';
        return 'poor';
    };

    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Monthly Report Card - ' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</title>
        <style>
            /* ===== CSS VARIABLES (NEW THEME) ===== */
            :root {
                --primary-dark: #1B3C53;
                --primary: #234C6A;
                --primary-light: #456882;
                --accent-warm: #D2C1B6;
                --accent-warm-light: #E5D9D0;
                --accent-warm-dark: #B8A898;
                --gold: #C4A962;
                --gold-light: #D4BC7E;
                --bg: #F8F6F3;
                --surface: #FFFFFF;
                --surface-hover: #F3F4F6;
                --text: #1F2937;
                --text-light: #6B7280;
                --border: #E5E7EB;
                --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
                --shadow: 0 4px 12px rgba(0,0,0,0.08);
                --shadow-lg: 0 10px 25px rgba(0,0,0,0.12);
                --radius: 12px;
                --radius-sm: 8px;
                --success: #059669;
                --warning: #D97706;
                --danger: #DC2626;
                --info: #0284C7;
                --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 30%, #456882 70%, #B8A898 100%);
            }

            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
                background: linear-gradient(135deg, #F8F6F3 0%, #E5D9D0 100%);
                color: var(--text);
                line-height: 1.5;
                padding: 20px;
                font-size: 14px;
                min-height: 100vh;
            }
            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: var(--surface);
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(27, 60, 83, 0.15);
                overflow: hidden;
            }

            /* ===== ANIMATIONS ===== */
            @keyframes fadeInUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes fadeInLeft {
                from { opacity: 0; transform: translateX(-20px); }
                to { opacity: 1; transform: translateX(0); }
            }
            @keyframes pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.02); }
            }
            @keyframes shimmer {
                0% { background-position: -200% 0; }
                100% { background-position: 200% 0; }
            }
            .animate-in {
                animation: fadeInUp 0.6s ease-out forwards;
                opacity: 0;
            }
            .delay-1 { animation-delay: 0.1s; }
            .delay-2 { animation-delay: 0.2s; }
            .delay-3 { animation-delay: 0.3s; }
            .delay-4 { animation-delay: 0.4s; }
            .delay-5 { animation-delay: 0.5s; }

            /* ===== HEADER ===== */
            .report-header {
                background: var(--primary-gradient);
                color: white;
                padding: 35px 40px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }
            .report-header::before {
                content: "";
                position: absolute;
                inset: 0;
                background: url("data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'0.05\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
                animation: shimmer 20s linear infinite;
                background-size: 60px 60px;
            }
            .report-header h1 { 
                font-size: 26px; 
                font-weight: 700; 
                margin-bottom: 5px; 
                letter-spacing: -0.5px;
                position: relative;
                z-index: 1;
            }
            .report-header .subtitle { 
                font-size: 14px; 
                opacity: 0.9; 
                font-weight: 500;
                position: relative;
                z-index: 1;
            }
            .report-header .month-range { 
                font-size: 16px; 
                margin-top: 8px; 
                opacity: 0.85;
                position: relative;
                z-index: 1;
            }

            /* ===== STUDENT CARD ===== */
            .student-card {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                padding: 25px 40px;
                background: linear-gradient(135deg, rgba(35, 76, 106, 0.04), rgba(210, 193, 182, 0.08));
                border-bottom: 3px solid var(--primary);
                align-items: center;
            }
            .student-avatar {
                width: 70px; height: 70px;
                background: var(--primary-gradient);
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                color: white; font-size: 26px; font-weight: 700;
                box-shadow: 0 8px 20px rgba(27, 60, 83, 0.2);
            }
            .student-details { flex: 1; }
            .student-details h2 { font-size: 22px; font-weight: 700; margin-bottom: 5px; color: var(--primary-dark); }
            .student-details .meta {
                color: var(--text-light); font-size: 14px;
                display: flex; flex-wrap: wrap; gap: 15px;
            }
            .student-details .meta span { 
                display: inline-flex; align-items: center; gap: 5px;
                padding: 4px 12px;
                background: white;
                border-radius: 20px;
                border: 1px solid var(--border);
            }

            /* ===== CONTROLS ===== */
            .controls-bar {
                background: var(--surface);
                padding: 15px 40px;
                display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
                border-bottom: 1px solid var(--border);
            }
            .btn {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 10px 18px; border-radius: 10px;
                font-weight: 600; font-size: 13px; text-decoration: none;
                cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
                border: 1px solid transparent;
                letter-spacing: 0.3px;
            }
            .btn-primary { 
                background: linear-gradient(135deg, var(--primary-dark), var(--primary)); 
                color: white; 
                border-color: transparent;
                box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2);
            }
            .btn-primary:hover { 
                transform: translateY(-2px); 
                box-shadow: 0 8px 24px rgba(27, 60, 83, 0.3);
                background: linear-gradient(135deg, var(--primary), var(--primary-light));
            }
            .btn-outline { 
                background: transparent; 
                color: var(--primary); 
                border-color: var(--primary);
                font-weight: 600;
            }
            .btn-outline:hover { 
                background: var(--primary); 
                color: white; 
            }
            .btn-neutral { 
                background: white; 
                color: var(--text); 
                border-color: var(--border);
                box-shadow: var(--shadow-sm);
            }
            .btn-neutral:hover { 
                background: var(--surface-hover);
                border-color: var(--primary-light);
            }
            .month-picker input {
                padding: 10px 14px; border-radius: 10px;
                border: 1.5px solid var(--border); font-size: 14px;
                background: var(--surface); color: var(--text);
                transition: border-color 0.3s;
                font-weight: 500;
            }
            .month-picker input:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 4px rgba(35, 76, 106, 0.08);
            }

            /* ===== KPI GRID ===== */
            .kpi-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 20px; padding: 30px 40px;
            }
            .kpi-card {
                background: var(--surface); border-radius: var(--radius);
                padding: 24px; text-align: center;
                box-shadow: var(--shadow-sm); 
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                border: 1px solid var(--border);
                position: relative;
                overflow: hidden;
            }
            .kpi-card::before {
                content: "";
                position: absolute;
                top: 0; left: 0;
                width: 100%;
                height: 3px;
                background: var(--primary-gradient);
                opacity: 0;
                transition: opacity 0.3s;
            }
            .kpi-card:hover {
                transform: translateY(-6px);
                box-shadow: var(--shadow);
                border-color: rgba(35, 76, 106, 0.2);
            }
            .kpi-card:hover::before {
                opacity: 1;
            }
            .kpi-icon { font-size: 32px; margin-bottom: 12px; }
            .kpi-value { font-size: 30px; font-weight: 700; margin: 8px 0; }
            .kpi-value.excellent { color: #059669; }
            .kpi-value.good { color: #0284C7; }
            .kpi-value.average { color: #D97706; }
            .kpi-value.poor { color: #DC2626; }
            .kpi-label { 
                font-size: 13px; 
                color: var(--text-light); 
                text-transform: uppercase; 
                font-weight: 700; 
                letter-spacing: 1px; 
            }
            .kpi-sub { font-size: 12px; color: var(--text-light); margin-top: 6px; }
            .progress-bar-bg {
                background: var(--border); border-radius: 100px; height: 8px;
                margin-top: 12px; overflow: hidden;
            }
            .progress-fill {
                height: 100%; border-radius: 100px; 
                transition: width 1.2s cubic-bezier(0.22, 1, 0.36, 1);
                background: var(--primary-gradient);
                position: relative;
            }
            .progress-fill::after {
                content: "";
                position: absolute;
                top: 0; left: 0; right: 0; bottom: 0;
                background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
                animation: shimmer 2s infinite;
                background-size: 200% 100%;
            }

            /* ===== OVERALL SCORE ===== */
            .score-overview {
                padding: 30px 40px;
                display: flex; flex-wrap: wrap; gap: 20px;
                background: linear-gradient(135deg, rgba(35, 76, 106, 0.03), rgba(210, 193, 182, 0.06));
            }
            .overall-card {
                flex: 2; min-width: 250px;
                background: white; border-radius: var(--radius);
                padding: 30px; text-align: center;
                box-shadow: var(--shadow); 
                border: 2px solid var(--primary);
                animation: pulse 3s ease-in-out infinite;
            }
            .overall-label { 
                font-size: 16px; 
                color: var(--text-light); 
                margin-bottom: 8px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .overall-number { 
                font-size: 56px; 
                font-weight: 800; 
                color: var(--primary-dark); 
                line-height: 1;
                font-family: "Playfair Display", Georgia, serif;
            }
            .overall-max { font-size: 20px; color: var(--text-light); font-weight: 500; }
            .trend-badge {
                display: inline-block; padding: 6px 16px; border-radius: 20px;
                font-size: 13px; font-weight: 600; margin-top: 10px;
                letter-spacing: 0.3px;
            }
            .trend-up { background: rgba(5, 150, 105, 0.1); color: #065F46; border: 1px solid rgba(5, 150, 105, 0.2); }
            .trend-down { background: rgba(220, 38, 38, 0.08); color: #991B1B; border: 1px solid rgba(220, 38, 38, 0.2); }
            .trend-stable { background: rgba(107, 114, 128, 0.08); color: #374151; border: 1px solid rgba(107, 114, 128, 0.2); }

            /* ===== SECTIONS ===== */
            .section {
                margin: 30px 40px;
                border: 1px solid var(--border);
                border-radius: var(--radius);
                overflow: hidden;
                background: var(--surface);
                box-shadow: var(--shadow-sm);
            }
            .section-header {
                background: linear-gradient(135deg, rgba(35, 76, 106, 0.05), rgba(210, 193, 182, 0.08));
                padding: 18px 24px;
                font-weight: 700; font-size: 16px;
                display: flex; align-items: center; gap: 10px;
                border-bottom: 1px solid var(--border);
                color: var(--primary-dark);
                letter-spacing: 0.3px;
            }
            .section-body { padding: 24px; }

            /* ===== TABLE ===== */
            .report-table {
                width: 100%; border-collapse: collapse; font-size: 13px;
            }
            .report-table th {
                background: linear-gradient(135deg, var(--primary-dark), var(--primary));
                padding: 14px 16px;
                text-align: left; font-weight: 600; color: white;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .report-table td {
                padding: 14px 16px; border-bottom: 1px solid var(--border);
            }
            .report-table tr:hover td { 
                background: rgba(35, 76, 106, 0.02);
            }
            .report-table tr:nth-child(even) td {
                background: rgba(210, 193, 182, 0.05);
            }

            .badge {
                display: inline-block; padding: 5px 12px; border-radius: 20px;
                font-size: 12px; font-weight: 600;
                letter-spacing: 0.3px;
            }
            .badge-success { background: rgba(5, 150, 105, 0.1); color: #065F46; border: 1px solid rgba(5, 150, 105, 0.2); }
            .badge-warning { background: rgba(217, 119, 6, 0.1); color: #92400E; border: 1px solid rgba(217, 119, 6, 0.2); }
            .badge-danger { background: rgba(220, 38, 38, 0.08); color: #991B1B; border: 1px solid rgba(220, 38, 38, 0.2); }
            .badge-info { background: rgba(2, 132, 199, 0.08); color: #075985; border: 1px solid rgba(2, 132, 199, 0.2); }

            /* ===== SUMMARY CARDS ===== */
            .summary-grid {
                display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
                margin: 0 40px 30px;
            }
            .summary-card {
                padding: 24px; border-radius: var(--radius);
                border: 1px solid var(--border); background: var(--surface);
                box-shadow: var(--shadow-sm);
            }
            .summary-card h3 { 
                margin-bottom: 18px; 
                font-size: 17px; 
                display: flex; 
                align-items: center; 
                gap: 10px;
                font-weight: 700;
            }
            .list-item {
                padding: 10px 0; border-bottom: 1px solid var(--border);
                display: flex; align-items: baseline; gap: 10px;
                font-size: 14px;
            }
            .list-item:last-child { border-bottom: none; }

            /* ===== FOOTER ===== */
            .report-footer {
                background: linear-gradient(135deg, var(--primary-dark), var(--primary));
                color: var(--accent-warm-light);
                text-align: center; padding: 24px 40px;
                font-size: 13px; margin-top: 20px;
                letter-spacing: 0.3px;
            }

            /* ===== PRINT ===== */
            @media print {
                body { background: white; padding: 0; }
                .container { box-shadow: none; border: none; }
                .no-print { display: none !important; }
                .report-header { background: #1B3C53 !important; -webkit-print-color-adjust: exact; }
                .report-table th { background: #1B3C53 !important; -webkit-print-color-adjust: exact; }
                .student-card, .score-overview, .section { break-inside: avoid; }
            }

            /* ===== RESPONSIVE ===== */
            @media (max-width: 768px) {
                .kpi-grid { grid-template-columns: 1fr 1fr; padding: 20px; }
                .student-card { padding: 15px 20px; }
                .controls-bar { flex-direction: column; align-items: flex-start; }
                .score-overview { flex-direction: column; }
                .summary-grid { grid-template-columns: 1fr; }
                .report-header h1 { font-size: 20px; }
                .overall-number { font-size: 42px; }
            }
        </style>
    </head>
    <body>
        <div class="container">';

    // Controls (web only)
    if (!$is_pdf) {
        $html .= '
            <div class="controls-bar no-print animate-in" style="animation-delay: 0.2s;">
                <a href="?student_id=' . $data['student']['student_id'] . '&month=' . date('Y-m', strtotime($data['month'] . ' -1 month')) . '" class="btn btn-outline">← Previous</a>
                <a href="?student_id=' . $data['student']['student_id'] . '&month=' . date('Y-m', strtotime($data['month'] . ' +1 month')) . '" class="btn btn-outline">Next →</a>
                <button onclick="window.print()" class="btn btn-neutral">🖨 Print</button>
                <a href="?student_id=' . $data['student']['student_id'] . '&month=' . $data['month'] . '&download=pdf" class="btn btn-primary">📥 Download PDF</a>
                <a href="../student_list.php" class="btn btn-neutral">✕ Close</a>
                <form method="GET" style="margin-left: auto; display: flex; gap: 8px; align-items: center;">
                    <input type="hidden" name="student_id" value="' . $data['student']['student_id'] . '">
                    <input type="month" name="month" value="' . $data['month'] . '" max="' . date('Y-m') . '" class="month-picker">
                    <button type="submit" class="btn btn-primary">View</button>
                </form>
            </div>';
    }

    // Report header
    $html .= '
            <div class="report-header animate-in" style="animation-delay: 0.1s;">
                <h1>📊 Monthly Performance Report Card</h1>
                <div class="subtitle">ASD Academy · Performance Tracking System</div>
                <div class="month-range">' . $month_name . ' (' . $month_start . ' - ' . $month_end . ')</div>
            </div>

            <!-- Student Info -->
            <div class="student-card animate-in delay-2">
                <div class="student-avatar">' . strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) . '</div>
                <div class="student-details">
                    <h2>' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</h2>
                    <div class="meta">
                        <span>🆔 ' . htmlspecialchars($student['student_id']) . '</span>
                        <span>📘 ' . htmlspecialchars($student['course'] ?? 'N/A') . '</span>
                        <span>📧 ' . htmlspecialchars($student['email'] ?? 'N/A') . '</span>
                        <span>📞 ' . htmlspecialchars($student['phone_number'] ?? 'N/A') . '</span>
                        <span>📌 ' . htmlspecialchars(ucfirst(str_replace('_', ' ', $student['current_status']))) . '</span>
                    </div>
                </div>
            </div>';

    // KPI Grid
    $html .= '
            <div class="kpi-grid">';

    // Attendance KPI
    $att = $data['attendance_summary'];
    $att_score = $data['overall_scores']['attendance'];
    $att_class = $scoreColorClass($att_score);
    $html .= '
                <div class="kpi-card animate-in delay-3">
                    <div class="kpi-icon">📅</div>
                    <div class="kpi-value ' . $att_class . '">' . $att['attendance_rate'] . '%</div>
                    <div class="kpi-label">Attendance</div>
                    <div class="kpi-sub">' . $att['present_count'] . '/' . $att['total_classes'] . ' days present</div>
                    <div class="progress-bar-bg"><div class="progress-fill" style="width:' . $att['attendance_rate'] . '%"></div></div>
                </div>';

    // Feedback KPI
    $fb = $data['feedback_summary'];
    $fb_score = $data['overall_scores']['feedback'];
    $fb_class = $scoreColorClass($fb_score);
    $html .= '
                <div class="kpi-card animate-in delay-3">
                    <div class="kpi-icon">💬</div>
                    <div class="kpi-value ' . $fb_class . '">' . number_format($fb['average_rating'], 1) . '</div>
                    <div class="kpi-label">Trainer Feedback</div>
                    <div class="kpi-sub">' . $fb['total_feedback'] . ' feedback entries</div>
                    <div class="progress-bar-bg"><div class="progress-fill" style="width:' . ($fb_score * 20) . '%"></div></div>
                </div>';

    // Assignments KPI
    $asgn = $data['assignment_summary'];
    $asgn_score = $data['overall_scores']['assignments'];
    $asgn_class = $scoreColorClass($asgn_score);
    $html .= '
                <div class="kpi-card animate-in delay-3">
                    <div class="kpi-icon">📝</div>
                    <div class="kpi-value ' . $asgn_class . '">' . $asgn['avg_percentage'] . '%</div>
                    <div class="kpi-label">Assignments</div>
                    <div class="kpi-sub">' . $asgn['graded_count'] . ' graded</div>
                    <div class="progress-bar-bg"><div class="progress-fill" style="width:' . ($asgn['avg_percentage']) . '%"></div></div>
                </div>';

    // Tests KPI
    $tests = $data['test_summary'];
    $test_score = $data['overall_scores']['tests'];
    $test_class = $scoreColorClass($test_score);
    $html .= '
                <div class="kpi-card animate-in delay-3">
                    <div class="kpi-icon">🧪</div>
                    <div class="kpi-value ' . $test_class . '">' . $tests['avg_percentage'] . '%</div>
                    <div class="kpi-label">MCQ Tests</div>
                    <div class="kpi-sub">' . $tests['attempted_count'] . ' attempts</div>
                    <div class="progress-bar-bg"><div class="progress-fill" style="width:' . $tests['avg_percentage'] . '%"></div></div>
                </div>';

    $html .= '
            </div>

            <!-- Overall Score & Component Scores -->
            <div class="score-overview animate-in delay-4">
                <div class="overall-card">
                    <div class="overall-label">Overall Monthly Score</div>
                    <div class="overall-number">' . $overall_score . '<span class="overall-max">/5</span></div>';
    if (isset($data['comparison'])) {
        $trend = $data['comparison']['trend'];
        $trend_class = ($trend == 'up') ? 'trend-up' : (($trend == 'down') ? 'trend-down' : 'trend-stable');
        $sign = ($trend == 'up') ? '+' : (($trend == 'down') ? '-' : '');
        $html .= '
                    <span class="trend-badge ' . $trend_class . '">' . ($trend == 'up' ? '↗' : ($trend == 'down' ? '↘' : '→')) . ' ' . $sign . abs($data['comparison']['change']) . ' from previous</span>';
    }
    $html .= '
                </div>';
    // Individual component scores
    foreach(['attendance'=>'📅 Attendance','feedback'=>'💬 Feedback','assignments'=>'📝 Assignments','tests'=>'🧪 Tests'] as $key=>$label) {
        $score = $data['overall_scores'][$key] ?? 0;
        $cls = $scoreColorClass($score);
        $html .= '
                <div class="kpi-card" style="flex:1; min-width:130px;">
                    <div class="kpi-label">' . $label . '</div>
                    <div class="kpi-value ' . $cls . '" style="font-size:24px;">' . $score . '<small style="font-size:14px;">/5</small></div>
                </div>';
    }
    $html .= '
            </div>';

    // Weekly breakdown
    if (!empty($data['weekly_breakdown'])) {
        $html .= '
            <div class="section animate-in delay-5">
                <div class="section-header">📆 Weekly Performance Overview</div>
                <div class="section-body">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Attendance</th>
                                <th>Feedback</th>
                                <th>Assignments</th>
                                <th>Tests</th>
                                <th>Overall</th>
                            </tr>
                        </thead>
                        <tbody>';
        foreach ($data['weekly_breakdown'] as $week) {
            $w_att = $week['attendance_score'];
            $w_fb = $week['feedback_score'];
            $w_asgn = $week['assignment_score'];
            $w_test = $week['test_score'];
            $w_ov = $week['overall_score'];
            $html .= '
                            <tr>
                                <td><strong>Week ' . $week['week_num'] . '</strong><br><small style="color: var(--text-light);">' . $week['start'] . ' - ' . $week['end'] . '</small></td>
                                <td><span class="badge ' . ($w_att>=4 ? 'badge-success' : ($w_att>=3 ? 'badge-warning' : 'badge-danger')) . '">' . $w_att . '/5</span></td>
                                <td><span class="badge ' . ($w_fb>=4 ? 'badge-success' : ($w_fb>=3 ? 'badge-warning' : 'badge-danger')) . '">' . $w_fb . '/5</span></td>
                                <td><span class="badge ' . ($w_asgn>=4 ? 'badge-success' : ($w_asgn>=3 ? 'badge-warning' : 'badge-danger')) . '">' . $w_asgn . '/5</span></td>
                                <td><span class="badge ' . ($w_test>=4 ? 'badge-success' : ($w_test>=3 ? 'badge-warning' : 'badge-danger')) . '">' . $w_test . '/5</span></td>
                                <td><strong class="kpi-value ' . ($w_ov>=4 ? 'excellent' : ($w_ov>=3 ? 'good' : 'poor')) . '">' . $w_ov . '/5</strong></td>
                            </tr>';
        }
        $html .= '
                        </tbody>
                    </table>
                </div>
            </div>';
    } else {
        $html .= '
            <div class="section animate-in delay-5">
                <div class="section-header">📆 Weekly Breakdown</div>
                <div class="section-body" style="text-align:center; padding:40px; color: var(--text-light);">
                    <div style="font-size: 40px; margin-bottom: 10px;">📊</div>
                    <p>No weekly data available for this month.</p>
                </div>
            </div>';
    }

    // Strengths & Improvements
    $html .= '
            <div class="summary-grid animate-in delay-5">
                <div class="summary-card" style="border-left: 4px solid #059669;">
                    <h3 style="color: #059669;">✓ Strengths</h3>';
    if (!empty($data['strengths'])) {
        foreach ($data['strengths'] as $s) {
            $html .= '<div class="list-item">✅ ' . htmlspecialchars($s) . '</div>';
        }
    } else {
        $html .= '<div class="list-item" style="color: var(--text-light);">No specific strengths identified.</div>';
    }
    $html .= '
                </div>
                <div class="summary-card" style="border-left: 4px solid #DC2626;">
                    <h3 style="color: #DC2626;">⚠ Areas for Improvement</h3>';
    if (!empty($data['improvements'])) {
        foreach ($data['improvements'] as $i) {
            $html .= '<div class="list-item">🔹 ' . htmlspecialchars($i) . '</div>';
        }
    } else {
        $html .= '<div class="list-item" style="color: var(--text-light);">No major improvement areas.</div>';
    }
    $html .= '
                </div>
            </div>';

    // Footer
    $html .= '
            <div class="report-footer">
                <p><strong>ASD Academy</strong> · Performance Tracking System</p>
                <p>Generated on ' . date('F j, Y, h:i A') . ' · info@asdacademy.in · 9680100687</p>
            </div>
        </div>';

    $html .= '
    </body>
    </html>';
    
    return $html;
}

// Output the web page
echo generatePDFContent($report_data, false);
?>