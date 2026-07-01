<?php
require_once '../db_connection.php';
session_start();

// Check user role
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
$academic_year = isset($_GET['academic_year']) ? intval($_GET['academic_year']) : '';

// Get batch details
$batch = null;
if (!empty($batch_id)) {
    $stmt = $db->prepare("SELECT * FROM batches WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get students in the batch
$students = [];
if (!empty($batch_id)) {
    $stmt = $db->prepare("SELECT student_id, first_name, last_name FROM students WHERE batch_name = ? ORDER BY first_name, last_name");
    $stmt->execute([$batch_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get exams for the batch
$exams = [];
if (!empty($batch_id)) {
    $sql = "SELECT exam_id, exam_name, subject, exam_date, total_marks, exam_components, mcq_marks, project_marks, viva_marks 
            FROM exams WHERE batch_id = ?";
    $params = [$batch_id];
    
    if (!empty($academic_year)) {
        $sql .= " AND YEAR(exam_date) = ?";
        $params[] = $academic_year;
    }
    
    $sql .= " ORDER BY exam_date ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get results for all students
$all_results = [];
if (!empty($batch_id) && count($exams) > 0) {
    $exam_ids = array_column($exams, 'exam_id');
    $placeholders = implode(',', array_fill(0, count($exam_ids), '?'));
    
    foreach ($students as $student) {
        $sql = "SELECT er.exam_id, er.obtained_marks, er.grade, e.total_marks, er.mcq_marks, er.project_marks, er.viva_marks 
                FROM exam_results er 
                JOIN exams e ON er.exam_id = e.exam_id 
                WHERE er.student_id = ? AND er.exam_id IN ($placeholders)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$student['student_id']], $exam_ids));
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $student_results = [];
        $total_marks = 0;
        $obtained_marks = 0;
        $exam_count = 0;
        
        foreach ($exams as $exam) {
            $result = null;
            foreach ($results as $r) {
                if ($r['exam_id'] == $exam['exam_id']) {
                    $result = $r;
                    break;
                }
            }
            
            $student_results[$exam['exam_id']] = $result;
            
            if ($result) {
                $total_marks += $exam['total_marks'];
                $obtained_marks += $result['obtained_marks'];
                $exam_count++;
            }
        }
        
        $overall_percentage = $exam_count > 0 ? ($obtained_marks / $total_marks) * 100 : 0;
        
        $all_results[$student['student_id']] = [
            'student' => $student,
            'results' => $student_results,
            'overall_percentage' => $overall_percentage,
            'exam_count' => $exam_count
        ];
    }
}

// Function to calculate grade
function calculateGrade($obtained, $total) {
    if ($total == 0) return '-';
    
    $percentage = ($obtained / $total) * 100;
    
    if ($percentage >= 90) return 'A+';
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C';
    if ($percentage >= 40) return 'D';
    return 'F';
}

// Function to determine if exam has component marks
function hasComponentMarks($exam) {
    return !empty($exam['exam_components']) && 
           ($exam['mcq_marks'] > 0 || $exam['project_marks'] > 0 || $exam['viva_marks'] > 0);
}

// Function to get component columns count
function getComponentColumnsCount($exam) {
    if (!hasComponentMarks($exam)) return 0;
    
    $count = 0;
    $components = explode(',', $exam['exam_components']);
    
    if (in_array('mcq', $components) && $exam['mcq_marks'] > 0) $count++;
    if (in_array('project', $components) && $exam['project_marks'] > 0) $count++;
    if (in_array('viva', $components) && $exam['viva_marks'] > 0) $count++;
    
    return $count;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Overall Report - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1B3C53;
            --secondary-color: #234C6A;
            --accent-color: #456882;
            --neutral-color: #D2C1B6;
            --light-bg: #f4f6f9;
            --card-shadow: 0 2px 12px rgba(27, 60, 83, 0.08);
            --hover-shadow: 0 8px 24px rgba(27, 60, 83, 0.12);
            --border-color: #e8ecef;
            --text-primary: #1B3C53;
            --text-secondary: #4a5b6b;
        }
        
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
            color: var(--text-primary);
            line-height: 1.6;
        }
        
        .main-content {
            margin-left: 0;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }
        
        @media (min-width: 768px) {
            .main-content {
                margin-left: 256px;
            }
        }
        
        .card {
            margin-bottom: 24px;
            border: none;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            background: #ffffff;
        }
        
        .card:hover {
            box-shadow: var(--hover-shadow);
        }
        
        .card-header {
            background-color: #ffffff;
            border-bottom: 2px solid var(--neutral-color);
            padding: 16px 24px;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .table-responsive {
            overflow-x: auto;
            border-radius: 10px;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        
        .table th {
            background-color: var(--primary-color);
            color: #ffffff;
            font-weight: 600;
            border: none;
            padding: 12px 10px;
            text-align: center;
            vertical-align: middle;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
        }
        
        .table td {
            padding: 10px 8px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            background-color: #ffffff;
        }
        
        .table tbody tr {
            transition: background-color 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: rgba(27, 60, 83, 0.03);
        }
        
        .grade-A { background-color: rgba(27, 60, 83, 0.08); }
        .grade-B { background-color: rgba(35, 76, 106, 0.08); }
        .grade-C { background-color: rgba(69, 104, 130, 0.08); }
        .grade-D { background-color: rgba(210, 193, 182, 0.25); }
        .grade-F { background-color: rgba(220, 53, 69, 0.08); }
        
        .stats-card {
            text-align: center;
            padding: 20px 15px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2);
            transition: transform 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-3px);
        }
        
        .stats-number {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }
        
        .stats-label {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 400;
        }
        
        .report-header {
            background: linear-gradient(135deg, #ffffff, #f8f9fb);
            padding: 24px 28px;
            border-radius: 12px;
            margin-bottom: 28px;
            border-left: 5px solid var(--primary-color);
            box-shadow: var(--card-shadow);
        }
        
        .exam-header {
            background: linear-gradient(135deg, var(--secondary-color), var(--accent-color));
            font-weight: 600;
            color: white;
        }
        
        .component-cell {
            font-size: 0.8rem;
            text-align: center;
            background-color: rgba(210, 193, 182, 0.1);
        }
        
        .component-label {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.9);
            background-color: rgba(0, 0, 0, 0.12);
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #ffffff;
            letter-spacing: 0.3px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(27, 60, 83, 0.3);
            background: linear-gradient(135deg, var(--secondary-color), var(--accent-color));
            color: #ffffff;
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
            border-radius: 8px;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
            background: transparent;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2);
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 16px;
            border: 2px solid var(--border-color);
            transition: all 0.3s ease;
            font-size: 0.95rem;
            background-color: #ffffff;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(27, 60, 83, 0.1);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
            margin-bottom: 6px;
        }
        
        .page-title {
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 4px;
            font-size: 1.8rem;
            letter-spacing: -0.5px;
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            font-size: 1rem;
            font-weight: 400;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            box-shadow: var(--card-shadow);
            padding: 16px 20px;
        }
        
        .alert-info {
            background-color: rgba(27, 60, 83, 0.06);
            color: var(--primary-color);
            border-left: 4px solid var(--primary-color);
        }
        
        .student-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .student-id {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .overall-score {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--primary-color);
        }
        
        .print-btn {
            background: linear-gradient(135deg, var(--accent-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
            letter-spacing: 0.3px;
        }
        
        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(35, 76, 106, 0.3);
            color: #ffffff;
        }
        
        .filters-card {
            background: #ffffff;
        }
        
        .filters-card .card-body {
            padding: 24px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .table th {
                background-color: #f8f9fa !important;
                color: #333 !important;
            }
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 767px) {
            .main-content {
                padding: 12px;
            }
            
            .page-title {
                font-size: 1.4rem;
            }
            
            .page-subtitle {
                font-size: 0.9rem;
            }
            
            .card-header {
                padding: 14px 16px;
            }
            
            .card-body {
                padding: 16px;
            }
            
            .report-header {
                padding: 16px 18px;
                margin-bottom: 20px;
            }
            
            .report-header h4 {
                font-size: 1.1rem;
            }
            
            .stats-card {
                padding: 14px 10px;
                margin-bottom: 10px;
            }
            
            .stats-number {
                font-size: 22px;
            }
            
            .stats-label {
                font-size: 12px;
            }
            
            .table {
                font-size: 0.78rem;
            }
            
            .table th,
            .table td {
                padding: 6px 4px;
                white-space: nowrap;
            }
            
            .table th {
                font-size: 0.7rem;
                padding: 8px 4px;
            }
            
            .student-name {
                font-size: 0.8rem;
            }
            
            .student-id {
                font-size: 0.7rem;
            }
            
            .overall-score {
                font-size: 0.85rem;
            }
            
            .btn-primary,
            .btn-outline-primary,
            .print-btn {
                padding: 8px 16px;
                font-size: 0.85rem;
                width: 100%;
                margin-bottom: 8px;
            }
            
            .btn-outline-primary {
                margin-right: 0 !important;
            }
            
            .d-flex.justify-content-between {
                flex-direction: column;
                align-items: stretch !important;
            }
            
            .d-flex.justify-content-between > div:last-child {
                display: flex;
                flex-direction: column;
                width: 100%;
            }
            
            .d-flex.justify-content-between > div:last-child a,
            .d-flex.justify-content-between > div:last-child button {
                width: 100%;
                margin-right: 0 !important;
                margin-bottom: 8px;
            }
            
            .filters-card .card-body {
                padding: 16px;
            }
            
            .form-control, .form-select {
                padding: 8px 12px;
                font-size: 0.9rem;
            }
            
            .component-cell {
                font-size: 0.7rem;
            }
            
            .component-label {
                font-size: 0.6rem;
            }
            
            .alert {
                padding: 12px 16px;
                font-size: 0.9rem;
            }
        }
        
        @media (min-width: 768px) and (max-width: 1024px) {
            .main-content {
                padding: 16px 20px;
            }
            
            .table {
                font-size: 0.82rem;
            }
            
            .table th,
            .table td {
                padding: 8px 6px;
            }
            
            .page-title {
                font-size: 1.6rem;
            }
            
            .stats-number {
                font-size: 24px;
            }
        }
        
        @media (min-width: 1025px) {
            .table {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- Include the sidebar -->
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <!-- Main content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center py-3 mb-4">
            <div>
                <h1 class="page-title">Student Overall Performance Report</h1>
                <p class="page-subtitle">Track and analyze student performance across all exams</p>
            </div>
            <div class="no-print">
                <a href="exams.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Exams
                </a>
                <button class="btn print-btn" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> Print Report
                </button>
            </div>
        </div>

        <!-- Report Filters -->
        <div class="card mb-4 filters-card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4 col-12">
                        <label for="batch_id" class="form-label">Select Batch</label>
                        <select class="form-select" id="batch_id" name="batch_id" required>
                            <option value="">Select Batch</option>
                            <?php
                            $batches_list = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($batches_list as $b): ?>
                                <option value="<?php echo $b['batch_id']; ?>" <?php echo $batch_id == $b['batch_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['batch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-12">
                        <label for="academic_year" class="form-label">Academic Year</label>
                        <select class="form-select" id="academic_year" name="academic_year">
                            <option value="">All Years</option>
                            <?php
                            $current_year = date('Y');
                            for ($y = $current_year; $y >= $current_year - 5; $y--) {
                                $next_year = $y + 1;
                                $selected = ($academic_year == $y) ? 'selected' : '';
                                echo "<option value='$y' $selected>$y-$next_year</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-12 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-1"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($batch_id) && $batch): ?>
            <!-- Report Header -->
            <div class="report-header mb-4">
                <div class="row">
                    <div class="col-md-8 col-12">
                        <h4><?php echo htmlspecialchars($batch['batch_name']); ?> - Overall Performance Report</h4>
                        <p class="mb-1"><strong>Academic Year:</strong> <?php echo !empty($academic_year) ? $academic_year . '-' . ($academic_year + 1) : 'All Years'; ?></p>
                        <p class="mb-1"><strong>Total Students:</strong> <?php echo count($students); ?></p>
                        <p class="mb-0"><strong>Total Exams:</strong> <?php echo count($exams); ?></p>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo count($students); ?></div>
                                    <div class="stats-label">Students</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo count($exams); ?></div>
                                    <div class="stats-label">Exams</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (count($exams) > 0): ?>
                <!-- Results Table -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-table me-2"></i>Student Performance Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th rowspan="2" class="align-middle">Student Name</th>
                                        <th rowspan="2" class="align-middle">Student ID</th>
                                        <?php foreach ($exams as $exam): 
                                            $component_cols = getComponentColumnsCount($exam);
                                            $total_cols = 3 + $component_cols; // Marks, %, Grade + component marks
                                        ?>
                                            <th colspan="<?php echo $total_cols; ?>" class="text-center exam-header">
                                                <?php echo htmlspecialchars($exam['exam_name']); ?><br>
                                                <small><?php echo date('d M Y', strtotime($exam['exam_date'])); ?></small>
                                            </th>
                                        <?php endforeach; ?>
                                        <th rowspan="2" class="text-center align-middle">Overall<br>Percentage</th>
                                        <th rowspan="2" class="text-center align-middle">Overall<br>Grade</th>
                                    </tr>
                                    <tr>
                                        <?php foreach ($exams as $exam): ?>
                                            <th class="text-center">Marks</th>
                                            
                                            <!-- Component marks headers -->
                                            <?php if (hasComponentMarks($exam)): 
                                                $components = explode(',', $exam['exam_components']);
                                            ?>
                                                <?php if (in_array('mcq', $components) && $exam['mcq_marks'] > 0): ?>
                                                    <th class="text-center component-label">MCQ</th>
                                                <?php endif; ?>
                                                <?php if (in_array('project', $components) && $exam['project_marks'] > 0): ?>
                                                    <th class="text-center component-label">Project</th>
                                                <?php endif; ?>
                                                <?php if (in_array('viva', $components) && $exam['viva_marks'] > 0): ?>
                                                    <th class="text-center component-label">Viva</th>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <th class="text-center">%</th>
                                            <th class="text-center">Grade</th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($students) > 0): ?>
                                        <?php foreach ($all_results as $student_id => $data): ?>
                                            <?php
                                            $student = $data['student'];
                                            $results = $data['results'];
                                            $overall_percentage = $data['overall_percentage'];
                                            $overall_grade = calculateGrade($overall_percentage, 100);
                                            ?>
                                            <tr>
                                                <td class="student-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                                <td class="student-id"><?php echo htmlspecialchars($student['student_id']); ?></td>
                                                
                                                <?php foreach ($exams as $exam): ?>
                                                    <?php
                                                    $result = $results[$exam['exam_id']];
                                                    $has_result = !is_null($result);
                                                    $marks = $has_result ? $result['obtained_marks'] : '-';
                                                    $percentage = $has_result ? ($result['obtained_marks'] / $exam['total_marks']) * 100 : '-';
                                                    $grade = $has_result ? $result['grade'] : '-';
                                                    $grade_class = $has_result ? 'grade-' . substr($grade, 0, 1) : '';
                                                    ?>
                                                    <td class="text-center"><?php echo $marks; ?></td>
                                                    
                                                    <!-- Component marks data -->
                                                    <?php if (hasComponentMarks($exam)): 
                                                        $components = explode(',', $exam['exam_components']);
                                                    ?>
                                                        <?php if (in_array('mcq', $components) && $exam['mcq_marks'] > 0): ?>
                                                            <td class="text-center component-cell">
                                                                <?php echo $has_result && isset($result['mcq_marks']) ? $result['mcq_marks'] : '-'; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if (in_array('project', $components) && $exam['project_marks'] > 0): ?>
                                                            <td class="text-center component-cell">
                                                                <?php echo $has_result && isset($result['project_marks']) ? $result['project_marks'] : '-'; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if (in_array('viva', $components) && $exam['viva_marks'] > 0): ?>
                                                            <td class="text-center component-cell">
                                                                <?php echo $has_result && isset($result['viva_marks']) ? $result['viva_marks'] : '-'; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <td class="text-center"><?php echo is_numeric($percentage) ? number_format($percentage, 1) . '%' : $percentage; ?></td>
                                                    <td class="text-center <?php echo $grade_class; ?>"><?php echo $grade; ?></td>
                                                <?php endforeach; ?>
                                                
                                                <td class="text-center fw-bold overall-score">
                                                    <?php echo number_format($overall_percentage, 1); ?>%
                                                </td>
                                                <td class="text-center fw-bold grade-<?php echo substr($overall_grade, 0, 1); ?>">
                                                    <?php echo $overall_grade; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php 
                                                $total_cols = 2; // Student name and ID
                                                foreach ($exams as $exam) {
                                                    $total_cols += 3 + getComponentColumnsCount($exam);
                                                }
                                                $total_cols += 2; // Overall percentage and grade
                                                echo $total_cols; 
                                            ?>" class="text-center py-4">
                                                <div class="py-3">
                                                    <i class="fas fa-users fa-2x text-muted mb-3"></i>
                                                    <p class="mb-0">No students found in this batch.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No exams found for the selected criteria.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> Please select a batch to generate the report.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle sidebar toggle for mobile
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('-translate-x-full');
                    sidebarOverlay.classList.toggle('hidden');
                    
                    // Adjust main content margin on mobile when sidebar is open
                    if (window.innerWidth < 768) {
                        if (sidebar.classList.contains('-translate-x-full')) {
                            mainContent.style.marginLeft = '0';
                        } else {
                            mainContent.style.marginLeft = '0';
                        }
                    }
                });
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.add('-translate-x-full');
                    this.classList.add('hidden');
                    mainContent.style.marginLeft = '0';
                });
            }
            
            // Adjust main content on resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    mainContent.style.marginLeft = '256px';
                } else {
                    mainContent.style.marginLeft = '0';
                }
            });
        });
    </script>
</body>
</html>