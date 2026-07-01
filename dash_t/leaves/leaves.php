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
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../logout.php");
    exit;
}

require_once '../db_connection.php';

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get trainer details
    $stmt = $db->prepare("SELECT t.*, u.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
    $stmt->execute([$user_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainer) {
        header("Location: ../logout.php");
        exit;
    }

    // Get courses assigned to this trainer for dropdown
    $batches_stmt = $db->prepare("
        SELECT DISTINCT c.id as batch_id, c.name as batch_name 
        FROM courses c
        JOIN batch_courses bc ON c.id = bc.course_id
        JOIN batches b ON bc.batch_id = b.batch_id
        WHERE b.batch_mentor_id = ? AND b.status != 'completed'
        ORDER BY c.name ASC
    ");
    $batches_stmt->execute([$trainer['id']]);
    $batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate required fields
        $required_fields = ['batch_id', 'start_date', 'end_date', 'reason_category', 'reason_detail'];
        $missing_fields = [];
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            $error_message = "Please fill all required fields: " . implode(', ', $missing_fields);
        } else {
            $batch_id = $_POST['batch_id'];
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $reason_category = $_POST['reason_category'];
            $reason_detail = trim($_POST['reason_detail']);
            $absence_type = $_POST['absence_type'] ?? 'Planned';
            $informed_academy = $_POST['informed_academy'] ?? 'Yes';
            $medical_prescription = $_POST['medical_prescription'] ?? '';
            
            // Calculate total days
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $interval = $start->diff($end);
            $total_days = $interval->days + 1;
            
            // Generate application number
            $app_no = 'LEAVE-' . strtoupper(uniqid());
            
            // Get trainer name and email
            $trainer_name = $trainer['name'];
            $trainer_email = $trainer['email'] ?? $_SESSION['email'] ?? '';
            
            // Insert leave application
            $insert_stmt = $db->prepare("
                INSERT INTO leave_applications (
                    application_no, student_id, student_name, batch_id, email,
                    start_date, end_date, total_days,
                    reason_category, reason_detail, absence_type, informed_academy,
                    medical_prescription,
                    course_importance, content_value, topic_understanding,
                    practical_ability, unique_learning, loss_reflection,
                    acceptable_situation, support_needed, future_commitment,
                    counselling_request, responsibility_acceptance,
                    status, created_at
                ) VALUES (
                    :application_no, :student_id, :student_name, :batch_id, :email,
                    :start_date, :end_date, :total_days,
                    :reason_category, :reason_detail, :absence_type, :informed_academy,
                    :medical_prescription,
                    'Yes, very important', 'Very valuable', 'Yes, clearly',
                    'Yes', 'Yes', 'I will cover the missed topics on my own',
                    'Medical emergency, family event', 'Study materials and notes', 'Yes',
                    'No', 1,
                    'pending', NOW()
                )
            ");
            
            $insert_stmt->execute([
                ':application_no' => $app_no,
                ':student_id' => $user_id,
                ':student_name' => $trainer_name,
                ':batch_id' => $batch_id,
                ':email' => $trainer_email,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':total_days' => $total_days,
                ':reason_category' => $reason_category,
                ':reason_detail' => $reason_detail,
                ':absence_type' => $absence_type,
                ':informed_academy' => $informed_academy,
                ':medical_prescription' => $medical_prescription
            ]);
            
            // Add to history
            $application_id = $db->lastInsertId();
            $history_stmt = $db->prepare("
                INSERT INTO leave_application_history (application_id, action, action_by, remarks)
                VALUES (:application_id, 'submitted', :action_by, :remarks)
            ");
            $history_stmt->execute([
                ':application_id' => $application_id,
                ':action_by' => $user_id,
                ':remarks' => 'Leave application submitted'
            ]);
            
            $success_message = "Leave application submitted successfully! Application Number: " . $app_no;
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
    <title>Apply for Leave | Trainer Dashboard</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .glass-card:hover {
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        }
        
        .gradient-text {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }
        
        .form-input, .form-select, .form-textarea {
            transition: all 0.2s ease;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            width: 100%;
            font-size: 0.95rem;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #1B3C53;
            box-shadow: 0 0 0 3px rgba(27,60,83, 0.1);
        }
        
        .form-label {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            display: block;
            font-size: 0.875rem;
        }
        
        .required::after {
            content: '*';
            color: #ef4444;
            margin-left: 4px;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-approved {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-rejected {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-cancelled {
            background-color: #f3f4f6;
            color: #374151;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(27,60,83, 0.3);
        }
        
        .btn-secondary {
            background: #6b7280;
            transition: all 0.2s ease;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        /* Mobile Navigation Styles */
        .mobile-nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-radius: 0.5rem;
            transition: all 0.3s;
        }
        
        .mobile-nav-link.active {
            background-color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-weight: 600;
        }
        
        #mobileMenu {
            transition: opacity 0.3s ease-in-out;
        }
        
        @media (max-width: 768px) {
            .glass-card {
                border-radius: 12px;
            }
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            border-radius: 4px;
        }
        
        .floating-shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.05;
            z-index: -1;
            filter: blur(40px);
        }
        
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }
        
        .wave-animation {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 80px;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none"><path d="M0,0V46.29c47.79,22.2,103.59,32.17,158,28,70.36-5.37,136.33-33.31,206.8-37.5C438.64,32.43,512.34,53.67,583,72.05c69.27,18,138.3,24.88,209.4,13.08,36.15-6,69.85-17.84,104.45-29.34C989.49,25,1113-14.29,1200,52.47V0Z" fill="%23667eea" opacity=".05"/></svg>');
            background-size: cover;
            background-repeat: no-repeat;
            opacity: 0.5;
            z-index: -1;
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    
        /* ===== Same dashboard/student theme visual enhancement only ===== */
        :root {
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 46%, #456882 100%);
            --dash-blue: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --dash-green: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
            --dash-orange: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            --dash-red: linear-gradient(135deg, #ef4444 0%, #f43f5e 100%);
            --dash-ink: #101827;
        }

        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83, .15), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130, .15), transparent 30%),
                linear-gradient(135deg, #eef4ff 0%, #f7f3ff 48%, #f8fbff 100%) !important;
            color: var(--dash-ink);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(27,60,83, .055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(69,104,130, .045) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.82), transparent 84%);
            z-index: -2;
        }

        .glass-card, .stat-card, .application-card, .filter-card, .info-card-pro {
            background: rgba(255,255,255,.90) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }

        .hero-shell {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: clamp(1.25rem, 2.5vw, 2rem);
            color: white;
            background: var(--dash-main);
            box-shadow: 0 24px 58px rgba(27,60,83,.25);
        }

        .hero-shell::before {
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

        .hero-shell::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .hero-shell > * { position: relative; z-index: 1; }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .5rem .78rem;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.25);
            color: white;
            font-size: .74rem;
            font-weight: 900;
            letter-spacing: .02em;
            backdrop-filter: blur(12px);
        }

        .hero-mini {
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.24);
            border-radius: 18px;
            padding: 1rem;
            backdrop-filter: blur(14px);
        }

        .feature-shell {
            position: relative;
            overflow: hidden;
            border-radius: 26px !important;
        }

        .feature-shell::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--dash-main));
        }

        .feature-shell::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            right: -55px;
            top: -55px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(8px);
            opacity: .75;
            pointer-events: none;
        }

        .feature-shell > * { position: relative; z-index: 1; }

        .feature-form {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-info {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-list {
            --feature-accent: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(79,70,229,.13), rgba(69,104,130,.05) 60%, transparent 72%);
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

        .form-input, .form-select, .form-textarea {
            background: rgba(248,250,252,.92) !important;
            border: 1px solid rgba(148,163,184,.34) !important;
            border-radius: 16px !important;
            min-height: 46px;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 4px rgba(139,92,246,.12) !important;
            background: #fff !important;
        }

        .form-label {
            color: #334155 !important;
            font-weight: 900 !important;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: .75rem !important;
        }

        .btn-primary, .btn-purple-pro {
            background: var(--dash-main) !important;
            color: white !important;
            box-shadow: 0 14px 28px rgba(35,76,106,.20);
            border-radius: 16px !important;
            font-weight: 900 !important;
        }

        .btn-primary:hover, .btn-purple-pro:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(35,76,106,.26);
        }

        .btn-secondary {
            border-radius: 16px !important;
            font-weight: 900 !important;
            background: linear-gradient(135deg, #64748b, #475569) !important;
        }

        .btn-danger {
            border-radius: 16px !important;
            font-weight: 900 !important;
            background: var(--dash-red) !important;
        }

        .application-card {
            position: relative;
            overflow: hidden;
        }

        .application-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--dash-main);
        }

        .application-card::after {
            content: "";
            position: absolute;
            width: 160px;
            height: 160px;
            right: -60px;
            top: -70px;
            border-radius: 999px;
            background: rgba(139,92,246,.08);
        }

        .application-card > * { position: relative; z-index: 1; }

        .stat-card {
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--dash-main);
        }

        .filter-card {
            position: relative;
            overflow: hidden;
            border-radius: 24px !important;
        }

        .filter-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, #234C6A, #234C6A, #456882);
        }

        .empty-state-pro {
            border: 1px dashed rgba(148,163,184,.45);
            background: linear-gradient(135deg, rgba(255,255,255,.9), rgba(248,250,255,.88));
            border-radius: 26px;
        }

        @media (max-width: 768px) {
            .hero-shell { border-radius: 22px; }
            .glass-card, .stat-card, .application-card, .filter-card { border-radius: 20px !important; }
        }

    
/* ===== Brand palette update: #1B3C53, #234C6A, #456882, #D2C1B6 ===== */
:root {
    --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --trainer-primary: #234C6A !important;
    --trainer-violet: #1B3C53 !important;
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
}
body {
    background:
        radial-gradient(circle at 14% 10%, rgba(27,60,83,.13), transparent 28%),
        radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
        linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
}
.bg-gradient-to-r.from-purple-500.to-pink-500,
.bg-gradient-to-r.from-indigo-500.to-purple-500,
.bg-gradient-to-r.from-indigo-600.to-purple-600,
.bg-gradient-to-r.from-blue-500.to-cyan-500,
.bg-gradient-to-r.from-blue-500.to-indigo-500,
.bg-gradient-to-r.from-purple-600.to-pink-600,
.bg-gradient-to-br.from-purple-500.to-pink-500,
.bg-gradient-to-br.from-blue-500.to-indigo-500,
.bg-gradient-to-br.from-indigo-500.to-purple-500,
.avatar-gradient,.avatar-gradient-2,.avatar-gradient-3,.avatar-gradient-4,.avatar-gradient-5 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.text-purple-500,.text-purple-600,.text-indigo-500,.text-indigo-600,.text-blue-500,.text-blue-600,.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-purple-200,.border-indigo-200,.border-blue-200 {
    border-color: rgba(69,104,130,.25) !important;
}
button[style*="--primary-gradient"],.btn-primary,.tab-button.active,.view-toggle.active,.page-link.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.gradient-text {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    color: transparent !important;
}
.hero-chip,.section-kicker {
    border-color: rgba(210,193,182,.45) !important;
}

    </style>
<style>

/* ===== Company Source Safe UI Patch: Apply Leave approved theme ===== */
/* CSS-only patch. PHP session, POST submit, DB insert, validation, form names, links and JS untouched. */

body {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,83,.10), transparent 28%),
        radial-gradient(circle at 88% 4%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 46%, #FBFAF8 100%) !important;
    color: #1B3C53 !important;
}

/* Remove old purple/blue decorative noise without touching layout */
.floating-shape,
.wave-animation {
    opacity: .16 !important;
    filter: blur(46px) !important;
}

/* Top header: same clean company theme */
header.sticky {
    background: rgba(255,253,250,.88) !important;
    backdrop-filter: blur(18px) !important;
    border-bottom: 1px solid rgba(210,193,182,.56) !important;
    box-shadow: 0 12px 34px rgba(27,60,83,.08) !important;
}

header.sticky h1,
header.sticky h1 span,
header.sticky p {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    background: none !important;
}

header.sticky h1 > div,
header.sticky .animate-pulse,
header.sticky .w-8.h-8 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.35), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    box-shadow: 0 12px 26px rgba(27,60,83,.18) !important;
    animation: none !important;
}

header.sticky h1 i,
header.sticky .animate-pulse i,
header.sticky .w-8.h-8 i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

/* My Applications button: theme, not random purple invading the ecosystem */
header.sticky a[href="view.php"] {
    background:
        radial-gradient(circle at 90% 10%, rgba(255,255,255,.16), transparent 35%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.3px solid rgba(255,255,255,.34) !important;
    border-radius: 14px !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.18) !important;
    font-weight: 900 !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

header.sticky a[href="view.php"]:hover {
    transform: translateY(-2px) !important;
    filter: brightness(1.06) !important;
    box-shadow: 0 20px 38px rgba(27,60,83,.24) !important;
}

/* Main container width: balanced and clean like previous approved pages */
.max-w-5xl {
    max-width: 980px !important;
}

/* Hero banner */
.hero-shell {
    background:
        radial-gradient(circle at 92% 20%, rgba(255,255,255,.18), transparent 26%),
        radial-gradient(circle at 70% 110%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    border: 1.6px solid rgba(255,255,255,.24) !important;
    border-radius: 28px !important;
    box-shadow:
        0 24px 64px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
}

.hero-shell h2,
.hero-shell p,
.hero-shell span,
.hero-shell i,
.hero-shell .hero-pill,
.hero-shell .hero-mini {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 7px rgba(0,0,0,.16) !important;
}

.hero-pill,
.hero-mini {
    background: rgba(255,255,255,.16) !important;
    border: 1.4px solid rgba(255,255,255,.32) !important;
    box-shadow:
        0 10px 22px rgba(15,23,42,.12),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    backdrop-filter: blur(12px) !important;
}

.hero-mini {
    transition: transform .22s ease, box-shadow .22s ease, background .22s ease !important;
}

.hero-mini:hover {
    transform: translateY(-4px) !important;
    background: rgba(255,255,255,.22) !important;
    box-shadow:
        0 18px 34px rgba(15,23,42,.17),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

/* Main form card + info card shades */
.glass-card,
.info-card-pro,
.feature-shell {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.09), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.55px solid rgba(210,193,182,.66) !important;
    border-radius: 24px !important;
    box-shadow:
        0 18px 44px rgba(27,60,83,.11),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    backdrop-filter: blur(16px) !important;
}

.feature-shell {
    position: relative !important;
    overflow: hidden !important;
    border-radius: 26px !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.feature-shell::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 0 auto 0 !important;
    height: 5px !important;
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882) !important;
    z-index: 1 !important;
}

.feature-shell::after {
    content: "" !important;
    position: absolute !important;
    width: 190px !important;
    height: 190px !important;
    right: -60px !important;
    top: -60px !important;
    border-radius: 999px !important;
    background: radial-gradient(circle, rgba(69,104,130,.13), rgba(210,193,182,.08) 58%, transparent 72%) !important;
    filter: blur(7px) !important;
    pointer-events: none !important;
}

.feature-shell > * {
    position: relative !important;
    z-index: 2 !important;
}

.feature-shell:hover {
    border-color: rgba(35,76,106,.42) !important;
    box-shadow:
        0 26px 58px rgba(27,60,83,.16),
        inset 0 1px 0 rgba(255,255,255,.90) !important;
}

/* Section kicker */
.section-kicker {
    background:
        linear-gradient(135deg, rgba(255,253,250,.96), rgba(238,243,246,.90)) !important;
    border: 1.3px solid rgba(210,193,182,.72) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    box-shadow: 0 8px 20px rgba(27,60,83,.08) !important;
}

/* New Leave Application icon, because it deserves an actual theme */
.glass-card .w-12.h-12 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.35), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    border: 1.3px solid rgba(255,255,255,.34) !important;
    box-shadow:
        0 14px 28px rgba(27,60,83,.18),
        inset 0 1px 0 rgba(255,255,255,.22) !important;
    transition: transform .22s ease, box-shadow .22s ease !important;
}

.glass-card:hover .w-12.h-12 {
    transform: translateY(-2px) scale(1.06) rotate(2deg) !important;
    box-shadow:
        0 18px 36px rgba(27,60,83,.24),
        0 0 0 7px rgba(69,104,130,.10),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

/* Form labels and fields */
.form-label {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
    text-transform: uppercase !important;
    letter-spacing: .045em !important;
    font-size: .74rem !important;
}

.form-input,
.form-select,
.form-textarea {
    background: rgba(255,255,255,.96) !important;
    border: 1.35px solid rgba(69,104,130,.28) !important;
    color: #102A3A !important;
    -webkit-text-fill-color: #102A3A !important;
    border-radius: 15px !important;
    font-weight: 800 !important;
    box-shadow:
        0 8px 20px rgba(27,60,83,.045),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
}

.form-input::placeholder,
.form-textarea::placeholder {
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
    opacity: .70 !important;
    font-weight: 700 !important;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
    border-color: #234C6A !important;
    box-shadow:
        0 0 0 4px rgba(35,76,106,.13),
        0 12px 24px rgba(27,60,83,.09) !important;
    background: #ffffff !important;
}

/* Buttons */
.btn-primary,
.btn-purple-pro {
    background:
        radial-gradient(circle at 90% 10%, rgba(255,255,255,.16), transparent 35%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.3px solid rgba(255,255,255,.30) !important;
    border-radius: 15px !important;
    font-weight: 1000 !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.btn-secondary {
    background: linear-gradient(135deg, #64748b, #475569) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border-radius: 15px !important;
    font-weight: 1000 !important;
    box-shadow: 0 14px 28px rgba(71,85,105,.16) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.btn-primary:hover,
.btn-secondary:hover,
.btn-purple-pro:hover {
    transform: translateY(-3px) !important;
    filter: brightness(1.06) !important;
}

/* Important notes: dark and theme-matched, not loud blue cafeteria notice */
.info-card-pro {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.10), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.24), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.99), rgba(238,243,246,.90)) !important;
}

.info-card-pro .fa-info-circle {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

.info-card-pro .text-blue-800,
.info-card-pro .text-blue-900,
.info-card-pro .text-blue-700,
.info-card-pro p,
.info-card-pro li {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

.info-card-pro ul {
    background:
        linear-gradient(135deg, rgba(255,255,255,.72), rgba(246,241,237,.70)) !important;
    border: 1px solid rgba(210,193,182,.62) !important;
    border-radius: 16px !important;
    padding: 1rem 1.15rem 1rem 1.35rem !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.80) !important;
}

.info-card-pro li {
    margin-bottom: .32rem !important;
}

/* Alerts keep function, improve look */
.bg-green-100 {
    background:
        linear-gradient(135deg, rgba(236,253,245,.98), rgba(209,250,229,.88)) !important;
    border-color: #059669 !important;
    color: #047857 !important;
    border-radius: 16px !important;
    box-shadow: 0 12px 28px rgba(5,150,105,.12) !important;
}

.bg-red-100 {
    background:
        linear-gradient(135deg, rgba(254,242,242,.98), rgba(254,226,226,.88)) !important;
    border-color: #dc2626 !important;
    color: #991b1b !important;
    border-radius: 16px !important;
    box-shadow: 0 12px 28px rgba(220,38,38,.10) !important;
}

/* Footer */
footer {
    border-top: 1px solid rgba(210,193,182,.50) !important;
    color: #456882 !important;
}

/* Mobile drawer stays usable */
#mobileMenu nav a.bg-white {
    background:
        linear-gradient(135deg, rgba(238,243,246,.96), rgba(246,241,237,.88)) !important;
    color: #1B3C53 !important;
    border: 1px solid rgba(210,193,182,.62) !important;
}

@media (max-width: 768px) {
    .hero-shell,
    .glass-card,
    .info-card-pro,
    .feature-shell {
        border-radius: 20px !important;
    }

    .max-w-5xl {
        max-width: 100% !important;
    }
}

</style>
</head>
<body class="relative overflow-x-hidden">
    <!-- Floating Background Shapes -->
    <div class="floating-shape w-96 h-96 bg-purple-300 top-0 -left-24" style="animation: float 20s ease-in-out infinite;"></div>
    <div class="floating-shape w-80 h-80 bg-blue-300 bottom-0 -right-20" style="animation: float 25s ease-in-out infinite reverse;"></div>
    <div class="wave-animation"></div>
    
    <!-- Sidebar (Desktop) -->
    <?php include '../t_sidebar.php'; ?>
    
    <div class="flex-1 ml-0 lg:ml-64 min-h-screen transition-all duration-300 ease-in-out">
        <!-- Mobile Header -->
        <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 lg:hidden">
            <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
                <div class="bg-indigo-100 p-2 rounded-lg">
                    <i class="fas fa-calendar-alt text-indigo-600 text-sm"></i>
                </div>
                <span>Apply Leave</span>
            </h1>
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-chalkboard-teacher text-indigo-600"></i>
                </div>
            </div>
        </header>

        <!-- Desktop Header -->
        <header class="hidden lg:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
            <div class="flex-1"></div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <div class="bg-indigo-100 p-2 rounded-lg">
                    <i class="fas fa-calendar-alt text-indigo-600 text-xl"></i>
                </div>
                <span>Apply for Leave</span>
            </h1>
            <div class="flex-1 flex justify-end items-center space-x-4">
                <a href="view.php" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors flex items-center gap-2">
                    <i class="fas fa-eye"></i>
                    <span>My Applications</span>
                </a>
                <div class="animate-pulse bg-indigo-100 rounded-full p-2">
                    <i class="fas fa-chalkboard-teacher text-indigo-600"></i>
                </div>
            </div>
        </header>

        <!-- Mobile Navigation Menu -->
        <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden lg:hidden">
            <div class="absolute left-0 top-0 h-full w-4/5 max-w-xs bg-gradient-to-b from-blue-50 to-indigo-50 shadow-xl transform transition-transform duration-300 -translate-x-full">
                <div class="p-4 border-b border-blue-200 bg-gradient-to-r from-blue-100 to-indigo-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                        </div>
                        <button onclick="toggleMobileMenu()" class="text-gray-500 hover:text-indigo-600 text-xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="mt-4 flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold">
                            <?= strtoupper(substr($trainer['name'] ?? 'T', 0, 1)) ?>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?= htmlspecialchars($trainer['name'] ?? 'Trainer') ?></p>
                            <p class="text-xs text-gray-600">Trainer</p>
                        </div>
                    </div>
                </div>
                <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                    <a href="../dashboard/dashboard.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-tachometer-alt text-gray-500"></i></div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    <a href="../batches/batches.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-users text-gray-500"></i></div>
                        <span class="font-medium">My Batches</span>
                    </a>
                    <a href="../students/students.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-user-graduate text-gray-500"></i></div>
                        <span class="font-medium">My Students</span>
                    </a>
                    <a href="../schedule/schedule.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-calendar-alt text-gray-500"></i></div>
                        <span class="font-medium">Schedule</span>
                    </a>
                    <a href="../attendance/trainer_attendance.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-clipboard-check text-gray-500"></i></div>
                        <span class="font-medium">Attendance</span>
                    </a>
                    <a href="../feedback/weekly_feedback.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-comment-dots text-gray-500"></i></div>
                        <span class="font-medium">Feedback</span>
                    </a>
                    <a href="../exam/trainer_dashboard.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-file-alt text-gray-500"></i></div>
                        <span class="font-medium">Exams</span>
                    </a>
                    <a href="../content/trainer_content.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-tasks text-gray-500"></i></div>
                        <span class="font-medium">Study Materials</span>
                    </a>
                    <a href="leaves.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 bg-white shadow-md text-purple-600" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-calendar-week text-purple-600"></i></div>
                        <span class="font-medium">Apply Leave</span>
                    </a>
                    <a href="view.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-eye text-gray-500"></i></div>
                        <span class="font-medium">My Leaves</span>
                    </a>
                    <a href="../profile.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-user text-gray-500"></i></div>
                        <span class="font-medium">Profile</span>
                    </a>
                    <a href="../logout.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-red-50 hover:text-red-600 text-gray-700 mt-4 border-t pt-4" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-sign-out-alt text-red-500"></i></div>
                        <span class="font-medium">Logout</span>
                    </a>
                </nav>
            </div>
        </div>

        <div class="p-4 lg:p-6 min-h-screen">
            <div class="max-w-5xl mx-auto">
                <!-- Visual Hero -->
                <section class="hero-shell mb-6 animate-fade-in">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-center">
                        <div class="lg:col-span-2">
                            <div class="flex flex-wrap gap-2 mb-4">
                                <span class="hero-pill"><i class="fas fa-calendar-week"></i> Leave Workspace</span>
                                <span class="hero-pill"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($trainer['name'] ?? 'Trainer') ?></span>
                                <span class="hero-pill"><i class="fas fa-calendar-day"></i> <?= date('l, d M Y') ?></span>
                            </div>
                            <h2 class="text-3xl sm:text-4xl font-black tracking-tight mb-3">Leave Request Center</h2>
                            <p class="text-white/85 text-sm sm:text-base max-w-2xl">
                                Submit trainer leave requests with course details, dates, reason category, and supporting notes in one clean form.
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="hero-mini">
                                <p class="text-xs uppercase font-black text-white/70">Assigned Courses</p>
                                <p class="text-3xl font-black mt-1"><?= count($batches) ?></p>
                            </div>
                            <div class="hero-mini">
                                <p class="text-xs uppercase font-black text-white/70">Status</p>
                                <p class="text-xl font-black mt-2">New Request</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                    <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-lg animate-fade-in">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-3 text-xl"></i>
                            <span><?= htmlspecialchars($success_message) ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg animate-fade-in">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-3 text-xl"></i>
                            <span><?= htmlspecialchars($error_message) ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Leave Application Form -->
                <div class="glass-card feature-shell feature-form p-6 mb-8 animate-fade-in">
                    <div class="section-kicker"><i class="fas fa-pen-alt"></i> Application Form</div>
                    <div class="flex items-center space-x-3 mb-6 pb-4 border-b border-gray-100">
                        <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                            <i class="fas fa-pen-alt text-white text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-gray-800">New Leave Application</h2>
                            <p class="text-sm text-gray-500">Fill in the details below to submit a leave request</p>
                        </div>
                    </div>

                    <form method="POST" action="" id="leaveForm" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Course Selection -->
                            <div>
                                <label class="form-label required">Select Course</label>
                                <select name="batch_id" class="form-select" required>
                                    <option value="">-- Select Course --</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?= htmlspecialchars($batch['batch_id']) ?>">
                                            <?= htmlspecialchars($batch['batch_name']) ?> (<?= htmlspecialchars($batch['batch_id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($batches)): ?>
                                    <p class="text-xs text-amber-600 mt-1">No courses assigned. Please contact admin.</p>
                                <?php endif; ?>
                            </div>

                            <!-- Reason Category -->
                            <div>
                                <label class="form-label required">Reason Category</label>
                                <select name="reason_category" class="form-select" required>
                                    <option value="">-- Select Category --</option>
                                    <option value="Health Issue">Health Issue</option>
                                    <option value="Family Emergency">Family Emergency</option>
                                    <option value="Personal Work">Personal Work</option>
                                    <option value="Training/Workshop">Training/Workshop</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <!-- Start Date -->
                            <div>
                                <label class="form-label required">Start Date</label>
                                <input type="date" name="start_date" class="form-input" required min="<?= date('Y-m-d') ?>">
                            </div>

                            <!-- End Date -->
                            <div>
                                <label class="form-label required">End Date</label>
                                <input type="date" name="end_date" class="form-input" required min="<?= date('Y-m-d') ?>">
                            </div>

                            <!-- Absence Type -->
                            <div>
                                <label class="form-label">Absence Type</label>
                                <select name="absence_type" class="form-select">
                                    <option value="Planned">Planned</option>
                                    <option value="Sudden">Sudden</option>
                                </select>
                            </div>

                            <!-- Informed Academy -->
                            <div>
                                <label class="form-label">Informed Academy</label>
                                <select name="informed_academy" class="form-select">
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>

                        <!-- Detailed Reason -->
                        <div>
                            <label class="form-label required">Detailed Reason</label>
                            <textarea name="reason_detail" rows="4" class="form-textarea" placeholder="Please provide a detailed explanation of why you need leave..." required></textarea>
                        </div>

                        <!-- Medical Prescription (Optional) -->
                        <div>
                            <label class="form-label">Medical Prescription (if applicable)</label>
                            <input type="text" name="medical_prescription" class="form-input" placeholder="Upload path or reference">
                            <p class="text-xs text-gray-500 mt-1">Optional - You can provide a reference or upload path for medical documents</p>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex flex-col sm:flex-row gap-4 pt-4 border-t border-gray-100">
                            <button type="submit" class="btn-primary px-6 py-3 text-white rounded-xl font-semibold flex items-center justify-center gap-2">
                                <i class="fas fa-paper-plane"></i>
                                Submit Application
                            </button>
                            <button type="reset" class="btn-secondary px-6 py-3 text-white rounded-xl font-semibold flex items-center justify-center gap-2">
                                <i class="fas fa-undo-alt"></i>
                                Reset Form
                            </button>
                            <a href="view.php" class="btn-purple-pro px-6 py-3 text-white rounded-xl font-semibold flex items-center justify-center gap-2 transition-colors">
                                <i class="fas fa-eye"></i>
                                View My Applications
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Information Card -->
                <div class="info-card-pro feature-shell feature-info mt-6 p-5 rounded-xl border border-blue-100">
                    <div class="section-kicker"><i class="fas fa-info-circle"></i> Important Notes</div>
                    <div class="flex items-start space-x-3">
                        <i class="fas fa-info-circle text-blue-500 text-lg mt-0.5"></i>
                        <div class="text-sm text-blue-800">
                            <p class="font-black mb-1 text-blue-900">Please note:</p>
                            <ul class="list-disc list-inside space-y-1 text-blue-700">
                                <li>Please submit leave applications at least 2 days in advance for planned leaves</li>
                                <li>Medical emergencies should be reported as soon as possible</li>
                                <li>Your application will be reviewed by the admin team</li>
                                <li>You will receive a notification once your application is processed</li>
                                <li>For urgent matters, please contact the admin directly</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="mt-8 px-6 py-4 border-t border-gray-100 text-center text-sm text-gray-500">
                <p>© <?= date('Y') ?> ASD Academy. All rights reserved.</p>
            </footer>
        </div>
    </div>

    <script>
        // Toggle mobile menu
        function toggleMobileMenu() {
            const mobileMenu = document.getElementById('mobileMenu');
            const mobileMenuContent = mobileMenu.querySelector('div');
            
            if (mobileMenu.classList.contains('hidden')) {
                mobileMenu.classList.remove('hidden');
                setTimeout(() => {
                    mobileMenuContent.classList.remove('-translate-x-full');
                }, 10);
            } else {
                mobileMenuContent.classList.add('-translate-x-full');
                setTimeout(() => {
                    mobileMenu.classList.add('hidden');
                }, 300);
            }
        }

        // Close mobile menu on outside click
        document.getElementById('mobileMenu').addEventListener('click', function(e) {
            if (e.target.id === 'mobileMenu') {
                toggleMobileMenu();
            }
        });

        // Handle ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const mobileMenu = document.getElementById('mobileMenu');
                if (!mobileMenu.classList.contains('hidden')) {
                    toggleMobileMenu();
                }
            }
        });

        // Date validation
        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');

        function validateDates() {
            if (startDateInput.value && endDateInput.value) {
                if (new Date(endDateInput.value) < new Date(startDateInput.value)) {
                    endDateInput.setCustomValidity('End date must be after start date');
                } else {
                    endDateInput.setCustomValidity('');
                }
            }
        }

        startDateInput.addEventListener('change', validateDates);
        endDateInput.addEventListener('change', validateDates);

        // Set minimum end date based on start date
        startDateInput.addEventListener('change', function() {
            endDateInput.min = this.value;
            validateDates();
        });

        // Form submission confirmation
        const leaveForm = document.getElementById('leaveForm');
        leaveForm.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to submit this leave application?\n\nPlease ensure all details are correct.')) {
                e.preventDefault();
            }
        });

        // Animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.glass-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.5s ease-out';
            observer.observe(card);
        });
    </script>
</body>
</html>