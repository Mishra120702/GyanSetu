<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get parameters
$batch_id = $_GET['batch_id'] ?? '';
$month = $_GET['month'] ?? '';

if (empty($batch_id) || empty($month)) {
    die('Batch ID and month are required');
}

// Get month name
$month_name = date('F Y', strtotime($month));

// Get report data
$stmt = $db->prepare("SELECT 
                        student_name,
                        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
                        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_count
                      FROM attendance
                      WHERE batch_id = :batch_id
                      AND DATE_FORMAT(date, '%Y-%m') = :month
                      GROUP BY student_name
                      ORDER BY student_name");
$stmt->execute([':batch_id' => $batch_id, ':month' => $month]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total classes
$stmt = $db->prepare("SELECT COUNT(DISTINCT date) as total_classes 
                      FROM attendance 
                      WHERE batch_id = :batch_id 
                      AND DATE_FORMAT(date, '%Y-%m') = :month");
$stmt->execute([':batch_id' => $batch_id, ':month' => $month]);
$total_classes = $stmt->fetch(PDO::FETCH_ASSOC)['total_classes'];

// Get batch details for the report
$stmt = $db->prepare("SELECT course_name, time_slot FROM batches WHERE batch_id = :batch_id");
$stmt->execute([':batch_id' => $batch_id]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);
$course_name = $batch['course_name'] ?? '';
$time_slot = $batch['time_slot'] ?? '';

// Set headers for HTML download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="attendance_report_' . $batch_id . '_' . $month . '.xls"');

// Generate HTML table with styling
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        .report-title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            background-color: #4CAF50;
            color: white;
            padding: 10px;
        }
        .batch-info {
            font-size: 14px;
            margin-bottom: 15px;
        }
        .batch-info td {
            padding: 5px;
        }
        .batch-info .label {
            font-weight: bold;
            background-color: #f2f2f2;
            width: 120px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th {
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
            text-align: center;
            padding: 8px;
            border: 1px solid #ddd;
        }
        td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .high-attendance {
            color: #4CAF50;
            font-weight: bold;
        }
        .medium-attendance {
            color: #FF9800;
        }
        .low-attendance {
            color: #F44336;
            font-weight: bold;
        }
        .summary-row {
            font-weight: bold;
            background-color: #e0e0e0;
        }
    </style>
</head>
<body>
    <table>
        <tr>
            <td colspan="6" class="report-title">Monthly Attendance Report - <?php echo $month_name; ?></td>
        </tr>
        <tr>
            <td colspan="6">
                <table class="batch-info">
                    <tr>
                        <td class="label">Batch ID:</td>
                        <td><?php echo $batch_id; ?></td>
                        <td class="label">Course Name:</td>
                        <td><?php echo $course_name; ?></td>
                    </tr>
                    <tr>
                        <td class="label">Time Slot:</td>
                        <td><?php echo $time_slot; ?></td>
                        <td class="label">Total Classes:</td>
                        <td><?php echo $total_classes; ?></td>
                    </tr>
                    <tr>
                        <td class="label">Report Date:</td>
                        <td colspan="3"><?php echo date('F j, Y'); ?></td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <th>Student Name</th>
            <th>Present</th>
            <th>Absent</th>
            <th>Late</th>
            <th>Total Sessions</th>
            <th>Attendance Percentage</th>
        </tr>
        <?php
        $total_present = 0;
        $total_absent = 0;
        $total_late = 0;
        
        foreach ($students as $student) {
            $total_sessions = $student['present_count'] + $student['absent_count'] + $student['late_count'];
            $percentage = $total_sessions > 0 ? round(($student['present_count'] + $student['late_count']) * 100 / $total_sessions, 2) : 0;
            
            // Determine attendance class for styling
            $attendance_class = '';
            if ($percentage >= 80) {
                $attendance_class = 'high-attendance';
            } elseif ($percentage >= 60) {
                $attendance_class = 'medium-attendance';
            } else {
                $attendance_class = 'low-attendance';
            }
            
            $total_present += $student['present_count'];
            $total_absent += $student['absent_count'];
            $total_late += $student['late_count'];
            ?>
            <tr>
                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                <td><?php echo $student['present_count']; ?></td>
                <td><?php echo $student['absent_count']; ?></td>
                <td><?php echo $student['late_count']; ?></td>
                <td><?php echo $total_sessions; ?></td>
                <td class="<?php echo $attendance_class; ?>"><?php echo $percentage; ?>%</td>
            </tr>
            <?php
        }
        
        // Calculate overall percentages
        $total_sessions_all = $total_present + $total_absent + $total_late;
        $overall_percentage = $total_sessions_all > 0 ? round(($total_present + $total_late) * 100 / $total_sessions_all, 2) : 0;
        ?>
        <tr class="summary-row">
            <td>TOTAL</td>
            <td><?php echo $total_present; ?></td>
            <td><?php echo $total_absent; ?></td>
            <td><?php echo $total_late; ?></td>
            <td><?php echo $total_sessions_all; ?></td>
            <td><?php echo $overall_percentage; ?>%</td>
        </tr>
    </table>
</body>
</html>
<?php
exit;
?>