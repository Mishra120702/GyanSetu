<?php
include '../db_connection.php';
session_start();

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Check authentication
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'mentor'])) {
    header("Location: ../index.php");
    exit;
}

// Check for course_id
$course_id_param = $_GET['course_id'] ?? $_POST['course_id'] ?? null;
if (!$course_id_param) {
    header("Location: upload_content.php");
    exit;
}

// Fetch the course name
$stmt = $db->prepare("SELECT name FROM courses WHERE id = ?");
$stmt->execute([$course_id_param]);
$current_course_name = $stmt->fetchColumn();
if (!$current_course_name) {
    header("Location: upload_content.php");
    exit;
}

// ============================================
// HANDLE DELETE REQUEST - MUST COME FIRST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    header('Content-Type: application/json');
    
    $delete_id = intval($_POST['delete_id']);
    
    if ($delete_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid content ID']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Get content info
        $stmt = $db->prepare("SELECT * FROM uploads WHERE id = ?");
        $stmt->execute([$delete_id]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$content) {
            echo json_encode(['success' => false, 'message' => 'Content not found']);
            exit;
        }
        
        // Check if user can delete (uploaded by them or admin)
        if ($content['uploaded_by'] != $_SESSION['user_id'] && $_SESSION['user_role'] !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'You are not authorized to delete this content']);
            exit;
        }
        
        // Check and delete submissions if they exist
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignment_submissions WHERE upload_id = ?");
        $stmt->execute([$delete_id]);
        $submission_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($submission_count > 0) {
            // Get submission files to delete
            $stmt = $db->prepare("SELECT file_path FROM assignment_submissions WHERE upload_id = ?");
            $stmt->execute([$delete_id]);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($submissions as $submission) {
                if (!empty($submission['file_path'])) {
                    $file_path = '../' . $submission['file_path'];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            }
            
            // Delete submissions
            $stmt = $db->prepare("DELETE FROM assignment_submissions WHERE upload_id = ?");
            $stmt->execute([$delete_id]);
        }
        
        // Delete batch associations
        $stmt = $db->prepare("DELETE FROM batch_uploads WHERE upload_id = ?");
        $stmt->execute([$delete_id]);
        
        // Delete the file if it's not a drive link
        if ($content['content_source'] === 'file' && !empty($content['file_path'])) {
            $file_path = '../' . $content['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        // Delete the upload record
        $stmt = $db->prepare("DELETE FROM uploads WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Content deleted successfully']);
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================
// HANDLE UPLOAD/EDIT REQUEST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'upload';
    $content_id = $_POST['content_id'] ?? null;
    
    // Common data for both upload and edit
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $fileType = $_POST['file_type'] ?? '';
    $batchIds = $_POST['batch_ids'] ?? [];
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $due_time = !empty($_POST['due_time']) ? $_POST['due_time'] : null;
    $max_marks = !empty($_POST['max_marks']) ? floatval($_POST['max_marks']) : 100.00;
    $content_source = $_POST['content_source'] ?? 'file';
    $drive_link = trim($_POST['drive_link'] ?? '');
    $course_id = $_POST['course_id'] ?? $course_id_param;
    
    // Custom student targeting
    $assigned_to = $_POST['assigned_to'] ?? 'all';
    $student_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? $_POST['student_ids'] : [];
    if ($fileType !== 'Assignment') {
        $assigned_to = 'all';
        $student_ids = [];
    }
    
    // Validate inputs
    $errors = [];
    
    if (empty($title)) {
        $errors[] = 'Title is required';
    }
    
    if (empty($fileType)) {
        $errors[] = 'File type is required';
    }
    
    if (empty($course_id)) {
        $errors[] = 'Please select a course';
    }
    
    // Validate drive link if content source is drive
    if ($content_source === 'drive') {
        if (empty($drive_link)) {
            $errors[] = 'Google Drive link is required';
        } elseif (!filter_var($drive_link, FILTER_VALIDATE_URL) || 
                  !preg_match('/drive\.google\.com/i', $drive_link)) {
            $errors[] = 'Please enter a valid Google Drive link';
        }
    }
    
    // Validate due date and time if provided
    if ($due_date) {
        $current_datetime = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        $current_datetime->setTime(0, 0, 0);
        
        $due_datetime = new DateTime($due_date, new DateTimeZone('Asia/Kolkata'));
        if (!empty($due_time)) {
            $time_parts = explode(':', $due_time);
            $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
        }
        
        if ($due_datetime < $current_datetime) {
            $errors[] = 'Due date and time cannot be in the past';
        }
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode('. ', $errors)]);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        if ($action === 'edit' && $content_id) {
            // EDIT EXISTING CONTENT
            $stmt = $db->prepare("SELECT * FROM uploads WHERE id = ?");
            $stmt->execute([$content_id]);
            $content = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$content) {
                throw new Exception('Content not found');
            }
            
            // Check if user can edit (uploaded by them or admin)
            if ($content['uploaded_by'] != $_SESSION['user_id'] && $_SESSION['user_role'] !== 'admin') {
                throw new Exception('You are not authorized to edit this content');
            }
            
            $filePath = $content['file_path'];
            
            // If editing and source is drive, update the link
            if ($content_source === 'drive') {
                $filePath = $drive_link;
            } 
            // If editing and source is file, check if new file is uploaded
            else if ($content_source === 'file' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                // Delete old file if exists and it's not a drive link
                if (!empty($content['file_path']) && strpos($content['file_path'], 'drive.google.com') === false) {
                    $old_file_path = '../' . $content['file_path'];
                    if (file_exists($old_file_path)) {
                        unlink($old_file_path);
                    }
                }
                
                // Handle new file upload
                $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $fileMimeType = $_FILES['file']['type'];
                
                if (!in_array($fileMimeType, $allowedTypes)) {
                    throw new Exception('Only PDF and DOCX files are allowed');
                }
                
                if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
                    throw new Exception('File size exceeds 10MB limit');
                }
                
                $uploadDir = 'uploads/content/';
                $fullUploadDir = '../' . $uploadDir;
                if (!is_dir($fullUploadDir)) {
                    mkdir($fullUploadDir, 0755, true);
                }
                
                $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['file']['name']));
                $filePath = $uploadDir . $fileName;
                
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullUploadDir . $fileName)) {
                    throw new Exception('File upload failed');
                }
            }
            
            // Update upload record with due_time and assigned_to
            $stmt = $db->prepare("UPDATE uploads SET 
                                 title = ?, 
                                 description = ?, 
                                 file_path = ?, 
                                 file_type = ?, 
                                 due_date = ?, 
                                 due_time = ?,
                                 max_marks = ?, 
                                 content_source = ?,
                                 course_id = ?,
                                 assigned_to = ?,
                                 uploaded_at = CURRENT_TIMESTAMP
                                 WHERE id = ?");
            $stmt->execute([$title, $description, $filePath, $fileType, $due_date, $due_time, $max_marks, $content_source, $course_id, $assigned_to, $content_id]);
            
            // Remove existing batch associations
            $stmt = $db->prepare("DELETE FROM batch_uploads WHERE upload_id = ?");
            $stmt->execute([$content_id]);
            
            // Insert new batch associations based on visibility
            $stmt = $db->prepare("
                INSERT INTO batch_uploads (upload_id, batch_id, course_id) 
                SELECT ?, batch_id, ? FROM course_content_visibility WHERE course_id = ?
            ");
            $stmt->execute([$content_id, $course_id, $course_id]);

            // Save targeted students
            $deleteTargetStmt = $db->prepare("DELETE FROM upload_students WHERE upload_id = ?");
            $deleteTargetStmt->execute([$content_id]);
            
            if ($assigned_to === 'specific' && !empty($student_ids)) {
                $studentStmt = $db->prepare("INSERT INTO upload_students (upload_id, student_id) VALUES (?, ?)");
                foreach ($student_ids as $sid) {
                    $studentStmt->execute([$content_id, $sid]);
                }
            }
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Content updated successfully']);
            exit;
            
        } else {
            // NEW UPLOAD
            // For drive links, store the link directly
            if ($content_source === 'drive') {
                $filePath = $drive_link;
            } else {
                // Handle file upload
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('No file uploaded or upload error');
                }
                
                $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $fileMimeType = $_FILES['file']['type'];
                
                if (!in_array($fileMimeType, $allowedTypes)) {
                    throw new Exception('Only PDF and DOCX files are allowed');
                }
                
                if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
                    throw new Exception('File size exceeds 10MB limit');
                }
                
                $uploadDir = 'uploads/content/';
                $fullUploadDir = '../' . $uploadDir;
                if (!is_dir($fullUploadDir)) {
                    mkdir($fullUploadDir, 0755, true);
                }
                
                $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['file']['name']));
                $filePath = $uploadDir . $fileName;
                
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullUploadDir . $fileName)) {
                    throw new Exception('File upload failed');
                }
            }
            
            // Insert upload record with due_time and assigned_to
            $stmt = $db->prepare("INSERT INTO uploads (title, description, file_path, file_type, due_date, due_time, max_marks, uploaded_by, content_source, course_id, assigned_to) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $filePath, $fileType, $due_date, $due_time, $max_marks, $_SESSION['user_id'], $content_source, $course_id, $assigned_to]);
            $uploadId = $db->lastInsertId();
            
            // Insert batch associations based on visibility
            $stmt = $db->prepare("
                INSERT INTO batch_uploads (upload_id, batch_id, course_id) 
                SELECT ?, batch_id, ? FROM course_content_visibility WHERE course_id = ?
            ");
            $stmt->execute([$uploadId, $course_id, $course_id]);

            // Save targeted students
            if ($assigned_to === 'specific' && !empty($student_ids)) {
                $studentStmt = $db->prepare("INSERT INTO upload_students (upload_id, student_id) VALUES (?, ?)");
                foreach ($student_ids as $sid) {
                    $studentStmt->execute([$uploadId, $sid]);
                }
            }
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Content uploaded successfully']);
            exit;
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        // Delete the uploaded file if DB operation failed (only for new uploads)
        if ($action !== 'edit' && isset($filePath) && $content_source === 'file' && isset($fullUploadDir) && isset($fileName)) {
            $fullPath = $fullUploadDir . $fileName;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle GET request for editing - fetch content data
$edit_content_id = $_GET['edit'] ?? null;
$content_to_edit = null;
$content_batches = [];
$targetStudents = [];

if ($edit_content_id) {
    // Fetch content to edit
    $stmt = $db->prepare("SELECT * FROM uploads WHERE id = ?");
    $stmt->execute([$edit_content_id]);
    $content_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($content_to_edit) {
        // Check if user can edit (uploaded by them or admin)
        if ($content_to_edit['uploaded_by'] != $_SESSION['user_id'] && $_SESSION['user_role'] !== 'admin') {
            header("Location: upload_content.php");
            exit;
        }
        
        // Fetch associated batches and course
        $stmt = $db->prepare("SELECT batch_id, course_id FROM batch_uploads WHERE upload_id = ?");
        $stmt->execute([$edit_content_id]);
        $batch_uploads_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $content_batches = array_column($batch_uploads_data, 'batch_id');
        $content_course_id = !empty($batch_uploads_data) ? $batch_uploads_data[0]['course_id'] : null;

        // Fetch targeted students
        if ($content_to_edit['assigned_to'] === 'specific') {
            $studentTargetStmt = $db->prepare("SELECT student_id FROM upload_students WHERE upload_id = ?");
            $studentTargetStmt->execute([$edit_content_id]);
            $targetStudents = $studentTargetStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

// Get all courses for dropdown
$courses = $db->query("SELECT id, name FROM courses ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get all batches for dropdown
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch active students in batches visible for this course
$visibility_stmt = $db->prepare("SELECT batch_id FROM course_content_visibility WHERE course_id = ?");
$visibility_stmt->execute([$course_id_param]);
$visible_batch_ids = $visibility_stmt->fetchAll(PDO::FETCH_COLUMN);

$course_students = [];
if (!empty($visible_batch_ids)) {
    $batch_placeholders = implode(',', array_fill(0, count($visible_batch_ids), '?'));
    $students_query = "
        SELECT student_id, first_name, last_name, batch_name 
        FROM students 
        WHERE (batch_name IN ($batch_placeholders) 
           OR batch_name_2 IN ($batch_placeholders) 
           OR batch_name_3 IN ($batch_placeholders) 
           OR batch_name_4 IN ($batch_placeholders))
          AND current_status = 'active'
        ORDER BY first_name, last_name
    ";
    
    $student_params = array_merge($visible_batch_ids, $visible_batch_ids, $visible_batch_ids, $visible_batch_ids);
    $students_stmt = $db->prepare($students_query);
    $students_stmt->execute($student_params);
    $course_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get content statistics
$statsStmt = $db->prepare("
    SELECT file_type, COUNT(*) as count 
    FROM uploads 
    WHERE course_id = ?
    GROUP BY file_type
");
$statsStmt->execute([$course_id_param]);
$contentStats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize stats array
$stats = [
    'Assignment' => 0,
    'Notes' => 0,
    'Lab Manual' => 0,
    'Other' => 0,
    'Test' => 0
];

foreach ($contentStats as $stat) {
    if (isset($stats[$stat['file_type']])) {
        $stats[$stat['file_type']] = $stat['count'];
    }
}

// Get filter parameters
$searchTerm = $_GET['search'] ?? '';
$fileTypeFilter = $_GET['type'] ?? '';
$batchFilter = $_GET['batch'] ?? '';
$courseFilter = $course_id_param; // Force filter to this course
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sortColumn = $_GET['sort'] ?? 'uploaded_at';
$sortOrder = $_GET['order'] ?? 'DESC';

// Validate sort parameters
$allowedSortColumns = ['title', 'file_type', 'uploaded_by_name', 'uploaded_at', 'id', 'due_date', 'due_time'];
$allowedSortOrders = ['ASC', 'DESC'];
$sortColumn = in_array($sortColumn, $allowedSortColumns) ? $sortColumn : 'uploaded_at';
$sortOrder = in_array(strtoupper($sortOrder), $allowedSortOrders) ? strtoupper($sortOrder) : 'DESC';

// Build query with filters
$whereClauses = [];
$params = [];

if (!empty($searchTerm)) {
    $whereClauses[] = "(u.title LIKE ? OR u.description LIKE ? OR users.name LIKE ?)";
    $searchTermWildcard = "%$searchTerm%";
    $params[] = $searchTermWildcard;
    $params[] = $searchTermWildcard;
    $params[] = $searchTermWildcard;
}

if (!empty($fileTypeFilter) && $fileTypeFilter !== 'all') {
    $whereClauses[] = "u.file_type = ?";
    $params[] = $fileTypeFilter;
}

if (!empty($batchFilter) && $batchFilter !== 'all') {
    $whereClauses[] = "EXISTS (SELECT 1 FROM batch_uploads bu WHERE bu.upload_id = u.id AND bu.batch_id = ?)";
    $params[] = $batchFilter;
}

if (!empty($courseFilter) && $courseFilter !== 'all') {
    $whereClauses[] = "u.course_id = ?";
    $params[] = $courseFilter;
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
$whereClause = '';
if (!empty($whereClauses)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereClauses);
}

// Get total count for pagination with filters
$countQuery = "
    SELECT COUNT(DISTINCT u.id) as total 
    FROM uploads u
    JOIN users ON u.uploaded_by = users.id
    $whereClause
";

$totalStmt = $db->prepare($countQuery);
if (!empty($params)) {
    $totalStmt->execute($params);
} else {
    $totalStmt->execute();
}
$totalRecords = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get all uploaded content with pagination and filters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$totalPages = ceil($totalRecords / $limit);

// Get uploads with user info and filters
$uploadsQuery = "
    SELECT DISTINCT u.*, users.name as uploaded_by_name,
           (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id) as submission_count,
           (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id AND status = 'graded') as graded_count
    FROM uploads u
    JOIN users ON u.uploaded_by = users.id
    $whereClause
    ORDER BY ";

// Add sorting
switch ($sortColumn) {
    case 'title':
        $uploadsQuery .= "u.title $sortOrder";
        break;
    case 'file_type':
        $uploadsQuery .= "u.file_type $sortOrder";
        break;
    case 'due_date':
        $uploadsQuery .= "u.due_date $sortOrder, u.due_time $sortOrder";
        break;
    case 'due_time':
        $uploadsQuery .= "u.due_time $sortOrder";
        break;
    case 'uploaded_by_name':
        $uploadsQuery .= "users.name $sortOrder";
        break;
    case 'uploaded_at':
    default:
        $uploadsQuery .= "u.uploaded_at $sortOrder";
        break;
}

$uploadsQuery .= " LIMIT ? OFFSET ?";

$uploadsStmt = $db->prepare($uploadsQuery);

// Bind parameters
$paramIndex = 1;
foreach ($params as $param) {
    $uploadsStmt->bindValue($paramIndex++, $param);
}
$uploadsStmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
$uploadsStmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
$uploadsStmt->execute();
$uploads = $uploadsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get batch associations for each upload
foreach ($uploads as &$upload) {
    $stmt = $db->prepare("
        SELECT b.batch_id, b.batch_name, c.name as course_name 
        FROM batch_uploads bu
        JOIN batches b ON bu.batch_id = b.batch_id
        LEFT JOIN courses c ON bu.course_id = c.id
        WHERE bu.upload_id = ?
    ");
    $stmt->execute([$upload['id']]);
    $upload['batches'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($upload);

// Theme colors for file types
$fileTypeColors = [
    'Assignment' => ['bg' => '#4f46e5', 'light' => 'rgba(79, 70, 229, 0.1)', 'icon' => 'fa-tasks'],
    'Notes' => ['bg' => '#ec4899', 'light' => 'rgba(236, 72, 153, 0.1)', 'icon' => 'fa-book'],
    'Lab Manual' => ['bg' => '#06b6d4', 'light' => 'rgba(6, 182, 212, 0.1)', 'icon' => 'fa-flask'],
    'Other' => ['bg' => '#10b981', 'light' => 'rgba(16, 185, 129, 0.1)', 'icon' => 'fa-file'],
    'Test' => ['bg' => '#f59e0b', 'light' => 'rgba(245, 158, 11, 0.1)', 'icon' => 'fa-pencil-alt']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($current_course_name) ?> - Folder Content - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.0.0/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.0.0/dist/js/tom-select.complete.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --deepest-navy: #1B3C53;
            --dark-steel: #234C6A;
            --mid-steel: #456882;
            --warm-sand: #D2C1B6;
            --soft-sky: #A4C4D4;
            --white: #ffffff;
            --terracotta: #C97B50;
            --amber: #f59e0b;
            --success-green: #166534;
            --danger-red: #C0392B;
        }

        * {
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
        }

        body {
            background:
                radial-gradient(1100px 500px at 100% -8%, rgba(69,104,130,.22), transparent 55%),
                radial-gradient(900px 450px at -10% 108%, rgba(27,60,83,.16), transparent 55%),
                radial-gradient(rgba(27,60,83,.045) 1px, transparent 1px) 0 0 / 22px 22px,
                linear-gradient(165deg, #e8e2db 0%, #e4ddd5 44%, #d9e3ec 100%);
            background-attachment: fixed;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(27,60,83,.13);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .glass-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--mid-steel);
            border-radius: 4px 0 0 4px;
            z-index: 2;
        }

        .glass-card:hover {
            box-shadow: 0 8px 32px rgba(27,60,83,.18);
            transform: translateY(-2px);
        }

        .glass-card::after {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 18px;
            background: conic-gradient(
                var(--deepest-navy), var(--dark-steel), var(--mid-steel),
                var(--warm-sand), var(--soft-sky), var(--deepest-navy)
            );
            z-index: -1;
            opacity: 0;
            transition: opacity 0.5s ease;
            animation: conicSpin 6s linear infinite;
            animation-play-state: paused;
        }

        .glass-card:hover::after {
            opacity: 0.35;
            animation-play-state: running;
        }

        @keyframes conicSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .hero-banner {
            background: linear-gradient(135deg, var(--deepest-navy) 0%, var(--dark-steel) 45%, var(--mid-steel) 100%);
            color: var(--white);
            border-radius: 16px;
            padding: 1.5rem 2rem;
            box-shadow: 0 4px 24px rgba(27,60,83,.2);
        }

        .hero-banner h1 {
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .badge-brand {
            background: var(--deepest-navy);
            color: var(--white);
            border-radius: 9999px;
            padding: 0.5rem 1.25rem;
            font-weight: 600;
            font-size: 0.85rem;
            box-shadow: 0 3px 10px rgba(27,60,83,.2);
        }

        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 1.25rem;
            box-shadow: 0 4px 20px rgba(27,60,83,.13);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border-left: 4px solid var(--mid-steel);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(27,60,83,.18);
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-card .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--deepest-navy);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--mid-steel);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .btn-brand {
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.6rem 1.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
            letter-spacing: 0.01em;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
        }

        .btn-brand:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,.15);
            text-decoration: none;
        }

        .btn-primary-brand {
            background: linear-gradient(135deg, var(--amber), var(--terracotta));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(201,123,80,.35);
        }

        .btn-primary-brand:hover {
            box-shadow: 0 8px 24px rgba(201,123,80,.45);
            color: var(--white);
        }

        .btn-success-brand {
            background: linear-gradient(135deg, var(--mid-steel), var(--dark-steel));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(35,76,106,.35);
        }

        .btn-success-brand:hover {
            box-shadow: 0 8px 24px rgba(35,76,106,.45);
            color: var(--white);
        }

        .btn-danger-brand {
            background: linear-gradient(135deg, #ef4444, var(--danger-red));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(192,57,43,.35);
        }

        .btn-danger-brand:hover {
            box-shadow: 0 8px 24px rgba(192,57,43,.45);
            color: var(--white);
        }

        .btn-secondary-brand {
            background: linear-gradient(135deg, #EAE4E0, var(--warm-sand));
            color: var(--deepest-navy);
            box-shadow: 0 4px 14px rgba(210,193,182,.35);
        }

        .btn-secondary-brand:hover {
            box-shadow: 0 8px 24px rgba(210,193,182,.45);
            color: var(--deepest-navy);
        }

        .btn-brand-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .input-brand {
            border-radius: 12px;
            border: 2px solid var(--warm-sand);
            padding: 0.65rem 1rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background: var(--white);
            color: var(--deepest-navy);
            width: 100%;
        }

        .input-brand:focus {
            border-color: var(--dark-steel);
            box-shadow: 0 0 0 4px rgba(35,76,106,.12);
            outline: none;
        }

        .file-upload-area {
            border: 2px dashed var(--warm-sand);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            background: rgba(248,245,242,.3);
            cursor: pointer;
        }

        .file-upload-area:hover {
            border-color: var(--dark-steel);
            background: rgba(35,76,106,.05);
        }

        .file-upload-area.dragover {
            border-color: var(--dark-steel);
            background: rgba(35,76,106,.1);
            transform: scale(1.02);
        }

        .source-tab {
            padding: 0.6rem 1.5rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(210,193,182,.15);
            border: 2px solid transparent;
            color: var(--mid-steel);
        }

        .source-tab:hover {
            background: rgba(210,193,182,.25);
            transform: translateY(-2px);
        }

        .source-tab.active {
            background: linear-gradient(135deg, var(--deepest-navy), var(--dark-steel));
            color: var(--white);
            border-color: var(--deepest-navy);
            box-shadow: 0 4px 14px rgba(27,60,83,.3);
        }

        .source-tab i {
            margin-right: 0.5rem;
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
        }

        .table-brand {
            width: 100%;
            border-collapse: collapse;
        }

        .table-brand thead th {
            background: linear-gradient(90deg, var(--deepest-navy), var(--dark-steel), var(--mid-steel));
            color: var(--white);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.9rem 1.25rem;
            border: none;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table-brand tbody td {
            padding: 0.9rem 1.25rem;
            vertical-align: middle;
            border-bottom: 1px solid rgba(210,193,182,.25);
        }

        .table-brand tbody tr:nth-child(even) {
            background: #f4ede7;
        }

        .table-brand tbody tr:hover {
            background: #e8dfd8;
            transform: scale(1.01);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .table-brand tbody tr:last-child td {
            border-bottom: none;
        }

        .badge-type {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: white;
        }

        .pagination-wrapper {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.25rem;
            background: rgba(248,245,242,.3);
            border-top: 1px solid rgba(210,193,182,.25);
        }

        .pagination-brand {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .pagination-brand .page-link {
            border-radius: 9999px;
            border: 2px solid rgba(210,193,182,.3);
            padding: 0.5rem 0.9rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--dark-steel);
            background: var(--white);
            transition: all 0.25s ease;
            text-decoration: none;
            min-width: 40px;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .pagination-brand .page-link:hover {
            background: var(--dark-steel);
            color: var(--white);
            border-color: var(--dark-steel);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(35,76,106,.25);
        }

        .pagination-brand .page-link.active {
            background: linear-gradient(135deg, var(--deepest-navy), var(--dark-steel));
            color: var(--white);
            border-color: var(--deepest-navy);
            box-shadow: 0 4px 14px rgba(27,60,83,.3);
            cursor: default;
        }

        .pagination-brand .page-link.disabled {
            opacity: 0.4;
            pointer-events: none;
            cursor: default;
        }

        .pagination-brand .page-info {
            color: var(--mid-steel);
            font-weight: 500;
            font-size: 0.85rem;
            padding: 0 0.75rem;
        }

        .filter-select {
            border-radius: 9999px;
            border: 2px solid rgba(210,193,182,.3);
            padding: 0.6rem 1.5rem;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--dark-steel);
            background: var(--white);
            cursor: pointer;
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
            appearance: none;
            width: 100%;
        }

        .filter-select:hover {
            border-color: var(--dark-steel);
            box-shadow: 0 2px 8px rgba(35,76,106,.15);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--deepest-navy);
            box-shadow: 0 0 0 3px rgba(27,60,83,.1);
        }

        .search-input {
            border-radius: 9999px;
            border: 2px solid rgba(210,193,182,.3);
            padding: 0.6rem 1.5rem 0.6rem 3rem;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--dark-steel);
            background: var(--white);
            transition: all 0.25s ease;
            width: 100%;
            font-family: 'Inter', sans-serif;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--deepest-navy);
            box-shadow: 0 0 0 3px rgba(27,60,83,.1);
        }

        .search-wrapper {
            position: relative;
        }

        .search-wrapper .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--mid-steel);
        }

        .filter-tag {
            background: rgba(27,60,83,.08);
            border: 1px solid rgba(27,60,83,.15);
            border-radius: 9999px;
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--deepest-navy);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-tag a {
            color: var(--danger-red);
            transition: all 0.2s ease;
        }

        .filter-tag a:hover {
            transform: rotate(90deg);
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }

        .time-input {
            border-radius: 12px;
            border: 2px solid var(--warm-sand);
            padding: 0.65rem 1rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background: var(--white);
            color: var(--deepest-navy);
            width: 100%;
        }

        .time-input:focus {
            border-color: var(--dark-steel);
            box-shadow: 0 0 0 4px rgba(35,76,106,.12);
            outline: none;
        }

        .checkbox-label {
            transition: all 0.2s ease;
            border-radius: 12px;
            border: 2px solid rgba(210,193,182,.3);
            padding: 0.75rem 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: var(--white);
        }

        .checkbox-label:hover {
            border-color: var(--dark-steel);
            background: rgba(35,76,106,.04);
            transform: translateX(2px);
        }

        .checkbox-label:has(input:checked) {
            border-color: var(--deepest-navy);
            background: rgba(27,60,83,.06);
            box-shadow: 0 2px 8px rgba(27,60,83,.08);
        }

        .checkbox-label .batch-text {
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
        }

        .checkbox-label:has(input:checked) .batch-text {
            color: var(--deepest-navy);
            font-weight: 600;
        }

        .checkbox-custom {
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 4px;
            border: 2px solid var(--warm-sand);
            transition: all 0.2s ease;
            cursor: pointer;
            accent-color: var(--deepest-navy);
            flex-shrink: 0;
        }

        .checkbox-custom:checked {
            border-color: var(--deepest-navy);
        }

        .sort-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
        }

        .sort-link:hover {
            color: var(--white);
        }

        .sort-link.active {
            color: var(--white);
        }

        .sort-link i {
            font-size: 0.7rem;
            opacity: 0.5;
        }

        .sort-link.active i {
            opacity: 1;
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--warm-sand);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--mid-steel), var(--dark-steel));
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--dark-steel), var(--deepest-navy));
        }

        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-slide-in {
            animation: slideIn 0.5s ease-out forwards;
        }

        @media (max-width: 768px) {
            .hero-banner {
                padding: 1rem 1.25rem;
            }
            .stat-card .stat-number {
                font-size: 1.25rem;
            }
            .stat-card {
                padding: 0.75rem;
            }
            .pagination-wrapper {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
            }
            .pagination-brand {
                justify-content: center;
            }
            .pagination-brand .page-link {
                padding: 0.35rem 0.7rem;
                font-size: 0.75rem;
                min-width: 32px;
            }
            .table-brand thead th,
            .table-brand tbody td {
                padding: 0.7rem 0.75rem;
                font-size: 0.8rem;
            }
            .action-btn {
                font-size: 0.7rem;
                padding: 0.35rem 0.7rem;
            }
            .source-tab {
                padding: 0.4rem 1rem;
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300">
        <!-- Hero Banner -->
        <div class="hero-banner mx-4 mt-4 md:mx-6 md:mt-6">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <button class="d-md-none btn btn-link text-white p-2" onclick="toggleSidebar()">
                        <i class="fas fa-bars fa-lg"></i>
                    </button>
                    <a href="upload_content.php" class="text-white/70 hover:text-white transition-colors mr-2" title="Back to Folders">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <span class="rounded-3 p-2.5 d-inline-flex align-items-center justify-content-center" style="background: rgba(255,255,255,.15);">
                        <i class="fas fa-folder-open text-white" style="font-size: 1.5rem;"></i>
                    </span>
                    <div>
                        <h1 class="mb-0" style="font-size: 1.5rem; font-weight: 800;"><?= htmlspecialchars($current_course_name) ?></h1>
                        <p class="mb-0 opacity-75" style="font-size: 0.85rem;">Manage course content and assignments</p>
                    </div>
                </div>
                <span class="badge-brand">
                    <i class="fas fa-file me-1.5"></i> <?= $totalRecords ?> Files
                </span>
            </div>
        </div>

        <div class="p-4 md:p-6 max-w-7xl mx-auto">
            <?php if (!$content_to_edit): ?>
            <!-- Statistics Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 animate-slide-in">
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(79, 70, 229, 0.12); color: #4f46e5;">
                            <i class="fas fa-tasks text-lg"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?= $stats['Assignment'] ?></div>
                            <div class="stat-label">Assignments</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(236, 72, 153, 0.12); color: #ec4899;">
                            <i class="fas fa-book text-lg"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?= $stats['Notes'] ?></div>
                            <div class="stat-label">Notes</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(6, 182, 212, 0.12); color: #06b6d4;">
                            <i class="fas fa-flask text-lg"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?= $stats['Lab Manual'] ?></div>
                            <div class="stat-label">Lab Manuals</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">
                            <i class="fas fa-file text-lg"></i>
                        </div>
                        <div>
                            <div class="stat-number"><?= $stats['Other'] ?></div>
                            <div class="stat-label">Other</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Upload/Edit Form -->
            <div class="glass-card p-6 animate-slide-in mb-6" <?= $content_to_edit ? '' : 'style="animation-delay: 0.1s"' ?>>
                <?php if ($content_to_edit): ?>
                <div class="flex items-center gap-3 mb-6 pb-4 border-b" style="border-color: rgba(210,193,182,.25);">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-amber-500 to-terracotta flex items-center justify-center text-white text-xl">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">Edit Content</h2>
                        <p class="text-sm text-gray-500">Last updated: <?= date('M j, Y g:i A', strtotime($content_to_edit['uploaded_at'])) ?></p>
                    </div>
                </div>
                <?php else: ?>
                <h2 class="text-xl font-semibold text-gray-800 mb-6 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-r from-amber-500 to-terracotta flex items-center justify-center text-white">
                        <i class="fas fa-upload"></i>
                    </div>
                    <span>Upload New Content</span>
                </h2>
                <?php endif; ?>
                
                <form id="uploadForm" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="action" value="<?= $content_to_edit ? 'edit' : 'upload' ?>">
                    <?php if ($content_to_edit): ?>
                        <input type="hidden" name="content_id" value="<?= $content_to_edit['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="content_source" id="content_source" value="<?= $content_to_edit ? $content_to_edit['content_source'] : 'file' ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-heading text-indigo-500 mr-2"></i>
                                Title <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="title" required
                                   class="input-brand"
                                   placeholder="Enter content title"
                                   value="<?= $content_to_edit ? htmlspecialchars($content_to_edit['title']) : '' ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-tag text-indigo-500 mr-2"></i>
                                Content Type <span class="text-red-500">*</span>
                            </label>
                            <select name="file_type" required id="fileTypeSelect"
                                    class="filter-select">
                                <option value="">Select a type</option>
                                <option value="Assignment" <?= $content_to_edit && $content_to_edit['file_type'] === 'Assignment' ? 'selected' : '' ?>>Assignment</option>
                                <option value="Notes" <?= $content_to_edit && $content_to_edit['file_type'] === 'Notes' ? 'selected' : '' ?>>Notes</option>
                                <option value="Lab Manual" <?= $content_to_edit && $content_to_edit['file_type'] === 'Lab Manual' ? 'selected' : '' ?>>Lab Manual</option>
                                <option value="Other" <?= $content_to_edit && $content_to_edit['file_type'] === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        
                        <div id="dueDateField" class="<?= ($content_to_edit && $content_to_edit['file_type'] === 'Assignment') ? '' : 'hidden' ?>">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-alt text-indigo-500 mr-2"></i>
                                Due Date (IST)
                            </label>
                            <input type="date" name="due_date" id="dueDate"
                                   class="input-brand"
                                   min="<?= date('Y-m-d') ?>"
                                   value="<?= $content_to_edit && $content_to_edit['due_date'] ? date('Y-m-d', strtotime($content_to_edit['due_date'])) : '' ?>">
                        </div>
                        
                        <div id="dueTimeField" class="<?= ($content_to_edit && $content_to_edit['file_type'] === 'Assignment') ? '' : 'hidden' ?>">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-clock text-indigo-500 mr-2"></i>
                                Due Time (IST)
                            </label>
                            <input type="time" name="due_time" id="dueTime"
                                   class="time-input"
                                   value="<?= $content_to_edit && $content_to_edit['due_time'] ? date('H:i', strtotime($content_to_edit['due_time'])) : '' ?>">
                            <p class="text-xs text-gray-500 mt-1">Leave empty for midnight (11:59 PM IST)</p>
                        </div>
                        
                        <div id="maxMarksField" class="<?= ($content_to_edit && $content_to_edit['file_type'] === 'Assignment') ? '' : 'hidden' ?>">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-star text-indigo-500 mr-2"></i>
                                Maximum Marks
                            </label>
                            <input type="number" name="max_marks" step="0.01" min="0" max="1000" id="maxMarks"
                                   class="input-brand"
                                   value="<?= $content_to_edit ? htmlspecialchars($content_to_edit['max_marks']) : '100' ?>">
                            <p class="text-xs text-gray-500 mt-1">For assignments only</p>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-align-left text-indigo-500 mr-2"></i>
                                Description
                            </label>
                            <textarea name="description" rows="3"
                                      class="input-brand"
                                      placeholder="Enter a brief description (optional)"><?= $content_to_edit ? htmlspecialchars($content_to_edit['description']) : '' ?></textarea>
                        </div>
                        
                        <!-- Assignment Student Targeting -->
                        <div id="assignmentTargetingField" class="md:col-span-2 <?= ($content_to_edit && $content_to_edit['file_type'] === 'Assignment') ? '' : 'hidden' ?> border-t pt-4 mt-2" style="border-color: rgba(210,193,182,.25);">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-users-cog text-indigo-500 mr-2"></i>
                                Assign To <span class="text-red-500">*</span>
                            </label>
                            <select name="assigned_to" id="assignedToSelect"
                                    class="filter-select">
                                <option value="all" <?= (!$content_to_edit || $content_to_edit['assigned_to'] === 'all') ? 'selected' : '' ?>>All Students in Course Batches</option>
                                <option value="specific" <?= ($content_to_edit && $content_to_edit['assigned_to'] === 'specific') ? 'selected' : '' ?>>Specific Students</option>
                            </select>
                            
                            <!-- Student List Checklist -->
                            <div id="studentChecklistContainer" class="mt-4 p-4 rounded-2xl <?= ($content_to_edit && $content_to_edit['assigned_to'] === 'specific') ? '' : 'hidden' ?>" style="background: rgba(210,193,182,.1); border: 2px solid rgba(210,193,182,.2);">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 mb-4 pb-3" style="border-bottom: 1px solid rgba(210,193,182,.2);">
                                    <div class="text-sm font-semibold text-gray-700">Select Students</div>
                                    <div class="flex gap-2">
                                        <button type="button" onclick="selectAllStudents(true)" class="text-xs px-3 py-1.5 rounded-lg font-medium transition-colors" style="background: rgba(27,60,83,.08); color: var(--deepest-navy); hover:background: rgba(27,60,83,.15);">
                                            Select All
                                        </button>
                                        <button type="button" onclick="selectAllStudents(false)" class="text-xs px-3 py-1.5 rounded-lg font-medium transition-colors" style="background: rgba(210,193,182,.2); color: var(--mid-steel); hover:background: rgba(210,193,182,.3);">
                                            Deselect All
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Student Search -->
                                <div class="relative mb-3">
                                    <i class="fas fa-search absolute left-3.5 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                                    <input type="text" id="studentSearchInput" onkeyup="filterStudentList()" placeholder="Search students by name..." class="w-full pl-9 pr-4 py-2 text-sm input-brand">
                                </div>
                                
                                <?php if (empty($course_students)): ?>
                                    <div class="text-center py-6 text-sm text-gray-500">
                                        <i class="fas fa-info-circle text-indigo-500 mr-1"></i> No active students found in batches visible for this course.
                                    </div>
                                <?php else: ?>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 max-h-60 overflow-y-auto pr-1" id="studentListGrid">
                                        <?php foreach ($course_students as $std): 
                                            $is_checked = ($content_to_edit && in_array($std['student_id'], $targetStudents)) ? 'checked' : '';
                                        ?>
                                            <label class="student-label checkbox-label">
                                                <input type="checkbox" name="student_ids[]" value="<?= htmlspecialchars($std['student_id']) ?>" <?= $is_checked ?>
                                                       class="checkbox-custom student-checkbox">
                                                <div class="ml-1 truncate">
                                                    <span class="block text-xs font-semibold text-gray-800 truncate">
                                                        <?= htmlspecialchars($std['first_name'] . ' ' . $std['last_name']) ?>
                                                    </span>
                                                    <span class="block text-[10px] text-gray-500">
                                                        Batch: <?= htmlspecialchars($std['batch_name']) ?>
                                                    </span>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="md:col-span-2 hidden">
                            <input type="hidden" id="course_id" name="course_id" value="<?= htmlspecialchars($course_id_param) ?>">
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                <i class="fas fa-cloud text-indigo-500 mr-2"></i>
                                Content Source <span class="text-red-500">*</span>
                            </label>
                            
                            <div class="flex gap-2 mb-4">
                                <div class="source-tab <?= (!$content_to_edit || $content_to_edit['content_source'] === 'file') ? 'active' : '' ?>" onclick="switchSource('file')">
                                    <i class="fas fa-file-upload"></i> File Upload
                                </div>
                                <div class="source-tab <?= ($content_to_edit && $content_to_edit['content_source'] === 'drive') ? 'active' : '' ?>" onclick="switchSource('drive')">
                                    <i class="fab fa-google-drive"></i> Google Drive
                                </div>
                            </div>
                            
                            <!-- File Upload Content -->
                            <div id="fileSourceContent" class="source-content <?= (!$content_to_edit || $content_to_edit['content_source'] === 'file') ? 'block' : 'hidden' ?>">
                                <?php if ($content_to_edit && $content_to_edit['content_source'] === 'file'): ?>
                                <div class="mb-4 p-4 rounded-xl" style="background: rgba(27,60,83,.05); border: 1px solid rgba(27,60,83,.1);">
                                    <p class="text-sm font-medium text-gray-700 mb-2">Current File:</p>
                                    <div class="flex items-center gap-3">
                                        <i class="fas fa-file-pdf text-2xl" style="color: var(--danger-red);"></i>
                                        <span class="text-sm"><?= htmlspecialchars(basename($content_to_edit['file_path'])) ?></span>
                                        <div class="flex gap-2 ml-auto">
                                            <a href="../<?= htmlspecialchars($content_to_edit['file_path']) ?>" target="_blank"
                                               class="text-indigo-600 hover:text-indigo-800">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="../<?= htmlspecialchars($content_to_edit['file_path']) ?>" download
                                               class="text-green-600 hover:text-green-800">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2">Upload a new file to replace the current one (optional)</p>
                                </div>
                                <?php endif; ?>
                                
                                <div id="fileDropArea" class="file-upload-area">
                                    <input type="file" id="file" name="file" class="hidden"
                                           accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                                    <i class="fas fa-cloud-upload-alt text-5xl mb-3" style="color: var(--mid-steel);"></i>
                                    <p class="text-gray-600 mb-1">Drag & drop your file here or click to browse</p>
                                    <p class="text-xs text-gray-500">Supports: PDF, DOC, DOCX (Max 10MB)</p>
                                    <div id="fileNameDisplay" class="mt-3 text-sm font-medium hidden" style="color: var(--dark-steel);"></div>
                                </div>
                            </div>
                            
                            <!-- Google Drive Content -->
                            <div id="driveSourceContent" class="source-content <?= ($content_to_edit && $content_to_edit['content_source'] === 'drive') ? 'block' : 'hidden' ?>">
                                <div class="p-4 rounded-xl" style="background: rgba(27,60,83,.05); border: 1px solid rgba(27,60,83,.1);">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fab fa-google-drive text-blue-500 mr-2"></i>
                                        Google Drive Link <span class="text-red-500">*</span>
                                    </label>
                                    <input type="url" name="drive_link" id="driveLink"
                                           class="input-brand"
                                           placeholder="https://drive.google.com/file/d/.../view?usp=sharing"
                                           value="<?= $content_to_edit && $content_to_edit['content_source'] === 'drive' ? htmlspecialchars($content_to_edit['file_path']) : '' ?>">
                                    <?php if ($content_to_edit && $content_to_edit['content_source'] === 'drive'): ?>
                                    <div class="mt-3">
                                        <a href="<?= htmlspecialchars($content_to_edit['file_path']) ?>" target="_blank"
                                           class="text-blue-600 hover:text-blue-800 text-sm inline-flex items-center gap-1">
                                            <i class="fas fa-external-link-alt"></i> Current Drive Link
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <p class="text-xs text-gray-500 mt-2">
                                        <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                                        Make sure the link is set to "Anyone with the link can view"
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4" style="border-top: 1px solid rgba(210,193,182,.25);">
                        <?php if ($content_to_edit): ?>
                            <a href="course_folder.php?course_id=<?= htmlspecialchars($course_id_param) ?>" class="btn-brand btn-secondary-brand">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="btn-brand btn-primary-brand">
                            <i class="fas <?= $content_to_edit ? 'fa-save' : 'fa-upload' ?>"></i>
                            <?= $content_to_edit ? 'Update Content' : 'Upload Content' ?>
                        </button>
                    </div>
                </form>
            </div>

            <?php if (!$content_to_edit): ?>
            <!-- Search and Filters -->
            <div class="glass-card p-6 animate-slide-in mb-6" style="animation-delay: 0.2s">
                <form method="GET" action="" id="filterForm">
                    <input type="hidden" name="course_id" value="<?= htmlspecialchars($course_id_param) ?>">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <!-- Search -->
                        <div class="md:col-span-2 search-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" 
                                   placeholder="Search by title, description, or uploader..." 
                                   class="search-input">
                        </div>
                        
                        <!-- File Type Filter -->
                        <div class="relative">
                            <select name="type" class="filter-select">
                                <option value="all">All Types</option>
                                <option value="Assignment" <?= $fileTypeFilter === 'Assignment' ? 'selected' : '' ?>>Assignments</option>
                                <option value="Notes" <?= $fileTypeFilter === 'Notes' ? 'selected' : '' ?>>Notes</option>
                                <option value="Lab Manual" <?= $fileTypeFilter === 'Lab Manual' ? 'selected' : '' ?>>Lab Manuals</option>
                                <option value="Other" <?= $fileTypeFilter === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                            <i class="fas fa-chevron-down absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                        </div>
                        
                        <!-- Batch Filter -->
                        <div class="relative">
                            <select name="batch" class="filter-select">
                                <option value="all">All Batches</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $batchFilter === $batch['batch_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <!-- Date Range -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" 
                                   class="filter-select">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" 
                                   class="filter-select">
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="flex items-end gap-2">
                            <button type="submit" class="btn-brand btn-primary-brand flex-1">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="course_folder.php?course_id=<?= htmlspecialchars($course_id_param) ?>" class="btn-brand btn-secondary-brand flex-1 text-center">
                                <i class="fas fa-redo-alt"></i> Reset
                            </a>
                        </div>
                    </div>

                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
                </form>

                <!-- Active Filters -->
                <?php if (!empty($searchTerm) || (!empty($fileTypeFilter) && $fileTypeFilter !== 'all') || (!empty($batchFilter) && $batchFilter !== 'all') || !empty($dateFrom) || !empty($dateTo)): ?>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="text-sm text-gray-500 mr-2">Active Filters:</span>
                    <?php if (!empty($searchTerm)): ?>
                        <span class="filter-tag">
                            <i class="fas fa-search"></i>
                            "<?= htmlspecialchars($searchTerm) ?>"
                            <a href="<?= removeFilterParam('search') ?>" class="hover:rotate-90 transition-transform">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($fileTypeFilter) && $fileTypeFilter !== 'all'): ?>
                        <span class="filter-tag">
                            <i class="fas fa-tag"></i>
                            <?= htmlspecialchars($fileTypeFilter) ?>
                            <a href="<?= removeFilterParam('type') ?>" class="hover:rotate-90 transition-transform">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($batchFilter) && $batchFilter !== 'all'): ?>
                        <span class="filter-tag">
                            <i class="fas fa-users"></i>
                            Batch: <?= htmlspecialchars($batchFilter) ?>
                            <a href="<?= removeFilterParam('batch') ?>" class="hover:rotate-90 transition-transform">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($dateFrom)): ?>
                        <span class="filter-tag">
                            <i class="fas fa-calendar-alt"></i>
                            From: <?= htmlspecialchars($dateFrom) ?>
                            <a href="<?= removeFilterParam('date_from') ?>" class="hover:rotate-90 transition-transform">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($dateTo)): ?>
                        <span class="filter-tag">
                            <i class="fas fa-calendar-alt"></i>
                            To: <?= htmlspecialchars($dateTo) ?>
                            <a href="<?= removeFilterParam('date_to') ?>" class="hover:rotate-90 transition-transform">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Uploaded Content Table -->
            <div class="glass-card p-0 overflow-hidden animate-slide-in" style="animation-delay: 0.3s">
                <div class="p-4 border-b" style="border-color: rgba(210,193,182,.25); background: rgba(248,245,242,.3);">
                    <div class="flex flex-wrap justify-between items-center gap-3">
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-amber-500 to-terracotta flex items-center justify-center text-white">
                                <i class="fas fa-folder-open"></i>
                            </div>
                            <span>Uploaded Content</span>
                        </h2>
                        <div class="text-sm text-gray-500">
                            Showing <span class="font-bold" style="color: var(--dark-steel);"><?= ($page - 1) * $limit + 1 ?></span> to 
                            <span class="font-bold" style="color: var(--dark-steel);"><?= min($page * $limit, $totalRecords) ?></span> of 
                            <span class="font-bold" style="color: var(--dark-steel);"><?= $totalRecords ?></span> items
                        </div>
                    </div>
                </div>
                
                <?php if (empty($uploads)): ?>
                <div class="text-center py-12">
                    <div class="w-24 h-24 mx-auto mb-4 rounded-full flex items-center justify-center" style="background: rgba(210,193,182,.2);">
                        <i class="fas fa-box-open text-4xl" style="color: var(--warm-sand);"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">No content found</h3>
                    <p class="text-gray-500 mb-4">Try adjusting your filters or upload new content</p>
                    <a href="course_folder.php?course_id=<?= htmlspecialchars($course_id_param) ?>" class="btn-brand btn-primary-brand">
                        <i class="fas fa-redo-alt"></i> Clear Filters
                    </a>
                </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="table-brand">
                        <thead>
                            <tr>
                                <th>
                                    <a href="<?= getSortUrl('title') ?>" class="sort-link <?= $sortColumn === 'title' ? 'active' : '' ?>">
                                        Title
                                        <?php if ($sortColumn === 'title'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?= getSortUrl('file_type') ?>" class="sort-link <?= $sortColumn === 'file_type' ? 'active' : '' ?>">
                                        Type
                                        <?php if ($sortColumn === 'file_type'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Batches</th>
                                <th>
                                    <a href="<?= getSortUrl('due_date') ?>" class="sort-link <?= $sortColumn === 'due_date' ? 'active' : '' ?>">
                                        Due Date
                                        <?php if ($sortColumn === 'due_date'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?= getSortUrl('uploaded_by_name') ?>" class="sort-link <?= $sortColumn === 'uploaded_by_name' ? 'active' : '' ?>">
                                        Uploaded By
                                        <?php if ($sortColumn === 'uploaded_by_name'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?= getSortUrl('uploaded_at') ?>" class="sort-link <?= $sortColumn === 'uploaded_at' ? 'active' : '' ?>">
                                        Date
                                        <?php if ($sortColumn === 'uploaded_at'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($uploads as $index => $upload): ?>
                            <?php
                            $timeRemaining = '';
                            $dueClass = '';
                            $dueIcon = '';
                            
                            if ($upload['due_date']) {
                                $due_datetime = new DateTime($upload['due_date'], new DateTimeZone('Asia/Kolkata'));
                                
                                if (!empty($upload['due_time'])) {
                                    $time_parts = explode(':', $upload['due_time']);
                                    $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
                                } else {
                                    $due_datetime->setTime(23, 59, 59);
                                }
                                
                                $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                                
                                if ($due_datetime < $now) {
                                    $dueClass = 'text-red-600 bg-red-100';
                                    $dueIcon = 'fa-exclamation-circle';
                                    $timeRemaining = 'Overdue';
                                } else {
                                    $interval = $now->diff($due_datetime);
                                    $days = $interval->format('%r%a');
                                    
                                    if ($days == 0) {
                                        $hours = $interval->h;
                                        $minutes = $interval->i;
                                        if ($hours > 0) {
                                            $timeRemaining = $hours . 'h ' . $minutes . 'm left';
                                            $dueClass = 'text-yellow-600 bg-yellow-100';
                                            $dueIcon = 'fa-clock';
                                        } else {
                                            $timeRemaining = $minutes . 'm left';
                                            $dueClass = 'text-orange-600 bg-orange-100';
                                            $dueIcon = 'fa-hourglass-half';
                                        }
                                    } elseif ($days > 0 && $days <= 7) {
                                        $timeRemaining = $days . ' day' . ($days > 1 ? 's' : '') . ' left';
                                        $dueClass = 'text-green-600 bg-green-100';
                                        $dueIcon = 'fa-calendar-check';
                                    } else {
                                        $timeRemaining = $days . ' days left';
                                        $dueClass = 'text-blue-600 bg-blue-100';
                                        $dueIcon = 'fa-calendar-alt';
                                    }
                                }
                            }
                            
                            $fileColor = isset($fileTypeColors[$upload['file_type']]) ? $fileTypeColors[$upload['file_type']] : $fileTypeColors['Other'];
                            ?>
                            <tr class="table-row" style="animation: slideIn 0.3s ease-out <?= $index * 0.05 ?>s both;">
                                <td>
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 rounded-lg flex items-center justify-center mr-3 text-white" style="background: <?= $fileColor['bg'] ?>;">
                                            <i class="fas <?= $fileColor['icon'] ?>"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 flex flex-wrap items-center gap-2">
                                                <?= htmlspecialchars($upload['title']) ?>
                                                <?php if ($upload['content_source'] === 'drive'): ?>
                                                    <span class="px-2 py-1 text-xs rounded-full" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                                                        <i class="fab fa-google-drive mr-1"></i> Drive
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 py-1 text-xs rounded-full" style="background: rgba(27,60,83,.08); color: var(--dark-steel);">
                                                        <i class="fas fa-file mr-1"></i> File
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($upload['file_type'] === 'Assignment'): ?>
                                                    <span class="px-2 py-1 text-xs rounded-full <?= $upload['assigned_to'] === 'specific' ? 'bg-yellow-100 text-yellow-700 border border-yellow-200' : 'bg-green-100 text-green-700 border border-green-200' ?>">
                                                        <i class="fas <?= $upload['assigned_to'] === 'specific' ? 'fa-user-tag' : 'fa-users' ?> mr-1"></i>
                                                        <?= $upload['assigned_to'] === 'specific' ? 'Specific' : 'All' ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($upload['description']): ?>
                                                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($upload['description']) ?></p>
                                            <?php endif; ?>
                                            <?php if ($upload['file_type'] === 'Assignment' && $upload['submission_count'] > 0): ?>
                                                <div class="mt-1 flex items-center gap-2 text-xs">
                                                    <span class="text-gray-500">
                                                        <i class="fas fa-users text-indigo-400 mr-1"></i>
                                                        <?= $upload['submission_count'] ?> submissions
                                                    </span>
                                                    <?php if ($upload['graded_count'] > 0): ?>
                                                        <span class="text-green-600">
                                                            <i class="fas fa-check-circle mr-1"></i>
                                                            <?= $upload['graded_count'] ?> graded
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-type" style="background: <?= $fileColor['bg'] ?>;">
                                        <i class="fas <?= $fileColor['icon'] ?> mr-2"></i>
                                        <?= htmlspecialchars($upload['file_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1 max-w-xs">
                                        <?php foreach ($upload['batches'] as $batch): ?>
                                            <span class="px-2 py-1 text-xs rounded-full" style="background: rgba(210,193,182,.2); color: var(--dark-steel); border: 1px solid rgba(210,193,182,.3);" title="<?= htmlspecialchars($batch['batch_name'] . (!empty($batch['course_name']) ? ' - ' . $batch['course_name'] : '')) ?>">
                                                <?= htmlspecialchars($batch['batch_name']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($upload['due_date']): ?>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-medium text-gray-900">
                                                <?= date('M j, Y', strtotime($upload['due_date'])) ?>
                                                <?php if (!empty($upload['due_time'])): ?>
                                                    <span class="text-xs text-gray-500 ml-1">
                                                        <?= date('h:i A', strtotime($upload['due_time'])) ?> IST
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-500 ml-1">
                                                        11:59 PM IST
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($timeRemaining): ?>
                                                <span class="mt-1 px-2 py-1 rounded-full text-xs font-medium inline-flex items-center gap-1 <?= $dueClass ?>">
                                                    <i class="fas <?= $dueIcon ?>"></i>
                                                    <?= $timeRemaining ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-sm">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center mr-2" style="background: rgba(27,60,83,.08);">
                                            <i class="fas fa-user text-sm" style="color: var(--dark-steel);"></i>
                                        </div>
                                        <span class="text-sm text-gray-700"><?= htmlspecialchars($upload['uploaded_by_name']) ?></span>
                                    </div>
                                </td>
                                <td class="text-sm text-gray-500">
                                    <i class="far fa-calendar-alt mr-2" style="color: var(--mid-steel);"></i>
                                    <?= date('M j, Y', strtotime($upload['uploaded_at'])) ?>
                                </td>
                                <td style="text-align: right;">
                                    <div class="flex gap-2 justify-end">
                                        <?php if ($upload['content_source'] === 'drive'): ?>
                                            <a href="<?= htmlspecialchars($upload['file_path']) ?>" target="_blank"
                                               class="w-10 h-10 rounded-lg flex items-center justify-center transition-all" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; hover:background: rgba(59, 130, 246, 0.2);"
                                               title="Open Drive Link">
                                                <i class="fab fa-google-drive"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="../<?= htmlspecialchars($upload['file_path']) ?>" target="_blank"
                                               class="w-10 h-10 rounded-lg flex items-center justify-center transition-all" style="background: rgba(27,60,83,.08); color: var(--dark-steel); hover:background: rgba(27,60,83,.15);"
                                               title="View File">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="../<?= htmlspecialchars($upload['file_path']) ?>" download
                                               class="w-10 h-10 rounded-lg flex items-center justify-center transition-all" style="background: rgba(16, 185, 129, 0.1); color: #10b981; hover:background: rgba(16, 185, 129, 0.2);"
                                               title="Download">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($upload['file_type'] === 'Assignment'): ?>
                                            <a href="view_submissions.php?id=<?= $upload['id'] ?>" 
                                               class="w-10 h-10 rounded-lg flex items-center justify-center transition-all" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; hover:background: rgba(139, 92, 246, 0.2);"
                                               title="View Submissions">
                                                <i class="fas fa-users"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="upload_content.php?edit=<?= $upload['id'] ?>" 
                                           class="w-10 h-10 rounded-lg flex items-center justify-center transition-all" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; hover:background: rgba(245, 158, 11, 0.2);"
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <button onclick="deleteContent(<?= $upload['id'] ?>)"
                                                class="w-10 h-10 rounded-lg flex items-center justify-center transition-all" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; hover:background: rgba(239, 68, 68, 0.2);"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-brand">
                        <?php if ($page > 1): ?>
                            <a href="<?= getPageUrl($page - 1) ?>" class="page-link" aria-label="Previous">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1) {
                            echo '<a href="' . getPageUrl(1) . '" class="page-link">1</a>';
                            if ($startPage > 2) {
                                echo '<span class="page-link disabled">…</span>';
                            }
                        }
                        
                        for ($p = $startPage; $p <= $endPage; $p++) {
                            $active = $p === $page ? 'active' : '';
                            echo '<a href="' . getPageUrl($p) . '" class="page-link ' . $active . '">' . $p . '</a>';
                        }
                        
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) {
                                echo '<span class="page-link disabled">…</span>';
                            }
                            echo '<a href="' . getPageUrl($totalPages) . '" class="page-link">' . $totalPages . '</a>';
                        }
                        ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= getPageUrl($page + 1) ?>" class="page-link" aria-label="Next">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>

                        <span class="page-info">
                            Page <?= $page ?> of <?= $totalPages ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Helper functions
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.classList.toggle('-translate-x-full');
        }
        document.body.classList.toggle('overflow-hidden');
    }

    function switchSource(source) {
        const sourceInput = document.getElementById('content_source');
        if (sourceInput) sourceInput.value = source;

        document.querySelectorAll('.source-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        const tabs = document.querySelectorAll('.source-tab');
        if (source === 'file') {
            tabs[0].classList.add('active');
        } else {
            tabs[1].classList.add('active');
        }

        const fileSource = document.getElementById('fileSourceContent');
        const driveSource = document.getElementById('driveSourceContent');
        
        if (fileSource) fileSource.classList.toggle('hidden', source !== 'file');
        if (driveSource) driveSource.classList.toggle('hidden', source !== 'drive');
        
        const fileInput = document.getElementById('file');
        const driveInput = document.getElementById('driveLink');
        
        if (fileInput) fileInput.required = source === 'file';
        if (driveInput) driveInput.required = source === 'drive';
    }

    function deleteContent(id) {
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#C0392B',
            cancelButtonColor: '#1B3C53',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            background: 'white',
            iconColor: '#f59e0b'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Deleting...',
                    text: 'Please wait while we delete the content',
                    icon: 'info',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('delete_id', id);
                formData.append('course_id', document.getElementById('course_id').value);

                fetch('course_folder.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: data.message,
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#10b981',
                            timer: 1500,
                            showConfirmButton: true
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: data.message,
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#ef4444'
                        });
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'An error occurred while deleting content.',
                        background: 'white',
                        confirmButtonColor: '#1B3C53',
                        iconColor: '#ef4444'
                    });
                    console.error('Error:', error);
                });
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        // File upload drag and drop
        const fileDropArea = document.getElementById('fileDropArea');
        const fileInput = document.getElementById('file');
        const fileNameDisplay = document.getElementById('fileNameDisplay');

        if (fileDropArea) {
            fileDropArea.addEventListener('click', () => fileInput.click());

            fileInput.addEventListener('change', () => {
                if (fileInput.files.length) {
                    fileNameDisplay.textContent = 'Selected: ' + fileInput.files[0].name;
                    fileNameDisplay.classList.remove('hidden');
                    fileDropArea.style.borderColor = '#1B3C53';
                    fileDropArea.style.background = 'rgba(27,60,83,.05)';
                }
            });

            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                fileDropArea.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                fileDropArea.addEventListener(eventName, highlight, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                fileDropArea.addEventListener(eventName, unhighlight, false);
            });

            function highlight() {
                fileDropArea.classList.add('dragover');
            }

            function unhighlight() {
                fileDropArea.classList.remove('dragover');
            }

            fileDropArea.addEventListener('drop', handleDrop, false);

            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                fileInput.files = files;

                if (files.length) {
                    fileNameDisplay.textContent = 'Selected: ' + files[0].name;
                    fileNameDisplay.classList.remove('hidden');
                    fileDropArea.style.borderColor = '#1B3C53';
                    fileDropArea.style.background = 'rgba(27,60,83,.05)';
                }
            }
        }

        // Toggle assignment fields based on file type
        const fileTypeSelect = document.getElementById('fileTypeSelect');
        const dueDateField = document.getElementById('dueDateField');
        const dueTimeField = document.getElementById('dueTimeField');
        const maxMarksField = document.getElementById('maxMarksField');
        const dueDateInput = document.getElementById('dueDate');
        const dueTimeInput = document.getElementById('dueTime');
        const maxMarksInput = document.getElementById('maxMarks');

        function toggleAssignmentFields() {
            if (fileTypeSelect) {
                const isAssignment = fileTypeSelect.value === 'Assignment';
                
                if (dueDateField) {
                    dueDateField.classList.toggle('hidden', !isAssignment);
                }
                
                if (dueTimeField) {
                    dueTimeField.classList.toggle('hidden', !isAssignment);
                }
                
                if (maxMarksField) {
                    maxMarksField.classList.toggle('hidden', !isAssignment);
                }

                const targetField = document.getElementById('assignmentTargetingField');
                if (targetField) {
                    targetField.classList.toggle('hidden', !isAssignment);
                }

                if (!isAssignment) {
                    if (dueDateInput) dueDateInput.value = '';
                    if (dueTimeInput) dueTimeInput.value = '';
                    if (maxMarksInput) maxMarksInput.value = '100';
                    const assignedToSelect = document.getElementById('assignedToSelect');
                    if (assignedToSelect) {
                        assignedToSelect.value = 'all';
                        toggleStudentChecklist();
                    }
                }
            }
        }

        if (fileTypeSelect) {
            fileTypeSelect.addEventListener('change', toggleAssignmentFields);
            toggleAssignmentFields();
        }

        function toggleStudentChecklist() {
            const assignedToSelect = document.getElementById('assignedToSelect');
            const container = document.getElementById('studentChecklistContainer');
            if (assignedToSelect && container) {
                const isSpecific = assignedToSelect.value === 'specific';
                container.classList.toggle('hidden', !isSpecific);
            }
        }

        const assignedToSelect = document.getElementById('assignedToSelect');
        if (assignedToSelect) {
            assignedToSelect.addEventListener('change', toggleStudentChecklist);
        }

        window.selectAllStudents = function(checked) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => {
                const label = cb.closest('.student-label');
                if (label && label.style.display !== 'none') {
                    cb.checked = checked;
                }
            });
        };

        window.filterStudentList = function() {
            const query = document.getElementById('studentSearchInput').value.toLowerCase();
            const labels = document.querySelectorAll('.student-label');
            labels.forEach(label => {
                const text = label.textContent.toLowerCase();
                if (text.includes(query)) {
                    label.style.display = 'flex';
                } else {
                    label.style.display = 'none';
                }
            });
        };

        // Set default time to 23:59 (11:59 PM) if empty
        if (dueTimeInput && dueTimeInput.value === '') {
            dueTimeInput.value = '23:59';
        }

        // Handle form submission
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();

                // Client-side validation
                const title = this.querySelector('input[name="title"]').value.trim();
                const fileType = this.querySelector('select[name="file_type"]').value;
                const courseId = document.getElementById('course_id').value;
                const contentSource = document.getElementById('content_source').value;
                const driveLink = document.getElementById('driveLink')?.value || '';
                const action = this.querySelector('input[name="action"]').value;
                const isEdit = action === 'edit';

                if (!title || !fileType || !courseId) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: 'Please fill all required fields',
                        background: 'white',
                        confirmButtonColor: '#1B3C53',
                        iconColor: '#ef4444'
                    });
                    return;
                }

                // Validate due date and time for assignments
                if (fileType === 'Assignment') {
                    const dueDate = dueDateInput.value;
                    const dueTime = dueTimeInput.value;
                    
                    if (!dueDate) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Oops...',
                            text: 'Due date is required for assignments',
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#ef4444'
                        });
                        return;
                    }

                    const now = new Date();
                    const istNow = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
                    
                    let dueDateTime;
                    if (dueTime) {
                        dueDateTime = new Date(dueDate + 'T' + dueTime + ':00');
                    } else {
                        dueDateTime = new Date(dueDate + 'T23:59:59');
                    }
                    
                    if (dueDateTime < istNow) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid due date',
                            text: 'Due date and time cannot be in the past (IST)',
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#ef4444'
                        });
                        return;
                    }
                }

                if (contentSource === 'file') {
                    if (!isEdit && (!fileInput || !fileInput.files.length)) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Oops...',
                            text: 'Please select a file to upload',
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#ef4444'
                        });
                        return;
                    }

                    if (fileInput && fileInput.files.length && fileInput.files[0].size > 10 * 1024 * 1024) {
                        Swal.fire({
                            icon: 'error',
                            title: 'File too large',
                            text: 'File size exceeds 10MB limit',
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#ef4444'
                        });
                        return;
                    }
                } else if (contentSource === 'drive') {
                    if (!driveLink.trim()) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Oops...',
                            text: 'Please enter Google Drive link',
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#ef4444'
                        });
                        return;
                    }

                    if (!driveLink.includes('drive.google.com')) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid link',
                            text: 'Please enter a valid Google Drive link',
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#ef4444'
                        });
                        return;
                    }
                }

                // Show loading animation
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalBtnContent = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> ' + (isEdit ? 'Updating...' : 'Uploading...');
                submitBtn.disabled = true;

                // Submit form with AJAX
                const formData = new FormData(this);

                fetch('course_folder.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: data.message,
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#10b981',
                            showClass: {
                                popup: 'animate__animated animate__fadeInDown'
                            },
                            hideClass: {
                                popup: 'animate__animated animate__fadeOutUp'
                            }
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message,
                            background: 'white',
                            confirmButtonColor: '#1B3C53',
                            iconColor: '#ef4444'
                        });
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while processing your request',
                        background: 'white',
                        confirmButtonColor: '#1B3C53',
                        iconColor: '#ef4444'
                    });
                    console.error('Error:', error);
                })
                .finally(() => {
                    submitBtn.innerHTML = originalBtnContent;
                    submitBtn.disabled = false;
                });
            });
        }
    });
    </script>

    <?php 
    // Helper function to build query strings for pagination
    function buildQueryString($newParams = []) {
        $params = $_GET;
        
        foreach ($newParams as $key => $value) {
            $params[$key] = $value;
        }
        
        if (isset($params['page']) && $params['page'] == 1) {
            unset($params['page']);
        }
        
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                unset($params[$key]);
            }
        }
        
        return http_build_query($params);
    }

    function getSortUrl($column) {
        $params = $_GET;
        $params['sort'] = $column;
        $params['order'] = (isset($_GET['sort']) && $_GET['sort'] === $column && isset($_GET['order']) && $_GET['order'] === 'ASC') ? 'DESC' : 'ASC';
        $params['page'] = 1;
        
        return '?' . http_build_query($params);
    }

    function getPageUrl($page) {
        $params = $_GET;
        $params['page'] = $page;
        return '?' . http_build_query($params);
    }

    function removeFilterParam($param) {
        $params = $_GET;
        unset($params[$param]);
        unset($params['page']);
        
        return '?' . http_build_query($params);
    }
    ?>

    <?php include '../footer.php'; ?>
</body>
</html>