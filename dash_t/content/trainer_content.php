<?php
// trainer_content.php
session_start();
require_once '../db_connection.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../../logout_t.php");
    exit;
}

// Get trainer details
$trainer_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT t.* FROM trainers t WHERE t.user_id = ?");
$stmt->execute([$trainer_id]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    die("Trainer information not found");
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_content'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $fileType = $_POST['file_type'] ?? '';
    $course_id = $_POST['course_id'] ?? '';
    $content_source = $_POST['content_source'] ?? 'file';
    $drive_link = trim($_POST['drive_link'] ?? '');
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $max_marks = !empty($_POST['max_marks']) ? floatval($_POST['max_marks']) : 100.00;
    $selected_batches = isset($_POST['batch_ids']) ? $_POST['batch_ids'] : [];
    
    // Validate inputs
    if (empty($title)) {
        $_SESSION['error'] = 'Title is required';
        header("Location: trainer_content.php");
        exit;
    }
    
    if (empty($fileType)) {
        $_SESSION['error'] = 'File type is required';
        header("Location: trainer_content.php");
        exit;
    }
    
    if (empty($course_id)) {
        $_SESSION['error'] = 'Please select a course';
        header("Location: trainer_content.php");
        exit;
    }
    
    // NEW: Validate batch selection
    if (empty($selected_batches)) {
        $_SESSION['error'] = 'Please select at least one batch';
        header("Location: trainer_content.php");
        exit;
    }
    
    // Validate drive link if content source is drive
    if ($content_source === 'drive') {
        if (empty($drive_link)) {
            $_SESSION['error'] = 'Google Drive link is required';
            header("Location: trainer_content.php");
            exit;
        } elseif (!filter_var($drive_link, FILTER_VALIDATE_URL) || 
                  !preg_match('/drive\.google\.com/i', $drive_link)) {
            $_SESSION['error'] = 'Please enter a valid Google Drive link';
            header("Location: trainer_content.php");
            exit;
        }
    }
    
    // Validate due date is required for assignments
    if ($fileType === 'Assignment') {
        if (empty($due_date)) {
            $_SESSION['error'] = 'Due date is required for assignments';
            header("Location: trainer_content.php");
            exit;
        }
        
        // Validate due date is not in the past
        $current_date = new DateTime();
        $due_date_obj = new DateTime($due_date);
        $current_date->setTime(0, 0, 0);
        $due_date_obj->setTime(0, 0, 0);
        
        if ($due_date_obj < $current_date) {
            $_SESSION['error'] = 'Due date cannot be in the past';
            header("Location: trainer_content.php");
            exit;
        }
        
        // Validate max marks for assignments
        if ($max_marks <= 0 || $max_marks > 1000) {
            $_SESSION['error'] = 'Maximum marks must be between 1 and 1000';
            header("Location: trainer_content.php");
            exit;
        }
    }
    
    // For drive links, store the link directly
    if ($content_source === 'drive') {
        $filePath = $drive_link;
        try {
            $db->beginTransaction();
            
            // Insert upload record
            $stmt = $db->prepare("INSERT INTO uploads (title, description, file_path, file_type, due_date, max_marks, uploaded_by, content_source, course_id) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $filePath, $fileType, $due_date, $max_marks, $trainer_id, $content_source, $course_id]);
            $uploadId = $db->lastInsertId();
            
            // Insert batch associations for selected batches
            $stmt = $db->prepare("
                INSERT INTO batch_uploads (upload_id, batch_id, course_id) 
                VALUES (?, ?, ?)
            ");
            foreach ($selected_batches as $batch_id) {
                $stmt->execute([$uploadId, $batch_id, $course_id]);
            }
            
            $db->commit();
            $_SESSION['success'] = 'Content uploaded successfully to ' . count($selected_batches) . ' batch(es)';
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
    } 
    // Handle file upload
    else if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
        ];
        
        $fileMimeType = $_FILES['file']['type'];
        $fileSize = $_FILES['file']['size'];
        
        if (!array_key_exists($fileMimeType, $allowedTypes)) {
            $_SESSION['error'] = 'Only PDF, DOC, and DOCX files are allowed';
            header("Location: trainer_content.php");
            exit;
        }
        
        // Check file size (max 10MB)
        if ($fileSize > 10 * 1024 * 1024) {
            $_SESSION['error'] = 'File size exceeds 10MB limit';
            header("Location: trainer_content.php");
            exit;
        }
        
        $uploadDir = '../../uploads/content/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Sanitize filename
        $originalName = basename($_FILES['file']['name']);
        $fileExtension = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $fileName = uniqid() . '_' . $safeName;
        $filePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
            try {
                $db->beginTransaction();
                
                // Insert upload record
                $stmt = $db->prepare("INSERT INTO uploads (title, description, file_path, file_type, due_date, max_marks, uploaded_by, content_source, course_id) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $description, $filePath, $fileType, $due_date, $max_marks, $trainer_id, 'file', $course_id]);
                $uploadId = $db->lastInsertId();
                
                // Insert batch associations for selected batches
                $stmt = $db->prepare("
                    INSERT INTO batch_uploads (upload_id, batch_id, course_id) 
                    VALUES (?, ?, ?)
                ");
                foreach ($selected_batches as $batch_id) {
                    $stmt->execute([$uploadId, $batch_id, $course_id]);
                }
                
                $db->commit();
                $_SESSION['success'] = 'Content uploaded successfully to ' . count($selected_batches) . ' batch(es)';
            } catch (Exception $e) {
                $db->rollBack();
                // Delete the uploaded file if DB operation failed
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'File upload failed';
        }
    } else {
        $uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $_SESSION['error'] = $errorMessages[$uploadError] ?? 'File upload error occurred';
    }
    
    header("Location: trainer_content.php");
    exit;
}

// Get courses taught by this trainer with their associated batches
$courses = $db->prepare("
    SELECT DISTINCT 
        c.id as course_id, 
        c.name as course_name,
        b.batch_id,
        b.batch_name,
        bc.trainer_id
    FROM courses c
    JOIN batch_courses bc ON c.id = bc.course_id
    JOIN batches b ON bc.batch_id = b.batch_id
    WHERE bc.trainer_id = :trainer_id AND b.status != 'completed'
    ORDER BY c.name ASC, b.batch_id ASC
");
$courses->execute([':trainer_id' => $trainer['id']]);
$mentor_course_batches = $courses->fetchAll(PDO::FETCH_ASSOC);

// Group batches by course
$course_batches = [];
foreach ($mentor_course_batches as $row) {
    if (!isset($course_batches[$row['course_id']])) {
        $course_batches[$row['course_id']] = [
            'course_name' => $row['course_name'],
            'batches' => []
        ];
    }
    $course_batches[$row['course_id']]['batches'][] = [
        'batch_id' => $row['batch_id'],
        'batch_name' => $row['batch_name']
    ];
}

// Get courses list for dropdown
$mentor_courses = [];
foreach ($course_batches as $id => $data) {
    $mentor_courses[] = [
        'course_id' => $id,
        'course_name' => $data['course_name']
    ];
}

// Get all batches taught by this trainer
$batches = $db->prepare("
    SELECT batch_id, batch_name 
    FROM batches 
    WHERE batch_mentor_id = :trainer_id AND status != 'completed'
    ORDER BY batch_id ASC
");
$batches->execute([':trainer_id' => $trainer['id']]);
$mentor_batches = $batches->fetchAll(PDO::FETCH_ASSOC);

// Get content statistics
if (!empty($mentor_batches)) {
    $batch_ids = array_column($mentor_batches, 'batch_id');
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    
    $statsStmt = $db->prepare("
        SELECT u.file_type, COUNT(DISTINCT u.id) as count 
        FROM uploads u
        JOIN batch_uploads bu ON u.id = bu.upload_id
        WHERE bu.batch_id IN ($placeholders)
        AND (u.uploaded_by = ? OR EXISTS (
            SELECT 1 FROM batches b 
            WHERE b.batch_id = bu.batch_id 
            AND b.batch_mentor_id = ?
        ))
        GROUP BY u.file_type
    ");
    $statsParams = array_merge($batch_ids, [$trainer_id, $trainer['id']]);
    $statsStmt->execute($statsParams);
    $contentStats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [
        'Test' => 0,
        'Assignment' => 0,
        'Notes' => 0,
        'Other' => 0
    ];
    
    foreach ($contentStats as $stat) {
        $stats[$stat['file_type']] = $stat['count'];
    }
} else {
    $stats = [
        'Test' => 0,
        'Assignment' => 0,
        'Notes' => 0,
        'Other' => 0
    ];
}

// Get filter parameters
$searchTerm = $_GET['search'] ?? '';
$fileTypeFilter = $_GET['type'] ?? '';
$batchFilter = $_GET['batch'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sortColumn = $_GET['sort'] ?? 'uploaded_at';
$sortOrder = $_GET['order'] ?? 'DESC';

$allowedSortColumns = ['title', 'file_type', 'uploaded_at', 'due_date'];
$allowedSortOrders = ['ASC', 'DESC'];
$sortColumn = in_array($sortColumn, $allowedSortColumns) ? $sortColumn : 'uploaded_at';
$sortOrder = in_array($sortOrder, $allowedSortOrders) ? $sortOrder : 'DESC';

// Build query with filters
$whereClauses = ["(u.uploaded_by = ? OR EXISTS (SELECT 1 FROM batches b WHERE b.batch_id = bu.batch_id AND b.batch_mentor_id = ?))"];
$params = [$trainer_id, $trainer['id']];

if (!empty($searchTerm)) {
    $whereClauses[] = "(u.title LIKE ? OR u.description LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

if (!empty($fileTypeFilter) && $fileTypeFilter !== 'all') {
    $whereClauses[] = "u.file_type = ?";
    $params[] = $fileTypeFilter;
}

if (!empty($batchFilter) && $batchFilter !== 'all' && !empty($mentor_batches)) {
    $hasAccess = false;
    foreach ($mentor_batches as $batch) {
        if ($batch['batch_id'] == $batchFilter) {
            $hasAccess = true;
            break;
        }
    }
    
    if ($hasAccess) {
        $whereClauses[] = "b.batch_id = ?";
        $params[] = $batchFilter;
    }
}

if (!empty($dateFrom)) {
    $whereClauses[] = "DATE(u.uploaded_at) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereClauses[] = "DATE(u.uploaded_at) <= ?";
    $params[] = $dateTo;
}

$whereClause = '';
if (!empty($whereClauses)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereClauses);
}

// Get total count
$countQuery = "
    SELECT COUNT(DISTINCT u.id) as total 
    FROM uploads u
    JOIN batch_uploads bu ON u.id = bu.upload_id
    JOIN batches b ON bu.batch_id = b.batch_id
    $whereClause
";

$totalStmt = $db->prepare($countQuery);
$totalStmt->execute($params);
$totalRecords = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$totalPages = ceil($totalRecords / $limit);

// Get uploads with filters
$uploadsQuery = "
    SELECT DISTINCT u.*, 
           u.uploaded_by as uploader_id,
           (CASE 
                WHEN u.uploaded_by = ? THEN 'You'
                WHEN EXISTS (SELECT 1 FROM users WHERE id = u.uploaded_by AND role = 'admin') THEN 'Admin'
                ELSE 'Other'
            END) as uploader_type,
           usr.name as uploader_name,
           usr.role as uploader_role,
           (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id) as submission_count,
           (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id AND status = 'graded') as graded_count
    FROM uploads u
    JOIN batch_uploads bu ON u.id = bu.upload_id
    JOIN batches b ON bu.batch_id = b.batch_id
    LEFT JOIN users usr ON u.uploaded_by = usr.id
    $whereClause
    ORDER BY $sortColumn $sortOrder
    LIMIT ? OFFSET ?
";

// Prepare parameters for uploads query
$uploadsParams = array_merge([$trainer_id], $params);
$uploadsParams[] = $limit;
$uploadsParams[] = $offset;

$uploadsStmt = $db->prepare($uploadsQuery);
$uploadsStmt->execute($uploadsParams);
$uploads = $uploadsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get batch associations for each upload
foreach ($uploads as &$upload) {
    $stmt = $db->prepare("
        SELECT b.batch_id, b.batch_name 
        FROM batch_uploads bu
        JOIN batches b ON bu.batch_id = b.batch_id
        WHERE bu.upload_id = ?
        AND b.batch_mentor_id = ?
    ");
    $stmt->execute([$upload['id'], $trainer['id']]);
    $upload['batches'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get uploader details if not already fetched
    if (empty($upload['uploader_name'])) {
        $uploaderStmt = $db->prepare("
            SELECT name, role 
            FROM users 
            WHERE id = ?
        ");
        $uploaderStmt->execute([$upload['uploader_id']]);
        $uploader = $uploaderStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($uploader) {
            $upload['uploader_name'] = $uploader['name'];
            $upload['uploader_role'] = $uploader['role'];
        }
    }
}
unset($upload);

// Helper function for building query strings
function buildQueryString($newParams = []) {
    $params = $_GET;
    
    foreach ($newParams as $key => $value) {
        $params[$key] = $value;
    }
    
    if (isset($params['page']) && $params['page'] == 1) {
        unset($params['page']);
    }
    
    return http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Content Management - Trainer Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.0.0/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.0.0/dist/js/tom-select.complete.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary: #1B3C53;
            --primary-hover: #4338ca;
            --secondary: #f9fafb;
            --accent: #10b981;
            --danger: #ef4444;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            transition: all 0.3s ease;
            overflow-x: hidden;
            background: linear-gradient(135deg, #f5f7ff 0%, #EEF3F6 40%, #fdf4ff 100%);
            background-attachment: fixed;
        }
        
        .card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.06), 0 2px 4px -1px rgba(79, 70, 229, 0.04);
            border: 1px solid rgba(27,60,83, 0.06);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card:hover {
            box-shadow: 0 12px 24px -3px rgba(27,60,83, 0.12), 0 4px 8px -2px rgba(27,60,83, 0.08);
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(27,60,83, 0.25);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #1B3C53, #6d28d9);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.35);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .filter-section {
            transition: all 0.3s ease;
            max-height: 1000px;
            overflow: hidden;
        }
        
        .filter-section.collapsed {
            max-height: 0;
            opacity: 0;
            padding: 0;
            margin: 0;
            border: none;
        }
        
        .sortable:hover {
            background: linear-gradient(135deg, #EEF3F6, #F6F1ED);
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
        
        .file-upload-container {
            border: 2px dashed #c7d2fe;
            border-radius: 0.75rem;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #f9fafb, #f0f4ff);
        }
        
        .file-upload-container:hover {
            border-color: var(--primary);
            background: linear-gradient(135deg, #EEF3F6, #F6F1ED);
            box-shadow: 0 8px 20px rgba(27,60,83, 0.1);
        }
        
        .file-upload-container.dragover {
            border-color: #234C6A;
            background: linear-gradient(135deg, #e0e7ff, #f3e8ff);
            transform: scale(1.01);
        }
        
        .content-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px -2px rgba(0,0,0,0.12), inset 0 1px 1px rgba(255,255,255,0.5);
        }
        
        .icon-test {
            background: linear-gradient(135deg, #d8b4fe, #234C6A);
            color: white;
        }
        
        .icon-assignment {
            background: linear-gradient(135deg, #93c5fd, #234C6A);
            color: white;
        }
        
        .icon-notes {
            background: linear-gradient(135deg, #6ee7b7, #059669);
            color: white;
        }
        
        .icon-other {
            background: linear-gradient(135deg, #d1d5db, #6b7280);
            color: white;
        }
        
        .stat-card:hover .content-icon {
            transform: scale(1.15) rotate(6deg);
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
            background: linear-gradient(135deg, #e9d5ff, #d8b4fe);
            color: #6b21a8;
        }
        
        .badge-assignment {
            background: linear-gradient(135deg, #bfdbfe, #93c5fd);
            color: #1e40af;
        }
        
        .badge-notes {
            background: linear-gradient(135deg, #a7f3d0, #6ee7b7);
            color: #065f46;
        }
        
        .badge-other {
            background: linear-gradient(135deg, #e5e7eb, #d1d5db);
            color: #374151;
        }
        
        .uploader-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .uploader-badge-you {
            background: linear-gradient(135deg, #bbf7d0, #86efac);
            color: #166534;
        }
        
        .uploader-badge-admin {
            background: linear-gradient(135deg, #fde68a, #fcd34d);
            color: #92400e;
        }
        
        .batch-tag {
            display: inline-flex;
            align-items: center;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            background: linear-gradient(135deg, #EEF3F6, #e0e7ff);
            color: #4338ca;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid rgba(27,60,83, 0.1);
        }
        
        .batch-tag:hover {
            background: linear-gradient(135deg, #e0e7ff, #ddd6fe);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(27,60,83, 0.15);
        }
        
        .action-btn {
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        tr {
            transition: all 0.2s ease;
        }
        
        tr:hover {
            background: linear-gradient(135deg, rgba(27,60,83, 0.04), rgba(192, 38, 211, 0.03));
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
        
        .pagination-btn {
            transition: all 0.2s ease;
        }
        
        .pagination-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(27,60,83, 0.15);
            border-color: #a5b4fc !important;
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #818cf8, #c084fc);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
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
            background: linear-gradient(135deg, #fecaca, #fca5a5);
            color: #b91c1c;
        }
        
        .due-date-upcoming {
            background: linear-gradient(135deg, #fde68a, #fcd34d);
            color: #b45309;
        }
        
        .due-date-future {
            background: linear-gradient(135deg, #a7f3d0, #6ee7b7);
            color: #047857;
        }
        
        .submission-stats {
            display: inline-flex;
            align-items: center;
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        .submission-stats i {
            margin-right: 0.25rem;
        }
        
        .required-field::after {
            content: "*";
            color: red;
            margin-left: 4px;
        }
        
        .ts-control {
            border-radius: 15px !important;
            padding: 0.5rem !important;
        }
        
        .ts-dropdown {
            border-radius: 0 0 15px 15px !important;
        }
        
        @media (max-width: 768px) {
            .table-responsive {
                display: block;
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            table {
                min-width: 600px;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .content-icon {
                width: 40px;
                height: 40px;
                font-size: 1.25rem;
            }
            
            .text-2xl {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 640px) {
            .card {
                padding: 1rem;
            }
            
            .grid-cols-1 {
                gap: 1rem;
            }
            
            .p-6 {
                padding: 1rem;
            }
            
            .stat-card {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
            
            .stat-card > div:first-child {
                order: 2;
            }
            
            .stat-card .content-icon {
                order: 1;
            }
        }
        
        @media (max-width: 480px) {
            .action-btn {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            
            .badge {
                padding: 0.125rem 0.5rem;
                font-size: 0.625rem;
            }
            
            .batch-tag {
                font-size: 0.625rem;
                padding: 0.125rem 0.5rem;
            }
            
            .uploader-badge {
                padding: 0.125rem 0.5rem;
                font-size: 0.625rem;
                margin-left: 0.25rem;
            }
        }
    
        /* Sidebar consistency fix: use the common trainer sidebar instead of page-local sidebar */
        @media (min-width: 1024px) {
            .flex-1.ml-0.lg\:ml-64 {
                margin-left: 16rem !important;
            }
        }

        aside {
            z-index: 50;
        }

        #sidebarOverlay {
            z-index: 40;
        }

    
/* ===== Brand palette update: #1B3C53, #234C6A, #456882, #D2C1B6 ===== */
:root {
    --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --trainer-primary: #234C6A !important;
    --trainer-violet: #1B3C53 !important;
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
}
body {
    background:
        radial-gradient(circle at 14% 10%, rgba(27,60,53,.13), transparent 28%),
        radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
        linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
}
.bg-gradient-to-r.from-purple-500.to-pink-500,
.bg-gradient-to-r.from-indigo-500.to-purple-500,
.bg-gradient-to-r.from-indigo-600.to-purple-600,
.bg-gradient-to-r.from-blue-500.to-cyan-500,
.bg-gradient-to-r.from-blue-500.to-indigo-500,
.bg-gradient-to-r.from-purple-600.to-pink-600,
.bg-gradient-to-br.from-purple-500.to-pink-500,
.bg-gradient-to-br.from-blue-500.to-indigo-500,
.bg-gradient-to-br.from-indigo-500.to-purple-500,
.avatar-gradient,.avatar-gradient-2,.avatar-gradient-3,.avatar-gradient-4,.avatar-gradient-5 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.text-purple-500,.text-purple-600,.text-indigo-500,.text-indigo-600,.text-blue-500,.text-blue-600,.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-purple-200,.border-indigo-200,.border-blue-200 {
    border-color: rgba(69,104,130,.25) !important;
}
button[style*="--primary-gradient"],.btn-primary,.tab-button.active,.view-toggle.active,.page-link.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.gradient-text {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    color: transparent !important;
}
.hero-chip,.section-kicker {
    border-color: rgba(210,193,182,.45) !important;
}

    
        /* Fix content page heading: keep text visible with new brand palette */
        .content-title-fixed {
            color: #1B3C53 !important;
            background: none !important;
            -webkit-background-clip: initial !important;
            background-clip: initial !important;
            font-weight: 900 !important;
            letter-spacing: -0.02em;
        }

        header h1 span:first-child {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
        }

    </style>
<style>

/* ===== Company Source Safe UI Patch: Content Management approved theme ===== */
/* CSS-only patch. Upload POST, filters, sorting, pagination, TomSelect, delete JS, download/grade links and DB queries untouched. */

:root {
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
    --theme-navy: linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%);
    --theme-blue: linear-gradient(135deg, #1d4ed8 0%, #2563eb 54%, #60a5fa 100%);
    --theme-purple: linear-gradient(135deg, #6d28d9 0%, #7c3aed 54%, #a78bfa 100%);
    --theme-green: linear-gradient(135deg, #65a30d 0%, #84cc16 54%, #bef264 100%);
    --theme-grey: linear-gradient(135deg, #374151 0%, #6b7280 54%, #9ca3af 100%);
}

html, body {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,53,.10), transparent 28%),
        radial-gradient(circle at 88% 4%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 46%, #FBFAF8 100%) !important;
    color: #1B3C53 !important;
}

/* Sidebar spacing safe */
@media (min-width: 1024px) {
    .flex-1.ml-0.lg\:ml-64 {
        margin-left: 16rem !important;
    }
}

aside {
    z-index: 50 !important;
}

/* Top header, finally visible instead of pretending to be fog */
header.sticky {
    background:
        radial-gradient(circle at 94% 14%, rgba(255,255,255,.18), transparent 28%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    border-bottom: 1.5px solid rgba(255,255,255,.22) !important;
    box-shadow:
        0 20px 44px rgba(27,60,53,.22),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    backdrop-filter: blur(16px) !important;
}

header.sticky h1,
header.sticky h1 span,
header.sticky .content-title-fixed,
header.sticky .hidden span,
header.sticky button,
header.sticky i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.16) !important;
    background: none !important;
}

header.sticky h1 > span:first-child {
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.4px solid rgba(255,255,255,.42) !important;
    box-shadow:
        0 12px 26px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.26) !important;
}

header.sticky .hidden span:last-child {
    font-weight: 1000 !important;
}

/* Top stats: requested color coding */
.stat-card {
    position: relative !important;
    overflow: hidden !important;
    min-height: 92px !important;
    border-radius: 18px !important;
    color: #ffffff !important;
    border: 1.55px solid rgba(255,255,255,.38) !important;
    box-shadow:
        0 18px 38px rgba(27,60,53,.16),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.stat-card::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.20), transparent 34%),
        radial-gradient(circle at 4% 100%, rgba(255,255,255,.10), transparent 32%) !important;
    pointer-events: none !important;
}

.stat-card::after {
    content: "" !important;
    position: absolute !important;
    right: -38px !important;
    top: -42px !important;
    width: 124px !important;
    height: 124px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.14) !important;
    pointer-events: none !important;
}

.stat-card > * {
    position: relative !important;
    z-index: 2 !important;
}

.stat-card.test {
    background: var(--theme-purple) !important;
}

.stat-card.assignment {
    background: var(--theme-blue) !important;
}

.stat-card.notes {
    background: var(--theme-green) !important;
}

.stat-card.other {
    background: var(--theme-grey) !important;
}

.stat-card:hover {
    transform: translateY(-5px) !important;
    filter: brightness(1.06) !important;
    box-shadow:
        0 28px 62px rgba(27,60,53,.25),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

.stat-card p,
.stat-card h3,
.stat-card i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.16) !important;
}

.stat-card .content-icon {
    width: 54px !important;
    height: 54px !important;
    min-width: 54px !important;
    min-height: 54px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.45px solid rgba(255,255,255,.46) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow:
        0 12px 26px rgba(0,0,0,.20),
        inset 0 1px 0 rgba(255,255,255,.26) !important;
}

.stat-card:hover .content-icon {
    transform: translateY(-2px) scale(1.10) rotate(3deg) !important;
    box-shadow:
        0 17px 36px rgba(0,0,0,.25),
        0 0 0 8px rgba(255,255,255,.14),
        inset 0 1px 0 rgba(255,255,255,.30) !important;
}

/* Main cards: Upload New Content and Content Library shades */
.card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.09), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.55px solid rgba(210,193,182,.66) !important;
    border-radius: 24px !important;
    box-shadow:
        0 18px 44px rgba(27,60,53,.11),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    backdrop-filter: blur(16px) !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.card:hover {
    transform: translateY(-3px) !important;
    border-color: rgba(35,76,106,.42) !important;
    box-shadow:
        0 26px 58px rgba(27,60,53,.16),
        inset 0 1px 0 rgba(255,255,255,.90) !important;
}

.card:has(#uploadForm),
.card:has(#filterSection) {
    position: relative !important;
    overflow: hidden !important;
}

.card:has(#uploadForm)::before,
.card:has(#filterSection)::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 0 auto 0 !important;
    height: 5px !important;
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882) !important;
    z-index: 1 !important;
}

.card:has(#uploadForm)::after,
.card:has(#filterSection)::after {
    content: "" !important;
    position: absolute !important;
    width: 220px !important;
    height: 220px !important;
    right: -70px !important;
    top: -80px !important;
    border-radius: 999px !important;
    background: radial-gradient(circle, rgba(69,104,130,.14), rgba(210,193,182,.08) 58%, transparent 72%) !important;
    filter: blur(7px) !important;
    pointer-events: none !important;
}

.card:has(#uploadForm) > *,
.card:has(#filterSection) > * {
    position: relative !important;
    z-index: 2 !important;
}

/* Section title icons */
.card h2 > span:first-child {
    width: 42px !important;
    height: 42px !important;
    min-width: 42px !important;
    min-height: 42px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
    border: 1.4px solid rgba(255,255,255,.42) !important;
    box-shadow:
        0 12px 26px rgba(27,60,53,.18),
        inset 0 1px 0 rgba(255,255,255,.24) !important;
}

.card h2,
.card h2 span:last-child {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

/* Form labels and controls */
label,
.required-field,
.card .text-gray-700,
.card .text-gray-600,
.card .text-gray-500 {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

label {
    font-weight: 900 !important;
}

input,
select,
textarea,
.ts-control {
    background: rgba(255,255,255,.96) !important;
    border: 1.35px solid rgba(69,104,130,.28) !important;
    color: #102A3A !important;
    -webkit-text-fill-color: #102A3A !important;
    border-radius: 15px !important;
    font-weight: 750 !important;
    box-shadow:
        0 8px 20px rgba(27,60,53,.045),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
}

input::placeholder,
textarea::placeholder {
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
    opacity: .72 !important;
    font-weight: 700 !important;
}

input:focus,
select:focus,
textarea:focus,
.ts-control.focus {
    border-color: #234C6A !important;
    box-shadow:
        0 0 0 4px rgba(35,76,106,.13),
        0 12px 24px rgba(27,60,53,.09) !important;
    background: #ffffff !important;
}

/* Source tabs */
.source-tab {
    border-radius: 14px !important;
    border: 1.3px solid rgba(210,193,182,.65) !important;
    background: rgba(255,255,255,.80) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    box-shadow: 0 8px 18px rgba(27,60,53,.055) !important;
    transition: all .22s ease !important;
}

.source-tab.active,
.source-tab:hover {
    background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    transform: translateY(-2px) !important;
}

/* Upload drop zone */
.file-upload-container {
    background:
        radial-gradient(circle at 50% 0%, rgba(69,104,130,.12), transparent 42%),
        linear-gradient(135deg, rgba(238,243,246,.96), rgba(246,241,237,.82)) !important;
    border: 2px dashed rgba(69,104,130,.42) !important;
    border-radius: 22px !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.72),
        0 12px 26px rgba(27,60,53,.06) !important;
}

.file-upload-container:hover,
.file-upload-container.dragover {
    background:
        radial-gradient(circle at 50% 0%, rgba(69,104,130,.16), transparent 42%),
        linear-gradient(135deg, rgba(238,243,246,1), rgba(210,193,182,.34)) !important;
    border-color: #234C6A !important;
    transform: translateY(-2px) !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.76),
        0 20px 38px rgba(27,60,53,.12) !important;
}

#fileDropArea .fa-cloud-upload-alt {
    background: none !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    width: 58px !important;
    height: 58px !important;
    border-radius: 999px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
    box-shadow: 0 14px 28px rgba(27,60,53,.18) !important;
}

/* Buttons */
.btn-primary,
button[type="submit"],
.action-btn.bg-gradient-to-r,
#filterSection button[type="submit"] {
    background:
        radial-gradient(circle at 90% 10%, rgba(255,255,255,.16), transparent 35%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.3px solid rgba(255,255,255,.30) !important;
    border-radius: 14px !important;
    font-weight: 1000 !important;
    box-shadow: 0 14px 28px rgba(27,60,53,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.btn-primary:hover,
button[type="submit"]:hover,
.action-btn:hover,
#filterSection button[type="submit"]:hover {
    transform: translateY(-3px) !important;
    filter: brightness(1.06) !important;
}

/* Content Library filter box */
#filterSection {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.08), transparent 34%),
        linear-gradient(135deg, rgba(238,243,246,.92), rgba(246,241,237,.82)) !important;
    border: 1.35px solid rgba(210,193,182,.66) !important;
    border-radius: 20px !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.78),
        0 12px 28px rgba(27,60,53,.065) !important;
}

button[onclick="toggleFilters()"] {
    background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.2px solid rgba(255,255,255,.32) !important;
    border-radius: 12px !important;
    box-shadow: 0 10px 22px rgba(27,60,53,.16) !important;
}

/* Table */
.table-responsive {
    border-radius: 20px !important;
    border: 1.25px solid rgba(210,193,182,.62) !important;
    background: rgba(255,255,255,.74) !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.74) !important;
}

table thead {
    background:
        linear-gradient(135deg, rgba(238,243,246,.96), rgba(210,193,182,.32)) !important;
}

table thead th {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

tbody.bg-white {
    background: rgba(255,255,255,.74) !important;
}

tbody tr:hover {
    background:
        linear-gradient(90deg, rgba(238,243,246,.92), rgba(246,241,237,.82)) !important;
}

td,
td .text-gray-900,
td .text-gray-500,
td .text-gray-400 {
    color: #1B3C53 !important;
}

/* Badges and tags according to theme */
.badge-test {
    background: linear-gradient(135deg, rgba(124,58,237,.18), rgba(167,139,250,.28)) !important;
    color: #5b21b6 !important;
}

.badge-assignment {
    background: linear-gradient(135deg, rgba(37,99,235,.16), rgba(96,165,250,.26)) !important;
    color: #1d4ed8 !important;
}

.badge-notes {
    background: linear-gradient(135deg, rgba(132,204,22,.18), rgba(190,242,100,.34)) !important;
    color: #3f6212 !important;
}

.badge-other {
    background: linear-gradient(135deg, rgba(107,114,128,.16), rgba(156,163,175,.28)) !important;
    color: #374151 !important;
}

.batch-tag {
    background:
        linear-gradient(135deg, rgba(238,243,246,.98), rgba(210,193,182,.28)) !important;
    color: #1B3C53 !important;
    border: 1px solid rgba(69,104,130,.24) !important;
    font-weight: 800 !important;
}

.uploader-badge-you {
    background: linear-gradient(135deg, rgba(16,185,129,.18), rgba(110,231,183,.34)) !important;
    color: #047857 !important;
}

.uploader-badge-admin {
    background: linear-gradient(135deg, rgba(245,158,11,.20), rgba(252,211,77,.34)) !important;
    color: #92400e !important;
}

/* Pagination */
.pagination-btn,
a[href*="page="] {
    border-radius: 12px !important;
    font-weight: 900 !important;
}

/* Alerts */
.bg-gradient-to-r.from-green-50,
.bg-gradient-to-r.from-red-50 {
    border-radius: 18px !important;
    box-shadow: 0 12px 28px rgba(27,60,53,.10) !important;
}

/* Scrollbar theme */
::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
}

/* Mobile polish */
@media (max-width: 768px) {
    header.sticky {
        border-radius: 0 0 18px 18px !important;
    }

    .stat-card,
    .card {
        border-radius: 20px !important;
    }

    .stat-card .content-icon {
        width: 46px !important;
        height: 46px !important;
        min-width: 46px !important;
        min-height: 46px !important;
    }
}

</style>
</head>
<body class="bg-gray-50 text-gray-800">
<?php include '../t_sidebar.php'; ?>

<div class="flex-1 ml-0 lg:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <header class="bg-white/90 shadow-sm px-4 sm:px-6 py-4 flex justify-between items-center sticky top-0 z-30 backdrop-blur-md border-b border-indigo-100">
        <button class="lg:hidden text-xl text-gray-600 hover:text-gray-900 transition-colors" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-xl sm:text-2xl font-bold flex items-center space-x-2">
            <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-md">
                <i class="fas fa-cloud-upload-alt text-white text-sm"></i>
            </span>
            <span class="content-title-fixed">Content Management</span>
        </h1>
        <div class="hidden sm:flex items-center space-x-2">
            <span class="text-sm text-gray-600">Trainer:</span>
            <span class="font-medium text-indigo-600"><?= htmlspecialchars($trainer['name']) ?></span>
        </div>
    </header>

    <div class="p-3 sm:p-4 md:p-6">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg shadow-sm animate__animated animate__fadeIn">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-green-800 font-medium text-sm sm:text-base"><?= htmlspecialchars($_SESSION['success']) ?></p>
                    </div>
                    <div class="ml-auto">
                        <button onclick="this.parentElement.parentElement.remove()" class="text-green-600 hover:text-green-800">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-gradient-to-r from-red-50 to-rose-50 border border-red-200 rounded-lg shadow-sm animate__animated animate__fadeIn">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-red-800 font-medium text-sm sm:text-base"><?= htmlspecialchars($_SESSION['error']) ?></p>
                    </div>
                    <div class="ml-auto">
                        <button onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4 sm:mb-6">
            <div class="stat-card test card p-3 sm:p-4 flex items-center justify-between relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-purple-400 to-fuchsia-600"></div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-500">Tests</p>
                    <h3 class="text-xl sm:text-2xl font-bold text-purple-700"><?= $stats['Test'] ?></h3>
                </div>
                <div class="content-icon icon-test">
                    <i class="fas fa-question-circle"></i>
                </div>
            </div>
            
            <div class="stat-card assignment card p-3 sm:p-4 flex items-center justify-between relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-blue-400 to-indigo-600"></div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-500">Assignments</p>
                    <h3 class="text-xl sm:text-2xl font-bold text-blue-700"><?= $stats['Assignment'] ?></h3>
                </div>
                <div class="content-icon icon-assignment">
                    <i class="fas fa-tasks"></i>
                </div>
            </div>
            
            <div class="stat-card notes card p-3 sm:p-4 flex items-center justify-between relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-emerald-400 to-green-600"></div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-500">Notes</p>
                    <h3 class="text-xl sm:text-2xl font-bold text-green-700"><?= $stats['Notes'] ?></h3>
                </div>
                <div class="content-icon icon-notes">
                    <i class="fas fa-book"></i>
                </div>
            </div>
            
            <div class="stat-card other card p-3 sm:p-4 flex items-center justify-between relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b from-gray-400 to-slate-600"></div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-500">Other</p>
                    <h3 class="text-xl sm:text-2xl font-bold text-gray-700"><?= $stats['Other'] ?></h3>
                </div>
                <div class="content-icon icon-other">
                    <i class="fas fa-file"></i>
                </div>
            </div>
        </div>
        
        <div class="card p-4 sm:p-6 mb-4 sm:mb-6 animate__animated animate__fadeInUp">
            <h2 class="text-lg sm:text-xl font-semibold mb-3 sm:mb-4 flex items-center">
                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center mr-2 shadow-md">
                    <i class="fas fa-upload text-white text-sm"></i>
                </span>
                <span>Upload New Content</span>
            </h2>
            <form id="uploadForm" method="POST" enctype="multipart/form-data" class="space-y-3 sm:space-y-4">
                <input type="hidden" name="upload_content" value="1">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                    <div class="space-y-1">
                        <label for="title" class="block text-sm font-medium text-gray-700 required-field">Title</label>
                        <input type="text" id="title" name="title" required
                               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base"
                               placeholder="Enter content title">
                    </div>
                    
                    <div class="space-y-1">
                        <label for="file_type" class="block text-sm font-medium text-gray-700 required-field">File Type</label>
                        <select id="file_type" name="file_type" required
                                class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base">
                            <option value="">Select a type</option>
                            <option value="Test">Test</option>
                            <option value="Assignment">Assignment</option>
                            <option value="Notes">Notes</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="space-y-1" id="dueDateContainer">
                        <label for="due_date" class="block text-sm font-medium text-gray-700">Due Date <span id="dueDateRequired" class="text-red-500 hidden">*</span></label>
                        <input type="date" id="due_date" name="due_date"
                               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base"
                               min="<?= date('Y-m-d') ?>">
                        <p class="text-xs text-gray-500 mt-1" id="dueDateHint">For assignments only</p>
                    </div>
                    
                    <div class="space-y-1" id="maxMarksContainer">
                        <label for="max_marks" class="block text-sm font-medium text-gray-700">Maximum Marks</label>
                        <input type="number" id="max_marks" name="max_marks" step="0.01" min="0" max="1000" value="100"
                               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base">
                        <p class="text-xs text-gray-500 mt-1">For assignments only</p>
                    </div>
                    
                    <div class="md:col-span-2 space-y-1">
                        <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea id="description" name="description" rows="3"
                                  class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base"
                                  placeholder="Enter a brief description (optional)"></textarea>
                    </div>
                    
                    <div class="md:col-span-2 space-y-1">
                        <label for="course_id" class="block text-sm font-medium text-gray-700 required-field">Associated Course</label>
                        <select id="course_id" name="course_id" required
                                class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base">
                            <option value="">Select a course</option>
                            <?php if (!empty($mentor_courses)): ?>
                                <?php foreach ($mentor_courses as $course): ?>
                                    <option value="<?= htmlspecialchars($course['course_id']) ?>">
                                        <?= htmlspecialchars($course['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No courses assigned to you</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <!-- NEW: Batch Selection -->
                    <div class="md:col-span-2 space-y-1">
                        <label for="batch_ids" class="block text-sm font-medium text-gray-700 required-field">Select Batches</label>
                        <select id="batch_ids" name="batch_ids[]" multiple required
                                class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base">
                            <?php if (!empty($course_batches)): ?>
                                <?php foreach ($course_batches as $course_id => $data): ?>
                                    <optgroup label="<?= htmlspecialchars($data['course_name']) ?>">
                                        <?php foreach ($data['batches'] as $batch): ?>
                                            <option value="<?= htmlspecialchars($batch['batch_id']) ?>">
                                                <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No batches assigned to you</option>
                            <?php endif; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple batches</p>
                    </div>
                    
                    <div class="md:col-span-2 space-y-2">
                        <label class="block text-sm font-medium text-gray-700 required-field">Content Source</label>
                        
                        <div class="flex gap-2 mb-2">
                            <div class="source-tab cursor-pointer px-4 py-2 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 font-medium active" onclick="switchSource('file')" id="tabFile">
                                <i class="fas fa-file-upload mr-1"></i> File Upload
                            </div>
                            <div class="source-tab cursor-pointer px-4 py-2 rounded-lg border border-gray-200 bg-white text-gray-600 font-medium" onclick="switchSource('drive')" id="tabDrive">
                                <i class="fab fa-google-drive mr-1"></i> Google Drive
                            </div>
                        </div>
                        <input type="hidden" name="content_source" id="contentSource" value="file">
                        
                        <div id="fileSourceContent" class="block">
                            <div id="fileDropArea" class="file-upload-container p-4 sm:p-8 text-center cursor-pointer border-2 border-dashed border-indigo-200 rounded-lg">
                                <input type="file" id="file" name="file" class="hidden" accept=".pdf,.doc,.docx">
                                <div class="flex flex-col items-center justify-center space-y-2">
                                    <i class="fas fa-cloud-upload-alt text-3xl sm:text-4xl mb-2 bg-gradient-to-br from-indigo-500 to-purple-600 bg-clip-text text-transparent"></i>
                                    <p class="text-xs sm:text-sm text-gray-600">Drag & drop your file here or click to browse</p>
                                    <p class="text-xs text-gray-500 mt-1">Supports: PDF, DOC, DOCX (Max 10MB)</p>
                                    <div id="fileNameDisplay" class="mt-2 text-sm font-medium text-indigo-600 hidden"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="driveSourceContent" class="hidden">
                            <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                                <label for="driveLink" class="block text-sm font-medium text-gray-700 mb-1 required-field">Google Drive Link</label>
                                <input type="url" name="drive_link" id="driveLink" value="<?= isset($_POST['drive_link']) ? htmlspecialchars($_POST['drive_link']) : '' ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                       placeholder="https://drive.google.com/file/d/.../view?usp=sharing">
                                <p class="text-xs text-gray-500 mt-2">
                                    <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                                    Make sure the link is set to "Anyone with the link can view"
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end pt-3 sm:pt-4">
                    <button type="submit" class="btn-primary px-4 sm:px-6 py-2 text-white rounded-lg flex items-center space-x-2 text-sm sm:text-base">
                        <i class="fas fa-upload"></i>
                        <span>Upload Content</span>
                    </button>
                </div>
            </form>
        </div>
        
        <div class="card p-4 sm:p-6 animate__animated animate__fadeIn">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-3 sm:gap-0">
                <h2 class="text-lg sm:text-xl font-semibold flex items-center">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center mr-2 shadow-md">
                        <i class="fas fa-folder-open text-white text-sm"></i>
                    </span>
                    <span>Content Library</span>
                </h2>
                <div class="flex items-center space-x-4 w-full sm:w-auto">
                    <div class="text-sm text-gray-500">
                        Total: <span class="font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent"><?= $totalRecords ?></span> items
                    </div>
                    <button onclick="toggleFilters()" class="px-3 py-1 border border-indigo-200 rounded-md text-sm text-indigo-700 bg-gradient-to-r from-indigo-50 to-purple-50 hover:from-indigo-100 hover:to-purple-100 flex items-center ml-auto sm:ml-0 transition-all">
                        <i id="filterIcon" class="fas fa-filter mr-1"></i>
                        Filters
                    </button>
                </div>
            </div>
            
            <div id="filterSection" class="filter-section border border-indigo-100 rounded-lg p-3 sm:p-4 mb-4 bg-gradient-to-br from-indigo-50/60 to-purple-50/60">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all"
                                   placeholder="Search title or description">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">File Type</label>
                            <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all">
                                <option value="all">All Types</option>
                                <option value="Lab Manual" <?= $fileTypeFilter === 'Lab Manual' ? 'selected' : '' ?>>Lab Manual</option>
                                <option value="Assignment" <?= $fileTypeFilter === 'Assignment' ? 'selected' : '' ?>>Assignment</option>
                                <option value="Notes" <?= $fileTypeFilter === 'Notes' ? 'selected' : '' ?>>Notes</option>
                                <option value="Other" <?= $fileTypeFilter === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Batch</label>
                            <select name="batch" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all">
                                <option value="all">All Batches</option>
                                <?php foreach ($mentor_batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $batchFilter === $batch['batch_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2 items-end">
                            <div class="flex-1 w-full">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all">
                            </div>
                            <div class="flex-1 w-full">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all">
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="resetFilters()" class="px-3 sm:px-4 py-2 text-sm text-gray-700 hover:text-gray-900">
                            Clear Filters
                        </button>
                        <button type="submit" class="px-3 sm:px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-md text-sm hover:from-indigo-700 hover:to-purple-700 shadow-md hover:shadow-lg transition-all">
                            Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="table-responsive overflow-x-auto rounded-lg border border-indigo-100">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-indigo-50 via-purple-50 to-pink-50">
                        <tr>
                            <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable" 
                                onclick="sortTable('title')">
                                <div class="flex items-center">
                                    <span>Title</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'title' ? 'active' : '' ?> <?= $sortColumn === 'title' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable" 
                                onclick="sortTable('file_type')">
                                <div class="flex items-center">
                                    <span>Type & Uploader</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'file_type' ? 'active' : '' ?> <?= $sortColumn === 'file_type' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batches</th>
                            <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable" 
                                onclick="sortTable('due_date')">
                                <div class="flex items-center">
                                    <span>Due Date</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'due_date' ? 'active' : '' ?> <?= $sortColumn === 'due_date' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable" 
                                onclick="sortTable('uploaded_at')">
                                <div class="flex items-center">
                                    <span>Date</span>
                                    <i class="fas fa-sort ml-1 sort-icon <?= $sortColumn === 'uploaded_at' ? 'active' : '' ?> <?= $sortColumn === 'uploaded_at' && $sortOrder === 'ASC' ? 'asc' : '' ?>"></i>
                                </div>
                            </th>
                            <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="contentTableBody">
                        <?php if (empty($uploads)): ?>
                            <tr>
                                <td colspan="6" class="px-4 sm:px-6 py-8 text-center">
                                    <div class="flex flex-col items-center justify-center space-y-2 text-gray-400">
                                        <i class="fas fa-box-open text-4xl"></i>
                                        <p class="text-sm">No content found</p>
                                        <?php if (empty($mentor_batches)): ?>
                                            <p class="text-sm text-red-500 mt-2">You are not assigned to any batches</p>
                                        <?php elseif (!empty($searchTerm) || !empty($fileTypeFilter) || !empty($batchFilter) || !empty($dateFrom) || !empty($dateTo)): ?>
                                            <button onclick="resetFilters()" class="text-blue-500 hover:text-blue-700 text-sm mt-2">
                                                Clear filters to see all content
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($uploads as $index => $upload): ?>
                                <tr class="fade-in" style="animation-delay: <?= $index * 0.05 ?>s">
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($upload['title']) ?>
                                            <?php if ($upload['description']): ?>
                                                <p class="text-xs text-gray-500 mt-1 truncate max-w-xs"><?= htmlspecialchars($upload['description']) ?></p>
                                            <?php endif; ?>
                                            <?php if ($upload['file_type'] === 'Assignment' && $upload['submission_count'] > 0): ?>
                                                <div class="submission-stats mt-1">
                                                    <i class="fas fa-users"></i>
                                                    <?= $upload['submission_count'] ?> submissions
                                                    <?php if ($upload['graded_count'] > 0): ?>
                                                        <span class="ml-2">
                                                            <i class="fas fa-check text-green-500"></i>
                                                            <?= $upload['graded_count'] ?> graded
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col space-y-1">
                                            <div>
                                                <?php
                                                $badgeClass = '';
                                                $iconClass = '';
                                                switch($upload['file_type']) {
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
                                                    default:
                                                        $badgeClass = 'badge-other';
                                                        $iconClass = 'fa-file';
                                                }
                                                ?>
                                                <span class="badge <?= $badgeClass ?>">
                                                    <i class="fas <?= $iconClass ?> mr-1"></i>
                                                    <?= htmlspecialchars($upload['file_type']) ?>
                                                </span>
                                                <?php if (isset($upload['uploader_type'])): ?>
                                                    <span class="uploader-badge <?= $upload['uploader_type'] === 'You' ? 'uploader-badge-you' : 'uploader-badge-admin' ?>">
                                                        <i class="fas <?= $upload['uploader_type'] === 'You' ? 'fa-user-check' : 'fa-user-tie' ?> mr-1"></i>
                                                        <?= htmlspecialchars($upload['uploader_type']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (isset($upload['uploader_name']) && $upload['uploader_type'] !== 'You'): ?>
                                                <div class="text-xs text-gray-500">
                                                    <i class="fas fa-user-circle mr-1"></i>
                                                    Uploaded by: <?= htmlspecialchars($upload['uploader_name']) ?>
                                                    <?php if (isset($upload['uploader_role'])): ?>
                                                        (<?= htmlspecialchars($upload['uploader_role']) ?>)
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <div class="flex flex-wrap">
                                            <?php foreach ($upload['batches'] as $batch): ?>
                                                <span class="batch-tag">
                                                    <i class="fas fa-users mr-1 text-xs"></i>
                                                    <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                        <?php if ($upload['due_date']): ?>
                                            <?php
                                            $due_date = new DateTime($upload['due_date']);
                                            $today = new DateTime();
                                            $today->setTime(0, 0, 0);
                                            $due_date->setTime(0, 0, 0);
                                            $interval = $today->diff($due_date);
                                            $days_left = $interval->format('%r%a');
                                            
                                            $due_badge_class = '';
                                            $due_text = '';
                                            
                                            if ($days_left < 0) {
                                                $due_badge_class = 'due-date-overdue';
                                                $due_text = 'Overdue';
                                            } elseif ($days_left <= 7) {
                                                $due_badge_class = 'due-date-upcoming';
                                                $due_text = 'Soon';
                                            } else {
                                                $due_badge_class = 'due-date-future';
                                                $due_text = 'Upcoming';
                                            }
                                            ?>
                                            <div>
                                                <span class="due-date-badge <?= $due_badge_class ?>">
                                                    <i class="far fa-calendar-alt mr-1"></i>
                                                    <?= date('M j, Y', strtotime($upload['due_date'])) ?>
                                                </span>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    <?= $due_text ?>
                                                    <?php if ($days_left != 0): ?>
                                                        (<?= abs($days_left) ?> days <?= $days_left < 0 ? 'ago' : 'left' ?>)
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <div class="flex items-center">
                                            <i class="far fa-calendar-alt mr-2 text-gray-400"></i>
                                            <?= date('M j, Y', strtotime($upload['uploaded_at'])) ?>
                                        </div>
                                    </td>
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex flex-wrap gap-2">
                                            <?php if (isset($upload['content_source']) && $upload['content_source'] === 'drive'): ?>
                                                <a href="<?= htmlspecialchars($upload['file_path']) ?>" 
                                                   target="_blank"
                                                   class="action-btn text-white px-2 sm:px-3 py-1 rounded-md bg-gradient-to-r from-blue-500 to-indigo-600 hover:from-blue-600 hover:to-indigo-700 shadow-sm">
                                                    <i class="fab fa-google-drive mr-1"></i> <span class="hidden sm:inline">Drive Link</span>
                                                </a>
                                            <?php else: ?>
                                                <a href="../<?= htmlspecialchars($upload['file_path']) ?>" 
                                                   download
                                                   class="action-btn text-white px-2 sm:px-3 py-1 rounded-md bg-gradient-to-r from-blue-500 to-indigo-600 hover:from-blue-600 hover:to-indigo-700 shadow-sm">
                                                    <i class="fas fa-download mr-1"></i> <span class="hidden sm:inline">Download</span>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($upload['file_type'] === 'Assignment'): ?>
                                                <a href="trainer_grade_assignments.php?assignment_id=<?= $upload['id'] ?>" 
                                                   class="action-btn text-white px-2 sm:px-3 py-1 rounded-md bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 shadow-sm">
                                                    <i class="fas fa-eye mr-1"></i> <span class="hidden sm:inline">Grade</span>
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
            
            <?php if (!empty($uploads)): ?>
            <div class="flex flex-col sm:flex-row justify-between items-center mt-4 px-2 gap-3 sm:gap-0">
                <div class="text-sm text-gray-500">
                    Showing <span class="font-medium"><?= ($page - 1) * $limit + 1 ?></span> to <span class="font-medium"><?= min($page * $limit, $totalRecords) ?></span> of <span class="font-medium"><?= $totalRecords ?></span> results
                </div>
                <div class="flex space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?= buildQueryString(['page' => $page - 1]) ?>" 
                           class="pagination-btn px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Previous
                        </a>
                    <?php else: ?>
                        <button class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-50 cursor-not-allowed" disabled>
                            Previous
                        </button>
                    <?php endif; ?>
                    
                    <div class="flex space-x-1">
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($p = $startPage; $p <= $endPage; $p++):
                        ?>
                            <a href="?<?= buildQueryString(['page' => $p]) ?>" 
                               class="px-3 py-1 border rounded-md text-sm font-medium <?= $p == $page ? 'bg-gradient-to-r from-indigo-600 to-purple-600 border-transparent text-white shadow-md' : 'border-gray-300 text-gray-700 bg-white hover:bg-gray-50' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= buildQueryString(['page' => $page + 1]) ?>" 
                           class="pagination-btn px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Next
                        </a>
                    <?php else: ?>
                        <button class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-50 cursor-not-allowed" disabled>
                            Next
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize TomSelect for batch selection
    const batchSelect = document.getElementById('batch_ids');
    if (batchSelect) {
        new TomSelect(batchSelect, {
            plugins: ['remove_button', 'clear_button'],
            create: false,
            maxItems: null,
            placeholder: 'Select batch(es)',
            render: {
                option: function(data, escape) {
                    return '<div class="flex items-center">' +
                           '<span class="inline-block w-3 h-3 rounded-full bg-blue-500 mr-2"></span>' +
                           escape(data.text) +
                           '</div>';
                }
            }
        });
    }
    
    // Show/hide assignment-specific fields based on file type
    const fileTypeSelect = document.getElementById('file_type');
    const dueDateInput = document.getElementById('due_date');
    const maxMarksInput = document.getElementById('max_marks');
    const dueDateRequired = document.getElementById('dueDateRequired');
    const dueDateHint = document.getElementById('dueDateHint');
    
    function toggleAssignmentFields() {
        const isAssignment = fileTypeSelect && fileTypeSelect.value === 'Assignment';
        
        if (isAssignment) {
            if (dueDateInput) {
                dueDateInput.required = true;
                dueDateInput.disabled = false;
            }
            if (dueDateRequired) dueDateRequired.classList.remove('hidden');
            if (dueDateHint) dueDateHint.innerHTML = 'Due date is required for assignments';
            if (maxMarksInput) {
                maxMarksInput.disabled = false;
                maxMarksInput.required = true;
            }
        } else {
            if (dueDateInput) {
                dueDateInput.required = false;
                dueDateInput.disabled = true;
                dueDateInput.value = '';
            }
            if (dueDateRequired) dueDateRequired.classList.add('hidden');
            if (dueDateHint) dueDateHint.innerHTML = 'For assignments only';
            if (maxMarksInput) {
                maxMarksInput.disabled = true;
                maxMarksInput.required = false;
                maxMarksInput.value = '100';
            }
        }
    }
    
    if (fileTypeSelect) {
        fileTypeSelect.addEventListener('change', toggleAssignmentFields);
        toggleAssignmentFields();
    }
    
    // File upload drag and drop
    const fileDropArea = document.getElementById('fileDropArea');
    const fileInput = document.getElementById('file');
    const fileNameDisplay = document.getElementById('fileNameDisplay');
    
    if (fileDropArea && fileInput) {
        fileDropArea.addEventListener('click', () => fileInput.click());
        
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) {
                fileNameDisplay.textContent = fileInput.files[0].name;
                fileNameDisplay.classList.remove('hidden');
                fileDropArea.classList.add('border-blue-500', 'bg-blue-50');
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
                fileNameDisplay.textContent = files[0].name;
                fileNameDisplay.classList.remove('hidden');
                fileDropArea.classList.add('border-blue-500', 'bg-blue-50');
            }
        }
    }
    
    // Form validation
    const uploadForm = document.getElementById('uploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            const title = document.getElementById('title');
            const fileType = document.getElementById('file_type');
            const courseId = document.getElementById('course_id');
            const batchSelect = document.getElementById('batch_ids');
            const contentSource = document.getElementById('contentSource');
            const driveLink = document.getElementById('driveLink');
            const fileInput = document.getElementById('file');
            const dueDate = document.getElementById('due_date');
            const maxMarks = document.getElementById('max_marks');
            
            // Validate title
            if (!title || !title.value.trim()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Oops...',
                    text: 'Title is required',
                    confirmButtonColor: '#1B3C53'
                });
                return;
            }
            
            // Validate file type
            if (!fileType || !fileType.value) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Oops...',
                    text: 'File type is required',
                    confirmButtonColor: '#1B3C53'
                });
                return;
            }
            
            // Validate course
            if (!courseId || !courseId.value) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Oops...',
                    text: 'Please select a course',
                    confirmButtonColor: '#1B3C53'
                });
                return;
            }
            
            // Validate batch selection
            if (batchSelect) {
                const selectedOptions = batchSelect.selectedOptions;
                if (!selectedOptions || selectedOptions.length === 0) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: 'Please select at least one batch',
                        confirmButtonColor: '#1B3C53'
                    });
                    return;
                }
            }
            
            // Validate based on content source
            if (contentSource && contentSource.value === 'drive') {
                if (!driveLink || !driveLink.value.trim()) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: 'Google Drive link is required',
                        confirmButtonColor: '#1B3C53'
                    });
                    return;
                }
                
                const driveUrl = driveLink.value.trim();
                if (!driveUrl.includes('drive.google.com')) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid Link',
                        text: 'Please enter a valid Google Drive link',
                        confirmButtonColor: '#1B3C53'
                    });
                    return;
                }
            } else {
                if (fileInput && (!fileInput.files || !fileInput.files.length)) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: 'Please select a file to upload',
                        confirmButtonColor: '#1B3C53'
                    });
                    return;
                }
                
                if (fileInput && fileInput.files[0] && fileInput.files[0].size > 10 * 1024 * 1024) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'File too large',
                        text: 'File size exceeds 10MB limit',
                        confirmButtonColor: '#1B3C53'
                    });
                    return;
                }
            }
            
            // Validate due date for assignments
            if (fileType.value === 'Assignment') {
                if (!dueDate || !dueDate.value) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Due Date Required',
                        text: 'Please select a due date for this assignment',
                        confirmButtonColor: '#1B3C53'
                    });
                    return;
                }
                
                const today = new Date();
                const dueDateObj = new Date(dueDate.value);
                today.setHours(0, 0, 0, 0);
                dueDateObj.setHours(0, 0, 0, 0);
                
                if (dueDateObj < today) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid due date',
                        text: 'Due date cannot be in the past',
                        confirmButtonColor: '#1B3C53'
                    });
                    return;
                }
                
                if (maxMarks) {
                    const marks = parseFloat(maxMarks.value);
                    if (isNaN(marks) || marks <= 0 || marks > 1000) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid marks',
                            text: 'Maximum marks must be between 1 and 1000',
                            confirmButtonColor: '#1B3C53'
                        });
                        return;
                    }
                }
            }
            
            const submitBtn = uploadForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Uploading...';
                submitBtn.disabled = true;
            }
        });
    }
    
    // Sidebar functionality
    const sidebar = document.querySelector('aside');
    const mobileToggleBtn = document.getElementById('mobileSidebarToggle');
    const sidebarToggleBtn = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    function showSidebar() {
        if (sidebar) {
            sidebar.classList.remove('-translate-x-full');
        }
        if (sidebarOverlay) {
            sidebarOverlay.classList.remove('hidden');
        }
        document.body.style.overflow = 'hidden';
    }
    
    function hideSidebar() {
        if (sidebar) {
            sidebar.classList.add('-translate-x-full');
        }
        if (sidebarOverlay) {
            sidebarOverlay.classList.add('hidden');
        }
        document.body.style.overflow = 'auto';
    }
    
    if (mobileToggleBtn) mobileToggleBtn.addEventListener('click', showSidebar);
    if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', hideSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', hideSidebar);
    
    document.addEventListener('click', function(event) {
        if (window.innerWidth < 1024 && 
            sidebar && !sidebar.contains(event.target) && 
            mobileToggleBtn && !mobileToggleBtn.contains(event.target) && 
            !sidebar.classList.contains('-translate-x-full')) {
            hideSidebar();
        }
    });
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && window.innerWidth < 1024) {
            hideSidebar();
        }
    });
    
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            if (sidebar) sidebar.classList.remove('-translate-x-full');
            if (sidebarOverlay) sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    });
    
    if (window.innerWidth >= 1024 && sidebar) {
        sidebar.classList.remove('-translate-x-full');
    }
});

function toggleSidebar() {
    const sidebar = document.querySelector('aside');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar && sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        if (overlay) overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    } else if (sidebar) {
        sidebar.classList.add('-translate-x-full');
        if (overlay) overlay.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
}

function toggleFilters() {
    const filterSection = document.getElementById('filterSection');
    if (filterSection) {
        filterSection.classList.toggle('collapsed');
    }
}

function sortTable(column) {
    const currentUrl = new URL(window.location.href);
    const currentSort = currentUrl.searchParams.get('sort');
    const currentOrder = currentUrl.searchParams.get('order');
    
    let newOrder = 'DESC';
    if (currentSort === column) {
        newOrder = currentOrder === 'DESC' ? 'ASC' : 'DESC';
    }
    
    currentUrl.searchParams.set('sort', column);
    currentUrl.searchParams.set('order', newOrder);
    window.location.href = currentUrl.toString();
}

function resetFilters() {
    window.location.href = 'trainer_content.php';
}

function switchSource(source) {
    const fileSource = document.getElementById('fileSourceContent');
    const driveSource = document.getElementById('driveSourceContent');
    const tabFile = document.getElementById('tabFile');
    const tabDrive = document.getElementById('tabDrive');
    const contentSource = document.getElementById('contentSource');
    const fileInput = document.getElementById('file');
    const driveLink = document.getElementById('driveLink');
    
    if (source === 'file') {
        fileSource.classList.remove('hidden');
        fileSource.classList.add('block');
        driveSource.classList.add('hidden');
        tabFile.classList.add('active', 'bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
        tabFile.classList.remove('bg-white', 'text-gray-600', 'border-gray-200');
        tabDrive.classList.remove('active', 'bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
        tabDrive.classList.add('bg-white', 'text-gray-600', 'border-gray-200');
        contentSource.value = 'file';
        if (fileInput) fileInput.required = true;
        if (driveLink) driveLink.required = false;
    } else {
        fileSource.classList.add('hidden');
        driveSource.classList.remove('hidden');
        driveSource.classList.add('block');
        tabDrive.classList.add('active', 'bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
        tabDrive.classList.remove('bg-white', 'text-gray-600', 'border-gray-200');
        tabFile.classList.remove('active', 'bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
        tabFile.classList.add('bg-white', 'text-gray-600', 'border-gray-200');
        contentSource.value = 'drive';
        if (fileInput) fileInput.required = false;
        if (driveLink) driveLink.required = true;
    }
}
</script>

<?php include '../../footer.php'; ?>
</body>
</html>