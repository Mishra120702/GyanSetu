<?php
// file: attendance_api.php
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'fetch':
            fetchAttendance($db);
            break;
            
        case 'update':
            updateAttendance($db);
            break;
            
        case 'student_history':
            getStudentHistory($db);
            break;
            
        case 'get_students':
            getStudents($db);
            break;
            
        case 'student_calendar':
            getStudentCalendar($db);
            break;
            
        case 'student_full_history':
            getStudentFullHistory($db);
            break;
            
        case 'get_summary':
            getAttendanceSummary($db);
            break;
            
        case 'get_batch_stats':
            getBatchStatistics($db);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (PDOException $e) {
    error_log("Database error in attendance_api.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error in attendance_api.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Fetch attendance records based on filters
 * Supports batch_id, date filters and preview mode
 */
function fetchAttendance($db) {
    $batchId = $_GET['batch_id'] ?? '';
    $date = $_GET['date'] ?? '';
    $preview = isset($_GET['preview']) ? true : false;
    
    if (empty($date)) {
        echo json_encode(['success' => false, 'message' => 'Date is required']);
        return;
    }
    
    try {
        // Build query to get attendance records with student details
        $sql = "SELECT 
                    a.id, 
                    a.student_id, 
                    a.student_name,
                    a.batch_id,
                    a.date,
                    a.status,
                    a.camera_status,
                    a.remarks,
                    s.batch_name,
                    s.batch_name_2,
                    s.batch_name_3,
                    s.batch_name_4,
                    s.email,
                    s.phone_number,
                    s.father_name,
                    s.father_phone_number,
                    s.current_status as student_status,
                    s.enrollment_date
                FROM attendance a
                LEFT JOIN students s ON a.student_id = s.student_id
                WHERE a.date = ?";
        
        $params = [$date];
        
        if (!empty($batchId)) {
            $sql .= " AND a.batch_id = ?";
            $params[] = $batchId;
        }
        
        $sql .= " ORDER BY a.batch_id, a.student_name";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If preview mode, return simplified data
        if ($preview) {
            $previewData = array_map(function($row) {
                return [
                    'student_id' => $row['student_id'],
                    'student_name' => $row['student_name'],
                    'batch_id' => $row['batch_id'],
                    'status' => $row['status'],
                    'camera_status' => $row['camera_status']
                ];
            }, $attendance);
            
            echo json_encode(['success' => true, 'data' => $previewData]);
        } else {
            echo json_encode(['success' => true, 'data' => $attendance]);
        }
        
    } catch (PDOException $e) {
        error_log("Error in fetchAttendance: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch attendance data']);
    }
}

/**
 * Update multiple attendance records
 */
function updateAttendance($db) {
    $changes = json_decode($_POST['changes'], true);
    
    if (empty($changes)) {
        echo json_encode(['success' => false, 'message' => 'No changes provided']);
        return;
    }
    
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        foreach ($changes as $change) {
            // Validate required fields
            if (!isset($change['id']) || !isset($change['status'])) {
                $errorCount++;
                $errors[] = "Invalid change data: missing required fields";
                continue;
            }
            
            // Ensure camera_status is 'Off' if status is not 'Present'
            $cameraStatus = ($change['status'] === 'Present') ? ($change['camera_status'] ?? 'Off') : 'Off';
            
            $stmt = $db->prepare("UPDATE attendance 
                                 SET status = ?, camera_status = ?, remarks = ?
                                 WHERE id = ?");
            
            if ($stmt->execute([
                $change['status'],
                $cameraStatus,
                $change['remarks'] ?? '',
                $change['id']
            ])) {
                $successCount++;
            } else {
                $errorCount++;
                $errors[] = "Failed to update record ID: {$change['id']}";
            }
        }
        
        if ($successCount > 0) {
            logSystemActivity($db, $_SESSION['user_id'], 'ATTENDANCE_UPDATED', "Updated $successCount attendance records.");
        }

        // Commit transaction if all updates successful or partial success
        $db->commit();
        
        if ($errorCount > 0) {
            echo json_encode([
                'success' => false, 
                'message' => "Updated $successCount records, failed to update $errorCount records",
                'errors' => $errors
            ]);
        } else {
            echo json_encode([
                'success' => true, 
                'message' => "Successfully updated $successCount attendance records"
            ]);
        }
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error in updateAttendance: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred while updating']);
    }
}

/**
 * Get student attendance history within date range
 */
function getStudentHistory($db) {
    $studentId = $_GET['student_id'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    
    if (empty($studentId)) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        return;
    }
    
    try {
        $sql = "SELECT 
                    a.date,
                    a.batch_id,
                    a.status,
                    a.camera_status,
                    a.remarks,
                    b.batch_name
                FROM attendance a
                LEFT JOIN batches b ON a.batch_id = b.batch_id
                WHERE a.student_id = ?";
        
        $params = [$studentId];
        
        if (!empty($startDate)) {
            $sql .= " AND a.date >= ?";
            $params[] = $startDate;
        }
        
        if (!empty($endDate)) {
            $sql .= " AND a.date <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " ORDER BY a.date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate statistics
        $totalDays = count($history);
        $presentDays = count(array_filter($history, function($row) { 
            return $row['status'] == 'Present'; 
        }));
        $absentDays = $totalDays - $presentDays;
        $attendancePercentage = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 0;
        
        echo json_encode([
            'success' => true, 
            'data' => $history,
            'statistics' => [
                'total_days' => $totalDays,
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'attendance_percentage' => $attendancePercentage
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Error in getStudentHistory: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch student history']);
    }
}

/**
 * Get all students for dropdown
 */
function getStudents($db) {
    try {
        $stmt = $db->query("SELECT DISTINCT student_id, student_name FROM students WHERE current_status = 'active' ORDER BY student_name");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $students]);
        
    } catch (PDOException $e) {
        error_log("Error in getStudents: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch students']);
    }
}

/**
 * Get student calendar view for a specific month/date range
 */
function getStudentCalendar($db) {
    $studentId = $_GET['student_id'] ?? '';
    $month = $_GET['month'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $reportType = $_GET['report_type'] ?? 'monthly';
    
    if (empty($studentId)) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        return;
    }
    
    try {
        // Determine date range
        if ($reportType === 'monthly' && !empty($month)) {
            $startDate = date('Y-m-01', strtotime($month));
            $endDate = date('Y-m-t', strtotime($month));
        } elseif (empty($startDate) || empty($endDate)) {
            // Default to current month if no range provided
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
        }
        
        // Get attendance data for the date range
        $sql = "SELECT 
                    a.date,
                    a.status,
                    a.camera_status,
                    a.batch_id,
                    a.remarks,
                    b.batch_name
                FROM attendance a
                LEFT JOIN batches b ON a.batch_id = b.batch_id
                WHERE a.student_id = ? AND a.date BETWEEN ? AND ?
                ORDER BY a.date";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$studentId, $startDate, $endDate]);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create calendar array
        $calendar = [];
        $currentDate = strtotime($startDate);
        $endDateTime = strtotime($endDate);
        
        while ($currentDate <= $endDateTime) {
            $dateStr = date('Y-m-d', $currentDate);
            $calendar[$dateStr] = [
                'date' => $dateStr,
                'day' => date('j', $currentDate),
                'day_name' => date('l', $currentDate),
                'status' => null,
                'camera_status' => null,
                'batch_id' => null,
                'remarks' => null
            ];
            $currentDate = strtotime('+1 day', $currentDate);
        }
        
        // Fill in attendance data
        foreach ($attendance as $record) {
            if (isset($calendar[$record['date']])) {
                $calendar[$record['date']]['status'] = $record['status'];
                $calendar[$record['date']]['camera_status'] = $record['camera_status'];
                $calendar[$record['date']]['batch_id'] = $record['batch_id'];
                $calendar[$record['date']]['batch_name'] = $record['batch_name'];
                $calendar[$record['date']]['remarks'] = $record['remarks'];
            }
        }
        
        // Generate HTML for calendar view
        $html = generateCalendarHTML($calendar, $startDate, $endDate, $studentId);
        
        echo json_encode(['success' => true, 'html' => $html, 'data' => array_values($calendar)]);
        
    } catch (PDOException $e) {
        error_log("Error in getStudentCalendar: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to generate calendar']);
    }
}

/**
 * Get complete student attendance history with statistics
 */
function getStudentFullHistory($db) {
    $studentId = $_GET['student_id'] ?? '';
    
    if (empty($studentId)) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        return;
    }
    
    try {
        // Get student details
        $stmt = $db->prepare("SELECT 
                                student_id, 
                                CONCAT(first_name, ' ', last_name) as student_name,
                                email,
                                phone_number,
                                enrollment_date,
                                current_status
                              FROM students 
                              WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            return;
        }
        
        // Get all attendance records
        $sql = "SELECT 
                    a.date,
                    a.batch_id,
                    a.status,
                    a.camera_status,
                    a.remarks,
                    b.batch_name
                FROM attendance a
                LEFT JOIN batches b ON a.batch_id = b.batch_id
                WHERE a.student_id = ?
                ORDER BY a.date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$studentId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate statistics
        $totalDays = count($history);
        $presentDays = count(array_filter($history, function($row) { 
            return $row['status'] == 'Present'; 
        }));
        $absentDays = $totalDays - $presentDays;
        $cameraOnDays = count(array_filter($history, function($row) { 
            return $row['camera_status'] == 'On'; 
        }));
        $attendancePercentage = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 0;
        
        // Group by month
        $monthlyStats = [];
        foreach ($history as $record) {
            $month = date('Y-m', strtotime($record['date']));
            if (!isset($monthlyStats[$month])) {
                $monthlyStats[$month] = [
                    'month' => $month,
                    'month_name' => date('F Y', strtotime($record['date'])),
                    'total' => 0,
                    'present' => 0,
                    'absent' => 0
                ];
            }
            $monthlyStats[$month]['total']++;
            if ($record['status'] == 'Present') {
                $monthlyStats[$month]['present']++;
            } else {
                $monthlyStats[$month]['absent']++;
            }
        }
        
        // Generate HTML for history view
        $html = generateHistoryHTML($student, $history, $monthlyStats, $totalDays, $presentDays, $absentDays, $cameraOnDays, $attendancePercentage);
        
        echo json_encode([
            'success' => true, 
            'html' => $html,
            'data' => [
                'student' => $student,
                'history' => $history,
                'statistics' => [
                    'total_days' => $totalDays,
                    'present_days' => $presentDays,
                    'absent_days' => $absentDays,
                    'camera_on_days' => $cameraOnDays,
                    'attendance_percentage' => $attendancePercentage
                ],
                'monthly_stats' => array_values($monthlyStats)
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Error in getStudentFullHistory: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch student history']);
    }
}

/**
 * Get attendance summary for dashboard
 */
function getAttendanceSummary($db) {
    $date = $_GET['date'] ?? date('Y-m-d');
    $batchId = $_GET['batch_id'] ?? '';
    
    try {
        $sql = "SELECT 
                    COUNT(*) as total_students,
                    SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN camera_status = 'On' THEN 1 ELSE 0 END) as camera_on_count
                FROM attendance
                WHERE date = ?";
        
        $params = [$date];
        
        if (!empty($batchId)) {
            $sql .= " AND batch_id = ?";
            $params[] = $batchId;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get batch-wise breakdown
        $batchSql = "SELECT 
                        a.batch_id,
                        b.batch_name,
                        COUNT(*) as total,
                        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present
                    FROM attendance a
                    LEFT JOIN batches b ON a.batch_id = b.batch_id
                    WHERE a.date = ?";
        
        $batchParams = [$date];
        
        if (!empty($batchId)) {
            $batchSql .= " AND a.batch_id = ?";
            $batchParams[] = $batchId;
        }
        
        $batchSql .= " GROUP BY a.batch_id, b.batch_name ORDER BY a.batch_id";
        
        $stmt = $db->prepare($batchSql);
        $stmt->execute($batchParams);
        $batchBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'summary' => $summary,
            'batch_breakdown' => $batchBreakdown
        ]);
        
    } catch (PDOException $e) {
        error_log("Error in getAttendanceSummary: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch attendance summary']);
    }
}

/**
 * Get batch-wise statistics for a date range
 */
function getBatchStatistics($db) {
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-t');
    
    try {
        $sql = "SELECT 
                    a.batch_id,
                    b.batch_name,
                    COUNT(DISTINCT a.date) as total_days,
                    COUNT(DISTINCT a.student_id) as total_students,
                    SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as total_present,
                    COUNT(*) as total_records,
                    ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as attendance_percentage
                FROM attendance a
                LEFT JOIN batches b ON a.batch_id = b.batch_id
                WHERE a.date BETWEEN ? AND ?
                GROUP BY a.batch_id, b.batch_name
                ORDER BY attendance_percentage DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $stats,
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Error in getBatchStatistics: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch batch statistics']);
    }
}

/**
 * Generate HTML for calendar view
 */
function generateCalendarHTML($calendar, $startDate, $endDate, $studentId) {
    $daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $firstDayOfMonth = date('w', strtotime($startDate));
    
    $html = '<div class="calendar-container">';
    
    // Calendar header
    $html .= '<div class="calendar-header grid grid-cols-7 gap-1 mb-2">';
    foreach ($daysOfWeek as $day) {
        $html .= '<div class="text-center font-semibold text-sm py-2 bg-gray-100 rounded">' . $day . '</div>';
    }
    $html .= '</div>';
    
    // Calendar days
    $html .= '<div class="calendar-grid grid grid-cols-7 gap-1">';
    
    // Empty cells for days before month start
    for ($i = 0; $i < $firstDayOfMonth; $i++) {
        $html .= '<div class="calendar-day empty p-2 bg-gray-50 rounded min-h-[80px]"></div>';
    }
    
    // Fill in the days
    foreach ($calendar as $date => $dayData) {
        $status = $dayData['status'];
        $statusClass = '';
        $statusText = '';
        $cameraIcon = '';
        
        if ($status) {
            $statusClass = $status === 'Present' ? 'bg-green-100 border-green-300' : 'bg-red-100 border-red-300';
            $statusText = '<span class="status-badge status-' . strtolower($status) . ' mt-1">' . $status . '</span>';
            
            if ($dayData['camera_status'] === 'On') {
                $cameraIcon = '<i class="fas fa-video text-blue-500 ml-1" title="Camera On"></i>';
            }
        } else {
            $statusClass = 'bg-gray-50 border-gray-200';
            $statusText = '<span class="text-xs text-gray-400 mt-1">No record</span>';
        }
        
        $html .= '<div class="calendar-day ' . $statusClass . ' p-2 border rounded min-h-[80px]">';
        $html .= '<div class="flex justify-between items-start">';
        $html .= '<span class="day-number font-semibold">' . $dayData['day'] . '</span>';
        $html .= $cameraIcon;
        $html .= '</div>';
        $html .= $statusText;
        
        if ($status && $dayData['batch_name']) {
            $html .= '<div class="text-xs text-gray-600 mt-1 truncate" title="' . htmlspecialchars($dayData['batch_name']) . '">' . htmlspecialchars($dayData['batch_name']) . '</div>';
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    // Legend
    $html .= '<div class="calendar-legend mt-4 flex gap-4 text-sm">';
    $html .= '<div class="flex items-center"><span class="w-3 h-3 bg-green-100 border border-green-300 rounded mr-1"></span> Present</div>';
    $html .= '<div class="flex items-center"><span class="w-3 h-3 bg-red-100 border border-red-300 rounded mr-1"></span> Absent</div>';
    $html .= '<div class="flex items-center"><span class="w-3 h-3 bg-gray-50 border border-gray-200 rounded mr-1"></span> No Record</div>';
    $html .= '<div class="flex items-center"><i class="fas fa-video text-blue-500 mr-1"></i> Camera On</div>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Generate HTML for full history view
 */
function generateHistoryHTML($student, $history, $monthlyStats, $totalDays, $presentDays, $absentDays, $cameraOnDays, $attendancePercentage) {
    $html = '<div class="history-container">';
    
    // Student info card
    $html .= '<div class="student-info-card bg-blue-50 p-4 rounded-lg mb-4">';
    $html .= '<h4 class="font-semibold text-lg mb-2">' . htmlspecialchars($student['student_name']) . '</h4>';
    $html .= '<div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">';
    $html .= '<div><span class="text-gray-600">Student ID:</span> ' . htmlspecialchars($student['student_id']) . '</div>';
    $html .= '<div><span class="text-gray-600">Email:</span> ' . htmlspecialchars($student['email'] ?? 'N/A') . '</div>';
    $html .= '<div><span class="text-gray-600">Phone:</span> ' . htmlspecialchars($student['phone_number'] ?? 'N/A') . '</div>';
    $html .= '<div><span class="text-gray-600">Enrollment:</span> ' . htmlspecialchars($student['enrollment_date'] ?? 'N/A') . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Statistics cards
    $html .= '<div class="stats-grid grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">';
    
    $html .= '<div class="stat-card bg-white p-3 rounded-lg shadow-sm border">';
    $html .= '<div class="text-xs text-gray-500">Total Days</div>';
    $html .= '<div class="text-2xl font-bold text-gray-800">' . $totalDays . '</div>';
    $html .= '</div>';
    
    $html .= '<div class="stat-card bg-white p-3 rounded-lg shadow-sm border">';
    $html .= '<div class="text-xs text-gray-500">Present</div>';
    $html .= '<div class="text-2xl font-bold text-green-600">' . $presentDays . '</div>';
    $html .= '</div>';
    
    $html .= '<div class="stat-card bg-white p-3 rounded-lg shadow-sm border">';
    $html .= '<div class="text-xs text-gray-500">Absent</div>';
    $html .= '<div class="text-2xl font-bold text-red-600">' . $absentDays . '</div>';
    $html .= '</div>';
    
    $html .= '<div class="stat-card bg-white p-3 rounded-lg shadow-sm border">';
    $html .= '<div class="text-xs text-gray-500">Camera On</div>';
    $html .= '<div class="text-2xl font-bold text-blue-600">' . $cameraOnDays . '</div>';
    $html .= '</div>';
    
    $html .= '<div class="stat-card bg-white p-3 rounded-lg shadow-sm border">';
    $html .= '<div class="text-xs text-gray-500">Attendance %</div>';
    $html .= '<div class="text-2xl font-bold ' . ($attendancePercentage >= 75 ? 'text-green-600' : ($attendancePercentage >= 50 ? 'text-yellow-600' : 'text-red-600')) . '">' . $attendancePercentage . '%</div>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    // Monthly statistics
    if (!empty($monthlyStats)) {
        $html .= '<h5 class="font-semibold text-md mb-2">Monthly Breakdown</h5>';
        $html .= '<div class="monthly-stats overflow-x-auto mb-4">';
        $html .= '<table class="min-w-full bg-white border">';
        $html .= '<thead><tr class="bg-gray-100">';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Month</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Total Days</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Present</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Absent</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Percentage</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($monthlyStats as $stat) {
            $monthPercentage = $stat['total'] > 0 ? round(($stat['present'] / $stat['total']) * 100, 2) : 0;
            $html .= '<tr class="border-t">';
            $html .= '<td class="px-4 py-2">' . htmlspecialchars($stat['month_name']) . '</td>';
            $html .= '<td class="px-4 py-2">' . $stat['total'] . '</td>';
            $html .= '<td class="px-4 py-2 text-green-600">' . $stat['present'] . '</td>';
            $html .= '<td class="px-4 py-2 text-red-600">' . $stat['absent'] . '</td>';
            $html .= '<td class="px-4 py-2 ' . ($monthPercentage >= 75 ? 'text-green-600' : ($monthPercentage >= 50 ? 'text-yellow-600' : 'text-red-600')) . '">' . $monthPercentage . '%</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
    }
    
    // Detailed history table
    if (!empty($history)) {
        $html .= '<h5 class="font-semibold text-md mb-2">Detailed Attendance History</h5>';
        $html .= '<div class="history-table overflow-x-auto">';
        $html .= '<table class="min-w-full bg-white border">';
        $html .= '<thead><tr class="bg-gray-100">';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Date</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Batch</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Status</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Camera</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Remarks</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($history as $record) {
            $html .= '<tr class="border-t hover:bg-gray-50">';
            $html .= '<td class="px-4 py-2">' . date('Y-m-d', strtotime($record['date'])) . '</td>';
            $html .= '<td class="px-4 py-2">' . htmlspecialchars($record['batch_name'] ?? $record['batch_id']) . '</td>';
            $html .= '<td class="px-4 py-2"><span class="status-badge status-' . strtolower($record['status']) . '">' . $record['status'] . '</span></td>';
            $html .= '<td class="px-4 py-2"><span class="px-2 py-1 text-xs rounded-full ' . ($record['camera_status'] == 'On' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800') . '">' . $record['camera_status'] . '</span></td>';
            $html .= '<td class="px-4 py-2">' . htmlspecialchars($record['remarks'] ?? '') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
    } else {
        $html .= '<div class="text-center py-8 text-gray-500">No attendance records found</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}
?>