<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

// Check if user is logged in as trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../logout_t.php");
    exit;
}

require_once '../db_connection.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get trainer details
    $trainer_user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT t.*, u.email FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
    $stmt->execute([$trainer_user_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no trainer row found, provide a safe fallback
    if ($trainer === false) {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$trainer_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $trainer = [
                'id' => null,
                'user_id' => $trainer_user_id,
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => '',
                'address' => '',
                'qualifications' => '',
                'specialization' => '',
                'joining_date' => date('Y-m-d'),
                'years_of_experience' => 0,
                'bio' => '',
                'profile_picture' => '',
                'is_active' => 1
            ];
        } else {
            header("Location: ../logout_t.php");
            exit;
        }
    }

    // Helper function to get profile picture URL (same as t_sidebar.php)
    function getProfilePictureUrl($path) {
        $clean = trim(str_replace('\\', '/', (string)$path));
        if ($clean === '') {
            return '';
        }

        if (preg_match('/^(https?:)?\/\//', $clean) || str_starts_with($clean, 'data:image/')) {
            return htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
        }

        $clean = ltrim($clean, './');

        $uploadsPos = strpos($clean, 'uploads/');
        if ($uploadsPos !== false) {
            $uploadsPath = substr($clean, $uploadsPos);
            return htmlspecialchars('../' . $uploadsPath, ENT_QUOTES, 'UTF-8');
        }

        return htmlspecialchars('../uploads/profiles/' . basename($clean), ENT_QUOTES, 'UTF-8');
    }

    // Get trainer documents
    if ($trainer['id']) {
        $doc_stmt = $db->prepare("SELECT * FROM trainer_documents WHERE trainer_id = ?");
        $doc_stmt->execute([$trainer['id']]);
        $documents = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $documents = [];
    }

    // Get assigned batches
    if ($trainer['id']) {
        $batches_stmt = $db->prepare("
            SELECT b.batch_id, b.batch_name, b.start_date, b.end_date, b.status, 
                   b.time_slot, b.mode, b.max_students, b.current_enrollment,
                   COUNT(DISTINCT s.student_id) as student_count
            FROM batches b 
            LEFT JOIN students s ON b.batch_id = s.batch_name 
            WHERE b.batch_mentor_id = ? 
            GROUP BY b.batch_id
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $batches_stmt->execute([$trainer['id']]);
        $batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $batches = [];
    }

    // Get batch statistics
    $batch_stats = [
        'total' => count($batches),
        'ongoing' => 0,
        'upcoming' => 0,
        'completed' => 0,
        'cancelled' => 0
    ];
    
    foreach ($batches as $batch) {
        if (isset($batch_stats[$batch['status']])) {
            $batch_stats[$batch['status']]++;
        }
    }

    // Get student statistics
    if ($trainer['id']) {
        $student_stats_stmt = $db->prepare("
            SELECT COUNT(DISTINCT s.student_id) as total_students
            FROM students s
            LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
            LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
            LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
            WHERE (b1.batch_mentor_id = ? OR b2.batch_mentor_id = ? OR b3.batch_mentor_id = ?)
            AND s.current_status != 'dropped'
        ");
        $student_stats_stmt->execute([$trainer['id'], $trainer['id'], $trainer['id']]);
        $student_stats_result = $student_stats_stmt->fetch(PDO::FETCH_ASSOC);
        $total_students = $student_stats_result['total_students'] ?? 0;

        $active_students_stmt = $db->prepare("
            SELECT COUNT(DISTINCT s.student_id) as active_students
            FROM students s
            LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
            LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
            LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
            WHERE (b1.batch_mentor_id = ? OR b2.batch_mentor_id = ? OR b3.batch_mentor_id = ?)
            AND s.current_status = 'active'
        ");
        $active_students_stmt->execute([$trainer['id'], $trainer['id'], $trainer['id']]);
        $active_students_result = $active_students_stmt->fetch(PDO::FETCH_ASSOC);
        $active_students = $active_students_result['active_students'] ?? 0;
    } else {
        $total_students = 0;
        $active_students = 0;
    }

    // Get profile picture URL
    $profile_picture_url = getProfilePictureUrl($trainer['profile_picture'] ?? '');

    // Get notifications
    $notifications = [];
    $notifications_count = 0;
    try {
        $notif_stmt = $db->prepare("
            SELECT id, title, message, type, reference_id, is_read, created_at 
            FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $notif_stmt->execute([$trainer_user_id]);
        $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Count unread
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $count_stmt->execute([$trainer_user_id]);
        $notifications_count = $count_stmt->fetchColumn();
    } catch (PDOException $e) {
        // Table might not exist, ignore
    }

    // Mark notification as read via AJAX (handled at bottom)
    if (isset($_POST['mark_notification_read']) && isset($_POST['notification_id'])) {
        $notif_id = intval($_POST['notification_id']);
        $update_stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $update_stmt->execute([$notif_id, $trainer_user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Mark all notifications as read
    if (isset($_POST['mark_all_read'])) {
        $update_stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $update_stmt->execute([$trainer_user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Handle profile update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $name = $_POST['name'] ?? $trainer['name'];
        $email = $_POST['email'] ?? $trainer['email'];
        $phone = $_POST['phone'] ?? $trainer['phone'];
        $specialization = $_POST['specialization'] ?? $trainer['specialization'];
        $address = $_POST['address'] ?? $trainer['address'];
        $qualifications = $_POST['qualifications'] ?? $trainer['qualifications'];
        $bio = $_POST['bio'] ?? $trainer['bio'];
        $years_of_experience = $_POST['years_of_experience'] ?? $trainer['years_of_experience'];
        
        if ($trainer['id']) {
            $update_stmt = $db->prepare("
                UPDATE trainers 
                SET name = ?, email = ?, phone = ?, specialization = ?, 
                    address = ?, qualifications = ?, bio = ?, years_of_experience = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([
                $name, $email, $phone, $specialization, 
                $address, $qualifications, $bio, $years_of_experience,
                $trainer['id']
            ]);
        } else {
            $insert_stmt = $db->prepare("
                INSERT INTO trainers (user_id, name, email, phone, specialization, 
                    address, qualifications, bio, years_of_experience, joining_date, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
            ");
            $insert_stmt->execute([
                $trainer_user_id, $name, $email, $phone, $specialization,
                $address, $qualifications, $bio, $years_of_experience
            ]);
            $trainer['id'] = $db->lastInsertId();
        }
        
        $update_user_stmt = $db->prepare("UPDATE users SET email = ?, name = ? WHERE id = ?");
        $update_user_stmt->execute([$email, $name, $trainer_user_id]);
        
        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array(strtolower($file_ext), $allowed_ext)) {
                $file_name = 'trainer_' . $trainer['id'] . '_' . time() . '.' . $file_ext;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $file_path)) {
                    $stored_path = 'uploads/profiles/' . $file_name;
                    $update_pic_stmt = $db->prepare("UPDATE trainers SET profile_picture = ? WHERE id = ?");
                    $update_pic_stmt->execute([$stored_path, $trainer['id']]);
                    $trainer['profile_picture'] = $stored_path;
                    $profile_picture_url = getProfilePictureUrl($stored_path);
                }
            }
        }
        
        // Refresh trainer data
        $stmt = $db->prepare("SELECT t.*, u.email FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
        $stmt->execute([$trainer_user_id]);
        $trainer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($trainer === false) {
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$trainer_user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $trainer = [
                    'id' => null,
                    'user_id' => $trainer_user_id,
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'phone' => '',
                    'address' => '',
                    'qualifications' => '',
                    'specialization' => '',
                    'joining_date' => date('Y-m-d'),
                    'years_of_experience' => 0,
                    'bio' => '',
                    'profile_picture' => '',
                    'is_active' => 1
                ];
            }
        }
        
        $profile_picture_url = getProfilePictureUrl($trainer['profile_picture'] ?? '');
        $success_message = "Profile updated successfully!";
    }

    // Handle document upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
        $document_type = $_POST['document_type'] ?? 'other';
        $trainer_id = $trainer['id'];
        
        if ($trainer_id && isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/trainer_documents/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_ext = pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION);
            $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'xls', 'xlsx'];
            
            if (in_array(strtolower($file_ext), $allowed_ext)) {
                $file_name = 'trainer_' . $trainer_id . '_' . time() . '_' . $document_type . '.' . $file_ext;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['document_file']['tmp_name'], $file_path)) {
                    $insert_doc_stmt = $db->prepare("
                        INSERT INTO trainer_documents (trainer_id, document_type, file_path, uploaded_at)
                        VALUES (?, ?, ?, NOW())
                    ");
                    $insert_doc_stmt->execute([$trainer_id, $file_path]);
                    
                    $doc_stmt = $db->prepare("SELECT * FROM trainer_documents WHERE trainer_id = ?");
                    $doc_stmt->execute([$trainer['id']]);
                    $documents = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $doc_success_message = "Document uploaded successfully!";
                }
            }
        }
    }

    // Handle document deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
        $document_id = $_POST['document_id'] ?? 0;
        $trainer_id = $trainer['id'];
        
        if ($trainer_id && $document_id) {
            $doc_stmt = $db->prepare("SELECT file_path FROM trainer_documents WHERE document_id = ? AND trainer_id = ?");
            $doc_stmt->execute([$document_id, $trainer_id]);
            $doc = $doc_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($doc) {
                if (file_exists($doc['file_path'])) {
                    unlink($doc['file_path']);
                }
                
                $delete_stmt = $db->prepare("DELETE FROM trainer_documents WHERE document_id = ? AND trainer_id = ?");
                $delete_stmt->execute([$document_id, $trainer_id]);
                
                $doc_stmt = $db->prepare("SELECT * FROM trainer_documents WHERE trainer_id = ?");
                $doc_stmt->execute([$trainer['id']]);
                $documents = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $doc_success_message = "Document deleted successfully!";
            }
        }
    }

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>My Profile | ASD Academy Trainer Portal</title>
    
    <!-- Optimized: Load only essential CSS first -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome with defer for faster loading -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="all" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    
    <!-- AOS with defer -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet" media="all" onload="this.media='all'">
    <noscript><link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet"></noscript>
    
    <style>
        /* Critical CSS - inline for faster render */
        :root {
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 46%, #456882 100%);
            --brand-dark: #1B3C53;
            --brand-primary: #234C6A;
            --brand-secondary: #456882;
            --brand-soft: #D2C1B6;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            background: 
                radial-gradient(circle at 14% 10%, rgba(27,60,83,.13), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
                linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
            color: #1B3C53;
        }
        
        .glass-card {
            background: rgba(255,255,255,.90);
            border: 1px solid rgba(226,232,240,.82);
            border-radius: 24px;
            box-shadow: 0 18px 42px rgba(15,23,42,.075);
            backdrop-filter: blur(16px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .glass-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 48px rgba(15,23,42,.11);
        }
        
        .gradient-text {
            background: var(--dash-main);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }
        
        .gradient-bg {
            background: var(--dash-main);
        }
        
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            object-fit: cover;
        }
        
        .profile-avatar-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: white;
            background: var(--primary-gradient);
        }
        
        .profile-hero-shell {
            position: relative;
            overflow: hidden;
            border-radius: 30px;
            background: var(--dash-main);
            box-shadow: 0 24px 58px rgba(27,60,83,.25);
            border: 1px solid rgba(255,255,255,.20);
        }
        
        .profile-hero-shell::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 430px;
            right: -135px;
            top: -145px;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            filter: blur(2px);
        }
        
        .profile-hero-shell::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }
        
        .profile-hero-shell > * { position: relative; z-index: 1; }
        
        .profile-hero-shell h1,
        .profile-hero-shell p,
        .profile-hero-shell span,
        .profile-hero-shell i,
        .profile-hero-shell div {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }
        
        .feature-shell {
            position: relative;
            overflow: hidden;
            border-radius: 26px;
        }
        
        .feature-shell::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
        }
        
        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .36rem .72rem;
            border-radius: 999px;
            margin-bottom: .85rem;
            background: rgba(255,255,255,.84);
            border: 1px solid rgba(226,232,240,.9);
            color: #1B3C53;
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            box-shadow: 0 6px 18px rgba(15,23,42,.05);
        }
        
        .skill-tag {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.24);
            backdrop-filter: blur(12px);
            color: #fff;
            border-radius: 20px;
            font-size: 14px;
            margin: 4px;
            font-weight: 800;
            transition: all 0.3s ease;
        }
        
        .skill-tag:hover {
            transform: translateY(-2px);
        }
        
        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: white;
            font-size: 18px;
            box-shadow: 0 14px 28px rgba(15,23,42,.12);
        }
        
        .info-icon.blue { background: linear-gradient(135deg, #234C6A 0%, #456882 100%); }
        .info-icon.green { background: linear-gradient(135deg, #10b981 0%, #22c55e 100%); }
        .info-icon.purple { background: var(--dash-main); }
        .info-icon.yellow { background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%); }
        
        .document-icon {
            width: 50px;
            height: 50px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 16px;
            color: white;
            font-size: 20px;
            box-shadow: 0 14px 28px rgba(15,23,42,.12);
        }
        
        .document-icon.pdf { background: linear-gradient(135deg, #ef4444, #f87171); }
        .document-icon.word { background: linear-gradient(135deg, #234C6A, #234C6A); }
        .document-icon.image { background: linear-gradient(135deg, #234C6A, #a78bfa); }
        .document-icon.other { background: linear-gradient(135deg, #6b7280, #9ca3af); }
        
        .tab-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            flex: 0 0 auto;
            white-space: nowrap;
            min-height: 46px;
            padding: .7rem 1rem;
            border-radius: 16px;
            color: #5f6f7d;
            background: rgba(255,255,255,.72);
            border: 1px solid rgba(210,193,182,.32);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            color: #ffffff;
            background: var(--dash-main);
            box-shadow: 0 14px 30px rgba(27,60,83,.22);
            border-color: transparent;
        }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .status-ongoing {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .status-upcoming {
            background: rgba(59, 130, 246, 0.15);
            color: #234C6A;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .status-completed {
            background: rgba(249, 115, 22, 0.15);
            color: #ea580c;
            border: 1px solid rgba(249, 115, 22, 0.2);
        }
        
        .status-cancelled {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .empty-state {
            padding: 40px 20px;
            text-align: center;
            border: 1.5px dashed rgba(69,104,130,.35);
            border-radius: 22px;
            background: 
                radial-gradient(circle at 50% 0%, rgba(69,104,130,.08), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,.92), rgba(238,243,246,.76));
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .floating-shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.1;
            z-index: -1;
            pointer-events: none;
        }
        
        .modal-overlay {
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            transform: scale(0.9);
            opacity: 0;
            transition: all 0.3s ease;
            border-radius: 26px;
            border: 1px solid rgba(226,232,240,.85);
            box-shadow: 0 30px 80px rgba(15,23,42,.26);
            background: 
                radial-gradient(circle at 96% 4%, rgba(69,104,130,.08), transparent 34%),
                linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.92));
        }
        
        .modal-content.active {
            transform: scale(1);
            opacity: 1;
        }
        
        .hover-lift {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .ripple-effect {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        
        @keyframes ripple {
            to { transform: scale(4); opacity: 0; }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
            opacity: 0;
        }
        
        @keyframes fadeIn {
            to { opacity: 1; }
        }
        
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        
        /* Notification Dropdown */
        .notification-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 380px;
            max-width: calc(100vw - 24px);
            max-height: 480px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(16px);
            border-radius: 20px;
            border: 1px solid rgba(226,232,240,.85);
            box-shadow: 0 24px 60px rgba(15,23,42,.18);
            overflow: hidden;
            z-index: 60;
            transform-origin: top right;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .notification-dropdown.hidden {
            opacity: 0;
            transform: scale(0.95) translateY(-8px);
            pointer-events: none;
        }
        
        .notification-dropdown .dropdown-header {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(226,232,240,.7);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,.6);
        }
        
        .notification-dropdown .dropdown-header h4 {
            font-weight: 800;
            font-size: 15px;
            color: #1B3C53;
        }
        
        .notification-dropdown .dropdown-header button {
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px 12px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .notification-dropdown .dropdown-header button:hover {
            background: rgba(27,60,83,.08);
            color: #1B3C53;
        }
        
        .notification-dropdown .notification-list {
            max-height: 360px;
            overflow-y: auto;
            padding: 4px 0;
        }
        
        .notification-dropdown .notification-list::-webkit-scrollbar {
            width: 4px;
        }
        
        .notification-dropdown .notification-list::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.03);
        }
        
        .notification-dropdown .notification-list::-webkit-scrollbar-thumb {
            background: rgba(27,60,83,.2);
            border-radius: 4px;
        }
        
        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 12px 20px;
            border-bottom: 1px solid rgba(226,232,240,.4);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: rgba(27,60,83,.04);
        }
        
        .notification-item.unread {
            background: rgba(27,60,83,.06);
            border-left: 3px solid #234C6A;
        }
        
        .notification-item .notif-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 14px;
            color: white;
        }
        
        .notification-item .notif-icon.feedback { background: linear-gradient(135deg, #f59e0b, #f97316); }
        .notification-item .notif-icon.message { background: linear-gradient(135deg, #3b82f6, #6366f1); }
        .notification-item .notif-icon.leave { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
        .notification-item .notif-icon.ticket { background: linear-gradient(135deg, #ef4444, #f87171); }
        .notification-item .notif-icon.default { background: var(--dash-main); }
        
        .notification-item .notif-content {
            flex: 1;
            min-width: 0;
        }
        
        .notification-item .notif-content .title {
            font-weight: 600;
            font-size: 14px;
            color: #1B3C53;
            margin-bottom: 2px;
        }
        
        .notification-item .notif-content .message {
            font-size: 13px;
            color: #64748b;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
        }
        
        .notification-item .notif-content .time {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }
        
        .notification-empty {
            padding: 40px 20px;
            text-align: center;
            color: #94a3b8;
        }
        
        .notification-empty i {
            font-size: 32px;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        
        .notification-empty p {
            font-size: 14px;
        }
        
        /* Mobile responsive */
        @media (max-width: 640px) {
            .profile-avatar {
                width: 100px;
                height: 100px;
            }
            .profile-avatar-placeholder {
                width: 100px;
                height: 100px;
                font-size: 40px;
            }
            .notification-dropdown {
                width: calc(100vw - 24px);
                right: 12px;
                max-height: 400px;
            }
            .notification-dropdown .notification-list {
                max-height: 280px;
            }
            .tab-button {
                min-height: 42px;
                padding: .62rem .85rem;
                font-size: .82rem;
            }
            .glass-card {
                border-radius: 20px;
            }
            .profile-hero-shell {
                border-radius: 22px;
            }
            .px-6 { padding-left: 1rem; padding-right: 1rem; }
            .py-4 { padding-top: 1rem; padding-bottom: 1rem; }
        }
        
        @media (min-width: 1024px) {
            #main-content { margin-left: 16rem !important; }
        }
        
        aside { z-index: 50; }
        footer { 
            color: #456882;
            background: rgba(255,255,255,.34);
            backdrop-filter: blur(12px);
        }
        
        input, textarea, select {
            color: #102A3A;
            -webkit-text-fill-color: #102A3A;
            border-color: rgba(69,104,130,.28);
            background: rgba(255,255,255,.96);
            border-radius: 14px;
        }
        
        input:focus, textarea:focus, select:focus {
            border-color: #234C6A;
            box-shadow: 0 0 0 4px rgba(35,76,106,.13);
            outline: none;
        }
        
        button[type="submit"] {
            background: var(--dash-main);
            color: #ffffff;
            border-radius: 14px;
            font-weight: 900;
            box-shadow: 0 14px 28px rgba(35,76,106,.20);
            transition: all 0.3s ease;
        }
        
        button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(35,76,106,.28);
        }
        
        .trainer-top-avatar-img,
        .trainer-mobile-avatar-img {
            object-fit: cover;
            border-radius: 999px;
            border: 2px solid rgba(255,255,255,.75);
            box-shadow: 0 10px 22px rgba(27,60,83,.18);
            background: rgba(255,255,255,.25);
        }
        
        .trainer-top-avatar-img {
            width: 42px;
            height: 42px;
            min-width: 42px;
            min-height: 42px;
        }
        
        .trainer-mobile-avatar-img {
            width: 34px;
            height: 34px;
            min-width: 34px;
            min-height: 34px;
        }
        
        .trainer-top-avatar-fallback,
        .trainer-mobile-avatar-fallback {
            border: 2px solid rgba(255,255,255,.75);
            box-shadow: 0 10px 22px rgba(27,60,83,.18);
            background:
                radial-gradient(circle at 30% 20%, rgba(255,255,255,.32), transparent 34%),
                linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%);
            color: #ffffff;
            -webkit-text-fill-color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
        }
        
        /* Toast notification */
        .custom-toast {
            border-radius: 18px;
            box-shadow: 0 18px 42px rgba(15,23,42,.16);
            padding: 14px 24px;
            font-weight: 600;
            z-index: 9999;
            transition: all 0.3s ease;
        }
        
        .custom-toast.success { background: linear-gradient(135deg, #10b981, #22c55e); }
        .custom-toast.error { background: linear-gradient(135deg, #ef4444, #f43f5e); }
        .custom-toast.info { background: linear-gradient(135deg, #3b82f6, #6366f1); }
        
        .profile-tabs-scroll {
            gap: .65rem;
            padding: .65rem;
            align-items: center;
            overflow-x: auto;
            scrollbar-width: thin;
        }
        
        .profile-tabs-scroll::-webkit-scrollbar {
            height: 3px;
        }
        
        .profile-tabs-scroll::-webkit-scrollbar-thumb {
            background: rgba(27,60,83,.2);
            border-radius: 4px;
        }
        
        /* Optimize animations for reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *, ::before, ::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body class="relative overflow-x-hidden">
    <!-- Floating Background Shapes (decorative, hidden on mobile) -->
    <div class="floating-shape w-64 h-64 bg-purple-300 top-10 left-10 float-animation hidden sm:block" style="animation-delay: 0s;"></div>
    <div class="floating-shape w-48 h-48 bg-blue-300 bottom-20 right-10 float-animation hidden sm:block" style="animation-delay: 2s;"></div>
    <div class="floating-shape w-32 h-32 bg-pink-300 top-1/3 right-1/4 float-animation hidden sm:block" style="animation-delay: 1s;"></div>
    
    <?php include 't_sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 transition-all duration-300 min-h-screen" id="main-content">
        <!-- Mobile Header -->
        <div class="lg:hidden sticky top-0 z-40" style="background: var(--dash-main); border-bottom: 1px solid rgba(255,255,255,.18); box-shadow: 0 16px 36px rgba(27,60,83,.18);">
            <div class="px-4 py-3 flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <button id="mobileSidebarToggle" class="p-2 text-white">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div>
                        <h1 class="text-lg font-bold text-white">My Profile</h1>
                        <p class="text-xs text-white/70 truncate">Trainer Dashboard</p>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <!-- Notification Bell -->
                    <div class="relative" id="notifBellMobile">
                        <button class="text-white p-1 relative" onclick="toggleNotifications(event, 'mobile')">
                            <i class="fas fa-bell text-lg"></i>
                            <?php if ($notifications_count > 0): ?>
                                <span class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold"><?php echo min(9, $notifications_count); ?></span>
                            <?php endif; ?>
                        </button>
                        <!-- Notification Dropdown Mobile -->
                        <div id="notifDropdownMobile" class="notification-dropdown hidden">
                            <div class="dropdown-header">
                                <h4><i class="fas fa-bell mr-2"></i>Notifications</h4>
                                <?php if ($notifications_count > 0): ?>
                                    <button onclick="markAllRead(event)">Mark all read</button>
                                <?php endif; ?>
                            </div>
                            <div class="notification-list" id="notifListMobile">
                                <?php if (count($notifications) > 0): ?>
                                    <?php foreach ($notifications as $notif): ?>
                                        <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['id']; ?>" onclick="markRead(<?php echo $notif['id']; ?>, event)">
                                            <div class="notif-icon <?php echo $notif['type'] ?? 'default'; ?>">
                                                <i class="fas <?php echo $notif['type'] === 'feedback' ? 'fa-star' : ($notif['type'] === 'message' ? 'fa-envelope' : ($notif['type'] === 'leave' ? 'fa-calendar-check' : ($notif['type'] === 'ticket' ? 'fa-ticket-alt' : 'fa-bell'))); ?>"></i>
                                            </div>
                                            <div class="notif-content">
                                                <div class="title"><?php echo htmlspecialchars($notif['title'] ?? 'Notification'); ?></div>
                                                <div class="message"><?php echo htmlspecialchars($notif['message'] ?? ''); ?></div>
                                                <div class="time"><?php echo date('M j, g:i A', strtotime($notif['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notification-empty">
                                        <i class="fas fa-bell-slash"></i>
                                        <p>No notifications yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($profile_picture_url)): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture_url); ?>" alt="Profile" class="trainer-mobile-avatar-img">
                    <?php else: ?>
                        <div class="trainer-mobile-avatar-fallback w-8 h-8 rounded-full flex items-center justify-center text-white font-bold">
                            <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Desktop Header -->
        <header class="hidden lg:block sticky top-0 z-40" style="background: var(--dash-main); border-bottom: 1px solid rgba(255,255,255,.18); box-shadow: 0 16px 36px rgba(27,60,83,.18);">
            <div class="px-6 py-4 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-white">My Profile</h1>
                    <p class="text-white/70">Manage your profile and account settings</p>
                </div>
                <div class="flex items-center space-x-4">
                    <!-- Notification Bell -->
                    <div class="relative" id="notifBellDesktop">
                        <button class="text-white p-1 relative" onclick="toggleNotifications(event, 'desktop')">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($notifications_count > 0): ?>
                                <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold"><?php echo min(9, $notifications_count); ?></span>
                            <?php endif; ?>
                        </button>
                        <!-- Notification Dropdown Desktop -->
                        <div id="notifDropdownDesktop" class="notification-dropdown hidden">
                            <div class="dropdown-header">
                                <h4><i class="fas fa-bell mr-2"></i>Notifications</h4>
                                <?php if ($notifications_count > 0): ?>
                                    <button onclick="markAllRead(event)">Mark all read</button>
                                <?php endif; ?>
                            </div>
                            <div class="notification-list" id="notifListDesktop">
                                <?php if (count($notifications) > 0): ?>
                                    <?php foreach ($notifications as $notif): ?>
                                        <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['id']; ?>" onclick="markRead(<?php echo $notif['id']; ?>, event)">
                                            <div class="notif-icon <?php echo $notif['type'] ?? 'default'; ?>">
                                                <i class="fas <?php echo $notif['type'] === 'feedback' ? 'fa-star' : ($notif['type'] === 'message' ? 'fa-envelope' : ($notif['type'] === 'leave' ? 'fa-calendar-check' : ($notif['type'] === 'ticket' ? 'fa-ticket-alt' : 'fa-bell'))); ?>"></i>
                                            </div>
                                            <div class="notif-content">
                                                <div class="title"><?php echo htmlspecialchars($notif['title'] ?? 'Notification'); ?></div>
                                                <div class="message"><?php echo htmlspecialchars($notif['message'] ?? ''); ?></div>
                                                <div class="time"><?php echo date('M j, g:i A', strtotime($notif['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notification-empty">
                                        <i class="fas fa-bell-slash"></i>
                                        <p>No notifications yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <?php if (!empty($profile_picture_url)): ?>
                            <img src="<?php echo htmlspecialchars($profile_picture_url); ?>" alt="Profile" class="trainer-top-avatar-img">
                        <?php else: ?>
                            <div class="trainer-top-avatar-fallback w-10 h-10 rounded-full flex items-center justify-center text-white font-bold">
                                <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <p class="font-semibold text-white"><?php echo htmlspecialchars($trainer['name']); ?></p>
                            <p class="text-sm text-white/70">Trainer</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-3 sm:p-4 md:p-6">
            <!-- Welcome Card -->
            <div class="glass-card welcome-theme-mini p-4 sm:p-6 mb-4 sm:mb-6 relative overflow-hidden hover-lift" style="background: linear-gradient(135deg, rgba(255,255,255,.94), rgba(248,250,255,.92)); border-top: 5px solid #1B3C53;">
                <div class="section-kicker"><i class="fas fa-user-circle"></i> Profile Workspace</div>
                <div class="absolute top-0 right-0 w-32 h-32 sm:w-40 sm:h-40 bg-gradient-to-br from-purple-100 to-pink-100 rounded-full -mr-6 -mt-6 sm:-mr-10 sm:-mt-10 opacity-50"></div>
                <div class="relative z-10">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between">
                        <div class="mb-4 sm:mb-0">
                            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mb-1 sm:mb-2">
                                <span class="welcome-dot" style="display:inline-block; width:.55rem; height:.55rem; border-radius:999px; background:#10b981; box-shadow:0 0 0 6px rgba(16,185,129,.14); margin-right:.45rem;"></span>
                                Welcome back, <?php echo htmlspecialchars($trainer['name']); ?>! 👋
                            </h2>
                            <p class="text-sm sm:text-base text-gray-600">Manage your profile, documents, and account settings</p>
                        </div>
                        <div class="flex flex-col xs:flex-row items-stretch xs:items-center gap-2">
                            <a href="students/students.php" class="px-3 py-2 sm:px-4 sm:py-2 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-full font-semibold hover:opacity-90 transition-opacity text-sm sm:text-base whitespace-nowrap text-center">
                                <i class="fas fa-users mr-1 sm:mr-2"></i>My Students (<?php echo $total_students; ?>)
                            </a>
                            <a href="dashboard/dashboard.php" class="px-3 py-2 sm:px-4 sm:py-2 bg-white text-purple-600 border border-purple-200 rounded-full font-semibold hover:bg-purple-50 transition-colors text-sm sm:text-base whitespace-nowrap text-center">
                                <i class="fas fa-tachometer-alt mr-1 sm:mr-2"></i>Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Header -->
            <div class="glass-card profile-hero-shell p-4 sm:p-6 mb-6 relative overflow-hidden gradient-bg text-white" data-aos="fade-up">
                <div class="absolute top-0 right-0 w-64 h-64 bg-gradient-to-br from-white/10 to-transparent rounded-full -mr-32 -mt-32"></div>
                <div class="relative z-10">
                    <div class="flex flex-col md:flex-row items-center md:items-start">
                        <div class="mb-6 md:mb-0 md:mr-8">
                            <?php if (!empty($profile_picture_url)): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture_url); ?>" alt="Profile Picture" class="profile-avatar border-4 border-white/50">
                            <?php else: ?>
                                <div class="profile-avatar-placeholder border-4 border-white/50">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 text-center md:text-left">
                            <h1 class="text-2xl sm:text-3xl font-bold mb-2"><?php echo htmlspecialchars($trainer['name']); ?></h1>
                            <div class="flex flex-wrap justify-center md:justify-start gap-2 mb-4">
                                <?php 
                                $specializations = explode(',', $trainer['specialization']);
                                foreach ($specializations as $spec): 
                                    $spec = trim($spec);
                                    if (!empty($spec)):
                                ?>
                                    <span class="skill-tag bg-white/20 backdrop-blur-sm"><?php echo htmlspecialchars($spec); ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                            <div class="flex flex-col md:flex-row items-center justify-center md:justify-start space-y-2 md:space-y-0 md:space-x-6 text-sm sm:text-base">
                                <div class="flex items-center">
                                    <i class="fas fa-envelope mr-2"></i>
                                    <span><?php echo htmlspecialchars($trainer['email']); ?></span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-phone mr-2"></i>
                                    <span><?php echo htmlspecialchars($trainer['phone']); ?></span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-briefcase mr-2"></i>
                                    <span><?php echo $trainer['years_of_experience']; ?> years experience</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-users mr-2"></i>
                                    <span><?php echo $total_students; ?> students</span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6 md:mt-0">
                            <button class="px-4 py-2 sm:px-6 sm:py-3 bg-black text-purple-600 rounded-full font-semibold hover:bg-purple-100 text-black transition-colors ripple" onclick="showEditModal()">
                                <i class="fas fa-edit mr-2"></i>Edit Profile
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="glass-card feature-shell mb-6 overflow-hidden">
                <div class="section-kicker mx-4 mt-4"><i class="fas fa-layer-group"></i> Profile Sections</div>
                <div class="flex overflow-x-auto profile-tabs-scroll">
                    <button class="tab-button active" data-tab="overview">
                        <i class="fas fa-user-circle mr-2"></i>Overview
                    </button>
                    <button class="tab-button" data-tab="batches">
                        <i class="fas fa-users mr-2"></i>My Batches (<?php echo $batch_stats['total']; ?>)
                    </button>
                    <button class="tab-button" data-tab="documents">
                        <i class="fas fa-file-alt mr-2"></i>Documents (<?php echo count($documents); ?>)
                    </button>
                    <button class="tab-button" data-tab="students">
                        <i class="fas fa-user-graduate mr-2"></i>Students (<?php echo $total_students; ?>)
                    </button>
                </div>
            </div>

            <!-- Tab Contents -->
            <div class="space-y-6">
                <!-- Overview Tab -->
                <div class="tab-content active" id="overview">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-2 space-y-6">
                            <!-- About Me -->
                            <div class="glass-card feature-shell p-4 sm:p-6" data-aos="fade-right">
                                <div class="section-kicker"><i class="fas fa-id-badge"></i> Personal Overview</div>
                                <div class="flex items-center mb-4">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-500 to-cyan-500 flex items-center justify-center mr-3">
                                        <i class="fas fa-user text-white"></i>
                                    </div>
                                    <h3 class="text-lg sm:text-xl font-bold text-gray-800">About Me</h3>
                                </div>
                                <p class="text-gray-600 mb-6 text-sm sm:text-base"><?php echo htmlspecialchars($trainer['bio'] ?: 'No bio information available.'); ?></p>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="flex items-center p-3 bg-gradient-to-r from-blue-50 to-cyan-50 rounded-xl">
                                        <div class="info-icon blue">
                                            <i class="fas fa-graduation-cap"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs sm:text-sm text-gray-500">Qualifications</p>
                                            <p class="font-medium text-sm sm:text-base"><?php echo htmlspecialchars($trainer['qualifications'] ?: 'Not specified'); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center p-3 bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl">
                                        <div class="info-icon green">
                                            <i class="fas fa-briefcase"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs sm:text-sm text-gray-500">Experience</p>
                                            <p class="font-medium text-sm sm:text-base"><?php echo htmlspecialchars($trainer['years_of_experience']); ?> years</p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center p-3 bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl">
                                        <div class="info-icon purple">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs sm:text-sm text-gray-500">Location</p>
                                            <p class="font-medium text-sm sm:text-base"><?php echo htmlspecialchars($trainer['address'] ?: 'Not specified'); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center p-3 bg-gradient-to-r from-yellow-50 to-amber-50 rounded-xl">
                                        <div class="info-icon yellow">
                                            <i class="fas fa-star"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs sm:text-sm text-gray-500">Status</p>
                                            <p class="font-medium text-sm sm:text-base"><?php echo $trainer['is_active'] ? 'Active' : 'Inactive'; ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Statistics -->
                            <div class="glass-card feature-shell profile-stats-align-card p-4 sm:p-6" data-aos="fade-right" data-aos-delay="100">
                                <div class="section-kicker"><i class="fas fa-chart-pie"></i> Trainer Statistics</div>
                                <div class="flex items-center mb-4">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-r from-green-500 to-emerald-500 flex items-center justify-center mr-3">
                                        <i class="fas fa-chart-bar text-white"></i>
                                    </div>
                                    <h3 class="text-lg sm:text-xl font-bold text-gray-800">Statistics</h3>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                    <div class="text-center p-4 bg-gradient-to-r from-blue-50 to-cyan-50 rounded-xl">
                                        <p class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $batch_stats['total']; ?></p>
                                        <p class="text-sm text-gray-600">Total Batches</p>
                                    </div>
                                    <div class="text-center p-4 bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl">
                                        <p class="text-2xl sm:text-3xl font-bold text-green-600"><?php echo $batch_stats['ongoing']; ?></p>
                                        <p class="text-sm text-gray-600">Active</p>
                                    </div>
                                    <div class="text-center p-4 bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl">
                                        <p class="text-2xl sm:text-3xl font-bold text-purple-600"><?php echo $total_students; ?></p>
                                        <p class="text-sm text-gray-600">Students</p>
                                    </div>
                                    <div class="text-center p-4 bg-gradient-to-r from-yellow-50 to-amber-50 rounded-xl">
                                        <p class="text-2xl sm:text-3xl font-bold text-yellow-600"><?php echo $trainer['years_of_experience']; ?></p>
                                        <p class="text-sm text-gray-600">Years Exp</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="space-y-6">
                            <!-- Contact Information -->
                            <div class="glass-card feature-shell p-4 sm:p-6" data-aos="fade-left">
                                <div class="section-kicker"><i class="fas fa-address-card"></i> Contact Details</div>
                                <div class="flex items-center mb-4">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-r from-purple-500 to-pink-500 flex items-center justify-center mr-3">
                                        <i class="fas fa-address-card text-white"></i>
                                    </div>
                                    <h3 class="text-lg sm:text-xl font-bold text-gray-800">Contact Information</h3>
                                </div>
                                <div class="space-y-4">
                                    <div class="flex items-center p-3 bg-gray-50 rounded-xl">
                                        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center mr-3">
                                            <i class="fas fa-envelope text-blue-600"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500">Email</p>
                                            <p class="font-medium text-sm sm:text-base"><?php echo htmlspecialchars($trainer['email']); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center p-3 bg-gray-50 rounded-xl">
                                        <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center mr-3">
                                            <i class="fas fa-phone text-green-600"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500">Phone</p>
                                            <p class="font-medium text-sm sm:text-base"><?php echo htmlspecialchars($trainer['phone']); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center p-3 bg-gray-50 rounded-xl">
                                        <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center mr-3">
                                            <i class="fas fa-map-marker-alt text-purple-600"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500">Address</p>
                                            <p class="font-medium text-sm sm:text-base"><?php echo htmlspecialchars($trainer['address'] ?: 'Not specified'); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Actions -->
                            <div class="glass-card feature-shell profile-actions-align-card p-4 sm:p-6" data-aos="fade-left" data-aos-delay="100">
                                <div class="section-kicker"><i class="fas fa-bolt"></i> Quick Actions</div>
                                <div class="flex items-center mb-4">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-r from-yellow-500 to-amber-500 flex items-center justify-center mr-3">
                                        <i class="fas fa-bolt text-white"></i>
                                    </div>
                                    <h3 class="text-lg sm:text-xl font-bold text-gray-800">Quick Actions</h3>
                                </div>
                                <div class="space-y-3">
                                    <a href="students/students.php" class="flex items-center p-3 bg-blue-50 rounded-xl hover:bg-blue-100 transition-colors ripple">
                                        <i class="fas fa-users text-blue-600 mr-3"></i>
                                        <span class="text-sm sm:text-base">View My Students</span>
                                    </a>
                                    <a href="schedule/schedule.php" class="flex items-center p-3 bg-green-50 rounded-xl hover:bg-green-100 transition-colors ripple">
                                        <i class="fas fa-calendar-alt text-green-600 mr-3"></i>
                                        <span class="text-sm sm:text-base">View Schedule</span>
                                    </a>
                                    <a href="attendance/trainer_attendance.php" class="flex items-center p-3 bg-purple-50 rounded-xl hover:bg-purple-100 transition-colors ripple">
                                        <i class="fas fa-clipboard-check text-purple-600 mr-3"></i>
                                        <span class="text-sm sm:text-base">Take Attendance</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Batches Tab -->
                <div class="tab-content" id="batches">
                    <div class="glass-card feature-shell p-4 sm:p-6" data-aos="fade-up">
                        <div class="section-kicker"><i class="fas fa-users"></i> Assigned Batches</div>
                        <div class="flex items-center mb-4">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-500 to-cyan-500 flex items-center justify-center mr-3">
                                <i class="fas fa-users text-white"></i>
                            </div>
                            <h3 class="text-lg sm:text-xl font-bold text-gray-800">My Batches</h3>
                        </div>
                        
                        <?php if (count($batches) > 0): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch ID</th>
                                            <th class="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Course</th>
                                            <th class="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Schedule</th>
                                            <th class="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Students</th>
                                            <th class="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($batches as $index => $batch): ?>
                                            <tr class="fade-in" style="animation-delay: <?php echo $index * 0.05; ?>s">
                                                <td class="p-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($batch['batch_id']); ?>
                                                </td>
                                                <td class="p-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($batch['batch_name']); ?>
                                                </td>
                                                <td class="p-3 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($batch['time_slot']); ?><br>
                                                    <small class="text-gray-400"><?php echo date('M j, Y', strtotime($batch['start_date'])) . ' - ' . date('M j, Y', strtotime($batch['end_date'])); ?></small>
                                                </td>
                                                <td class="p-3 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="mb-1">
                                                        <?php echo htmlspecialchars($batch['current_enrollment']) . '/' . htmlspecialchars($batch['max_students']); ?>
                                                    </div>
                                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                                        <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo ($batch['current_enrollment'] / max(1, $batch['max_students'])) * 100; ?>%"></div>
                                                    </div>
                                                </td>
                                                <td class="p-3 whitespace-nowrap text-sm text-gray-500">
                                                    <?php 
                                                    $status_class = 'status-' . $batch['status'];
                                                    ?>
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($batch['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="p-3 whitespace-nowrap text-sm font-medium">
                                                    <a href="courses/my_courses.php" class="inline-flex items-center px-3 py-1 bg-blue-50 text-white-100 rounded-full text-xs hover:bg-blue-200 transition-colors mr-2">
                                                        <i class="fas fa-eye mr-1"></i> View
                                                    </a>
                                                    <a href="students/students.php?id=<?php echo $batch['batch_id']; ?>" class="inline-flex items-center px-3 py-1 bg-green-50 text-white-100 rounded-full text-xs hover:bg-green-200 transition-colors">
                                                        <i class="fas fa-users mr-1"></i> Students
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-users-slash empty-state-icon animate-pulse"></i>
                                <p>You don't have any batches assigned yet</p>
                                <a href="#" class="text-sm text-blue-500 hover:underline mt-2 ripple">Request a batch</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Documents Tab -->
                <div class="tab-content" id="documents">
                    <div class="glass-card feature-shell p-4 sm:p-6" data-aos="fade-up">
                        <div class="section-kicker"><i class="fas fa-file-alt"></i> Uploaded Documents</div>
                        <div class="flex items-center mb-4">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-purple-500 to-pink-500 flex items-center justify-center mr-3">
                                <i class="fas fa-file-alt text-white"></i>
                            </div>
                            <h3 class="text-lg sm:text-xl font-bold text-gray-800">My Documents</h3>
                            <button onclick="showDocumentModal()" class="ml-auto px-4 py-2 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-full font-semibold hover:opacity-90 transition-opacity text-sm">
                                <i class="fas fa-upload mr-2"></i>Upload Document
                            </button>
                        </div>
                        
                        <?php if (count($documents) > 0): ?>
                            <div class="space-y-4">
                                <?php foreach ($documents as $index => $doc): ?>
                                    <div class="fade-in" style="animation-delay: <?php echo $index * 0.05; ?>s">
                                        <?php
                                        $file_ext = pathinfo($doc['file_path'], PATHINFO_EXTENSION);
                                        $icon_class = 'other';
                                        $icon = 'fa-file';
                                        
                                        if (in_array($file_ext, ['pdf'])) {
                                            $icon_class = 'pdf';
                                            $icon = 'fa-file-pdf';
                                        } elseif (in_array($file_ext, ['doc', 'docx'])) {
                                            $icon_class = 'word';
                                            $icon = 'fa-file-word';
                                        } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                            $icon_class = 'image';
                                            $icon = 'fa-file-image';
                                        }
                                        ?>
                                        <div class="flex items-center p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors">
                                            <div class="document-icon <?php echo $icon_class; ?>">
                                                <i class="fas <?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="flex-1">
                                                <h4 class="font-medium text-gray-800 text-sm sm:text-base"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $doc['document_type']))); ?></h4>
                                                <p class="text-xs text-gray-500">Uploaded on <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?></p>
                                            </div>
                                            <div class="flex space-x-2">
                                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="inline-flex items-center px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs hover:bg-blue-200 transition-colors mr-2">
                                                    <i class="fas fa-eye mr-1"></i> View
                                                </a>
                                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" download class="inline-flex items-center px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs hover:bg-green-200 transition-colors mr-2">
                                                    <i class="fas fa-download mr-1"></i> Download
                                                </a>
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this document?');">
                                                    <input type="hidden" name="delete_document" value="1">
                                                    <input type="hidden" name="document_id" value="<?php echo $doc['document_id']; ?>">
                                                    <button type="submit" class="inline-flex items-center px-3 py-1 bg-red-100 text-red-700 rounded-full text-xs hover:bg-red-200 transition-colors">
                                                        <i class="fas fa-trash mr-1"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-folder-open empty-state-icon animate-pulse"></i>
                                <p>No documents uploaded yet</p>
                                <button class="text-sm text-blue-500 hover:underline mt-2 ripple" onclick="showDocumentModal()">
                                    <i class="fas fa-upload mr-1"></i>Upload your first document
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Students Tab -->
                <div class="tab-content" id="students">
                    <div class="glass-card feature-shell p-4 sm:p-6" data-aos="fade-up">
                        <div class="section-kicker"><i class="fas fa-user-graduate"></i> Student Summary</div>
                        <div class="flex items-center mb-4">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-green-500 to-emerald-500 flex items-center justify-center mr-3">
                                <i class="fas fa-user-graduate text-white"></i>
                            </div>
                            <h3 class="text-lg sm:text-xl font-bold text-gray-800">My Students</h3>
                        </div>
                        
                        <?php if ($total_students > 0): ?>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                                <div class="bg-gradient-to-r from-blue-50 to-cyan-50 rounded-xl p-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm text-gray-600">Total Students</p>
                                            <p class="text-3xl font-bold text-gray-800"><?php echo $total_students; ?></p>
                                        </div>
                                        <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-500 to-cyan-500 flex items-center justify-center">
                                            <i class="fas fa-users text-white text-xl"></i>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm text-gray-600">Active Students</p>
                                            <p class="text-3xl font-bold text-green-600"><?php echo $active_students; ?></p>
                                        </div>
                                        <div class="w-12 h-12 rounded-full bg-gradient-to-r from-green-500 to-emerald-500 flex items-center justify-center">
                                            <i class="fas fa-user-check text-white text-xl"></i>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl p-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm text-gray-600">Assigned Batches</p>
                                            <p class="text-3xl font-bold text-purple-600"><?php echo $batch_stats['total']; ?></p>
                                        </div>
                                        <div class="w-12 h-12 rounded-full bg-gradient-to-r from-purple-500 to-pink-500 flex items-center justify-center">
                                            <i class="fas fa-layer-group text-white text-xl"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <a href="students/students.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-full font-semibold hover:opacity-90 transition-opacity text-sm sm:text-base">
                                    <i class="fas fa-external-link-alt mr-2"></i> View All Students
                                </a>
                                <p class="text-sm text-gray-500 mt-4">Manage your students, view performance, and track progress</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-user-graduate empty-state-icon animate-pulse"></i>
                                <p>You don't have any students assigned yet</p>
                                <p class="text-sm text-gray-500 mt-2">Students will appear here once they are assigned to your batches</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="mt-8 py-4 text-center text-gray-500 text-sm border-t border-gray-200">
            <p>ASD Academy Trainer Portal © <?php echo date('Y'); ?>. All rights reserved.</p>
        </footer>
    </div>

    <!-- Edit Profile Modal -->
    <div id="editModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-overlay fixed inset-0" onclick="hideEditModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-3 sm:p-4">
            <div class="modal-content bg-white rounded-xl sm:rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-bold text-gray-800">Edit Profile</h3>
                        <button class="text-gray-400 hover:text-gray-600 text-2xl" onclick="hideEditModal()">&times;</button>
                    </div>
                </div>
                <div class="p-4 sm:p-6">
                    <form id="editProfileForm" method="POST" enctype="multipart/form-data" class="space-y-6">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Full Name *</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($trainer['name']); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($trainer['email']); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Phone</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($trainer['phone']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Years of Experience</label>
                                <input type="number" name="years_of_experience" value="<?php echo htmlspecialchars($trainer['years_of_experience']); ?>" min="0" max="50" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Specialization</label>
                                <input type="text" name="specialization" value="<?php echo htmlspecialchars($trainer['specialization']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Profile Picture</label>
                                <input type="file" name="profile_picture" accept="image/*" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base">
                                <?php if (!empty($profile_picture_url)): ?>
                                    <p class="text-xs text-gray-500 mt-1">Current: <?php echo basename($profile_picture_url); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                                <textarea name="address" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base"><?php echo htmlspecialchars($trainer['address']); ?></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Qualifications</label>
                                <textarea name="qualifications" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base"><?php echo htmlspecialchars($trainer['qualifications']); ?></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Bio</label>
                                <textarea name="bio" rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base"><?php echo htmlspecialchars($trainer['bio']); ?></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="p-4 sm:p-6 border-t border-gray-200 flex justify-end space-x-3">
                    <button class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-sm sm:text-base" onclick="hideEditModal()">Cancel</button>
                    <button type="submit" form="editProfileForm" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm sm:text-base">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div id="documentModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-overlay fixed inset-0" onclick="hideDocumentModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-3 sm:p-4">
            <div class="modal-content bg-white rounded-xl sm:rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-bold text-gray-800">Upload Document</h3>
                        <button class="text-gray-400 hover:text-gray-600 text-2xl" onclick="hideDocumentModal()">&times;</button>
                    </div>
                </div>
                <div class="p-4 sm:p-6">
                    <form id="uploadDocumentForm" method="POST" enctype="multipart/form-data" class="space-y-6">
                        <input type="hidden" name="upload_document" value="1">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Document Type *</label>
                                <select name="document_type" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base">
                                    <option value="resume">Resume / CV</option>
                                    <option value="certification">Certification</option>
                                    <option value="degree">Degree / Diploma</option>
                                    <option value="id_proof">ID Proof</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Select File *</label>
                                <input type="file" name="document_file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.txt,.xls,.xlsx" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm sm:text-base">
                                <p class="text-xs text-gray-500 mt-1">Allowed formats: PDF, DOC, DOCX, JPG, PNG, GIF, TXT, XLS, XLSX</p>
                            </div>
                            
                            <div class="bg-blue-50 p-4 rounded-lg">
                                <p class="text-sm text-blue-700">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Upload your professional documents to keep your profile complete.
                                </p>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="p-4 sm:p-6 border-t border-gray-200 flex justify-end space-x-3">
                    <button class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors text-sm sm:text-base" onclick="hideDocumentModal()">Cancel</button>
                    <button type="submit" form="uploadDocumentForm" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm sm:text-base">Upload Document</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Load scripts at bottom for faster page render -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js" defer></script>
    <script>
        // Store trainer_id for AJAX calls
        const TRAINER_ID = <?php echo json_encode($trainer_user_id); ?>;
        
        // ========== NOTIFICATION FUNCTIONS ==========
        
        // Toggle notification dropdown
        function toggleNotifications(event, type) {
            event.stopPropagation();
            const dropdownId = type === 'desktop' ? 'notifDropdownDesktop' : 'notifDropdownMobile';
            const dropdown = document.getElementById(dropdownId);
            
            // Close other dropdown
            const otherId = type === 'desktop' ? 'notifDropdownMobile' : 'notifDropdownDesktop';
            const otherDropdown = document.getElementById(otherId);
            if (otherDropdown && !otherDropdown.classList.contains('hidden')) {
                otherDropdown.classList.add('hidden');
            }
            
            dropdown.classList.toggle('hidden');
        }
        
        // Mark single notification as read
        function markRead(id, event) {
            if (event) event.stopPropagation();
            
            const item = document.querySelector(`.notification-item[data-id="${id}"]`);
            if (!item) return;
            
            // If already read, just close
            if (!item.classList.contains('unread')) {
                closeAllDropdowns();
                return;
            }
            
            // Send AJAX request
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `mark_notification_read=1&notification_id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    item.classList.remove('unread');
                    updateNotificationCount();
                    showToast('Notification marked as read', 'info');
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // Mark all notifications as read
        function markAllRead(event) {
            if (event) event.stopPropagation();
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mark_all_read=1'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    updateNotificationCount();
                    showToast('All notifications marked as read', 'success');
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // Update notification badge count
        function updateNotificationCount() {
            const unreadCount = document.querySelectorAll('.notification-item.unread').length;
            const badges = document.querySelectorAll('.relative .bg-red-500');
            
            badges.forEach(badge => {
                if (unreadCount > 0) {
                    badge.textContent = Math.min(9, unreadCount);
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            });
        }
        
        // Close all dropdowns
        function closeAllDropdowns() {
            document.querySelectorAll('.notification-dropdown').forEach(d => {
                d.classList.add('hidden');
            });
        }
        
        // Click outside to close dropdowns
        document.addEventListener('click', function(e) {
            const isBell = e.target.closest('.relative') || e.target.closest('.notification-dropdown');
            if (!isBell) {
                closeAllDropdowns();
            }
        });
        
        // ========== EDIT MODAL FUNCTIONS ==========
        
        function showEditModal() {
            const modal = document.getElementById('editModal');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.querySelector('.modal-content').classList.add('active');
            }, 10);
            closeAllDropdowns();
        }
        
        function hideEditModal() {
            const modal = document.getElementById('editModal');
            modal.querySelector('.modal-content').classList.remove('active');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }
        
        // ========== DOCUMENT MODAL FUNCTIONS ==========
        
        function showDocumentModal() {
            const modal = document.getElementById('documentModal');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.querySelector('.modal-content').classList.add('active');
            }, 10);
            closeAllDropdowns();
        }
        
        function hideDocumentModal() {
            const modal = document.getElementById('documentModal');
            modal.querySelector('.modal-content').classList.remove('active');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }
        
        // ========== TOAST NOTIFICATION ==========
        
        function showToast(message, type = 'info') {
            const existingToasts = document.querySelectorAll('.custom-toast');
            existingToasts.forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `custom-toast fixed top-4 right-4 px-4 py-3 sm:px-6 sm:py-3 rounded-lg shadow-xl text-white font-semibold z-[9999] transform translate-x-full opacity-0 transition-all duration-300 text-sm sm:text-base ${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            // Trigger animation
            requestAnimationFrame(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
                toast.classList.add('translate-x-0', 'opacity-100');
            });
            
            setTimeout(() => {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // ========== INITIALIZATION ==========
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize AOS
            if (typeof AOS !== 'undefined') {
                AOS.init({
                    duration: 400,
                    once: true,
                    offset: 30,
                    disable: window.innerWidth < 640
                });
            }
            
            // Mobile sidebar toggle
            const mobileToggle = document.getElementById('mobileSidebarToggle');
            const sidebar = document.querySelector('aside');
            const body = document.body;
            
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('-translate-x-full');
                    body.classList.toggle('sidebar-open');
                    closeAllDropdowns();
                });
            }
            
            // Close sidebar on link click (mobile)
            document.querySelectorAll('nav a').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 1024) {
                        sidebar.classList.add('-translate-x-full');
                        body.classList.remove('sidebar-open');
                    }
                });
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 1024) {
                    sidebar.classList.remove('-translate-x-full');
                    body.classList.remove('sidebar-open');
                }
                if (typeof AOS !== 'undefined') {
                    AOS.refresh();
                }
            });
            
            // Tab functionality
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                    closeAllDropdowns();
                });
            });
            
            // Ripple effect
            document.querySelectorAll('button, .ripple').forEach(button => {
                button.addEventListener('click', function(e) {
                    const rect = this.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    
                    const ripple = document.createElement('span');
                    ripple.className = 'ripple-effect';
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';
                    
                    this.appendChild(ripple);
                    setTimeout(() => ripple.remove(), 600);
                });
            });
            
            // Show success messages
            <?php if (isset($success_message)): ?>
                showToast('<?php echo addslashes($success_message); ?>', 'success');
            <?php endif; ?>
            
            <?php if (isset($doc_success_message)): ?>
                showToast('<?php echo addslashes($doc_success_message); ?>', 'success');
            <?php endif; ?>
        });
        
        // Handle form submissions with loading state
        document.getElementById('editProfileForm')?.addEventListener('submit', function() {
            const btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
                btn.disabled = true;
            }
        });
        
        document.getElementById('uploadDocumentForm')?.addEventListener('submit', function() {
            const btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Uploading...';
                btn.disabled = true;
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape key
            if (e.key === 'Escape') {
                closeAllDropdowns();
                if (!document.getElementById('editModal').classList.contains('hidden')) {
                    hideEditModal();
                }
                if (!document.getElementById('documentModal').classList.contains('hidden')) {
                    hideDocumentModal();
                }
            }
        });
        
        // Touch interactions
        document.querySelectorAll('button, .tab-button').forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            }, { passive: true });
            
            element.addEventListener('touchend', function() {
                this.style.opacity = '';
            }, { passive: true });
        });
    </script>
</body>
</html>