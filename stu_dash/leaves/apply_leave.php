<?php
session_start();
require_once '../../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_user_id = $_SESSION['user_id'];

// Get student information
$student_query = $db->prepare("
    SELECT s.*, u.email 
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get student's active batches
$batches = [];
$batch_ids = [];

if (!empty($student['batch_name'])) {
    $batch_ids[] = $student['batch_name'];
}
if (!empty($student['batch_name_2'])) {
    $batch_ids[] = $student['batch_name_2'];
}
if (!empty($student['batch_name_3'])) {
    $batch_ids[] = $student['batch_name_3'];
}

if (!empty($batch_ids)) {
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $batches_query = $db->prepare("
        SELECT * FROM batches 
        WHERE batch_id IN ($placeholders)
        AND status IN ('upcoming', 'ongoing')
        ORDER BY batch_name
    ");
    $batches_query->execute($batch_ids);
    $active_batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);
} else {
    $active_batches = [];
}

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {
    
    // Validate required fields (removed counselling_request, acceptable_situation, support_needed)
    $required_fields = [
        'batch_id', 'start_date', 'end_date', 'reason_category', 
        'reason_detail', 'absence_type', 'informed_academy',
        'course_importance', 'content_value', 'topic_understanding',
        'practical_ability', 'unique_learning', 'loss_reflection',
        'future_commitment'
    ];
    
    $valid = true;
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field]) && $_POST[$field] !== '0') {
            $valid = false;
            $error_message = "Please fill in all required fields.";
            break;
        }
    }
    
    // Check responsibility acceptance
    if (!isset($_POST['responsibility_acceptance'])) {
        $valid = false;
        $error_message = "Please accept the responsibility statement.";
    }
    
    // Calculate total days
    $start_date = new DateTime($_POST['start_date']);
    $end_date = new DateTime($_POST['end_date']);
    $interval = $start_date->diff($end_date);
    $total_days = $interval->days + 1;
    
    // Handle file upload
    $prescription_path = null;
    if (isset($_FILES['medical_prescription']) && $_FILES['medical_prescription']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/leave_prescriptions/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $file_type = $_FILES['medical_prescription']['type'];
        
        if (in_array($file_type, $allowed_types)) {
            $file_extension = pathinfo($_FILES['medical_prescription']['name'], PATHINFO_EXTENSION);
            $file_name = 'prescription_' . $student['student_id'] . '_' . time() . '.' . $file_extension;
            $target_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['medical_prescription']['tmp_name'], $target_path)) {
                $prescription_path = 'uploads/leave_prescriptions/' . $file_name;
            }
        }
    }
    
    if ($valid) {
        // Generate application number
        $year = date('Y');
        $month = date('m');
        $app_query = $db->prepare("SELECT COUNT(*) as count FROM leave_applications WHERE application_no LIKE 'LEAVE-$year$month%'");
        $app_query->execute();
        $count = $app_query->fetch(PDO::FETCH_ASSOC)['count'] + 1;
        $application_no = 'LEAVE-' . $year . $month . str_pad($count, 4, '0', STR_PAD_LEFT);
        
        // Insert leave application
        $stmt = $db->prepare("
            INSERT INTO leave_applications (
                application_no, student_id, student_name, batch_id, email,
                start_date, end_date, total_days, reason_category, reason_detail,
                absence_type, informed_academy, medical_prescription,
                course_importance, content_value, topic_understanding,
                practical_ability, unique_learning, loss_reflection,
                future_commitment, responsibility_acceptance,
                counselling_request, acceptable_situation, support_needed,
                status
            ) VALUES (
                :application_no, :student_id, :student_name, :batch_id, :email,
                :start_date, :end_date, :total_days, :reason_category, :reason_detail,
                :absence_type, :informed_academy, :medical_prescription,
                :course_importance, :content_value, :topic_understanding,
                :practical_ability, :unique_learning, :loss_reflection,
                :future_commitment, :responsibility_acceptance,
                '', '', '',
                'pending'
            )
        ");
        
        $result = $stmt->execute([
            ':application_no' => $application_no,
            ':student_id' => $student['student_id'],
            ':student_name' => $student['first_name'] . ' ' . $student['last_name'],
            ':batch_id' => $_POST['batch_id'],
            ':email' => $student['email'],
            ':start_date' => $_POST['start_date'],
            ':end_date' => $_POST['end_date'],
            ':total_days' => $total_days,
            ':reason_category' => $_POST['reason_category'],
            ':reason_detail' => $_POST['reason_detail'],
            ':absence_type' => $_POST['absence_type'],
            ':informed_academy' => $_POST['informed_academy'],
            ':medical_prescription' => $prescription_path,
            ':course_importance' => $_POST['course_importance'],
            ':content_value' => $_POST['content_value'],
            ':topic_understanding' => $_POST['topic_understanding'],
            ':practical_ability' => $_POST['practical_ability'],
            ':unique_learning' => $_POST['unique_learning'],
            ':loss_reflection' => $_POST['loss_reflection'],
            ':future_commitment' => $_POST['future_commitment'],
            ':responsibility_acceptance' => isset($_POST['responsibility_acceptance']) ? 1 : 0
        ]);
        
        if ($result) {
            $application_id = $db->lastInsertId();
            
            // Add to history
            $history_stmt = $db->prepare("
                INSERT INTO leave_application_history (application_id, action, action_by)
                VALUES (:application_id, 'submitted', :action_by)
            ");
            $history_stmt->execute([
                ':application_id' => $application_id,
                ':action_by' => $_SESSION['user_id']
            ]);
            
            $success_message = "Leave application submitted successfully! Application Number: " . $application_no;
            
            // Redirect to success page
            header("Location: ../my_leaves.php?success=1&app_no=" . urlencode($application_no));
            exit();
        } else {
            $error_message = "Failed to submit leave application. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Leave - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ─── Colour Palette Variables ─── */
        :root {
            --primary-dark: #1B3C53;
            --primary: #234C6A;
            --primary-light: #456882;
            --neutral: #D2C1B6;
            --neutral-bg: #F5F0EB;
            --neutral-light: #E8E0D9;
            --white: #ffffff;
            --shadow: 0 8px 32px rgba(27, 60, 83, 0.10);
            --shadow-hover: 0 16px 48px rgba(27, 60, 83, 0.18);
            --radius: 1.25rem;
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(150deg, #f8f5f0 0%, #f0ebe6 45%, #e8e0d9 100%);
            min-height: 100vh;
        }

        /* ── Animations ─────────────────────────────────────────── */
        @keyframes fadeIn   { from { opacity:0; transform:translateY(20px) } to { opacity:1; transform:translateY(0) } }
        @keyframes slideInLeft  { from { opacity:0; transform:translateX(-30px) } to { opacity:1; transform:translateX(0) } }
        @keyframes slideInRight { from { opacity:0; transform:translateX(30px)  } to { opacity:1; transform:translateX(0) } }
        @keyframes pulse-ring   { 0%,100%{box-shadow:0 0 0 0 rgba(27,60,83,.4)} 50%{box-shadow:0 0 0 8px rgba(27,60,83,0)} }
        @keyframes shimmer { 0%{background-position:-200% 0} 100%{background-position:200% 0} }
        @keyframes float   { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }

        .animate-fade-in   { animation: fadeIn .5s ease-out forwards; }
        .animate-slide-left  { animation: slideInLeft  .5s ease-out forwards; }
        .animate-slide-right { animation: slideInRight .5s ease-out forwards; }

        /* ── Page header card ────────────────────────────────────── */
        .page-header-card {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 45%, #456882 100%);
            border-radius: 24px;
            padding: 28px 32px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 24px 64px -12px rgba(27,60,83,.45), 0 0 0 1px rgba(255,255,255,.1);
        }
        .page-header-card::before {
            content:'';
            position:absolute; inset:0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Ccircle cx='30' cy='30' r='4'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .page-header-card::after {
            content:'';
            position:absolute; right:-60px; top:-60px;
            width:240px; height:240px;
            border-radius:50%;
            background: rgba(255,255,255,.07);
        }
        .page-header-card .orb-inner {
            position:absolute; bottom:-40px; left:30%;
            width:180px; height:180px; border-radius:50%;
            background: rgba(255,255,255,.04);
        }

        /* ── Step progress ───────────────────────────────────────── */
        .stepper-wrap {
            background: linear-gradient(135deg, #ffffff, #F5F0EB);
            border-bottom: 2px solid #D2C1B6;
            padding: 22px 32px 18px;
        }
        .stepper {
            display: flex;
            align-items: center;
            max-width: 580px;
            margin: 0 auto;
        }
        .step-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        .step-circle {
            width: 48px; height: 48px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .9rem;
            transition: all .4s cubic-bezier(.34,1.56,.64,1);
            border: 3px solid transparent;
            background: #e5e7eb;
            color: #9ca3af;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .step-circle.active {
            background: linear-gradient(135deg, #1B3C53, #456882);
            color: white;
            border-color: rgba(27,60,83,.3);
            box-shadow: 0 0 0 6px rgba(27,60,83,.15), 0 6px 20px rgba(27,60,83,.35);
            transform: scale(1.14);
            animation: pulse-ring 2s infinite;
        }
        .step-circle.completed {
            background: linear-gradient(135deg, #234C6A, #456882);
            color: white;
            border-color: rgba(35,76,106,.25);
            box-shadow: 0 4px 14px rgba(35,76,106,.35);
        }
        .step-label {
            margin-top: 7px;
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #9ca3af;
            white-space: nowrap;
        }
        .step-label.active   { color: #1B3C53; }
        .step-label.completed{ color: #234C6A; }

        .step-connector {
            flex: 1;
            height: 5px;
            border-radius: 3px;
            background: #e5e7eb;
            margin: 0 6px;
            margin-bottom: 22px;
            overflow: hidden;
            position: relative;
        }
        .step-connector-fill {
            height: 100%;
            width: 0%;
            border-radius: 3px;
            background: linear-gradient(90deg, #234C6A, #456882);
            transition: width .5s ease;
            box-shadow: 0 0 8px rgba(27,60,83,.4);
        }

        /* ── Section header strips ───────────────────────────────── */
        .section-header {
            border-radius: 18px;
            padding: 18px 22px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }
        .section-header::after {
            content:'';
            position:absolute; right:-20px; top:-20px;
            width:100px; height:100px; border-radius:50%;
            background: rgba(255,255,255,.25);
            pointer-events:none;
        }
        .section-header-icon {
            width: 50px; height: 50px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(0,0,0,.12);
        }
        .section-header h3 { font-size: 1.1rem; font-weight: 800; letter-spacing:-.01em; }
        .section-header p  { font-size: .82rem; margin-top: 3px; opacity: .75; font-weight:500; }

        /* Section 1 – Neutral beige */
        .sh-blue {
            background: linear-gradient(135deg, #F5F0EB, #E8E0D9);
            border: 1.5px solid #D2C1B6;
            box-shadow: 0 4px 20px rgba(27,60,83,.12);
        }
        .sh-blue .section-header-icon { background: linear-gradient(135deg, #1B3C53, #456882); color:white; }
        .sh-blue h3 { color: #1B3C53; }
        .sh-blue p  { color: #456882; }

        /* Section 2 – Amber (keep for warnings) */
        .sh-amber {
            background: linear-gradient(135deg, #fef9c3, #fef3c7);
            border: 1.5px solid #fde68a;
            box-shadow: 0 4px 20px rgba(245,158,11,.1);
        }
        .sh-amber .section-header-icon { background: linear-gradient(135deg,#f59e0b,#d97706); color:white; }
        .sh-amber h3 { color: #78350f; }
        .sh-amber p  { color: #d97706; }

        /* Section 3 – Purple (keep but tone down) */
        .sh-purple {
            background: linear-gradient(135deg, #ede9fe, #f3e8ff);
            border: 1.5px solid #ddd6fe;
            box-shadow: 0 4px 20px rgba(139,92,246,.1);
        }
        .sh-purple .section-header-icon { background: linear-gradient(135deg, #1B3C53, #456882); color:white; }
        .sh-purple h3 { color: #1B3C53; }
        .sh-purple p  { color: #456882; }

        /* Section 4 – Green (keep for success) */
        .sh-green {
            background: linear-gradient(135deg, #d1fae5, #dcfce7);
            border: 1.5px solid #a7f3d0;
            box-shadow: 0 4px 20px rgba(16,185,129,.1);
        }
        .sh-green .section-header-icon { background: linear-gradient(135deg, #234C6A, #456882); color:white; }
        .sh-green h3 { color: #064e3b; }
        .sh-green p  { color: #059669; }

        /* ── Input fields ────────────────────────────────────────── */
        .field-wrap { position: relative; }
        .fancy-input, .fancy-select, .fancy-textarea {
            width: 100%;
            padding: 13px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 14px;
            font-size: .9rem;
            transition: border-color .2s, box-shadow .2s, background .2s, transform .15s;
            background: #fafbff;
            color: #111827;
            outline: none;
        }
        .fancy-input:focus, .fancy-select:focus, .fancy-textarea:focus {
            border-color: #1B3C53;
            background: white;
            box-shadow: 0 0 0 4px rgba(27,60,83,.12), 0 4px 16px rgba(27,60,83,.08);
            transform: translateY(-1px);
        }
        .fancy-input.readonly-field, .fancy-select.readonly-field {
            background: linear-gradient(135deg,#f8faff,#f1f5f9);
            color: #6b7280;
            border-color: #e2e8f0;
            cursor: default;
        }
        .fancy-input.input-error, .fancy-textarea.input-error {
            border-color: #ef4444 !important;
            background: #fef2f2 !important;
            box-shadow: 0 0 0 4px rgba(239,68,68,.1) !important;
        }
        .field-label {
            display: block;
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: 8px;
            letter-spacing: .01em;
        }
        .field-label .req { color: #ef4444; margin-left: 2px; }

        /* ── Radio cards ─────────────────────────────────────────── */
        .radio-card {
            position: relative;
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            padding: 18px 12px;
            cursor: pointer;
            transition: all .25s cubic-bezier(.34,1.56,.64,1);
            background: white;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }
        .radio-card:hover {
            transform: translateY(-4px);
            border-color: #D2C1B6;
            background: #F5F0EB;
            box-shadow: 0 10px 28px -4px rgba(27,60,83,.2);
        }
        .radio-card input[type="radio"] {
            position: absolute; opacity: 0; pointer-events: none;
        }
        .radio-card .rc-icon {
            font-size: 1.8rem;
            margin-bottom: 10px;
            color: #cbd5e1;
            transition: color .2s, transform .25s cubic-bezier(.34,1.56,.64,1);
            display: block;
        }
        .radio-card .rc-label {
            font-size: .78rem;
            font-weight: 700;
            color: #94a3b8;
            transition: color .2s;
            line-height: 1.3;
        }

        /* Selected states with new palette */
        .radio-card.selected-blue   { border-color:#1B3C53; background:linear-gradient(135deg,#F5F0EB,#E8E0D9); box-shadow:0 10px 28px -4px rgba(27,60,83,.25); }
        .radio-card.selected-blue   .rc-icon  { color:#1B3C53; transform:scale(1.2) rotate(-5deg); }
        .radio-card.selected-blue   .rc-label { color:#1B3C53; }

        .radio-card.selected-amber  { border-color:#f59e0b; background:linear-gradient(135deg,#fffbeb,#fef3c7); box-shadow:0 10px 28px -4px rgba(245,158,11,.22); }
        .radio-card.selected-amber  .rc-icon  { color:#d97706; transform:scale(1.2) rotate(-5deg); }
        .radio-card.selected-amber  .rc-label { color:#92400e; }

        .radio-card.selected-purple { border-color:#456882; background:linear-gradient(135deg,#F5F0EB,#E8E0D9); box-shadow:0 10px 28px -4px rgba(27,60,83,.22); }
        .radio-card.selected-purple .rc-icon  { color:#234C6A; transform:scale(1.2) rotate(-5deg); }
        .radio-card.selected-purple .rc-label { color:#234C6A; }

        .radio-card.selected-green  { border-color:#234C6A; background:linear-gradient(135deg,#F5F0EB,#E8E0D9); box-shadow:0 10px 28px -4px rgba(27,60,83,.22); }
        .radio-card.selected-green  .rc-icon  { color:#1B3C53; transform:scale(1.2) rotate(-5deg); }
        .radio-card.selected-green  .rc-label { color:#1B3C53; }

        /* ── Yes / No toggle radio ───────────────────────────────── */
        .toggle-radio-group { display: flex; gap: 12px; }
        .toggle-radio {
            flex: 1;
            border: 2px solid #e5e7eb;
            border-radius: 14px;
            padding: 13px 16px;
            cursor: pointer;
            display: flex; align-items: center; gap: 10px;
            transition: all .25s cubic-bezier(.34,1.56,.64,1);
            background: #fafbff;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }
        .toggle-radio input { position:absolute; opacity:0; pointer-events:none; }
        .toggle-radio span { font-size:.875rem; font-weight:700; color:#6b7280; }
        .toggle-radio .t-dot {
            width:20px; height:20px; border-radius:50%;
            border: 2px solid #d1d5db;
            display:flex; align-items:center; justify-content:center;
            transition: all .2s;
            flex-shrink:0;
        }
        .toggle-radio:hover { border-color:#D2C1B6; background:white; transform:translateY(-2px); box-shadow:0 6px 16px rgba(27,60,83,.15); }
        .toggle-radio.checked-yes { border-color:#1B3C53; background:linear-gradient(135deg,#F5F0EB,#E8E0D9); box-shadow:0 6px 16px rgba(27,60,83,.2); }
        .toggle-radio.checked-yes span { color:#1B3C53; }
        .toggle-radio.checked-yes .t-dot { border-color:#1B3C53; background:#1B3C53; box-shadow:0 2px 8px rgba(27,60,83,.35); }
        .toggle-radio.checked-no  { border-color:#ef4444; background:linear-gradient(135deg,#fef2f2,#fee2e2); box-shadow:0 6px 16px rgba(239,68,68,.15); }
        .toggle-radio.checked-no span { color:#991b1b; }
        .toggle-radio.checked-no .t-dot { border-color:#ef4444; background:#ef4444; box-shadow:0 2px 8px rgba(239,68,68,.35); }

        /* planned/sudden special */
        .toggle-radio.checked-planned { border-color:#456882; background:linear-gradient(135deg,#F5F0EB,#E8E0D9); box-shadow:0 6px 16px rgba(27,60,83,.18); }
        .toggle-radio.checked-planned span { color:#234C6A; }
        .toggle-radio.checked-planned .t-dot { border-color:#456882; background:#456882; box-shadow:0 2px 8px rgba(27,60,83,.35); }
        .toggle-radio.checked-sudden  { border-color:#f59e0b; background:linear-gradient(135deg,#fffbeb,#fef3c7); box-shadow:0 6px 16px rgba(245,158,11,.18); }
        .toggle-radio.checked-sudden  span { color:#92400e; }
        .toggle-radio.checked-sudden  .t-dot { border-color:#f59e0b; background:#f59e0b; box-shadow:0 2px 8px rgba(245,158,11,.35); }

        /* ── File upload ─────────────────────────────────────────── */
        .upload-zone {
            position: relative;
            border: 2.5px dashed #D2C1B6;
            border-radius: 18px;
            padding: 36px 24px;
            text-align: center;
            background: linear-gradient(135deg, #F5F0EB, #E8E0D9);
            transition: all .25s;
            cursor: pointer;
            overflow: hidden;
        }
        .upload-zone:hover, .upload-zone.drag-over {
            border-color: #456882;
            background: linear-gradient(135deg, #E8E0D9, #F5F0EB);
            box-shadow: 0 0 0 5px rgba(27,60,83,.1);
            transform: translateY(-2px);
        }
        .upload-zone input {
            position: absolute; inset: 0;
            width: 100%; height: 100%;
            opacity: 0; cursor: pointer;
        }
        .upload-icon-wrap {
            width: 60px; height: 60px;
            border-radius: 18px;
            background: linear-gradient(135deg, #1B3C53, #456882);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
            transition: transform .25s cubic-bezier(.34,1.56,.64,1);
            box-shadow: 0 8px 24px rgba(27,60,83,.3);
        }
        .upload-zone:hover .upload-icon-wrap { transform: scale(1.1) rotate(-6deg); }

        /* ── Navigation buttons ──────────────────────────────────── */
        .btn-prev {
            display: flex; align-items: center; gap: 8px;
            padding: 13px 24px;
            border: 2px solid #e5e7eb;
            border-radius: 14px;
            font-weight: 700; font-size: .9rem;
            color: #374151;
            background: white;
            cursor: pointer;
            transition: all .25s;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }
        .btn-prev:hover {
            border-color: #1B3C53; color: #1B3C53;
            background: linear-gradient(135deg,#F5F0EB,#E8E0D9);
            box-shadow: 0 6px 16px rgba(27,60,83,.18);
            transform: translateY(-1px);
        }
        .btn-next {
            display: flex; align-items: center; gap: 8px;
            padding: 13px 30px;
            border: none;
            border-radius: 14px;
            font-weight: 700; font-size: .9rem;
            color: white;
            background: linear-gradient(135deg, #1B3C53, #456882);
            background-size: 200% auto;
            cursor: pointer;
            transition: all .3s;
            box-shadow: 0 8px 24px rgba(27,60,83,.4);
        }
        .btn-next:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 32px rgba(27,60,83,.5);
            background-position: right center;
        }
        .btn-next:active { transform: translateY(0); }
        .btn-submit {
            display: flex; align-items: center; gap: 8px;
            padding: 13px 34px;
            border: none;
            border-radius: 14px;
            font-weight: 700; font-size: .9rem;
            color: white;
            background: linear-gradient(135deg, #10b981, #059669, #0d9488);
            background-size: 200% auto;
            cursor: pointer;
            transition: all .3s;
            box-shadow: 0 8px 24px rgba(16,185,129,.4);
        }
        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 32px rgba(16,185,129,.5);
            background-position: right center;
        }
        .btn-submit:active { transform: translateY(0); }
        button:disabled { opacity: .5; cursor: not-allowed; transform: none !important; }

        /* ── Responsibility box ──────────────────────────────────── */
        .resp-box {
            background: linear-gradient(135deg, #F5F0EB, #E8E0D9);
            border: 2px solid #D2C1B6;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 4px 16px rgba(27,60,83,.12);
        }
        .resp-box label { display:flex; align-items:flex-start; gap:14px; cursor:pointer; }
        .resp-checkbox {
            width:24px; height:24px;
            border-radius:7px;
            border:2px solid #1B3C53;
            appearance:none; -webkit-appearance:none;
            background:white; cursor:pointer;
            transition:all .2s; flex-shrink:0; margin-top:2px;
            position:relative;
            box-shadow: 0 2px 6px rgba(27,60,83,.2);
        }
        .resp-checkbox:checked {
            background: linear-gradient(135deg, #1B3C53, #456882);
            border-color: #1B3C53;
            box-shadow: 0 4px 12px rgba(27,60,83,.35);
        }
        .resp-checkbox:checked::after {
            content:'✓'; position:absolute; inset:0;
            display:flex; align-items:center; justify-content:center;
            color:white; font-size:.9rem; font-weight:800;
        }

        /* ── Error/info banners ──────────────────────────────────── */
        .alert-error {
            background: linear-gradient(135deg,#fef2f2,#fee2e2);
            border: 1.5px solid #fca5a5;
            border-left: 5px solid #ef4444;
            border-radius: 14px;
            padding: 16px 20px;
            color: #991b1b;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 4px 16px rgba(239,68,68,.1);
        }
        .alert-warning {
            background: linear-gradient(135deg,#fffbeb,#fef3c7);
            border: 1.5px solid #fde68a;
            border-radius: 20px;
            padding: 40px 32px;
            text-align: center;
        }

        /* ── Card container ──────────────────────────────────────── */
        .form-card {
            background: white;
            border-radius: 28px;
            box-shadow: 0 32px 72px -16px rgba(27,60,83,.15), 0 0 0 1px rgba(27,60,83,.06);
            overflow: hidden;
            position: relative; z-index: 1;
        }

        /* ── form-section hidden ─────────────────────────────────── */
        .form-section.hidden { display:none !important; }

        /* ── Days pill ───────────────────────────────────────────── */
        .days-pill {
            background: linear-gradient(135deg, #F5F0EB, #E8E0D9);
            border: 2px solid #D2C1B6;
            border-radius: 14px;
            padding: 14px 18px;
            font-weight: 800;
            color: #1B3C53;
            font-size: .95rem;
            box-shadow: 0 4px 14px rgba(27,60,83,.12);
        }

        /* ── Animations ─────────────────────────────────────────── */
        @keyframes fadeIn   { from { opacity:0; transform:translateY(20px) } to { opacity:1; transform:translateY(0) } }
        @keyframes slideInLeft  { from { opacity:0; transform:translateX(-30px) } to { opacity:1; transform:translateX(0) } }
        @keyframes slideInRight { from { opacity:0; transform:translateX(30px)  } to { opacity:1; transform:translateX(0) } }
        @keyframes pulse-ring   { 0%,100%{box-shadow:0 0 0 6px rgba(27,60,83,.15),0 6px 20px rgba(27,60,83,.35)} 50%{box-shadow:0 0 0 10px rgba(27,60,83,.05),0 6px 20px rgba(27,60,83,.35)} }
        @keyframes float   { 0%,100%{transform:translateY(0) rotate(0deg)} 50%{transform:translateY(-8px) rotate(3deg)} }
        @keyframes shimmer { 0%{background-position:-200% 0} 100%{background-position:200% 0} }

        .animate-fade-in   { animation: fadeIn .6s ease-out forwards; }
        .animate-slide-left  { animation: slideInLeft  .5s ease-out forwards; }
        .animate-slide-right { animation: slideInRight .5s ease-out forwards; }

        /* ── Toast ───────────────────────────────────────────────── */
        .custom-toast {
            position:fixed; top:24px; right:24px;
            min-width:320px; padding:16px 22px;
            border-radius:16px;
            box-shadow: 0 24px 48px -8px rgba(0,0,0,.25);
            color:white;
            z-index:9999;
            display:flex; align-items:center; gap:12px;
            font-weight:700; font-size:.875rem;
            transform:translateX(calc(100% + 28px));
            transition:transform .4s cubic-bezier(.34,1.56,.64,1), opacity .3s ease;
            opacity:0;
        }
        .custom-toast.show { transform:translateX(0); opacity:1; }
        .custom-toast.toast-error   { background:linear-gradient(135deg,#ef4444,#dc2626); box-shadow:0 16px 40px rgba(239,68,68,.35); }
        .custom-toast.toast-success { background:linear-gradient(135deg,#10b981,#059669); box-shadow:0 16px 40px rgba(16,185,129,.35); }
        .custom-toast.toast-info    { background:linear-gradient(135deg,#1B3C53,#456882); box-shadow:0 16px 40px rgba(27,60,83,.35); }

        @media (max-width:768px) {
            .page-header-card { padding:20px; border-radius:18px; }
            .stepper-wrap { padding:16px 20px 12px; }
            .step-label { display:none; }
        }
    </style>
</head>
<body>
    <?php include '../../header.php'; ?>
    <?php include '../side.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <div class="max-w-4xl mx-auto px-4 py-6 md:py-8">

            <!-- ── Page Header ─────────────────────────────────── -->
            <div class="page-header-card mb-8 animate-fade-in">
                <div class="orb-inner"></div>
                <div class="relative z-10 flex items-center justify-between">
                    <div class="flex items-center gap-5">
                        <div style="background:rgba(255,255,255,.2);border-radius:18px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,.15);backdrop-filter:blur(4px);">
                            <i class="fas fa-calendar-alt text-white text-2xl" style="animation:float 3s ease-in-out infinite;display:block;"></i>
                        </div>
                        <div>
                            <h1 class="text-white font-bold text-2xl md:text-3xl leading-tight" style="text-shadow:0 2px 12px rgba(0,0,0,.2);">Apply for Leave</h1>
                            <p style="color:rgba(255,255,255,.8);font-size:.85rem;margin-top:5px;font-weight:500;">
                                Fill out all sections carefully &mdash; fields marked <span style="color:#fbbf24;font-weight:800;">★</span> are required
                            </p>
                        </div>
                    </div>
                    <a href="my_leaves.php" style="background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.3);color:white;border-radius:12px;padding:10px 18px;font-size:.82rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:7px;transition:all .25s;backdrop-filter:blur(4px);" onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.18)'">
                        <i class="fas fa-arrow-left"></i> My Leaves
                    </a>
                </div>
            </div>

            <!-- ── Error Banner ────────────────────────────────── -->
            <?php if ($error_message): ?>
            <div class="alert-error mb-6 animate-fade-in">
                <i class="fas fa-exclamation-circle text-red-500 text-lg flex-shrink-0"></i>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
            <?php endif; ?>

            <!-- ── No Batches ──────────────────────────────────── -->
            <?php if (empty($active_batches)): ?>
            <div class="form-card animate-fade-in">
                <div class="alert-warning">
                    <div style="width:72px;height:72px;background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                        <i class="fas fa-exclamation-triangle text-amber-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">No Active Batches</h3>
                    <p class="text-gray-600 mb-5">You don't have any active batches to apply for leave.</p>
                    <a href="my_leaves.php" class="btn-next inline-flex">
                        <i class="fas fa-arrow-left"></i> Back to My Leaves
                    </a>
                </div>
            </div>

            <?php else: ?>

            <!-- ── Main Form Card ──────────────────────────────── -->
            <form method="POST" enctype="multipart/form-data" id="leaveForm" class="form-card animate-fade-in">

                <!-- ── Progress Stepper ─────────────────────────── -->
                <div class="stepper-wrap">
                    <div class="stepper">
                        <div class="step-node">
                            <div class="step-circle active" id="step1"><i class="fas fa-info-circle"></i></div>
                            <span class="step-label active" id="label1">Basic Info</span>
                        </div>
                        <div class="step-connector">
                            <div class="step-connector-fill" id="progress1"></div>
                        </div>
                        <div class="step-node">
                            <div class="step-circle" id="step2"><i class="fas fa-question-circle"></i></div>
                            <span class="step-label" id="label2">Reason</span>
                        </div>
                        <div class="step-connector">
                            <div class="step-connector-fill" id="progress2"></div>
                        </div>
                        <div class="step-node">
                            <div class="step-circle" id="step3"><i class="fas fa-graduation-cap"></i></div>
                            <span class="step-label" id="label3">Feedback</span>
                        </div>
                        <div class="step-connector">
                            <div class="step-connector-fill" id="progress3"></div>
                        </div>
                        <div class="step-node">
                            <div class="step-circle" id="step4"><i class="fas fa-brain"></i></div>
                            <span class="step-label" id="label4">Commitment</span>
                        </div>
                    </div>
                </div>

                <!-- ── Form Body ──────────────────────────────────── -->
                <div class="p-6 md:p-8">

                    <!-- ═══════════ SECTION 1: Basic Info ═══════════ -->
                    <div id="section1" class="form-section">
                        <div class="section-header sh-blue">
                            <div class="section-header-icon"><i class="fas fa-user-circle"></i></div>
                            <div>
                                <h3>Basic Information</h3>
                                <p>Your personal details and leave duration</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="field-label">Select Batch <span class="req">*</span></label>
                                <select name="batch_id" id="batch_id" required class="fancy-select">
                                    <option value="">-- Select Batch --</option>
                                    <?php foreach ($active_batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>">
                                        <?= htmlspecialchars($batch['batch_name']) ?> (<?= htmlspecialchars($batch['batch_id']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="field-label">Student Name</label>
                                <input type="text"
                                       value="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>"
                                       class="fancy-input readonly-field" readonly>
                            </div>

                            <div>
                                <label class="field-label">Student ID</label>
                                <input type="text" value="<?= htmlspecialchars($student['student_id']) ?>"
                                       class="fancy-input readonly-field" readonly>
                            </div>

                            <div>
                                <label class="field-label">Email</label>
                                <input type="email" value="<?= htmlspecialchars($student['email']) ?>"
                                       class="fancy-input readonly-field" readonly>
                            </div>

                            <div>
                                <label class="field-label">Leave Start Date <span class="req">*</span></label>
                                <input type="date" name="start_date" id="start_date" required
                                       min="<?= date('Y-m-d') ?>" class="fancy-input">
                            </div>

                            <div>
                                <label class="field-label">Leave End Date <span class="req">*</span></label>
                                <input type="date" name="end_date" id="end_date" required
                                       min="<?= date('Y-m-d') ?>" class="fancy-input">
                            </div>

                            <div class="md:col-span-2">
                                <label class="field-label">Total Days</label>
                                <div id="total_days_display" class="days-pill">— select dates above —</div>
                                <!-- Hidden mirror for JS compatibility -->
                                <input type="hidden" id="total_days_hidden">
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════ SECTION 2: Reason ════════════ -->
                    <div id="section2" class="form-section hidden">
                        <div class="section-header sh-amber">
                            <div class="section-header-icon"><i class="fas fa-search"></i></div>
                            <div>
                                <h3>Reason for Absence</h3>
                                <p>Tell us why you need this time away</p>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <!-- Reason category -->
                            <div>
                                <label class="field-label">Main Reason for Missing Class <span class="req">*</span></label>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mt-1">
                                    <?php
                                    $reasons = ['Health Issue','Family Emergency','Personal Work','College Work & Exam','Other'];
                                    $icons   = ['fa-heartbeat','fa-users','fa-user-cog','fa-graduation-cap','fa-ellipsis-h'];
                                    foreach ($reasons as $i => $reason):
                                    ?>
                                    <label class="radio-card" data-group="reason_category" data-color="amber">
                                        <input type="radio" name="reason_category" value="<?= $reason ?>" required>
                                        <i class="fas <?= $icons[$i] ?> rc-icon"></i>
                                        <span class="rc-label"><?= $reason ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Detailed reason -->
                            <div>
                                <label class="field-label">Detailed Reason <span class="req">*</span></label>
                                <textarea name="reason_detail" id="reason_detail" required rows="4"
                                          class="fancy-textarea"
                                          placeholder="Please provide a detailed reason for your absence…"></textarea>
                            </div>

                            <!-- File upload -->
                            <div>
                                <label class="field-label">Medical Prescription <span style="font-weight:400;color:#9ca3af">(if applicable)</span></label>
                                <div class="upload-zone" id="uploadZone">
                                    <input type="file" name="medical_prescription" id="medical_prescription" accept="image/*,.pdf">
                                    <div class="upload-icon-wrap">
                                        <i class="fas fa-cloud-upload-alt" style="color:white;font-size:1.4rem;"></i>
                                    </div>
                                    <p class="font-semibold text-gray-700 mb-1">Click or drag to upload</p>
                                    <p class="text-xs text-gray-400">PDF, JPG, PNG — max 5 MB</p>
                                    <div id="file_name_display" class="hidden mt-3 text-sm font-semibold" style="color:#059669;"></div>
                                </div>
                            </div>

                            <!-- Absence type + Informed -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="field-label">Was your absence planned? <span class="req">*</span></label>
                                    <div class="toggle-radio-group mt-1">
                                        <label class="toggle-radio" data-val="Planned" data-group="absence_type">
                                            <input type="radio" name="absence_type" value="Planned" required>
                                            <div class="t-dot"></div>
                                            <span>Planned</span>
                                        </label>
                                        <label class="toggle-radio" data-val="Sudden" data-group="absence_type">
                                            <input type="radio" name="absence_type" value="Sudden">
                                            <div class="t-dot"></div>
                                            <span>Sudden</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="field-label">Did you inform the academy before? <span class="req">*</span></label>
                                    <div class="toggle-radio-group mt-1">
                                        <label class="toggle-radio" data-val="Yes" data-group="informed_academy">
                                            <input type="radio" name="informed_academy" value="Yes" required>
                                            <div class="t-dot"></div>
                                            <span>Yes</span>
                                        </label>
                                        <label class="toggle-radio" data-val="No" data-group="informed_academy">
                                            <input type="radio" name="informed_academy" value="No">
                                            <div class="t-dot"></div>
                                            <span>No</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════ SECTION 3: Feedback ══════════ -->
                    <div id="section3" class="form-section hidden">
                        <div class="section-header sh-purple">
                            <div class="section-header-icon"><i class="fas fa-graduation-cap"></i></div>
                            <div>
                                <h3>Course Value & Learning Feedback</h3>
                                <p>Help us understand your learning experience</p>
                            </div>
                        </div>

                        <div class="space-y-7">
                            <!-- Course importance -->
                            <div>
                                <label class="field-label">Do you understand how important this course is for your career? <span class="req">*</span></label>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-1">
                                    <?php
                                    $opts = ['Yes, very important','Somewhat important','Not sure'];
                                    $icns = ['fa-check-circle','fa-question-circle','fa-times-circle'];
                                    foreach ($opts as $k => $opt):
                                    ?>
                                    <label class="radio-card" data-group="course_importance" data-color="purple">
                                        <input type="radio" name="course_importance" value="<?= $opt ?>" required>
                                        <i class="fas <?= $icns[$k] ?> rc-icon"></i>
                                        <span class="rc-label"><?= $opt ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Content value -->
                            <div>
                                <label class="field-label">Do you feel the content taught in class is valuable? <span class="req">*</span></label>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-1">
                                    <?php
                                    $opts = ['Very valuable','Good','Average','Not useful'];
                                    $icns = ['fa-star','fa-thumbs-up','fa-meh','fa-thumbs-down'];
                                    foreach ($opts as $k => $opt):
                                    ?>
                                    <label class="radio-card" data-group="content_value" data-color="purple">
                                        <input type="radio" name="content_value" value="<?= $opt ?>" required>
                                        <i class="fas <?= $icns[$k] ?> rc-icon"></i>
                                        <span class="rc-label"><?= $opt ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Topic understanding -->
                            <div>
                                <label class="field-label">Are you able to understand the topics being taught? <span class="req">*</span></label>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-1">
                                    <?php
                                    $opts = ['Yes, clearly','Sometimes','No, I struggle'];
                                    $icns = ['fa-smile','fa-meh','fa-frown'];
                                    foreach ($opts as $k => $opt):
                                    ?>
                                    <label class="radio-card" data-group="topic_understanding" data-color="purple">
                                        <input type="radio" name="topic_understanding" value="<?= $opt ?>" required>
                                        <i class="fas <?= $icns[$k] ?> rc-icon"></i>
                                        <span class="rc-label"><?= $opt ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Practical ability -->
                            <div>
                                <label class="field-label">Are you able to perform practical tasks properly? <span class="req">*</span></label>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-1">
                                    <?php
                                    $opts = ['Yes','With some difficulty','No'];
                                    $icns = ['fa-check-circle','fa-exclamation-circle','fa-times-circle'];
                                    foreach ($opts as $k => $opt):
                                    ?>
                                    <label class="radio-card" data-group="practical_ability" data-color="purple">
                                        <input type="radio" name="practical_ability" value="<?= $opt ?>" required>
                                        <i class="fas <?= $icns[$k] ?> rc-icon"></i>
                                        <span class="rc-label"><?= $opt ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Unique learning -->
                            <div>
                                <label class="field-label">Do you think this type of practical learning is difficult to find elsewhere? <span class="req">*</span></label>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-1">
                                    <?php
                                    $opts = ['Yes','Maybe','No'];
                                    $icns = ['fa-check-circle','fa-question-circle','fa-times-circle'];
                                    foreach ($opts as $k => $opt):
                                    ?>
                                    <label class="radio-card" data-group="unique_learning" data-color="purple">
                                        <input type="radio" name="unique_learning" value="<?= $opt ?>" required>
                                        <i class="fas <?= $icns[$k] ?> rc-icon"></i>
                                        <span class="rc-label"><?= $opt ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════ SECTION 4: Commitment ════════ -->
                    <div id="section4" class="form-section hidden">
                        <div class="section-header sh-green">
                            <div class="section-header-icon"><i class="fas fa-brain"></i></div>
                            <div>
                                <h3>Self-Reflection &amp; Commitment</h3>
                                <p>Take a moment to reflect and commit</p>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <!-- Loss reflection -->
                            <div>
                                <label class="field-label">If you continue missing classes, what do you think you will lose? <span class="req">*</span></label>
                                <textarea name="loss_reflection" id="loss_reflection" required rows="3"
                                          class="fancy-textarea"
                                          placeholder="Share your honest thoughts…"></textarea>
                            </div>

                            <!-- Future commitment -->
                            <div>
                                <label class="field-label">Will you ensure regular attendance from now? <span class="req">*</span></label>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-1">
                                    <?php
                                    $opts = ['Yes','I will try','Not sure'];
                                    $icns = ['fa-check-circle','fa-hand-peace','fa-question-circle'];
                                    foreach ($opts as $k => $opt):
                                    ?>
                                    <label class="radio-card" data-group="future_commitment" data-color="green">
                                        <input type="radio" name="future_commitment" value="<?= $opt ?>" required>
                                        <i class="fas <?= $icns[$k] ?> rc-icon"></i>
                                        <span class="rc-label"><?= $opt ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Responsibility -->
                            <div class="resp-box">
                                <label>
                                    <input type="checkbox" name="responsibility_acceptance" id="responsibility_acceptance"
                                           value="1" class="resp-checkbox">
                                    <span class="text-sm text-gray-700" style="padding-top:1px;">
                                        <strong style="color:#1B3C53;">Yes, I accept full responsibility</strong>
                                        for any negative impact (exam performance, internship delay, skill gap, or placement delay)
                                        caused due to missing classes. <span class="req">*</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- ── Navigation ─────────────────────────────── -->
                    <div class="flex justify-between items-center pt-6 mt-6" style="border-top:2px solid #D2C1B6;">
                        <button type="button" id="prevBtn" class="btn-prev hidden">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <div id="placeholderDiv"></div>
                        <button type="button" id="nextBtn" class="btn-next">
                            Next <i class="fas fa-arrow-right"></i>
                        </button>
                        <button type="submit" name="submit_leave" id="submitBtn" class="btn-submit hidden">
                            <i class="fas fa-paper-plane"></i> Submit Application
                        </button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Global variables
        let currentSection = 1;
        const totalSections = 4;

        // DOM Elements
        const section1 = document.getElementById('section1');
        const section2 = document.getElementById('section2');
        const section3 = document.getElementById('section3');
        const section4 = document.getElementById('section4');
        const prevBtn  = document.getElementById('prevBtn');
        const nextBtn  = document.getElementById('nextBtn');
        const submitBtn= document.getElementById('submitBtn');
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const step3 = document.getElementById('step3');
        const step4 = document.getElementById('step4');
        const progress1 = document.getElementById('progress1');
        const progress2 = document.getElementById('progress2');
        const progress3 = document.getElementById('progress3');

        // ── Calculate total days ────────────────────────────────
        function calculateTotalDays() {
            const startDate = document.getElementById('start_date').value;
            const endDate   = document.getElementById('end_date').value;
            const display   = document.getElementById('total_days_display');

            if (startDate && endDate) {
                const start = new Date(startDate);
                const end   = new Date(endDate);
                if (end >= start) {
                    const diffDays = Math.ceil(Math.abs(end - start) / (1000*60*60*24)) + 1;
                    display.textContent = diffDays + (diffDays === 1 ? ' day' : ' days');
                    display.style.color = '#1B3C53';
                    return true;
                } else {
                    display.textContent = '⚠ End date must be after start date';
                    display.style.color = '#dc2626';
                    return false;
                }
            } else {
                display.textContent = '— select dates above —';
                display.style.color = '';
            }
            return false;
        }

        const startDateInput = document.getElementById('start_date');
        const endDateInput   = document.getElementById('end_date');
        if (startDateInput && endDateInput) {
            startDateInput.addEventListener('change', calculateTotalDays);
            endDateInput.addEventListener('change', calculateTotalDays);
        }

        // ── File upload display ─────────────────────────────────
        const fileInput = document.getElementById('medical_prescription');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                const fd = document.getElementById('file_name_display');
                if (this.files && this.files[0]) {
                    fd.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Selected: ' + this.files[0].name;
                    fd.classList.remove('hidden');
                } else {
                    fd.classList.add('hidden');
                }
            });
        }

        // ── Radio card selection ────────────────────────────────
        function setupRadioCards() {
            document.querySelectorAll('.radio-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const radio = this.querySelector('input[type="radio"]');
                    if (!radio) return;
                    radio.checked = true;
                    const name  = radio.getAttribute('name');
                    const color = this.dataset.color || 'blue';

                    document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                        const pc = r.closest('.radio-card');
                        if (pc) {
                            pc.classList.remove(
                                'selected-blue','selected-amber',
                                'selected-purple','selected-green'
                            );
                        }
                    });
                    this.classList.add('selected-' + color);
                });
            });
        }

        // ── Toggle radio (Yes/No / Planned/Sudden) ──────────────
        function setupToggleRadios() {
            document.querySelectorAll('.toggle-radio').forEach(tr => {
                tr.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    if (!radio) return;
                    radio.checked = true;
                    const group = this.dataset.group;
                    const val   = this.dataset.val;

                    document.querySelectorAll(`.toggle-radio[data-group="${group}"]`).forEach(t => {
                        t.classList.remove(
                            'checked-yes','checked-no',
                            'checked-planned','checked-sudden'
                        );
                    });

                    if (group === 'absence_type') {
                        this.classList.add(val === 'Planned' ? 'checked-planned' : 'checked-sudden');
                    } else {
                        this.classList.add(val === 'Yes' ? 'checked-yes' : 'checked-no');
                    }
                });
            });
        }

        // ── Validate section ────────────────────────────────────
        function validateSection(section) {
            let sectionElement;
            switch(section) {
                case 1: sectionElement = section1; break;
                case 2: sectionElement = section2; break;
                case 3: sectionElement = section3; break;
                case 4: sectionElement = section4; break;
                default: return true;
            }

            const requiredInputs = sectionElement.querySelectorAll('[required]');
            let isValid = true;

            requiredInputs.forEach(input => {
                if (input.type === 'radio') {
                    const name = input.getAttribute('name');
                    const radioGroup = sectionElement.querySelectorAll(`input[name="${name}"]`);
                    let isChecked = false;
                    radioGroup.forEach(r => { if (r.checked) isChecked = true; });
                    if (!isChecked) {
                        isValid = false;
                        input.closest('.radio-card')?.classList.add('border-red-500');
                    } else {
                        radioGroup.forEach(r => r.closest('.radio-card')?.classList.remove('border-red-500'));
                    }
                } else if (input.type === 'checkbox') {
                    if (!input.checked && input.getAttribute('name') === 'responsibility_acceptance') {
                        isValid = false;
                        input.classList.add('border-red-500');
                        input.closest('.resp-box')?.classList.add('border-red-500');
                    } else {
                        input.classList.remove('border-red-500');
                        input.closest('.resp-box')?.classList.remove('border-red-500');
                    }
                } else if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('input-error');
                } else {
                    input.classList.remove('input-error');
                }
            });

            if (section === 1) {
                const s = document.getElementById('start_date').value;
                const en = document.getElementById('end_date').value;
                if (s && en && new Date(en) < new Date(s)) {
                    isValid = false;
                    document.getElementById('end_date').classList.add('input-error');
                    showToast('End date must be after start date', 'error');
                }
            }
            return isValid;
        }

        // ── Update progress indicator ───────────────────────────
        function updateProgress(newSection) {
            const steps  = [step1, step2, step3, step4];
            const labels = ['label1','label2','label3','label4'].map(id => document.getElementById(id));

            steps.forEach((step, index) => {
                const n = index + 1;
                step.classList.remove('active','completed');
                if (labels[index]) labels[index].classList.remove('active','completed');

                if (n < newSection) {
                    step.classList.add('completed');
                    step.style.background = 'linear-gradient(135deg,#234C6A,#456882)';
                    step.style.color = 'white';
                    if (labels[index]) labels[index].classList.add('completed');
                } else if (n === newSection) {
                    step.classList.add('active');
                    step.style.background = 'linear-gradient(135deg,#1B3C53,#456882)';
                    step.style.color = 'white';
                    if (labels[index]) labels[index].classList.add('active');
                } else {
                    step.style.background = '#e5e7eb';
                    step.style.color = '#9ca3af';
                }
            });

            [progress1, progress2, progress3].forEach((bar, i) => {
                bar.style.width = (i + 2 <= newSection) ? '100%' : '0%';
            });
        }

        // ── Navigate between sections ───────────────────────────
        function navigateToSection(section) {
            if (section > currentSection) {
                if (!validateSection(currentSection)) {
                    showToast('Please fill in all required fields in this section', 'error');
                    return false;
                }
            }

            section1.classList.add('hidden');
            section2.classList.add('hidden');
            section3.classList.add('hidden');
            section4.classList.add('hidden');

            switch(section) {
                case 1: section1.classList.remove('hidden'); break;
                case 2: section2.classList.remove('hidden'); break;
                case 3: section3.classList.remove('hidden'); break;
                case 4: section4.classList.remove('hidden'); break;
            }

            if (section === 1) {
                prevBtn.classList.add('hidden');
            } else {
                prevBtn.classList.remove('hidden');
            }

            if (section === totalSections) {
                nextBtn.classList.add('hidden');
                submitBtn.classList.remove('hidden');
            } else {
                nextBtn.classList.remove('hidden');
                submitBtn.classList.add('hidden');
            }

            updateProgress(section);

            const cur = document.getElementById(`section${section}`);
            cur.classList.add('animate-slide-right');
            setTimeout(() => cur.classList.remove('animate-slide-right'), 500);

            currentSection = section;
            return true;
        }

        function nextSection() { navigateToSection(currentSection + 1); }
        function prevSection() { navigateToSection(currentSection - 1); }

        // ── Toast notification ──────────────────────────────────
        function showToast(message, type = 'info') {
            document.querySelectorAll('.custom-toast').forEach(t => t.remove());

            const toast = document.createElement('div');
            const cls = type === 'success' ? 'toast-success' : type === 'error' ? 'toast-error' : 'toast-info';
            const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
            toast.className = `custom-toast ${cls}`;
            toast.innerHTML = `<i class="fas ${icon}"></i><span>${message}</span>`;
            document.body.appendChild(toast);

            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.parentNode && toast.parentNode.removeChild(toast), 350);
            }, 3000);
        }

        // ── Form submission validation ──────────────────────────
        function validateForm() {
            if (!validateSection(4)) {
                showToast('Please fill in all required fields in the final section', 'error');
                return false;
            }
            if (!document.getElementById('responsibility_acceptance').checked) {
                showToast('Please accept the responsibility statement', 'error');
                return false;
            }
            return true;
        }

        // ── Init ────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            setupRadioCards();
            setupToggleRadios();

            nextBtn.addEventListener('click', nextSection);
            prevBtn.addEventListener('click', prevSection);

            const form = document.getElementById('leaveForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!validateForm()) e.preventDefault();
                });
            }

            navigateToSection(1);

            // Input focus effects
            document.querySelectorAll('input, textarea, select').forEach(input => {
                input.addEventListener('focus', () => input.parentElement?.classList.add('focused'));
                input.addEventListener('blur',  () => { if (!input.value) input.parentElement?.classList.remove('focused'); });
            });
        });

        // Mobile menu toggle
        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        }

        // Expose globally
        window.nextSection = nextSection;
        window.prevSection = prevSection;
        window.toggleMobileMenu = toggleMobileMenu;
        window.calculateTotalDays = calculateTotalDays;
    </script>
</body>
</html>