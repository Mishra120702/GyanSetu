<?php
// upload.php - Handles file uploads for trainer content
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

require_once '../db_connection.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get trainer details
    $trainer_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT t.* FROM trainers t WHERE t.user_id = ?");
    $stmt->execute([$trainer_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($trainer === false) {
        echo json_encode(['success' => false, 'message' => 'Trainer not found']);
        exit;
    }

    // Handle file upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate required fields
        if (!isset($_POST['title']) || empty($_POST['title'])) {
            echo json_encode(['success' => false, 'message' => 'Title is required']);
            exit;
        }
        
        if (!isset($_POST['file_type']) || empty($_POST['file_type'])) {
            echo json_encode(['success' => false, 'message' => 'File type is required']);
            exit;
        }
        
        if (!isset($_POST['batch_ids']) || empty($_POST['batch_ids'])) {
            echo json_encode(['success' => false, 'message' => 'Please select at least one batch']);
            exit;
        }
        
        $title = $_POST['title'];
        $description = $_POST['description'] ?? '';
        $fileType = $_POST['file_type'];
        $batchIds = is_array($_POST['batch_ids']) ? $_POST['batch_ids'] : [$_POST['batch_ids']];
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $max_marks = !empty($_POST['max_marks']) ? floatval($_POST['max_marks']) : 100.00;
        
        // Validate due date if provided
        if ($due_date) {
            $current_date = new DateTime();
            $due_date_obj = new DateTime($due_date);
            if ($due_date_obj < $current_date) {
                echo json_encode(['success' => false, 'message' => 'Due date cannot be in the past']);
                exit;
            }
        }
        
        // Validate max marks
        if ($max_marks <= 0 || $max_marks > 1000) {
            echo json_encode(['success' => false, 'message' => 'Maximum marks must be between 0 and 1000']);
            exit;
        }
        
        // Verify trainer has access to selected batches
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $batchCheckStmt = $db->prepare("
            SELECT COUNT(*) FROM batches 
            WHERE batch_mentor_id = ? AND batch_id IN ($placeholders)
        ");
        $batchCheckParams = array_merge([$trainer['id']], $batchIds);
        $batchCheckStmt->execute($batchCheckParams);
        $batchCount = $batchCheckStmt->fetchColumn();
        
        if ($batchCount != count($batchIds)) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to upload content for one or more selected batches']);
            exit;
        }
        
        // Handle file upload
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            // Validate file type
            $allowedTypes = [
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
            ];
            
            $fileMimeType = $_FILES['file']['type'];
            $fileSize = $_FILES['file']['size'];
            
            if (!array_key_exists($fileMimeType, $allowedTypes)) {
                echo json_encode(['success' => false, 'message' => 'Only PDF and DOCX files are allowed']);
                exit;
            }
            
            // Validate file size (max 10MB)
            if ($fileSize > 10 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'File size exceeds 10MB limit']);
                exit;
            }
            
            // Create upload directory if it doesn't exist
            $uploadDir = '../uploads/content/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $originalName = basename($_FILES['file']['name']);
            $fileExtension = pathinfo($originalName, PATHINFO_EXTENSION);
            $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $filePath = $uploadDir . $fileName;
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                try {
                    $db->beginTransaction();
                    
                    // Insert upload record
                    $stmt = $db->prepare("INSERT INTO uploads (title, description, file_path, file_type, due_date, max_marks, uploaded_by) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$title, $description, $filePath, $fileType, $due_date, $max_marks, $trainer_id]);
                    $uploadId = $db->lastInsertId();
                    
                    // Insert batch associations
                    $stmt = $db->prepare("INSERT INTO batch_uploads (upload_id, batch_id) VALUES (?, ?)");
                    foreach ($batchIds as $batchId) {
                        $stmt->execute([$uploadId, $batchId]);
                    }
                    
                    $db->commit();
                    
                    // Log the upload
                    error_log("Content uploaded: ID=$uploadId, Title='$title', Type='$fileType' by Trainer ID=$trainer_id");
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'File uploaded successfully',
                        'upload_id' => $uploadId
                    ]);
                    exit;
                    
                } catch (PDOException $e) {
                    $db->rollBack();
                    // Delete the uploaded file if DB operation failed
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    error_log("Database error during upload: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File upload failed']);
                exit;
            }
        } else {
            $uploadError = $_FILES['file']['error'] ?? 'No file uploaded';
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
            ];
            
            $message = $errorMessages[$uploadError] ?? "File upload error: $uploadError";
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit;
    }
    
} catch(PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}
?>