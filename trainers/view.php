<?php
require_once '../db_connection.php';
require_once 'functions.php';

// Check admin permissions
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$trainerId = (int)$_GET['id'];

// Fetch trainer data
$stmt = $db->prepare("SELECT t.*, u.email, u.created_at as user_created 
                      FROM trainers t 
                      JOIN users u ON t.user_id = u.id 
                      WHERE t.id = :id");
$stmt->bindParam(':id', $trainerId, PDO::PARAM_INT);
$stmt->execute();
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    header('Location: index.php');
    exit;
}

// Get trainer stats
$batchCount = getTrainerBatchCount($trainerId);
$avgRating = getTrainerAverageRating($trainerId);

// Get batches assigned to this trainer
$stmt = $db->prepare("SELECT b.* 
                      FROM batches b
                      WHERE b.batch_mentor_id = :id
                      ORDER BY b.start_date DESC
                      LIMIT 5");
$stmt->bindParam(':id', $trainerId, PDO::PARAM_INT);
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent feedback for batches taught by this trainer - FIXED QUERY
$stmt = $db->prepare("SELECT f.*, s.first_name, s.last_name, b.batch_name 
                      FROM feedback f
                      JOIN batches b ON f.batch_id = b.batch_id
                      JOIN students s ON f.student_name = CONCAT(s.first_name, ' ', s.last_name)
                      WHERE b.batch_mentor_id = :id
                      ORDER BY f.date DESC
                      LIMIT 5");
$stmt->bindParam(':id', $trainerId, PDO::PARAM_INT);
$stmt->execute();
$feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get trainer documents
$stmt = $db->prepare("SELECT * FROM trainer_documents WHERE trainer_id = ? ORDER BY document_type");
$stmt->execute([$trainerId]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $document_type = $_POST['document_type'];
    $allowed_types = ['resume', 'certification', 'degree', 'id_proof', 'other'];
    
    if (in_array($document_type, $allowed_types)) {
        $upload_dir = '../uploads/trainer_documents/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Check if file was uploaded without errors
        if ($_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = "File upload error: " . $_FILES['document_file']['error'];
            $_SESSION['show_upload_modal'] = true;
            header("Location: view.php?id=$trainerId");
            exit();
        }
        
        // Validate file type
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $file_extension = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $_SESSION['error_message'] = "Invalid file type. Allowed types: PDF, JPG, JPEG, PNG, DOC, DOCX";
            $_SESSION['show_upload_modal'] = true;
            header("Location: view.php?id=$trainerId");
            exit();
        }
        
        $file_name = $trainerId . '_' . $document_type . '_' . time() . '_' . basename($_FILES['document_file']['name']);
        $target_file = $upload_dir . $file_name;
        
        // Check if file already exists for this document type
        $stmt = $db->prepare("SELECT * FROM trainer_documents WHERE trainer_id = ? AND document_type = ?");
        $stmt->execute([$trainerId, $document_type]);
        $existing_doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_doc) {
            // Delete old file
            if (file_exists($existing_doc['file_path'])) {
                unlink($existing_doc['file_path']);
            }
            
            // Update record
            $stmt = $db->prepare("UPDATE trainer_documents SET file_path = ? WHERE document_id = ?");
            $stmt->execute([$target_file, $existing_doc['document_id']]);
        } else {
            // Insert new record
            $stmt = $db->prepare("INSERT INTO trainer_documents (trainer_id, document_type, file_path) VALUES (?, ?, ?)");
            $stmt->execute([$trainerId, $document_type, $target_file]);
        }
        
        if (move_uploaded_file($_FILES['document_file']['tmp_name'], $target_file)) {
            $_SESSION['success_message'] = "Document uploaded successfully.";
            header("Location: view.php?id=$trainerId");
            exit();
        } else {
            $_SESSION['error_message'] = "Sorry, there was an error uploading your file.";
            $_SESSION['show_upload_modal'] = true;
            header("Location: view.php?id=$trainerId");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Invalid document type selected.";
        $_SESSION['show_upload_modal'] = true;
    }
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    $document_id = $_POST['document_id'];
    
    $stmt = $db->prepare("SELECT * FROM trainer_documents WHERE document_id = ? AND trainer_id = ?");
    $stmt->execute([$document_id, $trainerId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($doc) {
        if (file_exists($doc['file_path'])) {
            unlink($doc['file_path']);
        }
        
        $stmt = $db->prepare("DELETE FROM trainer_documents WHERE document_id = ?");
        $stmt->execute([$document_id]);
        
        $_SESSION['success_message'] = "Document deleted successfully.";
        header("Location: view.php?id=$trainerId");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($trainer['name']) ?> — ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════════════════════
           DESIGN SYSTEM — Navy/Sand Theme (matches admin_dashboard & batches)
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

        .layout { display: flex; min-height: 100vh; }
        .main-area { flex: 1; margin-left: var(--sidebar-w); display: flex; flex-direction: column; }

        /* ── Top Bar ── */
        .topbar {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,253,248,0.92);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-light);
            padding: 0 32px;
            height: 64px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 1px 0 0 rgba(69,104,130,0.08);
        }
        .topbar-left { display: flex; align-items: center; gap: 12px; }
        .topbar-back {
            display: flex; align-items: center; gap: 6px;
            font-size: 13px; font-weight: 500; color: var(--text-muted);
            text-decoration: none; transition: color 0.15s;
        }
        .topbar-back:hover { color: var(--navy-deep); }
        .topbar-divider { color: var(--border-medium); font-size: 16px; }
        .topbar-title {
            font-size: 15px; font-weight: 700; color: var(--text-primary);
            letter-spacing: -0.01em;
        }
        .topbar-right { display: flex; gap: 8px; }
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 13.5px; font-weight: 500; font-family: 'Inter', sans-serif;
            padding: 0 16px; height: 36px; border-radius: var(--radius-sm);
            cursor: pointer; transition: all 0.18s ease;
            text-decoration: none; border: none; white-space: nowrap;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            color: #fff;
            box-shadow: 0 2px 10px rgba(27,60,83,0.25);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
            box-shadow: 0 6px 20px rgba(27,60,83,0.35);
            transform: translateY(-1px);
            color: #fff;
        }
        .btn-ghost {
            background: rgba(255,255,255,0.7);
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
            backdrop-filter: blur(4px);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.9);
            color: var(--navy-deep);
            border-color: var(--border-medium);
        }
        .btn-sm { height: 30px; padding: 0 12px; font-size: 12.5px; }

        .page-content { padding: 28px 32px; flex: 1; }

        /* ── Hero Banner (same as batches) ── */
        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 30%, #456882 60%, #D2C1B6 100%);
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(27,60,83,0.35), 0 6px 20px rgba(35,76,106,0.25);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(210,193,182,0.20);
            padding: 20px 28px 16px;
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
            padding: 6px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.20);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.25);
            color: #f7f5f3;
            font-size: 0.78rem;
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
            gap: 20px;
        }
        .hero-avatar-wrap {
            flex-shrink: 0;
            position: relative;
        }
        .hero-avatar {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,0.25);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            background: var(--sand-faint);
        }
        .hero-status {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.6);
        }
        .hero-status.active { background: #10b981; }
        .hero-status.inactive { background: #94a3b8; }

        .hero-text {
            flex: 1;
            min-width: 200px;
        }
        .hero-text h1 {
            font-size: 1.6rem;
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
            gap: 12px;
            margin-top: 4px;
        }
        .hero-text .hero-sub span {
            color: rgba(255,255,255,0.85);
            font-size: 0.9rem;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        .hero-text .hero-sub .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: rgba(255,255,255,0.20);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
        }
        .hero-text .hero-sub .badge-spec {
            background: rgba(210,193,182,0.25);
            border-color: rgba(255,255,255,0.15);
        }
        .hero-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-left: auto;
        }
        .hero-actions .btn {
            height: 34px;
            padding: 0 16px;
            font-size: 12.5px;
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

        /* ── KPI Cards (same as admin_dashboard) ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 14px 16px 12px;
            border: 2px solid rgba(69,104,130,0.25);
            box-shadow: 0 4px 20px rgba(27,60,83,0.08), inset 0 1px 0 rgba(255,255,255,0.7);
            transition: transform .3s cubic-bezier(.4,0,.2,1), box-shadow .3s;
            position: relative;
            overflow: hidden;
            cursor: default;
            color: #1B3C53;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: linear-gradient(180deg, #1B3C53, #234C6A);
            transition: width 0.4s ease;
            border-radius: 18px 0 0 18px;
        }
        .kpi-card:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px rgba(27,60,83,0.18), inset 0 1px 0 rgba(255,255,255,0.7);
        }
        .kpi-card:hover::before { width: 100%; opacity: 0.08; }

        .kpi-icon {
            position: absolute; top: 12px; right: 12px;
            width: 40px; height: 40px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .kpi-icon-blue   { background: linear-gradient(135deg,#234C6A,#456882); }
        .kpi-icon-green  { background: linear-gradient(135deg,#D2C1B6,#456882); }
        .kpi-icon-purple { background: linear-gradient(135deg,#1B3C53,#234C6A); }
        .kpi-icon-violet { background: linear-gradient(135deg,#456882,#D2C1B6); }
        .kpi-icon-pink   { background: linear-gradient(135deg,#1B3C53,#456882); }

        .kpi-label { font-size: .65rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #456882; }
        .kpi-value { font-size: 1.6rem; font-weight: 900; line-height: 1.1; color: #1B3C53; }
        .kpi-sub  { font-size: .7rem; color: #456882; font-weight: 500; }

        .kpi-bar-wrap { height: 4px; border-radius: 99px; background: #e2e8f0; margin-top: 6px; overflow: hidden; }
        .kpi-bar { height: 100%; border-radius: 99px; background: linear-gradient(90deg,#1B3C53,#234C6A,#456882); }

        .star-row-kpi { display: flex; gap: 2px; margin-top: 2px; }
        .star-row-kpi i { font-size: 12px; }
        .star-filled-kpi { color: #f59e0b; }
        .star-empty-kpi { color: #d1d5db; }

        /* ── Two Column Layout ── */
        .two-col {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 20px;
            align-items: start;
        }

        /* ── Cards ── */
        .card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.2s;
        }
        .card:hover {
            box-shadow: var(--shadow-md);
        }
        .card-header {
            padding: 16px 22px 0;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-title-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            background: rgba(69,104,130,0.12);
            color: var(--navy-mid);
        }
        .card-link {
            font-size: 12.5px;
            font-weight: 500;
            color: var(--navy-mid);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: color 0.15s;
        }
        .card-link:hover { color: var(--navy-deep); }

        .card-body { padding: 18px 22px 20px; }

        /* ── Profile Side Items ── */
        .profile-side-item {
            display: flex;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .profile-side-item:last-child { border-bottom: none; }
        .profile-side-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
            margin-top: 1px;
            background: rgba(69,104,130,0.10);
            color: var(--navy-mid);
        }
        .profile-side-label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .profile-side-val {
            font-size: 13.5px;
            font-weight: 500;
            color: var(--text-primary);
            margin-top: 1px;
        }

        /* ── Batch Table ── */
        .batch-table { width: 100%; border-collapse: collapse; }
        .batch-table th {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-muted);
            padding: 0 0 10px;
            text-align: left;
        }
        .batch-table td {
            padding: 12px 0;
            border-top: 1px solid var(--border-light);
            font-size: 13.5px;
            color: var(--text-secondary);
        }
        .batch-table td:first-child { font-weight: 600; color: var(--text-primary); }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 20px;
        }
        .status-upcoming { background: #EEF4F8; color: #234C6A; }
        .status-ongoing  { background: #ECFDF5; color: #15803D; }
        .status-completed{ background: #F1F5F9; color: #64748B; }

        /* ── Feedback ── */
        .feedback-item {
            padding: 14px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .feedback-item:last-child { border-bottom: none; }
        .feedback-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        .feedback-author {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .feedback-batch {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 1px;
        }
        .feedback-rating {
            display: flex;
            gap: 2px;
        }
        .feedback-rating i { font-size: 11px; }
        .feedback-date {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 3px;
            text-align: right;
        }
        .feedback-text {
            font-size: 13px;
            color: var(--text-secondary);
            padding: 10px 12px;
            background: var(--sand-faint);
            border-radius: var(--radius-sm);
            border-left: 3px solid var(--border-medium);
            font-style: italic;
        }
        .star-filled { color: #f59e0b; }
        .star-empty { color: #d1d5db; }

        /* ── Documents ── */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
        }
        .doc-card {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 16px;
            text-align: center;
            transition: all 0.2s;
            background: #fff;
        }
        .doc-card:hover {
            border-color: var(--navy-light);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        .doc-icon { font-size: 28px; margin-bottom: 8px; }
        .doc-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .doc-date {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-bottom: 10px;
        }
        .doc-actions { display: flex; gap: 6px; justify-content: center; }
        .doc-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11.5px;
            font-weight: 500;
            padding: 4px 10px;
            border-radius: 5px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.14s;
        }
        .doc-btn-view { background: #EEF4F8; color: var(--navy-mid); }
        .doc-btn-view:hover { background: #dce8f0; }
        .doc-btn-del { background: #fef2f2; color: #dc2626; }
        .doc-btn-del:hover { background: #fee2e2; }

        .upload-zone-trigger {
            border: 2px dashed var(--border-medium);
            border-radius: var(--radius-lg);
            padding: 28px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            background: var(--sand-faint);
        }
        .upload-zone-trigger:hover {
            border-color: var(--navy-light);
            background: rgba(69,104,130,0.05);
        }
        .upload-zone-trigger input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .upload-zone-icon { font-size: 28px; color: var(--text-muted); margin-bottom: 8px; }
        .upload-zone-title { font-size: 14px; font-weight: 600; color: var(--text-secondary); }
        .upload-zone-sub { font-size: 12.5px; color: var(--text-muted); }

        /* ── Empty State ── */
        .empty-state {
            text-align: center;
            padding: 28px 16px;
        }
        .empty-icon {
            font-size: 32px;
            color: var(--border-medium);
            margin-bottom: 12px;
        }
        .empty-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .empty-sub {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* ── Alerts ── */
        .alert-success {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            border-radius: var(--radius);
            padding: 12px 18px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13.5px;
            font-weight: 500;
            color: #065f46;
        }
        .alert-error-msg {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: var(--radius);
            padding: 12px 18px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13.5px;
            font-weight: 500;
            color: #991b1b;
        }

        /* ── Modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 100;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: var(--radius-xl);
            width: 480px;
            max-width: 92vw;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--sand-faint);
        }
        .modal-title { font-size: 15px; font-weight: 700; }
        .modal-close {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            background: transparent;
            color: var(--text-muted);
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-close:hover { background: rgba(0,0,0,0.05); }
        .modal-body { padding: 24px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: var(--sand-faint);
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }
        .field-label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .field-select {
            height: 40px;
            padding: 0 13px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            background: #fff;
            cursor: pointer;
        }
        .field-select:focus {
            outline: none;
            border-color: var(--navy-light);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
        }
        .field input[type="file"] {
            font-size: 13.5px;
            font-family: 'Inter', sans-serif;
            padding: 8px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            width: 100%;
            background: #fff;
        }

        /* ── Responsive ── */
        @media (max-width: 1100px) {
            .two-col { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .main-area { margin-left: 0; }
            .page-content { padding: 16px; }
            .topbar { padding: 0 16px; }
            .hero-content { flex-direction: column; align-items: flex-start; }
            .hero-actions { margin-left: 0; width: 100%; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<?php include '../header.php'; ?>

<div class="layout">
    <?php include '../sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <a href="index.php" class="topbar-back"><i class="fas fa-arrow-left"></i> Trainers</a>
                <span class="topbar-divider">/</span>
                <span class="topbar-title"><?= htmlspecialchars($trainer['name']) ?></span>
            </div>
            <div class="topbar-right">
                <a href="edit.php?id=<?= $trainerId ?>" class="btn btn-primary"><i class="fas fa-pen"></i> Edit Trainer</a>
                <a href="performance.php?id=<?= $trainerId ?>" class="btn btn-ghost"><i class="fas fa-chart-line"></i> Analytics</a>
            </div>
        </header>

        <div class="page-content">
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?></div>
            <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert-error-msg"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?></div>
            <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- ── Hero Banner ── -->
            <div class="hero-banner">
                <div class="bubble"></div>
                <div class="bubble"></div>
                <div class="bubble"></div>
                <div class="bubble"></div>

                <div class="hero-content">
                    <div class="hero-avatar-wrap">
                        <img src="<?= getTrainerPhoto($trainer) ?>" class="hero-avatar"
                             alt="<?= htmlspecialchars($trainer['name']) ?>"
                             onerror="this.src='../assets/images/default-avatar.svg'">
                        <span class="hero-status <?= $trainer['is_active'] ? 'active' : 'inactive' ?>"></span>
                    </div>
                    <div class="hero-text">
                        <h1><?= htmlspecialchars($trainer['name']) ?></h1>
                        <div class="hero-sub">
                            <span><?= htmlspecialchars($trainer['email']) ?></span>
                            <span class="badge <?= $trainer['is_active'] ? '' : '' ?>" style="background:<?= $trainer['is_active'] ? 'rgba(16,185,129,0.25)' : 'rgba(148,163,184,0.25)' ?>; border-color:<?= $trainer['is_active'] ? 'rgba(16,185,129,0.3)' : 'rgba(148,163,184,0.3)' ?>;">
                                <?= $trainer['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                            <?php if ($trainer['specialization']): ?>
                            <span class="badge badge-spec"><?= htmlspecialchars($trainer['specialization']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:0.7rem; opacity:0.6;">ID: <?= $trainer['id'] ?></span>
                        </div>
                    </div>
                    <div class="hero-actions">
                        <a href="batches.php?id=<?= $trainerId ?>" class="btn btn-outline-light"><i class="fas fa-layer-group"></i> Batches</a>
                    </div>
                </div>
            </div>

            <!-- ── KPI Cards ── -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-blue"><i class="fas fa-layer-group text-white"></i></div>
                    <div class="kpi-label">Total Batches</div>
                    <div class="kpi-value"><?= $batchCount ?></div>
                    <div class="kpi-sub">Assigned to this trainer</div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= min(100, $batchCount * 10) ?>%"></div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-green"><i class="fas fa-star text-white"></i></div>
                    <div class="kpi-label">Avg Rating</div>
                    <div class="kpi-value"><?= $avgRating ? number_format($avgRating, 1) : '—' ?></div>
                    <?php if ($avgRating): ?>
                    <div class="star-row-kpi">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($avgRating) ? 'star-filled-kpi' : 'star-empty-kpi' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <?php else: ?>
                    <div class="kpi-sub">No ratings yet</div>
                    <?php endif; ?>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= $avgRating ? ($avgRating/5)*100 : 0 ?>%"></div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-purple"><i class="fas fa-briefcase text-white"></i></div>
                    <div class="kpi-label">Experience</div>
                    <div class="kpi-value"><?= $trainer['years_of_experience'] ?? 0 ?></div>
                    <div class="kpi-sub">Years of experience</div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= min(100, (($trainer['years_of_experience'] ?? 0) / 30) * 100) ?>%"></div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-icon-violet"><i class="fas fa-calendar-alt text-white"></i></div>
                    <div class="kpi-label">Member Since</div>
                    <div class="kpi-value"><?= date('M Y', strtotime($trainer['user_created'])) ?></div>
                    <div class="kpi-sub">Joined <?= date('F j, Y', strtotime($trainer['user_created'])) ?></div>
                    <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:100%"></div></div>
                </div>
            </div>

            <!-- ── Two Column Layout ── -->
            <div class="two-col">
                <!-- Left Column -->
                <div>
                    <!-- About Card -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <div class="card-title-icon"><i class="fas fa-user"></i></div>
                                About
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($trainer['specialization']): ?>
                            <div class="profile-side-item">
                                <div class="profile-side-icon"><i class="fas fa-code"></i></div>
                                <div>
                                    <div class="profile-side-label">Specialization</div>
                                    <div class="profile-side-val"><?= htmlspecialchars($trainer['specialization']) ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="profile-side-item">
                                <div class="profile-side-icon"><i class="fas fa-briefcase"></i></div>
                                <div>
                                    <div class="profile-side-label">Experience</div>
                                    <div class="profile-side-val"><?= $trainer['years_of_experience'] ?? 0 ?> years</div>
                                </div>
                            </div>
                            <div class="profile-side-item">
                                <div class="profile-side-icon"><i class="fas fa-envelope"></i></div>
                                <div>
                                    <div class="profile-side-label">Email</div>
                                    <div class="profile-side-val"><?= htmlspecialchars($trainer['email']) ?></div>
                                </div>
                            </div>
                            <div class="profile-side-item">
                                <div class="profile-side-icon"><i class="fas fa-calendar"></i></div>
                                <div>
                                    <div class="profile-side-label">Joined</div>
                                    <div class="profile-side-val"><?= date('F j, Y', strtotime($trainer['user_created'])) ?></div>
                                </div>
                            </div>
                            <?php if ($trainer['bio']): ?>
                            <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border-light);">
                                <div class="profile-side-label" style="margin-bottom:6px;">Bio</div>
                                <div style="font-size:13.5px; color:var(--text-secondary); line-height:1.6;"><?= nl2br(htmlspecialchars($trainer['bio'])) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <div class="card-title-icon"><i class="fas fa-bolt"></i></div>
                                Quick Actions
                            </div>
                        </div>
                        <div class="card-body" style="padding:12px 22px;">
                            <a href="edit.php?id=<?= $trainerId ?>" class="btn btn-ghost" style="width:100%; justify-content:flex-start; margin-bottom:6px;"><i class="fas fa-pen" style="color:var(--navy-mid);"></i> Edit Profile</a>
                            <a href="performance.php?id=<?= $trainerId ?>" class="btn btn-ghost" style="width:100%; justify-content:flex-start; margin-bottom:6px;"><i class="fas fa-chart-line" style="color:#f59e0b;"></i> View Performance</a>
                            <a href="batches.php?id=<?= $trainerId ?>" class="btn btn-ghost" style="width:100%; justify-content:flex-start;"><i class="fas fa-layer-group" style="color:#10b981;"></i> All Batches</a>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <!-- Recent Batches -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <div class="card-title-icon"><i class="fas fa-users"></i></div>
                                Recent Batches
                            </div>
                            <a href="batches.php?id=<?= $trainerId ?>" class="card-link">View All <i class="fas fa-arrow-right"></i></a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($batches)): ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-layer-group"></i></div>
                                <div class="empty-title">No batches assigned</div>
                                <div class="empty-sub">This trainer has not been assigned to any batch yet.</div>
                            </div>
                            <?php else: ?>
                            <table class="batch-table">
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Start Date</th>
                                        <th>Status</th>
                                        <th style="text-align:right;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($batches as $batch): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($batch['batch_name']) ?></td>
                                        <td><?= date('M d, Y', strtotime($batch['start_date'])) ?></td>
                                        <td>
                                            <span class="status-pill status-<?= $batch['status'] ?>">
                                                <?= ucfirst($batch['status']) ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;">
                                            <a href="../batch/batch_view.php?batch_id=<?= urlencode($batch['batch_id']) ?>" class="btn btn-ghost" style="height:28px; padding:0 10px; font-size:12px;">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Feedback -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <div class="card-title-icon"><i class="fas fa-star"></i></div>
                                Recent Feedback
                            </div>
                            <a href="../feedback/feedback.php?trainer_id=<?= $trainerId ?>" class="card-link">View All <i class="fas fa-arrow-right"></i></a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($feedbacks)): ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-comment-slash"></i></div>
                                <div class="empty-title">No feedback yet</div>
                                <div class="empty-sub">Student feedback will appear here once submitted.</div>
                            </div>
                            <?php else: ?>
                            <?php foreach ($feedbacks as $fb): ?>
                            <div class="feedback-item">
                                <div class="feedback-top">
                                    <div>
                                        <div class="feedback-author"><?= htmlspecialchars($fb['first_name'] . ' ' . $fb['last_name']) ?></div>
                                        <div class="feedback-batch"><?= htmlspecialchars($fb['batch_name']) ?></div>
                                    </div>
                                    <div>
                                        <?php if ($fb['rating']): ?>
                                        <div class="feedback-rating">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star <?= $i <= $fb['rating'] ? 'star-filled' : 'star-empty' ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <?php endif; ?>
                                        <div class="feedback-date"><?= date('M d, Y', strtotime($fb['date'])) ?></div>
                                    </div>
                                </div>
                                <?php if ($fb['suggestions']): ?>
                                <div class="feedback-text"><?= htmlspecialchars($fb['suggestions']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Documents -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <div class="card-title-icon"><i class="fas fa-folder-open"></i></div>
                                Documents
                            </div>
                            <button class="btn btn-ghost btn-sm" onclick="openUploadModal()"><i class="fas fa-upload"></i> Upload</button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($documents)): ?>
                            <div class="upload-zone-trigger" onclick="openUploadModal()">
                                <div class="upload-zone-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                <div class="upload-zone-title">No documents uploaded</div>
                                <div class="upload-zone-sub">Click to upload certifications, resumes, or other credentials.</div>
                            </div>
                            <?php else: ?>
                            <div class="doc-grid">
                                <?php foreach ($documents as $doc):
                                    $ext = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                                    $icon = 'fa-file'; $iconColor = '#6B7280';
                                    if ($ext === 'pdf') { $icon = 'fa-file-pdf'; $iconColor = '#DC2626'; }
                                    elseif (in_array($ext, ['jpg','jpeg','png'])) { $icon = 'fa-file-image'; $iconColor = '#059669'; }
                                    elseif (in_array($ext, ['doc','docx'])) { $icon = 'fa-file-word'; $iconColor = '#4F46E5'; }
                                ?>
                                <div class="doc-card">
                                    <div class="doc-icon"><i class="fas <?= $icon ?>" style="color:<?= $iconColor ?>;"></i></div>
                                    <div class="doc-name"><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?></div>
                                    <div class="doc-date"><?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></div>
                                    <div class="doc-actions">
                                        <a href="<?= $doc['file_path'] ?>" target="_blank" class="doc-btn doc-btn-view"><i class="fas fa-eye"></i> View</a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this document?')">
                                            <input type="hidden" name="document_id" value="<?= $doc['document_id'] ?>">
                                            <button type="submit" name="delete_document" class="doc-btn doc-btn-del"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Document Modal -->
<div class="modal-overlay <?= isset($_SESSION['show_upload_modal']) ? 'active' : '' ?>" id="uploadModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-upload" style="color:var(--navy-mid); margin-right:8px;"></i> Upload Document</div>
            <button class="modal-close" onclick="closeUploadModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="modal-body">
                <div class="field">
                    <label class="field-label">Document Type</label>
                    <select class="field-select" name="document_type" required>
                        <option value="">Select a type…</option>
                        <option value="resume">Resume / CV</option>
                        <option value="certification">Certification</option>
                        <option value="degree">Degree</option>
                        <option value="id_proof">ID Proof</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label">File</label>
                    <input type="file" name="document_file" id="document_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                    <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">Accepted: PDF, JPG, PNG, DOC, DOCX — max 10 MB</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeUploadModal()">Cancel</button>
                <button type="submit" name="upload_document" class="btn btn-primary"><i class="fas fa-upload"></i> Upload</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUploadModal() { document.getElementById('uploadModal').classList.add('active'); }
function closeUploadModal() { document.getElementById('uploadModal').classList.remove('active'); }

<?php if (isset($_SESSION['show_upload_modal'])): unset($_SESSION['show_upload_modal']); ?>
document.addEventListener('DOMContentLoaded', () => openUploadModal());
<?php endif; ?>

document.getElementById('uploadModal').addEventListener('click', function(e) {
    if (e.target === this) closeUploadModal();
});
</script>
</body>
</html>
