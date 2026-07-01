<?php
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';
$workshop_id = $_POST['workshop_id'] ?? '';

// Verify workshop exists
$workshop_stmt = $db->prepare("SELECT * FROM workshops WHERE workshop_id = ?");
$workshop_stmt->execute([$workshop_id]);
$workshop = $workshop_stmt->fetch(PDO::FETCH_ASSOC);

if (!$workshop) {
    echo json_encode(['success' => false, 'message' => 'Workshop not found']);
    exit;
}

// Handle different actions
switch ($action) {
    case 'upload_template':
        handleTemplateUpload();
        break;
    case 'upload_certificate':
        handleCertificateUpload();
        break;
    case 'generate_certificates':
        handleBulkGeneration();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
}

function handleTemplateUpload() {
    global $db, $workshop_id;
    
    // Check if file was uploaded
    if (!isset($_FILES['template_file'])) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        exit;
    }
    
    $file = $_FILES['template_file'];
    
    // Validate file
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only PDF, JPG, and PNG are allowed.']);
        exit;
    }
    
    if ($file['size'] > $max_size) {
        echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
        exit;
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = '../uploads/certificate_templates/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'template_' . $workshop_id . '_' . time() . '.' . $extension;
    $target_path = $upload_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }
    
    // Save to database
    try {
        $stmt = $db->prepare("
            INSERT INTO certificate_templates 
            (workshop_id, template_path, created_by)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $workshop_id,
            $target_path,
            $_SESSION['user_id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Template uploaded successfully']);
    } catch (PDOException $e) {
        unlink($target_path); // Delete the file if DB insert fails
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleCertificateUpload() {
    global $db, $workshop_id;
    
    $student_id = $_POST['student_id'] ?? '';
    
    // Check if file was uploaded
    if (!isset($_FILES['certificate_file'])) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        exit;
    }
    
    // Validate student registration
    $reg_stmt = $db->prepare("
        SELECT * FROM workshop_registrations 
        WHERE workshop_id = ? AND student_id = ?
    ");
    $reg_stmt->execute([$workshop_id, $student_id]);
    $registration = $reg_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        echo json_encode(['success' => false, 'message' => 'Student not registered for this workshop']);
        exit;
    }
    
    $file = $_FILES['certificate_file'];
    
    // Validate file
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only PDF, JPG, and PNG are allowed.']);
        exit;
    }
    
    if ($file['size'] > $max_size) {
        echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
        exit;
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = '../uploads/certificates/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'certificate_' . $workshop_id . '_' . $student_id . '_' . time() . '.' . $extension;
    $target_path = $upload_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }
    
    // Update registration record
    try {
        $stmt = $db->prepare("
            UPDATE workshop_registrations 
            SET certificate_path = ?, 
                certificate_issued = 1,
                certificate_issued_date = NOW()
            WHERE workshop_id = ? AND student_id = ?
        ");
        $stmt->execute([
            $target_path,
            $workshop_id,
            $student_id
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Certificate uploaded successfully']);
    } catch (PDOException $e) {
        unlink($target_path); // Delete the file if DB update fails
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleBulkGeneration() {
    global $db, $workshop_id;
    
    $certificate_date = $_POST['certificate_date'] ?? date('Y-m-d');
    
    // Get template
    $template_stmt = $db->prepare("
        SELECT * FROM certificate_templates 
        WHERE workshop_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $template_stmt->execute([$workshop_id]);
    $template = $template_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        echo json_encode(['success' => false, 'message' => 'No certificate template found']);
        exit;
    }
    
    // Get students without certificates
    $students_stmt = $db->prepare("
        SELECT wr.*, s.first_name, s.last_name
        FROM workshop_registrations wr
        JOIN students s ON wr.student_id = s.student_id
        WHERE wr.workshop_id = ? AND wr.certificate_path IS NULL
    ");
    $students_stmt->execute([$workshop_id]);
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($students) === 0) {
        echo json_encode(['success' => false, 'message' => 'No students need certificates']);
        exit;
    }
    
    // Create certificates directory if it doesn't exist
    $upload_dir = '../uploads/certificates/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $success_count = 0;
    $error_messages = [];
    
    foreach ($students as $student) {
        try {
            // In a real implementation, you would generate the certificate here
            // For this example, we'll just create a placeholder
            
            $extension = pathinfo($template['template_path'], PATHINFO_EXTENSION);
            $filename = 'certificate_' . $workshop_id . '_' . $student['student_id'] . '_' . time() . '.' . $extension;
            $target_path = $upload_dir . $filename;
            
            // In a real system, you would generate the certificate file here
            // For now, we'll just copy the template as a placeholder
            if (!copy($template['template_path'], $target_path)) {
                throw new Exception('Failed to generate certificate file');
            }
            
            // Update registration record
            $stmt = $db->prepare("
                UPDATE workshop_registrations 
                SET certificate_path = ?, 
                    certificate_issued = 1,
                    certificate_issued_date = ?
                WHERE workshop_id = ? AND student_id = ?
            ");
            $stmt->execute([
                $target_path,
                $certificate_date,
                $workshop_id,
                $student['student_id']
            ]);
            
            $success_count++;
        } catch (Exception $e) {
            $error_messages[] = 'Student ' . $student['first_name'] . ' ' . $student['last_name'] . ': ' . $e->getMessage();
        }
    }
    
    if ($success_count === count($students)) {
        echo json_encode(['success' => true, 'message' => 'Successfully generated certificates for all ' . $success_count . ' students']);
    } else {
        $message = 'Generated certificates for ' . $success_count . ' of ' . count($students) . ' students.';
        if (!empty($error_messages)) {
            $message .= ' Errors: ' . implode('; ', $error_messages);
        }
        echo json_encode(['success' => false, 'message' => $message]);
    }
}