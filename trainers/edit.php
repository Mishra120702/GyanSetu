<?php
require_once '../db_connection.php';
require_once 'functions.php';

// Check admin permissions
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['user_role'] !== 'admin') {
    header("Location: ../unauthorized.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$trainerId = (int)$_GET['id'];
$errors = [];
$success = false;
$passwordChangeSuccess = false;
$profilePictureSuccess = false;

// Fetch trainer data
$stmt = $db->prepare("SELECT t.*, u.email, u.id as user_id 
                       FROM trainers t 
                       JOIN users u ON t.user_id = u.id 
                       WHERE t.id = ?");
$stmt->execute([$trainerId]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is a profile picture change request
    if (isset($_POST['change_profile_picture']) && isset($_FILES['profile_picture'])) {
        $uploadDir = '../uploads/trainer_profile_pictures/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $file = $_FILES['profile_picture'];
        $fileType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = 'Only JPG, PNG, and GIF files are allowed.';
        } elseif ($file['size'] > 2097152) { // 2MB
            $errors[] = 'File size must be less than 2MB.';
        } else {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'trainer_' . $trainerId . '_' . time() . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                // Delete old profile picture if it exists
                if (!empty($trainer['profile_picture']) && file_exists($trainer['profile_picture'])) {
                    unlink($trainer['profile_picture']);
                }
                
                // Update database
                $stmt = $db->prepare("UPDATE trainers SET profile_picture = ? WHERE id = ?");
                if ($stmt->execute([$destination, $trainerId])) {
                    $profilePictureSuccess = true;
                    $_SESSION['success_message'] = 'Profile picture updated successfully';
                    // Refresh trainer data
                    $stmt = $db->prepare("SELECT t.*, u.email, u.id as user_id 
                                          FROM trainers t 
                                          JOIN users u ON t.user_id = u.id 
                                          WHERE t.id = ?");
                    $stmt->execute([$trainerId]);
                    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $errors[] = 'Failed to update profile picture in database';
                }
            } else {
                $errors[] = 'Failed to upload file';
            }
        }
    }
    // Check if this is a password change request
    elseif (isset($_POST['change_password'])) {
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');
        
        // Validate password
        if (empty($newPassword)) {
            $errors[] = 'New password is required';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'Passwords do not match';
        }
        
        if (empty($errors)) {
            try {
                // Start transaction
                $db->beginTransaction();
                
                // Update password in users table
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $result = $stmt->execute([$passwordHash, $trainer['user_id']]);
                
                if ($result && $stmt->rowCount() > 0) {
                    // Update trainer's updated_at timestamp
                    $stmt2 = $db->prepare("UPDATE trainers SET updated_at = NOW() WHERE id = ?");
                    $stmt2->execute([$trainerId]);
                    
                    $db->commit();
                    $passwordChangeSuccess = true;
                    $_SESSION['success_message'] = 'Password changed successfully';
                    
                    // Clear any existing password-related session data
                    unset($_SESSION['password_reset_required']);
                } else {
                    $db->rollBack();
                    $errors[] = 'Failed to update password. User record not found or no changes made.';
                }
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Failed to update password: ' . $e->getMessage();
                error_log("Password update error for trainer ID {$trainerId}, user ID {$trainer['user_id']}: " . $e->getMessage());
            }
        }
    } else {
        // Original form processing for trainer data
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $experience = (int)($_POST['experience'] ?? 0);
        $bio = trim($_POST['bio'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Basic validation
        if (empty($name)) $errors[] = 'Name is required';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
        if ($experience < 0) $errors[] = 'Experience cannot be negative';

        if (empty($errors)) {
            // Check if email already exists for another user
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $trainer['user_id']]);
            
            if ($stmt->rowCount() > 0) {
                $errors[] = 'Email already exists for another user';
            } else {
                // Update records in a transaction
                $db->beginTransaction();
                
                try {
                    // Update user email
                    $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$email, $trainer['user_id']]);
                    
                    // Update trainer
                    $stmt = $db->prepare("UPDATE trainers 
                                          SET name = ?, specialization = ?, years_of_experience = ?, 
                                              bio = ?, is_active = ?, updated_at = NOW() 
                                          WHERE id = ?");
                    $stmt->execute([$name, $specialization, $experience, $bio, $isActive, $trainerId]);
                    
                    $db->commit();
                    $success = true;
                    
                    // Refresh trainer data
                    $stmt = $db->prepare("SELECT t.*, u.email 
                                          FROM trainers t 
                                          JOIN users u ON t.user_id = u.id 
                                          WHERE t.id = ?");
                    $stmt->execute([$trainerId]);
                    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Show success message
                    $_SESSION['success_message'] = 'Trainer updated successfully';
                } catch (Exception $e) {
                    $db->rollBack();
                    $errors[] = 'Failed to update trainer: ' . $e->getMessage();
                    error_log("Trainer update error for ID {$trainerId}: " . $e->getMessage());
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Trainer | ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        /* ═══════════════════════════════════════════════════════════════════════════
           DESIGN SYSTEM — Navy/Sand Theme (matches admin_dashboard)
           ═══════════════════════════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy-deep:   #1B3C53;
            --navy-mid:    #234C6A;
            --navy-light:  #456882;
            --sand:        #D2C1B6;
            --sand-light:  #e8ddd8;
            --sand-faint:  #f5f0ee;
            --white:       #ffffff;
            --text-primary: #1B3C53;
            --text-secondary: #456882;
            --text-muted:  #7a9ab0;
            --border-light: rgba(69,104,130,0.18);
            --border-medium: rgba(69,104,130,0.30);
            --shadow-sm: 0 2px 8px rgba(27,60,83,0.06);
            --shadow-md: 0 4px 20px rgba(27,60,83,0.10);
            --shadow-lg: 0 12px 36px rgba(27,60,83,0.14);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 18px;
            --radius-xl: 24px;
            --sidebar-w: 260px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(160deg, var(--sand-faint) 0%, var(--sand-light) 100%);
            color: var(--text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── LAYOUT ── */
        .page-layout {
            display: flex;
            min-height: 100vh;
        }
        .main-area {
            flex: 1;
            margin-left: 0;
            min-height: 100vh;
        }
        @media (min-width: 768px) {
            .main-area { margin-left: 256px; }
        }

        /* ── STICKY TOPBAR ── */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 30;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 28px;
            height: 64px;
            background: rgba(255,253,248,0.92);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-light);
            box-shadow: 0 1px 0 0 rgba(69,104,130,0.08);
        }
        .topbar-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 18px;
            padding: 6px;
        }
        @media (max-width: 767px) { .topbar-toggle { display: block; } }

        .topbar-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .topbar-title-icon {
            width: 32px; height: 32px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            font-size: 14px;
        }
        .topbar-actions { margin-left: auto; display: flex; gap: 10px; }

        /* ── CONTENT ── */
        .content {
            padding: 28px;
            max-width: 1600px;
            width: 100%;
        }

        /* ── HERO BANNER (same as view/batches) ── */
        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 30%, #456882 60%, #D2C1B6 100%);
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(27,60,83,0.35), 0 6px 20px rgba(35,76,106,0.25);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(210,193,182,0.20);
            padding: 18px 28px;
            margin-bottom: 24px;
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 20% 30%, rgba(255,255,255,0.15) 0%, transparent 8%),
                radial-gradient(circle at 80% 70%, rgba(255,255,255,0.12) 0%, transparent 12%),
                radial-gradient(circle at 40% 80%, rgba(255,255,255,0.10) 0%, transparent 10%),
                radial-gradient(circle at 10% 90%, rgba(255,255,255,0.08) 0%, transparent 6%),
                radial-gradient(circle at 70% 20%, rgba(255,255,255,0.18) 0%, transparent 14%),
                radial-gradient(circle at 50% 50%, rgba(255,255,255,0.06) 0%, transparent 20%);
            pointer-events: none;
            z-index: 1;
        }
        .hero-banner .bubble {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.08);
            pointer-events: none;
            z-index: 0;
        }
        .hero-banner .bubble:nth-child(1) { width: 80px; height: 80px; top: 10%; left: 5%; animation: floatBubble 12s infinite ease-in-out; }
        .hero-banner .bubble:nth-child(2) { width: 120px; height: 120px; bottom: 5%; right: 10%; animation: floatBubble 18s infinite ease-in-out reverse; }
        .hero-banner .bubble:nth-child(3) { width: 60px; height: 60px; top: 60%; left: 80%; animation: floatBubble 14s infinite ease-in-out 2s; }
        .hero-banner .bubble:nth-child(4) { width: 40px; height: 40px; top: 20%; right: 25%; animation: floatBubble 10s infinite ease-in-out 1s; }
        @keyframes floatBubble {
            0% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(20px, -30px) scale(1.05); }
            66% { transform: translate(-10px, 20px) scale(0.95); }
            100% { transform: translate(0, 0) scale(1); }
        }
        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.20);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.25);
            color: #f7f5f3;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .hero-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
        }
        .hero-avatar-wrap {
            flex-shrink: 0;
            position: relative;
        }
        .hero-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.25);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            background: var(--sand-faint);
        }
        .hero-status {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.6);
        }
        .hero-status.active { background: #10b981; }
        .hero-status.inactive { background: #94a3b8; }

        .hero-text {
            flex: 1;
            min-width: 180px;
        }
        .hero-text h1 {
            font-size: 1.4rem;
            font-weight: 800;
            color: #fff;
            font-family: 'Poppins', sans-serif;
            text-shadow: 0 2px 4px rgba(0,0,0,0.10);
            letter-spacing: -0.02em;
            margin: 0;
        }
        .hero-text .hero-sub {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 4px;
        }
        .hero-text .hero-sub span {
            color: rgba(255,255,255,0.85);
            font-size: 0.85rem;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        .hero-text .hero-sub .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: rgba(255,255,255,0.20);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
        }
        .hero-text .hero-sub .badge-spec {
            background: rgba(210,193,182,0.25);
        }
        .hero-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-left: auto;
        }
        .hero-actions .btn {
            height: 32px;
            padding: 0 14px;
            font-size: 12px;
        }
        .hero-actions .btn-outline-light {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.20);
            backdrop-filter: blur(4px);
        }
        .hero-actions .btn-outline-light:hover {
            background: rgba(255,255,255,0.25);
        }

        /* ── ALERTS ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            font-size: 14px;
        }
        .alert-icon { font-size: 16px; margin-top: 1px; flex-shrink: 0; }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-left: 3px solid #dc2626;
        }
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border-left: 3px solid #10b981;
        }

        /* ── EDIT GRID ── */
        .edit-grid {
            display: grid;
            grid-template-columns: minmax(0,1fr) 360px;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 900px) {
            .edit-grid { grid-template-columns: 1fr; }
        }

        /* ── CARDS ── */
        .card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: box-shadow .22s, transform .22s;
            margin-bottom: 0;
        }
        .card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 22px;
            background: linear-gradient(135deg, var(--navy-deep) 0%, var(--navy-mid) 100%);
        }
        .card-header-icon {
            width: 34px; height: 34px;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.15);
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            font-size: 14px;
            flex-shrink: 0;
        }
        .card-header-text { flex: 1; }
        .card-header-title { font-size: 14px; font-weight: 600; color: #fff; }
        .card-header-sub   { font-size: 12px; color: rgba(255,255,255,0.6); }
        .card-header-badge { margin-left: auto; }

        .card-body { padding: 24px; }

        /* ── FORM FIELDS ── */
        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        @media (max-width: 580px) { .field-grid { grid-template-columns: 1fr; } }

        .field { display: flex; flex-direction: column; gap: 6px; }
        .field-full { grid-column: 1 / -1; }

        .field-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .field-input {
            padding: 11px 14px;
            background: var(--sand-faint);
            border: 1.5px solid transparent;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
            color: var(--text-primary);
            transition: border-color .18s, box-shadow .18s, background .18s;
            outline: none;
            width: 100%;
        }
        .field-input::placeholder { color: var(--text-muted); }
        .field-input:hover  { border-color: var(--sand); }
        .field-input:focus  {
            border-color: var(--navy-light);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.12);
        }

        textarea.field-input { resize: vertical; min-height: 100px; }

        /* ── TOGGLE SWITCH ── */
        .toggle-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            background: var(--sand-faint);
            border-radius: var(--radius-sm);
            border: 1.5px solid transparent;
            cursor: pointer;
            transition: border-color .18s;
            grid-column: 1 / -1;
        }
        .toggle-row:hover { border-color: var(--sand); }

        .toggle-switch {
            position: relative;
            width: 44px; height: 24px;
            flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-track {
            position: absolute; inset: 0;
            background: var(--sand);
            border-radius: 999px;
            transition: background .2s;
            cursor: pointer;
        }
        .toggle-track::after {
            content: '';
            position: absolute;
            left: 3px; top: 3px;
            width: 18px; height: 18px;
            border-radius: 50%;
            background: #fff;
            transition: transform .2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .toggle-switch input:checked + .toggle-track { background: var(--navy-mid); }
        .toggle-switch input:checked + .toggle-track::after { transform: translateX(20px); }

        .toggle-label-text { font-size: 14px; font-weight: 500; color: var(--text-body); }
        .toggle-label-sub  { font-size: 12px; color: var(--text-muted); }

        /* ── FORM ACTIONS ── */
        .card-footer {
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: flex-end;
            padding: 14px 22px;
            border-top: 1px solid var(--border-light);
            background: var(--sand-faint);
        }

        /* ── BUTTONS ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all .18s;
        }
        .btn:active { transform: scale(0.97); }

        .btn-primary {
            background: linear-gradient(135deg, var(--navy-deep) 0%, var(--navy-mid) 100%);
            color: #fff;
            box-shadow: 0 2px 8px rgba(27,60,83,0.22);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--navy-mid) 0%, var(--navy-light) 100%);
            box-shadow: 0 4px 16px rgba(27,60,83,0.30);
            transform: translateY(-1px);
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border: 1.5px solid var(--border-light);
        }
        .btn-ghost:hover { background: var(--white); color: var(--text-body); border-color: var(--sand); }

        .btn-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1.5px solid rgba(155,35,53,0.2);
        }
        .btn-danger:hover {
            background: #dc2626;
            color: #fff;
            border-color: #dc2626;
        }

        .btn-accent {
            background: var(--sand-faint);
            color: var(--navy-light);
            border: 1.5px solid var(--border-light);
        }
        .btn-accent:hover { background: var(--white); box-shadow: var(--shadow-sm); }

        .btn-sm { padding: 7px 14px; font-size: 12.5px; }

        /* ── BADGES ── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-active   { background: #ecfdf5; color: #065f46; }
        .badge-inactive { background: #fef2f2; color: #991b1b; }

        /* ── SIDEBAR PROFILE CARD ── */
        .profile-card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: box-shadow .22s;
            margin-bottom: 20px;
        }
        .profile-card:hover { box-shadow: var(--shadow-md); }

        .profile-banner { display: none; }

        .profile-avatar-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 20px 20px;
            margin-top: 0;
        }
        .profile-avatar {
            width: 72px; height: 72px;
            border-radius: 16px;
            object-fit: cover;
            border: 3px solid var(--white);
            box-shadow: var(--shadow-md);
            background: var(--sand-faint);
            display: block;
        }
        .profile-name { font-size: 15px; font-weight: 700; color: var(--text-primary); margin-top: 10px; text-align: center; }
        .profile-role { font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 2px; }
        .profile-change-btn { margin-top: 12px; width: 100%; text-align: center; }

        /* ── STATS CARD ── */
        .stats-list { display: flex; flex-direction: column; gap: 14px; }
        .stat-row { display: flex; flex-direction: column; gap: 5px; }
        .stat-meta { display: flex; justify-content: space-between; align-items: baseline; }
        .stat-label { font-size: 12px; color: var(--text-muted); font-weight: 500; }
        .stat-value { font-size: 13px; font-weight: 700; color: var(--navy-deep); }
        .stat-bar-track {
            height: 6px;
            background: var(--sand-faint);
            border-radius: 999px;
            overflow: hidden;
            border: 1px solid var(--border-light);
        }
        .stat-bar-fill {
            height: 100%;
            border-radius: 999px;
            transition: width .6s ease;
        }
        .bar-blue   { background: linear-gradient(90deg, var(--navy-deep), var(--navy-light)); }
        .bar-green  { background: linear-gradient(90deg, #0D7B5E, #2ECC9A); }
        .bar-purple { background: linear-gradient(90deg, #5B3FA8, #9B6FE0); }

        /* ── PASSWORD STRENGTH ── */
        .pw-strength-bar {
            height: 4px;
            border-radius: 999px;
            margin-top: 6px;
            transition: all .3s;
            background: var(--sand);
        }
        .pw-strength-bar.s1 { width: 25%; background: #dc2626; }
        .pw-strength-bar.s2 { width: 50%; background: #f59e0b; }
        .pw-strength-bar.s3 { width: 75%; background: var(--navy-light); }
        .pw-strength-bar.s4 { width: 100%; background: #10b981; }

        .pw-reqs { margin-top: 8px; }
        .pw-req  { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
        .pw-req.valid   { color: #10b981; }
        .pw-req.invalid { color: #dc2626; }
        .pw-match { font-size: 12px; margin-top: 4px; }

        /* ── MODAL ── */
        .modal-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: rgba(15,34,51,0.55);
            z-index: 1000;
            backdrop-filter: blur(4px);
            justify-content: center;
            align-items: center;
        }
        .modal-backdrop.open { display: flex; }

        .modal {
            background: var(--white);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 460px;
            box-shadow: var(--shadow-lg);
            animation: modalIn .25s ease;
            overflow: hidden;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-16px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
        }
        .modal-header-icon {
            width: 32px; height: 32px;
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
        }
        .modal-header-icon.danger  { background: #fef2f2; color: #dc2626; }
        .modal-header-icon.primary { background: var(--sand-faint); color: var(--navy-deep); }

        .modal-title { font-size: 15px; font-weight: 700; color: var(--text-primary); flex: 1; }
        .modal-close {
            background: none; border: none; cursor: pointer;
            color: var(--text-muted); font-size: 18px; padding: 4px;
            border-radius: 6px; transition: background .15s;
        }
        .modal-close:hover { background: var(--sand-faint); }

        .modal-body   { padding: 20px; }
        .modal-footer {
            display: flex; gap: 10px; justify-content: flex-end;
            padding: 14px 20px;
            border-top: 1px solid var(--border-light);
            background: var(--sand-faint);
        }

        /* ── PREVIEW IMAGE ── */
        .preview-wrap { display: flex; justify-content: center; margin-bottom: 18px; }
        .preview-img  { width: 120px; height: 120px; object-fit: cover; border-radius: 16px; border: 3px solid var(--border-light); }

        .file-drop-zone {
            border: 2px dashed var(--sand);
            border-radius: var(--radius-md);
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color .18s, background .18s;
            position: relative;
        }
        .file-drop-zone:hover { border-color: var(--navy-light); background: var(--sand-faint); }
        .file-drop-zone i { font-size: 28px; color: var(--navy-light); display: block; margin-bottom: 6px; }
        .file-drop-zone span { font-size: 13px; color: var(--text-muted); display: block; }
        .file-drop-zone .hint { font-size: 11px; color: var(--sand); margin-top: 4px; }
        .file-drop-zone input {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }

        /* ── LOADING SPINNER ── */
        .loading-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(255,255,255,0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 12px;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 44px; height: 44px;
            border: 4px solid var(--sand-faint);
            border-top-color: var(--navy-deep);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner-text { font-size: 13px; color: var(--text-muted); font-weight: 500; }
    </style>
</head>
<body>

<?php include '../header.php'; ?>
<?php include '../sidebar.php'; ?>

<!-- Loading overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <div class="spinner-text">Saving changes…</div>
</div>

<div class="main-area">
    <!-- Topbar -->
    <header class="topbar">
        <button class="topbar-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-title">
            <div class="topbar-title-icon"><i class="fas fa-user-pen"></i></div>
            Edit Trainer
        </div>
        <div class="topbar-actions">
            <a href="index.php" class="btn btn-ghost btn-sm">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </header>

    <div class="content">

        <!-- ── HERO BANNER ── -->
        <div class="hero-banner">
            <div class="bubble"></div>
            <div class="bubble"></div>
            <div class="bubble"></div>
            <div class="bubble"></div>

            <div class="hero-content">
                <div class="hero-avatar-wrap">
                    <img src="<?= !empty($trainer['profile_picture']) ? $trainer['profile_picture'] : '../assets/images/default-avatar.svg' ?>"
                         class="hero-avatar"
                         alt="<?= htmlspecialchars($trainer['name']) ?>"
                         onerror="this.src='../assets/images/default-avatar.svg'">
                    <span class="hero-status <?= $trainer['is_active'] ? 'active' : 'inactive' ?>"></span>
                </div>
                <div class="hero-text">
                    <div class="hero-pill" style="margin-bottom:4px;">
                        <i class="fas fa-pen-to-square"></i> Edit Trainer
                    </div>
                    <h1><?= htmlspecialchars($trainer['name']) ?></h1>
                    <div class="hero-sub">
                        <span><?= htmlspecialchars($trainer['email']) ?></span>
                        <span class="badge" style="background:<?= $trainer['is_active'] ? 'rgba(16,185,129,0.25)' : 'rgba(148,163,184,0.25)' ?>; border-color:<?= $trainer['is_active'] ? 'rgba(16,185,129,0.3)' : 'rgba(148,163,184,0.3)' ?>;">
                            <?= $trainer['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                        <?php if ($trainer['specialization']): ?>
                        <span class="badge badge-spec"><?= htmlspecialchars($trainer['specialization']) ?></span>
                        <?php endif; ?>
                        <span style="font-size:0.7rem; opacity:0.6;">ID: <?= $trainer['id'] ?></span>
                    </div>
                </div>
                <div class="hero-actions">
                    <a href="view.php?id=<?= $trainerId ?>" class="btn btn-outline-light"><i class="fas fa-eye"></i> View Profile</a>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-circle-exclamation alert-icon"></i>
            <div>
                <strong>Please fix the following:</strong>
                <ul style="margin-top:6px; padding-left:18px;">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php elseif (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-circle-check alert-icon"></i>
            <div><?= htmlspecialchars($_SESSION['success_message']) ?></div>
        </div>
        <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <div class="edit-grid">

            <!-- ── LEFT: Main form ── -->
            <div style="display:flex; flex-direction:column; gap:20px;">

                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon"><i class="fas fa-circle-user"></i></div>
                        <div class="card-header-text">
                            <div class="card-header-title">Trainer Information</div>
                            <div class="card-header-sub">Professional profile & credentials</div>
                        </div>
                        <div class="card-header-badge">
                            <span class="badge <?= $trainer['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                <i class="fas <?= $trainer['is_active'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                                <?= $trainer['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                    </div>

                    <form method="POST" id="trainerForm">
                        <div class="card-body">
                            <div class="field-grid">
                                <div class="field">
                                    <label class="field-label" for="name">Full Name</label>
                                    <input type="text" id="name" name="name" class="field-input"
                                           value="<?= htmlspecialchars($trainer['name']) ?>"
                                           placeholder="Trainer full name" required>
                                </div>
                                <div class="field">
                                    <label class="field-label" for="email">Email Address</label>
                                    <input type="email" id="email" name="email" class="field-input"
                                           value="<?= htmlspecialchars($trainer['email']) ?>"
                                           placeholder="trainer@example.com" required>
                                </div>
                                <div class="field">
                                    <label class="field-label" for="specialization">Specialization</label>
                                    <input type="text" id="specialization" name="specialization" class="field-input"
                                           value="<?= htmlspecialchars($trainer['specialization']) ?>"
                                           placeholder="e.g. Python, Data Science">
                                </div>
                                <div class="field">
                                    <label class="field-label" for="experience">Years of Experience</label>
                                    <input type="number" id="experience" name="experience" class="field-input"
                                           value="<?= htmlspecialchars($trainer['years_of_experience']) ?>"
                                           min="0" placeholder="0">
                                </div>
                                <div class="field field-full">
                                    <label class="field-label" for="bio">Bio / Description</label>
                                    <textarea id="bio" name="bio" class="field-input"
                                              placeholder="Brief professional bio…"><?= htmlspecialchars($trainer['bio']) ?></textarea>
                                </div>
                                
                                <!-- Status toggle -->
                                <div class="toggle-row">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="is_active" name="is_active" <?= $trainer['is_active'] ? 'checked' : '' ?>>
                                        <span class="toggle-track"></span>
                                    </label>
                                    <div>
                                        <div class="toggle-label-text">Trainer is active</div>
                                        <div class="toggle-label-sub">Inactive trainers cannot be assigned to batches.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <button type="button" onclick="openPasswordModal()" class="btn btn-danger btn-sm">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                            <a href="view.php?id=<?= $trainerId ?>" class="btn btn-accent btn-sm">
                                <i class="fas fa-eye"></i> View Profile
                            </a>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-floppy-disk"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

            </div><!-- /left -->

            <!-- ── RIGHT: Sidebar cards ── -->
            <div style="display:flex; flex-direction:column; gap:20px;">

                <!-- Profile photo card -->
                <div class="profile-card">
                    <div class="profile-banner"></div>
                    <div class="profile-avatar-wrap">
                        <img src="<?= !empty($trainer['profile_picture']) ? $trainer['profile_picture'] : '../assets/images/default-avatar.svg' ?>"
                             class="profile-avatar"
                             alt="<?= htmlspecialchars($trainer['name']) ?>"
                             id="profileImagePreview">
                        <div class="profile-name"><?= htmlspecialchars($trainer['name']) ?></div>
                        <div class="profile-role">Trainer — <?= htmlspecialchars($trainer['specialization'] ?: 'General') ?></div>
                        <div class="profile-change-btn">
                            <button type="button" onclick="openProfileModal()" class="btn btn-primary btn-sm" style="width:100%; justify-content:center;">
                                <i class="fas fa-camera"></i> Change Photo
                            </button>
                        </div>
                        <p style="font-size:11px; color:var(--text-muted); text-align:center; margin-top:8px; line-height:1.5;">
                            500×500px recommended<br>JPG or PNG · Max 2 MB
                        </p>
                    </div>
                </div>

                <!-- Performance stats card -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-header-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="card-header-text">
                            <div class="card-header-title">Performance</div>
                            <div class="card-header-sub">This trainer's metrics</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="stats-list">
                            <div class="stat-row">
                                <div class="stat-meta">
                                    <span class="stat-label">Trainer Rating</span>
                                    <span class="stat-value">4.8 / 5</span>
                                </div>
                                <div class="stat-bar-track">
                                    <div class="stat-bar-fill bar-blue" style="width:96%"></div>
                                </div>
                            </div>
                            <div class="stat-row">
                                <div class="stat-meta">
                                    <span class="stat-label">Active Batches</span>
                                    <span class="stat-value">3</span>
                                </div>
                                <div class="stat-bar-track">
                                    <div class="stat-bar-fill bar-green" style="width:60%"></div>
                                </div>
                            </div>
                            <div class="stat-row">
                                <div class="stat-meta">
                                    <span class="stat-label">Completion Rate</span>
                                    <span class="stat-value">92%</span>
                                </div>
                                <div class="stat-bar-track">
                                    <div class="stat-bar-fill bar-purple" style="width:92%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /right -->
        </div><!-- /edit-grid -->
    </div><!-- /content -->
</div><!-- /main-area -->


<!-- ══ PASSWORD CHANGE MODAL ══ -->
<div class="modal-backdrop" id="passwordBackdrop">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-icon danger"><i class="fas fa-key"></i></div>
            <span class="modal-title">Change Password</span>
            <button class="modal-close" onclick="closePasswordModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="passwordForm" method="POST">
                <input type="hidden" name="change_password" value="1">
                <div class="field" style="margin-bottom:16px;">
                    <label class="field-label" for="new_password">New Password</label>
                    <input type="password" class="field-input" id="new_password" name="new_password"
                           placeholder="Min 8 characters" required>
                    <div class="pw-strength-bar" id="pwStrengthBar"></div>
                    <div class="pw-reqs" id="pwReqs">
                        <div class="pw-req" id="req-length"><i class="fas fa-circle"></i> At least 8 characters</div>
                        <div class="pw-req" id="req-lower"><i class="fas fa-circle"></i> One lowercase letter</div>
                        <div class="pw-req" id="req-upper"><i class="fas fa-circle"></i> One uppercase letter</div>
                        <div class="pw-req" id="req-number"><i class="fas fa-circle"></i> One number or special character</div>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="confirm_password">Confirm Password</label>
                    <input type="password" class="field-input" id="confirm_password" name="confirm_password"
                           placeholder="Repeat new password" required>
                    <div class="pw-match" id="pwMatch"></div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost btn-sm" onclick="closePasswordModal()">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="submitPasswordForm()">
                <i class="fas fa-floppy-disk"></i> Update Password
            </button>
        </div>
    </div>
</div>

<!-- ══ PROFILE PICTURE MODAL ══ -->
<div class="modal-backdrop" id="profileBackdrop">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-icon primary"><i class="fas fa-camera"></i></div>
            <span class="modal-title">Change Profile Photo</span>
            <button class="modal-close" onclick="closeProfileModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="profileForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="change_profile_picture" value="1">
                <div class="preview-wrap">
                    <img id="imagePreview"
                         src="<?= !empty($trainer['profile_picture']) ? $trainer['profile_picture'] : '../assets/images/default-avatar.svg' ?>"
                         class="preview-img" alt="Preview">
                </div>
                <div class="file-drop-zone">
                    <i class="fas fa-cloud-arrow-up"></i>
                    <span>Click to browse or drag & drop</span>
                    <span class="hint">JPG, PNG — max 2 MB</span>
                    <input type="file" id="profile_picture" name="profile_picture"
                           accept="image/jpeg,image/png" onchange="previewImage(this)">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost btn-sm" onclick="closeProfileModal()">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="submitProfileForm()">
                <i class="fas fa-floppy-disk"></i> Upload Photo
            </button>
        </div>
    </div>
</div>


<!-- JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function() {
    // Loading spinner on main form submit
    $('#trainerForm').on('submit', function() {
        $('#loadingOverlay').addClass('active');
    });

    // Sidebar toggle (mobile)
    window.toggleSidebar = function() { $('.sidebar').toggleClass('hidden'); };

    // Dirty form warning
    let formChanged = false;
    $('input, textarea, select').on('change keyup', function() { formChanged = true; });
    window.addEventListener('beforeunload', function(e) {
        if (formChanged) { e.preventDefault(); e.returnValue = ''; }
    });

    // ── Password strength ──
    function checkPwStrength(pw) {
        const reqs = {
            length:  pw.length >= 8,
            lower:   /[a-z]/.test(pw),
            upper:   /[A-Z]/.test(pw),
            number:  /[0-9!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pw)
        };
        ['length','lower','upper','number'].forEach(k => {
            const $el = $('#req-' + k);
            $el.toggleClass('valid', reqs[k]).toggleClass('invalid', !reqs[k]);
            $el.find('i').removeClass('fa-circle fa-check-circle').addClass(reqs[k] ? 'fa-check-circle' : 'fa-circle');
        });
        const score = Object.values(reqs).filter(Boolean).length;
        $('#pwStrengthBar').removeClass('s1 s2 s3 s4').addClass('s' + score);
        return score;
    }

    $('#new_password').on('keyup', function() { checkPwStrength($(this).val()); });

    $('#confirm_password').on('keyup', function() {
        const pw = $('#new_password').val(), cpw = $(this).val();
        const $m = $('#pwMatch');
        if (!cpw) { $m.text('').css('color',''); return; }
        if (pw === cpw) $m.text('✓ Passwords match').css('color','var(--success)');
        else            $m.text('✗ Passwords do not match').css('color','var(--danger)');
    });

    // ── Success alert (SweetAlert2) ──
    <?php if ($success || $passwordChangeSuccess || $profilePictureSuccess): ?>
    Swal.fire({
        title: 'Saved!',
        text: '<?= $profilePictureSuccess ? "Profile photo updated." : ($passwordChangeSuccess ? "Password changed successfully." : "Trainer profile saved.") ?>',
        icon: 'success',
        showConfirmButton: false,
        timer: 2200,
        timerProgressBar: true,
        toast: true,
        position: 'top-end'
    });
    <?php endif; ?>
});

// ── Password modal ──
window.openPasswordModal = function() {
    $('#passwordBackdrop').addClass('open');
    setTimeout(() => $('#new_password').focus(), 200);
    $('#pwStrengthBar').removeClass('s1 s2 s3 s4');
    $('#pwMatch').text('');
    ['length','lower','upper','number'].forEach(k => {
        $('#req-' + k).removeClass('valid invalid');
        $('#req-' + k + ' i').removeClass('fa-check-circle').addClass('fa-circle');
    });
};
window.closePasswordModal = function() {
    $('#passwordBackdrop').removeClass('open');
    $('#passwordForm')[0].reset();
    $('#pwStrengthBar').removeClass('s1 s2 s3 s4');
    $('#pwMatch').text('');
};
window.submitPasswordForm = function() {
    const pw = $('#new_password').val(), cpw = $('#confirm_password').val();
    const hasL = /[a-z]/.test(pw), hasU = /[A-Z]/.test(pw), hasN = /[0-9!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pw);

    if (!pw || pw.length < 8) {
        return Swal.fire({ title: 'Too short', text: 'Password must be at least 8 characters.', icon: 'error', confirmButtonColor: 'var(--primary)' });
    }
    if (!hasL || !hasU || !hasN) {
        return Swal.fire({ title: 'Weak password', text: 'Include uppercase, lowercase, and a number or symbol.', icon: 'error', confirmButtonColor: 'var(--primary)' });
    }
    if (pw !== cpw) {
        return Swal.fire({ title: 'Mismatch', text: 'Passwords do not match.', icon: 'error', confirmButtonColor: 'var(--primary)' });
    }
    Swal.fire({
        title: 'Confirm password change?',
        text: 'The trainer will need to use the new password on next login.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: 'var(--primary)',
        cancelButtonColor: 'var(--neutral)',
        confirmButtonText: 'Yes, change it'
    }).then(r => { if (r.isConfirmed) { $('#loadingOverlay').addClass('active'); $('#passwordForm').submit(); } });
};

// ── Profile picture modal ──
window.openProfileModal = function() { $('#profileBackdrop').addClass('open'); };
window.closeProfileModal = function() {
    $('#profileBackdrop').removeClass('open');
    $('#profileForm')[0].reset();
    $('#imagePreview').attr('src', $('#profileImagePreview').attr('src'));
};
window.previewImage = function(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (!['image/jpeg','image/png'].includes(file.type)) {
        Swal.fire({ title: 'Invalid file', text: 'Only JPG and PNG are allowed.', icon: 'error', confirmButtonColor: 'var(--primary)' });
        input.value = ''; return;
    }
    if (file.size > 2097152) {
        Swal.fire({ title: 'File too large', text: 'Max file size is 2 MB.', icon: 'error', confirmButtonColor: 'var(--primary)' });
        input.value = ''; return;
    }
    const reader = new FileReader();
    reader.onload = e => { $('#imagePreview').attr('src', e.target.result); };
    reader.readAsDataURL(file);
};
window.submitProfileForm = function() {
    if (!$('#profile_picture').val()) {
        return Swal.fire({ title: 'No file selected', text: 'Please choose an image first.', icon: 'error', confirmButtonColor: 'var(--primary)' });
    }
    $('#loadingOverlay').addClass('active');
    $('#profileForm').submit();
};

// Close modals on backdrop click
document.getElementById('passwordBackdrop').addEventListener('click', function(e) {
    if (e.target === this) closePasswordModal();
});
document.getElementById('profileBackdrop').addEventListener('click', function(e) {
    if (e.target === this) closeProfileModal();
});
</script>
</body>
</html>
