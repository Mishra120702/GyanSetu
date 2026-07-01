<?php
require_once '../db_connection.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$category = $_POST['category'] ?? '';
$target_type = $_POST['target_type'] ?? '';
$title = trim($_POST['title'] ?? '');
$message = trim($_POST['message'] ?? '');

// Handle target ID based on type
$target_id = $_POST['target_id'] ?? null;

// Validation
if (empty($category) || empty($target_type) || empty($title) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

if (!in_array($target_type, ['all', 'trainers', 'students']) && empty($target_id)) {
    echo json_encode(['success' => false, 'message' => 'Target ID is required for selected target type']);
    exit;
}

// Handle Image Upload
$image_path = null;
if (isset($_FILES['image']) && $_FILES['image']['size'] > 0) {
    if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/notifications/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_info = pathinfo($_FILES['image']['name']);
        $ext = strtolower($file_info['extension']);
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        
        if (in_array($ext, $allowed_exts)) {
            $new_filename = uniqid('notif_') . '.' . $ext;
            $dest_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $dest_path)) {
                $image_path = 'uploads/notifications/' . $new_filename; // Relative path for DB
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to move uploaded image. Check directory permissions.']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid image format. Allowed: JPG, PNG, GIF, SVG, WEBP']);
            exit;
        }
    } else {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
        ];
        $error_code = $_FILES['image']['error'];
        $message = $error_messages[$error_code] ?? 'Unknown upload error.';
        echo json_encode(['success' => false, 'message' => 'Image upload error: ' . $message]);
        exit;
    }
}

try {
    // Insert into admin_notifications for students
    if (in_array($target_type, ['all', 'batch', 'course', 'students', 'student'])) {
        $stmt = $db->prepare("
            INSERT INTO admin_notifications (title, message, category, target_type, target_id, image_path) 
            VALUES (:title, :message, :category, :target_type, :target_id, :image_path)
        ");
        
        $stmt->execute([
            ':title' => $title,
            ':message' => $message,
            ':category' => $category,
            ':target_type' => $target_type,
            ':target_id' => $target_id,
            ':image_path' => $image_path
        ]);
    }

    // Insert into notifications for trainers
    if (in_array($target_type, ['all', 'trainers', 'trainer'])) {
        if ($target_type === 'trainer') {
            $t_stmt = $db->prepare("SELECT user_id FROM trainers WHERE id = ? AND user_id IS NOT NULL");
            $t_stmt->execute([$target_id]);
            $trainers = $t_stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $t_stmt = $db->query("SELECT user_id FROM trainers WHERE is_active = 1 AND user_id IS NOT NULL");
            $trainers = $t_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        if (!empty($trainers)) {
            $insert_n = $db->prepare("INSERT INTO notifications (user_id, type, title, message, is_read) VALUES (?, 'admin_notif', ?, ?, 0)");
            foreach ($trainers as $uid) {
                $insert_n->execute([$uid, "[$category] $title", $message]);
            }
        }
    }
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
