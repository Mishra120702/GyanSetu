<?php
include '../db_connection.php';
session_start();

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST requests for deletion
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get the content ID from POST
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid content ID']);
    exit;
}

try {
    // Start transaction
    $db->beginTransaction();
    
    // First, check if content exists and get its details
    $stmt = $db->prepare("
        SELECT u.*, u.file_path, u.content_source, u.uploaded_by, u.file_type
        FROM uploads u 
        WHERE u.id = ?
    ");
    $stmt->execute([$id]);
    $upload = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$upload) {
        echo json_encode(['success' => false, 'message' => 'Content not found']);
        exit;
    }
    
    // Check if user is authorized to delete this content
    $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    $isOwner = $upload['uploaded_by'] == $_SESSION['user_id'];
    
    if (!$isAdmin && !$isOwner) {
        echo json_encode(['success' => false, 'message' => 'You are not authorized to delete this content']);
        exit;
    }
    
    // Check if there are any submissions for this content (for assignments)
    if ($upload['file_type'] === 'Assignment') {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignment_submissions WHERE upload_id = ?");
        $stmt->execute([$id]);
        $submissionCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($submissionCount > 0) {
            // Option 1: Delete submissions first (with file cleanup)
            // Get all submission files to delete
            $stmt = $db->prepare("SELECT file_path FROM assignment_submissions WHERE upload_id = ?");
            $stmt->execute([$id]);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Delete submission files
            foreach ($submissions as $submission) {
                if (!empty($submission['file_path'])) {
                    $fileToDelete = '../' . $submission['file_path'];
                    if (file_exists($fileToDelete)) {
                        unlink($fileToDelete);
                    }
                }
            }
            
            // Delete all submissions from database
            $stmt = $db->prepare("DELETE FROM assignment_submissions WHERE upload_id = ?");
            $stmt->execute([$id]);
        }
    }
    
    // Delete batch associations
    $stmt = $db->prepare("DELETE FROM batch_uploads WHERE upload_id = ?");
    $stmt->execute([$id]);
    
    // Delete the actual file if it's a local file upload (not Google Drive link)
    if ($upload['content_source'] === 'file' && !empty($upload['file_path'])) {
        // Construct the full file path (relative to the script location)
        $fullFilePath = '../' . $upload['file_path'];
        
        // Check if file exists before attempting to delete
        if (file_exists($fullFilePath)) {
            if (!unlink($fullFilePath)) {
                // Log error but continue with database deletion
                error_log("Failed to delete file: " . $fullFilePath);
            }
        } else {
            // File doesn't exist, log for reference
            error_log("File not found for deletion: " . $fullFilePath);
        }
    }
    
    // Finally, delete the upload record
    $stmt = $db->prepare("DELETE FROM uploads WHERE id = ?");
    $stmt->execute([$id]);
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Content deleted successfully'
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $db->rollBack();
    error_log("Delete content error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollBack();
    error_log("Delete content error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>