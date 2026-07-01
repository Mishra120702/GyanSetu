<?php
session_start();
require_once '../db_connection.php';

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

// Get student's tickets
$tickets_query = $db->prepare("
    SELECT t.*, b.batch_name 
    FROM tickets t
    LEFT JOIN batches b ON t.batch_id = b.batch_id
    WHERE t.student_id = :student_id 
    ORDER BY t.created_at DESC
");
$tickets_query->execute([':student_id' => $student['student_id']]);
$tickets = $tickets_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch messages for each ticket
foreach ($tickets as &$t) {
    $msg_stmt = $db->prepare("
        SELECT tm.*, u.name as sender_name, u.role as sender_role
        FROM ticket_messages tm
        JOIN users u ON tm.sender_id = u.id
        WHERE tm.ticket_id = :ticket_id
        ORDER BY tm.created_at ASC
    ");
    $msg_stmt->execute([':ticket_id' => $t['id']]);
    $t['messages'] = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($t);

// Fetch student's enrolled batches
$batches_query = $db->prepare("
    SELECT b.batch_id, b.batch_name 
    FROM students s
    LEFT JOIN batches b ON (s.batch_name = b.batch_id OR s.batch_name_2 = b.batch_id OR s.batch_name_3 = b.batch_id OR s.batch_name_4 = b.batch_id)
    WHERE s.student_id = :student_id AND b.batch_id IS NOT NULL
");
$batches_query->execute([':student_id' => $student['student_id']]);
$student_batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$total_count = count($tickets);
$open_count = 0;
$resolved_count = 0;
foreach ($tickets as $t) {
    if ($t['status'] === 'open') $open_count++;
    if ($t['status'] === 'resolved') $resolved_count++;
}

// Handle ticket form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raise_ticket'])) {
    $reason = trim($_POST['reason'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($reason)) {
        $error_message = "Please select a reason.";
    } else {
        $attachment_path = null;
        
        // Handle file upload
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/tickets/';
            
            // Allow only specific file types
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $file_type = $_FILES['attachment']['type'];
            
            if (in_array($file_type, $allowed_types)) {
                $file_size = $_FILES['attachment']['size'];
                if ($file_size <= 5 * 1024 * 1024) { // 5MB limit
                    $file_extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                    $file_name = 'ticket_' . $student['student_id'] . '_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
                    $target_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                        $attachment_path = 'uploads/tickets/' . $file_name;
                    } else {
                        $error_message = "Failed to upload attachment.";
                    }
                } else {
                    $error_message = "Attachment file size must be less than 5MB.";
                }
            } else {
                $error_message = "Only JPG, PNG, and PDF files are allowed as attachments.";
            }
        }
        
        if (empty($error_message)) {
            $batch_id = !empty($_POST['batch_id']) ? trim($_POST['batch_id']) : null;
            try {
                $insert_stmt = $db->prepare("
                    INSERT INTO tickets (student_id, reason, description, attachment_path, status, batch_id)
                    VALUES (:student_id, :reason, :description, :attachment_path, 'open', :batch_id)
                ");
                $result = $insert_stmt->execute([
                    ':student_id' => $student['student_id'],
                    ':reason' => $reason,
                    ':description' => !empty($description) ? $description : null,
                    ':attachment_path' => $attachment_path,
                    ':batch_id' => $batch_id
                ]);
                
                if ($result) {
                    $_SESSION['ticket_success'] = "Support ticket raised successfully!";
                    header("Location: tickets.php");
                    exit();
                } else {
                    $error_message = "Failed to raise support ticket. Please try again.";
                }
            } catch (PDOException $e) {
                $error_message = "System error: " . $e->getMessage();
            }
        }
    }
}

// Handle close ticket request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    
    // Verify ticket belongs to current student and is open
    $check_stmt = $db->prepare("SELECT * FROM tickets WHERE id = :id AND student_id = :student_id AND status = 'open'");
    $check_stmt->execute([
        ':id' => $ticket_id,
        ':student_id' => $student['student_id']
    ]);
    $ticket = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ticket) {
        try {
            $update_stmt = $db->prepare("
                UPDATE tickets 
                SET status = 'resolved', 
                    admin_response = 'Closed by student', 
                    resolved_at = NOW(), 
                    resolved_by = :resolved_by 
                WHERE id = :ticket_id
            ");
            $update_stmt->execute([
                ':resolved_by' => $student_user_id,
                ':ticket_id' => $ticket_id
            ]);
            
            $_SESSION['ticket_success'] = "Ticket #$ticket_id closed successfully!";
            header("Location: tickets.php");
            exit();
        } catch (PDOException $e) {
            $error_message = "System error: " . $e->getMessage();
        }
    } else {
        $error_message = "Ticket not found or already closed.";
    }
}

// Handle send message request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if (empty($message)) {
        $error_message = "Message cannot be empty.";
    } else {
        // Verify ticket belongs to current student and is open
        $check_stmt = $db->prepare("SELECT * FROM tickets WHERE id = :id AND student_id = :student_id AND status = 'open'");
        $check_stmt->execute([
            ':id' => $ticket_id,
            ':student_id' => $student['student_id']
        ]);
        $ticket = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ticket) {
            try {
                $insert_stmt = $db->prepare("
                    INSERT INTO ticket_messages (ticket_id, sender_id, message)
                    VALUES (:ticket_id, :sender_id, :message)
                ");
                $insert_stmt->execute([
                    ':ticket_id' => $ticket_id,
                    ':sender_id' => $student_user_id,
                    ':message' => $message
                ]);
                
                $_SESSION['ticket_success'] = "Message sent successfully!";
                header("Location: tickets.php");
                exit();
            } catch (PDOException $e) {
                $error_message = "System error: " . $e->getMessage();
            }
        } else {
            $error_message = "Ticket not found or already closed.";
        }
    }
}

// Get success message from session if redirected
if (isset($_SESSION['ticket_success'])) {
    $success_message = $_SESSION['ticket_success'];
    unset($_SESSION['ticket_success']);
}

// Helper function: Category Chips with Icons & Colors
function get_category_chip($category) {
    $c = trim($category ?? '');
    switch ($c) {
        case 'Fee Related':
            return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200">💰 Fee Related</span>';
        case 'Batch/Schedule Related':
        case 'Batch/Schedule':
            return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 border border-blue-200">📅 Batch/Schedule</span>';
        case 'Exam/Test Related':
        case 'Exam/Test':
            return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200">📝 Exam/Test</span>';
        case 'App/Technical Issue':
        case 'App/Technical':
            return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 border border-red-200">🔧 App/Technical</span>';
        case 'Other':
        default:
            return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 border border-gray-200">📌 ' . htmlspecialchars($c ?: 'Other') . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
        }
        .status-badge {
            transition: all 0.3s ease;
        }
        .status-badge:hover {
            transform: translateY(-1px);
        }
        
        /* Glassmorphism pulsing glow for open tickets */
        @keyframes openGlow {
            0%, 100% { box-shadow: 0 0 5px rgba(59, 130, 246, 0.05), 0 4px 6px -1px rgba(0, 0, 0, 0.03); }
            50% { box-shadow: 0 0 15px rgba(59, 130, 246, 0.15), 0 8px 12px -3px rgba(59, 130, 246, 0.06); }
        }
        .open-ticket-glow {
            animation: openGlow 3s infinite ease-in-out;
        }
        
        /* WhatsApp / iMessage Chat Bubble Styles */
        .chat-bubble-left {
            position: relative;
            background-color: rgba(239, 246, 255, 0.9) !important;
            border: 1px solid #bfdbfe !important;
            border-radius: 16px;
            border-top-left-radius: 2px !important;
            color: #1e293b !important;
            animation: slideInLeft 0.3s ease-out;
        }
        .chat-bubble-right {
            position: relative;
            background-color: rgba(236, 253, 245, 0.95) !important;
            border: 1px solid #a7f3d0 !important;
            border-radius: 16px;
            border-top-right-radius: 2px !important;
            color: #1e293b !important;
            animation: slideInRight 0.3s ease-out;
            margin-left: auto;
        }
        /* Bubble tail pseudo-elements */
        .chat-bubble-left::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 0;
            width: 0;
            height: 0;
            border-top: 8px solid #bfdbfe;
            border-left: 8px solid transparent;
        }
        .chat-bubble-right::before {
            content: '';
            position: absolute;
            right: -7px;
            top: 0;
            width: 0;
            height: 0;
            border-top: 8px solid #a7f3d0;
            border-right: 8px solid transparent;
        }
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-15px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(15px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .date-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 16px 0;
            color: #64748b !important;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .date-divider::before, .date-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        }
        .date-divider:not(:empty)::before {
            margin-right: .5em;
        }
        .date-divider:not(:empty)::after {
            margin-left: .5em;
        }
        .tick-mark {
            font-size: 10px;
            color: #10b981 !important;
            margin-left: 4px;
            font-weight: bold;
        }

        /* Navy Theme overrides */
        body {
            background: linear-gradient(135deg, #eef2f5 0%, #f5f0ed 40%, #ede8e4 100%) !important;
        }

        header[class*="from-blue-600"] {
            background: linear-gradient(135deg, rgba(27, 60, 83, 0.92) 0%, rgba(35, 76, 106, 0.92) 60%, rgba(69, 104, 130, 0.92) 100%) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
            box-shadow: 0 4px 20px rgba(27, 60, 83, 0.25) !important;
        }
        header h1 { color: #fff !important; }
        header .text-indigo-600 { color: rgba(255, 255, 255, 0.85) !important; }

        /* Stat Cards Gradients & Hover Effects */
        [class*="from-blue-500"][class*="to-blue-600"] {
            background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
        }

        [class*="from-indigo-500"][class*="to-indigo-600"] {
            background: linear-gradient(135deg, #234C6A, #456882) !important;
        }

        [class*="from-green-500"][class*="to-emerald-500"] {
            background: linear-gradient(135deg, #456882, #D2C1B6) !important;
        }

        [class*="from-blue-500"][class*="to-blue-600"],
        [class*="from-indigo-500"][class*="to-indigo-600"],
        [class*="from-green-500"][class*="to-emerald-500"] {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        /* ── Stat Cards ─────────────────────────────────── */
        .stat-card {
            position: relative;
            overflow: hidden;
            transition: transform 0.38s cubic-bezier(0.34, 1.56, 0.64, 1),
                        box-shadow 0.38s cubic-bezier(0.4, 0, 0.2, 1) !important;
            cursor: default;
        }

        /* Shimmer sweep pseudo-element */
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: -75%;
            width: 50%; height: 100%;
            background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,0.22) 50%, transparent 70%);
            transform: skewX(-20deg);
            transition: left 0.55s ease;
            pointer-events: none;
            z-index: 1;
        }
        .stat-card:hover::before {
            left: 130%;
        }

        .stat-card:hover {
            transform: translateY(-10px) scale(1.03) !important;
            box-shadow:
                0 24px 40px -8px rgba(27, 60, 83, 0.3),
                0 12px 16px -6px rgba(27, 60, 83, 0.18),
                0 0 0 2px rgba(255,255,255,0.15) inset !important;
        }

        /* Bounce & spring the icon on card hover */
        .stat-card:hover i {
            animation: iconPop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards !important;
        }

        @keyframes iconPop {
            0%   { transform: scale(1)    rotate(0deg);   }
            30%  { transform: scale(1.3)  rotate(-14deg); }
            60%  { transform: scale(0.88) rotate(8deg);   }
            80%  { transform: scale(1.12) rotate(-4deg);  }
            100% { transform: scale(1.08) rotate(0deg);   }
        }

        /* Stat Icon Box — spring float */
        .stat-icon-box {
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
                        box-shadow 0.35s ease,
                        background 0.35s ease !important;
        }
        .stat-card:hover .stat-icon-box {
            transform: translateY(-8px) scale(1.15) rotate(-4deg) !important;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2) !important;
            background: rgba(255, 255, 255, 0.45) !important;
        }

        /* Glassmorphism card for Support History container */
        .bg-white.rounded-2xl.shadow-xl.overflow-hidden.animate-fade-in {
            background: rgba(255, 255, 255, 0.75) !important;
            backdrop-filter: blur(16px) !important;
            -webkit-backdrop-filter: blur(16px) !important;
            border: 1px solid rgba(69, 104, 130, 0.25) !important;
            box-shadow: 0 10px 30px -5px rgba(27, 60, 83, 0.08) !important;
            transition: box-shadow 0.3s ease, border-color 0.3s ease !important;
        }
        .bg-white.rounded-2xl.shadow-xl.overflow-hidden.animate-fade-in:hover {
            box-shadow: 0 20px 40px -15px rgba(27, 60, 83, 0.15) !important;
            border-color: rgba(27, 60, 83, 0.4) !important;
        }

        /* ── Individual Support Ticket rows ─────────────── */
        .ticket-row {
            position: relative;
            background: rgba(255, 255, 255, 0.5) !important;
            border-color: rgba(69, 104, 130, 0.2) !important;
            transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1),
                        box-shadow 0.35s ease,
                        border-color 0.3s ease,
                        background 0.3s ease !important;
            box-shadow: 0 2px 8px rgba(27, 60, 83, 0.03) !important;
        }
        /* left accent bar that reveals on hover */
        .ticket-row::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, #1B3C53, #456882);
            border-radius: 4px 0 0 4px;
            opacity: 0;
            transform: scaleY(0.4);
            transition: opacity 0.3s ease, transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .ticket-row:hover::before {
            opacity: 1;
            transform: scaleY(1);
        }
        .ticket-row:hover {
            background: rgba(255, 255, 255, 0.95) !important;
            transform: translateY(-4px) translateX(2px) !important;
            border-color: rgba(27, 60, 83, 0.4) !important;
            box-shadow:
                0 16px 32px -8px rgba(27, 60, 83, 0.18),
                0 4px 12px -2px rgba(27, 60, 83, 0.1) !important;
        }

        /* Ticket Icons Box — spring float + gradient swap */
        .ticket-icon-box {
            background: rgba(210, 193, 182, 0.35) !important;
            color: #1B3C53 !important;
            border: 1px solid rgba(69, 104, 130, 0.25) !important;
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
                        box-shadow 0.35s ease,
                        background 0.35s ease,
                        color 0.35s ease !important;
        }
        .ticket-row:hover .ticket-icon-box {
            transform: translateY(-8px) scale(1.18) rotate(6deg) !important;
            box-shadow: 0 10px 20px rgba(27, 60, 83, 0.2) !important;
            background: linear-gradient(135deg, #1B3C53, #456882) !important;
            color: #ffffff !important;
            border-color: transparent !important;
        }

        /* Attachment Badge link */
        a[class*="text-blue-600"][class*="bg-blue-50"] {
            color: #1B3C53 !important;
            background: rgba(210, 193, 182, 0.3) !important;
            border: 1px solid rgba(69, 104, 130, 0.25) !important;
            transition: all 0.25s ease !important;
        }
        a[class*="text-blue-600"][class*="bg-blue-50"]:hover {
            color: #fff !important;
            background: linear-gradient(135deg, #1B3C53, #456882) !important;
            border-color: transparent !important;
            transform: scale(1.03);
        }

        /* Chat conversation inner background box */
        .max-h-80.overflow-y-auto.mb-4.p-4.bg-slate-50 {
            background: rgba(238, 242, 245, 0.5) !important;
            border-color: rgba(69, 104, 130, 0.15) !important;
            box-shadow: inset 0 2px 8px rgba(27, 60, 83, 0.03) !important;
        }

        /* Chat Bubbles */
        .chat-bubble-right {
            background-color: rgba(242, 238, 236, 0.95) !important;
            border-color: #D2C1B6 !important;
            color: #1B3C53 !important;
        }
        .chat-bubble-right::before {
            border-top-color: #D2C1B6 !important;
        }
        
        .chat-bubble-left {
            background-color: rgba(227, 235, 241, 0.95) !important;
            border-color: rgba(69, 104, 130, 0.3) !important;
            color: #1B3C53 !important;
        }
        .chat-bubble-left::before {
            border-top-color: rgba(69, 104, 130, 0.3) !important;
        }

        .student-bubble, [class*="bg-blue-950"], [class*="bg-blue-50"][class*="border-blue"] {
            background: rgba(227, 235, 241, 0.9) !important;
            border-color: rgba(69, 104, 130, 0.3) !important;
            color: #1B3C53 !important;
        }

        .status-open, span[class*="bg-blue"][class*="text-blue"] {
            background: rgba(210, 193, 182, 0.3) !important;
            color: #1B3C53 !important;
            border-color: rgba(69, 104, 130, 0.35) !important;
        }

        button[type="submit"], form button.submit-ticket, button[name="raise_ticket"], button[name="send_message"], button[onclick="openModal()"] {
            background: linear-gradient(135deg, #456882, #1B3C53) !important;
            color: #fff !important;
            border: none !important;
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.2) !important;
            transition: all 0.3s ease !important;
        }
        button[type="submit"]:hover, button[name="raise_ticket"]:hover, button[name="send_message"]:hover, button[onclick="openModal()"]:hover {
            background: linear-gradient(135deg, #234C6A, #1B3C53) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(27, 60, 83, 0.3) !important;
        }

        thead tr {
            background: linear-gradient(90deg, rgba(210, 193, 182, 0.25), rgba(238, 242, 245, 0.6)) !important;
        }

        div[class*="from-blue-600"][class*="to-indigo-600"] {
            background: linear-gradient(135deg, #1B3C53, #456882) !important;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 min-h-screen">
    <?php include '../header.php'; ?>
    <?php include '../s_sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
        <!-- Mobile Header -->
        <header class="bg-gradient-to-r from-blue-600 to-indigo-600 shadow-lg px-4 py-4 flex justify-between items-center sticky top-0 z-30 md:hidden">
            <button class="text-white text-xl" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-lg font-bold text-white flex items-center space-x-2">
                <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                    <i class="fas fa-ticket-alt text-white text-sm"></i>
                </div>
                <span>Support Tickets</span>
            </h1>
            <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                <i class="fas fa-user-graduate text-indigo-600"></i>
            </div>
        </header>

        <!-- Desktop Header -->
        <header class="hidden md:flex bg-gradient-to-r from-blue-600 to-indigo-600 shadow-lg px-8 py-5 justify-between items-center sticky top-0 z-30">
            <div class="flex-1"></div>
            <div class="flex items-center space-x-4">
                <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                    <i class="fas fa-ticket-alt text-white text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-white">Support Tickets</h1>
            </div>
            <div class="flex-1 flex justify-end items-center space-x-4">
                <div class="relative group">
                    <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center cursor-pointer group-hover:scale-110 transition-transform">
                        <i class="fas fa-user-graduate text-indigo-600"></i>
                    </div>
                    <div class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 transform group-hover:translate-y-0 translate-y-2 z-50">
                        <div class="p-3 border-b">
                            <p class="font-medium text-gray-800"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($student['student_id']) ?></p>
                        </div>
                        <a href="../stu_dash/student_profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 transition-colors">
                            <i class="fas fa-user mr-2"></i> Profile
                        </a>
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="p-4 md:p-8">
            <!-- Alert Messages -->
            <?php if ($success_message): ?>
                <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-lg shadow-md animate-fade-in flex items-center">
                    <i class="fas fa-check-circle mr-3 text-lg"></i>
                    <span><?= htmlspecialchars($success_message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg shadow-md animate-fade-in flex items-center">
                    <i class="fas fa-exclamation-circle mr-3 text-lg"></i>
                    <span><?= htmlspecialchars($error_message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-blue-100 text-sm font-medium">Total Raised</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $total_count ?></h3>
                        </div>
                        <div class="stat-icon-box bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-ticket-alt text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card bg-gradient-to-br from-indigo-500 to-indigo-600 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in delay-100">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-indigo-100 text-sm font-medium">Open Tickets</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $open_count ?></h3>
                        </div>
                        <div class="stat-icon-box bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-envelope-open text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card bg-gradient-to-br from-green-500 to-emerald-500 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in delay-200">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-green-100 text-sm font-medium">Resolved</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $resolved_count ?></h3>
                        </div>
                        <div class="stat-icon-box bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tickets List & Raise Button -->
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden animate-fade-in">
                <div class="p-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">Support History</h3>
                            <p class="text-sm text-gray-500">Track and manage your support requests</p>
                        </div>
                        <button onclick="openModal()" class="px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-md transform hover:scale-105 flex items-center space-x-2">
                            <i class="fas fa-plus"></i>
                            <span>Raise Support Ticket</span>
                        </button>
                    </div>

                    <?php if (empty($tickets)): ?>
                        <div class="text-center py-16">
                            <div class="bg-gray-100 inline-block p-6 rounded-full mb-4">
                                <i class="fas fa-ticket-alt text-gray-400 text-4xl"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800 mb-1">No Tickets Found</h3>
                            <p class="text-gray-500 max-w-sm mx-auto mb-6">If you are facing issues with your course, fees, schedule, or apps, raise a support ticket and we will look into it.</p>
                            <button onclick="openModal()" class="px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-all font-semibold shadow-md">
                                Raise Your First Ticket
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($tickets as $ticket): ?>
                                <div class="ticket-row bg-gray-50 border border-gray-100 rounded-2xl p-5 hover:shadow-md transition-all">
                                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                        <div class="flex items-start space-x-4 flex-1">
                                            <div class="ticket-icon-box bg-indigo-100 text-indigo-600 p-3.5 rounded-xl">
                                                <i class="fas fa-ticket-alt text-lg"></i>
                                            </div>
                                            <div class="flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <h4 class="font-bold text-gray-800 text-base flex items-center gap-2">Ticket #<?= $ticket['id'] ?> - <?= get_category_chip($ticket['reason']) ?></h4>
                                                    <?php 
                                                    $s_status = trim($ticket['status'] ?? '');
                                                    if (empty($s_status)) $s_status = 'open';
                                                    ?>
                                                    <span class="status-badge px-3 py-1 rounded-full text-xs font-semibold
                                                        <?= $s_status === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' ?>">
                                                        <?= ucfirst($s_status) ?>
                                                    </span>
                                                </div>
                                                <p class="text-xs text-gray-500 mt-1">
                                                    <i class="far fa-calendar-alt mr-1"></i> Raised on: <?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?>
                                                    <?php if (!empty($ticket['batch_name'])): ?>
                                                        <span class="mx-2">•</span>
                                                        <i class="fas fa-graduation-cap mr-1"></i> Batch: <?= htmlspecialchars($ticket['batch_name']) ?>
                                                    <?php endif; ?>
                                                </p>
                                                
                                                <?php if ($ticket['attachment_path']): ?>
                                                    <div class="mt-3.5 mb-2">
                                                        <a href="../<?= htmlspecialchars($ticket['attachment_path']) ?>" target="_blank" class="inline-flex items-center space-x-2 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
                                                            <i class="fas fa-paperclip"></i>
                                                            <span>View Attachment File</span>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Chat & Message Section -->
                                                <div class="mt-4 border-t border-gray-150 pt-4">
                                                    <h5 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Ticket Conversation</h5>
                                                    
                                                    <div class="space-y-4 max-h-80 overflow-y-auto mb-4 p-4 bg-slate-50 border border-slate-200/80 rounded-xl flex flex-col">
                                                        <?php 
                                                        $last_date = null;
                                                        $first_date = date('Y-m-d', strtotime($ticket['created_at']));
                                                        $today = date('Y-m-d');
                                                        $yesterday = date('Y-m-d', strtotime('yesterday'));
                                                        
                                                        if ($first_date === $today) {
                                                            $divider = "Today";
                                                        } elseif ($first_date === $yesterday) {
                                                            $divider = "Yesterday";
                                                        } else {
                                                            $divider = date('d M Y', strtotime($ticket['created_at']));
                                                        }
                                                        echo '<div class="date-divider w-full">' . $divider . '</div>';
                                                        $last_date = $first_date;
                                                        ?>

                                                        <!-- First Message (Ticket Description) -->
                                                        <div class="flex items-start gap-2 max-w-[85%] ml-auto justify-end">
                                                            <div class="chat-bubble-right p-3 text-left">
                                                                <div class="flex items-center space-x-2 mb-1">
                                                                    <span class="text-xs font-bold text-emerald-600">You (Student)</span>
                                                                </div>
                                                                <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($ticket['description'] ?: 'No description provided.') ?></p>
                                                                <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium flex items-center justify-end gap-1">
                                                                    <?= date('h:i A', strtotime($ticket['created_at'])) ?>
                                                                    <span class="<?= $ticket['status'] === 'resolved' ? 'text-blue-500' : 'text-gray-400' ?> font-bold text-[10px]">✓✓</span>
                                                                </div>
                                                            </div>
                                                            <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-emerald-500 to-teal-400 text-white shadow-sm flex items-center justify-center text-xs font-bold shrink-0">
                                                                S
                                                            </div>
                                                        </div>

                                                        <!-- Chat Replies -->
                                                        <?php foreach ($ticket['messages'] as $msg): ?>
                                                            <?php 
                                                                $msg_date = date('Y-m-d', strtotime($msg['created_at']));
                                                                if ($msg_date !== $last_date) {
                                                                    $last_date = $msg_date;
                                                                    if ($msg_date === $today) {
                                                                        $divider_label = "Today";
                                                                    } elseif ($msg_date === $yesterday) {
                                                                        $divider_label = "Yesterday";
                                                                    } else {
                                                                        $divider_label = date('d M Y', strtotime($msg['created_at']));
                                                                    }
                                                                    echo '<div class="date-divider w-full">' . $divider_label . '</div>';
                                                                }

                                                                $is_student = ($msg['sender_role'] === 'student');
                                                                $is_mentor = ($msg['sender_role'] === 'mentor');
                                                                $avatar_initial = $is_student ? 'S' : ($is_mentor ? 'T' : 'A');
                                                                $sender_display = $is_student ? 'You (Student)' : 
                                                                                  ($is_mentor ? htmlspecialchars($msg['sender_name']) . ' (Trainer)' : htmlspecialchars($msg['sender_name']) . ' (Admin)');
                                                            ?>
                                                            
                                                            <?php if ($is_student): ?>
                                                                <!-- Right Aligned message (Student) -->
                                                                <div class="flex items-start gap-2 max-w-[85%] ml-auto justify-end">
                                                                    <div class="chat-bubble-right p-3 text-left">
                                                                        <div class="flex items-center space-x-2 mb-1">
                                                                            <span class="text-xs font-bold text-emerald-600"><?= $sender_display ?></span>
                                                                        </div>
                                                                        <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($msg['message']) ?></p>
                                                                        <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium flex items-center justify-end gap-1">
                                                                            <?= date('h:i A', strtotime($msg['created_at'])) ?>
                                                                            <span class="<?= $ticket['status'] === 'resolved' ? 'text-blue-500' : 'text-gray-400' ?> font-bold text-[10px]">✓✓</span>
                                                                        </div>
                                                                    </div>
                                                                    <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-emerald-500 to-teal-400 text-white shadow-sm flex items-center justify-center text-xs font-bold shrink-0">
                                                                        <?= $avatar_initial ?>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <!-- Left Aligned message (Admin or Trainer) -->
                                                                <?php
                                                                $avatar_gradient = $is_mentor ? 'from-purple-600 to-pink-500' : 'from-blue-600 to-indigo-500';
                                                                $sender_display_color = $is_mentor ? 'text-purple-600' : 'text-blue-600';
                                                                ?>
                                                                <div class="flex items-start gap-2 max-w-[85%]">
                                                                    <div class="w-8 h-8 rounded-full bg-gradient-to-tr <?= $avatar_gradient ?> text-white shadow-sm flex items-center justify-center text-xs font-bold shrink-0">
                                                                        <?= $avatar_initial ?>
                                                                    </div>
                                                                    <div class="chat-bubble-left p-3 text-left">
                                                                        <div class="flex items-center space-x-2 mb-1">
                                                                            <span class="text-xs font-bold <?= $sender_display_color ?>"><?= $sender_display ?></span>
                                                                        </div>
                                                                        <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($msg['message']) ?></p>
                                                                        <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium flex items-center justify-end gap-1">
                                                                            <?= date('h:i A', strtotime($msg['created_at'])) ?>
                                                                            <span class="text-blue-500 font-bold text-[10px]">✓✓</span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>

                                                        <!-- Old Legacy Admin Response if present and status is resolved (fallback) -->
                                                        <?php if ($ticket['status'] === 'resolved' && !empty($ticket['admin_response']) && $ticket['admin_response'] !== 'Closed by student' && $ticket['admin_response'] !== 'Closed by Admin'): ?>
                                                            <?php 
                                                                $legacy_date = !empty($ticket['resolved_at']) ? date('Y-m-d', strtotime($ticket['resolved_at'])) : null;
                                                                if ($legacy_date && $legacy_date !== $last_date) {
                                                                    if ($legacy_date === $today) {
                                                                        $divider_label = "Today";
                                                                    } elseif ($legacy_date === $yesterday) {
                                                                        $divider_label = "Yesterday";
                                                                    } else {
                                                                        $divider_label = date('d M Y', strtotime($ticket['resolved_at']));
                                                                    }
                                                                    echo '<div class="date-divider w-full">' . $divider_label . '</div>';
                                                                }
                                                            ?>
                                                            <div class="flex items-start gap-2 max-w-[85%]">
                                                                <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-blue-600 to-indigo-500 text-white shadow-sm flex items-center justify-center text-xs font-bold shrink-0">
                                                                    A
                                                                </div>
                                                                <div class="chat-bubble-left p-3 text-left">
                                                                    <div class="flex items-center space-x-2 mb-1">
                                                                        <span class="text-xs font-bold text-blue-600">Admin (Response)</span>
                                                                    </div>
                                                                    <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($ticket['admin_response']) ?></p>
                                                                    <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium flex items-center justify-end gap-1">
                                                                        <?php if (!empty($ticket['resolved_at'])): ?>
                                                                            <?= date('h:i A', strtotime($ticket['resolved_at'])) ?>
                                                                        <?php endif; ?>
                                                                        <span class="text-blue-500 font-bold text-[10px]">✓✓</span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <?php if ($ticket['status'] === 'open'): ?>
                                                         <!-- Support Agent Pulse Banner -->
                                                         <div class="flex items-center gap-2 text-[11px] text-blue-600 bg-blue-50/50 border border-blue-100/50 px-3 py-2 rounded-xl mb-3 animate-pulse">
                                                             <span class="flex h-1.5 w-1.5 relative">
                                                                 <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                                                                 <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-blue-500"></span>
                                                             </span>
                                                             <span>Support agent is reviewing your ticket...</span>
                                                         </div>
                                                     <?php endif; ?>
                                                    </div>

                                                    <!-- Reply Input Box if ticket is open -->
                                                    <?php if ($ticket['status'] === 'open'): ?>
                                                        <form method="POST" action="tickets.php" class="flex gap-2">
                                                            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                            <input type="text" name="message" required placeholder="Type your reply here..." class="flex-1 px-4 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                            <button type="submit" name="send_message" class="px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm transition-all flex items-center space-x-1">
                                                                <i class="fas fa-paper-plane"></i>
                                                                <span>Reply</span>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <div class="text-center py-2 bg-gray-100 rounded-xl border border-gray-200 text-xs font-semibold text-gray-500">
                                                            <i class="fas fa-lock mr-1"></i> This ticket is closed. Chat is disabled.
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php if ($ticket['status'] === 'open'): ?>
                                            <div class="flex items-center lg:self-start mt-2 lg:mt-0">
                                                <form method="POST" onsubmit="return confirm('Are you sure you want to close this ticket?');">
                                                    <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                    <button type="submit" name="close_ticket" class="px-4 py-2 bg-red-50 hover:bg-red-100 text-red-600 hover:text-red-700 font-semibold rounded-xl text-sm transition-all flex items-center space-x-1.5 border border-red-200 shadow-sm">
                                                        <i class="fas fa-times-circle"></i>
                                                        <span>Close Ticket</span>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Raise Ticket Modal -->
    <div id="ticketModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden transition-all duration-300">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4 overflow-hidden transform transition-all duration-300 scale-95 opacity-0" id="modalContainer">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-5">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-bold flex items-center">
                        <i class="fas fa-ticket-alt mr-2.5"></i>
                        Raise Support Ticket
                    </h2>
                    <button onclick="closeModal()" class="text-white hover:text-gray-200 text-2xl focus:outline-none">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <!-- Modal Form -->
            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Reason / Category *</label>
                    <select name="reason" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        <option value="">-- Select Category --</option>
                        <option value="Fee Related">Fee Related</option>
                        <option value="Batch/Schedule Related">Batch/Schedule Related</option>
                        <option value="Exam/Test Related">Exam/Test Related</option>
                        <option value="App/Technical Issue">App/Technical Issue</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Associated Batch (Optional)</label>
                    <select name="batch_id" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        <option value="">-- General / No Specific Batch --</option>
                        <?php foreach ($student_batches as $batch): ?>
                            <option value="<?= htmlspecialchars($batch['batch_id']) ?>"><?= htmlspecialchars($batch['batch_name']) ?> (<?= htmlspecialchars($batch['batch_id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Description (Optional)</label>
                    <textarea name="description" rows="4" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="Provide detailed explanation of the issue..."></textarea>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Attachment (Optional)</label>
                    <div class="relative border-2 border-dashed border-gray-300 rounded-xl p-5 text-center hover:border-blue-500 transition-all group cursor-pointer">
                        <input type="file" name="attachment" id="attachment" accept="image/*,.pdf" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                        <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 group-hover:text-blue-500 transition-colors"></i>
                        <p class="mt-1.5 text-sm text-gray-600">Click or drag files to upload</p>
                        <p class="text-xs text-gray-400">PDF, JPG, PNG (Max 5MB)</p>
                        <div id="file_name_display" class="mt-2 text-xs font-semibold text-green-600 hidden"></div>
                    </div>
                </div>

                <div class="flex space-x-3 pt-3">
                    <button type="button" onclick="closeModal()" class="w-1/2 px-4 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-semibold transition-all">
                        Cancel
                    </button>
                    <button type="submit" name="raise_ticket" class="w-1/2 px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-semibold shadow-md transition-all">
                        Submit Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal helpers
        function openModal() {
            const modal = document.getElementById('ticketModal');
            const container = document.getElementById('modalContainer');
            modal.classList.remove('hidden');
            setTimeout(() => {
                container.classList.remove('scale-95', 'opacity-0');
                container.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function closeModal() {
            const modal = document.getElementById('ticketModal');
            const container = document.getElementById('modalContainer');
            container.classList.remove('scale-100', 'opacity-100');
            container.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        // File upload helper
        const fileInput = document.getElementById('attachment');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                const display = document.getElementById('file_name_display');
                if (this.files && this.files[0]) {
                    display.textContent = 'Selected File: ' + this.files[0].name;
                    display.classList.remove('hidden');
                } else {
                    display.classList.add('hidden');
                }
            });
        }

        // Toggle mobile menu
        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        }
    </script>
</body>
</html>
