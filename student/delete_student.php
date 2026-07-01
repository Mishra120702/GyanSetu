<?php
// delete_student.php
require_once '../db_connection.php';
session_start();

// Redirect to login if user is not authenticated or not an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get student ID from URL
$student_id = isset($_GET['id']) ? trim($_GET['id']) : null;

// Redirect if student ID is missing
if (!$student_id) {
    $_SESSION['error_message'] = "No student specified for deletion.";
    header("Location: students_list.php");
    exit();
}

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if student exists and get user_id
    $stmt = $db->prepare("SELECT s.*, u.id as user_id FROM students s LEFT JOIN users u ON s.user_id = u.id WHERE s.student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $_SESSION['error_message'] = "Student not found.";
        header("Location: students_list.php");
        exit();
    }
    
    $user_id = $student['user_id'];
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['confirm_delete'])) {
            // Begin transaction for multiple operations
            $db->beginTransaction();
            
            try {
                // Get student name for related records
                $student_name = $student['first_name'] . ' ' . $student['last_name'];
                $batch_id = $student['batch_name'];
                
                // ==============================================
                // 1. Delete attendance records
                // ==============================================
                $stmt = $db->prepare("DELETE FROM attendance WHERE student_id = ? OR student_name = ?");
                $stmt->execute([$student_id, $student_name]);
                
                // ==============================================
                // 2. Delete exam-related records
                // ==============================================
                $stmt = $db->prepare("DELETE er FROM exam_results er WHERE er.student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM exam_enrollments WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM exam_students WHERE student_name = ?");
                $stmt->execute([$student_name]);
                
                // ==============================================
                // 3. Delete feedback records
                // ==============================================
                $stmt = $db->prepare("DELETE FROM feedback WHERE student_name = ?");
                $stmt->execute([$student_name]);
                
                // ==============================================
                // 4. Delete workshop-related records
                // ==============================================
                $stmt = $db->prepare("DELETE FROM workshop_attendance WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM workshop_feedback WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM workshop_registrations WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 5. Delete assignment submissions
                // ==============================================
                $stmt = $db->prepare("DELETE FROM assignment_submissions WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 6. Delete test attempts and related data
                // ==============================================
                $stmt = $db->prepare("DELETE ta FROM test_answers ta INNER JOIN test_attempts t ON ta.attempt_id = t.id WHERE t.student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM test_attempts WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 7. Delete doubt records
                // ==============================================
                $stmt = $db->prepare("DELETE dr FROM doubt_responses dr INNER JOIN doubts d ON dr.doubt_id = d.id WHERE d.student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM doubts WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 8. Delete leave applications
                // ==============================================
                $stmt = $db->prepare("DELETE lah FROM leave_application_history lah INNER JOIN leave_applications la ON lah.application_id = la.id WHERE la.student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM leave_applications WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 9. Delete fee-related records
                // ==============================================
                $stmt = $db->prepare("DELETE FROM fee_installments WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM fee_reminder_logs WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM transactions WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 10. Delete weekly feedback and report cards
                // ==============================================
                $stmt = $db->prepare("DELETE FROM weekly_feedback WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM weekly_report_cards WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 11. Delete chat-related records
                // ==============================================
                $stmt = $db->prepare("SELECT DISTINCT c.id FROM conversations c 
                                       INNER JOIN conversation_members cm ON c.id = cm.conversation_id 
                                       WHERE cm.user_id = ?");
                $stmt->execute([$user_id]);
                $conversations = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($conversations)) {
                    $placeholders = str_repeat('?,', count($conversations) - 1) . '?';
                    
                    $stmt = $db->prepare("DELETE FROM clear_chat_history WHERE conversation_id IN ($placeholders)");
                    $stmt->execute($conversations);
                    
                    $stmt = $db->prepare("DELETE FROM messages WHERE conversation_id IN ($placeholders)");
                    $stmt->execute($conversations);
                    
                    $stmt = $db->prepare("DELETE FROM conversation_members WHERE conversation_id IN ($placeholders)");
                    $stmt->execute($conversations);
                    
                    $stmt = $db->prepare("DELETE FROM conversations WHERE id IN ($placeholders) AND (created_by = ? OR type = 'one_to_one')");
                    $stmt->execute(array_merge($conversations, [$user_id]));
                }
                
                $stmt = $db->prepare("DELETE FROM conversation_members WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // ==============================================
                // 12. Delete notifications
                // ==============================================
                if ($user_id) {
                    $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                }
                
                // ==============================================
                // 13. Delete student documents and files
                // ==============================================
                $stmt = $db->prepare("SELECT * FROM student_documents WHERE student_id = ?");
                $stmt->execute([$student_id]);
                $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($documents as $doc) {
                    if (isset($doc['file_path']) && file_exists($doc['file_path'])) {
                        unlink($doc['file_path']);
                    }
                }
                
                $stmt = $db->prepare("DELETE FROM student_documents WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 14. Delete student status logs and batch history
                // ==============================================
                $stmt = $db->prepare("DELETE FROM student_status_log WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                $stmt = $db->prepare("DELETE FROM student_batch_history WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 15. Delete results sync records
                // ==============================================
                $stmt = $db->prepare("DELETE FROM results_sync WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 16. Finally delete the student record
                // ==============================================
                $stmt = $db->prepare("DELETE FROM students WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // ==============================================
                // 17. Delete the user account if it exists
                // ==============================================
                if ($user_id) {
                    $stmt = $db->prepare("DELETE FROM conversation_members WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $db->prepare("DELETE FROM user_lock_logs WHERE user_id = ? OR performed_by = ?");
                    $stmt->execute([$user_id, $user_id]);
                    
                    $stmt = $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                
                // Commit the transaction
                $db->commit();
                
                $_SESSION['success_message'] = "Student and all related records deleted successfully!";
                header("Location: students_list.php");
                exit();
                
            } catch (Exception $e) {
                // Rollback the transaction on error
                $db->rollBack();
                throw $e;
            }
        } else {
            // User cancelled the deletion
            $_SESSION['info_message'] = "Deletion cancelled.";
            header("Location: students_list.php");
            exit();
        }
    }
    
} catch(PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header("Location: students_list.php");
    exit();
} catch(Exception $e) {
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    header("Location: students_list.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#D2C1B6">
    <title>Delete Student - ASD Academy</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* ============================================================
           PREMIUM ANIMATIONS & EFFECTS
           ============================================================ */
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(35, 76, 106, 0.4); }
            50% { box-shadow: 0 0 0 15px rgba(35, 76, 106, 0); }
        }
        
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        
        @keyframes warningPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }
        
        @keyframes ripple {
            0% { transform: scale(0); opacity: 0.6; }
            100% { transform: scale(4); opacity: 0; }
        }
        
        @keyframes countUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes borderGlow {
            0%, 100% { border-color: rgba(35, 76, 106, 0.12); }
            50% { border-color: rgba(35, 76, 106, 0.3); }
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Page Background */
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: linear-gradient(180deg, #D2C1B6 0%, #FFFFFF 60%);
            background-attachment: fixed;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: 
                radial-gradient(ellipse at 20% 50%, rgba(69, 104, 130, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(27, 60, 83, 0.03) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* ============================================================
           MAIN CONTENT
           ============================================================ */
        .main-content {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            padding: 100px 2rem 3rem;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            max-width: 1200px;
            margin: 0 auto;
            animation: fadeIn 0.6s ease-out;
        }

        .main-content.shifted {
            margin-left: 260px;
            max-width: calc(1200px - 260px);
        }

        /* ============================================================
           PAGE HEADER - Animated Entry
           ============================================================ */
        .page-header {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            margin-bottom: 2.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid rgba(35, 76, 106, 0.08);
            animation: slideInLeft 0.5s ease-out;
        }

        .page-header-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(35, 76, 106, 0.1), rgba(69, 104, 130, 0.05));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #234C6A;
            font-size: 1.5rem;
            flex-shrink: 0;
            box-shadow: 0 4px 20px rgba(35, 76, 106, 0.08);
            position: relative;
            overflow: hidden;
            animation: float 4s ease-in-out infinite;
        }
        
        .page-header-icon::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 3s infinite;
            background-size: 200% 100%;
        }

        .page-header-text h1 {
            font-size: 1.85rem;
            font-weight: 800;
            color: #1B3C53;
            margin: 0 0 0.25rem 0;
            letter-spacing: -0.02em;
            animation: fadeInUp 0.5s ease-out 0.1s both;
        }

        .page-header-text p {
            font-size: 0.875rem;
            color: #456882;
            margin: 0;
            animation: fadeInUp 0.5s ease-out 0.2s both;
        }

        /* ============================================================
           DASHBOARD CARD
           ============================================================ */
        .dashboard-card {
            background: #FFFFFF;
            border-radius: 24px;
            box-shadow: 
                0 4px 24px rgba(27, 60, 83, 0.06), 
                0 1px 4px rgba(27, 60, 83, 0.04),
                0 0 0 1px rgba(210, 193, 182, 0.2);
            padding: 2.5rem;
            margin-bottom: 2rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: fadeInUp 0.6s ease-out 0.2s both;
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #1B3C53, #234C6A, #456882, #D2C1B6);
            background-size: 200% 100%;
            animation: gradientShift 4s ease infinite;
            opacity: 0.3;
        }
        
        .dashboard-card:hover {
            box-shadow: 
                0 8px 32px rgba(27, 60, 83, 0.1), 
                0 2px 8px rgba(27, 60, 83, 0.06);
        }

        /* ============================================================
           STUDENT INFO CARD - Slide Up Animation
           ============================================================ */
        .student-info-card {
            background: #FFFFFF;
            border-radius: 18px;
            border-left: 5px solid #234C6A;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 16px rgba(27, 60, 83, 0.05);
            animation: fadeInUp 0.5s ease-out 0.3s both;
            transition: all 0.3s ease;
        }
        
        .student-info-card:hover {
            box-shadow: 0 4px 24px rgba(27, 60, 83, 0.1);
            transform: translateY(-2px);
        }

        .student-info-card .section-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #456882;
            margin-bottom: 0.35rem;
            opacity: 0.8;
        }

        .student-info-card .section-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1B3C53;
        }

        /* Status Badge with Pulse */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 1rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .status-badge.active {
            background: rgba(69, 104, 130, 0.12);
            color: #234C6A;
        }

        .status-badge .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
        }

        .status-badge.active .status-dot {
            background: #456882;
            animation: pulse 2s infinite;
        }

        .status-badge.dropped {
            background: rgba(239, 68, 68, 0.1);
            color: #991B1B;
        }

        .status-badge.dropped .status-dot {
            background: #EF4444;
        }

        .status-badge.completed {
            background: rgba(27, 60, 83, 0.08);
            color: #1B3C53;
        }

        .status-badge.completed .status-dot {
            background: #1B3C53;
        }

        /* ============================================================
           WARNING SECTION - Animated Border
           ============================================================ */
        .warning-section {
            background: linear-gradient(135deg, rgba(210, 193, 182, 0.25), rgba(69, 104, 130, 0.08));
            border: 2px solid rgba(35, 76, 106, 0.12);
            border-radius: 18px;
            padding: 2rem;
            margin-bottom: 2rem;
            animation: fadeInUp 0.5s ease-out 0.4s both, borderGlow 4s ease-in-out infinite;
            transition: all 0.3s ease;
            position: relative;
        }

        .warning-section .warning-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .warning-section .warning-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: rgba(27, 60, 83, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1B3C53;
            font-size: 1.4rem;
            flex-shrink: 0;
            animation: warningPulse 3s ease-in-out infinite;
        }

        .warning-section .warning-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1B3C53;
            margin: 0;
        }

        .warning-section .warning-subtitle {
            font-size: 0.8rem;
            color: #456882;
            margin-top: 0.15rem;
        }

        .warning-list {
            list-style: none;
            padding: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem 2rem;
        }

        .warning-list li {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.82rem;
            color: #234C6A;
            padding: 0.5rem 0.75rem;
            border-radius: 10px;
            transition: all 0.3s ease;
            cursor: default;
        }
        
        .warning-list li:hover {
            background: rgba(210, 193, 182, 0.15);
            transform: translateX(4px);
        }

        .warning-list li .check-icon {
            color: #456882;
            font-size: 0.7rem;
            flex-shrink: 0;
            opacity: 0.7;
            transition: all 0.3s ease;
        }
        
        .warning-list li:hover .check-icon {
            opacity: 1;
            transform: scale(1.2);
        }

        .warning-footer {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(35, 76, 106, 0.1);
            font-weight: 700;
            font-size: 0.875rem;
            color: #1B3C53;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .warning-footer i {
            animation: warningPulse 2s ease-in-out infinite;
        }

        /* ============================================================
           CONFIRMATION CHECKBOX - Ripple Effect
           ============================================================ */
        .confirm-card {
            background: #F8FAFC;
            border: 1px solid rgba(35, 76, 106, 0.1);
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            animation: fadeInUp 0.5s ease-out 0.5s both;
            position: relative;
            overflow: hidden;
        }

        .confirm-card:hover {
            border-color: rgba(35, 76, 106, 0.25);
            background: #F1F5F9;
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(27, 60, 83, 0.06);
        }

        .confirm-card input[type="checkbox"] {
            appearance: none;
            width: 24px;
            height: 24px;
            border: 2px solid #456882;
            border-radius: 7px;
            flex-shrink: 0;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .confirm-card input[type="checkbox"]:checked {
            background: #1B3C53;
            border-color: #1B3C53;
            animation: pulse 0.4s ease;
        }

        .confirm-card input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #D2C1B6;
            font-size: 15px;
            font-weight: bold;
        }

        .confirm-card label {
            font-size: 0.9rem;
            font-weight: 500;
            color: #1B3C53;
            cursor: pointer;
            user-select: none;
            line-height: 1.5;
        }

        /* ============================================================
           BUTTONS - Premium Effects
           ============================================================ */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            padding: 0.95rem 2.25rem;
            border-radius: 16px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            border: none;
            min-height: 54px;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.01em;
        }
        
        .btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn:active::after {
            width: 300px;
            height: 300px;
        }

        .btn-cancel {
            background: #D2C1B6;
            color: #1B3C53;
            flex: 1;
        }

        .btn-cancel:hover {
            background: #C4B3A8;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.12);
        }

        .btn-delete {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            background-size: 200% 200%;
            animation: gradientShift 6s ease infinite;
            color: #FFFFFF;
            flex: 1;
            box-shadow: 0 6px 20px rgba(35, 76, 106, 0.3);
        }

        .btn-delete:hover:not(:disabled) {
            background: linear-gradient(135deg, #234C6A, #456882);
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(35, 76, 106, 0.4);
        }
        
        .btn-delete:not(:disabled):active {
            transform: translateY(-1px) scale(0.98);
        }

        .btn-delete:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none;
            animation: none;
            filter: grayscale(0.5);
        }

        /* ============================================================
           ALERT MESSAGES - Slide Animation
           ============================================================ */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            border-left: 4px solid;
            animation: slideInLeft 0.4s ease-out;
            backdrop-filter: blur(8px);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.06);
            border-color: #EF4444;
            color: #991B1B;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.06);
            border-color: #10B981;
            color: #065F46;
        }

        .alert-info {
            background: rgba(69, 104, 130, 0.06);
            border-color: #456882;
            color: #1B3C53;
        }

        /* ============================================================
           TOP HEADER - Glass Effect
           ============================================================ */
        .top-header {
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            z-index: 50;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-bottom: 1px solid rgba(210, 193, 182, 0.3);
            padding: 0.85rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .top-header.shifted {
            left: 260px;
        }

        .top-header .menu-btn {
            display: none;
            background: none;
            border: none;
            color: #1B3C53;
            font-size: 1.4rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .top-header .menu-btn:hover {
            background: rgba(27, 60, 83, 0.06);
        }

        .header-title-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-title-group i {
            color: #234C6A;
            font-size: 1.2rem;
        }

        .header-title-group span {
            color: #1B3C53;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .admin-badge {
            background: rgba(210, 193, 182, 0.3);
            padding: 0.45rem 1.1rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            color: #1B3C53;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid rgba(210, 193, 182, 0.5);
            transition: all 0.3s ease;
        }
        
        .admin-badge:hover {
            background: rgba(210, 193, 182, 0.4);
            transform: translateY(-1px);
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0 !important;
                max-width: 100% !important;
                padding: 90px 1.5rem 2rem !important;
            }
            
            .main-content.shifted {
                margin-left: 0 !important;
                max-width: 100% !important;
            }
            
            .warning-list {
                grid-template-columns: 1fr;
            }
            
            .dashboard-card {
                padding: 1.75rem;
            }
            
            .student-info-card {
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .top-header .menu-btn {
                display: block;
            }
            
            .top-header.shifted {
                left: 0;
            }
            
            .main-content {
                padding: 85px 1rem 1.5rem !important;
            }
            
            .dashboard-card {
                padding: 1.25rem;
                border-radius: 20px;
            }
            
            .student-info-card {
                padding: 1.25rem;
            }
            
            .warning-section {
                padding: 1.25rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            
            .page-header-text h1 {
                font-size: 1.5rem;
            }
            
            .btn {
                width: 100%;
                font-size: 0.85rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .warning-list li {
                font-size: 0.78rem;
            }
        }

        @media (max-width: 480px) {
            .dashboard-card {
                padding: 1rem;
                border-radius: 16px;
            }
            
            .warning-list li {
                font-size: 0.72rem;
                padding: 0.4rem 0.5rem;
            }
            
            .confirm-card {
                padding: 1rem;
                gap: 0.75rem;
            }
            
            .confirm-card label {
                font-size: 0.78rem;
            }
            
            .page-header-icon {
                width: 48px;
                height: 48px;
                border-radius: 14px;
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- ============================================
    SIDEBAR - UNTOUCHED (ORIGINAL DESIGN)
    ============================================ -->
    <?php include '../sidebar.php'; ?>

    <!-- ============================================
    TOP HEADER - Glass Effect
    ============================================ -->
    <header class="top-header" id="topHeader">
        <div class="header-title-group">
            <button class="menu-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <i class="fas fa-trash-alt"></i>
            <span>Delete Student</span>
        </div>
        <span class="admin-badge">
            <i class="fas fa-shield-alt"></i>
            Admin
        </span>
    </header>

    <!-- ============================================
    MAIN CONTENT - Dashboard Layout
    ============================================ -->
    <div class="main-content" id="mainContent">
        
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-icon">
                <i class="fas fa-user-times"></i>
            </div>
            <div class="page-header-text">
                <h1>Delete Student</h1>
                <p>Permanently remove student and all related records</p>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($_SESSION['error_message']) ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($_SESSION['success_message']) ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['info_message'])): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <?= htmlspecialchars($_SESSION['info_message']) ?>
            </div>
            <?php unset($_SESSION['info_message']); ?>
        <?php endif; ?>

        <!-- Main Dashboard Card -->
        <div class="dashboard-card">
            
            <!-- Student Information -->
            <div class="student-info-card">
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
                    <i class="fas fa-user-graduate" style="color: #234C6A; font-size: 1.1rem;"></i>
                    <span style="font-weight: 700; color: #1B3C53; font-size: 0.95rem;">Student Information</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 2rem;">
                    <div>
                        <p class="section-label">Student ID</p>
                        <p class="section-value"><?= htmlspecialchars($student['student_id']) ?></p>
                    </div>
                    <div>
                        <p class="section-label">Name</p>
                        <p class="section-value"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                    </div>
                    <div>
                        <p class="section-label">Email</p>
                        <p class="section-value"><?= htmlspecialchars($student['email'] ?: 'N/A') ?></p>
                    </div>
                    <div>
                        <p class="section-label">Phone</p>
                        <p class="section-value"><?= htmlspecialchars($student['phone_number'] ?: 'N/A') ?></p>
                    </div>
                    <div>
                        <p class="section-label">Status</p>
                        <span class="status-badge <?= $student['current_status'] ?>">
                            <span class="status-dot"></span>
                            <?= htmlspecialchars(ucfirst($student['current_status'])) ?>
                        </span>
                    </div>
                    <div>
                        <p class="section-label">Batch</p>
                        <p class="section-value"><?= htmlspecialchars($student['batch_name'] ?: 'N/A') ?></p>
                    </div>
                    <div>
                        <p class="section-label">Enrollment Date</p>
                        <p class="section-value"><?= !empty($student['enrollment_date']) ? date('M j, Y', strtotime($student['enrollment_date'])) : 'N/A' ?></p>
                    </div>
                    <div>
                        <p class="section-label">User Account</p>
                        <p class="section-value">
                            <?= $user_id ? '<span style="color: #10B981;"><i class="fas fa-check-circle"></i> Linked</span>' : '<span style="color: #94A3B8;"><i class="fas fa-times-circle"></i> No account</span>' ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Warning Section -->
            <div class="warning-section">
                <div class="warning-header">
                    <div class="warning-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h3 class="warning-title">Critical Warning</h3>
                        <p class="warning-subtitle">Review carefully before proceeding</p>
                    </div>
                </div>
                
                <p style="font-size: 0.85rem; color: #234C6A; margin-bottom: 1rem; font-weight: 500;">
                    This action will permanently delete the following records:
                </p>
                
                <ul class="warning-list">
                    <li><i class="fas fa-check-circle check-icon"></i> Student record (students table)</li>
                    <li><i class="fas fa-check-circle check-icon"></i> User account (users table)</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All attendance records</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All exam results & enrollments</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All assignment submissions</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All test attempts & answers</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All feedback submissions</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All workshop registrations</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All doubt questions & responses</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All leave applications</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All fee installments & transactions</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All chat conversations & messages</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All uploaded documents</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All notifications</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All status history</li>
                    <li><i class="fas fa-check-circle check-icon"></i> All weekly report cards</li>
                </ul>
                
                <p class="warning-footer">
                    <i class="fas fa-exclamation-triangle" style="color: #1B3C53;"></i>
                    This action is irreversible and will permanently delete all related data from the database!
                </p>
            </div>

            <!-- Confirmation Checkbox -->
            <div class="confirm-card" onclick="document.getElementById('confirmUnderstanding').click()">
                <input type="checkbox" id="confirmUnderstanding" onclick="event.stopPropagation()">
                <label for="confirmUnderstanding">
                    I understand that this action cannot be undone and I have verified this is the correct student to delete.
                </label>
            </div>

            <!-- Action Buttons -->
            <form method="POST" style="display: flex; gap: 1rem;" class="btn-group">
                <a href="students_list.php" class="btn btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" name="confirm_delete" id="deleteButton" class="btn btn-delete" disabled>
                    <i class="fas fa-trash-alt"></i> Permanently Delete Student
                </button>
            </form>
            
        </div>
    </div>

    <!-- ============================================
    SCRIPTS
    ============================================ -->
    <script>
        // Sidebar Toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const topHeader = document.getElementById('topHeader');
            
            if (sidebar) sidebar.classList.toggle('active');
            if (mainContent) mainContent.classList.toggle('shifted');
            if (topHeader) topHeader.classList.toggle('shifted');
        }

        // Close sidebar on outside click (mobile)
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.menu-btn');
            
            if (sidebar && window.innerWidth <= 768 && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && !(menuBtn && menuBtn.contains(e.target))) {
                    toggleSidebar();
                }
            }
        });

        // Close sidebar on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                if (sidebar && sidebar.classList.contains('active')) {
                    toggleSidebar();
                }
            }
        });

        // Delete Button State
        const checkbox = document.getElementById('confirmUnderstanding');
        const deleteBtn = document.getElementById('deleteButton');
        
        if (checkbox && deleteBtn) {
            checkbox.addEventListener('change', function() {
                deleteBtn.disabled = !this.checked;
                if (this.checked) {
                    deleteBtn.style.animation = 'none';
                    deleteBtn.offsetHeight;
                    deleteBtn.style.animation = 'pulse 0.5s ease';
                }
            });
        }

        // Confirmation Dialog
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function(e) {
                if (!confirm('⚠️ FINAL WARNING\n\nAre you absolutely sure you want to delete this student and ALL related data?\n\nThis action will permanently remove:\n• Student record\n• User account\n• All attendance, exams, assignments\n• All fees and transactions\n• All chat history\n• All documents\n\nThis action CANNOT be undone!')) {
                    e.preventDefault();
                }
            });
        }

        // Initial State
        if (window.innerWidth > 768) {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const topHeader = document.getElementById('topHeader');
            if (sidebar) sidebar.classList.add('active');
            if (mainContent) mainContent.classList.add('shifted');
            if (topHeader) topHeader.classList.add('shifted');
        }

        // Handle Resize
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('mainContent');
                const topHeader = document.getElementById('topHeader');
                
                if (window.innerWidth > 768) {
                    if (sidebar) sidebar.classList.add('active');
                    if (mainContent) mainContent.classList.add('shifted');
                    if (topHeader) topHeader.classList.add('shifted');
                } else {
                    if (sidebar) sidebar.classList.remove('active');
                    if (mainContent) mainContent.classList.remove('shifted');
                    if (topHeader) topHeader.classList.remove('shifted');
                }
            }, 250);
        });

        // Handle orientation change
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                if (window.innerWidth <= 768) {
                    const sidebar = document.getElementById('sidebar');
                    const mainContent = document.getElementById('mainContent');
                    const topHeader = document.getElementById('topHeader');
                    if (sidebar) sidebar.classList.remove('active');
                    if (mainContent) mainContent.classList.remove('shifted');
                    if (topHeader) topHeader.classList.remove('shifted');
                }
            }, 300);
        });
        
        // Intersection Observer for scroll animations
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.student-info-card, .warning-section, .confirm-card').forEach(el => {
            observer.observe(el);
        });
    </script>
</body>
</html>