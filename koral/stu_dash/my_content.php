<?php
// my_content.php - Student Content Viewer with Integrated Submission (Multi-Batch Support)
session_start();
require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

// Handle file submission if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['submission_file']) && isset($_POST['upload_id'])) {
    $upload_id = intval($_POST['upload_id']);
    $student_user_id = $_SESSION['user_id'];
    
    // Get student info
    $student_query = $db->prepare("
        SELECT s.* 
        FROM students s
        WHERE s.user_id = :user_id
    ");
    $student_query->execute([':user_id' => $student_user_id]);
    $student = $student_query->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $submission_message = "Student information not found";
        $submission_success = false;
    } else {
        $student_id = $student['student_id'];
        
        // Check if assignment exists and hasn't been submitted yet
        $check_query = $db->prepare("
            SELECT u.*, 
                   (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id AND student_id = :student_id) as has_submitted
            FROM uploads u
            WHERE u.id = :upload_id 
            AND u.file_type = 'Assignment'
        ");
        $check_query->execute([
            ':upload_id' => $upload_id,
            ':student_id' => $student_id
        ]);
        $assignment = $check_query->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignment) {
            $submission_message = "Assignment not found";
            $submission_success = false;
        } elseif ($assignment['has_submitted'] > 0) {
            $submission_message = "You have already submitted this assignment. One-time submission only.";
            $submission_success = false;
        } else {
            // Check if submission is still allowed (not past due date/time)
            $submission_allowed = true;
            if ($assignment['due_date']) {
                $due_datetime = new DateTime($assignment['due_date'], new DateTimeZone('Asia/Kolkata'));
                
                // Set due time if available, otherwise default to 23:59:59
                if (!empty($assignment['due_time'])) {
                    $time_parts = explode(':', $assignment['due_time']);
                    $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
                } else {
                    $due_datetime->setTime(23, 59, 59);
                }
                
                $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                
                if ($now > $due_datetime) {
                    $submission_allowed = false;
                    $submission_message = "This assignment is past its due date and time. Submissions are no longer accepted.";
                }
            }
            
            if (!$submission_allowed) {
                $submission_success = false;
            } else {
                // Validate file upload
                if ($_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
                    // Validate file type (PDF only)
                    $allowed_types = ['application/pdf'];
                    $file_type = $_FILES['submission_file']['type'];
                    
                    if (!in_array($file_type, $allowed_types)) {
                        $submission_message = 'Only PDF files are allowed for submissions';
                        $submission_success = false;
                    } else {
                        // Validate file size (max 10MB)
                        $max_size = 10 * 1024 * 1024; // 10MB in bytes
                        if ($_FILES['submission_file']['size'] > $max_size) {
                            $submission_message = 'File size must be less than 10MB';
                            $submission_success = false;
                        } else {
                            // Create upload directory
                            $upload_dir = '../uploads/assignments/submissions/';
                            if (!is_dir($upload_dir)) {
                                mkdir($upload_dir, 0755, true);
                            }
                            
                            // Generate unique filename
                            $file_name = 'submission_' . $student_id . '_' . $upload_id . '_' . time() . '.pdf';
                            $file_path = $upload_dir . $file_name;
                            
                            if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $file_path)) {
                                try {
                                    $db->beginTransaction();
                                    
                                    // Determine if submission is late
                                    $status = 'submitted';
                                    if ($assignment['due_date']) {
                                        $submission_time = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                                        if ($submission_time > $due_datetime) {
                                            $status = 'late';
                                        }
                                    }
                                    
                                    // Insert new submission
                                    $stmt = $db->prepare("
                                        INSERT INTO assignment_submissions (upload_id, student_id, file_path, status)
                                        VALUES (:upload_id, :student_id, :file_path, :status)
                                    ");
                                    $stmt->execute([
                                        ':upload_id' => $upload_id,
                                        ':student_id' => $student_id,
                                        ':file_path' => $file_path,
                                        ':status' => $status
                                    ]);
                                    
                                    $db->commit();
                                    $submission_message = 'Assignment submitted successfully! This is your final submission.';
                                    $submission_success = true;
                                    
                                    // Reload the page to show updated status
                                    header("Location: my_content.php?tab=assignments&message=" . urlencode($submission_message));
                                    exit();
                                    
                                } catch (PDOException $e) {
                                    $db->rollBack();
                                    if (file_exists($file_path)) {
                                        unlink($file_path);
                                    }
                                    $submission_message = 'Database error: ' . $e->getMessage();
                                    $submission_success = false;
                                }
                            } else {
                                $submission_message = 'File upload failed. Please try again.';
                                $submission_success = false;
                            }
                        }
                    }
                } else {
                    $submission_message = 'File upload error. Please select a PDF file.';
                    $submission_success = false;
                }
            }
        }
    }
}

// Get student information
$student_user_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT s.*, u.email as user_email, 
           b1.batch_id as batch1_id, b1.batch_name as batch1_name, b1.start_date as batch1_start, b1.end_date as batch1_end, 
           b1.time_slot as batch1_time, b1.mode as batch1_mode, b1.status as batch1_status, b1.meeting_link as batch1_meeting,
           b2.batch_id as batch2_id, b2.batch_name as batch2_name, b2.start_date as batch2_start, b2.end_date as batch2_end, 
           b2.time_slot as batch2_time, b2.mode as batch2_mode, b2.status as batch2_status, b2.meeting_link as batch2_meeting,
           b3.batch_id as batch3_id, b3.batch_name as batch3_name, b3.start_date as batch3_start, b3.end_date as batch3_end, 
           b3.time_slot as batch3_time, b3.mode as batch3_mode, b3.status as batch3_status, b3.meeting_link as batch3_meeting
    FROM students s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
    LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
    LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found. Please contact administrator.");
}

// Get student ID for content lookup
$student_id_value = $student['student_id'];

// Collect all batch information
$all_batches = [];
$batch_details = [];

// Check each batch field and add if exists
if (!empty($student['batch1_id'])) {
    $all_batches[] = $student['batch1_id'];
    $batch_details[$student['batch1_id']] = [
        'id' => $student['batch1_id'],
        'name' => $student['batch1_name'],
        'start_date' => $student['batch1_start'],
        'end_date' => $student['batch1_end'],
        'time_slot' => $student['batch1_time'],
        'mode' => $student['batch1_mode'],
        'status' => $student['batch1_status'],
        'meeting_link' => $student['batch1_meeting'],
        'field' => 'batch1'
    ];
}

if (!empty($student['batch2_id'])) {
    $all_batches[] = $student['batch2_id'];
    $batch_details[$student['batch2_id']] = [
        'id' => $student['batch2_id'],
        'name' => $student['batch2_name'],
        'start_date' => $student['batch2_start'],
        'end_date' => $student['batch2_end'],
        'time_slot' => $student['batch2_time'],
        'mode' => $student['batch2_mode'],
        'status' => $student['batch2_status'],
        'meeting_link' => $student['batch2_meeting'],
        'field' => 'batch2'
    ];
}

if (!empty($student['batch3_id'])) {
    $all_batches[] = $student['batch3_id'];
    $batch_details[$student['batch3_id']] = [
        'id' => $student['batch3_id'],
        'name' => $student['batch3_name'],
        'start_date' => $student['batch3_start'],
        'end_date' => $student['batch3_end'],
        'time_slot' => $student['batch3_time'],
        'mode' => $student['batch3_mode'],
        'status' => $student['batch3_status'],
        'meeting_link' => $student['batch3_meeting'],
        'field' => 'batch3'
    ];
}

// Get historical batches from student_batch_history
$history_query = $db->prepare("
    SELECT DISTINCT from_batch_id, to_batch_id 
    FROM student_batch_history 
    WHERE student_id = :student_id
");
$history_query->execute([':student_id' => $student_id_value]);
$history_batches = $history_query->fetchAll(PDO::FETCH_ASSOC);

foreach ($history_batches as $batch) {
    if ($batch['from_batch_id'] && !in_array($batch['from_batch_id'], $all_batches)) {
        $all_batches[] = $batch['from_batch_id'];
    }
    if ($batch['to_batch_id'] && !in_array($batch['to_batch_id'], $all_batches)) {
        $all_batches[] = $batch['to_batch_id'];
    }
}

// Get filter parameters
$searchTerm = $_GET['search'] ?? '';
$fileTypeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sortColumn = $_GET['sort'] ?? 'uploaded_at';
$sortOrder = $_GET['order'] ?? 'DESC';
$active_tab = $_GET['tab'] ?? 'all';
$selected_batch = $_GET['batch'] ?? 'all';

// Validate sort parameters
$allowedSortColumns = ['title', 'file_type', 'uploaded_at', 'due_date', 'batch_name'];
$allowedSortOrders = ['ASC', 'DESC'];
$sortColumn = in_array($sortColumn, $allowedSortColumns) ? $sortColumn : 'uploaded_at';
$sortOrder = in_array($sortOrder, $allowedSortOrders) ? $sortOrder : 'DESC';

// Initialize content arrays
$content_items = [];
$assignments = [];
$tests = [];
$notes = [];
$lab_manuals = [];

if (!empty($all_batches)) {
    // Create placeholders for batch IDs
    $placeholders = implode(',', array_fill(0, count($all_batches), '?'));
    
    // Base query with batch information
    $base_query = "
        SELECT DISTINCT u.*, 
               b.batch_id, 
               b.batch_name,
               b.status as batch_status,
               b.time_slot as batch_time,
               b.mode as batch_mode
        FROM uploads u
        JOIN batch_uploads bu ON u.id = bu.upload_id
        JOIN batches b ON bu.batch_id = b.batch_id
        WHERE bu.batch_id IN ($placeholders)
    ";
    
    $params = $all_batches;
    
    // Apply additional filters based on active tab
    $whereClauses = [];
    
    if ($active_tab === 'assignments') {
        $whereClauses[] = "u.file_type = 'Assignment'";
    } elseif ($active_tab === 'tests') {
        $whereClauses[] = "u.file_type = 'Test'";
    } elseif ($active_tab === 'notes') {
        $whereClauses[] = "u.file_type = 'Notes'";
    } elseif ($active_tab === 'lab_manuals') {
        $whereClauses[] = "u.file_type = 'Lab Manual'";
    }
    
    // Apply batch filter if not 'all'
    if ($selected_batch !== 'all' && in_array($selected_batch, $all_batches)) {
        $whereClauses[] = "bu.batch_id = ?";
        $params[] = $selected_batch;
    }
    
    if (!empty($searchTerm)) {
        $whereClauses[] = "(u.title LIKE ? OR u.description LIKE ?)";
        $params[] = "%$searchTerm%";
        $params[] = "%$searchTerm%";
    }
    
    if (!empty($fileTypeFilter) && $fileTypeFilter !== 'all') {
        $whereClauses[] = "u.file_type = ?";
        $params[] = $fileTypeFilter;
    }
    
    if (!empty($dateFrom)) {
        $whereClauses[] = "DATE(u.uploaded_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $whereClauses[] = "DATE(u.uploaded_at) <= ?";
        $params[] = $dateTo;
    }
    
    // Build WHERE clause
    if (!empty($whereClauses)) {
        $base_query .= " AND " . implode(' AND ', $whereClauses);
    }
    
    // Add ORDER BY
    $base_query .= " ORDER BY u.$sortColumn $sortOrder";
    
    try {
        if ($active_tab === 'assignments') {
            // For assignments, we need to get submission status separately
            $assignments_query = $db->prepare($base_query);
            $assignments_query->execute($params);
            $assignments = $assignments_query->fetchAll(PDO::FETCH_ASSOC);
            
            // Now get submission status for each assignment
            foreach ($assignments as &$assignment) {
                $submission_query = $db->prepare("
                    SELECT status, grade, feedback, submitted_at, file_path 
                    FROM assignment_submissions 
                    WHERE upload_id = ? AND student_id = ?
                    ORDER BY submitted_at DESC 
                    LIMIT 1
                ");
                $submission_query->execute([$assignment['id'], $student_id_value]);
                $submission = $submission_query->fetch(PDO::FETCH_ASSOC);
                
                // Calculate due date/time status
                $due_status = '';
                $due_class = '';
                $time_remaining = '';
                
                if ($assignment['due_date']) {
                    $due_datetime = new DateTime($assignment['due_date'], new DateTimeZone('Asia/Kolkata'));
                    
                    // Set due time if available, otherwise default to 23:59:59
                    if (!empty($assignment['due_time'])) {
                        $time_parts = explode(':', $assignment['due_time']);
                        $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
                    } else {
                        $due_datetime->setTime(23, 59, 59);
                    }
                    
                    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                    $interval = $now->diff($due_datetime);
                    $days_left = $interval->format('%r%a');
                    
                    if ($days_left < 0) {
                        $due_status = 'Overdue';
                        $due_class = 'due-date-overdue';
                        $time_remaining = abs($days_left) . ' day' . (abs($days_left) > 1 ? 's' : '') . ' ago';
                    } elseif ($days_left == 0) {
                        $hours_left = $interval->h;
                        $minutes_left = $interval->i;
                        if ($hours_left > 0) {
                            $due_status = 'Due Today';
                            $due_class = 'due-date-upcoming';
                            $time_remaining = $hours_left . 'h ' . $minutes_left . 'm left';
                        } else {
                            $due_status = 'Due Soon';
                            $due_class = 'due-date-overdue';
                            $time_remaining = $minutes_left . 'm left';
                        }
                    } elseif ($days_left <= 3) {
                        $due_status = $days_left . ' day' . ($days_left > 1 ? 's' : '') . ' left';
                        $due_class = 'due-date-upcoming';
                        $time_remaining = '';
                    } else {
                        $due_status = date('M j, Y', strtotime($assignment['due_date']));
                        $due_class = 'due-date-future';
                        $time_remaining = '';
                    }
                }
                
                if ($submission) {
                    $assignment['has_submitted'] = 1;
                    $assignment['can_resubmit'] = 0; // No resubmission allowed
                    $assignment['submission_status'] = $submission['status'];
                    $assignment['student_grade'] = $submission['grade'];
                    $assignment['feedback'] = $submission['feedback'];
                    $assignment['submission_date'] = $submission['submitted_at'];
                    $assignment['submission_file_path'] = $submission['file_path'];
                } else {
                    $assignment['has_submitted'] = 0;
                    $assignment['can_resubmit'] = 1; // Can submit if not submitted yet
                    $assignment['submission_status'] = 'not_submitted';
                    $assignment['student_grade'] = null;
                    $assignment['feedback'] = null;
                    $assignment['submission_date'] = null;
                    $assignment['submission_file_path'] = null;
                }
                
                $assignment['due_status'] = $due_status;
                $assignment['due_class'] = $due_class;
                $assignment['time_remaining'] = $time_remaining;
            }
            unset($assignment); // Unset reference
            
        } elseif ($active_tab === 'tests') {
            $tests_query = $db->prepare($base_query);
            $tests_query->execute($params);
            $tests = $tests_query->fetchAll(PDO::FETCH_ASSOC);
            
        } elseif ($active_tab === 'notes') {
            $notes_query = $db->prepare($base_query);
            $notes_query->execute($params);
            $notes = $notes_query->fetchAll(PDO::FETCH_ASSOC);
            
        } elseif ($active_tab === 'lab_manuals') {
            $lab_manuals_query = $db->prepare($base_query);
            $lab_manuals_query->execute($params);
            $lab_manuals = $lab_manuals_query->fetchAll(PDO::FETCH_ASSOC);
            
        } else {
            // For 'all' tab, we need to handle assignments differently
            $all_query = $db->prepare($base_query);
            $all_query->execute($params);
            $all_items = $all_query->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($all_items as $item) {
                if ($item['file_type'] === 'Assignment') {
                    // Calculate due date/time status
                    $due_status = '';
                    $due_class = '';
                    $time_remaining = '';
                    
                    if ($item['due_date']) {
                        $due_datetime = new DateTime($item['due_date'], new DateTimeZone('Asia/Kolkata'));
                        
                        // Set due time if available, otherwise default to 23:59:59
                        if (!empty($item['due_time'])) {
                            $time_parts = explode(':', $item['due_time']);
                            $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
                        } else {
                            $due_datetime->setTime(23, 59, 59);
                        }
                        
                        $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                        $interval = $now->diff($due_datetime);
                        $days_left = $interval->format('%r%a');
                        
                        if ($days_left < 0) {
                            $due_status = 'Overdue';
                            $due_class = 'due-date-overdue';
                            $time_remaining = abs($days_left) . ' day' . (abs($days_left) > 1 ? 's' : '') . ' ago';
                        } elseif ($days_left == 0) {
                            $hours_left = $interval->h;
                            $minutes_left = $interval->i;
                            if ($hours_left > 0) {
                                $due_status = 'Due Today';
                                $due_class = 'due-date-upcoming';
                                $time_remaining = $hours_left . 'h ' . $minutes_left . 'm left';
                            } else {
                                $due_status = 'Due Soon';
                                $due_class = 'due-date-overdue';
                                $time_remaining = $minutes_left . 'm left';
                            }
                        } elseif ($days_left <= 3) {
                            $due_status = $days_left . ' day' . ($days_left > 1 ? 's' : '') . ' left';
                            $due_class = 'due-date-upcoming';
                            $time_remaining = '';
                        } else {
                            $due_status = date('M j, Y', strtotime($item['due_date']));
                            $due_class = 'due-date-future';
                            $time_remaining = '';
                        }
                    }
                    
                    // Get submission status for assignments
                    $submission_query = $db->prepare("
                        SELECT status, grade, feedback, submitted_at, file_path 
                        FROM assignment_submissions 
                        WHERE upload_id = ? AND student_id = ?
                        ORDER BY submitted_at DESC 
                        LIMIT 1
                    ");
                    $submission_query->execute([$item['id'], $student_id_value]);
                    $submission = $submission_query->fetch(PDO::FETCH_ASSOC);
                    
                    if ($submission) {
                        $item['has_submitted'] = 1;
                        $item['can_resubmit'] = 0; // No resubmission allowed
                        $item['submission_status'] = $submission['status'];
                        $item['student_grade'] = $submission['grade'];
                        $item['feedback'] = $submission['feedback'];
                        $item['submission_date'] = $submission['submitted_at'];
                        $item['submission_file_path'] = $submission['file_path'];
                    } else {
                        $item['has_submitted'] = 0;
                        $item['can_resubmit'] = 1; // Can submit if not submitted yet
                        $item['submission_status'] = 'not_submitted';
                        $item['student_grade'] = null;
                        $item['feedback'] = null;
                        $item['submission_date'] = null;
                        $item['submission_file_path'] = null;
                    }
                    
                    $item['due_status'] = $due_status;
                    $item['due_class'] = $due_class;
                    $item['time_remaining'] = $time_remaining;
                }
                $content_items[] = $item;
            }
        }
        
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        die("Error fetching content. Please try again later.");
    }
}

// Get content statistics for student - per batch
$batch_stats = [];
foreach ($all_batches as $batch_id) {
    if (!isset($batch_details[$batch_id])) {
        // Get batch details if not already fetched
        $batch_info_query = $db->prepare("
            SELECT batch_id, batch_name, status 
            FROM batches 
            WHERE batch_id = ?
        ");
        $batch_info_query->execute([$batch_id]);
        $batch_info = $batch_info_query->fetch(PDO::FETCH_ASSOC);
        
        if ($batch_info) {
            $batch_details[$batch_id] = [
                'id' => $batch_info['batch_id'],
                'name' => $batch_info['batch_name'],
                'status' => $batch_info['status'],
                'field' => 'history'
            ];
        }
    }
    
    $statsQuery = "
        SELECT u.file_type, COUNT(*) as count 
        FROM uploads u
        JOIN batch_uploads bu ON u.id = bu.upload_id
        WHERE bu.batch_id = ?
        GROUP BY u.file_type
    ";
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->execute([$batch_id]);
    $contentStats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [
        'Test' => 0,
        'Assignment' => 0,
        'Notes' => 0,
        'Lab Manual' => 0,
        'Other' => 0
    ];
    
    foreach ($contentStats as $stat) {
        $stats[$stat['file_type']] = $stat['count'];
    }
    
    $batch_stats[$batch_id] = [
        'stats' => $stats,
        'total' => array_sum($stats),
        'batch_name' => $batch_details[$batch_id]['name'] ?? $batch_id,
        'status' => $batch_details[$batch_id]['status'] ?? 'unknown'
    ];
}

// Calculate overall statistics
$overall_stats = [
    'Test' => 0,
    'Assignment' => 0,
    'Notes' => 0,
    'Lab Manual' => 0,
    'Other' => 0,
    'total' => 0
];

foreach ($batch_stats as $batch_stat) {
    foreach ($batch_stat['stats'] as $type => $count) {
        $overall_stats[$type] += $count;
    }
    $overall_stats['total'] += $batch_stat['total'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Course Content - Student Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --secondary: #f9fafb;
            --accent: #10b981;
            --danger: #ef4444;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            transition: all 0.3s ease;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: var(--primary);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.3), 0 2px 4px -1px rgba(79, 70, 229, 0.1);
        }
        
        .sortable:hover {
            background-color: #f8fafc;
            cursor: pointer;
        }
        
        .sort-icon {
            transition: transform 0.3s ease;
        }
        
        .sort-icon.active {
            color: var(--primary);
        }
        
        .sort-icon.asc {
            transform: rotate(180deg);
        }
        
        .content-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .icon-test {
            background: rgba(167, 139, 250, 0.2);
            color: #7e22ce;
        }
        
        .icon-assignment {
            background: rgba(96, 165, 250, 0.2);
            color: #1d4ed8;
        }
        
        .icon-notes {
            background: rgba(52, 211, 153, 0.2);
            color: #047857;
        }
        
        .icon-lab-manual {
            background: rgba(251, 191, 36, 0.2);
            color: #b45309;
        }
        
        .icon-other {
            background: rgba(209, 213, 219, 0.2);
            color: #4b5563;
        }
        
        .stat-card:hover .content-icon {
            transform: scale(1.1) rotate(5deg);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .badge-test {
            background-color: #f3e8ff;
            color: #7e22ce;
        }
        
        .badge-assignment {
            background-color: #dbeafe;
            color: #1d4ed8;
        }
        
        .badge-notes {
            background-color: #d1fae5;
            color: #047857;
        }
        
        .badge-lab-manual {
            background-color: #fef3c7;
            color: #b45309;
        }
        
        .badge-other {
            background-color: #e5e7eb;
            color: #4b5563;
        }
        
        .batch-tag {
            display: inline-flex;
            align-items: center;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            background-color: #f3f4f6;
            color: #4b5563;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .action-btn {
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
        }
        
        tr {
            transition: all 0.2s ease;
        }
        
        tr:hover {
            background-color: #f9fafb;
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-out forwards;
            opacity: 0;
        }
        
        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }
        
        .due-date-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .due-date-overdue {
            background-color: #fee2e2;
            color: #dc2626;
        }
        
        .due-date-upcoming {
            background-color: #fef3c7;
            color: #d97706;
        }
        
        .due-date-future {
            background-color: #d1fae5;
            color: #059669;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-submitted {
            background-color: #dbeafe;
            color: #1d4ed8;
        }
        
        .status-graded {
            background-color: #d1fae5;
            color: #047857;
        }
        
        .status-late {
            background-color: #fef3c7;
            color: #d97706;
        }
        
        .status-missing {
            background-color: #fee2e2;
            color: #dc2626;
        }
        
        .status-not-submitted {
            background-color: #e5e7eb;
            color: #6b7280;
        }
        
        .status-final {
            background-color: #6b7280;
            color: #ffffff;
        }
        
        .tab-active {
            background-color: #eff6ff;
            color: #3b82f6;
            border-color: #3b82f6;
        }
        
        .batch-indicator {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .batch-current {
            background-color: #dbeafe;
            color: #1d4ed8;
        }
        
        .batch-previous {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        
        .batch-history {
            background-color: #e5e7eb;
            color: #4b5563;
        }
        
        .file-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-right: 12px;
        }
        
        .icon-pdf {
            background-color: #fee2e2;
            color: #dc2626;
        }
        
        .icon-doc {
            background-color: #dbeafe;
            color: #1d4ed8;
        }
        
        .icon-default {
            background-color: #e5e7eb;
            color: #6b7280;
        }
        
        .submission-locked {
            opacity: 0.9;
            background-color: #f9fafb;
        }
        
        .submission-locked .action-btn:not(.download-btn) {
            pointer-events: none;
            background-color: #e5e7eb !important;
            color: #6b7280 !important;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .download-btn {
            background-color: #3b82f6;
            color: white;
        }
        
        .download-btn:hover {
            background-color: #2563eb;
        }
        
        .submission-btn {
            background-color: #10b981;
            color: white;
        }
        
        .submission-btn:hover {
            background-color: #059669;
        }
        
        .locked-badge {
            background-color: #6b7280;
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.6rem;
        }
        
        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }
        
        .file-drop-area {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            transition: border-color 0.3s ease;
            cursor: pointer;
        }
        
        .file-drop-area:hover {
            border-color: #3b82f6;
            background-color: #f8fafc;
        }
        
        .file-drop-area.dragover {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }
        
        .file-preview {
            display: flex;
            align-items: center;
            padding: 12px;
            background-color: #f8fafc;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .file-preview-icon {
            font-size: 24px;
            color: #dc2626;
            margin-right: 10px;
        }
        
        /* Batch selector styles */
        .batch-selector {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .batch-selector h3 {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
        }
        
        .batch-selector h3 i {
            margin-right: 8px;
        }
        
        .batch-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .batch-option {
            padding: 8px 16px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .batch-option:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .batch-option.active {
            background: white;
            color: #4f46e5;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
        }
        
        .batch-option .batch-count {
            margin-left: 6px;
            background: rgba(255, 255, 255, 0.3);
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.8rem;
        }
        
        .batch-option.active .batch-count {
            background: #4f46e5;
            color: white;
        }
        
        /* Batch stats cards */
        .batch-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .batch-stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-left: 4px solid;
        }
        
        .batch-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .batch-stat-card.batch1 {
            border-left-color: #4f46e5;
        }
        
        .batch-stat-card.batch2 {
            border-left-color: #10b981;
        }
        
        .batch-stat-card.batch3 {
            border-left-color: #f59e0b;
        }
        
        .batch-stat-card.history {
            border-left-color: #6b7280;
        }
        
        .batch-stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .batch-name {
            font-weight: 600;
            font-size: 1.1rem;
            color: #1f2937;
        }
        
        .batch-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-ongoing {
            background-color: #d1fae5;
            color: #047857;
        }
        
        .status-upcoming {
            background-color: #fef3c7;
            color: #d97706;
        }
        
        .status-completed {
            background-color: #e5e7eb;
            color: #4b5563;
        }
        
        .status-unknown {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        
        .batch-stats-numbers {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .batch-stat-number {
            text-align: center;
            padding: 8px;
            background: #f9fafb;
            border-radius: 8px;
        }
        
        .batch-stat-number .label {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .batch-stat-number .value {
            font-weight: 600;
            font-size: 1.25rem;
            color: #1f2937;
        }
        
        .batch-type-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-top: 8px;
        }
        
        .type-current {
            background-color: #dbeafe;
            color: #1d4ed8;
        }
        
        .type-history {
            background-color: #e5e7eb;
            color: #4b5563;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .text-sm-mobile {
                font-size: 0.875rem !important;
            }
            
            .text-lg-mobile {
                font-size: 1.125rem !important;
            }
            
            .overflow-x-auto {
                -webkit-overflow-scrolling: touch;
            }
            
            .batch-stats-grid {
                grid-template-columns: 1fr;
            }
            
            .batch-options {
                flex-direction: column;
            }
            
            .batch-option {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Mobile navigation styles */
        .mobile-nav-link.active {
            background-color: #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-weight: 600;
        }
        
        .mobile-nav-link i.active {
            transform: scale(1.1);
        }
        
        /* Mobile menu overlay */
        #mobileMenu {
            transition: opacity 0.3s ease-in-out;
        }
        
        /* Time display styles */
        .time-remaining {
            font-size: 0.7rem;
            margin-top: 2px;
            color: #6b7280;
        }
        
        .urgent {
            color: #dc2626;
            font-weight: 600;
        }
        
        .warning {
            color: #d97706;
            font-weight: 600;
        }
        
        .safe {
            color: #059669;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <!-- Mobile Header (Visible only on mobile) -->
    <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden">
        <!-- Mobile Menu Button -->
        <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
        
        <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-book-open text-indigo-600 text-sm"></i>
            </div>
            <span>My Content</span>
        </h1>
        
        <div class="flex items-center space-x-3">
            <!-- Batch Count Indicator -->
            <div class="text-xs bg-indigo-100 text-indigo-800 px-2 py-1 rounded-full">
                <?= count($all_batches) ?> Batch<?= count($all_batches) !== 1 ? 'es' : '' ?>
            </div>
            
            <!-- User Profile/Indicator -->
            <div class="relative">
                <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-graduate text-indigo-600"></i>
                </div>
            </div>
        </div>
    </header>

    <!-- Desktop Header (Hidden on mobile) -->
    <header class="hidden md:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
        <div class="flex-1"></div> <!-- Spacer for centering -->
        
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-book-open text-indigo-600 text-xl"></i>
            </div>
            <span>My Course Content</span>
        </h1>
        
        <div class="flex-1 flex justify-end items-center space-x-4">
            <span class="text-sm text-gray-600">Total Batches:</span>
            <span class="font-medium text-blue-600"><?= count($all_batches) ?></span>
            <div class="animate-pulse bg-indigo-100 rounded-full p-2">
                <i class="fas fa-user-graduate text-indigo-600"></i>
            </div>
        </div>
    </header>

    <!-- Mobile Navigation Menu -->
    <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden">
        <div class="absolute left-0 top-0 h-full w-4/5 max-w-xs bg-gradient-to-b from-blue-50 to-indigo-50 shadow-xl transform transition-transform duration-300 -translate-x-full">
            <!-- Mobile Menu Header -->
            <div class="p-4 border-b border-blue-200 bg-gradient-to-r from-blue-100 to-indigo-100">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                    </div>
                    <button onclick="toggleMobileMenu()" class="text-gray-500 hover:text-indigo-600 text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <!-- User Info -->
                <div class="mt-4 flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold">
                        <?= strtoupper(substr($student['first_name'] ?? 'S', 0, 1)) ?>
                    </div>
                    <div>
                        <p class="font-medium text-gray-800"><?= htmlspecialchars($student['first_name'] ?? 'Student') ?> <?= htmlspecialchars($student['last_name'] ?? '') ?></p>
                        <p class="text-xs text-gray-600">Student</p>
                    </div>
                </div>
            </div>
            
            <!-- Mobile Navigation Links -->
            <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                
                <a href="../stu_dash/dashboard.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'dashboard.php' ? 'bg-white shadow-md text-blue-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-tachometer-alt <?= $current_page == 'dashboard.php' ? 'text-blue-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Dashboard</span>
                </a>

                <a href="../stu_dash/my_batches.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_batches.php' ? 'bg-white shadow-md text-green-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-users <?= $current_page == 'my_batches.php' ? 'text-green-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Batches</span>
                </a>

                <a href="../stu_dash/upcoming.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'upcoming.php' ? 'bg-white shadow-md text-purple-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-calendar-alt <?= $current_page == 'upcoming.php' ? 'text-purple-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Upcoming Schedule</span>
                </a>

                <a href="../stu_dash/my_content.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_content.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-book <?= $current_page == 'my_content.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Content</span>
                </a>
                
                <a href="../student_test/student_dashboard.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_dashboard.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-vial <?= $current_page == 'student_dashboard.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Test</span>
                </a>

                <a href="../stu_dash/my_performance.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_performance.php' ? 'bg-white shadow-md text-red-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-chart-line <?= $current_page == 'my_performance.php' ? 'text-red-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Performance</span>
                </a>

                <a href="../stu_dash/student_feedback.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_feedback.php' ? 'bg-white shadow-md text-indigo-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-comment-dots <?= $current_page == 'student_feedback.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Feedback</span>
                </a>

                <a href="../stu_dash/student_profile.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_profile.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-user-circle <?= $current_page == 'student_profile.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Profile</span>
                </a>
                
                <!-- Logout Button -->
                <a href="../logout.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-red-50 hover:text-red-600 text-gray-700 mt-4 border-t pt-4"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-sign-out-alt text-red-500"></i>
                    </div>
                    <span class="font-medium">Logout</span>
                </a>
            </nav>
        </div>
    </div>

    <div class="p-4 md:p-6 bg-gray-50 min-h-screen">
        <!-- Messages -->
        <?php if (isset($_GET['message'])): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg animate__animated animate__fadeIn">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-400 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-green-800 font-medium"><?= htmlspecialchars($_GET['message']) ?></p>
                    </div>
                    <div class="ml-auto">
                        <button onclick="this.parentElement.parentElement.remove()" class="text-green-600 hover:text-green-800">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (isset($submission_message) && !$submission_success): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg animate__animated animate__fadeIn">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-400 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-red-800 font-medium"><?= htmlspecialchars($submission_message) ?></p>
                    </div>
                    <div class="ml-auto">
                        <button onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Batch Selector -->
        <?php if (count($all_batches) > 0): ?>
        <div class="batch-selector animate-fade-in mb-6">
            <h3><i class="fas fa-filter"></i> Filter by Batch</h3>
            <div class="batch-options">
                <button class="batch-option <?= $selected_batch === 'all' ? 'active' : '' ?>" 
                        onclick="selectBatch('all')">
                    <i class="fas fa-layer-group mr-2"></i> All Batches
                    <span class="batch-count"><?= $overall_stats['total'] ?></span>
                </button>
                
                <?php $batch_index = 1; ?>
                <?php foreach ($batch_details as $batch_id => $batch_detail): ?>
                    <?php if (isset($batch_stats[$batch_id])): ?>
                        <button class="batch-option <?= $selected_batch === $batch_id ? 'active' : '' ?>" 
                                onclick="selectBatch('<?= $batch_id ?>')">
                            <i class="fas fa-<?= $batch_detail['field'] === 'batch1' ? 'star' : ($batch_detail['field'] === 'batch2' ? 'star-half' : ($batch_detail['field'] === 'batch3' ? 'star-and-crescent' : 'history')) ?> mr-2"></i>
                            <?= htmlspecialchars($batch_detail['name']) ?>
                            <span class="batch-count"><?= $batch_stats[$batch_id]['total'] ?></span>
                        </button>
                        <?php $batch_index++; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="text-sm text-white/80 mt-2">
                <?php if ($selected_batch === 'all'): ?>
                    Showing content from all <?= count($all_batches) ?> batches
                <?php else: ?>
                    Showing content from: <strong><?= htmlspecialchars($batch_details[$selected_batch]['name'] ?? $selected_batch) ?></strong>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Batch Statistics Cards -->
        <?php if (count($batch_stats) > 0): ?>
        <div class="batch-stats-grid animate-fade-in delay-100">
            <?php $card_index = 1; ?>
            <?php foreach ($batch_stats as $batch_id => $stats): ?>
                <?php $batch_detail = $batch_details[$batch_id] ?? ['name' => $batch_id, 'field' => 'history', 'status' => 'unknown']; ?>
                <div class="batch-stat-card batch<?= $card_index ?> <?= $batch_detail['field'] ?>">
                    <div class="batch-stat-header">
                        <div class="batch-name">
                            <?= htmlspecialchars($stats['batch_name']) ?>
                            <div class="batch-type-badge <?= $batch_detail['field'] === 'history' ? 'type-history' : 'type-current' ?>">
                                <i class="fas fa-<?= $batch_detail['field'] === 'batch1' ? 'star' : ($batch_detail['field'] === 'batch2' ? 'star-half' : ($batch_detail['field'] === 'batch3' ? 'star-and-crescent' : 'history')) ?> mr-1"></i>
                                <?= $batch_detail['field'] === 'history' ? 'History' : 'Current' ?>
                            </div>
                        </div>
                        <div class="batch-status status-<?= $stats['status'] ?>">
                            <?= ucfirst($stats['status']) ?>
                        </div>
                    </div>
                    
                    <div class="batch-stats-numbers">
                        <div class="batch-stat-number">
                            <div class="label">Assignments</div>
                            <div class="value" style="color: #1d4ed8;"><?= $stats['stats']['Assignment'] ?></div>
                        </div>
                        <div class="batch-stat-number">
                            <div class="label">Tests</div>
                            <div class="value" style="color: #7e22ce;"><?= $stats['stats']['Test'] ?></div>
                        </div>
                        <div class="batch-stat-number">
                            <div class="label">Notes</div>
                            <div class="value" style="color: #047857;"><?= $stats['stats']['Notes'] ?></div>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <div class="text-xs text-gray-500 mb-1">Total Content</div>
                        <div class="text-2xl font-bold" style="color: <?= $card_index === 1 ? '#4f46e5' : ($card_index === 2 ? '#10b981' : ($card_index === 3 ? '#f59e0b' : '#6b7280')) ?>;">
                            <?= $stats['total'] ?>
                        </div>
                    </div>
                </div>
                <?php $card_index++; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Overall Content Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6 animate-fade-in">
            <div class="stat-card card p-4 flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Total Content</p>
                    <h3 class="text-2xl font-bold text-blue-700"><?= $overall_stats['total'] ?></h3>
                    <p class="text-xs text-gray-500">across <?= count($all_batches) ?> batches</p>
                </div>
                <div class="content-icon icon-test">
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>
            
            <div class="stat-card card p-4 flex items-center justify-between delay-100">
                <div>
                    <p class="text-sm text-gray-500">Assignments</p>
                    <h3 class="text-2xl font-bold text-purple-700"><?= $overall_stats['Assignment'] ?></h3>
                </div>
                <div class="content-icon icon-assignment">
                    <i class="fas fa-tasks"></i>
                </div>
            </div>
            
            <div class="stat-card card p-4 flex items-center justify-between delay-200">
                <div>
                    <p class="text-sm text-gray-500">Tests</p>
                    <h3 class="text-2xl font-bold text-green-700"><?= $overall_stats['Test'] ?></h3>
                </div>
                <div class="content-icon icon-notes">
                    <i class="fas fa-question-circle"></i>
                </div>
            </div>
            
            <div class="stat-card card p-4 flex items-center justify-between delay-100">
                <div>
                    <p class="text-sm text-gray-500">Notes</p>
                    <h3 class="text-2xl font-bold text-orange-700"><?= $overall_stats['Notes'] ?></h3>
                </div>
                <div class="content-icon icon-lab-manual">
                    <i class="fas fa-book"></i>
                </div>
            </div>
            
            <div class="stat-card card p-4 flex items-center justify-between delay-300">
                <div>
                    <p class="text-sm text-gray-500">Lab Manuals</p>
                    <h3 class="text-2xl font-bold text-amber-700"><?= $overall_stats['Lab Manual'] ?></h3>
                </div>
                <div class="content-icon icon-lab-manual">
                    <i class="fas fa-flask"></i>
                </div>
            </div>
        </div>
        
        <!-- Tabs Navigation -->
        <div class="card p-4 mb-6 animate-fade-in delay-200">
            <div class="border-b border-gray-200 mb-4">
                <nav class="flex space-x-1 overflow-x-auto pb-1">
                    <a href="?tab=all&batch=<?= $selected_batch ?>" 
                       class="px-4 py-2 text-sm font-medium rounded-lg transition-colors <?= $active_tab === 'all' ? 'tab-active' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' ?>">
                        <i class="fas fa-layer-group mr-2"></i>All Content
                        <span class="ml-1 px-2 py-0.5 text-xs bg-blue-100 text-blue-600 rounded-full">
                            <?= $overall_stats['total'] ?>
                        </span>
                    </a>
                    <a href="?tab=assignments&batch=<?= $selected_batch ?>" 
                       class="px-4 py-2 text-sm font-medium rounded-lg transition-colors <?= $active_tab === 'assignments' ? 'tab-active' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' ?>">
                        <i class="fas fa-tasks mr-2"></i>Assignments
                        <span class="ml-1 px-2 py-0.5 text-xs bg-purple-100 text-purple-600 rounded-full">
                            <?= $overall_stats['Assignment'] ?>
                        </span>
                    </a>
                    <a href="?tab=tests&batch=<?= $selected_batch ?>" 
                       class="px-4 py-2 text-sm font-medium rounded-lg transition-colors <?= $active_tab === 'tests' ? 'tab-active' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' ?>">
                        <i class="fas fa-question-circle mr-2"></i>Tests
                        <span class="ml-1 px-2 py-0.5 text-xs bg-green-100 text-green-600 rounded-full">
                            <?= $overall_stats['Test'] ?>
                        </span>
                    </a>
                    <a href="?tab=notes&batch=<?= $selected_batch ?>" 
                       class="px-4 py-2 text-sm font-medium rounded-lg transition-colors <?= $active_tab === 'notes' ? 'tab-active' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' ?>">
                        <i class="fas fa-book mr-2"></i>Notes
                        <span class="ml-1 px-2 py-0.5 text-xs bg-orange-100 text-orange-600 rounded-full">
                            <?= $overall_stats['Notes'] ?>
                        </span>
                    </a>
                    <a href="?tab=lab_manuals&batch=<?= $selected_batch ?>" 
                       class="px-4 py-2 text-sm font-medium rounded-lg transition-colors <?= $active_tab === 'lab_manuals' ? 'tab-active' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' ?>">
                        <i class="fas fa-flask mr-2"></i>Lab Manuals
                        <span class="ml-1 px-2 py-0.5 text-xs bg-amber-100 text-amber-600 rounded-full">
                            <?= $overall_stats['Lab Manual'] ?>
                        </span>
                    </a>
                </nav>
            </div>
            
            <!-- Search and Filter Bar -->
            <div class="flex flex-col md:flex-row md:items-center justify-between space-y-4 md:space-y-0 md:space-x-4">
                <div class="flex-1">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="searchInput" placeholder="Search content..." 
                               class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                </div>
                
                <div class="flex space-x-2 overflow-x-auto pb-2">
                    <select id="typeFilter" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent min-w-[120px]">
                        <option value="all" <?= $fileTypeFilter === 'all' || empty($fileTypeFilter) ? 'selected' : '' ?>>All Types</option>
                        <option value="Test" <?= $fileTypeFilter === 'Test' ? 'selected' : '' ?>>Tests</option>
                        <option value="Assignment" <?= $fileTypeFilter === 'Assignment' ? 'selected' : '' ?>>Assignments</option>
                        <option value="Notes" <?= $fileTypeFilter === 'Notes' ? 'selected' : '' ?>>Notes</option>
                        <option value="Lab Manual" <?= $fileTypeFilter === 'Lab Manual' ? 'selected' : '' ?>>Lab Manuals</option>
                        <option value="Other" <?= $fileTypeFilter === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                    
                    <input type="date" id="dateFrom" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent min-w-[140px]"
                           value="<?= htmlspecialchars($dateFrom) ?>" placeholder="From Date">
                    
                    <input type="date" id="dateTo" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent min-w-[140px]"
                           value="<?= htmlspecialchars($dateTo) ?>" placeholder="To Date">
                    
                    <button onclick="applyFilters()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center whitespace-nowrap">
                        <i class="fas fa-filter mr-2"></i> Apply
                    </button>
                    
                    <button onclick="resetFilters()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 flex items-center whitespace-nowrap">
                        <i class="fas fa-redo mr-2"></i> Reset
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Content Display -->
        <div class="card p-6 animate-fade-in delay-300">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold flex items-center text-lg-mobile">
                    <i class="fas fa-folder-open mr-2 text-blue-500"></i>
                    <span>
                        <?= $active_tab === 'all' ? 'All Course Content' : 
                           ($active_tab === 'assignments' ? 'Assignments' : 
                           ($active_tab === 'tests' ? 'Tests' : 
                           ($active_tab === 'notes' ? 'Notes' : 
                           ($active_tab === 'lab_manuals' ? 'Lab Manuals' : 'Course Content')))) ?>
                    </span>
                    <?php if ($selected_batch !== 'all'): ?>
                        <span class="text-sm font-normal text-blue-600 ml-2">
                            (<?= htmlspecialchars($batch_details[$selected_batch]['name'] ?? $selected_batch) ?>)
                        </span>
                    <?php endif; ?>
                </h2>
                <div class="text-sm text-gray-500 text-sm-mobile flex items-center">
                    <i class="fas fa-info-circle mr-1 text-blue-500"></i>
                    Total: <span class="font-bold ml-1">
                        <?= $active_tab === 'all' ? count($content_items) : 
                           ($active_tab === 'assignments' ? count($assignments) : 
                           ($active_tab === 'tests' ? count($tests) : 
                           ($active_tab === 'notes' ? count($notes) : 
                           ($active_tab === 'lab_manuals' ? count($lab_manuals) : 0)))) ?>
                    </span> items
                    <?php if ($selected_batch === 'all'): ?>
                        <span class="ml-2 text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded-full">
                            <?= count($all_batches) ?> batches
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- One-time submission notice for mobile -->
            <?php if ($active_tab === 'assignments'): ?>
            <div class="md:hidden mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-info-circle text-blue-400 mr-2"></i>
                    <p class="text-xs text-blue-800">One-time submission only</p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Content Table -->
            <div class="overflow-x-auto rounded-lg border border-gray-200 -mx-2 md:mx-0">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable" 
                                onclick="sortTable('title')">
                                <div class="flex items-center">
                                    <span>Content</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'title' ? 'active' : '' ?> <?= $sortColumn === 'title' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable" 
                                onclick="sortTable('file_type')">
                                <div class="flex items-center">
                                    <span>Type</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'file_type' ? 'active' : '' ?> <?= $sortColumn === 'file_type' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable hidden md:table-cell" 
                                onclick="sortTable('batch_name')">
                                <div class="flex items-center">
                                    <span>Batch</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'batch_name' ? 'active' : '' ?> <?= $sortColumn === 'batch_name' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <?php if ($active_tab === 'assignments' || $active_tab === 'all'): ?>
                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable hidden md:table-cell" 
                                onclick="sortTable('due_date')">
                                <div class="flex items-center">
                                    <span>Due Date & Time (IST)</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'due_date' ? 'active' : '' ?> <?= $sortColumn === 'due_date' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Status</th>
                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Grade</th>
                            <?php else: ?>
                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable hidden md:table-cell" 
                                onclick="sortTable('uploaded_at')">
                                <div class="flex items-center">
                                    <span>Uploaded</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'uploaded_at' ? 'active' : '' ?> <?= $sortColumn === 'uploaded_at' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <?php endif; ?>
                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php 
                        $items_to_display = [];
                        if ($active_tab === 'assignments') $items_to_display = $assignments;
                        elseif ($active_tab === 'tests') $items_to_display = $tests;
                        elseif ($active_tab === 'notes') $items_to_display = $notes;
                        elseif ($active_tab === 'lab_manuals') $items_to_display = $lab_manuals;
                        else $items_to_display = $content_items;
                        
                        if (empty($items_to_display)): ?>
                            <tr>
                                <td colspan="<?= ($active_tab === 'assignments' || $active_tab === 'all') ? 7 : 5 ?>" class="px-6 py-8 text-center">
                                    <div class="flex flex-col items-center justify-center space-y-2 text-gray-400">
                                        <i class="fas fa-box-open text-4xl"></i>
                                        <p class="text-sm">No content found</p>
                                        <?php if (empty($all_batches)): ?>
                                            <p class="text-sm text-red-500 mt-2">You are not enrolled in any batches</p>
                                        <?php elseif (!empty($searchTerm) || !empty($fileTypeFilter) || !empty($dateFrom) || !empty($dateTo) || $selected_batch !== 'all'): ?>
                                            <button onclick="resetFilters()" class="text-blue-500 hover:text-blue-700 text-sm mt-2">
                                                Clear filters to see all content
                                            </button>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-500 mt-2">Content will appear here when uploaded by your trainers</p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items_to_display as $index => $item): ?>
                                <?php
                                // Determine file icon
                                $file_path = $item['file_path'];
                                $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                                $file_icon_class = 'icon-default';
                                $file_icon = 'fa-file';
                                
                                if (in_array($file_extension, ['pdf'])) {
                                    $file_icon_class = 'icon-pdf';
                                    $file_icon = 'fa-file-pdf';
                                } elseif (in_array($file_extension, ['doc', 'docx'])) {
                                    $file_icon_class = 'icon-doc';
                                    $file_icon = 'fa-file-word';
                                }
                                
                                // Check if file exists
                                $download_path = $file_path;
                                $file_exists = false;
                                
                                // Check if it's a full URL (starts with http)
                                if (strpos($file_path, 'http') === 0) {
                                    $file_exists = true;
                                } else {
                                    // Check if the file exists on the server
                                    $full_path = __DIR__ . '/' . $file_path;
                                    
                                    if (!file_exists($full_path) && strpos($file_path, 'uploads/') !== 0) {
                                        $full_path = __DIR__ . '/uploads/' . $file_path;
                                        if (file_exists($full_path)) {
                                            $download_path = 'uploads/' . $file_path;
                                            $file_exists = true;
                                        } else {
                                            $full_path = dirname(__DIR__) . '/uploads/' . $file_path;
                                            if (file_exists($full_path)) {
                                                $download_path = '../uploads/' . $file_path;
                                                $file_exists = true;
                                            }
                                        }
                                    } elseif (file_exists($full_path)) {
                                        $file_exists = true;
                                    }
                                    
                                    if (!$file_exists) {
                                        $uploads_content_path = dirname(__DIR__) . '/uploads/content/' . basename($file_path);
                                        if (file_exists($uploads_content_path)) {
                                            $download_path = '../uploads/content/' . basename($file_path);
                                            $file_exists = true;
                                        }
                                    }
                                }
                                
                                // Determine batch type
                                $batch_id = $item['batch_id'];
                                $batch_field = $batch_details[$batch_id]['field'] ?? 'history';
                                $batch_class = '';
                                $batch_icon = '';
                                
                                switch($batch_field) {
                                    case 'batch1':
                                        $batch_class = 'batch-current';
                                        $batch_icon = 'fa-star';
                                        $batch_text = 'Batch 1';
                                        break;
                                    case 'batch2':
                                        $batch_class = 'batch-current';
                                        $batch_icon = 'fa-star-half';
                                        $batch_text = 'Batch 2';
                                        break;
                                    case 'batch3':
                                        $batch_class = 'batch-current';
                                        $batch_icon = 'fa-star-and-crescent';
                                        $batch_text = 'Batch 3';
                                        break;
                                    default:
                                        $batch_class = 'batch-history';
                                        $batch_icon = 'fa-history';
                                        $batch_text = 'History';
                                }
                                
                                // For assignments: get due status
                                $due_status = $item['due_status'] ?? '';
                                $due_class = $item['due_class'] ?? '';
                                $time_remaining = $item['time_remaining'] ?? '';
                                
                                // For assignments: get submission status
                                $has_submitted = $item['has_submitted'] ?? 0;
                                $can_resubmit = $item['can_resubmit'] ?? 0;
                                $status_text = $item['submission_status'] ?? 'not_submitted';
                                $status_class = 'status-' . $status_text;
                                
                                if ($item['file_type'] === 'Assignment') {
                                    if ($has_submitted) {
                                        $status_text = 'Submitted (Final)';
                                        $status_class = 'status-final';
                                    } else {
                                        $status_text = 'Not Submitted';
                                        $status_class = 'status-not-submitted';
                                    }
                                }
                                
                                // Check if submission file exists
                                $submission_exists = false;
                                $submission_download_path = '';
                                if (isset($item['submission_file_path']) && $item['submission_file_path']) {
                                    $submission_download_path = $item['submission_file_path'];
                                    $submission_exists = file_exists($submission_download_path) || file_exists(__DIR__ . '/' . $submission_download_path);
                                }
                                ?>
                                <tr class="fade-in <?= ($item['file_type'] === 'Assignment' && $has_submitted) ? 'submission-locked' : '' ?>" 
                                    style="animation-delay: <?= $index * 0.05 ?>s">
                                    <td class="px-4 md:px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="file-icon <?= $file_icon_class ?> hidden md:flex">
                                                <i class="fas <?= $file_icon ?>"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm font-medium text-gray-900 truncate">
                                                    <?= htmlspecialchars($item['title']) ?>
                                                    <?php if (!$file_exists && !(strpos($file_path, 'http') === 0)): ?>
                                                        <span class="ml-1 text-xs text-red-500" title="File not found">
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($item['file_type'] === 'Assignment' && $has_submitted): ?>
                                                        <span class="ml-1 text-xs text-gray-500 hidden md:inline" title="One-time submission - Final">
                                                            <i class="fas fa-lock"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($item['description']): ?>
                                                    <div class="text-xs text-gray-500 mt-1 truncate max-w-xs hidden md:block">
                                                        <?= htmlspecialchars($item['description']) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <!-- Mobile-only info -->
                                                <div class="md:hidden text-xs text-gray-500 mt-1 space-y-1">
                                                    <?php if ($item['batch_name']): ?>
                                                        <div class="flex items-center">
                                                            <i class="fas <?= $batch_icon ?> mr-1 text-gray-400"></i>
                                                            <span class="batch-indicator <?= $batch_class ?> px-1 rounded text-xs">
                                                                <?= $batch_text ?>: <?= htmlspecialchars($item['batch_name']) ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($item['file_type'] === 'Assignment' && $item['due_date']): ?>
                                                        <div class="flex items-center">
                                                            <i class="far fa-calendar-alt mr-1 text-gray-400"></i>
                                                            <span class="<?= $due_class ?> px-1 rounded">
                                                                <?= date('M j, Y', strtotime($item['due_date'])) ?>
                                                                <?php if (!empty($item['due_time'])): ?>
                                                                    <?= date('h:i A', strtotime($item['due_time'])) ?>
                                                                <?php else: ?>
                                                                    11:59 PM
                                                                <?php endif; ?>
                                                                <?php if ($due_status && $due_status !== date('M j, Y', strtotime($item['due_date']))): ?>
                                                                    (<?= $due_status ?>)
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($item['file_type'] === 'Assignment'): ?>
                                                        <div class="flex items-center">
                                                            <i class="fas fa-tasks mr-1 text-gray-400"></i>
                                                            <span class="<?= $status_class ?> px-1 rounded text-xs"><?= ucfirst($status_text) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $badgeClass = '';
                                        $iconClass = '';
                                        switch($item['file_type']) {
                                            case 'Test':
                                                $badgeClass = 'badge-test';
                                                $iconClass = 'fa-question-circle';
                                                break;
                                            case 'Assignment':
                                                $badgeClass = 'badge-assignment';
                                                $iconClass = 'fa-tasks';
                                                break;
                                            case 'Notes':
                                                $badgeClass = 'badge-notes';
                                                $iconClass = 'fa-book';
                                                break;
                                            case 'Lab Manual':
                                                $badgeClass = 'badge-lab-manual';
                                                $iconClass = 'fa-flask';
                                                break;
                                            default:
                                                $badgeClass = 'badge-other';
                                                $iconClass = 'fa-file';
                                        }
                                        ?>
                                        <span class="badge <?= $badgeClass ?> text-sm-mobile">
                                            <i class="fas <?= $iconClass ?> mr-1 hidden md:inline"></i>
                                            <?= htmlspecialchars($item['file_type']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap hidden md:table-cell">
                                        <div class="flex flex-col">
                                            <span class="batch-indicator <?= $batch_class ?>">
                                                <i class="fas <?= $batch_icon ?> mr-1 text-xs"></i>
                                                <?= $batch_text ?>
                                            </span>
                                            <span class="text-xs text-gray-500 mt-1">
                                                <?= htmlspecialchars($item['batch_name']) ?>
                                            </span>
                                            <span class="text-xs text-gray-400">
                                                <?= htmlspecialchars($item['batch_time'] ?? '') ?>
                                            </span>
                                        </div>
                                    </td>
                                    <?php if ($active_tab === 'assignments' || $active_tab === 'all'): ?>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap hidden md:table-cell">
                                        <?php if ($item['due_date']): ?>
                                            <div class="flex flex-col">
                                                <span class="due-date-badge <?= $due_class ?>">
                                                    <i class="far fa-calendar-alt mr-1"></i>
                                                    <?= date('M j, Y', strtotime($item['due_date'])) ?>
                                                    <?php if (!empty($item['due_time'])): ?>
                                                        <span class="text-xs ml-1">
                                                            <?= date('h:i A', strtotime($item['due_time'])) ?> IST
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-xs ml-1">11:59 PM IST</span>
                                                    <?php endif; ?>
                                                </span>
                                                <?php if (!empty($due_status) && $due_status !== date('M j, Y', strtotime($item['due_date']))): ?>
                                                    <span class="text-xs <?= $due_class ?> mt-1">
                                                        <?= htmlspecialchars($due_status) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($time_remaining)): ?>
                                                    <span class="text-xs text-gray-500 mt-1">
                                                        <?= htmlspecialchars($time_remaining) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">No due date</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap hidden md:table-cell">
                                        <?php if ($item['file_type'] === 'Assignment'): ?>
                                            <span class="status-badge <?= $status_class ?>">
                                                <i class="fas 
                                                    <?= $has_submitted ? 'fa-lock' : 'fa-exclamation-circle' ?> 
                                                    mr-1"></i>
                                                <?= ucfirst($status_text) ?>
                                            </span>
                                            <?php if (isset($item['submission_date']) && $item['submission_date']): ?>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    Submitted: <?= date('M j, Y H:i', strtotime($item['submission_date'])) ?> IST
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap hidden md:table-cell">
                                        <?php if ($item['file_type'] === 'Assignment' && isset($item['student_grade']) && $item['student_grade'] !== null): ?>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?= $item['student_grade'] ?>/<?= $item['max_marks'] ?? 100 ?>
                                            </div>
                                            <?php if ($item['student_grade'] < ($item['max_marks'] ?? 100) * 0.4): ?>
                                                <div class="text-xs text-red-500">Needs improvement</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php else: ?>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm text-gray-500 hidden md:table-cell">
                                        <div class="flex items-center">
                                            <i class="far fa-calendar-alt mr-2 text-gray-400"></i>
                                            <?= date('M j, Y', strtotime($item['uploaded_at'])) ?>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                    <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex flex-wrap gap-2">
                                            <!-- ALWAYS show assignment file download if it exists -->
                                            <?php if ($file_exists || strpos($file_path, 'http') === 0): ?>
                                                <?php if (strpos($file_path, 'http') === 0): ?>
                                                    <!-- External URL - open in new tab -->
                                                    <a href="<?= htmlspecialchars($download_path) ?>" 
                                                       target="_blank"
                                                       class="action-btn download-btn text-white hover:text-white px-3 py-1 rounded-md flex items-center text-xs md:text-sm">
                                                        <i class="fas fa-external-link-alt mr-1"></i> 
                                                        <span class="hidden md:inline"><?= $item['file_type'] === 'Assignment' ? 'View Assignment' : 
                                                           ($item['file_type'] === 'Test' ? 'View Test' : 
                                                           ($item['file_type'] === 'Lab Manual' ? 'View Lab Manual' : 'View Content')) ?></span>
                                                        <span class="md:hidden">View</span>
                                                    </a>
                                                <?php else: ?>
                                                    <!-- Local file - download -->
                                                    <a href="<?= htmlspecialchars($download_path) ?>" 
                                                       download
                                                       class="action-btn download-btn text-white hover:text-white px-3 py-1 rounded-md flex items-center text-xs md:text-sm">
                                                        <i class="fas fa-download mr-1"></i> 
                                                        <span class="hidden md:inline"><?= $item['file_type'] === 'Assignment' ? 'Download Assignment' : 
                                                           ($item['file_type'] === 'Test' ? 'Download Test' : 
                                                           ($item['file_type'] === 'Lab Manual' ? 'Download Lab Manual' : 'Download')) ?></span>
                                                        <span class="md:hidden">Download</span>
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-red-500 text-sm px-3 py-1 bg-red-50 rounded-md" title="File not found on server">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i> 
                                                    <span class="hidden md:inline"><?= $item['file_type'] === 'Assignment' ? 'Assignment Unavailable' : 
                                                       ($item['file_type'] === 'Test' ? 'Test Unavailable' : 
                                                       ($item['file_type'] === 'Lab Manual' ? 'Lab Manual Unavailable' : 'File Unavailable')) ?></span>
                                                    <span class="md:hidden">Unavailable</span>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <!-- For assignments only -->
                                            <?php if ($item['file_type'] === 'Assignment'): ?>
                                                <?php if ($has_submitted): ?>
                                                    <!-- Already submitted - Show download for submission file -->
                                                    <?php if ($submission_exists): ?>
                                                        <a href="<?= htmlspecialchars($submission_download_path) ?>" 
                                                           download
                                                           class="action-btn bg-green-600 text-white hover:bg-green-700 px-3 py-1 rounded-md flex items-center text-xs md:text-sm">
                                                            <i class="fas fa-download mr-1"></i> 
                                                            <span class="hidden md:inline">My Submission</span>
                                                            <span class="md:hidden">My Sub</span>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-yellow-600 text-sm px-3 py-1 bg-yellow-50 rounded-md text-xs md:text-sm">
                                                            <i class="fas fa-exclamation-circle mr-1"></i> 
                                                            <span class="hidden md:inline">Submission File Missing</span>
                                                            <span class="md:hidden">Missing</span>
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Locked message -->
                                                    <span class="locked-badge rounded-md px-3 py-1 flex items-center text-xs">
                                                        <i class="fas fa-lock mr-1"></i> 
                                                        <span class="hidden md:inline">Final</span>
                                                        <span class="md:hidden">✓</span>
                                                    </span>
                                                <?php else: ?>
                                                    <!-- Not submitted yet - Show submit button -->
                                                    <button type="button" 
                                                            onclick="openSubmitModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['title'])) ?>')"
                                                            class="action-btn submission-btn text-white hover:text-white px-3 py-1 rounded-md flex items-center text-xs md:text-sm">
                                                        <i class="fas fa-upload mr-1"></i> 
                                                        <span class="hidden md:inline">Submit Now</span>
                                                        <span class="md:hidden">Submit</span>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <!-- Preview option for PDFs (non-assignment content) -->
                                            <?php if ($item['file_type'] !== 'Assignment' && in_array($file_extension, ['pdf']) && $file_exists): ?>
                                                <a href="<?= htmlspecialchars($download_path) ?>" 
                                                   target="_blank"
                                                   class="action-btn text-green-600 hover:text-green-900 px-3 py-1 rounded-md bg-green-50 hover:bg-green-100 text-xs md:text-sm">
                                                    <i class="fas fa-eye mr-1"></i> 
                                                    <span class="hidden md:inline">Preview</span>
                                                    <span class="md:hidden">View</span>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Assignment Submission Notice -->
            <?php if ($active_tab === 'assignments' || $active_tab === 'all'): ?>
            <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-400 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <h4 class="text-sm font-medium text-blue-800">Important Note</h4>
                        <p class="text-sm text-blue-600 mt-1">
                            <strong>One-time submission policy:</strong> Assignments can only be submitted once. 
                            Once submitted, you cannot update or resubmit. You will be able to download both the original assignment file and your submitted file.
                        </p>
                        <ul class="text-xs text-blue-600 mt-2 list-disc pl-5 hidden md:block">
                            <li>You can always download the assignment file, even after submission</li>
                            <li>After submission, you can download your submitted file</li>
                            <li>Make sure your submission is complete and accurate - no changes allowed</li>
                            <li>Only PDF files are accepted for submission</li>
                            <li>Check the due date and time - late submissions will be marked as 'late'</li>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Batch Information Summary -->
        <div class="card p-6 mt-6 animate-fade-in delay-400">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                Batch Information Summary
            </h3>
            
            <?php if (count($batch_details) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($batch_details as $batch_id => $detail): ?>
                        <?php if (isset($batch_stats[$batch_id])): ?>
                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 p-4 rounded-xl border border-indigo-100">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <h4 class="font-semibold text-indigo-800">
                                            <?= htmlspecialchars($detail['name']) ?>
                                        </h4>
                                        <p class="text-xs text-gray-600">ID: <?= htmlspecialchars($batch_id) ?></p>
                                    </div>
                                    <span class="px-2 py-1 text-xs rounded-full 
                                        <?= $detail['status'] === 'ongoing' ? 'bg-green-100 text-green-800' : 
                                           ($detail['status'] === 'completed' ? 'bg-gray-100 text-gray-800' : 
                                           ($detail['status'] === 'upcoming' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800')) ?>">
                                        <?= htmlspecialchars(ucfirst($detail['status'] ?? 'Unknown')) ?>
                                    </span>
                                </div>
                                
                                <div class="space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600">Total Content:</span>
                                        <span class="font-medium"><?= $batch_stats[$batch_id]['total'] ?></span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600">Assignments:</span>
                                        <span class="font-medium text-purple-600"><?= $batch_stats[$batch_id]['stats']['Assignment'] ?></span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600">Tests:</span>
                                        <span class="font-medium text-green-600"><?= $batch_stats[$batch_id]['stats']['Test'] ?></span>
                                    </div>
                                    <?php if ($detail['time_slot']): ?>
                                        <div class="flex justify-between text-sm">
                                            <span class="text-gray-600">Schedule:</span>
                                            <span class="font-medium"><?= htmlspecialchars($detail['time_slot']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-3 pt-3 border-t border-gray-200">
                                    <span class="text-xs text-gray-500">
                                        <i class="fas fa-<?= $detail['field'] === 'batch1' ? 'star' : ($detail['field'] === 'batch2' ? 'star-half' : ($detail['field'] === 'batch3' ? 'star-and-crescent' : 'history')) ?> mr-1"></i>
                                        <?= $detail['field'] === 'history' ? 'Historical Batch' : 'Current Batch' ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <div class="bg-indigo-50 inline-block p-4 rounded-full mb-4">
                        <i class="fas fa-exclamation-circle text-indigo-500 text-3xl"></i>
                    </div>
                    <p class="text-gray-500 text-lg">No batch information available</p>
                    <p class="text-gray-400 mt-2">Please contact administration for assistance</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Submission Modal -->
<div id="submissionModal" class="modal-overlay">
    <div class="modal-content p-0">
        <div class="bg-blue-600 text-white p-6 rounded-t-lg">
            <h3 class="text-xl font-bold flex items-center">
                <i class="fas fa-upload mr-2"></i>
                <span id="modalTitle">Submit Assignment</span>
            </h3>
        </div>
        
        <form id="submissionForm" method="POST" enctype="multipart/form-data" class="p-6">
            <input type="hidden" name="upload_id" id="modalUploadId" value="">
            
            <div class="mb-6">
                <p class="text-gray-600 mb-4">
                    <strong class="text-red-600">Important:</strong> This is a one-time submission. You cannot update or resubmit once submitted.
                    Submissions after the due date/time will be marked as 'late'.
                </p>
                
                <div class="file-drop-area" id="fileDropAreaModal">
                    <input type="file" id="submission_file" name="submission_file" required 
                           accept=".pdf" class="hidden">
                    <div class="flex flex-col items-center justify-center">
                        <i class="fas fa-file-pdf text-5xl text-red-400 mb-3"></i>
                        <p class="text-sm text-gray-600">Click to browse or drag & drop PDF file</p>
                        <p class="text-xs text-gray-500 mt-1">Maximum file size: 10MB</p>
                        <div id="fileNameDisplayModal" class="mt-3 text-sm font-medium text-blue-600">
                            No file selected
                        </div>
                    </div>
                </div>
                
                <div id="filePreview" class="file-preview hidden">
                    <i class="fas fa-file-pdf file-preview-icon"></i>
                    <div class="flex-1">
                        <div class="font-medium" id="previewFileName"></div>
                        <div class="text-xs text-gray-500" id="previewFileSize"></div>
                    </div>
                    <button type="button" onclick="removeFile()" class="text-red-500 hover:text-red-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Cancel
                </button>
                <button type="submit" id="submitBtn" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center">
                    <i class="fas fa-upload mr-2"></i>
                    Submit Assignment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Function to toggle mobile menu
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

// Close mobile menu when clicking outside
document.getElementById('mobileMenu').addEventListener('click', function(e) {
    if (e.target.id === 'mobileMenu') {
        toggleMobileMenu();
    }
});

// Handle ESC key to close mobile menu
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const mobileMenu = document.getElementById('mobileMenu');
        if (!mobileMenu.classList.contains('hidden')) {
            toggleMobileMenu();
        }
    }
});

// Add active state to current page link in mobile menu
document.addEventListener('DOMContentLoaded', function() {
    const currentPage = '<?= basename($_SERVER['PHP_SELF']) ?>';
    const mobileLinks = document.querySelectorAll('.mobile-nav-link');
    
    mobileLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href.includes(currentPage)) {
            link.classList.add('bg-white', 'shadow-md');
            const icon = link.querySelector('i');
            if (icon) {
                if (currentPage === 'dashboard.php') icon.classList.add('text-blue-600');
                else if (currentPage === 'my_batches.php') icon.classList.add('text-green-600');
                else if (currentPage === 'upcoming.php') icon.classList.add('text-purple-600');
                else if (currentPage === 'my_content.php') icon.classList.add('text-yellow-600');
                else if (currentPage === 'student_dashboard.php') icon.classList.add('text-yellow-600');
                else if (currentPage === 'my_performance.php') icon.classList.add('text-red-600');
                else if (currentPage === 'student_feedback.php') icon.classList.add('text-indigo-600');
                else if (currentPage === 'student_profile.php') icon.classList.add('text-cyan-600');
            }
        }
    });
    
    // Initialize search input with debounce
    const searchInput = document.getElementById('searchInput');
    let searchTimeout;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            applyFilters();
        }, 500);
    });
    
    // Initialize date pickers
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('dateFrom').max = today;
    document.getElementById('dateTo').max = today;
    
    // Initialize file upload for modal
    initFileUpload();
    
    // Prevent form double submission
    const submissionForm = document.getElementById('submissionForm');
    if (submissionForm) {
        submissionForm.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...';
        });
    }
    
    // Add staggered animations for table rows
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, index) => {
        row.style.animationDelay = `${index * 0.05}s`;
    });
    
    // Animate cards on page load
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        card.classList.add('animate-fade-in');
        card.classList.add(`delay-${(index % 3) + 1}00`);
    });
});

function sortTable(column) {
    const currentUrl = new URL(window.location.href);
    const currentSort = currentUrl.searchParams.get('sort');
    const currentOrder = currentUrl.searchParams.get('order');
    const currentBatch = currentUrl.searchParams.get('batch') || 'all';
    const currentTab = currentUrl.searchParams.get('tab') || 'all';
    
    let newOrder = 'DESC';
    if (currentSort === column) {
        newOrder = currentOrder === 'DESC' ? 'ASC' : 'DESC';
    }
    
    currentUrl.searchParams.set('sort', column);
    currentUrl.searchParams.set('order', newOrder);
    currentUrl.searchParams.set('batch', currentBatch);
    currentUrl.searchParams.set('tab', currentTab);
    window.location.href = currentUrl.toString();
}

function applyFilters() {
    const searchTerm = document.getElementById('searchInput').value;
    const fileType = document.getElementById('typeFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const activeTab = '<?= $active_tab ?>';
    const selectedBatch = '<?= $selected_batch ?>';
    
    const params = new URLSearchParams();
    
    if (searchTerm) params.set('search', searchTerm);
    if (fileType && fileType !== 'all') params.set('type', fileType);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    if (activeTab) params.set('tab', activeTab);
    if (selectedBatch) params.set('batch', selectedBatch);
    
    // Preserve sort order
    const currentSort = new URL(window.location.href).searchParams.get('sort');
    const currentOrder = new URL(window.location.href).searchParams.get('order');
    if (currentSort) params.set('sort', currentSort);
    if (currentOrder) params.set('order', currentOrder);
    
    window.location.href = `?${params.toString()}`;
}

function resetFilters() {
    const activeTab = '<?= $active_tab ?>';
    const selectedBatch = '<?= $selected_batch ?>';
    window.location.href = `my_content.php?tab=${activeTab}&batch=${selectedBatch}`;
}

// Batch selection function
function selectBatch(batchId) {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('batch', batchId);
    window.location.href = currentUrl.toString();
}

function openSubmitModal(uploadId, assignmentTitle) {
    // Show warning first
    Swal.fire({
        title: 'One-time Submission Warning',
        html: `
            <div class="text-left">
                <p class="mb-3"><strong>IMPORTANT:</strong> This is a <span class="text-red-600 font-bold">ONE-TIME SUBMISSION</span>.</p>
                <ul class="list-disc pl-5 text-sm mb-4 space-y-1">
                    <li>You can only submit <strong>once</strong> per assignment</li>
                    <li><strong>NO updates or resubmissions</strong> allowed</li>
                    <li>Make sure your file is correct before submitting</li>
                    <li>Only PDF files are accepted</li>
                    <li>Maximum file size: 10MB</li>
                    <li>Submissions after due date/time will be marked as 'late'</li>
                    <li>You can download the assignment file anytime, even after submission</li>
                    <li>After submission, you can download your submitted file</li>
                </ul>
                <div class="bg-red-50 border border-red-200 p-3 rounded mb-3">
                    <p class="text-red-700 text-sm font-bold">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        This action cannot be undone!
                    </p>
                </div>
                <p class="text-sm text-gray-600">Are you ready to submit your final answer?</p>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, I understand',
        cancelButtonText: 'No, let me check',
        width: 600,
        customClass: {
            popup: 'text-left'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Set modal values
            document.getElementById('modalUploadId').value = uploadId;
            document.getElementById('modalTitle').textContent = 'Submit: ' + assignmentTitle;
            
            // Reset file input
            const fileInput = document.getElementById('submission_file');
            fileInput.value = '';
            document.getElementById('fileNameDisplayModal').textContent = 'No file selected';
            document.getElementById('filePreview').classList.add('hidden');
            
            // Show modal
            document.getElementById('submissionModal').classList.add('active');
        }
    });
}

function closeModal() {
    document.getElementById('submissionModal').classList.remove('active');
    // Reset form
    const form = document.getElementById('submissionForm');
    if (form) {
        form.reset();
    }
}

function initFileUpload() {
    const fileDropArea = document.getElementById('fileDropAreaModal');
    const fileInput = document.getElementById('submission_file');
    const fileNameDisplay = document.getElementById('fileNameDisplayModal');
    const filePreview = document.getElementById('filePreview');
    const previewFileName = document.getElementById('previewFileName');
    const previewFileSize = document.getElementById('previewFileSize');
    
    if (!fileDropArea || !fileInput) return;
    
    fileDropArea.addEventListener('click', () => fileInput.click());
    
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) {
            const file = fileInput.files[0];
            handleFileSelection(file);
        }
    });
    
    // Drag and drop events
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        fileDropArea.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        fileDropArea.addEventListener(eventName, () => {
            fileDropArea.classList.add('dragover');
        }, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        fileDropArea.addEventListener(eventName, () => {
            fileDropArea.classList.remove('dragover');
        }, false);
    });
    
    fileDropArea.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        
        if (files.length) {
            const file = files[0];
            // Check if it's a PDF
            if (file.type === 'application/pdf') {
                fileInput.files = files;
                handleFileSelection(file);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid File',
                    text: 'Only PDF files are allowed for submissions'
                });
            }
        }
    }, false);
    
    function handleFileSelection(file) {
        // Check file size (max 10MB)
        const maxSize = 10 * 1024 * 1024; // 10MB in bytes
        if (file.size > maxSize) {
            Swal.fire({
                icon: 'error',
                title: 'File Too Large',
                text: 'File size must be less than 10MB'
            });
            fileInput.value = '';
            return;
        }
        
        // Update display
        fileNameDisplay.textContent = file.name;
        fileDropArea.classList.add('hidden');
        filePreview.classList.remove('hidden');
        
        // Update preview
        previewFileName.textContent = file.name;
        previewFileSize.textContent = formatFileSize(file.size);
    }
    
    window.removeFile = function() {
        fileInput.value = '';
        fileNameDisplay.textContent = 'No file selected';
        fileDropArea.classList.remove('hidden');
        filePreview.classList.add('hidden');
    };
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
}
</script>

<?php include '../footer.php'; ?>
</body>
</html>