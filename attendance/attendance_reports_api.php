<?php
// file: attendance_reports_api.php
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$batch_id = $_GET['batch_id'] ?? '';
$course_id = $_GET['course_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if (empty($start_date) || empty($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Date range is required']);
    exit;
}

try {
    // Base WHERE clause
    $whereConditions = ["a.date >= ?", "a.date <= ?"];
    $params = [$start_date, $end_date];

    if (!empty($batch_id)) {
        $whereConditions[] = "a.batch_id = ?";
        $params[] = $batch_id;
    }
    
    if (!empty($course_id)) {
        $whereConditions[] = "a.course_id = ?";
        $params[] = $course_id;
    }

    $whereClause = implode(" AND ", $whereConditions);

    // 1. Daily Report
    // Group by date and course
    $dailySql = "SELECT 
                    a.date,
                    c.name as course_name,
                    COUNT(a.id) as total_students,
                    SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent
                 FROM course_attendance a
                 LEFT JOIN courses c ON a.course_id = c.id
                 WHERE $whereClause
                 GROUP BY a.date, a.course_id, c.name
                 ORDER BY a.date DESC";
    
    $stmt = $db->prepare($dailySql);
    $stmt->execute($params);
    $daily_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $daily_report = [];
    $total_classes = count($daily_raw);
    $total_present_overall = 0;
    $total_records_overall = 0;

    foreach ($daily_raw as $row) {
        $total = $row['present'] + $row['absent'];
        $percentage = $total > 0 ? round(($row['present'] / $total) * 100, 2) : 0;
        
        $daily_report[] = [
            'date' => $row['date'],
            'course_name' => $row['course_name'] ?? 'Unknown Course',
            'total_students' => $total,
            'present' => $row['present'],
            'absent' => $row['absent'],
            'percentage' => $percentage
        ];

        $total_present_overall += $row['present'];
        $total_records_overall += $total;
    }

    // 2. Student-wise Report
    // Group by student
    $studentSql = "SELECT 
                    a.student_name,
                    b.batch_name,
                    COUNT(a.id) as total_classes,
                    SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent
                 FROM course_attendance a
                 LEFT JOIN batches b ON a.batch_id = b.batch_id
                 WHERE $whereClause
                 GROUP BY a.student_id, a.student_name, a.batch_id, b.batch_name
                 ORDER BY present DESC";
    
    $stmt = $db->prepare($studentSql);
    $stmt->execute($params);
    $student_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $student_report = [];
    $max_absent = 0;
    $most_absent_student = '';

    foreach ($student_raw as $row) {
        $total = $row['present'] + $row['absent'];
        $percentage = $total > 0 ? round(($row['present'] / $total) * 100, 2) : 0;
        
        $student_report[] = [
            'student_name' => $row['student_name'],
            'batch_name' => $row['batch_name'] ?? $row['batch_id'],
            'total_classes' => $total,
            'present' => $row['present'],
            'absent' => $row['absent'],
            'percentage' => $percentage
        ];

        if ($row['absent'] > $max_absent) {
            $max_absent = $row['absent'];
            $most_absent_student = $row['student_name'];
        }
    }

    // 3. Overall Stats
    $overall_percentage = $total_records_overall > 0 ? round(($total_present_overall / $total_records_overall) * 100, 2) : 0;
    $avg_present = $total_classes > 0 ? round($total_present_overall / $total_classes, 1) : 0;

    echo json_encode([
        'success' => true,
        'data' => [
            'stats' => [
                'total_classes' => $total_classes,
                'overall_percentage' => $overall_percentage,
                'avg_present' => $avg_present,
                'most_absent_student' => $most_absent_student
            ],
            'daily_report' => $daily_report,
            'student_report' => $student_report
        ]
    ]);

} catch (PDOException $e) {
    error_log("Reports API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
