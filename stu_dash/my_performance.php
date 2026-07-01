<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_query = $db->prepare("SELECT * FROM students WHERE user_id = :user_id");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get all batches (current + historical from student_batch_history)
$all_batches = [];
$batch_ids_list = [];
$student_id_value = $student['student_id'];
$student_name = $student['first_name'] . ' ' . $student['last_name'];

// 1. Get current batches (batch_name, batch_name_2, batch_name_3, batch_name_4)
$batch_names = ['batch_name', 'batch_name_2', 'batch_name_3', 'batch_name_4'];

foreach ($batch_names as $batch_field) {
    if (!empty($student[$batch_field])) {
        $batch_query = $db->prepare("
            SELECT * 
            FROM batches 
            WHERE batch_id = :batch_id
        ");
        $batch_query->execute([':batch_id' => $student[$batch_field]]);
        $batch_data = $batch_query->fetch(PDO::FETCH_ASSOC);
        
        if ($batch_data) {
            // Check if this batch is already added
            $batch_exists = false;
            foreach ($all_batches as $existing_batch) {
                if ($existing_batch['batch_id'] == $batch_data['batch_id']) {
                    $batch_exists = true;
                    break;
                }
            }
            
            if (!$batch_exists) {
                $batch_label = "Batch ";
                if ($batch_field == 'batch_name') $batch_label .= "1 (Current)";
                elseif ($batch_field == 'batch_name_2') $batch_label .= "2 (Current)";
                elseif ($batch_field == 'batch_name_3') $batch_label .= "3 (Current)";
                elseif ($batch_field == 'batch_name_4') $batch_label .= "4 (Current)";
                
                $all_batches[] = [
                    'batch_id' => $batch_data['batch_id'],
                    'batch_data' => $batch_data,
                    'batch_label' => $batch_label,
                    'batch_type' => 'current',
                    'field_name' => $batch_field
                ];
                
                if (!in_array($batch_data['batch_id'], $batch_ids_list)) {
                    $batch_ids_list[] = $batch_data['batch_id'];
                }
            }
        }
    }
}

// 2. Get historical batches from student_batch_history
$history_query = $db->prepare("
    SELECT DISTINCT from_batch_id, to_batch_id, transfer_date
    FROM student_batch_history 
    WHERE student_id = :student_id
    ORDER BY transfer_date DESC
");
$history_query->execute([':student_id' => $student_id_value]);
$history_batches = $history_query->fetchAll(PDO::FETCH_ASSOC);

foreach ($history_batches as $history) {
    // Add from_batch_id
    if (!empty($history['from_batch_id']) && !in_array($history['from_batch_id'], $batch_ids_list)) {
        $batch_query = $db->prepare("SELECT * FROM batches WHERE batch_id = :batch_id");
        $batch_query->execute([':batch_id' => $history['from_batch_id']]);
        $batch_data = $batch_query->fetch(PDO::FETCH_ASSOC);
        
        if ($batch_data) {
            $all_batches[] = [
                'batch_id' => $batch_data['batch_id'],
                'batch_data' => $batch_data,
                'batch_label' => "Previous Batch",
                'batch_type' => 'history',
                'transfer_date' => $history['transfer_date']
            ];
            $batch_ids_list[] = $batch_data['batch_id'];
        }
    }
    
    // Add to_batch_id if it exists and not already in list
    if (!empty($history['to_batch_id']) && !in_array($history['to_batch_id'], $batch_ids_list)) {
        $batch_query = $db->prepare("SELECT * FROM batches WHERE batch_id = :batch_id");
        $batch_query->execute([':batch_id' => $history['to_batch_id']]);
        $batch_data = $batch_query->fetch(PDO::FETCH_ASSOC);
        
        if ($batch_data) {
            // Check if this is actually a current batch
            $is_current = false;
            foreach ($batch_names as $batch_field) {
                if (!empty($student[$batch_field]) && $student[$batch_field] == $batch_data['batch_id']) {
                    $is_current = true;
                    break;
                }
            }
            
            if (!$is_current) {
                $all_batches[] = [
                    'batch_id' => $batch_data['batch_id'],
                    'batch_data' => $batch_data,
                    'batch_label' => "Previous Batch",
                    'batch_type' => 'history',
                    'transfer_date' => $history['transfer_date']
                ];
                $batch_ids_list[] = $batch_data['batch_id'];
            }
        }
    }
}

// Get selected batch from URL or default to first
$selected_batch_index = isset($_GET['batch_index']) ? intval($_GET['batch_index']) : 0;
if ($selected_batch_index >= count($all_batches)) {
    $selected_batch_index = 0;
}

$selected_batch = null;
$selected_batch_id = null;

if (!empty($all_batches) && isset($all_batches[$selected_batch_index])) {
    $selected_batch = $all_batches[$selected_batch_index]['batch_data'];
    $selected_batch_id = $selected_batch['batch_id'];
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$report_view = $_GET['view'] ?? 'overview';

// Initialize performance data arrays
$attendance_records = [];
$exam_results = [];
$exam_rankings = []; // New array to store ranking data per exam
$feedback_data = [];
$batch_history_data = [];

// Get attendance data for selected batch
$total_classes = 0;
$present_count = 0;
$absent_count = 0;
$camera_on_count = 0;
$monthly_attendance = [];
$daily_attendance_pattern = [];

if ($selected_batch_id) {
    // Get attendance data
    $attendance_query = $db->prepare("
        SELECT 
            date,
            status,
            camera_status,
            remarks,
            DAYNAME(date) as day_name,
            MONTH(date) as month,
            YEAR(date) as year,
            batch_id
        FROM course_attendance 
        WHERE (student_id = :student_id OR student_name = :student_name)
        AND batch_id = :batch_id
        AND date BETWEEN :start_date AND :end_date
        ORDER BY date DESC
    ");
    $attendance_query->execute([
        ':student_id' => $student['student_id'],
        ':student_name' => $student_name,
        ':batch_id' => $selected_batch_id,
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $attendance_records = $attendance_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate attendance statistics
    $total_classes = count($attendance_records);
    
    foreach ($attendance_records as $record) {
        if ($record['status'] === 'Present') {
            $present_count++;
        } else {
            $absent_count++;
        }
        
        if ($record['camera_status'] === 'On') {
            $camera_on_count++;
        }
        
        // Monthly attendance
        $month_year = date('M Y', strtotime($record['date']));
        if (!isset($monthly_attendance[$month_year])) {
            $monthly_attendance[$month_year] = ['present' => 0, 'total' => 0];
        }
        $monthly_attendance[$month_year]['total']++;
        if ($record['status'] === 'Present') {
            $monthly_attendance[$month_year]['present']++;
        }
        
        // Daily pattern
        $day = $record['day_name'];
        if (!isset($daily_attendance_pattern[$day])) {
            $daily_attendance_pattern[$day] = ['present' => 0, 'total' => 0];
        }
        $daily_attendance_pattern[$day]['total']++;
        if ($record['status'] === 'Present') {
            $daily_attendance_pattern[$day]['present']++;
        }
    }
}

$attendance_percentage = $total_classes > 0 ? ($present_count / $total_classes) * 100 : 0;
$camera_usage_percentage = $total_classes > 0 ? ($camera_on_count / $total_classes) * 100 : 0;

// Get exam performance data for selected batch
$total_exams = 0;
$passed_exams = 0;
$total_marks_obtained = 0;
$total_possible_marks = 0;
$subject_performance = [];
$exam_type_performance = [];
$component_performance = [
    'mcq' => ['total' => 0, 'obtained' => 0, 'count' => 0],
    'project' => ['total' => 0, 'obtained' => 0, 'count' => 0],
    'viva' => ['total' => 0, 'obtained' => 0, 'count' => 0]
];

if ($selected_batch_id) {
    // Get exam results with component details
    $exam_query = $db->prepare("
        SELECT 
            e.exam_id,
            e.exam_name,
            e.exam_date,
            e.total_marks,
            e.passing_marks,
            e.exam_type,
            e.subject,
            e.exam_components,
            e.mcq_marks,
            e.project_marks,
            e.viva_marks,
            er.obtained_marks,
            er.grade,
            er.remarks,
            er.mcq_marks as student_mcq_marks,
            er.project_marks as student_project_marks,
            er.viva_marks as student_viva_marks,
            b.batch_name,
            b.batch_id
        FROM exams e
        JOIN exam_results er ON e.exam_id = er.exam_id
        JOIN batches b ON e.batch_id = b.batch_id
        WHERE (er.student_id = :student_id OR er.student_id = :student_name)
        AND e.batch_id = :batch_id
        AND e.exam_date BETWEEN :start_date AND :end_date
        ORDER BY e.exam_date DESC
    ");
    $exam_query->execute([
        ':student_id' => $student['student_id'],
        ':student_name' => $student_name,
        ':batch_id' => $selected_batch_id,
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $exam_results = $exam_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate exam statistics
    foreach ($exam_results as $exam) {
        $total_exams++;
        $total_marks_obtained += $exam['obtained_marks'];
        $total_possible_marks += $exam['total_marks'];
        
        if ($exam['obtained_marks'] >= $exam['passing_marks']) {
            $passed_exams++;
        }
        
        // Subject performance
        $subject = $exam['subject'];
        if (!isset($subject_performance[$subject])) {
            $subject_performance[$subject] = ['total' => 0, 'obtained' => 0, 'count' => 0];
        }
        $subject_performance[$subject]['total'] += $exam['total_marks'];
        $subject_performance[$subject]['obtained'] += $exam['obtained_marks'];
        $subject_performance[$subject]['count']++;
        
        // Exam type performance
        $exam_type = $exam['exam_type'];
        if (!isset($exam_type_performance[$exam_type])) {
            $exam_type_performance[$exam_type] = ['total' => 0, 'obtained' => 0, 'count' => 0];
        }
        $exam_type_performance[$exam_type]['total'] += $exam['total_marks'];
        $exam_type_performance[$exam_type]['obtained'] += $exam['obtained_marks'];
        $exam_type_performance[$exam_type]['count']++;
        
        // Component performance
        if (!empty($exam['exam_components'])) {
            $components = explode(',', $exam['exam_components']);
            
            if (in_array('mcq', $components) && !is_null($exam['student_mcq_marks'])) {
                $component_performance['mcq']['total'] += $exam['mcq_marks'];
                $component_performance['mcq']['obtained'] += $exam['student_mcq_marks'];
                $component_performance['mcq']['count']++;
            }
            
            if (in_array('project', $components) && !is_null($exam['student_project_marks'])) {
                $component_performance['project']['total'] += $exam['project_marks'];
                $component_performance['project']['obtained'] += $exam['student_project_marks'];
                $component_performance['project']['count']++;
            }
            
            if (in_array('viva', $components) && !is_null($exam['student_viva_marks'])) {
                $component_performance['viva']['total'] += $exam['viva_marks'];
                $component_performance['viva']['obtained'] += $exam['student_viva_marks'];
                $component_performance['viva']['count']++;
            }
        }
    }
    
    // ========== NEW: GET RANKINGS FOR EACH EXAM ==========
    // For each exam the student appeared in, fetch ranking data
    if (!empty($exam_results)) {
        $exam_ids = array_unique(array_column($exam_results, 'exam_id'));
        
        foreach ($exam_ids as $exam_id) {
            // Get all results for this exam
            $rank_query = $db->prepare("
                SELECT 
                    er.student_id,
                    s.first_name,
                    s.last_name,
                    er.obtained_marks,
                    e.total_marks,
                    ROUND((er.obtained_marks / e.total_marks) * 100, 2) as percentage
                FROM exam_results er
                JOIN exams e ON er.exam_id = e.exam_id
                LEFT JOIN students s ON er.student_id = s.student_id
                WHERE er.exam_id = :exam_id
                ORDER BY er.obtained_marks DESC, (er.obtained_marks / e.total_marks) DESC
            ");
            $rank_query->execute([':exam_id' => $exam_id]);
            $all_results = $rank_query->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate ranks
            $ranked_results = [];
            $rank = 1;
            $last_marks = null;
            $rank_counter = 1;
            
            foreach ($all_results as $result) {
                if ($last_marks !== null && $result['obtained_marks'] < $last_marks) {
                    $rank = $rank_counter;
                }
                $result['rank'] = $rank;
                $ranked_results[] = $result;
                $last_marks = $result['obtained_marks'];
                $rank_counter++;
            }
            
            // Find current student's rank and the top rankers
            $student_rank_info = null;
            $top_rankers = array_slice($ranked_results, 0, 5); // Top 5 rankers
            
            foreach ($ranked_results as $result) {
                if ($result['student_id'] == $student['student_id']) {
                    $student_rank_info = [
                        'rank' => $result['rank'],
                        'obtained_marks' => $result['obtained_marks'],
                        'total_marks' => $result['total_marks'],
                        'percentage' => $result['percentage'],
                        'total_students' => count($ranked_results)
                    ];
                    break;
                }
            }
            
            // Get exam details for this ranking
            $exam_detail_query = $db->prepare("SELECT exam_name, exam_date, total_marks FROM exams WHERE exam_id = :exam_id");
            $exam_detail_query->execute([':exam_id' => $exam_id]);
            $exam_detail = $exam_detail_query->fetch(PDO::FETCH_ASSOC);
            
            if ($exam_detail && $student_rank_info) {
                $exam_rankings[] = [
                    'exam_id' => $exam_id,
                    'exam_name' => $exam_detail['exam_name'],
                    'exam_date' => $exam_detail['exam_date'],
                    'total_marks' => $exam_detail['total_marks'],
                    'student_rank' => $student_rank_info['rank'],
                    'student_marks' => $student_rank_info['obtained_marks'],
                    'student_percentage' => $student_rank_info['percentage'],
                    'total_students' => $student_rank_info['total_students'],
                    'top_rankers' => $top_rankers
                ];
            }
        }
    }
}

$overall_exam_percentage = $total_possible_marks > 0 ? ($total_marks_obtained / $total_possible_marks) * 100 : 0;
$pass_percentage = $total_exams > 0 ? ($passed_exams / $total_exams) * 100 : 0;

// Calculate component performance percentages
foreach ($component_performance as $component => $data) {
    if ($data['count'] > 0) {
        $component_performance[$component]['percentage'] = ($data['obtained'] / $data['total']) * 100;
        $component_performance[$component]['avg_percentage'] = round(($data['obtained'] / $data['total']) * 100, 1);
    } else {
        $component_performance[$component]['percentage'] = 0;
        $component_performance[$component]['avg_percentage'] = 0;
    }
}

// Get feedback data for selected batch
if ($selected_batch_id) {
    $feedback_query = $db->prepare("
        SELECT 
            f.date,
            f.class_rating,
            f.assignment_understanding,
            f.practical_understanding,
            f.satisfied,
            f.suggestions,
            f.feedback_text,
            f.is_regular,
            f.batch_id,
            b.batch_name
        FROM feedback f
        LEFT JOIN batches b ON f.batch_id = b.batch_id
        WHERE (f.student_name = :student_name OR f.student_name = :student_id)
        AND f.batch_id = :batch_id
        AND f.date BETWEEN :start_date AND :end_date
        ORDER BY f.date DESC
    ");
    $feedback_query->execute([
        ':student_name' => $student_name,
        ':student_id' => $student['student_id'],
        ':batch_id' => $selected_batch_id,
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $feedback_data = $feedback_query->fetchAll(PDO::FETCH_ASSOC);
}

// Get batch history
$batch_history_query = $db->prepare("
    SELECT 
        sbh.*,
        b1.batch_name as from_batch_name,
        b2.batch_name as to_batch_name,
        u.name as transferred_by_name
    FROM student_batch_history sbh
    LEFT JOIN batches b1 ON sbh.from_batch_id = b1.batch_id
    LEFT JOIN batches b2 ON sbh.to_batch_id = b2.batch_id
    LEFT JOIN users u ON sbh.transferred_by = u.id
    WHERE sbh.student_id = :student_id
    ORDER BY sbh.transfer_date DESC
");
$batch_history_query->execute([':student_id' => $student_id_value]);
$batch_history_data = $batch_history_query->fetchAll(PDO::FETCH_ASSOC);

// Prepare chart data
$chart_data = [];

if (!empty($attendance_records)) {
    $attendance_counts = ['Present' => $present_count, 'Absent' => $absent_count];
    $attendance_by_month = [];
    
    foreach ($attendance_records as $record) {
        $month = date('Y-m', strtotime($record['date']));
        
        if (!isset($attendance_by_month[$month])) {
            $attendance_by_month[$month] = ['Present' => 0, 'Absent' => 0];
        }
        $attendance_by_month[$month][$record['status']]++;
    }
    
    // Prepare monthly attendance data
    $monthly_attendance_labels = [];
    $monthly_attendance_present = [];
    $monthly_attendance_absent = [];
    
    ksort($attendance_by_month);
    foreach ($attendance_by_month as $month => $counts) {
        $monthly_attendance_labels[] = date('M Y', strtotime($month));
        $monthly_attendance_present[] = $counts['Present'];
        $monthly_attendance_absent[] = $counts['Absent'];
    }
    
    $chart_data['attendance'] = [
        'labels' => array_keys($attendance_counts),
        'data' => array_values($attendance_counts),
        'colors' => ['#4ade80', '#f87171'],
        'title' => 'Attendance Distribution',
        'type' => 'pie'
    ];
    
    $chart_data['monthly_attendance'] = [
        'labels' => $monthly_attendance_labels,
        'datasets' => [
            ['label' => 'Present', 'data' => $monthly_attendance_present, 'backgroundColor' => '#4ade80'],
            ['label' => 'Absent', 'data' => $monthly_attendance_absent, 'backgroundColor' => '#f87171']
        ],
        'title' => 'Monthly Attendance Breakdown',
        'type' => 'bar'
    ];
}

// Prepare exam performance chart data
if (!empty($exam_results)) {
    $exam_performance_labels = [];
    $exam_performance_marks = [];
    $exam_performance_total = [];
    $exam_performance_passing = [];
    $exam_performance_percentages = [];
    
    foreach ($exam_results as $exam) {
        $exam_performance_labels[] = $exam['exam_name'] . ' (' . date('M d', strtotime($exam['exam_date'])) . ')';
        $exam_performance_marks[] = floatval($exam['obtained_marks']);
        $exam_performance_total[] = floatval($exam['total_marks']);
        $exam_performance_passing[] = floatval($exam['passing_marks']);
        $exam_performance_percentages[] = round(($exam['obtained_marks'] / $exam['total_marks']) * 100, 1);
    }
    
    $chart_data['exam_performance'] = [
        'labels' => $exam_performance_labels,
        'datasets' => [
            [
                'label' => 'Obtained Marks', 
                'data' => $exam_performance_marks, 
                'backgroundColor' => 'rgba(59, 130, 246, 0.7)', 
                'borderColor' => '#3b82f6', 
                'borderWidth' => 2
            ],
            [
                'label' => 'Total Marks', 
                'data' => $exam_performance_total, 
                'backgroundColor' => 'rgba(156, 163, 175, 0.5)', 
                'borderColor' => '#9ca3af', 
                'borderWidth' => 2,
                'type' => 'line',
                'fill' => false
            ],
            [
                'label' => 'Passing Marks', 
                'data' => $exam_performance_passing, 
                'backgroundColor' => 'rgba(239, 68, 68, 0.5)', 
                'borderColor' => '#ef4444', 
                'borderWidth' => 2, 
                'type' => 'line', 
                'fill' => false
            ]
        ],
        'title' => 'Exam Performance',
        'type' => 'bar'
    ];
    
    $chart_data['exam_percentages'] = [
        'labels' => $exam_performance_labels,
        'data' => $exam_performance_percentages,
        'title' => 'Exam Score Percentages',
        'type' => 'line'
    ];
}

// Prepare feedback chart data
if (!empty($feedback_data)) {
    $feedback_labels = array_map(function($item) { 
        return date('M d, Y', strtotime($item['date'])); 
    }, $feedback_data);
    
    $feedback_class = array_column($feedback_data, 'class_rating');
    $feedback_assignments = array_column($feedback_data, 'assignment_understanding');
    $feedback_practical = array_column($feedback_data, 'practical_understanding');
    $feedback_satisfaction = array_column($feedback_data, 'satisfied');
    
    $chart_data['feedback_details'] = [
        'labels' => $feedback_labels,
        'datasets' => [
            ['label' => 'Class Rating', 'data' => $feedback_class, 'borderColor' => '#3b82f6', 'backgroundColor' => '#3b82f620', 'tension' => 0.4],
            ['label' => 'Assignments', 'data' => $feedback_assignments, 'borderColor' => '#10b981', 'backgroundColor' => '#10b98120', 'tension' => 0.4],
            ['label' => 'Practical', 'data' => $feedback_practical, 'borderColor' => '#f59e0b', 'backgroundColor' => '#f59e0b20', 'tension' => 0.4],
            ['label' => 'Satisfaction', 'data' => $feedback_satisfaction, 'borderColor' => '#8b5cf6', 'backgroundColor' => '#8b5cf620', 'tension' => 0.4]
        ],
        'title' => 'Feedback Ratings Over Time',
        'type' => 'line'
    ];
}

// Learning analytics insights
$learning_insights = [];

// Attendance insights
if ($total_classes > 0) {
    if ($attendance_percentage < 70) {
        $learning_insights[] = [
            'type' => 'warning',
            'title' => 'Attendance Alert',
            'message' => 'Your attendance rate is below 70%. Consider improving class participation for better academic performance.',
            'icon' => 'fa-user-clock'
        ];
    }
    
    if ($camera_usage_percentage < 50) {
        $learning_insights[] = [
            'type' => 'info',
            'title' => 'Camera Engagement',
            'message' => 'Try keeping your camera on more often during classes for better engagement and interaction.',
            'icon' => 'fa-video'
        ];
    }
    
    if ($attendance_percentage >= 90) {
        $learning_insights[] = [
            'type' => 'success',
            'title' => 'Excellent Attendance',
            'message' => 'Great job maintaining excellent attendance! Keep up the consistency.',
            'icon' => 'fa-trophy'
        ];
    }
}

// Exam performance insights
if ($total_exams > 0) {
    if ($overall_exam_percentage < 60) {
        $learning_insights[] = [
            'type' => 'warning',
            'title' => 'Exam Performance',
            'message' => 'Your exam performance is below 60%. Consider focusing more on exam preparation.',
            'icon' => 'fa-chart-line'
        ];
    }
    
    if ($overall_exam_percentage >= 80) {
        $learning_insights[] = [
            'type' => 'success',
            'title' => 'Excellent Performance',
            'message' => 'Great job! You are performing excellently in exams. Keep up the good work!',
            'icon' => 'fa-award'
        ];
    }
    
    // Component-specific insights
    if ($component_performance['mcq']['count'] > 0 && $component_performance['mcq']['percentage'] < 60) {
        $learning_insights[] = [
            'type' => 'info',
            'title' => 'MCQ Performance',
            'message' => 'Your MCQ performance could use improvement. Try practicing more multiple-choice questions.',
            'icon' => 'fa-list-ol'
        ];
    }
    
    if ($component_performance['project']['count'] > 0 && $component_performance['project']['percentage'] < 60) {
        $learning_insights[] = [
            'type' => 'info',
            'title' => 'Project Work',
            'message' => 'Focus on improving your project work. Pay attention to requirements and presentation.',
            'icon' => 'fa-project-diagram'
        ];
    }
    
    if ($component_performance['viva']['count'] > 0 && $component_performance['viva']['percentage'] < 60) {
        $learning_insights[] = [
            'type' => 'info',
            'title' => 'Viva Performance',
            'message' => 'Your viva performance needs improvement. Practice explaining concepts clearly.',
            'icon' => 'fa-microphone'
        ];
    }
}

// Consistency analysis
if (count($monthly_attendance) >= 2) {
    $recent_months = array_slice($monthly_attendance, 0, 2);
    $recent_percentages = [];
    
    foreach ($recent_months as $month_data) {
        $recent_percentages[] = $month_data['total'] > 0 ? ($month_data['present'] / $month_data['total']) * 100 : 0;
    }
    
    if (count($recent_percentages) >= 2) {
        $latest_percentage = $recent_percentages[0];
        $previous_percentage = $recent_percentages[1];
        
        if ($latest_percentage > $previous_percentage + 10) {
            $learning_insights[] = [
                'type' => 'success',
                'title' => 'Attendance Improving',
                'message' => 'Your attendance has significantly improved recently. Keep up the good work!',
                'icon' => 'fa-chart-line'
            ];
        } elseif ($latest_percentage < $previous_percentage - 10) {
            $learning_insights[] = [
                'type' => 'warning',
                'title' => 'Attendance Decline',
                'message' => 'Your attendance has declined recently. Try to maintain consistent class participation.',
                'icon' => 'fa-exclamation-triangle'
            ];
        }
    }
}

// Batch type label for display
$batch_type_label = '';
$batch_transfer_date = '';
if (!empty($all_batches) && isset($all_batches[$selected_batch_index])) {
    $batch_type_label = $all_batches[$selected_batch_index]['batch_label'];
    if ($all_batches[$selected_batch_index]['batch_type'] == 'history' && isset($all_batches[$selected_batch_index]['transfer_date'])) {
        $batch_transfer_date = date('M j, Y', strtotime($all_batches[$selected_batch_index]['transfer_date']));
    }
}

// Calculate summary data for display
$summary_data = [
    'total_attendance' => $total_classes,
    'present_percentage' => $attendance_percentage,
    'camera_usage_percentage' => $camera_usage_percentage,
    'total_exams' => $total_exams,
    'passed_exams' => $passed_exams,
    'pass_percentage' => $pass_percentage,
    'total_marks_obtained' => $total_marks_obtained,
    'total_possible_marks' => $total_possible_marks,
    'overall_exam_percentage' => $overall_exam_percentage,
    'total_feedback' => count($feedback_data),
    'avg_feedback_rating' => !empty($feedback_data) ? round(array_sum(array_column($feedback_data, 'class_rating')) / count($feedback_data), 1) : 0,
    'batches_attended' => count($all_batches)
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Performance - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1B3C53',
                        secondary: '#234C6A',
                        cardColor: '#456882',
                        contentColor: '#EAE2DC',
                        sidebarBg: '#F7F5F3',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
        }
        
        .animate-slide-in {
            animation: slideIn 0.3s ease-out forwards;
        }
        
        .animate-slide-up {
            animation: slideUp 0.6s ease-out forwards;
        }
        
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        
        tr {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease-out;
        }
        
        .mobile-nav-link.active {
            background-color: #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-weight: 600;
        }
        
        .mobile-nav-link i.active {
            transform: scale(1.1);
        }
        
        #mobileMenu {
            transition: opacity 0.3s ease-in-out;
        }
        
        @media (max-width: 768px) {
            .text-sm-mobile {
                font-size: 0.875rem !important;
            }
            
            .text-lg-mobile {
                font-size: 1.125rem !important;
            }
}
            
            .chart-container {
             height: 200px !important;
            }

            .chart-wrapper{
                   background: linear-gradient(
                to bottom right,
                rgba(27,60,83,0.08),
                #ffffff,
                rgba(234,226,220,0.15)
            ) !important;
            }
        
        
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            border: 1px solid rgba(69, 104, 130, 0.15);
            box-shadow: 0 8px 32px rgba(27, 60, 83, 0.08);
        }
        
        .history-badge {
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
        }
        
        /* Print styles */
        @media print {
            .ml-64 { margin-left: 0 !important; }
            .p-8 { padding: 1rem !important; }
            button, .no-print { display: none !important; }
            .shadow-xl { box-shadow: none !important; }
            .bg-gradient-to-r { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        /* Ranking card hover effect */
        .rank-card {
            transition: all 0.3s ease;
        }
        .rank-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.1);
        }
        .rank-badge {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
        }
        .rank-1 {
            background: linear-gradient(135deg, #ffd700, #ffb347);
        }
        .rank-2 {
            background: linear-gradient(135deg, #c0c0c0, #a0a0a0);
        }
        .rank-3 {
            background: linear-gradient(135deg, #cd7f32, #b87333);
        }
    </style>
</head>
<body class="font-sans antialiased" style="background:#F7F5F3;">

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <!-- Mobile Header -->
    <header class="shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden" style="background:#F7F5F3;">
        <button class="text-xl transition-colors" style="color:#1B3C53;" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        
        <h1 class="text-lg font-bold flex items-center space-x-2" style="color:#1B3C53;">
            <div class="p-2 rounded-lg" style="background:#234C6A;">
                <i class="fas fa-chart-line text-sm" style="color:#EAE2DC;"></i>
            </div>
            <span>My Performance</span>
        </h1>
        
        <div class="flex items-center space-x-3">
            <div class="relative">
                <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background:#234C6A;">
                    <i class="fas fa-user-graduate" style="color:#EAE2DC;"></i>
                </div>
            </div>
        </div>
    </header>

    <!-- Desktop Header -->
<header class="hidden md:flex shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30" 
style="background: linear-gradient(90deg, #1B3C53, #234C6A, #456882, #D2C1B6); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);">

    <div class="flex-1"></div>
    
    <h1 class="text-2xl font-bold flex items-center space-x-2" style="color:#D2C1B6;">
        <div class="p-2 rounded-lg" style="background:#456882;">
            <i class="fas fa-chart-line text-xl" style="color:#D2C1B6;"></i>
        </div>

        <span style="color: white;">My Performance Dashboard</span>
    </h1>
    
    <div class="flex-1 flex justify-end items-center space-x-4">
        <div class="animate-pulse rounded-full p-2" style="background:#456882;">
            <i class="fas fa-user-graduate" style="color:#D2C1B6;"></i>
        </div>
    </div>

</header>


    <!-- Mobile Navigation Menu -->
    <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden">
        <div class="absolute left-0 top-0 h-full w-4/5 max-w-xs shadow-xl transform transition-transform duration-300 -translate-x-full" style="background:#F7F5F3;">
            <div class="p-4 border-b" style="border-color:#EAE2DC; background:linear-gradient(90deg,#1B3C53,#234C6A);">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                    </div>
                    <button onclick="toggleSidebar()" class="text-gray-400 hover:text-white text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="mt-4 flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold" style="background:#456882;">
                        <?= strtoupper(substr($student['first_name'] ?? 'S', 0, 1)) ?>
                    </div>
                    <div>
                        <p class="font-medium" style="color:#EAE2DC;"><?= htmlspecialchars($student['first_name'] ?? 'Student') ?> <?= htmlspecialchars($student['last_name'] ?? '') ?></p>
                        <p class="text-xs" style="color:rgba(234,226,220,0.7);">Student</p>
                    </div>
                </div>
            </div>
            
            <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                
                <a href="../stu_dash/dashboard.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'dashboard.php' ? 'bg-white shadow-md text-blue-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-tachometer-alt <?= $current_page == 'dashboard.php' ? 'text-blue-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Dashboard</span>
                </a>

                <a href="../stu_dash/my_batches.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_batches.php' ? 'bg-white shadow-md text-green-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-users <?= $current_page == 'my_batches.php' ? 'text-green-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Batches</span>
                </a>

                <a href="../stu_dash/upcoming.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'upcoming.php' ? 'bg-white shadow-md text-purple-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-calendar-alt <?= $current_page == 'upcoming.php' ? 'text-purple-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Upcoming Schedule</span>
                </a>

                <a href="../stu_dash/my_content.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_content.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-book <?= $current_page == 'my_content.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Content</span>
                </a>
                
                <a href="../student_test/student_dashboard.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_dashboard.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-vial <?= $current_page == 'student_dashboard.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Test</span>
                </a>

                <a href="../stu_dash/my_performance.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_performance.php' ? 'bg-white shadow-md text-red-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-chart-line <?= $current_page == 'my_performance.php' ? 'text-red-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Performance</span>
                </a>

                <a href="../stu_dash/student_feedback.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_feedback.php' ? 'bg-white shadow-md text-indigo-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-comment-dots <?= $current_page == 'student_feedback.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Feedback</span>
                </a>

                <a href="../stu_dash/student_profile.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_profile.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-user-circle <?= $current_page == 'student_profile.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Profile</span>
                </a>
                
                <a href="../logout.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-red-50 hover:text-red-600 text-gray-700 mt-4 border-t pt-4"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-sign-out-alt text-red-500"></i>
                    </div>
                    <span class="font-medium">Logout</span>
                </a>
            </nav>
        </div>
    </div>

    <div class="p-4 md:p-6 min-h-screen" style="background:#F7F5F3;">
        <!-- Student Info Banner -->
        <div class="rounded-2xl p-6 mb-6 border animate-slide-up" style="background:linear-gradient(90deg,rgba(27,60,83,0.06),rgba(35,76,106,0.08)); border-color:rgba(69,104,130,0.2);">
            <div class="flex flex-wrap items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="p-4 rounded-2xl shadow-lg" style="background:linear-gradient(135deg,#234C6A,#1B3C53);">
                        <i class="fas fa-user-graduate text-white text-3xl"></i>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold" style="color:#1B3C53;"><?= htmlspecialchars($student_name) ?></h3>
                        <div class="flex flex-wrap gap-3 mt-2">
                            <span class="px-3 py-1 rounded-full text-base flex items-center" style="background:rgba(69,104,130,0.15); color:#234C6A;">
                                <i class="fas fa-id-card mr-1"></i> ID: <?= htmlspecialchars($student['student_id']) ?>
                            </span>
                            <?php if (!empty($student['email'])): ?>
                                <span class="px-3 py-1 rounded-full text-base flex items-center" style="background:rgba(234,226,220,0.35); color:#1B3C53;">
                                    <i class="fas fa-envelope mr-1"></i> <?= htmlspecialchars($student['email']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($student['phone_number'])): ?>
                                <span class="px-3 py-1 rounded-full text-base flex items-center" style="background:rgba(69,104,130,0.12); color:#234C6A;">
                                    <i class="fas fa-phone mr-1"></i> <?= htmlspecialchars($student['phone_number']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="px-3 py-1 rounded-full text-base flex items-center" style="background:rgba(234,226,220,0.4); color:#1B3C53;">
                                <i class="fas fa-calendar-alt mr-1"></i> Enrolled: <?= date('M d, Y', strtotime($student['enrollment_date'])) ?>
                            </span>
                        </div>

                    </div>
                </div>
                <div class="mt-4 md:mt-0">
                    <div class="bg-white rounded-xl px-6 py-3 shadow-sm">
                        <span class="text-sm text-gray-500 block">Current Status</span>
                        <span class="font-semibold text-lg <?= $student['current_status'] === 'active' ? 'text-green-600' : 'text-orange-600' ?>">
                            <?= ucfirst($student['current_status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Batch History Timeline (if available) -->
        <?php if (!empty($batch_history_data)): ?>
        <div class="bg-white rounded-2xl shadow-xl p-6 mb-6 animate-fade-in">
            <div class="flex items-center mb-4">
                <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                    <i class="fas fa-timeline" style="color:#234C6A;"></i>
                </div>
                <h3 class="text-lg font-semibold" style="color:#1B3C53;">Batch Transfer History</h3>
            </div>
            <div class="relative">
                <div class="absolute left-4 top-0 bottom-0 w-0.5" style="background:rgba(69,104,130,0.2);"></div>
                <div class="space-y-4">
                    <?php foreach ($batch_history_data as $history): ?>
                    <div class="relative pl-10">
                        <div class="absolute left-2 top-2 w-4 h-4 rounded-full border-4" style="background:#456882; border-color:rgba(69,104,130,0.15);"></div>
                        <div class="rounded-xl p-4" style="background:rgba(234,226,220,0.18);">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-medium" style="color:#1B3C53;">
                                        <span style="color:#456882;"><?= htmlspecialchars($history['from_batch_name'] ?? $history['from_batch_id']) ?></span>
                                        <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                                        <span style="color:#234C6A;"><?= htmlspecialchars($history['to_batch_name'] ?? $history['to_batch_id']) ?></span>
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <i class="far fa-clock mr-1"></i> <?= date('M d, Y H:i', strtotime($history['transfer_date'])) ?>
                                    </p>
                                </div>
                                <?php if (!empty($history['transferred_by_name'])): ?>
                                <span class="text-xs text-gray-500 bg-white px-3 py-1 rounded-full">
                                    By: <?= htmlspecialchars($history['transferred_by_name']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Batch Selection Tabs -->
        <?php if (count($all_batches) > 0): ?>
        <div class="glass-card p-4 mb-6">
            <h3 class="text-lg font-bold mb-3 flex items-center" style="color:#1B3C53;">
                <i class="fas fa-exchange-alt mr-2" style="color:#456882;"></i>
                Select Batch to View Performance
            </h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($all_batches as $index => $batch_info): ?>
                    <a href="?batch_index=<?= $index ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&view=<?= $report_view ?>" 
                       class="px-4 py-2 rounded-lg transition-all duration-300 font-medium text-sm"
                       style="<?= $selected_batch_index == $index ? 'background:#456882; color:white; box-shadow:0 4px 12px rgba(69,104,130,0.35);' : ($batch_info['batch_type'] == 'history' ? 'background:rgba(234,226,220,0.4); color:#1B3C53; border-left:3px solid #456882;' : 'background:rgba(234,226,220,0.25); color:#1B3C53;') ?>">
                        <div class="flex items-center">
                            <i class="fas <?= $batch_info['batch_type'] == 'history' ? 'fa-history' : 'fa-layer-group' ?> mr-2"></i>
                            <span><?= htmlspecialchars($batch_info['batch_label']) ?>: <?= htmlspecialchars($batch_info['batch_data']['batch_name']) ?></span>
                            <?php if ($selected_batch_index == $index): ?>
                                <i class="fas fa-check ml-2"></i>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($selected_batch): ?>
                <p class="text-sm text-gray-500 mt-2 flex items-center">
                    <i class="fas <?= $batch_type_label == 'history' ? 'fa-history' : 'fa-info-circle' ?> mr-1" style="color:#456882;"></i>
                    Currently viewing: <span class="font-semibold ml-1" style="color:#234C6A;">
                        <?= htmlspecialchars($batch_type_label) ?> - <?= htmlspecialchars($selected_batch['batch_name']) ?>
                    </span>
                    <?php if (!empty($batch_transfer_date)): ?>
                        <span class="ml-2 text-xs bg-gray-100 px-2 py-1 rounded">
                            Transferred on: <?= $batch_transfer_date ?>
                        </span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Current Batch Info -->
        <?php if ($selected_batch): ?>
        <div class="p-4 rounded-2xl shadow-lg mb-6 transform transition-transform duration-300 hover:scale-[1.005] border-l-4"
             style="background:<?= $batch_type_label == 'history' ? 'rgba(234,226,220,0.2)' : 'rgba(27,60,83,0.06)' ?>; border-color:#456882;">
            <div class="flex flex-col md:flex-row md:items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold mb-2 flex items-center" style="color:#1B3C53;">
                        <i class="fas <?= $batch_type_label == 'history' ? 'fa-history' : 'fa-star' ?> mr-2" style="color:#456882;"></i>
                        Current Performance View
                    </h3>
                    <p class="text-gray-600">
                        Showing performance data for <span class="font-semibold" style="color:#234C6A;"><?= htmlspecialchars($selected_batch['batch_name']) ?></span>
                        <?php if ($batch_type_label == 'history'): ?>
                            <span class="ml-2 text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full">Previous Batch</span>
                        <?php endif; ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        Period: <?= date('M j, Y', strtotime($start_date)) ?> to <?= date('M j, Y', strtotime($end_date)) ?>
                    </p>
                </div>
                <div class="mt-3 md:mt-0 flex space-x-2">
                    <span class="px-3 py-1 text-xs rounded-full animate-pulse
                        <?= $selected_batch['status'] === 'ongoing' ? 'bg-blue-100 text-blue-800' : 
                           ($selected_batch['status'] === 'upcoming' ? 'bg-yellow-100 text-yellow-800' : 
                           ($selected_batch['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')) ?>">
                        <?= ucfirst($selected_batch['status']) ?>
                    </span>
                    <?php if ($batch_type_label == 'history'): ?>
                        <span class="px-3 py-1 text-xs rounded-full bg-gray-200 text-gray-800">
                            <i class="fas fa-history mr-1"></i> Archived
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

       

<?php if ($selected_batch): ?>
<!-- Stats Overview Cards -->
<!-- Stats Overview Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">

<?php
$card_style = "
background: linear-gradient(135deg,#234C6A 0%,#1B3C53 100%);
border-color:#456882;
";
?>

<!-- Attendance -->
<div class="p-5 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 hover:-translate-y-1.5 border-l-4"
style="<?= $card_style ?>">

    <div class="flex items-center justify-between mb-4">
        <div class="p-2.5 rounded-xl bg-white/20">
            <i class="fas fa-user-check text-white text-lg"></i>
        </div>

        <span class="text-xs font-bold px-3 py-1 rounded-full bg-white/20 text-white border border-white/30">
            <?= $attendance_percentage >= 75 ? 'Good' : 'Average' ?>
        </span>
    </div>

    <p class="text-xs font-bold uppercase tracking-wider text-[#D2C1B6]">
        Attendance Rate
    </p>

    <h3 class="text-3xl font-extrabold text-white mb-3">
        <?= number_format($attendance_percentage,1) ?>%
    </h3>

    <div class="w-full rounded-full h-2 bg-white/20">
        <div class="h-2 rounded-full bg-[#D2C1B6]"
        style="width:<?= $attendance_percentage ?>%">
        </div>
    </div>

    <p class="text-xs text-white/80 mt-2">
        <?= $present_count ?> of <?= $total_classes ?> classes attended
    </p>

</div>


<!-- Exam -->
<div class="p-5 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 hover:-translate-y-1.5 border-l-4"
style="<?= $card_style ?>">

    <div class="flex items-center justify-between mb-4">
        <div class="p-2.5 rounded-xl bg-white/20">
            <i class="fas fa-graduation-cap text-white text-lg"></i>
        </div>

        <span class="text-xs font-bold px-3 py-1 rounded-full bg-white/20 text-white border border-white/30">
            <?= $overall_exam_percentage >= 75 ? 'Good' : 'Average' ?>
        </span>
    </div>

    <p class="text-xs font-bold uppercase tracking-wider text-[#D2C1B6]">
        Exam Performance
    </p>

    <h3 class="text-3xl font-extrabold text-white mb-3">
        <?= number_format($overall_exam_percentage,1) ?>%
    </h3>

    <div class="w-full rounded-full h-2 bg-white/20">
        <div class="h-2 rounded-full bg-[#D2C1B6]"
        style="width:<?= $overall_exam_percentage ?>%">
        </div>
    </div>

    <p class="text-xs text-white/80 mt-2">
        <?= $passed_exams ?> of <?= $total_exams ?> passed
    </p>

</div>


<!-- Camera -->
<div class="p-5 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 hover:-translate-y-1.5 border-l-4"
style="<?= $card_style ?>">

    <div class="flex items-center justify-between mb-4">
        <div class="p-2.5 rounded-xl bg-white/20">
            <i class="fas fa-video text-white text-lg"></i>
        </div>

        <span class="text-xs font-bold px-3 py-1 rounded-full bg-white/20 text-white border border-white/30">
            <?= $camera_usage_percentage >= 75 ? 'Good' : 'Average' ?>
        </span>
    </div>


    <p class="text-xs font-bold uppercase tracking-wider text-[#D2C1B6]">
        Camera Usage
    </p>

    <h3 class="text-3xl font-extrabold text-white mb-3">
        <?= number_format($camera_usage_percentage,1) ?>%
    </h3>

    <div class="w-full rounded-full h-2 bg-white/20">
        <div class="h-2 rounded-full bg-[#D2C1B6]"
        style="width:<?= $camera_usage_percentage ?>%">
        </div>
    </div>

    <p class="text-xs text-white/80 mt-2">
        <?= $camera_on_count ?> classes with camera on
    </p>

</div>


<!-- Feedback -->
<div class="p-5 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 hover:-translate-y-1.5 border-l-4"
style="<?= $card_style ?>">

    <div class="flex items-center justify-between mb-4">

        <div class="p-2.5 rounded-xl bg-white/20">
            <i class="fas fa-star text-white text-lg"></i>
        </div>

    </div>


    <p class="text-xs font-bold uppercase tracking-wider text-[#D2C1B6]">
        Feedback Rating
    </p>

    <h3 class="text-3xl font-extrabold text-white mb-3">
        <?= number_format($summary_data['avg_feedback_rating'],1) ?>/5
    </h3>

    <div class="w-full rounded-full h-2 bg-white/20">
        <div class="h-2 rounded-full bg-[#D2C1B6]"
        style="width:<?= ($summary_data['avg_feedback_rating']/5)*100 ?>%">
        </div>
    </div>

    <p class="text-xs text-white/80 mt-2">
        <?= $summary_data['total_feedback'] ?> feedback entries
    </p>

</div>

</div>

 <!-- Filters -->
        <div class="p-6 rounded-2xl shadow-lg mb-6 transform transition-transform duration-300 hover:scale-[1.005]" style="background:#F7F5F3; border:1px solid rgba(27,60,83,0.12);">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold flex items-center" style="color:#1B3C53;">
                    <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                        <i class="fas fa-filter" style="color:#456882;"></i>
                    </div>
                    Filter Performance Data
                </h2>
                <?php if ($selected_batch): ?>
                <span class="text-sm" style="color:#456882;">
                    Batch: <?= htmlspecialchars($selected_batch['batch_name']) ?>
                    <?php if ($batch_type_label == 'history'): ?>
                        <span class="ml-1 text-xs px-2 py-0.5 rounded" style="background:#EAE2DC; color:#1B3C53;">Previous</span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
            
            <form method="get" class="space-y-4 md:space-y-0 md:grid md:grid-cols-4 md:gap-4">
                <input type="hidden" name="batch_index" value="<?= $selected_batch_index ?>">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:#234C6A;">Start Date</label>
                    <input type="date" name="start_date" value="<?= $start_date ?>" 
                           class="w-full px-3 py-2 rounded-lg focus:outline-none transition-colors" style="border:1.5px solid #456882; color:#1B3C53; background:#fff;">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:#234C6A;">End Date</label>
                    <input type="date" name="end_date" value="<?= $end_date ?>" 
                           class="w-full px-3 py-2 rounded-lg focus:outline-none transition-colors" style="border:1.5px solid #456882; color:#1B3C53; background:#fff;">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:#234C6A;">View</label>
                    <select name="view" class="w-full px-3 py-2 rounded-lg focus:outline-none transition-colors" style="border:1.5px solid #456882; color:#1B3C53; background:#fff;">
                        <option value="overview" <?= $report_view == 'overview' ? 'selected' : '' ?>>Overview</option>
                        <option value="attendance" <?= $report_view == 'attendance' ? 'selected' : '' ?>>Attendance</option>
                        <option value="exams" <?= $report_view == 'exams' ? 'selected' : '' ?>>Exams</option>
                        <option value="feedback" <?= $report_view == 'feedback' ? 'selected' : '' ?>>Feedback</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full text-white font-medium py-2 px-4 rounded-lg transition-all duration-300 transform hover:scale-[1.02] flex items-center justify-center" style="background:#1B3C53;" onmouseover="this.style.background='#234C6A'" onmouseout="this.style.background='#1B3C53'">
                        <i class="fas fa-sync-alt mr-2"></i>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabs Navigation -->
        <div class="p-2 rounded-2xl shadow-lg mb-6" style="background:#F7F5F3;">
            <div class="flex flex-col md:flex-row space-y-2 md:space-y-0 md:space-x-2">
                <button class="tab-btn flex-1 py-3 px-4 rounded-xl text-center font-medium transition-all duration-300" 
                        style="<?= $report_view == 'overview' ? 'background:#456882; color:#fff;' : 'background:#EAE2DC; color:#1B3C53;' ?>"
                        data-tab="overview">
                    <i class="fas fa-chart-pie mr-2"></i>
                    Overview
                </button>
                <button class="tab-btn flex-1 py-3 px-4 rounded-xl text-center font-medium transition-all duration-300" 
                        style="<?= $report_view == 'attendance' ? 'background:#456882; color:#fff;' : 'background:#EAE2DC; color:#1B3C53;' ?>"
                        data-tab="attendance">
                    <i class="fas fa-calendar-check mr-2"></i>
                    Attendance
                </button>
                <button class="tab-btn flex-1 py-3 px-4 rounded-xl text-center font-medium transition-all duration-300" 
                        style="<?= $report_view == 'exams' ? 'background:#456882; color:#fff;' : 'background:#EAE2DC; color:#1B3C53;' ?>"
                        data-tab="exams">
                    <i class="fas fa-graduation-cap mr-2"></i>
                    Exams
                </button>
                <button class="tab-btn flex-1 py-3 px-4 rounded-xl text-center font-medium transition-all duration-300" 
                        style="<?= $report_view == 'feedback' ? 'background:#456882; color:#fff;' : 'background:#EAE2DC; color:#1B3C53;' ?>"
                        data-tab="feedback">
                    <i class="fas fa-comment-alt mr-2"></i>
                    Feedback
                </button>
            </div>
        </div>

        <!-- Tab Contents -->
        <div class="tab-contents">
            <!-- Overview Tab -->
            <div class="tab-content <?= $report_view == 'overview' ? 'active' : '' ?>" id="overview-tab">
                <?php if (!empty($learning_insights)): ?>
               <div class="p-6 rounded-2xl shadow-lg mb-6" style="background:#F7F5F3; border:1px solid rgba(69,104,130,0.2);">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-lightbulb" style="color:#456882;"></i>
                        </div>
                        Learning Insights & Recommendations
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($learning_insights as $insight): ?>
                        <div class="border-l-4 p-4 rounded-r-lg" style="<?= $insight['type'] == 'success' ? 'border-color:#456882; background:rgba(69,104,130,0.08);' : ($insight['type'] == 'warning' ? 'border-color:#EAE2DC; background:rgba(234,226,220,0.2);' : 'border-color:#1B3C53; background:rgba(27,60,83,0.07);') ?>">
                            <div class="flex items-start mb-2">
                                <div class="flex-shrink-0">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center mr-3" style="background:rgba(69,104,130,0.15);">
                                        <i class="fas <?= $insight['icon'] ?>" style="color:#456882;"></i>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold" style="color:#1B3C53;"><?= $insight['title'] ?></h4>
                                    <p class="text-sm mt-1" style="color:#234C6A;"><?= $insight['message'] ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Performance Charts -->
                <div class="bg-white p-6 rounded-2xl shadow-lg mb-6" style="border-top:3px solid #456882;">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-chart-bar" style="color:#456882;"></i>
                        </div>
                        Performance Overview
                    </h2>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php if (isset($chart_data['attendance'])): ?>
                        <div class="chart-wrapper">
                            <h4 class="font-semibold text-gray-700 mb-3 text-center">Attendance Distribution</h4>
                            <div class="chart-container" style="height: 250px;">
                                <canvas id="attendanceChart"></canvas>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (isset($chart_data['exam_performance'])): ?>
                        <div class="chart-wrapper">
                            <h4 class="font-semibold text-gray-700 mb-3 text-center">Exam Performance</h4>
                            <div class="chart-container" style="height: 250px;">
                                <canvas id="examPerformanceChart"></canvas>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Component Performance -->
                <?php if ($total_exams > 0): ?>
                <div class="bg-white p-6 rounded-2xl shadow-lg mb-6" style="border-top:3px solid #1B3C53;">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-puzzle-piece" style="color:#456882;"></i>
                        </div>
                        Component Performance Analysis
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="p-6 rounded-xl text-center" style="background:rgba(234,226,220,0.35); border:1px solid rgba(69,104,130,0.2);">
                            <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4" style="background:#456882;">
                                <i class="fas fa-list-ol text-white text-2xl"></i>
                            </div>
                            <h3 class="font-bold mb-2" style="color:#1B3C53;">MCQ Performance</h3>
                            <div class="text-3xl font-bold mb-2" style="color:#456882;"><?= number_format($component_performance['mcq']['percentage'], 1) ?>%</div>
                            <div class="w-full rounded-full h-2 mb-2" style="background:rgba(69,104,130,0.2);">
                                <div class="h-2 rounded-full transition-all duration-1000 ease-out" style="width: <?= $component_performance['mcq']['percentage'] ?>%; background:#456882;"></div>
                            </div>
                            <div class="text-sm" style="color:#234C6A;">
                                <?= $component_performance['mcq']['obtained'] ?>/<?= $component_performance['mcq']['total'] ?> marks
                            </div>
                            <div class="text-xs mt-1" style="color:#456882;">
                                <?= $component_performance['mcq']['count'] ?> exam(s)
                            </div>
                        </div>
                        
                        <div class="p-6 rounded-xl text-center" style="background:rgba(27,60,83,0.07); border:1px solid rgba(27,60,83,0.15);">
                            <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4" style="background:#1B3C53;">
                                <i class="fas fa-project-diagram text-white text-2xl"></i>
                            </div>
                            <h3 class="font-bold mb-2" style="color:#1B3C53;">Project Work</h3>
                            <div class="text-3xl font-bold mb-2" style="color:#1B3C53;"><?= number_format($component_performance['project']['percentage'], 1) ?>%</div>
                            <div class="w-full rounded-full h-2 mb-2" style="background:rgba(27,60,83,0.15);">
                                <div class="h-2 rounded-full transition-all duration-1000 ease-out" style="width: <?= $component_performance['project']['percentage'] ?>%; background:#1B3C53;"></div>
                            </div>
                            <div class="text-sm" style="color:#234C6A;">
                                <?= $component_performance['project']['obtained'] ?>/<?= $component_performance['project']['total'] ?> marks
                            </div>
                            <div class="text-xs mt-1" style="color:#456882;">
                                <?= $component_performance['project']['count'] ?> exam(s)
                            </div>
                        </div>
                        
                        <div class="p-6 rounded-xl text-center" style="background:rgba(234,226,220,0.35); border:1px solid rgba(69,104,130,0.2);">
                            <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4" style="background:#234C6A;">
                                <i class="fas fa-microphone text-white text-2xl"></i>
                            </div>
                            <h3 class="font-bold mb-2" style="color:#1B3C53;">Viva Performance</h3>
                            <div class="text-3xl font-bold mb-2" style="color:#234C6A;"><?= number_format($component_performance['viva']['percentage'], 1) ?>%</div>
                            <div class="w-full rounded-full h-2 mb-2" style="background:rgba(35,76,106,0.2);">
                                <div class="h-2 rounded-full transition-all duration-1000 ease-out" style="width: <?= $component_performance['viva']['percentage'] ?>%; background:#234C6A;"></div>
                            </div>
                            <div class="text-sm" style="color:#234C6A;">
                                <?= $component_performance['viva']['obtained'] ?>/<?= $component_performance['viva']['total'] ?> marks
                            </div>
                            <div class="text-xs mt-1" style="color:#456882;">
                                <?= $component_performance['viva']['count'] ?> exam(s)
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Subject Performance -->
                <?php if (!empty($subject_performance)): ?>
                <div class="bg-white p-6 rounded-2xl shadow-lg" style="border-top:3px solid #456882;">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-book-open" style="color:#456882;"></i>
                        </div>
                        Subject-wise Performance
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($subject_performance as $subject => $data): ?>
                        <?php $subject_percentage = $data['total'] > 0 ? ($data['obtained'] / $data['total']) * 100 : 0; ?>
                        <div class="p-4 rounded-lg" style="background:#F6F4F2; border:1px solid rgba(69,104,130,0.15);">
                            <div class="flex justify-between items-start mb-2">
                                <h4 class="font-semibold" style="color:#1B3C53;"><?= htmlspecialchars($subject) ?></h4>
                                <span class="text-xs px-2 py-1 rounded-full" style="background:rgba(69,104,130,0.1); color:#234C6A;"><?= $data['count'] ?> exam(s)</span>
                            </div>
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-sm" style="color:#234C6A;">Average Score</span>
                                <span class="font-bold" style="color:#456882;"><?= number_format($subject_percentage, 1) ?>%</span>
                            </div>
                            <div class="w-full rounded-full h-2 mb-1" style="background:rgba(69,104,130,0.2);">
                                <div class="h-2 rounded-full" style="width: <?= $subject_percentage ?>%; background:#456882;"></div>
                            </div>
                            <div class="text-xs" style="color:#234C6A;">
                                <?= $data['obtained'] ?>/<?= $data['total'] ?> marks
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
                            
            <!-- Attendance Tab -->
            <div class="tab-content <?= $report_view == 'attendance' ? 'active' : '' ?>" id="attendance-tab">
               
                <?php if ($total_classes > 0): ?>
                <!-- Monthly Attendance Chart -->
                <div class="chart-wrapper">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-chart-bar" style="color:#456882;"></i>
                        </div>
                        Monthly Attendance Trend
                    </h2>
                    
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="monthlyAttendanceChart"></canvas>
                    </div>
                </div>
                
                <!-- Daily Attendance Pattern -->
                <?php if (!empty($daily_attendance_pattern)): ?>
                <div class="p-6 rounded-2xl shadow-lg mb-6" style="background:#EAE2DC;">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.2);">
                            <i class="fas fa-calendar-week" style="color:#456882;"></i>
                        </div>
                        Attendance by Day of Week
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-7 gap-3">
                        <?php 
                        $days_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        foreach ($days_order as $day):
                            $day_data = $daily_attendance_pattern[$day] ?? ['present' => 0, 'total' => 0];
                            $day_percentage = $day_data['total'] > 0 ? ($day_data['present'] / $day_data['total']) * 100 : 0;
                        ?>
                        <div class="p-3 rounded-lg text-center" style="background:rgba(247,245,243,0.7);">
                            <h4 class="font-medium text-sm" style="color:#234C6A;"><?= substr($day, 0, 3) ?></h4>
                            <div class="text-lg font-bold" style="color:<?= $day_percentage >= 70 ? '#456882' : ($day_percentage >= 50 ? '#D97706' : '#dc2626') ?>;">
                                <?= number_format($day_percentage, 0) ?>%
                            </div>
                            <div class="text-xs" style="color:#234C6A;">
                                <?= $day_data['present'] ?>/<?= $day_data['total'] ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Attendance Records -->
                <div class="bg-white p-6 rounded-2xl shadow-lg" style="border-top:3px solid #456882;">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-table" style="color:#456882;"></i>
                        </div>
                        Attendance Records
                    </h2>
                    
                    <div class="overflow-x-auto rounded-xl" style="border:1px solid rgba(69,104,130,0.25);">
                        <table class="min-w-full">
                            <thead>
                                <tr style="background:#1B3C53;">
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-white">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-white">Day</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-white">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-white">Camera</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-white">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_records as $record): ?>
                                <tr class="transition-colors duration-200" style="background:#EAE2DC; border-bottom:1px solid rgba(69,104,130,0.15);" onmouseover="this.style.background='rgba(69,104,130,0.15)'" onmouseout="this.style.background='#EAE2DC'">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:#1B3C53;">
                                        <?= date('M j, Y', strtotime($record['date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:#1B3C53;">
                                        <?= $record['day_name'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-3 py-1 text-xs rounded-full <?= $record['status'] === 'Present' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= $record['status'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:#1B3C53;">
                                        <span class="px-3 py-1 text-xs rounded-full" style="<?= $record['camera_status'] === 'On' ? 'background:rgba(69,104,130,0.2); color:#234C6A;' : 'background:rgba(0,0,0,0.07); color:#456882;' ?>">
                                            <?= $record['camera_status'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:#1B3C53;">
                                        <?= $record['remarks'] ?: '-' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="p-8 rounded-2xl shadow-lg text-center" style="background:#F7F5F3;">
                    <div class="inline-block p-6 rounded-full mb-4" style="background:rgba(69,104,130,0.15);">
                        <i class="fas fa-calendar-times text-4xl" style="color:#456882;"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2" style="color:#1B3C53;">No Attendance Records Found</h3>
                    <p style="color:#234C6A;">No attendance data is available for the selected date range in this batch.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Exams Tab -->
            <div class="tab-content <?= $report_view == 'exams' ? 'active' : '' ?>" id="exams-tab">
                <?php if ($total_exams > 0): ?>
                <!-- Exam Performance Summary -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <!-- Overall Average Card -->
                    <div class="p-6 rounded-2xl shadow-lg" style="background: linear-gradient(135deg, #f0fdfa 0%, #ccfbf1 100%); border-top: 4px solid #0d9488;">
                        <div class="flex items-center mb-3">
                            <div class="p-3 rounded-full mr-3" style="background:rgba(13, 148, 136, 0.15);">
                                <i class="fas fa-chart-pie" style="color:#0d9488;"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium" style="color:#115e59;">Overall Average</h3>
                                <p class="text-2xl font-bold" style="color:#115e59;"><?= number_format($overall_exam_percentage, 1) ?>%</p>
                            </div>
                        </div>
                        <div class="w-full rounded-full h-2" style="background:rgba(13, 148, 136, 0.15);">
                            <div class="h-2 rounded-full" style="width: <?= $overall_exam_percentage ?>%; background:#0d9488;"></div>
                        </div>
                        <p class="text-xs mt-2" style="color:#115e59;">
                            <?= $total_marks_obtained ?> / <?= $total_possible_marks ?> total marks
                        </p>
                    </div>
                    
                    <!-- Pass Rate Card -->
                    <div class="p-6 rounded-2xl shadow-lg" style="background: linear-gradient(135deg, #f5f3ff 0%, #e0e7ff 100%); border-top: 4px solid #6366f1;">
                        <div class="flex items-center mb-3">
                            <div class="p-3 rounded-full mr-3" style="background:rgba(99, 102, 241, 0.12);">
                                <i class="fas fa-check-circle" style="color:#6366f1;"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium" style="color:#3730a3;">Pass Rate</h3>
                                <p class="text-2xl font-bold" style="color:#312e81;"><?= number_format($pass_percentage, 1) ?>%</p>
                            </div>
                        </div>
                        <div class="w-full rounded-full h-2" style="background:rgba(99, 102, 241, 0.15);">
                            <div class="h-2 rounded-full" style="width: <?= $pass_percentage ?>%; background:#6366f1;"></div>
                        </div>
                        <p class="text-xs mt-2" style="color:#3730a3;">
                            <?= $passed_exams ?> / <?= $total_exams ?> exams passed
                        </p>
                    </div>
                    
                    <!-- Best Performance Card -->
                    <div class="p-6 rounded-2xl shadow-lg" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-top: 4px solid #d97706;">
                        <div class="flex items-center mb-3">
                            <div class="p-3 rounded-full mr-3" style="background:rgba(217, 119, 6, 0.12);">
                                <i class="fas fa-star" style="color:#d97706;"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium" style="color:#92400e;">Best Performance</h3>
                                <?php 
                                $best_exam = null;
                                $best_percentage = 0;
                                foreach ($exam_results as $exam) {
                                    $percentage = ($exam['obtained_marks'] / $exam['total_marks']) * 100;
                                    if ($percentage > $best_percentage) {
                                        $best_percentage = $percentage;
                                        $best_exam = $exam;
                                    }
                                }
                                ?>
                                <p class="text-2xl font-bold" style="color:#78350f;"><?= number_format($best_percentage, 1) ?>%</p>
                            </div>
                        </div>
                        <p class="text-xs truncate" style="color:#92400e;">
                            <?= $best_exam ? htmlspecialchars($best_exam['exam_name']) : 'N/A' ?>
                        </p>
                    </div>
                </div>
                
                
<!-- Exam Results Detailed List -->
    <div class="bg-white p-6 rounded-2xl shadow-lg mb-6" style="border-top:3px solid #1B3C53;">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-file-alt" style="color:#456882;"></i>
                        </div>
                        Detailed Exam Results
                    </h2>

        <div class="space-y-6">
            <?php foreach ($exam_results as $exam):
                $percentage = ($exam['obtained_marks'] / $exam['total_marks']) * 100;
                $is_passed  = $exam['obtained_marks'] >= $exam['passing_marks'];
                $grade_color = $is_passed ? 'text-green-600' : 'text-red-600';

                // ── Grade → color mapping ──────────────────────────
                $grade_raw = trim($exam['grade'] ?? '');
                $grade_upper = strtoupper($grade_raw);

                if (in_array($grade_upper, ['A+', 'A++'])) {
                    $grade_badge_bg   = '#d1fae5';   // vibrant green bg
                    $grade_badge_text = '#059669';   // vibrant green text
                    $grade_glow       = 'rgba(16,185,129,0.35)';
                } elseif ($grade_upper === 'A' || $grade_upper === 'A-') {
                    $grade_badge_bg   = '#dcfce7';   // green bg
                    $grade_badge_text = '#16a34a';   // green text
                    $grade_glow       = 'rgba(34,197,94,0.30)';
                } elseif ($grade_upper === 'B' || $grade_upper === 'B+' || $grade_upper === 'B-') {
                    $grade_badge_bg   = 'rgba(69, 104, 130, 0.12)';   // brand cardColor transparent bg
                    $grade_badge_text = '#234C6A';                     // brand secondary text
                    $grade_glow       = 'rgba(35, 76, 106, 0.20)';
                } else {
                    // C and below
                    $grade_badge_bg   = '#fef9c3';   // yellow bg
                    $grade_badge_text = '#ca8a04';   // yellow/amber text
                    $grade_glow       = 'rgba(234,179,8,0.30)';
                }
            ?>
            <div class="bg-white p-4 rounded-xl shadow-md hover:shadow-xl transition-all duration-300"
                style="border-left: 5px solid #456882;">

                <div class="flex flex-col md:flex-row md:items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold flex items-center" style="color: #1B3C53;">
                            <i class="fas fa-file-signature mr-2" style="color: #456882;"></i>
                            <?= htmlspecialchars($exam['exam_name']) ?>
                        </h3>
                        <div class="flex flex-wrap items-center mt-1 gap-3">
                            <span class="text-sm text-gray-600">
                                <i class="far fa-calendar mr-1"></i>
                                <?= date('M j, Y', strtotime($exam['exam_date'])) ?>
                            </span>
                            <span class="text-sm text-gray-600">
                                <i class="fas fa-book mr-1"></i>
                                <?= htmlspecialchars($exam['subject']) ?>
                            </span>

                            <!-- Exam type pill -->
                            <span class="text-xs font-bold px-3 py-1 rounded-full text-white shadow-sm"
                                style="background:#456882; box-shadow: 0 2px 8px rgba(69,104,130,0.35);">
                                <i class="fas fa-tag mr-1"></i>
                                <?= ucfirst(str_replace('_', ' ', $exam['exam_type'])) ?>
                            </span>

                            <?php if (!empty($exam['grade'])): ?>
                            <!-- Grade badge → dynamic color by grade tier -->
                            <span class="text-sm font-extrabold px-3 py-1 rounded-full"
                                style="background: <?= $grade_badge_bg ?>; color: <?= $grade_badge_text ?>; box-shadow: 0 2px 10px <?= $grade_glow ?>;">
                                Grade: <?= htmlspecialchars($exam['grade']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-2 md:mt-0 flex items-center space-x-3">
                        <span class="px-4 py-2 rounded-full text-sm font-bold <?= $is_passed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                            <?= $is_passed ? 'PASSED' : 'FAILED' ?>
                        </span>
                        <span class="text-2xl font-bold <?= $grade_color ?>">
                            <?= number_format($percentage, 1) ?>%
                        </span>
                    </div>
                </div>

                <!-- Marks Breakdown → vibrant reddish-purple focal block -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 p-4 rounded-xl" style="background:rgba(234,226,220,0.25);">

                    <!-- Total Marks Card (Sky Blue) -->
                    <div class="p-4 rounded-lg" style="background:#f0f9ff; border:1px solid #bae6fd; box-shadow:0 2px 8px rgba(186,230,253,0.2);">
                        <div class="text-sm mb-1 font-semibold" style="color:#0369a1;">Total Marks</div>
                        <div class="text-2xl font-extrabold" style="color:#0c4a6e;"><?= number_format($exam['total_marks'], 1) ?></div>
                    </div>
                    <!-- Obtained Marks Card (Violet/Purple) -->
                    <div class="p-4 rounded-lg" style="background:#f5f3ff; border:1px solid #ddd6fe; box-shadow:0 2px 8px rgba(221,214,254,0.2);">
                        <div class="text-sm mb-1 font-semibold" style="color:#6d28d9;">Obtained Marks</div>
                        <div class="text-2xl font-extrabold" style="color:#4c1d95;">
                            <?= number_format($exam['obtained_marks'], 1) ?>
                        </div>
                    </div>
                    <!-- Passing Marks Card (Emerald Green) -->
                    <div class="p-4 rounded-lg" style="background:#f0fdf4; border:1px solid #bbf7d0; box-shadow:0 2px 8px rgba(187,247,208,0.2);">
                        <div class="text-sm mb-1 font-semibold" style="color:#047857;">Passing Marks</div>
                        <div class="text-2xl font-extrabold" style="color:#064e3b;"><?= number_format($exam['passing_marks'], 1) ?></div>
                    </div>
                    <!-- Percentage Card (Amber) -->
                    <div class="p-4 rounded-lg" style="background:#fffbeb; border:1px solid #fde68a; box-shadow:0 2px 8px rgba(253,230,138,0.2);">
                        <div class="text-sm mb-1 font-semibold" style="color:#b45309;">Percentage</div>
                        <div class="text-2xl font-extrabold" style="color:#78350f;">
                            <?= number_format($percentage, 1) ?>%
                        </div>
                    </div>
                </div>

                <?php if (!empty($exam['exam_components'])): ?>
                <div class="mt-4">
                    <h4 class="font-semibold mb-3 flex items-center" style="color: #234C6A;">
                        <i class="fas fa-puzzle-piece mr-2" style="color: #456882;"></i>
                        Component Breakdown
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <?php
                        $components = explode(',', $exam['exam_components']);
                        foreach ($components as $component):
                            $component = trim($component);
                            $marks_field = "student_{$component}_marks";
                            $total_field = "{$component}_marks";
                            $obtained = $exam[$marks_field] ?: 0;
                            $total = $exam[$total_field] ?: 0;
                            $component_percentage = $total > 0 ? ($obtained / $total) * 100 : 0;
                        ?>
                        <div class="p-3 rounded-lg" style="background:#F7F5F3; border:1px solid rgba(69,104,130,0.2);">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-sm font-medium uppercase" style="color:#1B3C53;"><?= $component ?></span>
                                <span class="text-sm font-bold" style="color:<?= $component_percentage >= 60 ? '#ca8a04' : '#a16207' ?>;">
                                    <?= number_format($obtained, 1) ?>/<?= number_format($total, 1) ?>
                                </span>
                            </div>
                            <div class="w-full rounded-full h-2.5 mb-1" style="background:rgba(69,104,130,0.15);">
                                <div class="h-2.5 rounded-full"
                                    style="width: <?= $component_percentage ?>%; background:<?= $component_percentage >= 60 ? '#ca8a04' : '#a16207' ?>;"></div>
                            </div>
                            <div class="text-xs text-right" style="color:#234C6A;">
                                <?= number_format($component_percentage, 1) ?>%
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($exam['remarks'])): ?>
                <div class="mt-4 pt-4" style="border-top:1px solid rgba(69,104,130,0.2);">
                    <h4 class="font-semibold mb-2 flex items-center" style="color:#1B3C53;">
                        <i class="fas fa-comment mr-2" style="color:#456882;"></i>
                        Remarks
                    </h4>
                    <p class="p-3 rounded-lg" style="color:#234C6A; background:rgba(234,226,220,0.3);"><?= nl2br(htmlspecialchars($exam['remarks'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<!-- ========== NEW: RANKINGS SECTION ========== -->
<?php if (!empty($exam_rankings)): ?>
<div class="bg-white p-6 rounded-2xl shadow-lg mb-6" style="border-top:3px solid #1B3C53;">
    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
        <div class="p-2 rounded-lg mr-3 shadow-md" style="background:#1B3C53;">
            <i class="fas fa-trophy text-white"></i>
        </div>
        Exam Rankings & Leaderboard
    </h2>
    <p class="mb-6 text-sm" style="color:#234C6A;">See how you compare with other students in your batch. Rankings are based on obtained marks.</p>

    <div class="space-y-8">
        <?php foreach ($exam_rankings as $ranking): ?>
        <div class="rounded-xl overflow-hidden shadow-sm rank-card" style="border:1px solid rgba(69,104,130,0.2);">

            <!-- Card header -->
            <div class="px-6 py-4" style="background:#F7F5F3; border-bottom:1px solid rgba(69,104,130,0.15);">
                <div class="flex flex-wrap justify-between items-center">
                    <div>
                        <h3 class="text-lg mb-4 font-bold" style="color:#1B3C53;"><?= strtoupper(htmlspecialchars($ranking['exam_name'])) ?></h3>
                        <p class="text-sm" style="color:#456882;">
                            <i class="far fa-calendar-alt mr-1" style="color:#456882;"></i> <?= date('M d, Y', strtotime($ranking['exam_date'])) ?>
                            <span class="mx-2">•</span>
                            <i class="fas fa-chart-simple mr-1" style="color:#456882;"></i> Total: <?= $ranking['total_marks'] ?> marks
                        </p>
                    </div>

                    <!-- YOUR RANK highlight -->
                    <div class="mt-2 md:mt-0 rounded-xl px-5 py-3 text-center shadow-lg relative overflow-hidden"
                         style="background:#1B3C53; box-shadow:0 6px 20px rgba(27,60,83,0.40);">
                        <div class="absolute inset-0 opacity-10" style="background: radial-gradient(circle at 30% 20%, white, transparent 60%);"></div>
                        <div class="relative">
                            <div class="text-xs font-semibold tracking-wide uppercase" style="color:#EAE2DC;">Your Rank</div>
                            <div class="text-3xl font-extrabold text-white drop-shadow-sm">
                                #<?= $ranking['student_rank'] ?>
                            </div>
                            <div class="text-xs" style="color:#EAE2DC;">out of <?= $ranking['total_students'] ?> students</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Student's Performance Bar -->
            <div class="p-6" style="background:rgba(234,226,220,0.2); border-bottom:1px solid rgba(69,104,130,0.12);">
                <div class="flex flex-wrap justify-between items-center mb-2">
                    <div class="flex items-center">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center mr-3 shadow-sm"
                             style="background:#456882;">
                            <i class="fas fa-user-graduate text-white text-sm"></i>
                        </div>
                        <!-- YOUR NAME highlighted -->
                        <span class="font-bold text-base px-3 py-1 rounded-lg"
                              style="background:rgba(69,104,130,0.15); color:#1B3C53;">
                            <?= htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?: 'Your Performance' ?>
                        </span>
                    </div>
                    <div class="text-right">
                        <span class="font-bold text-lg" style="color:#1B3C53;"><?= $ranking['student_marks'] ?> / <?= $ranking['total_marks'] ?></span>
                        <span class="text-sm ml-1" style="color:#456882;"> (<?= $ranking['student_percentage'] ?>%)</span>
                    </div>
                </div>
                <div class="w-full rounded-full h-3 shadow-inner overflow-hidden" style="background:rgba(255,255,255,0.7);">
                    <div class="h-3 rounded-full transition-all"
                         style="width: <?= ($ranking['student_marks'] / $ranking['total_marks']) * 100 ?>%; background:#456882;"></div>
                </div>
            </div>

            <!-- Top Rankers List -->
            <div class="p-6">
                <h4 class="font-semibold text-gray-700 mb-3 flex items-center">
                    <i class="fas fa-crown text-amber-500 mr-2"></i>
                    Top 5 Rankers
                </h4>
                <div class="space-y-2">
                    <?php
                    $rank_counter = 1;
                    foreach ($ranking['top_rankers'] as $ranker):
                        $is_current_student = ($ranker['student_id'] == $student['student_id']);

                        // Medal styling per position
                        if ($rank_counter == 1) {
                            $medal_bg = 'linear-gradient(135deg, #fbbf24, #f59e0b)';
                            $medal_icon = 'fa-crown';
                            $rank_text_color = '#d97706';
                            $row_bg = 'bg-gradient-to-r from-amber-50 to-yellow-50';
                        } elseif ($rank_counter == 2) {
                            $medal_bg = 'linear-gradient(135deg, #cbd5e1, #94a3b8)';
                            $medal_icon = 'fa-medal';
                            $rank_text_color = '#64748b';
                            $row_bg = 'bg-slate-50';
                        } elseif ($rank_counter == 3) {
                            $medal_bg = 'linear-gradient(135deg, #fcd9a8, #d97757)';
                            $medal_icon = 'fa-medal';
                            $rank_text_color = '#b45309';
                            $row_bg = 'bg-orange-50';
                        } else {
                            $medal_bg = 'linear-gradient(135deg, #e2e8f0, #cbd5e1)';
                            $medal_icon = 'fa-user';
                            $rank_text_color = '#64748b';
                            $row_bg = 'bg-gray-50';
                        }
                    ?>
                    <div class="flex items-center justify-between p-3 rounded-lg transition-all hover:shadow-md hover:scale-[1.01]"
                         style="<?= $is_current_student
                                ? 'background:rgba(69,104,130,0.12); border-left:4px solid #456882; box-shadow:0 2px 10px rgba(69,104,130,0.2);'
                                : 'background:rgba(247,245,243,0.8);' ?>">

                        <div class="flex items-center space-x-3">
                            <div class="w-9 h-9 rounded-full flex items-center justify-center text-white font-bold shadow-sm"
                                 style="background: <?= $medal_bg ?>;">
                                <?php if ($rank_counter <= 3): ?>
                                    <i class="fas <?= $medal_icon ?> text-sm"></i>
                                <?php else: ?>
                                    <span class="text-white text-sm"><?= $rank_counter ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="font-medium text-gray-800">
                                    <?= htmlspecialchars($ranker['first_name'] ?? 'Student') ?> <?= htmlspecialchars($ranker['last_name'] ?? '') ?>
                                    <?php if ($is_current_student): ?>
                                        <span class="ml-2 text-xs font-bold px-3 py-1 text-white shadow-sm"
                                              <div style="background:#456882; display:inline-block; border-radius:4px;">
                                            ✦ You
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <div class="text-xs text-gray-500">
                                    <?= $ranker['obtained_marks'] ?> / <?= $ranking['total_marks'] ?> marks (<?= $ranker['percentage'] ?>%)
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-lg font-extrabold" style="color: <?= $rank_text_color ?>;">
                                #<?= $ranker['rank'] ?>
                            </span>
                        </div>
                    </div>
                    <?php
                    $rank_counter++;
                    endforeach;
                    ?>
                </div>
                <?php if ($ranking['student_rank'] > 5): ?>
                <div class="mt-4 pt-3 text-center" style="border-top:1px solid rgba(69,104,130,0.15);">
                    <p class="text-sm" style="color:#456882;">
                        Your rank is <span class="font-bold" style="color:#1B3C53">#<?= $ranking['student_rank'] ?></span> out of <?= $ranking['total_students'] ?> students.
                        <?php if ($ranking['student_rank'] > 1): ?>
                            <span class="font-semibold" style="color:#234C6A">Keep working hard to climb the leaderboard!</span>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
                
 
                
                <!-- Exam Type Performance -->
                <?php if (!empty($exam_type_performance)): ?>
                <div class="bg-white p-6 rounded-2xl shadow-lg" style="border-top:3px solid #456882;">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-chart-simple" style="color:#456882;"></i>
                        </div>
                        Performance by Exam Type
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-<?= min(count($exam_type_performance), 4) ?> gap-4">
                        <?php foreach ($exam_type_performance as $type => $data): 
                            $type_percentage = $data['total'] > 0 ? ($data['obtained'] / $data['total']) * 100 : 0;
                        ?>
                        <div class="p-4 rounded-lg" style="background:rgba(234,226,220,0.25); border:1px solid rgba(69,104,130,0.2);">
                            <div class="flex justify-between items-start mb-2">
                                <h4 class="font-semibold capitalize" style="color:#1B3C53;"><?= str_replace('_', ' ', $type) ?></h4>
                                <span class="text-xs px-2 py-1 rounded-full" style="background:rgba(69,104,130,0.2); color:#234C6A;"><?= $data['count'] ?> exam(s)</span>
                            </div>
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-sm" style="color:#234C6A;">Average Score</span>
                                <span class="font-bold" style="color:#456882;"><?= number_format($type_percentage, 1) ?>%</span>
                            </div>
                            <div class="w-full rounded-full h-2 mb-1" style="background:rgba(69,104,130,0.2);">
                                <div class="h-2 rounded-full" style="width: <?= $type_percentage ?>%; background:#456882;"></div>
                            </div>
                            <div class="text-xs" style="color:#234C6A;">
                                <?= $data['obtained'] ?>/<?= $data['total'] ?> marks
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="p-8 rounded-2xl shadow-lg text-center" style="background:#F7F5F3;">
                    <div class="inline-block p-6 rounded-full mb-4" style="background:rgba(69,104,130,0.15);">
                        <i class="fas fa-file-alt text-4xl" style="color:#456882;"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2" style="color:#1B3C53;">No Exam Records Found</h3>
                    <p style="color:#234C6A;">No exam data is available for the selected date range in this batch.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Feedback Tab -->
            <div class="tab-content <?= $report_view == 'feedback' ? 'active' : '' ?>" id="feedback-tab">
                <?php if (!empty($feedback_data)): ?>
                <!-- Feedback Charts -->
                <?php if (isset($chart_data['feedback_details'])): ?>
                <div class="chart-wrapper">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-chart-line" style="color:#456882;"></i>
                        </div>
                        Feedback Ratings Over Time
                    </h2>
                    
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="feedbackDetailsChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Feedback Cards -->
                <div class="bg-white p-6 rounded-2xl shadow-lg" style="border-top:3px solid #1B3C53;">
                    <h2 class="text-xl font-bold mb-4 flex items-center" style="color:#1B3C53;">
                        <div class="p-2 rounded-lg mr-3" style="background:rgba(69,104,130,0.15);">
                            <i class="fas fa-comment-dots" style="color:#456882;"></i>
                        </div>
                        Your Feedback Entries
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($feedback_data as $feedback): ?>
                        <div class="rounded-xl p-6 shadow-lg hover:shadow-xl transition-all" style="background:#FAF8F6; border: 1px solid rgba(69, 104, 130, 0.2);">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <div class="flex items-center mb-2">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium mr-2" style="background:rgba(69,104,130,0.2); color:#1B3C53;">
                                            <?= htmlspecialchars($feedback['batch_name'] ?? $feedback['batch_id'] ?? 'N/A') ?>
                                        </span>
                                        <span class="text-sm" style="color:#234C6A;">
                                            <i class="far fa-calendar-alt mr-1"></i> <?= date('M d, Y', strtotime($feedback['date'])) ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center">
                                        <?php $avg_rating = ($feedback['class_rating'] + $feedback['assignment_understanding'] + $feedback['practical_understanding']) / 3; ?>
                                        <span class="text-2xl font-bold mr-2" style="color:#1B3C53;"><?= number_format($avg_rating, 1) ?></span>
                                        <span style="color:#456882;">/5</span>
                                    </div>
                                </div>
                                <div class="bg-white p-3 rounded-xl shadow-sm">
                                    <i class="fas fa-star text-yellow-400 text-xl"></i>
                                </div>
                            </div>

                            <!-- Ratings Grid -->
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <p class="text-xs mb-1" style="color:#234C6A;">Class Rating</p>
                                    <div class="flex items-center">
                                        <div class="w-full rounded-full h-2 mr-2" style="background:rgba(247,245,243,0.7); border: 1px solid rgba(69,104,130,0.25);">
                                            <div class="h-full rounded-full" style="width: <?= ($feedback['class_rating'] / 5) * 100 ?>%; background:#456882;"></div>
                                        </div>
                                        <span class="text-sm font-medium" style="color:#1B3C53;"><?= $feedback['class_rating'] ?></span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs mb-1" style="color:#234C6A;">Assignments</p>
                                    <div class="flex items-center">
                                        <div class="w-full rounded-full h-2 mr-2" style="background:rgba(247,245,243,0.7); border: 1px solid rgba(69,104,130,0.25);">
                                            <div class="h-full rounded-full" style="width: <?= ($feedback['assignment_understanding'] / 5) * 100 ?>%; background:#1B3C53;"></div>
                                        </div>
                                        <span class="text-sm font-medium" style="color:#1B3C53;"><?= $feedback['assignment_understanding'] ?></span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs mb-1" style="color:#234C6A;">Practical</p>
                                    <div class="flex items-center">
                                        <div class="w-full rounded-full h-2 mr-2" style="background:rgba(247,245,243,0.7); border: 1px solid rgba(69,104,130,0.25);">
                                            <div class="h-full rounded-full" style="width: <?= ($feedback['practical_understanding'] / 5) * 100 ?>%; background:#234C6A;"></div>
                                        </div>
                                        <span class="text-sm font-medium" style="color:#1B3C53;"><?= $feedback['practical_understanding'] ?></span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs mb-1" style="color:#234C6A;">Satisfaction</p>
                                    <div class="flex items-center">
                                        <div class="w-full rounded-full h-2 mr-2" style="background:rgba(247,245,243,0.7); border: 1px solid rgba(69,104,130,0.25);">
                                            <div class="h-full rounded-full" style="width: <?= ($feedback['satisfied'] / 5) * 100 ?>%; background:#456882;"></div>
                                        </div>
                                        <span class="text-sm font-medium" style="color:#1B3C53;"><?= $feedback['satisfied'] ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Feedback Text -->
                            <?php if (!empty($feedback['suggestions']) || !empty($feedback['feedback_text'])): ?>
                            <div class="rounded-lg p-4 mt-2" style="background:rgba(247,245,243,0.6);">
                                <?php if (!empty($feedback['suggestions'])): ?>
                                <p class="text-sm mb-2" style="color:#234C6A;">
                                    <span class="font-medium" style="color:#1B3C53;">Suggestions:</span> <?= htmlspecialchars($feedback['suggestions']) ?>
                                </p>
                                <?php endif; ?>
                                <?php if (!empty($feedback['feedback_text'])): ?>
                                <p class="text-sm" style="color:#234C6A;">
                                    <span class="font-medium" style="color:#1B3C53;">Comments:</span> <?= htmlspecialchars($feedback['feedback_text']) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="mt-4 flex items-center">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $feedback['is_regular'] === 'Yes' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <i class="fas fa-<?= $feedback['is_regular'] === 'Yes' ? 'check' : 'times' ?>-circle mr-1"></i>
                                    Regular: <?= $feedback['is_regular'] ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="p-8 rounded-2xl shadow-lg text-center" style="background:#F7F5F3;">
                    <div class="inline-block p-6 rounded-full mb-4" style="background:rgba(69,104,130,0.15);">
                        <i class="fas fa-comment-alt text-4xl" style="color:#456882;"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2" style="color:#1B3C53;">No Feedback Records Found</h3>
                    <p style="color:#234C6A;">No feedback data is available for the selected date range in this batch.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <!-- No Batch Selected Message -->
        <div class="p-8 rounded-2xl shadow-lg text-center" style="background:#F7F5F3;">
            <div class="inline-block p-6 rounded-full mb-4" style="background:rgba(69,104,130,0.15);">
                <i class="fas fa-exclamation-circle text-4xl" style="color:#456882;"></i>
            </div>
            <h3 class="text-xl font-bold mb-2" style="color:#1B3C53;">No Batches Found</h3>
            <p style="color:#234C6A;">You are not currently enrolled in any batches.</p>
            <?php if (count($all_batches) > 0): ?>
            <div class="mt-4">
                <a href="?batch_index=0&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&view=<?= $report_view ?>" 
                   class="inline-block text-white font-medium py-2 px-6 rounded-lg transition-all duration-300" style="background:#1B3C53;" onmouseover="this.style.background='#234C6A'" onmouseout="this.style.background='#1B3C53'">
                    View Your First Batch
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleMobileMenu() {
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileMenuContent = mobileMenu.querySelector('div');
    
    if (mobileMenu.classList.contains('hidden')) {
        mobileMenu.classList.remove('hidden');
        setTimeout(() => {
            mobileMenuContent.classList.remove('-translate-x-full');
        }, 10);
    } else {
        mobileMenuContent.classList.add('-translate-x-full');
        setTimeout(() => {
            mobileMenu.classList.add('hidden');
        }, 300);
    }
}

document.getElementById('mobileMenu').addEventListener('click', function(e) {
    if (e.target.id === 'mobileMenu') {
        toggleMobileMenu();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const mobileMenu = document.getElementById('mobileMenu');
        if (!mobileMenu.classList.contains('hidden')) {
            toggleMobileMenu();
        }
    }
});

// Tab switching functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            
            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('view', tabId);
            window.history.pushState({}, '', url);
            
            // Update active tab
            tabBtns.forEach(b => {
                if (b.getAttribute('data-tab') === tabId) {
                    b.style.background = '#456882';
                    b.style.color = '#fff';
                } else {
                    b.style.background = '#EAE2DC';
                    b.style.color = '#1B3C53';
                }
            });
            
            // Show active content
            tabContents.forEach(content => {
                if (content.id === `${tabId}-tab`) {
                    content.classList.add('active');
                } else {
                    content.classList.remove('active');
                }
            });
        });
    });
    
    // Add staggered animations for table rows
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, index) => {
        row.style.animationDelay = `${index * 0.05}s`;
    });
    
    // Animate progress bars — select all inline-styled progress bars in the new theme
    const progressBars = document.querySelectorAll('[style*="background:#456882"], [style*="background:#1B3C53"], [style*="background:#234C6A"]');
    progressBars.forEach(bar => {
        if (bar.style.width) {
            const width = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => {
                bar.style.width = width;
            }, 100);
        }
    });
    
    // Initialize charts
    initializeCharts();
});

function initializeCharts() {
    // Attendance Distribution Chart
    // Theme palette — matches the dashboard design system
const VIBRANT_PALETTE = ['#4ade80', '#fb7185']
<?php if (isset($chart_data['attendance'])): ?>
const attendanceCtx = document.getElementById('attendanceChart');
if (attendanceCtx) {
    new Chart(attendanceCtx, {
        type: 'pie',
        data: {
            labels: <?= json_encode($chart_data['attendance']['labels']) ?>,
            datasets: [{
                data: <?= json_encode($chart_data['attendance']['data']) ?>,
                backgroundColor: VIBRANT_PALETTE,
                hoverBackgroundColor:['#4edd2d','rgb(242, 19, 19)'],
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverOffset: 12,
                hoverBorderWidth: 4,
                hoverBorderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { animateRotate: true, animateScale: true, duration: 800 },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        font: { size: 12, weight: '600' },
                        color: '#374151',
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: '#1f2937',
                    titleColor: '#5eead4',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 10,
                    titleFont: { weight: 'bold' },
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

// Exam Performance Chart
<?php if (isset($chart_data['exam_performance'])): ?>
const examCtx = document.getElementById('examPerformanceChart');
if (examCtx) {
    const ctx2d = examCtx.getContext('2d');

    // Theme palette bar fills
    const gradientPairs = [
        ['#9878eb', '#0ea5e9'], // Sky blue (61% -> 54% luma)
        ['#34d399', '#10b981'], // Teal/Green (71% -> 50.2% luma)
        ['#fb923c', '#f97316'], // Orange (65% -> 56% luma)
        ['#a78bfa', '#8b5cf6'], // Purple (63% -> 50.5% luma)
        ['#fb7185', '#fda4af']  // Rose/Pink (61% -> 78% luma)
    ];

    const examDatasets = <?= json_encode($chart_data['exam_performance']['datasets']) ?>;
    examDatasets.forEach((ds, i) => {
        const [start, end] = gradientPairs[i % gradientPairs.length];
        const gradient = ctx2d.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, start);
        gradient.addColorStop(1, end);
        ds.backgroundColor = gradient;
        ds.hoverBackgroundColor = start;
        ds.borderRadius = 6;
        ds.borderSkipped = false;
    });

    new Chart(examCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_data['exam_performance']['labels']) ?>,
            datasets: examDatasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Marks', color: '#374151', font: { weight: '600' } },
                    grid: { color: 'rgba(45, 212, 191, 0.12)' },
                    ticks: { color: '#6b7280', maxRotation: 0, minRotation: 0 }
                },
                x: {
                    ticks: { maxRotation: 0, minRotation: 0, font: { size: 10 }, color: '#6b7280' },
                    grid: { display: false }
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 12, weight: '600' }, color: '#374151', usePointStyle: true, pointStyle: 'rectRounded' }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: '#1f2937',
                    titleColor: '#fbbf24',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 10
                },
                annotation: {
                    annotations: {
                        passingMarker: {
                            type: 'point',
                            yValue: 40,          // passing mark
                            xValue: 1,           // place it under second bar (adjust as needed)
                            backgroundColor: 'red',
                            radius: 0,
                            label: {
                                enabled: true,
                                content: '^',
                                color: 'red',
                                font: { weight: 'bold', size: 14 }
                            }
                        }
                    }
                }
            }
        }
    });
}



    // Theme gradient pairs, cycled per dataset
    const gradientPairs2 = [
        ['#38bdf8', '#0ea5e9'],
        ['#34d399', '#10b981'],
        ['#fb923c', '#f97316'],
        ['#a78bfa', '#8b5cf6'],
        ['#fb7185', '#fda4af']
    ];

    const examDatasets2 = <?= json_encode($chart_data['exam_performance']['datasets']) ?>;
    examDatasets2.forEach((ds, i) => {
        const [start2, end2] = gradientPairs2[i % gradientPairs2.length];
        ds.backgroundColor = start2;
        ds.hoverBackgroundColor = end2;
        ds.borderRadius = 6;
        ds.borderSkipped = false;
        ds.hoverOffset = 12;
    });

    new Chart(examCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_data['exam_performance']['labels']) ?>,
            datasets: examDatasets2
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Marks', color: '#374151', font: { weight: '600' } },
                    grid: { color: 'rgba(45, 212, 191, 0.12)' },
                    ticks: { color: '#6b7280' ,
                    maxrotation : 0,
                    minrotation : 0,

                    }
                },
                x: {
                    ticks: { maxRotation:0, minRotation: 0, font: { size: 12 }, color: '#6b7280' },
                    grid: { display: false }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { font: { size: 12, weight: '600' }, color: '#374151', usePointStyle: true, pointStyle: 'rectRounded' }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: '#1f2937',
                    titleColor: '#fbbf24',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 10
                }
            }
        }
    });
}
<?php endif; ?>
    
    // Monthly Attendance Chart
    <?php if (isset($chart_data['monthly_attendance'])): ?>
    const monthlyCtx = document.getElementById('monthlyAttendanceChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_data['monthly_attendance']['labels']) ?>,
                datasets: <?= json_encode($chart_data['monthly_attendance']['datasets']) ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        title: { display: true, text: 'Number of Classes' },
                        grid: { color: 'rgba(0, 0, 0, 0.05)' }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 12 } }
                    },
                    tooltip: { mode: 'index', intersect: false }
                }
            }
        });
    }
    <?php endif; ?>
    
    // Feedback Details Chart
    <?php if (isset($chart_data['feedback_details'])): ?>
    const feedbackDetailsCtx = document.getElementById('feedbackDetailsChart');
    if (feedbackDetailsCtx) {
        new Chart(feedbackDetailsCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_data['feedback_details']['labels']) ?>,
                datasets: <?= json_encode($chart_data['feedback_details']['datasets']) ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 5, grid: { color: 'rgba(0, 0, 0, 0.05)' } },
                    x: { grid: { display: false } }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 12 } }
                    }
                }
            }
        });
    }
    <?php endif; ?>

</script>

<?php include '../footer.php'; ?>
</body>
</html>
