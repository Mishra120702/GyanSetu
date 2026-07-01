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
    
    // Validate inputs
    $errors = [];
    
    if (empty($title)) {
        $errors[] = 'Title is required';
    }
    
    if (empty($fileType)) {
        $errors[] = 'File type is required';
    }
    
    if (empty($batchIds)) {
        $errors[] = 'Please select at least one batch';
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
            
            // Update upload record with due_time
            $stmt = $db->prepare("UPDATE uploads SET 
                                 title = ?, 
                                 description = ?, 
                                 file_path = ?, 
                                 file_type = ?, 
                                 due_date = ?, 
                                 due_time = ?,
                                 max_marks = ?, 
                                 content_source = ?,
                                 uploaded_at = CURRENT_TIMESTAMP
                                 WHERE id = ?");
            $stmt->execute([$title, $description, $filePath, $fileType, $due_date, $due_time, $max_marks, $content_source, $content_id]);
            
            // Remove existing batch associations
            $stmt = $db->prepare("DELETE FROM batch_uploads WHERE upload_id = ?");
            $stmt->execute([$content_id]);
            
            // Insert new batch associations
            if (!empty($batchIds)) {
                $stmt = $db->prepare("INSERT INTO batch_uploads (upload_id, batch_id) VALUES (?, ?)");
                foreach ($batchIds as $batchId) {
                    $stmt->execute([$content_id, $batchId]);
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
            
            // Insert upload record with due_time
            $stmt = $db->prepare("INSERT INTO uploads (title, description, file_path, file_type, due_date, due_time, max_marks, uploaded_by, content_source) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $filePath, $fileType, $due_date, $due_time, $max_marks, $_SESSION['user_id'], $content_source]);
            $uploadId = $db->lastInsertId();
            
            // Insert batch associations
            if (!empty($batchIds)) {
                $stmt = $db->prepare("INSERT INTO batch_uploads (upload_id, batch_id) VALUES (?, ?)");
                foreach ($batchIds as $batchId) {
                    $stmt->execute([$uploadId, $batchId]);
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
        
        // Fetch associated batches
        $stmt = $db->prepare("SELECT batch_id FROM batch_uploads WHERE upload_id = ?");
        $stmt->execute([$edit_content_id]);
        $content_batches = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
}

// Get all batches for dropdown
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get content statistics
$statsStmt = $db->query("
    SELECT file_type, COUNT(*) as count 
    FROM uploads 
    GROUP BY file_type
");
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
        SELECT b.batch_id, b.batch_name 
        FROM batch_uploads bu
        JOIN batches b ON bu.batch_id = b.batch_id
        WHERE bu.upload_id = ?
    ");
    $stmt->execute([$upload['id']]);
    $upload['batches'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($upload);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $content_to_edit ? 'Edit Content' : 'Content Management' ?> - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.0.0/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.0.0/dist/js/tom-select.complete.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        :root {
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --primary-dark: #4338ca;
            --secondary: #10b981;
            --accent: #f59e0b;
            --danger: #ef4444;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .hover-scale {
            transition: transform 0.3s ease;
        }
        
        .hover-scale:hover {
            transform: scale(1.02);
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid rgba(79, 70, 229, 0.1);
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 30px -10px rgba(79, 70, 229, 0.2);
            border-color: var(--primary-light);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .badge-assignment {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .badge-notes {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .badge-lab-manual {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        .badge-other {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
        }
        
        .filter-tag {
            background: rgba(79, 70, 229, 0.1);
            border: 1px solid rgba(79, 70, 229, 0.2);
            border-radius: 9999px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            color: var(--primary);
            transition: all 0.2s ease;
        }
        
        .filter-tag:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .filter-tag i {
            margin-right: 0.5rem;
        }
        
        .action-btn {
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .action-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.4);
        }
        
        .action-btn-success {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white;
        }
        
        .action-btn-warning {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: white;
        }
        
        .action-btn-danger {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .table-row {
            transition: all 0.3s ease;
        }
        
        .table-row:hover {
            background: rgba(79, 70, 229, 0.05);
            transform: scale(1.01);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .pagination-btn {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 9999px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #4b5563;
            transition: all 0.3s ease;
        }
        
        .pagination-btn:hover:not(:disabled) {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.3);
        }
        
        .pagination-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .file-upload-area {
            border: 2px dashed #e5e7eb;
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
        }
        
        .file-upload-area:hover {
            border-color: var(--primary);
            background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%);
            transform: scale(1.02);
        }
        
        .file-upload-area.dragover {
            border-color: var(--primary);
            background: linear-gradient(135deg, #c7d2fe 0%, #ddd6fe 100%);
            transform: scale(1.05);
        }
        
        .source-tab {
            padding: 0.75rem 1.5rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            border: 1px solid #e5e7eb;
            color: #6b7280;
        }
        
        .source-tab:hover {
            background: #f3f4f6;
            transform: translateY(-2px);
        }
        
        .source-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.3);
        }
        
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
        
        .search-input {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 9999px;
            padding: 0.75rem 1.5rem 0.75rem 3rem;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        
        .filter-select {
            border: 1px solid #e5e7eb;
            border-radius: 9999px;
            padding: 0.75rem 1.5rem;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            appearance: none;
        }
        
        .filter-select:hover {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .sort-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .sort-link:hover {
            color: var(--primary);
        }
        
        .sort-link i {
            font-size: 0.75rem;
            opacity: 0.5;
        }
        
        .sort-link.active i {
            opacity: 1;
            color: var(--primary);
        }
        
        .time-input {
            border: 1px solid #e5e7eb;
            border-radius: 9999px;
            padding: 0.75rem 1.5rem;
            background: white;
            transition: all 0.3s ease;
        }
        
        .time-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
    </style>
</head>
<body class="font-['Inter']">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300">
        <!-- Header -->
        <header class="glass-card mx-4 md:mx-6 mt-4 px-6 py-4 flex justify-between items-center">
            <button class="md:hidden text-xl text-gray-600 hover:text-gray-900 transition-colors" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 flex items-center justify-center text-white">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <span><?= $content_to_edit ? 'Edit Content' : 'Content Management' ?></span>
            </h1>
            <?php if (!$content_to_edit): ?>
            <div class="flex items-center gap-3">
                <div class="bg-white px-4 py-2 rounded-full shadow-sm">
                    <span class="text-sm text-gray-600">Total: </span>
                    <span class="font-bold text-indigo-600"><?= $totalRecords ?></span>
                </div>
            </div>
            <?php endif; ?>
        </header>

        <div class="p-4 md:p-6 space-y-6">
            <?php if (!$content_to_edit): ?>
            <!-- Content Statistics -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 animate-slide-in">
                <div class="stat-card hover-scale">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Assignments</p>
                            <h3 class="text-3xl font-bold text-indigo-600"><?= $stats['Assignment'] ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-indigo-100 flex items-center justify-center">
                            <i class="fas fa-tasks text-2xl text-indigo-600"></i>
                        </div>
                    </div>
                    <div class="mt-3 h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full bg-indigo-600 rounded-full" style="width: <?= $totalRecords > 0 ? ($stats['Assignment'] / $totalRecords * 100) : 0 ?>%"></div>
                    </div>
                </div>
                
                <div class="stat-card hover-scale">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Notes</p>
                            <h3 class="text-3xl font-bold text-pink-600"><?= $stats['Notes'] ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-pink-100 flex items-center justify-center">
                            <i class="fas fa-book text-2xl text-pink-600"></i>
                        </div>
                    </div>
                    <div class="mt-3 h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full bg-pink-600 rounded-full" style="width: <?= $totalRecords > 0 ? ($stats['Notes'] / $totalRecords * 100) : 0 ?>%"></div>
                    </div>
                </div>
                
                <div class="stat-card hover-scale">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Lab Manuals</p>
                            <h3 class="text-3xl font-bold text-blue-600"><?= $stats['Lab Manual'] ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-flask text-2xl text-blue-600"></i>
                        </div>
                    </div>
                    <div class="mt-3 h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-600 rounded-full" style="width: <?= $totalRecords > 0 ? ($stats['Lab Manual'] / $totalRecords * 100) : 0 ?>%"></div>
                    </div>
                </div>
                
                <div class="stat-card hover-scale">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Other</p>
                            <h3 class="text-3xl font-bold text-green-600"><?= $stats['Other'] ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                            <i class="fas fa-file text-2xl text-green-600"></i>
                        </div>
                    </div>
                    <div class="mt-3 h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full bg-green-600 rounded-full" style="width: <?= $totalRecords > 0 ? ($stats['Other'] / $totalRecords * 100) : 0 ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Search and Filters -->
            <div class="glass-card p-6 animate-slide-in" style="animation-delay: 0.1s">
                <form method="GET" action="" id="filterForm">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <!-- Search -->
                        <div class="md:col-span-2 relative">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" 
                                   placeholder="Search by title, description, or uploader..." 
                                   class="search-input">
                        </div>
                        
                        <!-- File Type Filter -->
                        <div class="relative">
                            <select name="type" class="filter-select w-full" onchange="this.form.submit()">
                                <option value="all">All Types</option>
                                <option value="Assignment" <?= $fileTypeFilter === 'Assignment' ? 'selected' : '' ?>>Assignments</option>
                                <option value="Notes" <?= $fileTypeFilter === 'Notes' ? 'selected' : '' ?>>Notes</option>
                                <option value="Lab Manual" <?= $fileTypeFilter === 'Lab Manual' ? 'selected' : '' ?>>Lab Manuals</option>
                                <option value="Test" <?= $fileTypeFilter === 'Test' ? 'selected' : '' ?>>Tests</option>
                                <option value="Other" <?= $fileTypeFilter === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                            <i class="fas fa-chevron-down absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                        </div>
                        
                        <!-- Batch Filter -->
                        <div class="relative">
                            <select name="batch" class="filter-select w-full" onchange="this.form.submit()">
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
                                   class="filter-select w-full" onchange="this.form.submit()">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" 
                                   class="filter-select w-full" onchange="this.form.submit()">
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="flex items-end gap-2">
                            <button type="submit" class="action-btn action-btn-primary flex-1">
                                <i class="fas fa-filter"></i>
                                Apply Filters
                            </button>
                            <a href="upload_content.php" class="action-btn bg-gray-100 text-gray-600 hover:bg-gray-200 flex-1 text-center">
                                <i class="fas fa-redo-alt"></i>
                                Reset
                            </a>
                        </div>
                    </div>

                    <!-- Hidden fields to maintain sort when filtering -->
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
                            <a href="<?= removeFilterParam('search') ?>" class="ml-2 hover:text-white">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($fileTypeFilter) && $fileTypeFilter !== 'all'): ?>
                        <span class="filter-tag">
                            <i class="fas fa-tag"></i>
                            <?= htmlspecialchars($fileTypeFilter) ?>
                            <a href="<?= removeFilterParam('type') ?>" class="ml-2 hover:text-white">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($batchFilter) && $batchFilter !== 'all'): ?>
                        <span class="filter-tag">
                            <i class="fas fa-users"></i>
                            Batch: <?= htmlspecialchars($batchFilter) ?>
                            <a href="<?= removeFilterParam('batch') ?>" class="ml-2 hover:text-white">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($dateFrom)): ?>
                        <span class="filter-tag">
                            <i class="fas fa-calendar-alt"></i>
                            From: <?= htmlspecialchars($dateFrom) ?>
                            <a href="<?= removeFilterParam('date_from') ?>" class="ml-2 hover:text-white">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($dateTo)): ?>
                        <span class="filter-tag">
                            <i class="fas fa-calendar-alt"></i>
                            To: <?= htmlspecialchars($dateTo) ?>
                            <a href="<?= removeFilterParam('date_to') ?>" class="ml-2 hover:text-white">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Upload/Edit Form -->
            <div class="glass-card p-6 animate-slide-in" <?= $content_to_edit ? '' : 'style="animation-delay: 0.2s"' ?>>
                <?php if ($content_to_edit): ?>
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-gray-200">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 flex items-center justify-center text-white text-xl">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800">Edit Content</h2>
                        <p class="text-sm text-gray-500">Last updated: <?= date('M j, Y g:i A', strtotime($content_to_edit['uploaded_at'])) ?></p>
                    </div>
                </div>
                <?php else: ?>
                <h2 class="text-xl font-semibold text-gray-800 mb-6 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 flex items-center justify-center text-white">
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
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
                                   placeholder="Enter content title"
                                   value="<?= $content_to_edit ? htmlspecialchars($content_to_edit['title']) : '' ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-tag text-indigo-500 mr-2"></i>
                                Content Type <span class="text-red-500">*</span>
                            </label>
                            <select name="file_type" required id="fileTypeSelect"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all">
                                <option value="">Select a type</option>
                                <option value="Assignment" <?= $content_to_edit && $content_to_edit['file_type'] === 'Assignment' ? 'selected' : '' ?>>Assignment</option>
                                <option value="Notes" <?= $content_to_edit && $content_to_edit['file_type'] === 'Notes' ? 'selected' : '' ?>>Notes</option>
                                <option value="Lab Manual" <?= $content_to_edit && $content_to_edit['file_type'] === 'Lab Manual' ? 'selected' : '' ?>>Lab Manual</option>
                                <option value="Test" <?= $content_to_edit && $content_to_edit['file_type'] === 'Test' ? 'selected' : '' ?>>Test</option>
                                <option value="Other" <?= $content_to_edit && $content_to_edit['file_type'] === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        
                        <div id="dueDateField" class="<?= ($content_to_edit && $content_to_edit['file_type'] === 'Assignment') ? '' : 'hidden' ?>">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-alt text-indigo-500 mr-2"></i>
                                Due Date (IST)
                            </label>
                            <input type="date" name="due_date" id="dueDate"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
                                   min="<?= date('Y-m-d') ?>"
                                   value="<?= $content_to_edit && $content_to_edit['due_date'] ? date('Y-m-d', strtotime($content_to_edit['due_date'])) : '' ?>">
                        </div>
                        
                        <div id="dueTimeField" class="<?= ($content_to_edit && $content_to_edit['file_type'] === 'Assignment') ? '' : 'hidden' ?>">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-clock text-indigo-500 mr-2"></i>
                                Due Time (IST)
                            </label>
                            <input type="time" name="due_time" id="dueTime"
                                   class="w-full time-input"
                                   value="<?= $content_to_edit && $content_to_edit['due_time'] ? date('H:i', strtotime($content_to_edit['due_time'])) : '' ?>">
                            <p class="text-xs text-gray-500 mt-1">Leave empty for midnight (11:59 PM IST)</p>
                        </div>
                        
                        <div id="maxMarksField" class="<?= ($content_to_edit && $content_to_edit['file_type'] === 'Assignment') ? '' : 'hidden' ?>">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-star text-indigo-500 mr-2"></i>
                                Maximum Marks
                            </label>
                            <input type="number" name="max_marks" step="0.01" min="0" max="1000" id="maxMarks"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
                                   value="<?= $content_to_edit ? htmlspecialchars($content_to_edit['max_marks']) : '100' ?>">
                            <p class="text-xs text-gray-500 mt-1">For assignments only</p>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-align-left text-indigo-500 mr-2"></i>
                                Description
                            </label>
                            <textarea name="description" rows="3"
                                      class="w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
                                      placeholder="Enter a brief description (optional)"><?= $content_to_edit ? htmlspecialchars($content_to_edit['description']) : '' ?></textarea>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-users text-indigo-500 mr-2"></i>
                                Associated Batch(es) <span class="text-red-500">*</span>
                            </label>
                            <select id="batch_ids" name="batch_ids[]" multiple required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all">
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>"
                                        <?= $content_to_edit && in_array($batch['batch_id'], $content_batches) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                <i class="fas fa-cloud text-indigo-500 mr-2"></i>
                                Content Source <span class="text-red-500">*</span>
                            </label>
                            
                            <div class="flex gap-2 mb-4">
                                <div class="source-tab <?= (!$content_to_edit || $content_to_edit['content_source'] === 'file') ? 'active' : '' ?>" onclick="switchSource('file')">
                                    <i class="fas fa-file-upload mr-2"></i> File Upload
                                </div>
                                <div class="source-tab <?= ($content_to_edit && $content_to_edit['content_source'] === 'drive') ? 'active' : '' ?>" onclick="switchSource('drive')">
                                    <i class="fab fa-google-drive mr-2"></i> Google Drive Link
                                </div>
                            </div>
                            
                            <!-- File Upload Content -->
                            <div id="fileSourceContent" class="source-content <?= (!$content_to_edit || $content_to_edit['content_source'] === 'file') ? 'block' : 'hidden' ?>">
                                <?php if ($content_to_edit && $content_to_edit['content_source'] === 'file'): ?>
                                <div class="mb-4 p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl border border-indigo-200">
                                    <p class="text-sm font-medium text-gray-700 mb-2">Current File:</p>
                                    <div class="flex items-center gap-3">
                                        <i class="fas fa-file-pdf text-2xl text-indigo-600"></i>
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
                                
                                <div id="fileDropArea" class="file-upload-area cursor-pointer">
                                    <input type="file" id="file" name="file" class="hidden"
                                           accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                                    <i class="fas fa-cloud-upload-alt text-5xl text-indigo-400 mb-3"></i>
                                    <p class="text-gray-600 mb-1">Drag & drop your file here or click to browse</p>
                                    <p class="text-xs text-gray-500">Supports: PDF, DOC, DOCX (Max 10MB)</p>
                                    <div id="fileNameDisplay" class="mt-3 text-sm font-medium text-indigo-600 hidden"></div>
                                </div>
                            </div>
                            
                            <!-- Google Drive Content -->
                            <div id="driveSourceContent" class="source-content <?= ($content_to_edit && $content_to_edit['content_source'] === 'drive') ? 'block' : 'hidden' ?>">
                                <div class="p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-200">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fab fa-google-drive text-blue-500 mr-2"></i>
                                        Google Drive Link <span class="text-red-500">*</span>
                                    </label>
                                    <input type="url" name="drive_link" id="driveLink"
                                           class="w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
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
                    
                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                        <?php if ($content_to_edit): ?>
                            <a href="upload_content.php" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all flex items-center gap-2">
                                <i class="fas fa-times"></i>
                                Cancel
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="px-6 py-3 bg-gradient-to-r from-indigo-500 to-purple-500 text-white rounded-xl hover:shadow-lg hover:scale-105 transition-all flex items-center gap-2">
                            <i class="fas <?= $content_to_edit ? 'fa-save' : 'fa-upload' ?>"></i>
                            <?= $content_to_edit ? 'Update Content' : 'Upload Content' ?>
                        </button>
                    </div>
                </form>
            </div>

            <?php if (!$content_to_edit): ?>
            <!-- Uploaded Content Table -->
            <div class="glass-card p-6 animate-slide-in" style="animation-delay: 0.3s">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold text-gray-800 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 flex items-center justify-center text-white">
                            <i class="fas fa-folder-open"></i>
                        </div>
                        <span>Uploaded Content</span>
                    </h2>
                    <div class="text-sm text-gray-500">
                        Showing <span class="font-bold text-indigo-600"><?= ($page - 1) * $limit + 1 ?></span> to 
                        <span class="font-bold text-indigo-600"><?= min($page * $limit, $totalRecords) ?></span> of 
                        <span class="font-bold text-indigo-600"><?= $totalRecords ?></span> items
                    </div>
                </div>
                
                <?php if (empty($uploads)): ?>
                <div class="text-center py-12">
                    <div class="w-24 h-24 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
                        <i class="fas fa-box-open text-4xl text-gray-400"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">No content found</h3>
                    <p class="text-gray-500 mb-4">Try adjusting your filters or upload new content</p>
                    <a href="upload_content.php" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-indigo-500 to-purple-500 text-white rounded-xl hover:shadow-lg transition-all">
                        <i class="fas fa-redo-alt"></i>
                        Clear Filters
                    </a>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto rounded-xl">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gradient-to-r from-indigo-50 to-purple-50">
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="<?= getSortUrl('title') ?>" class="sort-link <?= $sortColumn === 'title' ? 'active' : '' ?>">
                                        Title
                                        <?php if ($sortColumn === 'title'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="<?= getSortUrl('file_type') ?>" class="sort-link <?= $sortColumn === 'file_type' ? 'active' : '' ?>">
                                        Type
                                        <?php if ($sortColumn === 'file_type'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Batches
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="<?= getSortUrl('due_date') ?>" class="sort-link <?= $sortColumn === 'due_date' ? 'active' : '' ?>">
                                        Due Date & Time (IST)
                                        <?php if ($sortColumn === 'due_date'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="<?= getSortUrl('uploaded_by_name') ?>" class="sort-link <?= $sortColumn === 'uploaded_by_name' ? 'active' : '' ?>">
                                        Uploaded By
                                        <?php if ($sortColumn === 'uploaded_by_name'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="<?= getSortUrl('uploaded_at') ?>" class="sort-link <?= $sortColumn === 'uploaded_at' ? 'active' : '' ?>">
                                        Date
                                        <?php if ($sortColumn === 'uploaded_at'): ?>
                                            <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?>"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($uploads as $index => $upload): ?>
                            <?php
                            // Calculate time remaining in IST
                            $timeRemaining = '';
                            $dueClass = '';
                            $dueIcon = '';
                            
                            if ($upload['due_date']) {
                                $due_datetime = new DateTime($upload['due_date'], new DateTimeZone('Asia/Kolkata'));
                                
                                // Set time if available, otherwise default to 23:59:59
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
                            ?>
                            <tr class="table-row hover:bg-gray-50 transition-all" style="animation: slideIn 0.3s ease-out <?= $index * 0.05 ?>s both;">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <?php
                                        $icon = '';
                                        switch($upload['file_type']) {
                                            case 'Assignment':
                                                $icon = 'fa-tasks';
                                                break;
                                            case 'Notes':
                                                $icon = 'fa-book';
                                                break;
                                            case 'Lab Manual':
                                                $icon = 'fa-flask';
                                                break;
                                            case 'Test':
                                                $icon = 'fa-pencil-alt';
                                                break;
                                            default:
                                                $icon = 'fa-file';
                                        }
                                        ?>
                                        <div class="w-10 h-10 rounded-lg bg-gradient-to-r from-indigo-500 to-purple-500 flex items-center justify-center text-white mr-3">
                                            <i class="fas <?= $icon ?>"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 flex items-center gap-2">
                                                <?= htmlspecialchars($upload['title']) ?>
                                                <?php if ($upload['content_source'] === 'drive'): ?>
                                                    <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-600">
                                                        <i class="fab fa-google-drive mr-1"></i> Drive
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 py-1 text-xs rounded-full bg-purple-100 text-purple-600">
                                                        <i class="fas fa-file mr-1"></i> File
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
                                <td class="px-6 py-4">
                                    <?php
                                    $badgeClass = '';
                                    switch($upload['file_type']) {
                                        case 'Assignment':
                                            $badgeClass = 'badge-assignment';
                                            break;
                                        case 'Notes':
                                            $badgeClass = 'badge-notes';
                                            break;
                                        case 'Lab Manual':
                                            $badgeClass = 'badge-lab-manual';
                                            break;
                                        default:
                                            $badgeClass = 'badge-other';
                                    }
                                    ?>
                                    <span class="badge <?= $badgeClass ?>">
                                        <i class="fas <?= $icon ?> mr-2"></i>
                                        <?= htmlspecialchars($upload['file_type']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1 max-w-xs">
                                        <?php foreach ($upload['batches'] as $batch): ?>
                                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-600">
                                                <?= htmlspecialchars($batch['batch_id']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
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
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center mr-2">
                                            <i class="fas fa-user text-indigo-600 text-sm"></i>
                                        </div>
                                        <span class="text-sm text-gray-700"><?= htmlspecialchars($upload['uploaded_by_name']) ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <i class="far fa-calendar-alt mr-2 text-indigo-400"></i>
                                    <?= date('M j, Y', strtotime($upload['uploaded_at'])) ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex gap-2">
                                        <?php if ($upload['content_source'] === 'drive'): ?>
                                            <a href="<?= htmlspecialchars($upload['file_path']) ?>" target="_blank"
                                               class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 hover:bg-indigo-200 transition-all flex items-center justify-center"
                                               title="Open Drive Link">
                                                <i class="fab fa-google-drive"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="../<?= htmlspecialchars($upload['file_path']) ?>" target="_blank"
                                               class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 hover:bg-blue-200 transition-all flex items-center justify-center"
                                               title="View File">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="../<?= htmlspecialchars($upload['file_path']) ?>" download
                                               class="w-10 h-10 rounded-lg bg-green-100 text-green-600 hover:bg-green-200 transition-all flex items-center justify-center"
                                               title="Download">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($upload['file_type'] === 'Assignment'): ?>
                                            <a href="view_submissions.php?id=<?= $upload['id'] ?>" 
                                               class="w-10 h-10 rounded-lg bg-purple-100 text-purple-600 hover:bg-purple-200 transition-all flex items-center justify-center"
                                               title="View Submissions">
                                                <i class="fas fa-users"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="upload_content.php?edit=<?= $upload['id'] ?>" 
                                           class="w-10 h-10 rounded-lg bg-yellow-100 text-yellow-600 hover:bg-yellow-200 transition-all flex items-center justify-center"
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <button onclick="deleteContent(<?= $upload['id'] ?>)"
                                                class="w-10 h-10 rounded-lg bg-red-100 text-red-600 hover:bg-red-200 transition-all flex items-center justify-center"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    追赶
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="flex justify-between items-center mt-6">
                    <div class="text-sm text-gray-500">
                        Page <?= $page ?> of <?= $totalPages ?>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?= getPageUrl($page - 1) ?>" 
                               class="pagination-btn">
                                <i class="fas fa-chevron-left mr-1"></i>
                                Previous
                            </a>
                        <?php else: ?>
                            <button class="pagination-btn opacity-50 cursor-not-allowed" disabled>
                                <i class="fas fa-chevron-left mr-1"></i>
                                Previous
                            </button>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($p = $startPage; $p <= $endPage; $p++):
                        ?>
                            <a href="<?= getPageUrl($p) ?>" 
                               class="pagination-btn <?= $p == $page ? 'active' : '' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= getPageUrl($page + 1) ?>" 
                               class="pagination-btn">
                                Next
                                <i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        <?php else: ?>
                            <button class="pagination-btn opacity-50 cursor-not-allowed" disabled>
                                Next
                                <i class="fas fa-chevron-right ml-1"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Helper function to get query parameters
    function getQueryParams() {
        const params = {};
        const queryString = window.location.search;
        const urlParams = new URLSearchParams(queryString);
        for (const [key, value] of urlParams) {
            params[key] = value;
        }
        return params;
    }

    // Helper function to build URL with current filters
    function buildUrlWithParams(newParams = {}) {
        const params = getQueryParams();
        Object.assign(params, newParams);
        
        // Remove page if it's 1
        if (params.page == 1) {
            delete params.page;
        }
        
        // Remove empty params
        Object.keys(params).forEach(key => {
            if (params[key] === '' || params[key] === null || params[key] === 'all') {
                delete params[key];
            }
        });
        
        const queryString = new URLSearchParams(params).toString();
        return queryString ? '?' + queryString : window.location.pathname;
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize multi-select dropdown
        if (document.getElementById('batch_ids')) {
            new TomSelect('#batch_ids', {
                plugins: ['remove_button'],
                create: false,
                maxItems: null,
                placeholder: 'Select batch(es)',
                render: {
                    option: function(data, escape) {
                        return '<div class="flex items-center py-2 px-3 hover:bg-indigo-50">' +
                               '<span class="inline-block w-2 h-2 rounded-full bg-indigo-500 mr-2"></span>' +
                               escape(data.text) +
                               '</div>';
                    }
                }
            });
        }

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
                    fileDropArea.classList.add('border-indigo-500', 'bg-indigo-50');
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
                    fileDropArea.classList.add('border-indigo-500', 'bg-indigo-50');
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

                if (!isAssignment) {
                    if (dueDateInput) dueDateInput.value = '';
                    if (dueTimeInput) dueTimeInput.value = '';
                    if (maxMarksInput) maxMarksInput.value = '100';
                }
            }
        }

        if (fileTypeSelect) {
            fileTypeSelect.addEventListener('change', toggleAssignmentFields);
            toggleAssignmentFields();
        }

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
                const title = document.querySelector('input[name="title"]').value.trim();
                const fileType = document.querySelector('select[name="file_type"]').value;
                const batchIds = document.getElementById('batch_ids').value;
                const contentSource = document.getElementById('content_source').value;
                const driveLink = document.getElementById('driveLink')?.value || '';
                const action = document.querySelector('input[name="action"]').value;
                const isEdit = action === 'edit';

                if (!title || !fileType || !batchIds) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: 'Please fill all required fields',
                        background: 'white',
                        confirmButtonColor: '#4f46e5',
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
                            confirmButtonColor: '#4f46e5',
                            iconColor: '#ef4444'
                        });
                        return;
                    }

                    // Create IST datetime for validation
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
                            confirmButtonColor: '#4f46e5',
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
                            confirmButtonColor: '#4f46e5',
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
                            confirmButtonColor: '#4f46e5',
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
                            confirmButtonColor: '#4f46e5',
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
                            confirmButtonColor: '#4f46e5',
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

                fetch('upload_content.php', {
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
                            confirmButtonColor: '#4f46e5',
                            iconColor: '#10b981',
                            showClass: {
                                popup: 'animate__animated animate__fadeInDown'
                            },
                            hideClass: {
                                popup: 'animate__animated animate__fadeOutUp'
                            }
                        }).then(() => {
                            window.location.href = 'upload_content.php';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message,
                            background: 'white',
                            confirmButtonColor: '#4f46e5',
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
                        confirmButtonColor: '#4f46e5',
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

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.classList.toggle('-translate-x-full');
        }
        document.body.classList.toggle('overflow-hidden');
    }

    function switchSource(source) {
        // Update hidden input
        const sourceInput = document.getElementById('content_source');
        if (sourceInput) sourceInput.value = source;

        // Update tab styles
        document.querySelectorAll('.source-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        const tabs = document.querySelectorAll('.source-tab');
        if (source === 'file') {
            tabs[0].classList.add('active');
        } else {
            tabs[1].classList.add('active');
        }

        // Show/hide content sections
        const fileSource = document.getElementById('fileSourceContent');
        const driveSource = document.getElementById('driveSourceContent');
        
        if (fileSource) fileSource.classList.toggle('hidden', source !== 'file');
        if (driveSource) driveSource.classList.toggle('hidden', source !== 'drive');
        
        // Update required attributes
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
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#4f46e5',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            background: 'white',
            iconColor: '#f59e0b'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
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

                // Send delete request to server
                const formData = new FormData();
                formData.append('delete_id', id);

                fetch('upload_content.php', {
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
                            confirmButtonColor: '#4f46e5',
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
                            confirmButtonColor: '#4f46e5',
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
                        confirmButtonColor: '#4f46e5',
                        iconColor: '#ef4444'
                    });
                    console.error('Error:', error);
                });
            }
        });
    }
    </script>

    <?php 
    // Helper function to build query strings for pagination
    function buildQueryString($newParams = []) {
        $params = $_GET;
        
        foreach ($newParams as $key => $value) {
            $params[$key] = $value;
        }
        
        // Remove page if it's 1 (cleaner URL)
        if (isset($params['page']) && $params['page'] == 1) {
            unset($params['page']);
        }
        
        // Remove empty parameters
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                unset($params[$key]);
            }
        }
        
        return http_build_query($params);
    }

    // Helper function to get URL for sorting
    function getSortUrl($column) {
        $params = $_GET;
        $params['sort'] = $column;
        $params['order'] = (isset($_GET['sort']) && $_GET['sort'] === $column && isset($_GET['order']) && $_GET['order'] === 'ASC') ? 'DESC' : 'ASC';
        $params['page'] = 1; // Reset to first page when sorting
        
        return '?' . http_build_query($params);
    }

    // Helper function to get URL for pagination
    function getPageUrl($page) {
        $params = $_GET;
        $params['page'] = $page;
        return '?' . http_build_query($params);
    }

    // Helper function to remove a filter parameter
    function removeFilterParam($param) {
        $params = $_GET;
        unset($params[$param]);
        unset($params['page']); // Reset to first page
        
        return '?' . http_build_query($params);
    }
    ?>

    <?php include '../footer.php'; ?>
</body>
</html>