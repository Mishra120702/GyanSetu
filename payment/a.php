<?php
// a.php - Transaction Upload Form with Auto-fill
include '../db_connection.php';

// Start session and check for payment verification data
session_start();

// Check for accounts lock parameter
$is_accounts_lock = isset($_GET['accounts_lock']) && $_GET['accounts_lock'] == 1;
$accounts_lock_message = '';

// Check if we have payment verification data or accounts lock data
if (!isset($_SESSION['payment_verification_data']) && !isset($_GET['student_id']) && !isset($_SESSION['accounts_lock_data'])) {
    // If no session data and no student_id parameter, redirect to login
    header("Location: ../login.php");
    exit;
}

// Check if payment verification is required
$requires_payment = isset($_SESSION['payment_verification_data']['requires_payment']) 
    && $_SESSION['payment_verification_data']['requires_payment'] === true;

// Get student data either from session or URL parameter
$student_id = '';
$batch_id = '';
$student_name = '';
$batch_name = '';
$lock_reason = '';

// Check for accounts lock first
if (isset($_SESSION['accounts_lock_data'])) {
    $student_id = $_SESSION['accounts_lock_data']['student_id'] ?? '';
    $lock_reason = $_SESSION['accounts_lock_data']['lock_reason'] ?? 'Account locked by accounts team';
    $is_accounts_lock = true;
    
    // Get student info
    $stmt = $db->prepare("SELECT 
        s.*, 
        b.batch_id as actual_batch_id, 
        b.batch_name as batch_full_name,
        b.status as batch_status
    FROM students s 
    LEFT JOIN batches b ON s.batch_name = b.batch_name 
    WHERE s.student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        // Use the actual batch_id from batches table
        $batch_id = $student['actual_batch_id'] ?? $student['batch_name'];
        $batch_name = $student['batch_full_name'] ?? $student['batch_name'];
        $student_name = $student['first_name'] . ' ' . $student['last_name'];
        $requires_payment = true; // Force payment requirement for locked accounts
        
        // Store batch status for validation
        $batch_status = $student['batch_status'] ?? 'unknown';
    }
} elseif (isset($_SESSION['payment_verification_data'])) {
    $student_id = $_SESSION['payment_verification_data']['student_id'] ?? '';
    $batch_id = $_SESSION['payment_verification_data']['batch_id'] ?? '';
    $student_name = $_SESSION['payment_verification_data']['student_name'] ?? '';
    $batch_name = $_SESSION['payment_verification_data']['batch_name'] ?? '';
} elseif (isset($_GET['student_id'])) {
    $student_id = $_GET['student_id'];
    
    // FIXED: Get student info with proper batch relationship
    $stmt = $db->prepare("SELECT 
        s.*, 
        b.batch_id as actual_batch_id, 
        b.batch_name as batch_full_name,
        b.status as batch_status
    FROM students s 
    LEFT JOIN batches b ON s.batch_name = b.batch_name 
    WHERE s.student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        // Use the actual batch_id from batches table
        $batch_id = $student['actual_batch_id'] ?? $student['batch_name']; // Fallback to batch_name if batch_id not found
        $batch_name = $student['batch_full_name'] ?? $student['batch_name'];
        $student_name = $student['first_name'] . ' ' . $student['last_name'];
        $requires_payment = in_array($student['fees_status'], ['unpaid', 'partially_paid', 'overdue']);
        
        // Store batch status for validation
        $batch_status = $student['batch_status'] ?? 'unknown';
    }
}

// If we got batch_id, update session
if ($batch_id && !isset($_SESSION['payment_verification_data']['batch_id'])) {
    $_SESSION['payment_verification_data'] = $_SESSION['payment_verification_data'] ?? [];
    $_SESSION['payment_verification_data']['batch_id'] = $batch_id;
    $_SESSION['payment_verification_data']['batch_name'] = $batch_name;
    $_SESSION['payment_verification_data']['student_id'] = $student_id;
    $_SESSION['payment_verification_data']['student_name'] = $student_name;
}

// Prevent access to dashboard while payment is pending
if ($requires_payment && isset($_SESSION['payment_verification_data'])) {
    $_SESSION['payment_pending'] = true;
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$error_message = '';
$success_message = '';
$transaction_id_created = '';

// Get active batches for dropdown
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $transaction_id = trim($_POST['transaction_id']);
        $form_student_id = trim($_POST['student_id']);
        $form_batch_id = trim($_POST['batch_id']);
        $transaction_date = $_POST['transaction_date'];
        $amount = floatval($_POST['amount']);
        $payment_mode = $_POST['payment_mode'];
        $payment_recipient = 'BugDetox Technologies LLP'; // Always set to BugDetox Technologies LLP
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Validate student exists and get name
        $stmt = $db->prepare("SELECT 
            s.first_name, s.last_name, s.batch_name, s.fees_status, s.enrollment_fees, s.total_fees_paid,
            b.batch_id, b.batch_name as batch_full_name, b.status as batch_status
        FROM students s 
        LEFT JOIN batches b ON s.batch_name = b.batch_name
        WHERE s.student_id = ?");
        $stmt->execute([$form_student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception("Student ID not found.");
        }
        
        // IMPORTANT FIX: Validate batch exists by batch_id OR batch_name
        // Check if the batch exists with either batch_id or batch_name match
        $stmt = $db->prepare("SELECT batch_id, batch_name, status FROM batches 
                             WHERE (batch_id = ? OR batch_name = ?) 
                             AND (status = 'ongoing' OR status = 'upcoming')");
        $stmt->execute([$form_batch_id, $student['batch_name']]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            // Try one more time with just the batch name from student record
            $stmt = $db->prepare("SELECT batch_id, batch_name, status FROM batches 
                                 WHERE batch_name = ? 
                                 AND (status = 'ongoing' OR status = 'upcoming')");
            $stmt->execute([$student['batch_name']]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$batch) {
                throw new Exception("Batch not found or not active. Student's batch: " . htmlspecialchars($student['batch_name']));
            }
            
            // Use the batch_id from the found batch
            $form_batch_id = $batch['batch_id'];
        }
        
        // Handle file upload
        if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/transactions/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Only JPG, PNG, GIF, and PDF files are allowed.");
            }
            
            if ($_FILES['screenshot']['size'] > 5 * 1024 * 1024) { // 5MB limit
                throw new Exception("File size must be less than 5MB.");
            }
            
            $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['screenshot']['name']);
            $filepath = $upload_dir . $filename;
            
            if (!move_uploaded_file($_FILES['screenshot']['tmp_name'], $filepath)) {
                throw new Exception("Failed to upload file.");
            }
        } else {
            // Check which error occurred
            if ($_FILES['screenshot']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new Exception("Please upload a screenshot of the transaction.");
            } else {
                throw new Exception("File upload error: " . $_FILES['screenshot']['error']);
            }
        }
        
        // Begin transaction
        $db->beginTransaction();
        
        try {
            // Insert transaction with pending status
            $stmt = $db->prepare("INSERT INTO transactions 
                (transaction_id, student_id, student_name, batch_id, transaction_date, amount, 
                 payment_mode, payment_recipient, screenshot_path, remarks, uploaded_at, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')");
            
            $student_name = $student['first_name'] . ' ' . $student['last_name'];
            $stmt->execute([
                $transaction_id,
                $form_student_id,
                $student_name,
                $form_batch_id,
                $transaction_date,
                $amount,
                $payment_mode,
                $payment_recipient,
                $filename,
                $remarks
            ]);
            
            // Get the next installment number for this student
            $stmt = $db->prepare("SELECT MAX(installment_number) as last_installment FROM fee_installments WHERE student_id = ?");
            $stmt->execute([$form_student_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $next_installment_number = 1; // Default for first installment
            if ($result && !empty($result['last_installment'])) {
                $next_installment_number = intval($result['last_installment']) + 1;
            }
            
            // Calculate due date (30 days from transaction date)
            $due_date = date('Y-m-d', strtotime($transaction_date . ' +30 days'));
            
            // Insert into fee_installments table with pending status
            $stmt = $db->prepare("INSERT INTO fee_installments 
                (student_id, installment_number, installment_amount, due_date, 
                 paid_amount, payment_date, payment_method, payment_status, 
                 transaction_id, receipt_path, notes, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            
            $stmt->execute([
                $form_student_id,
                $next_installment_number,
                $amount,
                $due_date,
                0.00, // paid_amount (0 for pending - will be updated when approved)
                null, // payment_date (null for pending - will be updated when approved)
                $payment_mode,
                'pending', // payment_status (will be updated when approved)
                $transaction_id, // Store transaction ID for reference
                $filename, // Use screenshot as receipt path
                "Transaction submitted via portal. Status: Pending Verification. Remarks: " . $remarks,
            ]);
            
            // IMPORTANT: DO NOT update student's fees information here
            // This will be done in transaction_approval.php when the transaction is approved
            
            // IMPORTANT: DO NOT unlock the student account here
            // Account unlocking should be done manually by accounts team after verification
            
            // Commit transaction
            $db->commit();
            
            $success_message = "Transaction submitted successfully!<br>";
            $success_message .= "Transaction ID: <strong>" . $transaction_id . "</strong><br>";
            $success_message .= "Installment #" . $next_installment_number . " created and marked as pending verification.";
            $transaction_id_created = $transaction_id;
            
            // Clear payment pending flag and verification data
            if (isset($_SESSION['payment_pending'])) {
                unset($_SESSION['payment_pending']);
            }
            if (isset($_SESSION['payment_verification_data'])) {
                unset($_SESSION['payment_verification_data']);
            }
            
            // Clear accounts lock data if present
            if (isset($_SESSION['accounts_lock_data'])) {
                unset($_SESSION['accounts_lock_data']);
            }
            
            // Add login button after successful submission
            $success_message .= "<br><br><div class='text-center mt-4'>";
            $success_message .= "<a href='../login.php' class='px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors inline-block'>";
            $success_message .= "<i class='fas fa-sign-in-alt mr-2'></i>Login to Dashboard</a>";
            $success_message .= "</div>";
            
            // Clear form
            $_POST = array();
            
        } catch (Exception $e) {
            // Rollback on error
            $db->rollBack();
            throw new Exception("Database error: " . $e->getMessage());
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get student info for auto-fill if student_id is available
$student_info = null;
if (!empty($student_id)) {
    $stmt = $db->prepare("SELECT 
        s.first_name, s.last_name, s.email, s.phone_number, s.course, s.fees_status, 
        s.enrollment_fees, s.total_fees_paid, s.batch_name,
        b.batch_id, b.batch_name as batch_display_name, b.status as batch_status
    FROM students s
    LEFT JOIN batches b ON s.batch_name = b.batch_name
    WHERE s.student_id = ?");
    $stmt->execute([$student_id]);
    $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update batch_id if we found it from the join
    if ($student_info && !empty($student_info['batch_id'])) {
        $batch_id = $student_info['batch_id'];
        $batch_name = $student_info['batch_display_name'] ?? $student_info['batch_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Verification - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        
        .container {
            max-width: 1200px;
            width: 100%;
            display: flex;
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            min-height: auto;
            flex-direction: column;
        }
        
        .left-section {
            flex: 0 0 auto;
            background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);
            color: white;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .logo h1 {
            color: white;
            font-size: 1.3rem;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #adb5bd;
            font-size: 0.8rem;
        }
        
        .payment-info {
            flex-grow: 1;
            margin-bottom: 20px;
        }
        
        .payment-info h3 {
            color: #f8f9fa;
            margin-bottom: 12px;
            font-size: 1.1rem;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 6px;
        }
        
        .bank-details {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
        }
        
        .bank-details p {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
            font-size: 0.8rem;
        }
        
        .bank-details i {
            width: 18px;
            margin-right: 8px;
            text-align: center;
        }
        
        .qr-container {
            background: white;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            margin-top: 15px;
        }
        
        .qr-code {
            width: 140px;
            height: 140px;
            margin: 0 auto 10px;
        }
        
        .qr-code img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 6px;
        }
        
        .qr-label {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: #2c3e50;
        }
        
        .qr-instruction {
            color: #6c757d;
            font-size: 0.75rem;
            max-width: 250px;
            margin: 0 auto;
            line-height: 1.4;
        }
        
        .right-section {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
        }
        
        .header {
            margin-bottom: 20px;
        }
        
        .header h1 {
            color: #2c3e50;
            font-size: 1.5rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .header p {
            color: #6c757d;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        
        /* Accounts Lock Banner */
        .accounts-lock-banner {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
            border-left: 5px solid #f1c40f;
        }
        
        .accounts-lock-banner h4 {
            margin-bottom: 10px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .accounts-lock-banner p {
            margin-bottom: 8px;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .lock-reason-box {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-size: 0.85rem;
            border-left: 3px solid #ffd700;
        }
        
        /* Payment Banner */
        .payment-banner {
            background: linear-gradient(135deg, #ff6b6b 0%, #ff8e8e 100%);
            color: white;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            animation: slideIn 0.3s ease;
        }
        
        .payment-banner h4 {
            margin-bottom: 8px;
            font-size: 1rem;
        }
        
        .payment-banner p {
            margin-bottom: 6px;
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .alert {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert i {
            font-size: 1.2rem;
            margin-top: 2px;
        }
        
        .alert div {
            flex: 1;
            font-size: 0.9rem;
        }
        
        .form-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group.full-width {
            grid-column: span 1;
        }
        
        .form-group.half-width {
            grid-column: span 1;
        }
        
        label {
            font-weight: 600;
            margin-bottom: 6px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
        }
        
        label i {
            color: #667eea;
            width: 14px;
            text-align: center;
            font-size: 0.85rem;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
        }
        
        input:read-only, select:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
            border-color: #ced4da;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            background-color: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .student-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            padding: 10px;
            border-radius: 6px;
            margin-top: 8px;
            font-size: 0.85rem;
            display: block;
        }
        
        .student-info i {
            color: #2ecc71;
            margin-right: 5px;
            font-size: 0.9rem;
        }
        
        .file-upload {
            padding: 15px;
            background-color: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 5px;
        }
        
        .file-upload:hover {
            border-color: #667eea;
            background-color: rgba(102, 126, 234, 0.05);
        }
        
        .file-upload input {
            display: none;
        }
        
        .file-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            color: #6c757d;
        }
        
        .file-info i {
            font-size: 1.5rem;
            color: #667eea;
        }
        
        .file-info span {
            font-size: 0.85rem;
        }
        
        #imagePreview {
            max-width: 100%;
            max-height: 120px;
            border-radius: 6px;
            border: 2px solid #e9ecef;
            display: none;
            margin: 12px auto 0;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }
        
        .preview-container {
            text-align: center;
        }
        
        .file-name {
            margin-top: 8px;
            font-size: 0.8rem;
            color: #495057;
            font-weight: 500;
            word-break: break-all;
            display: none;
        }
        
        .file-name.visible {
            display: block;
        }
        
        .button-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 20px;
            grid-column: span 1;
        }
        
        button {
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            color: white;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(46, 204, 113, 0.3);
        }
        
        .reset-btn {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #2c3e50;
            border: 2px solid #dee2e6;
        }
        
        .reset-btn:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            transform: translateY(-2px);
        }
        
        .login-btn {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            margin-top: 10px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(52, 152, 219, 0.3);
        }
        
        .required {
            color: #e74c3c;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .success-details {
            background-color: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 6px;
            padding: 12px;
            margin-top: 12px;
            font-size: 0.85rem;
        }
        
        .success-details h4 {
            color: #2e7d32;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        
        .success-details ul {
            padding-left: 18px;
            color: #555;
        }
        
        .success-details li {
            margin-bottom: 4px;
            font-size: 0.8rem;
        }
        
        .logout-link {
            margin-top: 15px;
            text-align: center;
        }
        
        .logout-link a {
            color: #ff6b6b;
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .logout-link a:hover {
            color: #ff8e8e;
            text-decoration: underline;
        }
        
        .bank-details-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }
        
        .info-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
            padding: 6px;
            font-size: 0.75rem;
        }
        
        .info-item i {
            margin-right: 5px;
        }
        
        .no-gd-warning {
            background-color: #fff3cd;
            color: #856404;
            padding: 8px;
            border-radius: 5px;
            font-size: 0.75rem;
            margin-top: 8px;
            text-align: center;
            border: 1px solid #ffeaa7;
            margin-bottom: 15px;
        }
        
        .student-id-notice {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 8px;
            margin-top: 5px;
            border-radius: 4px;
            font-size: 0.8rem;
            color: #1976d2;
        }
        
        .secure-footer {
            margin-top: 15px;
            font-size: 0.7rem;
            color: #adb5bd;
            text-align: center;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .secure-footer p {
            margin-bottom: 5px;
        }
        
        .important-notes {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }
        
        .important-notes h3 {
            color: #2c3e50;
            font-size: 0.9rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .important-notes ul {
            color: #6c757d;
            font-size: 0.8rem;
            padding-left: 18px;
        }
        
        .important-notes li {
            margin-bottom: 6px;
            line-height: 1.4;
        }
        
        .verification-note {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 12px;
            margin: 15px 0;
            font-size: 0.85rem;
            color: #856404;
        }
        
        .verification-note h4 {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        /* Tablet Styles */
        @media (min-width: 768px) {
            .container {
                flex-direction: row;
                min-height: 600px;
            }
            
            .left-section {
                flex: 0 0 280px;
                padding: 25px;
            }
            
            .right-section {
                padding: 25px;
            }
            
            .form-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            
            .form-group.full-width {
                grid-column: span 2;
            }
            
            .form-group.half-width {
                grid-column: span 2;
            }
            
            .button-group {
                flex-direction: row;
                grid-column: span 2;
            }
            
            button {
                width: auto;
                flex: 1;
            }
            
            .logo h1 {
                font-size: 1.4rem;
            }
            
            .header h1 {
                font-size: 1.6rem;
            }
            
            .qr-code {
                width: 160px;
                height: 160px;
            }
        }
        
        /* Desktop Styles */
        @media (min-width: 1024px) {
            .container {
                max-height: 90vh;
            }
            
            .left-section {
                flex: 0 0 320px;
                padding: 30px;
            }
            
            .right-section {
                padding: 30px;
            }
            
            .form-container {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .form-group.full-width {
                grid-column: span 3;
            }
            
            .form-group.half-width {
                grid-column: span 2;
            }
            
            .button-group {
                grid-column: span 3;
            }
            
            .logo h1 {
                font-size: 1.5rem;
            }
            
            .header h1 {
                font-size: 1.8rem;
            }
            
            .qr-code {
                width: 180px;
                height: 180px;
            }
        }
        
        /* Small mobile devices */
        @media (max-width: 360px) {
            body {
                padding: 10px;
            }
            
            .left-section, .right-section {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.3rem;
            }
            
            .qr-code {
                width: 120px;
                height: 120px;
            }
            
            button {
                padding: 10px 15px;
                font-size: 0.85rem;
            }
            
            .file-upload {
                padding: 12px;
            }
        }
        
        /* Print styles */
        @media print {
            body {
                background: white;
            }
            
            .container {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .left-section {
                display: none;
            }
            
            .button-group,
            .file-upload,
            .no-gd-warning,
            .logout-link,
            .important-notes {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Left Section - Payment Info -->
        <div class="left-section">
            <div class="logo">
                <h1><i class="fas fa-graduation-cap"></i> ASD Academy</h1>
                <p>Transaction Verification Portal</p>
            </div>
            
            <div class="payment-info">
                <h3><i class="fas fa-university"></i> Payment Details</h3>
                <div class="bank-details">
                    <div class="bank-details-grid">
                        <div class="info-item" style="grid-column: span 1;">
                            <i class="fas fa-user" style="color: #2196F3;"></i>
                            <span>BUGDETOX TECHNOLOGIES LLP</span>
                        </div>
                        <div class="info-item" style="grid-column: span 1;">
                            <i class="fas fa-info-circle" style="color: #00BCD4;"></i>
                            <span>UPI ID: 8107315776@kotak</span>
                        </div>
                    </div>
                </div>
                
                <div class="qr-container">
                    <div class="qr-code">
                        <img src="qrcode.jpeg" alt="ASD Academy Payment QR Code">
                    </div>
                    <p class="qr-label">Scan to Make Payment</p>
                    <p class="qr-instruction">Scan this QR code with your payment app to complete the transaction</p>
                </div>
            </div>
            
            <div class="logout-link">
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout & Return to Login
                </a>
            </div>
            
            <div class="secure-footer">
                <p><i class="fas fa-shield-alt"></i> Secure & Encrypted</p>
                <p>© 2025 ASD Academy. All rights reserved.</p>
            </div>
        </div>
        
        <!-- Right Section - Form -->
        <div class="right-section">
            <div class="header">
                <h1><i class="fas fa-receipt"></i> Transaction Verification</h1>
                <p>Submit your payment details for verification. All fields marked with <span class="required">*</span> are required.</p>
            </div>
            
            <?php if ($is_accounts_lock): ?>
                <div class="accounts-lock-banner">
                    <h4><i class="fas fa-lock"></i> Account Locked - Payment Required</h4>
                    <p>Your account has been locked by the accounts team due to pending fees payment.</p>
                    <p>To unlock your account and regain access to the student dashboard, please complete your payment below.</p>
                    <?php if (!empty($lock_reason)): ?>
                        <div class="lock-reason-box">
                            <strong><i class="fas fa-info-circle"></i> Lock Reason:</strong> <?php echo htmlspecialchars($lock_reason); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($requires_payment && !$is_accounts_lock): ?>
                <div class="payment-banner">
                    <h4><i class="fas fa-exclamation-triangle"></i> Payment Required</h4>
                    <p>You must complete your fee payment to access the student dashboard.</p>
                    <p>Your student ID and batch have been pre-filled for your convenience.</p>
                    <?php if ($student_info): ?>
                        <p style="font-size: 0.85rem;">
                            <i class="fas fa-info-circle"></i> 
                            Current Fees Status: <strong><?php echo strtoupper($student_info['fees_status']); ?></strong>
                        </p>
                        <?php if ($student_info['enrollment_fees'] > 0): ?>
                            <p style="font-size: 0.85rem;">
                                <i class="fas fa-rupee-sign"></i> 
                                Enrollment Fees: ₹<?php echo number_format($student_info['enrollment_fees'], 2); ?> | 
                                Paid: ₹<?php echo number_format($student_info['total_fees_paid'], 2); ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $success_message; ?></div>
                </div>
                
                <?php if ($transaction_id_created): ?>
                <div class="success-details">
                    <h4><i class="fas fa-info-circle"></i> What happens next?</h4>
                    <ul>
                        <li>Installment has been created with <strong>pending verification</strong> status</li>
                        <li>Your transaction will be reviewed by the accounts team</li>
                        <li>Once verified, the installment status will change to <strong>paid</strong></li>
                        <li>Your fees status will be updated automatically after verification</li>
                        <?php if ($is_accounts_lock): ?>
                        <li><strong>Your account will be unlocked by the accounts team after payment verification</strong></li>
                        <?php endif; ?>
                        <li>You will receive an email confirmation</li>
                        <li>You can track your payment status using Transaction ID: <strong><?php echo htmlspecialchars($transaction_id_created); ?></strong></li>
                    </ul>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div><?php echo htmlspecialchars($error_message); ?></div>
                </div>
            <?php endif; ?>
            
            <form id="transactionForm" method="POST" enctype="multipart/form-data">
                <div class="form-container">
                    <!-- Student Details -->
                    <div class="form-group">
                        <label for="student_id"><i class="fas fa-id-card"></i> Student ID <span class="required">*</span></label>
                        <input type="text" id="student_id" name="student_id" 
                               placeholder="Enter Student ID" 
                               value="<?php echo htmlspecialchars($student_id); ?>"
                               required
                               <?php echo !empty($student_id) ? 'readonly' : ''; ?>>
                        <div class="student-info">
                            <?php if ($student_info): ?>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-user-check" style="color: #2ecc71;"></i>
                                    <div>
                                        <strong><?php echo htmlspecialchars($student_info['first_name'] . ' ' . $student_info['last_name']); ?></strong>
                                        <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                                            <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student_info['email'] ?? 'N/A'); ?></div>
                                            <?php if ($batch_name): ?>
                                            <div><i class="fas fa-layer-group"></i> Batch: <?php echo htmlspecialchars($batch_name); ?></div>
                                            <?php endif; ?>
                                            <?php if ($student_info['enrollment_fees'] > 0): ?>
                                            <div><i class="fas fa-rupee-sign"></i> Fees: ₹<?php echo number_format($student_info['enrollment_fees'], 2); ?> | Paid: ₹<?php echo number_format($student_info['total_fees_paid'], 2); ?></div>
                                            <?php endif; ?>
                                            <?php if ($is_accounts_lock): ?>
                                            <div><i class="fas fa-lock" style="color: #e74c3c;"></i> Account Status: <span style="color: #e74c3c; font-weight: bold;">Locked by Accounts Team</span></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="transaction_id"><i class="fas fa-receipt"></i> Transaction ID <span class="required">*</span></label>
                        <input type="text" id="transaction_id" name="transaction_id" 
                               placeholder="TXN-2025-00145 or UTR123456789"
                               value="<?php echo htmlspecialchars($_POST['transaction_id'] ?? ''); ?>"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="batch_id"><i class="fas fa-layer-group"></i> Batch <span class="required">*</span></label>
                        <?php if (!empty($batch_id)): ?>
                            <!-- If batch is already determined, show as read-only with hidden input -->
                            <input type="text" id="batch_display" 
                                   value="<?php echo htmlspecialchars($batch_name . ' (' . $batch_id . ')'); ?>"
                                   readonly
                                   style="background-color: #f8f9fa; cursor: not-allowed;">
                            <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch_id); ?>">
                            <div class="student-id-notice">
                                <i class="fas fa-info-circle"></i> Batch automatically selected based on your student ID: <?php echo htmlspecialchars($batch_name); ?>
                            </div>
                        <?php else: ?>
                            <!-- If no batch determined, show dropdown -->
                            <select id="batch_id" name="batch_id" required>
                                <option value="" disabled selected>Select Batch</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?php echo htmlspecialchars($batch['batch_id']); ?>"
                                        <?php echo (isset($_POST['batch_id']) && $_POST['batch_id'] == $batch['batch_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($batch['batch_name'] . ' (' . $batch['batch_id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Transaction Details -->
                    <div class="form-group">
                        <label for="transaction_date"><i class="fas fa-calendar-alt"></i> Date <span class="required">*</span></label>
                        <input type="date" id="transaction_date" name="transaction_date" 
                               value="<?php echo htmlspecialchars($_POST['transaction_date'] ?? date('Y-m-d')); ?>"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="amount"><i class="fas fa-rupee-sign"></i> Amount (₹) <span class="required">*</span></label>
                        <input type="number" id="amount" name="amount" 
                               placeholder="0.00" 
                               value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
                               step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_mode"><i class="fas fa-credit-card"></i> Payment Mode <span class="required">*</span></label>
                        <select id="payment_mode" name="payment_mode" required>
                            <option value="" disabled>Select Mode</option>
                            <option value="bank_transfer" <?php echo (isset($_POST['payment_mode']) && $_POST['payment_mode'] == 'bank_transfer') ? 'selected' : 'selected'; ?>>Bank Transfer</option>
                            <option value="cash" <?php echo (isset($_POST['payment_mode']) && $_POST['payment_mode'] == 'cash') ? 'selected' : ''; ?>>Cash</option>
                            <option value="cheque" <?php echo (isset($_POST['payment_mode']) && $_POST['payment_mode'] == 'cheque') ? 'selected' : ''; ?>>Cheque</option>
                            <option value="other" <?php echo (isset($_POST['payment_mode']) && $_POST['payment_mode'] == 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_recipient"><i class="fas fa-user-tie"></i> Payment Recipient <span class="required">*</span></label>
                        <select id="payment_recipient" name="payment_recipient" required disabled>
                            <option value="BugDetox Technologies LLP" selected>BugDetox Technologies LLP</option>
                        </select>
                        <input type="hidden" name="payment_recipient" value="BugDetox Technologies LLP">
                        <div class="student-id-notice">
                            <i class="fas fa-info-circle"></i> All payments must be made to BugDetox Technologies LLP
                        </div>
                    </div>
                    
                    <!-- File Upload -->
                    <div class="form-group full-width">
                        <label for="screenshot"><i class="fas fa-camera"></i> Payment Proof <span class="required">*</span></label>
                        <div class="file-upload" id="fileUploadArea" onclick="document.getElementById('screenshot').click()">
                            <input type="file" id="screenshot" name="screenshot" accept="image/*,.pdf" required>
                            <div class="file-info">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Click to upload screenshot (JPG, PNG, GIF, PDF)</span>
                                <small style="color: #6c757d; font-size: 0.85rem;">Max size: 5MB</small>
                            </div>
                            <div class="file-name" id="fileName"></div>
                        </div>
                        <div class="preview-container">
                            <img id="imagePreview" src="#" alt="Image preview">
                        </div>
                    </div>
                    
                    <!-- Verification Note - Dynamic based on accounts lock -->
                    <div class="form-group full-width">
                        <div class="verification-note" id="verificationNote">
                            <?php if ($is_accounts_lock): ?>
                                <h4><i class="fas fa-lock"></i> Important: Account Lock Status</h4>
                                <p>Your account is currently <strong style="color: #e74c3c;">LOCKED</strong> by the accounts team. Your payment will be marked as <strong>PENDING</strong> until verified.</p>
                                <p><strong>Your account will be unlocked by the accounts team only after payment verification is completed.</strong></p>
                                <p style="margin-top: 8px;"><i class="fas fa-clock"></i> This process typically takes 1-2 business days. You will receive an email notification once your account is unlocked.</p>
                            <?php else: ?>
                                <h4><i class="fas fa-clock"></i> Important Verification Note</h4>
                                <p>Your payment will be marked as <strong>PENDING</strong> until verified by the accounts team. 
                                Your fees status will be updated only after verification. This process typically takes 1-2 business days.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Remarks -->
                    <div class="form-group full-width">
                        <label for="remarks"><i class="fas fa-sticky-note"></i> Remarks (Optional)</label>
                        <textarea id="remarks" name="remarks" rows="3" 
                                  placeholder="Any additional information about the transaction..."><?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Buttons -->
                    <div class="button-group">
                        <button type="submit" class="submit-btn" id="submitBtn">
                            <?php if ($is_accounts_lock): ?>
                                <i class="fas fa-unlock-alt"></i> Submit Payment to Unlock Account
                            <?php else: ?>
                                <i class="fas fa-paper-plane"></i> Submit for Verification
                            <?php endif; ?>
                        </button>
                        <button type="button" class="reset-btn" id="resetBtn">
                            <i class="fas fa-redo"></i> Reset Form
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Set default date to today
        document.getElementById('transaction_date').valueAsDate = new Date();
        
        // Initialize date picker max date to today
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('transaction_date').max = today;
        
        // File upload preview functionality
        const fileInput = document.getElementById('screenshot');
        const imagePreview = document.getElementById('imagePreview');
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileName = document.getElementById('fileName');
        
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                // Show file name
                fileName.textContent = `Selected: ${file.name}`;
                fileName.classList.add('visible');
                
                // Preview image if it's an image file
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    
                    reader.addEventListener('load', function() {
                        imagePreview.style.display = 'block';
                        imagePreview.src = reader.result;
                    });
                    
                    reader.readAsDataURL(file);
                } else if (file.type === 'application/pdf') {
                    // Show PDF icon for PDF files
                    imagePreview.style.display = 'block';
                    imagePreview.src = 'https://cdn-icons-png.flaticon.com/512/337/337946.png';
                    imagePreview.alt = 'PDF Document';
                } else {
                    imagePreview.style.display = 'none';
                }
            } else {
                fileName.textContent = '';
                fileName.classList.remove('visible');
                imagePreview.style.display = 'none';
                imagePreview.src = '#';
            }
        });
        
        // Make the entire file upload area clickable
        fileUploadArea.addEventListener('click', function(e) {
            if (e.target !== fileInput) {
                fileInput.click();
            }
        });
        
        // Drag and drop functionality
        fileUploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.borderColor = '#667eea';
            this.style.backgroundColor = 'rgba(102, 126, 234, 0.1)';
        });
        
        fileUploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.borderColor = '#dee2e6';
            this.style.backgroundColor = '#f8f9fa';
        });
        
        fileUploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.borderColor = '#667eea';
            this.style.backgroundColor = 'rgba(102, 126, 234, 0.05)';
            
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                
                // Trigger change event to update preview
                const event = new Event('change');
                fileInput.dispatchEvent(event);
            }
        });
        
        // Reset button functionality
        document.getElementById('resetBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                document.getElementById('transactionForm').reset();
                document.getElementById('transaction_date').valueAsDate = new Date();
                imagePreview.style.display = 'none';
                fileName.textContent = '';
                fileName.classList.remove('visible');
                
                // Don't reset student ID and batch if they were pre-filled
                <?php if (!empty($student_id)): ?>
                document.getElementById('student_id').value = '<?php echo htmlspecialchars($student_id); ?>';
                <?php endif; ?>
                
                <?php if (!empty($batch_id)): ?>
                // If batch is pre-filled, don't reset it
                const batchDisplay = document.getElementById('batch_display');
                if (batchDisplay) {
                    // Keep the batch display as is
                }
                <?php endif; ?>
                
                // Reset payment mode to default (bank transfer)
                document.getElementById('payment_mode').value = 'bank_transfer';
                
                // Payment recipient is always BugDetox Technologies LLP
                document.getElementById('payment_recipient').value = 'BugDetox Technologies LLP';
            }
        });
        
        // Form validation
        document.getElementById('transactionForm').addEventListener('submit', function(e) {
            const amount = parseFloat(document.getElementById('amount').value);
            if (amount <= 0) {
                e.preventDefault();
                alert('Amount must be greater than 0.');
                return false;
            }
            
            const file = fileInput.files[0];
            if (!file) {
                e.preventDefault();
                alert('Please upload a screenshot of the transaction.');
                return false;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                e.preventDefault();
                alert('File size must be less than 5MB.');
                return false;
            }
            
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
            if (!allowedTypes.includes(file.type)) {
                e.preventDefault();
                alert('Only JPG, PNG, GIF, and PDF files are allowed.');
                return false;
            }
            
            // Show loading state
            const submitBtn = document.querySelector('.submit-btn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            submitBtn.disabled = true;
            
            return true;
        });
        
        // Auto-generate transaction ID if not provided
        document.getElementById('transaction_id').addEventListener('focus', function() {
            if (!this.value) {
                const now = new Date();
                const timestamp = now.getFullYear() + 
                                 String(now.getMonth() + 1).padStart(2, '0') + 
                                 String(now.getDate()).padStart(2, '0') + 
                                 String(now.getHours()).padStart(2, '0') + 
                                 String(now.getMinutes()).padStart(2, '0') + 
                                 String(now.getSeconds()).padStart(2, '0');
                const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                this.value = `TXN-${timestamp}-${random}`;
            }
        });
        
        // Style pre-filled fields
        document.addEventListener('DOMContentLoaded', function() {
            const studentIdField = document.getElementById('student_id');
            const paymentRecipient = document.getElementById('payment_recipient');
            
            <?php if (!empty($student_id)): ?>
            studentIdField.style.backgroundColor = '#f8f9fa';
            studentIdField.title = 'Student ID is pre-filled and cannot be changed';
            <?php endif; ?>
            
            // Style payment recipient as readonly
            paymentRecipient.style.backgroundColor = '#f8f9fa';
            paymentRecipient.title = 'All payments must be made to BugDetox Technologies LLP';
            
            // Adjust layout for mobile
            function adjustLayout() {
                const container = document.querySelector('.container');
                if (window.innerWidth < 768) {
                    container.style.flexDirection = 'column';
                } else {
                    container.style.flexDirection = 'row';
                }
            }
            
            // Initial adjustment
            adjustLayout();
            
            // Adjust on resize
            window.addEventListener('resize', adjustLayout);
            
            // Add accounts lock specific UI modifications
            <?php if ($is_accounts_lock): ?>
            // Change submit button color for lock case
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.style.background = 'linear-gradient(135deg, #e67e22 0%, #d35400 100%)';
                submitBtn.title = 'Submit payment to unlock your account';
            }
            
            // Add lock icon to batch display if applicable
            const batchDisplay = document.getElementById('batch_display');
            if (batchDisplay) {
                // Already handled in PHP
            }
            <?php endif; ?>
        });
        
        // Handle mobile keyboard
        function handleKeyboard() {
            if (window.innerWidth < 768) {
                // Scroll to active input when keyboard appears
                const inputs = document.querySelectorAll('input, textarea, select');
                inputs.forEach(input => {
                    input.addEventListener('focus', function() {
                        setTimeout(() => {
                            this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }, 300);
                    });
                });
            }
        }
        
        // Initialize keyboard handling
        handleKeyboard();
        
        // Prevent double submission
        let formSubmitted = false;
        document.getElementById('transactionForm').addEventListener('submit', function() {
            if (formSubmitted) {
                e.preventDefault();
                return false;
            }
            formSubmitted = true;
            return true;
        });
    </script>
</body>
</html>