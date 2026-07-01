<?php
// weekly_report_card.php
require_once 'db_connection.php';
session_start();

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Get student ID and week parameters
$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
$week_start = isset($_GET['week_start']) ? $_GET['week_start'] : date('Y-m-d', strtotime('monday this week'));
$week_end = isset($_GET['week_end']) ? $_GET['week_end'] : date('Y-m-d', strtotime('sunday this week'));

// Redirect if student ID is missing
if (!$student_id) {
    header("Location: ../student_list.php");
    exit();
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch student details
    $stmt = $conn->prepare("
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
    if (!empty($batch_ids)) {
        $placeholders = str_repeat('?,', count($batch_ids) - 1) . '?';
        $stmt = $conn->prepare("SELECT * FROM batches WHERE batch_id IN ($placeholders)");
        $stmt->execute($batch_ids);
        $all_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $batch_details = [];
        foreach ($all_batches as $batch) {
            $batch_details[$batch['batch_id']] = $batch;
        }
    }
    
    // Initialize report data
    $report_data = [
        'student' => $student,
        'week_start' => $week_start,
        'week_end' => $week_end,
        'attendance' => [],
        'weekly_feedback' => [],
        'assignments' => [],
        'mcq_tests' => [],  // NEW: Added for MCQ tests
        'overall_scores' => []
    ];
    
    // 1. FETCH ATTENDANCE DATA FOR THE WEEK
    $attendance_by_batch = [];
    $total_classes = 0;
    $present_count = 0;
    
    foreach ($batch_ids as $batch_id) {
        $stmt = $conn->prepare("
            SELECT * FROM attendance 
            WHERE student_name = ? 
            AND batch_id = ?
            AND date BETWEEN ? AND ?
            ORDER BY date ASC
        ");
        $stmt->execute([
            $student['first_name'] . ' ' . $student['last_name'],
            $batch_id,
            $week_start,
            $week_end
        ]);
        $batch_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $batch_classes = count($batch_attendance);
        $batch_present = 0;
        
        foreach ($batch_attendance as $record) {
            if ($record['status'] === 'Present') $batch_present++;
            
            $record['batch_name'] = $batch_details[$batch_id]['batch_name'] ?? $batch_id;
            $report_data['attendance'][] = $record;
        }
        
        if ($batch_classes > 0) {
            $attendance_rate = ($batch_present / $batch_classes) * 100;
            $attendance_by_batch[$batch_id] = [
                'batch_name' => $batch_details[$batch_id]['batch_name'] ?? $batch_id,
                'total_classes' => $batch_classes,
                'present_count' => $batch_present,
                'attendance_rate' => round($attendance_rate),
                'attendance_score' => round(($attendance_rate / 100) * 5, 1)
            ];
            
            $total_classes += $batch_classes;
            $present_count += $batch_present;
        }
    }
    
    // Calculate overall attendance score
    $overall_attendance_rate = $total_classes > 0 ? ($present_count / $total_classes) * 100 : 0;
    $overall_attendance_score = round(($overall_attendance_rate / 100) * 5, 1);
    $report_data['overall_scores']['attendance'] = $overall_attendance_score;
    
    // 2. FETCH WEEKLY FEEDBACK FOR THE WEEK
    $stmt = $conn->prepare("
        SELECT wf.*, t.name as trainer_name, b.batch_name
        FROM weekly_feedback wf
        LEFT JOIN trainers t ON wf.trainer_id = t.id
        LEFT JOIN batches b ON wf.batch_id = b.batch_id
        WHERE wf.student_id = ?
        AND wf.week_start_date = ?
        AND wf.week_end_date = ?
        ORDER BY wf.submitted_at DESC
    ");
    $stmt->execute([$student_id, $week_start, $week_end]);
    $weekly_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_feedback_rating = 0;
    $feedback_count = 0;
    
    foreach ($weekly_feedback as $feedback) {
        $report_data['weekly_feedback'][] = $feedback;
        $total_feedback_rating += $feedback['rating'];
        $feedback_count++;
    }
    
    // Calculate average feedback score
    $average_feedback_score = $feedback_count > 0 ? round($total_feedback_rating / $feedback_count, 1) : 0;
    $report_data['overall_scores']['feedback'] = $average_feedback_score;
    
    // 3. FETCH ASSIGNMENT MARKS FOR THE WEEK
    $stmt = $conn->prepare("
        SELECT au.*, a.title, a.max_marks, a.due_date, b.batch_name
        FROM assignment_submissions au
        LEFT JOIN uploads a ON au.upload_id = a.id
        LEFT JOIN batch_uploads bu ON a.id = bu.upload_id
        LEFT JOIN batches b ON bu.batch_id = b.batch_id
        WHERE au.student_id = ?
        AND DATE(au.submitted_at) BETWEEN ? AND ?
        AND au.status = 'graded'
        ORDER BY au.submitted_at DESC
    ");
    $stmt->execute([$student_id, $week_start, $week_end]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_assignment_score = 0;
    $assignment_count = 0;
    
    foreach ($assignments as $assignment) {
        if ($assignment['grade'] > 0 && $assignment['max_marks'] > 0) {
            $score_5_point = ($assignment['grade'] / $assignment['max_marks']) * 5;
            $assignment['score_5_point'] = round($score_5_point, 1);
            $assignment['percentage'] = round(($assignment['grade'] / $assignment['max_marks']) * 100, 1);
            
            $report_data['assignments'][] = $assignment;
            $total_assignment_score += $score_5_point;
            $assignment_count++;
        }
    }
    
    // Calculate average assignment score
    $average_assignment_score = $assignment_count > 0 ? round($total_assignment_score / $assignment_count, 1) : 0;
    $report_data['overall_scores']['assignments'] = $average_assignment_score;
    
    // 4. FETCH MCQ TEST RESULTS FOR THE WEEK
    $stmt = $conn->prepare("
        SELECT 
            ta.*,
            t.title as test_name,
            t.total_marks,
            t.subject,
            t.batch_id,
            b.batch_name,
            (ta.obtained_marks / t.total_marks) * 100 as percentage,
            ta.submitted_at
        FROM test_attempts ta
        JOIN tests t ON ta.test_id = t.id
        LEFT JOIN batches b ON t.batch_id = b.batch_id
        WHERE ta.student_id = ?
        AND ta.status = 'submitted'
        AND DATE(ta.submitted_at) BETWEEN ? AND ?
        ORDER BY ta.submitted_at DESC
    ");
    $stmt->execute([$student_id, $week_start, $week_end]);
    $mcq_tests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_test_score = 0;
    $test_count = 0;
    
    foreach ($mcq_tests as $test) {
        if ($test['total_marks'] > 0 && $test['obtained_marks'] !== null) {
            $score_5_point = ($test['obtained_marks'] / $test['total_marks']) * 5;
            $test['score_5_point'] = round($score_5_point, 1);
            $test['percentage'] = round($test['percentage'], 1);
            
            $report_data['mcq_tests'][] = $test;
            $total_test_score += $score_5_point;
            $test_count++;
        }
    }
    
    // Calculate average test score
    $average_test_score = $test_count > 0 ? round($total_test_score / $test_count, 1) : 0;
    $report_data['overall_scores']['tests'] = $average_test_score;
    
    // 5. CALCULATE OVERALL WEEKLY SCORE
    // NEW: Include tests in overall calculation with appropriate weight
    $components = ['attendance', 'feedback', 'assignments', 'tests'];
    $weights = [
        'attendance' => 0.2,   // Reduced from 0.3
        'feedback' => 0.3,     // Reduced from 0.4
        'assignments' => 0.25,  // Reduced from 0.3
        'tests' => 0.25        // NEW: Added weight for tests
    ];
    
    $weighted_sum = 0;
    $total_weight = 0;
    
    foreach ($components as $component) {
        if (isset($report_data['overall_scores'][$component]) && $report_data['overall_scores'][$component] > 0) {
            $weighted_sum += $report_data['overall_scores'][$component] * $weights[$component];
            $total_weight += $weights[$component];
        }
    }
    
    $overall_weekly_score = $total_weight > 0 ? round($weighted_sum / $total_weight, 1) : 0;
    $report_data['overall_scores']['weekly'] = $overall_weekly_score;
    
    // 6. GET PREVIOUS WEEK'S SCORE FOR COMPARISON
    $prev_week_start = date('Y-m-d', strtotime($week_start . ' -7 days'));
    $prev_week_end = date('Y-m-d', strtotime($week_end . ' -7 days'));
    
    $prev_weekly_score = 0;
    $stmt = $conn->prepare("
        SELECT AVG(rating) as avg_rating 
        FROM weekly_feedback 
        WHERE student_id = ? 
        AND week_start_date = ? 
        AND week_end_date = ?
    ");
    $stmt->execute([$student_id, $prev_week_start, $prev_week_end]);
    $prev_feedback = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($prev_feedback && $prev_feedback['avg_rating']) {
        $prev_weekly_score = round($prev_feedback['avg_rating'], 1);
    }
    
    $report_data['comparison'] = [
        'previous_score' => $prev_weekly_score,
        'change' => $overall_weekly_score - $prev_weekly_score,
        'trend' => $overall_weekly_score > $prev_weekly_score ? 'up' : ($overall_weekly_score < $prev_weekly_score ? 'down' : 'stable')
    ];
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle PDF generation
if (isset($_GET['download']) && $_GET['download'] == 'pdf') {
    // Generate PDF content using the same function for both web and PDF
    $html = generatePDFContent($report_data, true);
    
    // Generate PDF using html2pdf
    require_once('../vendor/autoload.php');
    
    $filename = "Weekly_Report_{$student['first_name']}_{$student['last_name']}_{$week_start}_to_{$week_end}.pdf";
    
    $html2pdf = new \Spipu\Html2Pdf\Html2Pdf('P', 'A4', 'en', true, 'UTF-8', [10, 10, 10, 10]);
    $html2pdf->setDefaultFont('Arial');
    $html2pdf->writeHTML($html);
    $html2pdf->output($filename, 'D');
    exit;
}

// Function to generate content for both web and PDF
function generatePDFContent($data, $is_pdf = false) {
    $student = $data['student'];
    $week_start = date('F j, Y', strtotime($data['week_start']));
    $week_end = date('F j, Y', strtotime($data['week_end']));
    $overall_score = $data['overall_scores']['weekly'] ?? 0;
    
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Weekly Report Card - ' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: Arial, sans-serif; color: #000; font-size: 10pt; line-height: 1.4; }
            
            .container { max-width: 1000px; margin: 0 auto; padding: 15px; }
            
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 15px; }
            .header h1 { color: #2c3e50; margin: 0 0 5px 0; font-size: ' . ($is_pdf ? '18pt' : '20pt') . '; }
            .header h2 { color: #7f8c8d; margin: 0; font-size: ' . ($is_pdf ? '12pt' : '14pt') . '; font-weight: normal; }
            
            .student-info { margin: 15px 0; padding: 10px; background: #f8f9fa; border-radius: 5px; border: 1px solid #ddd; }
            .student-info h3 { margin-bottom: 10px; font-size: 11pt; color: #2c3e50; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
            .info-row { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 8px; }
            .info-item { flex: 1; min-width: 200px; }
            .info-label { font-weight: bold; color: #555; display: inline-block; width: 120px; }
            
            .score-summary { display: flex; justify-content: space-between; margin: 20px 0; border: 1px solid #ddd; border-radius: 5px; padding: 15px; background: #f9f9f9; }
            .score-item { text-align: center; flex: 1; padding: 5px; }
            .score-value { font-size: 18pt; font-weight: bold; margin: 5px 0; }
            .score-label { font-size: 9pt; color: #7f8c8d; font-weight: bold; }
            
            .overall-score { text-align: center; margin: 25px 0; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 2px solid #3498db; }
            .overall-score .label { font-size: 11pt; color: #555; margin-bottom: 5px; font-weight: bold; }
            .overall-score .value { font-size: 28pt; font-weight: bold; color: #2c3e50; }
            .trend { margin-top: 8px; font-size: 9pt; font-weight: bold; }
            
            .section { margin: 20px 0; page-break-inside: avoid; border: 1px solid #ddd; border-radius: 5px; overflow: hidden; }
            .section-title { background-color: #e9ecef; padding: 8px 12px; border-bottom: 1px solid #ddd; font-weight: bold; font-size: 11pt; color: #2c3e50; }
            .section-content { padding: 15px; }
            
            table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9pt; }
            th { background-color: #f2f2f2; padding: 8px 6px; text-align: left; font-weight: bold; border: 1px solid #ddd; }
            td { padding: 6px; border: 1px solid #ddd; }
            
            .status-badge { padding: 3px 8px; border-radius: 3px; font-size: 8pt; font-weight: bold; display: inline-block; }
            .status-present { background: #d4edda; color: #155724; }
            .status-absent { background: #f8d7da; color: #721c24; }
            .status-late { background: #fff3cd; color: #856404; }
            
            .stars { color: #f39c12; font-size: 9pt; }
            
            .score-color { font-weight: bold; }
            .score-excellent { color: #27ae60; }
            .score-good { color: #f39c12; }
            .score-poor { color: #e74c3c; }
            
            .summary-section { margin: 20px 0; }
            .summary-section h4 { font-size: 10pt; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid #ddd; }
            .summary-list { list-style: none; padding-left: 0; }
            .summary-list li { margin-bottom: 5px; padding-left: 15px; position: relative; }
            .summary-list li:before { content: "•"; position: absolute; left: 0; color: #666; }
            .strength-item { color: #27ae60; }
            .improvement-item { color: #e74c3c; }
            
            .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; color: #7f8c8d; font-size: 9pt; }
            
            .page-break { page-break-before: always; }
            
            .controls { margin-bottom: 20px; padding: 10px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 5px; }
            .control-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
            .btn { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 11pt; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
            .btn-prev { background: #6c757d; color: white; }
            .btn-next { background: #6c757d; color: white; }
            .btn-print { background: #007bff; color: white; }
            .btn-pdf { background: #28a745; color: white; }
            .btn-close { background: #dc3545; color: white; }
            
            @media print {
                .no-print { display: none !important; }
                .controls { display: none !important; }
            }
            
            ' . (!$is_pdf ? '
            /* Web-only styles */
            .container { max-width: 1200px; }
            body { background: #f5f5f5; padding: 20px; }
            .section:hover { box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
            .btn:hover { opacity: 0.9; transform: translateY(-1px); }
            ' : '') . '
        </style>
    </head>
    <body>
        <div class="container">
            <!-- Controls (only for web) -->
            ' . (!$is_pdf ? '
            <div class="controls no-print">
                <div class="control-buttons">
                    <a href="?student_id=' . $data['student']['student_id'] . '&week_start=' . date('Y-m-d', strtotime($data['week_start'] . ' -7 days')) . '&week_end=' . date('Y-m-d', strtotime($data['week_end'] . ' -7 days')) . '" class="btn btn-prev">
                        ← Previous Week
                    </a>
                    <a href="?student_id=' . $data['student']['student_id'] . '&week_start=' . date('Y-m-d', strtotime($data['week_start'] . ' +7 days')) . '&week_end=' . date('Y-m-d', strtotime($data['week_end'] . ' +7 days')) . '" class="btn btn-next">
                        Next Week →
                    </a>
                    <button onclick="window.print()" class="btn btn-print">
                        🖨️ Print Report
                    </button>
                    <a href="?student_id=' . $data['student']['student_id'] . '&week_start=' . $data['week_start'] . '&week_end=' . $data['week_end'] . '&download=pdf" class="btn btn-pdf">
                        📥 Download PDF
                    </a>
                    <a href="../student_list.php" class="btn btn-close">
                        ✕ Close
                    </a>
                </div>
            </div>
            ' : '') . '
            
            <!-- Header -->
            <div class="header">
                <h1>ASD Academy - Weekly Performance Report Card</h1>
                <h2>' . $week_start . ' - ' . $week_end . '</h2>
            </div>
            
            <!-- Student Information -->
            <div class="student-info">
                <h3>Student Information</h3>
                <div class="info-row">
                    <div class="info-item">
                        <span class="info-label">Name:</span> ' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '
                    </div>
                    <div class="info-item">
                        <span class="info-label">Student ID:</span> ' . htmlspecialchars($student['student_id']) . '
                    </div>
                    <div class="info-item">
                        <span class="info-label">Course:</span> ' . htmlspecialchars($student['course'] ?? 'N/A') . '
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-item">
                        <span class="info-label">Email:</span> ' . htmlspecialchars($student['email'] ?? 'N/A') . '
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone:</span> ' . htmlspecialchars($student['phone_number'] ?? 'N/A') . '
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status:</span> ' . htmlspecialchars(ucfirst(str_replace('_', ' ', $student['current_status']))) . '
                    </div>
                </div>
            </div>
            
            <!-- Component Scores -->
            <div class="score-summary">';
    
    foreach ($data['overall_scores'] as $component => $score) {
        if ($component != 'weekly') {
            $label = ucfirst($component);
            $color_class = $score >= 4 ? 'score-excellent' : ($score >= 3 ? 'score-good' : 'score-poor');
            $html .= '
                <div class="score-item">
                    <div class="score-label">' . $label . '</div>
                    <div class="score-value score-color ' . $color_class . '">' . $score . '/5</div>
                </div>';
        }
    }
    
    $html .= '
            </div>
            
            <!-- Overall Score -->
            <div class="overall-score">
                <div class="label">Overall Weekly Performance Score</div>
                <div class="value">' . $data['overall_scores']['weekly'] . '/5</div>';
    
    if (isset($data['comparison'])) {
        $trend_icon = $data['comparison']['trend'] == 'up' ? '↗' : ($data['comparison']['trend'] == 'down' ? '↘' : '→');
        $trend_color = $data['comparison']['trend'] == 'up' ? '#27ae60' : ($data['comparison']['trend'] == 'down' ? '#e74c3c' : '#7f8c8d');
        $trend_text = $data['comparison']['trend'] == 'up' ? 'Improved' : ($data['comparison']['trend'] == 'down' ? 'Declined' : 'No change');
        $html .= '
                <div class="trend" style="color: ' . $trend_color . '">
                    ' . $trend_icon . ' ' . $trend_text . ' by ' . abs($data['comparison']['change']) . ' from previous week (' . $data['comparison']['previous_score'] . '/5)
                </div>';
    }
    
    $html .= '
            </div>
            
            <!-- Attendance Section -->
            <div class="section">
                <div class="section-title">Attendance Details</div>
                <div class="section-content">';
    
    if (!empty($data['attendance'])) {
        $html .= '
                    <table>
                        <thead>
                            <tr>
                                <th width="20%">Date</th>
                                <th width="25%">Batch</th>
                                <th width="15%">Status</th>
                                <th width="15%">Camera</th>
                                <th width="25%">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        foreach ($data['attendance'] as $attendance) {
            $status_class = 'status-absent';
            if ($attendance['status'] === 'Present') $status_class = 'status-present';
            if ($attendance['status'] === 'Late') $status_class = 'status-late';
            
            $html .= '
                            <tr>
                                <td>' . date('D, M j', strtotime($attendance['date'])) . '</td>
                                <td>' . htmlspecialchars($attendance['batch_name']) . '</td>
                                <td><span class="status-badge ' . $status_class . '">' . $attendance['status'] . '</span></td>
                                <td>' . $attendance['camera_status'] . '</td>
                                <td>' . ($attendance['remarks'] ? htmlspecialchars($attendance['remarks']) : '-') . '</td>
                            </tr>';
        }
        
        $html .= '
                        </tbody>
                    </table>';
    } else {
        $html .= '<p style="text-align: center; color: #666; padding: 15px; font-style: italic;">No attendance records for this week</p>';
    }
    
    $html .= '
                </div>
            </div>
            
            <!-- Weekly Feedback Section -->
            <div class="section">
                <div class="section-title">Interactions</div>
                <div class="section-content">';
    
    if (!empty($data['weekly_feedback'])) {
        $html .= '
                    <table>
                        <thead>
                            <tr>
                                <th width="25%">Trainer</th>
                                <th width="20%">Batch</th>
                                <th width="20%">Rating</th>
                                <th width="20%">Date</th>
                                <th width="15%">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        foreach ($data['weekly_feedback'] as $feedback) {
            $stars = str_repeat('★', $feedback['rating']) . str_repeat('☆', 5 - $feedback['rating']);
            $html .= '
                            <tr>
                                <td>' . htmlspecialchars($feedback['trainer_name']) . '</td>
                                <td>' . htmlspecialchars($feedback['batch_name']) . '</td>
                                <td><span class="stars">' . $stars . '</span> (' . $feedback['rating'] . '/5)</td>
                                <td>' . date('M j, Y', strtotime($feedback['submitted_at'])) . '</td>
                                <td>' . ($feedback['remarks'] ? htmlspecialchars($feedback['remarks']) : '-') . '</td>
                            </tr>';
        }
        
        $html .= '
                        </tbody>
                    </table>';
    } else {
        $html .= '<p style="text-align: center; color: #666; padding: 15px; font-style: italic;">No weekly feedback for this week</p>';
    }
    
    $html .= '
                </div>
            </div>
            
            <!-- Assignments Section -->
            <div class="section">
                <div class="section-title">Assignments & Assessments</div>
                <div class="section-content">';
    
    if (!empty($data['assignments'])) {
        $html .= '
                    <table>
                        <thead>
                            <tr>
                                <th width="30%">Title</th>
                                <th width="20%">Batch</th>
                                <th width="20%">Score</th>
                                <th width="15%">5-Point</th>
                                <th width="15%">Due Date</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        foreach ($data['assignments'] as $assignment) {
            $score_color = $assignment['score_5_point'] >= 4 ? 'score-excellent' : 
                          ($assignment['score_5_point'] >= 3 ? 'score-good' : 'score-poor');
            
            $html .= '
                            <tr>
                                <td>' . htmlspecialchars($assignment['title']) . '</td>
                                <td>' . htmlspecialchars($assignment['batch_name']) . '</td>
                                <td>' . $assignment['grade'] . '/' . $assignment['max_marks'] . ' (' . $assignment['percentage'] . '%)</td>
                                <td class="score-color ' . $score_color . '"><strong>' . $assignment['score_5_point'] . '/5</strong></td>
                                <td>' . date('M j, Y', strtotime($assignment['due_date'])) . '</td>
                            </tr>';
        }
        
        $html .= '
                        </tbody>
                    </table>';
    } else {
        $html .= '<p style="text-align: center; color: #666; padding: 15px; font-style: italic;">No graded assignments for this week</p>';
    }
    
    $html .= '
                </div>
            </div>
            
            <!-- MCQ Tests Section (NEW) -->
            <div class="section">
                <div class="section-title">MCQ Tests & Assessments</div>
                <div class="section-content">';
    
    if (!empty($data['mcq_tests'])) {
        $html .= '
                    <table>
                        <thead>
                            <tr>
                                <th width="30%">Test Name</th>
                                <th width="20%">Subject</th>
                                <th width="15%">Batch</th>
                                <th width="15%">Score</th>
                                <th width="10%">5-Point</th>
                                <th width="10%">Date</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        foreach ($data['mcq_tests'] as $test) {
            $score_color = $test['score_5_point'] >= 4 ? 'score-excellent' : 
                          ($test['score_5_point'] >= 3 ? 'score-good' : 'score-poor');
            
            $html .= '
                            <tr>
                                <td>' . htmlspecialchars($test['test_name']) . '</td>
                                <td>' . htmlspecialchars($test['subject']) . '</td>
                                <td>' . htmlspecialchars($test['batch_name']) . '</td>
                                <td>' . $test['obtained_marks'] . '/' . $test['total_marks'] . ' (' . $test['percentage'] . '%)</td>
                                <td class="score-color ' . $score_color . '"><strong>' . $test['score_5_point'] . '/5</strong></td>
                                <td>' . date('M j, Y', strtotime($test['submitted_at'])) . '</td>
                            </tr>';
        }
        
        $html .= '
                        </tbody>
                    </table>';
    } else {
        $html .= '<p style="text-align: center; color: #666; padding: 15px; font-style: italic;">No MCQ tests completed this week</p>';
    }
    
    $html .= '
                </div>
            </div>
            
            <!-- Performance Summary -->
            <div class="summary-section">
                <h3>Performance Summary & Recommendations</h3>
                <div style="display: flex; gap: 20px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <h4>Strengths</h4>
                        <ul class="summary-list">';
    
    // Strengths
    if (($data['overall_scores']['attendance'] ?? 0) >= 4) {
        $html .= '<li class="strength-item">Excellent attendance and punctuality</li>';
    }
    if (($data['overall_scores']['feedback'] ?? 0) >= 4) {
        $html .= '<li class="strength-item">Positive feedback from trainers</li>';
    }
    if (($data['overall_scores']['assignments'] ?? 0) >= 4) {
        $html .= '<li class="strength-item">Strong performance on assignments</li>';
    }
    if (($data['overall_scores']['tests'] ?? 0) >= 4) {
        $html .= '<li class="strength-item">Excellent test scores</li>';
    }
    if (($data['overall_scores']['weekly'] ?? 0) >= 4) {
        $html .= '<li class="strength-item">Overall excellent weekly performance</li>';
    }
    if (empty($data['strengths'])) {
        $html .= '<li style="color: #666; font-style: italic;">No specific strengths identified this week</li>';
    }
    
    $html .= '
                        </ul>
                    </div>
                    <div style="flex: 1;">
                        <h4>Areas for Improvement</h4>
                        <ul class="summary-list">';
    
    // Areas for improvement
    if (($data['overall_scores']['attendance'] ?? 0) < 3) {
        $html .= '<li class="improvement-item">Improve attendance consistency</li>';
    }
    if (($data['overall_scores']['feedback'] ?? 0) < 3) {
        $html .= '<li class="improvement-item">Seek additional help from trainers</li>';
    }
    if (($data['overall_scores']['assignments'] ?? 0) < 3) {
        $html .= '<li class="improvement-item">Focus on assignment quality and timeliness</li>';
    }
    if (($data['overall_scores']['tests'] ?? 0) < 3) {
        $html .= '<li class="improvement-item">Improve test preparation and performance</li>';
    }
    if (count($data['attendance'] ?? []) == 0) {
        $html .= '<li class="improvement-item">No attendance recorded this week</li>';
    }
    if (count($data['weekly_feedback'] ?? []) == 0) {
        $html .= '<li class="improvement-item">No trainer feedback received</li>';
    }
    if (count($data['assignments'] ?? []) == 0) {
        $html .= '<li class="improvement-item">No assignments graded this week</li>';
    }
    if (count($data['mcq_tests'] ?? []) == 0) {
        $html .= '<li class="improvement-item">No MCQ tests attempted this week</li>';
    }
    
    $html .= '
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <p><strong>ASD Academy - Performance Tracking System</strong></p>
                <p>Report generated on ' . date('F j, Y, h:i A') . '</p>
                <p>Contact: info@asdacademy.in | Phone: 9680100687</p>
            </div>
        </div>
        
        ' . (!$is_pdf ? '
        <script>
            // Simple script for web interactions
            document.addEventListener("DOMContentLoaded", function() {
                // Print button functionality
                document.querySelector(".btn-print").addEventListener("click", function(e) {
                    e.preventDefault();
                    window.print();
                });
                
                // Auto-hide messages after 5 seconds
                setTimeout(() => {
                    const messages = document.querySelectorAll("[id$=\"Message\"]");
                    messages.forEach(msg => {
                        if (msg) {
                            msg.style.opacity = "0";
                            setTimeout(() => msg.remove(), 500);
                        }
                    });
                }, 5000);
            });
        </script>
        ' : '') . '
    </body>
    </html>';
    
    return $html;
}

// If not downloading PDF, display the web page
echo generatePDFContent($report_data, false);
?>