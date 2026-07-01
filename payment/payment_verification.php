<?php
/**
 * payment_verification.php
 * Payment verification logic to be included in login.php
 */

function checkPaymentVerification($db, $student_id, $email) {
    try {
        // Get student's batch and fees status
        $stmt = $db->prepare("
            SELECT 
                s.student_id,
                s.batch_name,
                s.fees_status,
                s.current_status,
                s.first_name,
                s.last_name,
                b.batch_name as batch_full_name
            FROM students s
            LEFT JOIN batches b ON s.batch_name = b.batch_id
            WHERE s.email = ? OR s.student_id = ?
            LIMIT 1
        ");
        $stmt->execute([$email, $student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return ['redirect' => false, 'reason' => 'Student record not found'];
        }
        
        // Check if student's batch requires payment verification
        $stmt = $db->prepare("
            SELECT is_active 
            FROM payment_verification_settings 
            WHERE batch_id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$student['batch_name']]);
        $verification_required = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If batch doesn't require verification, allow login without payment check
        if (!$verification_required) {
            return ['redirect' => false, 'reason' => 'Payment verification not required for this batch'];
        }
        
        // Check fees status only if verification is required
        $unpaid_statuses = ['unpaid', 'overdue'];
        if (in_array($student['fees_status'], $unpaid_statuses)) {
            return [
                'redirect' => true,
                'redirect_url' => 'payment/a.php',
                'batch_id' => $student['batch_name'],
                'batch_name' => $student['batch_full_name'],
                'student_id' => $student['student_id'],
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'fees_status' => $student['fees_status']
            ];
        }
        
        return ['redirect' => false, 'reason' => 'Fees are paid'];
        
    } catch (PDOException $e) {
        error_log("Payment verification error: " . $e->getMessage());
        return ['redirect' => false, 'reason' => 'System error'];
    }
}

// Function to redirect to payment page with session data
function redirectToPaymentPage($verification_result) {
    // Store verification data in session (not the main auth session)
    $_SESSION['payment_verification_data'] = [
        'batch_id' => $verification_result['batch_id'],
        'batch_name' => $verification_result['batch_name'],
        'student_id' => $verification_result['student_id'],
        'student_name' => $verification_result['student_name'],
        'fees_status' => $verification_result['fees_status'],
        'redirected_at' => time(),
        'requires_payment' => true
    ];
    
    // Destroy the main authentication session
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
    
    // Start a new minimal session for payment page
    session_start();
    $_SESSION['payment_verification_data'] = $verification_result;
    
    // Redirect to payment page with student ID as parameter for safety
    header("Location: " . $verification_result['redirect_url'] . "?student_id=" . urlencode($verification_result['student_id']));
    exit;
}
?>