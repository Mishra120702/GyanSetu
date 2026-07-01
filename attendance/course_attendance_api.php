<?php
// file: course_attendance_api.php
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
    error_log("Database error in course_attendance_api.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in course_attendance_api.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}

/**
 * Fetch attendance records based on filters
 * Fetches ALL students (active, onhold, completed) when records exist
 */
function fetchAttendance($db) {
    $batchId = $_GET['batch_id'] ?? '';
    $date = $_GET['date'] ?? '';
    $courseId = $_GET['course_id'] ?? '';
    $preview = isset($_GET['preview']) ? true : false;
    $showAll = isset($_GET['show_all']) ? true : false; // New parameter to control showing all students
    
    if (empty($date)) {
        echo json_encode(['success' => false, 'message' => 'Date is required']);
        return;
    }
    
    if (empty($courseId)) {
        echo json_encode(['success' => false, 'message' => 'Course ID is required']);
        return;
    }
    
    if (empty($batchId)) {
        echo json_encode(['success' => false, 'message' => 'Batch ID is required']);
        return;
    }
    
    try {
        // First, verify the course exists
        $courseStmt = $db->prepare("SELECT id, name FROM courses WHERE id = ?");
        $courseStmt->execute([$courseId]);
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$course) {
            echo json_encode(['success' => false, 'message' => 'Course not found']);
            return;
        }
        
        // Build the query to fetch attendance records
        // This approach: First get all attendance records for this date/course/batch
        // Then LEFT JOIN with students to get student details
        $sql = "SELECT 
                    ca.id,
                    ca.student_id,
                    ca.student_name,
                    ca.batch_id,
                    ca.date,
                    ca.status,
                    ca.camera_status,
                    ca.remarks,
                    s.first_name,
                    s.last_name,
                    s.batch_name,
                    s.batch_name_2,
                    s.batch_name_3,
                    s.batch_name_4,
                    s.email,
                    s.phone_number,
                    s.father_name,
                    s.father_phone_number,
                    s.current_status as student_status,
                    s.enrollment_date,
                    ? as course_id,
                    ? as course_name
                FROM course_attendance ca
                LEFT JOIN students s ON ca.student_id = s.student_id
                WHERE ca.date = ? 
                AND ca.course_id = ? 
                AND ca.batch_id = ?
                ORDER BY ca.student_name";
        
        $params = [
            $courseId, $course['name'],
            $date, $courseId, $batchId
        ];
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If showAll is true OR we want to show all students (including those without records)
        if ($showAll) {
            // Get ALL students in the batch (regardless of status)
            $allStudentsSql = "SELECT 
                                student_id,
                                CONCAT(first_name, ' ', last_name) as student_name,
                                first_name,
                                last_name,
                                batch_name,
                                batch_name_2,
                                batch_name_3,
                                batch_name_4,
                                email,
                                phone_number,
                                father_name,
                                father_phone_number,
                                current_status as student_status,
                                enrollment_date
                            FROM students 
                            WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?)
                            ORDER BY first_name, last_name";
            
            $allStudentsStmt = $db->prepare($allStudentsSql);
            $allStudentsStmt->execute([$batchId, $batchId, $batchId, $batchId]);
            $allStudents = $allStudentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create a map of attendance records by student_id
            $attendanceMap = [];
            foreach ($attendanceRecords as $record) {
                $attendanceMap[$record['student_id']] = $record;
            }
            
            // Merge: For students with attendance records, use the record data
            // For students without records, create a default record
            $mergedData = [];
            foreach ($allStudents as $student) {
                $studentId = $student['student_id'];
                if (isset($attendanceMap[$studentId])) {
                    // Student has attendance record - use it
                    $mergedData[] = $attendanceMap[$studentId];
                } else {
                    // Student doesn't have attendance record - create default
                    $mergedData[] = [
                        'id' => null,
                        'student_id' => $student['student_id'],
                        'student_name' => $student['student_name'],
                        'batch_id' => $batchId,
                        'date' => $date,
                        'status' => 'Absent',
                        'camera_status' => 'Off',
                        'remarks' => '',
                        'first_name' => $student['first_name'],
                        'last_name' => $student['last_name'],
                        'batch_name' => $student['batch_name'],
                        'batch_name_2' => $student['batch_name_2'],
                        'batch_name_3' => $student['batch_name_3'],
                        'batch_name_4' => $student['batch_name_4'],
                        'email' => $student['email'],
                        'phone_number' => $student['phone_number'],
                        'father_name' => $student['father_name'],
                        'father_phone_number' => $student['father_phone_number'],
                        'student_status' => $student['student_status'],
                        'enrollment_date' => $student['enrollment_date'],
                        'course_id' => $courseId,
                        'course_name' => $course['name']
                    ];
                }
            }
            
            $attendanceRecords = $mergedData;
        }
        
        // If preview mode, return simplified data
        if ($preview) {
            $previewData = array_map(function($row) {
                return [
                    'student_id' => $row['student_id'],
                    'student_name' => $row['student_name'],
                    'batch_id' => $row['batch_id'],
                    'status' => $row['status'],
                    'camera_status' => $row['camera_status'],
                    'student_status' => $row['student_status'] ?? 'active'
                ];
            }, $attendanceRecords);
            
            echo json_encode(['success' => true, 'data' => $previewData]);
        } else {
            echo json_encode(['success' => true, 'data' => $attendanceRecords]);
        }
        
    } catch (PDOException $e) {
        error_log("Error in fetchAttendance: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch attendance data: ' . $e->getMessage()]);
    }
}

/**
 * Update multiple attendance records in course_attendance table
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
            if (!isset($change['status']) || !isset($change['student_id']) || !isset($change['course_id']) || !isset($change['batch_id']) || !isset($change['date'])) {
                $errorCount++;
                $errors[] = "Invalid change data: missing required fields";
                continue;
            }
            
            $studentId = $change['student_id'];
            $studentName = $change['student_name'];
            $batchId = $change['batch_id'];
            $courseId = $change['course_id'];
            $date = $change['date'];
            $status = $change['status'];
            $cameraStatus = ($status === 'Present') ? ($change['camera_status'] ?? 'Off') : 'Off';
            $remarks = $change['remarks'] ?? '';
            
            // Check if record exists using the unique constraint fields
            $checkStmt = $db->prepare("SELECT id FROM course_attendance WHERE student_id = ? AND date = ? AND course_id = ? AND batch_id = ?");
            $checkStmt->execute([$studentId, $date, $courseId, $batchId]);
            $exists = $checkStmt->fetchColumn();
            
            if ($exists) {
                // Update existing record
                $stmt = $db->prepare("UPDATE course_attendance 
                                     SET status = ?, camera_status = ?, remarks = ? 
                                     WHERE student_id = ? AND date = ? AND course_id = ? AND batch_id = ?");
                
                if ($stmt->execute([$status, $cameraStatus, $remarks, $studentId, $date, $courseId, $batchId])) {
                    $successCount++;
                } else {
                    $errorCount++;
                    $errors[] = "Failed to update record for student: {$studentName}";
                }
            } else {
                // Insert new record
                $stmt = $db->prepare("INSERT INTO course_attendance 
                                     (student_id, student_name, batch_id, course_id, date, status, camera_status, remarks) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                
                if ($stmt->execute([$studentId, $studentName, $batchId, $courseId, $date, $status, $cameraStatus, $remarks])) {
                    $successCount++;
                } else {
                    $errorCount++;
                    $errors[] = "Failed to insert record for student: {$studentName}";
                }
            }
        }
        
        if ($successCount > 0) {
            logSystemActivity($db, $_SESSION['user_id'], 'ATTENDANCE_UPDATED', "Updated $successCount course attendance records.");
        }

        // Commit transaction
        $db->commit();
        
        if ($errorCount > 0) {
            echo json_encode([
                'success' => false, 
                'message' => "Updated $successCount records, failed to update $errorCount records",
                'errors' => $errors,
                'partial_success' => $successCount > 0
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
        echo json_encode(['success' => false, 'message' => 'Database error occurred while updating: ' . $e->getMessage()]);
    }
}

/**
 * Get attendance records for a specific student with all statuses
 */
function getStudentAttendanceRecords($db) {
    $studentId = $_GET['student_id'] ?? '';
    $courseId = $_GET['course_id'] ?? '';
    $batchId = $_GET['batch_id'] ?? '';
    
    if (empty($studentId)) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        return;
    }
    
    try {
        $sql = "SELECT 
                    ca.id,
                    ca.student_id,
                    ca.student_name,
                    ca.batch_id,
                    ca.course_id,
                    ca.date,
                    ca.status,
                    ca.camera_status,
                    ca.remarks,
                    b.batch_name,
                    c.name as course_name,
                    s.current_status as student_status
                FROM course_attendance ca
                LEFT JOIN batches b ON ca.batch_id = b.batch_id
                LEFT JOIN courses c ON ca.course_id = c.id
                LEFT JOIN students s ON ca.student_id = s.student_id
                WHERE ca.student_id = ?";
        
        $params = [$studentId];
        
        if (!empty($courseId)) {
            $sql .= " AND ca.course_id = ?";
            $params[] = $courseId;
        }
        
        if (!empty($batchId)) {
            $sql .= " AND ca.batch_id = ?";
            $params[] = $batchId;
        }
        
        $sql .= " ORDER BY ca.date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $records]);
        
    } catch (PDOException $e) {
        error_log("Error in getStudentAttendanceRecords: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch student attendance records: ' . $e->getMessage()]);
    }
}

/**
 * Log system activity
 */
function logSystemActivity($db, $userId, $actionType, $description) {
    try {
        $stmt = $db->prepare("INSERT INTO system_activity_logs (user_id, action_type, description, ip_address, created_at) 
                              VALUES (?, ?, ?, ?, NOW())");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt->execute([$userId, $actionType, $description, $ip]);
    } catch (PDOException $e) {
        // Silent fail for logging
        error_log("Failed to log system activity: " . $e->getMessage());
    }
}

/**
 * Get student attendance history within date range
 */
function getStudentHistory($db) {
    $studentId = $_GET['student_id'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $courseId = $_GET['course_id'] ?? '';
    
    if (empty($studentId)) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        return;
    }
    
    try {
        $sql = "SELECT 
                    ca.date,
                    ca.batch_id,
                    ca.course_id,
                    ca.status,
                    ca.camera_status,
                    ca.remarks,
                    b.batch_name,
                    c.name as course_name,
                    s.current_status as student_status
                FROM course_attendance ca
                LEFT JOIN batches b ON ca.batch_id = b.batch_id
                LEFT JOIN courses c ON ca.course_id = c.id
                LEFT JOIN students s ON ca.student_id = s.student_id
                WHERE ca.student_id = ?";
        
        $params = [$studentId];
        
        if (!empty($courseId)) {
            $sql .= " AND ca.course_id = ?";
            $params[] = $courseId;
        }
        
        if (!empty($startDate)) {
            $sql .= " AND ca.date >= ?";
            $params[] = $startDate;
        }
        
        if (!empty($endDate)) {
            $sql .= " AND ca.date <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " ORDER BY ca.date DESC";
        
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
        echo json_encode(['success' => false, 'message' => 'Failed to fetch student history: ' . $e->getMessage()]);
    }
}

/**
 * Get all students for dropdown
 */
function getStudents($db) {
    try {
        $stmt = $db->query("SELECT DISTINCT student_id, CONCAT(first_name, ' ', last_name) as student_name, current_status 
                            FROM students 
                            ORDER BY student_name");
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
    $courseId = $_GET['course_id'] ?? '';
    
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
                    ca.date,
                    ca.status,
                    ca.camera_status,
                    ca.batch_id,
                    ca.course_id,
                    ca.remarks,
                    b.batch_name,
                    c.name as course_name
                FROM course_attendance ca
                LEFT JOIN batches b ON ca.batch_id = b.batch_id
                LEFT JOIN courses c ON ca.course_id = c.id
                WHERE ca.student_id = ? AND ca.date BETWEEN ? AND ?";
        
        $params = [$studentId, $startDate, $endDate];
        
        if (!empty($courseId)) {
            $sql .= " AND ca.course_id = ?";
            $params[] = $courseId;
        }
        
        $sql .= " ORDER BY ca.date";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
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
                'course_id' => null,
                'remarks' => null,
                'course_name' => null
            ];
            $currentDate = strtotime('+1 day', $currentDate);
        }
        
        // Fill in attendance data
        foreach ($attendance as $record) {
            if (isset($calendar[$record['date']])) {
                $calendar[$record['date']]['status'] = $record['status'];
                $calendar[$record['date']]['camera_status'] = $record['camera_status'];
                $calendar[$record['date']]['batch_id'] = $record['batch_id'];
                $calendar[$record['date']]['course_id'] = $record['course_id'];
                $calendar[$record['date']]['batch_name'] = $record['batch_name'];
                $calendar[$record['date']]['course_name'] = $record['course_name'];
                $calendar[$record['date']]['remarks'] = $record['remarks'];
            }
        }
        
        // Generate HTML for calendar view
        $html = generateCalendarHTML($calendar, $startDate, $endDate, $studentId);
        
        echo json_encode(['success' => true, 'html' => $html, 'data' => array_values($calendar)]);
        
    } catch (PDOException $e) {
        error_log("Error in getStudentCalendar: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to generate calendar: ' . $e->getMessage()]);
    }
}

/**
 * Get complete student attendance history with statistics
 */
function getStudentFullHistory($db) {
    $studentId = $_GET['student_id'] ?? '';
    $courseId = $_GET['course_id'] ?? '';
    
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
        
        // Get all attendance records from course_attendance
        $sql = "SELECT 
                    ca.date,
                    ca.batch_id,
                    ca.course_id,
                    ca.status,
                    ca.camera_status,
                    ca.remarks,
                    b.batch_name,
                    c.name as course_name
                FROM course_attendance ca
                LEFT JOIN batches b ON ca.batch_id = b.batch_id
                LEFT JOIN courses c ON ca.course_id = c.id
                WHERE ca.student_id = ?";
        
        $params = [$studentId];
        
        if (!empty($courseId)) {
            $sql .= " AND ca.course_id = ?";
            $params[] = $courseId;
        }
        
        $sql .= " ORDER BY ca.date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
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
        echo json_encode(['success' => false, 'message' => 'Failed to fetch student history: ' . $e->getMessage()]);
    }
}

/**
 * Get attendance summary for dashboard
 */
function getAttendanceSummary($db) {
    $date = $_GET['date'] ?? date('Y-m-d');
    $batchId = $_GET['batch_id'] ?? '';
    $courseId = $_GET['course_id'] ?? '';
    
    try {
        $sql = "SELECT 
                    COUNT(*) as total_students,
                    SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN camera_status = 'On' THEN 1 ELSE 0 END) as camera_on_count
                FROM course_attendance
                WHERE date = ?";
        
        $params = [$date];
        
        if (!empty($batchId)) {
            $sql .= " AND batch_id = ?";
            $params[] = $batchId;
        }
        
        if (!empty($courseId)) {
            $sql .= " AND course_id = ?";
            $params[] = $courseId;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get batch-wise breakdown
        $batchSql = "SELECT 
                        ca.batch_id,
                        b.batch_name,
                        COUNT(*) as total,
                        SUM(CASE WHEN ca.status = 'Present' THEN 1 ELSE 0 END) as present
                    FROM course_attendance ca
                    LEFT JOIN batches b ON ca.batch_id = b.batch_id
                    WHERE ca.date = ?";
        
        $batchParams = [$date];
        
        if (!empty($courseId)) {
            $batchSql .= " AND ca.course_id = ?";
            $batchParams[] = $courseId;
        }
        
        $batchSql .= " GROUP BY ca.batch_id, b.batch_name ORDER BY ca.batch_id";
        
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
        echo json_encode(['success' => false, 'message' => 'Failed to fetch attendance summary: ' . $e->getMessage()]);
    }
}

/**
 * Get batch-wise statistics for a date range
 */
function getBatchStatistics($db) {
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-t');
    $courseId = $_GET['course_id'] ?? '';
    
    try {
        $sql = "SELECT 
                    ca.batch_id,
                    b.batch_name,
                    COUNT(DISTINCT ca.date) as total_days,
                    COUNT(DISTINCT ca.student_id) as total_students,
                    SUM(CASE WHEN ca.status = 'Present' THEN 1 ELSE 0 END) as total_present,
                    COUNT(*) as total_records,
                    ROUND((SUM(CASE WHEN ca.status = 'Present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as attendance_percentage
                FROM course_attendance ca
                LEFT JOIN batches b ON ca.batch_id = b.batch_id
                WHERE ca.date BETWEEN ? AND ?";
        
        $params = [$startDate, $endDate];
        
        if (!empty($courseId)) {
            $sql .= " AND ca.course_id = ?";
            $params[] = $courseId;
        }
        
        $sql .= " GROUP BY ca.batch_id, b.batch_name ORDER BY attendance_percentage DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
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
        echo json_encode(['success' => false, 'message' => 'Failed to fetch batch statistics: ' . $e->getMessage()]);
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
        
        if ($status && !empty($dayData['course_name'])) {
            $html .= '<div class="text-xs text-gray-600 mt-1 truncate" title="' . htmlspecialchars($dayData['course_name']) . '">' . htmlspecialchars($dayData['course_name']) . '</div>';
        }
        
        if ($status && !empty($dayData['batch_name'])) {
            $html .= '<div class="text-xs text-gray-500 mt-0.5 truncate" title="' . htmlspecialchars($dayData['batch_name']) . '">' . htmlspecialchars($dayData['batch_name']) . '</div>';
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    // Legend
    $html .= '<div class="calendar-legend mt-4 flex gap-4 text-sm flex-wrap">';
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
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Course</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Status</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Camera</th>';
        $html .= '<th class="px-4 py-2 text-left text-xs font-medium text-gray-600">Remarks</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($history as $record) {
            $html .= '<tr class="border-t hover:bg-gray-50">';
            $html .= '<td class="px-4 py-2">' . date('Y-m-d', strtotime($record['date'])) . '</td>';
            $html .= '<td class="px-4 py-2">' . htmlspecialchars($record['batch_name'] ?? $record['batch_id']) . '</td>';
            $html .= '<td class="px-4 py-2">' . htmlspecialchars($record['course_name'] ?? $record['course_id']) . '</td>';
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