<?php
include '../db_connection.php';
session_start();

// Verify admin login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}

$admin_id = $_SESSION['user_id'];

// Handle AJAX Status Update from Kanban Drop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_status'])) {
    header('Content-Type: application/json');
    $ticket_id = intval($_POST['ticket_id']);
    $new_status = trim($_POST['status']); // 'open' or 'resolved'
    
    if ($new_status === 'resolved') {
        try {
            $stmt = $db->prepare("UPDATE tickets SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE id = ?");
            $stmt->execute([$admin_id, $ticket_id]);
            echo json_encode(['success' => true, 'message' => 'Ticket resolved successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
    } else {
        try {
            $stmt = $db->prepare("UPDATE tickets SET status = 'open', resolved_at = NULL, resolved_by = NULL WHERE id = ?");
            $stmt->execute([$ticket_id]);
            echo json_encode(['success' => true, 'message' => 'Ticket status updated to open.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
    }
    exit();
}

// Handle Assign to Trainer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_trainer'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $trainer_uid = intval($_POST['trainer_user_id']);
    try {
        $stmt = $db->prepare("UPDATE tickets SET assigned_to_trainer_id = ? WHERE id = ?");
        $stmt->execute([$trainer_uid ?: null, $ticket_id]);
        // Notify trainer
        if ($trainer_uid) {
            $t_stmt = $db->prepare("SELECT reason FROM tickets WHERE id = ?");
            $t_stmt->execute([$ticket_id]);
            $t_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
            $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) VALUES (?, 'ticket', ?, ?, ?, 0)");
            $notif_stmt->execute([$trainer_uid, 'Student Ticket Assigned to You', 'Admin assigned a student ticket regarding \'' . ($t_row['reason'] ?? '') . '\' to you.', $ticket_id]);
        }
        $_SESSION['ticket_message'] = ['type' => 'success', 'text' => $trainer_uid ? 'Ticket assigned to trainer.' : 'Trainer assignment removed.'];
    } catch (PDOException $e) {
        $_SESSION['ticket_message'] = ['type' => 'error', 'text' => 'DB error: ' . $e->getMessage()];
    }
    header("Location: tickets.php");
    exit();
}

// Handle Bulk Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = trim($_POST['bulk_action_type'] ?? ''); // 'close' or 'priority'
    $ticket_ids_str = trim($_POST['ticket_ids'] ?? '');
    $ticket_ids = array_filter(array_map('intval', explode(',', $ticket_ids_str)));

    if (!empty($ticket_ids)) {
        if ($action === 'close') {
            try {
                $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
                $stmt = $db->prepare("UPDATE tickets SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE id IN ($placeholders)");
                $stmt->execute(array_merge([$admin_id], $ticket_ids));
                $_SESSION['ticket_message'] = ['type' => 'success', 'text' => count($ticket_ids) . ' tickets closed successfully.'];
            } catch (PDOException $e) {
                $_SESSION['ticket_message'] = ['type' => 'error', 'text' => 'DB error: ' . $e->getMessage()];
            }
        }
    }
    header("Location: tickets.php");
    exit();
}



// Handle Respond submission (adds chat message, ticket remains open)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_ticket'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $admin_response = trim($_POST['admin_response'] ?? '');
    
    if (empty($admin_response)) {
        $_SESSION['ticket_message'] = [
            'type' => 'error',
            'text' => 'Response cannot be empty.'
        ];
    } else {
        try {
            $db->beginTransaction();
            
            // Get ticket details to retrieve student info
            $ticket_stmt = $db->prepare("SELECT * FROM tickets WHERE id = ? AND status = 'open'");
            $ticket_stmt->execute([$ticket_id]);
            $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ticket) {
                // Insert message into ticket_messages
                $insert_stmt = $db->prepare("
                    INSERT INTO ticket_messages (ticket_id, sender_id, message)
                    VALUES (:ticket_id, :sender_id, :message)
                ");
                $insert_stmt->execute([
                    ':ticket_id' => $ticket_id,
                    ':sender_id' => $admin_id,
                    ':message' => $admin_response
                ]);
                
                // Get student's user ID from students table
                $student_stmt = $db->prepare("SELECT user_id FROM students WHERE student_id = ?");
                $student_stmt->execute([$ticket['student_id']]);
                $student_user = $student_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student_user) {
                    $student_user_id = $student_user['user_id'];
                    $notif_title = "New Message on Support Ticket";
                    $notif_message = "Admin responded to your support ticket regarding '" . $ticket['reason'] . "'. Click to reply.";
                    
                    // Insert notification
                    $notif_stmt = $db->prepare("
                        INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) 
                        VALUES (:user_id, 'ticket', :title, :message, :reference_id, 0)
                    ");
                    $notif_stmt->execute([
                        ':user_id' => $student_user_id,
                        ':title' => $notif_title,
                        ':message' => $notif_message,
                        ':reference_id' => $ticket_id
                    ]);
                }
                
                $db->commit();
                $_SESSION['ticket_message'] = [
                    'type' => 'success',
                    'text' => "Message sent to student successfully!"
                ];
            } else {
                $db->rollBack();
                $_SESSION['ticket_message'] = [
                    'type' => 'error',
                    'text' => 'Ticket not found or already closed.'
                ];
            }
        } catch (PDOException $e) {
            $db->rollBack();
            $_SESSION['ticket_message'] = [
                'type' => 'error',
                'text' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    header("Location: tickets.php");
    exit();
}

// Handle direct close submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    try {
        $db->beginTransaction();
        
        // Get ticket details to retrieve student info
        $ticket_stmt = $db->prepare("SELECT * FROM tickets WHERE id = ? AND status = 'open'");
        $ticket_stmt->execute([$ticket_id]);
        $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ticket) {
            $update_stmt = $db->prepare("
                UPDATE tickets 
                SET status = 'resolved', 
                    admin_response = 'Closed by Admin', 
                    resolved_at = NOW(), 
                    resolved_by = :resolved_by 
                WHERE id = :ticket_id
            ");
            $update_stmt->execute([
                ':resolved_by' => $admin_id,
                ':ticket_id' => $ticket_id
            ]);
            
            // Get student's user ID from students table
            $student_stmt = $db->prepare("SELECT user_id FROM students WHERE student_id = ?");
            $student_stmt->execute([$ticket['student_id']]);
            $student_user = $student_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student_user) {
                $student_user_id = $student_user['user_id'];
                $notif_title = "Support Ticket Closed";
                $notif_message = "Your support ticket regarding '" . $ticket['reason'] . "' has been closed by the admin.";
                
                // Insert notification
                $notif_stmt = $db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) 
                    VALUES (:user_id, 'ticket', :title, :message, :reference_id, 0)
                ");
                $notif_stmt->execute([
                    ':user_id' => $student_user_id,
                    ':title' => $notif_title,
                    ':message' => $notif_message,
                    ':reference_id' => $ticket_id
                ]);
            }
            
            $db->commit();
            $_SESSION['ticket_message'] = [
                'type' => 'success',
                'text' => "Ticket #$ticket_id closed successfully!"
            ];
        } else {
            $db->rollBack();
            $_SESSION['ticket_message'] = [
                'type' => 'error',
                'text' => 'Ticket not found or already closed.'
            ];
        }
    } catch (PDOException $e) {
        $db->rollBack();
        $_SESSION['ticket_message'] = [
            'type' => 'error',
            'text' => 'Database error: ' . $e->getMessage()
        ];
    }
    
    header("Location: tickets.php");
    exit();
}

// Helper function: Priority Badge
function get_priority_badge($priority) {
    return '';
}

// Helper function: Ticket Age Badge
function get_ticket_age_badge($created_at) {
    $created = new DateTime($created_at);
    $now = new DateTime();
    $diff = $now->diff($created);
    
    // Total hours difference
    $total_hours = ($diff->days * 24) + $diff->h;
    
    if ($diff->days == 0) {
        if ($diff->h == 0) {
            $age_str = $diff->i . " min ago";
        } else {
            $age_str = $diff->h . " " . ($diff->h == 1 ? "hr" : "hrs") . " ago";
        }
    } else {
        $age_str = $diff->days . " " . ($diff->days == 1 ? "day" : "days") . " old";
    }
    
    if ($total_hours < 24) {
        $bg_color = "bg-green-100 text-green-800 border-green-200";
    } elseif ($total_hours <= 72) {
        $bg_color = "bg-yellow-100 text-yellow-800 border-yellow-200";
    } else {
        $bg_color = "bg-red-100 text-red-800 border-red-200";
    }
    
    return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border ' . $bg_color . '">⏱️ ' . htmlspecialchars($age_str) . '</span>';
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


// Fetch search and filter parameters
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');
$category_filter = trim($_GET['category'] ?? 'all');
$batch_filter = trim($_GET['batch'] ?? 'all');
$priority_filter = 'all';
$sort_by = trim($_GET['sort'] ?? 'date');

// Fetch all batches for filtering
$batches_stmt = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name ASC");
$batches_list = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch trainers for assign dropdown
$trainers_stmt = $db->query("SELECT u.id, u.name FROM trainers t JOIN users u ON t.user_id = u.id ORDER BY u.name ASC");
$trainers_list = $trainers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build SQL query
$sql = "
    SELECT t.*, s.first_name, s.last_name, s.email as student_email, u.name as admin_name, b.batch_name,
           tr.name as assigned_trainer_name
    FROM tickets t
    JOIN students s ON t.student_id = s.student_id
    LEFT JOIN users u ON t.resolved_by = u.id
    LEFT JOIN batches b ON t.batch_id = b.batch_id
    LEFT JOIN users tr ON t.assigned_to_trainer_id = tr.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (t.student_id LIKE :search1 
                   OR s.first_name LIKE :search2 
                   OR s.last_name LIKE :search3 
                   OR t.reason LIKE :search4)";
    $search_param = '%' . $search . '%';
    $params[':search1'] = $search_param;
    $params[':search2'] = $search_param;
    $params[':search3'] = $search_param;
    $params[':search4'] = $search_param;
}

if ($status_filter !== 'all') {
    $sql .= " AND t.status = :status";
    $params[':status'] = $status_filter;
}

if ($category_filter !== 'all') {
    $sql .= " AND t.reason = :category";
    $params[':category'] = $category_filter;
}

if ($batch_filter !== 'all') {
    if ($batch_filter === 'none') {
        $sql .= " AND t.batch_id IS NULL";
    } else {
        $sql .= " AND t.batch_id = :batch_id";
        $params[':batch_id'] = $batch_filter;
    }
}

$sql .= " ORDER BY t.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- KPI STATS CALCULATION ---
$total_count = count($tickets ?? []);
$open_count = 0;
$resolved_count = 0;
$high_priority_count = 0;

foreach (($tickets ?? []) as $t) {
    $st = strtolower(trim($t['status'] ?? 'open'));
    if ($st === 'resolved') $resolved_count++; else $open_count++;

    $p = strtolower(trim($t['priority'] ?? 'low'));
    if (in_array($p, ['high', 'urgent'], true)) $high_priority_count++;
}

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

// Check messages
$ticket_message = $_SESSION['ticket_message'] ?? null;
unset($_SESSION['ticket_message']);

include '../header.php';
include '../sidebar.php';
?>

<style>
/* ---------- Color Theme ---------- */
:root {
    --color-primary: #1B3C53;
    --color-secondary: #234C6A;
    --color-accent: #456882;
    --color-light: #D2C1B6;
    --color-white: #ffffff;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

/* Ticket Row Hover Animation */
.ticket-row {
    position: relative;
    border-left: 3px solid transparent;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.ticket-row:hover {
    transform: translateY(-1px);
    border-left-color: #456882;
    box-shadow: 0 6px 20px -5px rgba(27, 60, 83, 0.12), 0 2px 8px -2px rgba(0, 0, 0, 0.04);
    background: linear-gradient(135deg, rgba(210,193,182,0.08), rgba(69,104,130,0.06)) !important;
}

/* Smooth Button Hover */
.btn-hover {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px -3px rgba(59, 130, 246, 0.4);
}
.btn-red-hover {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-red-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px -3px rgba(239, 68, 68, 0.4);
}
.btn-purple-hover {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-purple-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px -3px rgba(147, 51, 234, 0.4);
}

/* Glassmorphism Filter Section */
.glass-filter {
    background: rgba(255, 255, 255, 0.8) !important;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3) !important;
    box-shadow: 0 4px 30px rgba(0, 0, 0, 0.06);
}

/* Ticket ID Capsule */
.ticket-capsule {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 13px;
    color: #475569;
    border: 1px solid #cbd5e1;
}

/* Better Search Input */
.search-enhanced {
    height: 48px;
    border-radius: 14px !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1.5px solid #e2e8f0 !important;
    transition: all 0.3s ease;
    font-size: 14px;
}
.search-enhanced:focus {
    box-shadow: 0 4px 20px rgba(59, 130, 246, 0.15);
    border-color: #93c5fd !important;
}

/* Trainer Badge Enhanced */
.trainer-badge-enhanced {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    background: linear-gradient(135deg, #f3e8ff, #ede9fe);
    color: #7c3aed;
    font-weight: 600;
    font-size: 11px;
    border: 1px solid #ddd6fe;
}

/* Online Indicator */
.online-dot {
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    display: inline-block;
    animation: pulse-dot 2s infinite;
}
@keyframes pulse-dot {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
    50% { opacity: 0.8; box-shadow: 0 0 0 4px rgba(34, 197, 94, 0); }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
    animation: fadeIn 0.5s ease-out forwards;
}

/* Frosted glassmorphism camera depth of field blur classes */
.portrait-blur-backdrop {
    background: rgba(15, 23, 42, 0.08) !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
}
.portrait-blur-panel {
    background: rgba(255, 253, 248, 0.85) !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
    border-left: 1px solid rgba(0, 0, 0, 0.08) !important;
    color: #1e293b !important;
}
/* Apply light text styles only to the scrollable content area, NOT the panel header */
.portrait-blur-panel .panel-body h3, 
.portrait-blur-panel .panel-body h5, 
.portrait-blur-panel .panel-body span, 
.portrait-blur-panel .panel-body p, 
.portrait-blur-panel .panel-body div {
    color: #1e293b;
}
.portrait-blur-panel .panel-body strong,
.portrait-blur-panel .panel-body label,
.portrait-blur-panel .panel-body .text-slate-400,
.portrait-blur-panel .panel-body .text-gray-400,
.portrait-blur-panel .panel-body .text-gray-500 {
    color: #475569 !important;
}
.portrait-blur-panel .panel-body select,
.portrait-blur-panel .panel-body input[type="text"],
.portrait-blur-panel .panel-body textarea {
    background-color: #ffffff !important;
    color: #1e293b !important;
    border: 1px solid #cbd5e1 !important;
}
.portrait-blur-panel .panel-body select option {
    background-color: #ffffff !important;
    color: #1e293b !important;
}
.portrait-blur-panel .panel-body .bg-slate-950\/40 {
    background-color: rgba(241, 245, 249, 0.6) !important;
}
.portrait-blur-panel .panel-body .bg-blue-950\/40 {
    background-color: rgba(239, 246, 255, 0.8) !important;
}
.portrait-blur-panel .panel-body .bg-purple-950\/40 {
    background-color: rgba(250, 245, 255, 0.8) !important;
}
.portrait-blur-panel .panel-body .bg-emerald-950\/40 {
    background-color: rgba(236, 253, 245, 0.8) !important;
}
.portrait-blur-panel .panel-body .text-slate-100,
.portrait-blur-panel .panel-body .text-slate-200,
.portrait-blur-panel .panel-body .text-slate-300 {
    color: #334155 !important;
}
.portrait-blur-panel .panel-body button,
.portrait-blur-panel .panel-body button[type="submit"] {
    color: #ffffff !important;
}
.portrait-blur-panel .panel-body button[name="update_priority"] {
    background-color: #d97706 !important;
}
.portrait-blur-panel .panel-body button[name="assign_trainer"] {
    background-color: #7c3aed !important;
}
.portrait-blur-panel .panel-body button[name="resolve_ticket"] {
    background-color: #2563eb !important;
}
.portrait-blur-panel .panel-body button[name="close_ticket"] {
    background-color: #ef4444 !important;
}
/* Panel header always stays white/dark themed */
.panel-header {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 60%, #2d5f82 100%) !important;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}
.panel-header h3,
.panel-header span,
.panel-header p,
.panel-header button,
.panel-header i {
    color: #ffffff !important;
}
.panel-header .status-badge-resolved {
    background: rgba(16,185,129,0.25) !important;
    color: #6ee7b7 !important;
    border: 1px solid rgba(16,185,129,0.4) !important;
}
.panel-header .status-badge-open {
    background: rgba(59,130,246,0.25) !important;
    color: #93c5fd !important;
    border: 1px solid rgba(59,130,246,0.4) !important;
}
.panel-header .close-btn {
    color: rgba(255,255,255,0.6) !important;
}
.panel-header .close-btn:hover {
    color: #ffffff !important;
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

/* Kanban Styling */
.kanban-board {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.5rem;
    margin-top: 1.5rem;
    align-items: start;
}
@media (max-width: 1024px) {
    .kanban-board {
        grid-template-columns: 1fr;
    }
}
/* ── Kanban Board ── */
.kanban-board {
    align-items: start;
}
.kanban-column {
    border-radius: 1.1rem;
    padding: 0;
    display: flex;
    flex-direction: column;
    min-height: 500px;
    transition: all 0.25s ease;
    overflow: hidden;
    border: none;
    box-shadow: 0 2px 16px rgba(0,0,0,0.07);
}

/* ── OPEN column — blue tone ── */
#column-open {
    background: linear-gradient(160deg, #dbeafe 0%, #eff6ff 60%, #f0f9ff 100%);
    border-top: 4px solid #3b82f6;
}
#column-open .kanban-column-header {
    background: linear-gradient(90deg, #1d4ed8, #3b82f6);
    color: #fff;
    border-radius: 0;
    padding: 0.9rem 1.1rem;
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-bottom: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    text-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
#column-open .column-count {
    background: rgba(255,255,255,0.25) !important;
    color: #fff !important;
    font-weight: 800;
    border: 1px solid rgba(255,255,255,0.4);
}
#column-open .kanban-cards {
    padding: 1rem;
}
#column-open .kanban-card {
    background: #fff;
    border: 1.5px solid #bfdbfe;
    border-left: 4px solid #3b82f6;
    border-radius: 0.75rem;
    box-shadow: 0 2px 8px rgba(59,130,246,0.1);
}
#column-open .kanban-card:hover {
    border-left-color: #1d4ed8;
    box-shadow: 0 6px 20px rgba(59,130,246,0.2);
    transform: translateY(-2px);
}
#column-open.dragover {
    background: linear-gradient(160deg, #bfdbfe 0%, #dbeafe 100%);
    box-shadow: inset 0 0 0 2px #3b82f6;
}

/* ── IN PROGRESS column — amber/orange tone ── */
#column-in-progress {
    background: linear-gradient(160deg, #fef3c7 0%, #fffbeb 60%, #fff7ed 100%);
    border-top: 4px solid #f59e0b;
}
#column-in-progress .kanban-column-header {
    background: linear-gradient(90deg, #b45309, #f59e0b);
    color: #fff;
    border-radius: 0;
    padding: 0.9rem 1.1rem;
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-bottom: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    text-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
#column-in-progress .column-count {
    background: rgba(255,255,255,0.25) !important;
    color: #fff !important;
    font-weight: 800;
    border: 1px solid rgba(255,255,255,0.4);
}
#column-in-progress .kanban-cards {
    padding: 1rem;
}
#column-in-progress .kanban-card {
    background: #fff;
    border: 1.5px solid #fde68a;
    border-left: 4px solid #f59e0b;
    border-radius: 0.75rem;
    box-shadow: 0 2px 8px rgba(245,158,11,0.1);
}
#column-in-progress .kanban-card:hover {
    border-left-color: #b45309;
    box-shadow: 0 6px 20px rgba(245,158,11,0.22);
    transform: translateY(-2px);
}
#column-in-progress.dragover {
    background: linear-gradient(160deg, #fde68a 0%, #fef3c7 100%);
    box-shadow: inset 0 0 0 2px #f59e0b;
}

/* ── RESOLVED column — green tone ── */
#column-resolved {
    background: linear-gradient(160deg, #d1fae5 0%, #ecfdf5 60%, #f0fdf4 100%);
    border-top: 4px solid #10b981;
}
#column-resolved .kanban-column-header {
    background: linear-gradient(90deg, #065f46, #10b981);
    color: #fff;
    border-radius: 0;
    padding: 0.9rem 1.1rem;
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-bottom: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    text-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
#column-resolved .column-count {
    background: rgba(255,255,255,0.25) !important;
    color: #fff !important;
    font-weight: 800;
    border: 1px solid rgba(255,255,255,0.4);
}
#column-resolved .kanban-cards {
    padding: 1rem;
}
#column-resolved .kanban-card {
    background: #fff;
    border: 1.5px solid #a7f3d0;
    border-left: 4px solid #10b981;
    border-radius: 0.75rem;
    box-shadow: 0 2px 8px rgba(16,185,129,0.1);
}
#column-resolved .kanban-card:hover {
    border-left-color: #065f46;
    box-shadow: 0 6px 20px rgba(16,185,129,0.22);
    transform: translateY(-2px);
}
#column-resolved.dragover {
    background: linear-gradient(160deg, #a7f3d0 0%, #d1fae5 100%);
    box-shadow: inset 0 0 0 2px #10b981;
}

/* Shared card base */
.kanban-card {
    padding: 1rem;
    cursor: grab;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    user-select: none;
}
.kanban-card:active { cursor: grabbing; }
.kanban-card.dragging { opacity: 0.4; transform: scale(0.97); }

.kanban-cards {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    flex-grow: 1;
}

/* Enhanced Table Header */
.table thead tr {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 60%, #456882 100%) !important;
    border-bottom: none;
}
.table thead th {
    color: rgba(210,193,182,0.95) !important;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.06em;
    font-size: 0.72rem;
    padding: 0.9rem 1.5rem;
}
.table thead th:first-child { border-radius: 0; }
.table thead th:last-child { border-radius: 0; }

/* ---------- NEW ENHANCED STYLES ---------- */

/* Side Panel Responsive */
.ticket-side-panel-overlay {
    position: fixed !important;
    inset: 0 !important;
    z-index: 1000 !important;
}
.side-panel-inner {
    position: absolute !important;
    right: 0 !important;
    top: 0 !important;
    bottom: 0 !important;
    height: 100% !important;
    width: 100% !important;
    max-width: 520px;
}
@media (max-width: 640px) {
    .side-panel-inner {
        max-width: 100% !important;
    }
    .side-panel-inner .grid-cols-2 {
        grid-template-columns: 1fr !important;
    }
}
@media (min-width: 641px) and (max-width: 1024px) {
    .side-panel-inner {
        max-width: 420px;
    }
}
.chat-messages-area {
    flex: 1 1 auto;
    min-height: 160px;
}
/* Make the panel content area use proper flex so chat fills available height */
.side-panel-inner > div.p-6 {
    display: flex;
    flex-direction: column;
}
.side-panel-inner > div.p-6 > .border-t.border-gray-150 {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}


/* Dark Header Banner */
.banner-hero {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 50%, #456882 100%);
    padding: 1.75rem 2rem;
    border-radius: 1.25rem;
    margin-bottom: 1.5rem;
    color: white;
    box-shadow: 0 10px 25px -5px rgba(27, 60, 83, 0.3);
    position: relative;
    overflow: hidden;
}
.banner-hero::after {
    content: '';
    position: absolute;
    top: -30%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.banner-hero .banner-content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
}
.banner-hero .banner-left h1 {
    font-size: 1.75rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0;
}
.banner-hero .banner-left h1 i {
    font-size: 2rem;
    opacity: 0.9;
}
.banner-hero .banner-left p {
    margin: 0.25rem 0 0;
    opacity: 0.85;
    font-size: 0.95rem;
}
.banner-hero .banner-stats {
    display: flex;
    gap: 2rem;
    background: rgba(255,255,255,0.12);
    backdrop-filter: blur(8px);
    padding: 0.6rem 1.5rem;
    border-radius: 0.75rem;
    border: 1px solid rgba(255,255,255,0.15);
}
.banner-hero .banner-stats .stat-item {
    text-align: center;
}
.banner-hero .banner-stats .stat-item .stat-number {
    font-size: 1.25rem;
    font-weight: 700;
    display: block;
    line-height: 1.2;
}
.banner-hero .banner-stats .stat-item .stat-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.7;
}

/* KPI Cards Enhanced */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.kpi-card {
    background: white;
    border-radius: 1rem;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 2px 12px rgba(27,60,83,0.10);
    border: 1px solid rgba(69,104,130,0.13);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 1rem;
    cursor: default;
    position: relative;
    overflow: hidden;
}
.kpi-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(27,60,83,0.03), rgba(69,104,130,0.05));
    opacity: 0;
    transition: opacity 0.3s ease;
    border-radius: inherit;
}
.kpi-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 30px rgba(27,60,83,0.18);
    border-color: var(--color-accent);
}
.kpi-card:hover::before { opacity: 1; }
.kpi-card .kpi-icon {
    width: 52px;
    height: 52px;
    border-radius: 0.875rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    color: white;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
    transition: transform 0.3s ease;
}
.kpi-card:hover .kpi-icon { transform: scale(1.08) rotate(-3deg); }
.kpi-card .kpi-content { flex: 1; position: relative; z-index: 1; }
.kpi-card .kpi-content .kpi-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #64748b;
    font-weight: 700;
}
.kpi-card .kpi-content .kpi-number {
    font-size: 1.65rem;
    font-weight: 800;
    color: #1B3C53;
    line-height: 1.1;
}
.kpi-card:hover .kpi-content .kpi-number { color: var(--color-secondary); }
.kpi-card .kpi-content .kpi-sub {
    font-size: 0.68rem;
    color: #94a3b8;
    margin-top: 1px;
}
/* KPI color variants */
.kpi-total .kpi-icon { background: linear-gradient(135deg, #1B3C53, #456882); }
.kpi-open .kpi-icon  { background: linear-gradient(135deg, #d97706, #f59e0b); }
.kpi-resolved .kpi-icon { background: linear-gradient(135deg, #059669, #10b981); }
.kpi-high .kpi-icon  { background: linear-gradient(135deg, #dc2626, #ef4444); }

/* KPI right glow accent on hover */
.kpi-total:hover  { border-color: #456882; box-shadow: 0 12px 30px rgba(27,60,83,0.2), 4px 0 0 0 #1B3C53 inset; }
.kpi-open:hover   { border-color: #f59e0b; box-shadow: 0 12px 30px rgba(245,158,11,0.18), 4px 0 0 0 #f59e0b inset; }
.kpi-resolved:hover { border-color: #10b981; box-shadow: 0 12px 30px rgba(16,185,129,0.18), 4px 0 0 0 #10b981 inset; }
.kpi-high:hover   { border-color: #ef4444; box-shadow: 0 12px 30px rgba(239,68,68,0.18), 4px 0 0 0 #ef4444 inset; }

/* Filter Card Enhanced */
.filter-card {
    background: white;
    border-radius: 1rem;
    padding: 1.1rem 1.5rem;
    box-shadow: 0 2px 12px rgba(27,60,83,0.08);
    border: 1px solid rgba(69,104,130,0.12);
    margin-bottom: 1.25rem;
}
.filter-card .filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: flex-end;
}
.filter-card .filter-form .filter-group {
    flex: 1 1 160px;
    min-width: 140px;
}
.filter-card .filter-form .filter-group label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    color: #1B3C53;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 0.3rem;
}
.filter-card .filter-form .filter-group input,
.filter-card .filter-form .filter-group select {
    width: 100%;
    padding: 0.55rem 0.85rem;
    border: 1.5px solid #d1d9e0;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    background: #f8fafc;
    transition: all 0.25s ease;
    color: #1e293b;
}
/* Highlighted search input */
#search {
    border-color: rgba(69,104,130,0.4) !important;
    background: linear-gradient(135deg, #f0f5f9, #f8fafc) !important;
    box-shadow: 0 0 0 0 rgba(69,104,130,0);
    transition: all 0.3s ease !important;
}
#search:focus {
    border-color: #234C6A !important;
    background: white !important;
    box-shadow: 0 0 0 3px rgba(35,76,106,0.14), 0 4px 18px rgba(27,60,83,0.1) !important;
    outline: none;
}
.filter-card .filter-form .filter-group input:focus,
.filter-card .filter-form .filter-group select:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(69, 104, 130, 0.12);
    background: white;
}
.filter-card .filter-form .filter-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.filter-card .filter-form .filter-actions button,
.filter-card .filter-form .filter-actions a {
    padding: 0.55rem 1.25rem;
    border-radius: 0.75rem;
    font-weight: 700;
    font-size: 0.875rem;
    border: none;
    cursor: pointer;
    transition: all 0.25s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}
.filter-card .filter-form .filter-actions .btn-primary {
    background: linear-gradient(135deg, #1B3C53, #234C6A);
    color: white;
    box-shadow: 0 2px 10px rgba(27,60,83,0.25);
}
.filter-card .filter-form .filter-actions .btn-primary:hover {
    background: linear-gradient(135deg, #234C6A, #456882);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(27,60,83,0.3);
}
.filter-card .filter-form .filter-actions .btn-clear {
    background: rgba(210,193,182,0.3);
    color: #475569;
    border: 1px solid rgba(210,193,182,0.5);
}
.filter-card .filter-form .filter-actions .btn-clear:hover {
    background: rgba(210,193,182,0.5);
}

/* Table Header Enhancements */
.tickets-table-wrapper .table-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.75rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.tickets-table-wrapper .table-header .left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.tickets-table-wrapper .table-header .left .bulk-bar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: #D2C1B6;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.8rem;
    color: #1B3C53;
    font-weight: 600;
}
.tickets-table-wrapper .table-header .left .bulk-bar.hidden {
    display: none;
}
.tickets-table-wrapper .table-header .view-toggle {
    display: flex;
    gap: 0.25rem;
    background: #f1f5f9;
    padding: 0.25rem;
    border-radius: 0.75rem;
}
.tickets-table-wrapper .table-header .view-toggle button {
    padding: 0.3rem 0.9rem;
    border-radius: 0.5rem;
    border: none;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    background: transparent;
    color: #64748b;
}
.tickets-table-wrapper .table-header .view-toggle button.active {
    background: white;
    color: var(--color-primary);
    box-shadow: var(--shadow-sm);
}
.tickets-table-wrapper .table-header .view-toggle button:hover:not(.active) {
    background: #e2e8f0;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .banner-hero {
        padding: 1.25rem;
        border-radius: 1rem;
    }
    .banner-hero .banner-content {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }
    .banner-hero .banner-stats {
        width: 100%;
        justify-content: space-around;
        padding: 0.5rem;
        gap: 0.5rem;
    }
    .banner-hero .banner-stats .stat-item .stat-number {
        font-size: 1rem;
    }
    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .filter-card .filter-form .filter-group {
        flex: 1 1 100%;
    }
    .filter-card .filter-form .filter-actions {
        flex: 1 1 100%;
        justify-content: flex-end;
    }
    .tickets-table-wrapper .table-header {
        flex-direction: column;
        align-items: stretch;
        gap: 0.5rem;
    }
}
@media (max-width: 480px) {
    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .banner-hero h1 {
        font-size: 1.25rem;
    }
    /* Table: hide less-critical columns on very small screens */
    .table thead th:nth-child(5),
    .table tbody td:nth-child(5) {
        display: none;
    }
    .ticket-row td {
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }
    .ticket-capsule {
        padding: 3px 8px;
        font-size: 11px;
    }
    /* Action buttons stack */
    .table .flex.flex-col.items-end {
        align-items: stretch;
    }
    .table .flex.flex-col.items-end button,
    .table .flex.flex-col.items-end form button {
        width: 100%;
        justify-content: center;
    }
}
@media (max-width: 640px) {
    /* View toggle: shorter labels */
    #view-toggle-table span,
    #view-toggle-kanban span {
        display: none;
    }
    #view-toggle-table i,
    #view-toggle-kanban i {
        display: inline-block;
    }
    .banner-hero .banner-stats .stat-item .stat-number {
        font-size: 0.9rem;
    }
}

</style>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out" style="background: #eef0f3; min-width: 0;">

    <!-- ═══════════════════════════════════════════════════════════
         ENHANCED HEADER BANNER (KPIs REMOVED)
    ═══════════════════════════════════════════════════════════ -->
    <header class="sticky top-0 z-30" id="main-header">
        <div class="header-banner">
            <!-- Decorative geometric shapes -->
            <div class="header-orb header-orb-1"></div>
            <div class="header-orb header-orb-2"></div>
            <div class="header-orb header-orb-3"></div>
            <div class="header-grid-lines"></div>

            <div class="header-inner">

                <!-- ── ROW 1: breadcrumb + clock ── -->
                <div class="header-topbar">
                    <div class="header-breadcrumb">
                        <button class="md:hidden flex items-center justify-center w-9 h-9 rounded-xl mr-3 focus:outline-none transition-all active:scale-95" 
                                style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2);"
                                onclick="toggleSidebar()" 
                                aria-label="Open navigation menu">
                            <i class="fas fa-bars text-white text-base"></i>
                        </button>
                        <i class="fas fa-home text-[10px]" style="color:rgba(210,193,182,0.55);"></i>
                        <span class="header-crumb-sep">/</span>
                        <span class="header-crumb-item">Dashboard</span>
                        <span class="header-crumb-sep">/</span>
                        <span class="header-crumb-item header-crumb-active">Support Tickets</span>
                    </div>
                    <div class="header-clock" id="header-clock">
                        <i class="far fa-clock" style="color:rgba(210,193,182,0.6); font-size:11px;"></i>
                        <span id="clock-time" style="color:rgba(210,193,182,0.8); font-size:11px; font-weight:600; letter-spacing:0.04em;"></span>
                    </div>
                </div>

                <!-- ── ROW 2: main content ── -->
                <div class="header-main-row">

                    <!-- Left: icon + title + subtitle + status pills -->
                    <div class="header-left">
                        <div class="header-icon-wrap">
                            <i class="fas fa-ticket-alt"></i>
                            <span class="header-icon-ping"></span>
                        </div>
                        <div class="header-title-block">
                            <h1 class="header-title">Support Tickets</h1>
                            <p class="header-subtitle">Student Support Management &mdash; Real-time overview</p>
                            <!-- Quick status pills -->
                            <div class="header-status-pills">
                                <span class="hpill hpill-open">
                                    <span class="hpill-dot" style="background:#f59e0b;"></span>
                                    <?= (int)$open_count ?> Open
                                </span>
                                <span class="hpill hpill-resolved">
                                    <span class="hpill-dot" style="background:#10b981;"></span>
                                    <?= (int)$resolved_count ?> Resolved
                                </span>
                                <?php if ($high_priority_count > 0): ?>
                                <span class="hpill hpill-urgent">
                                    <i class="fas fa-exclamation-triangle" style="font-size:9px;"></i>
                                    <?= (int)$high_priority_count ?> Urgent
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right: admin card (stat strip removed) -->
                    <div class="header-right">
                        <!-- Admin profile card -->
                        <div class="header-admin-card">
                            <div class="header-admin-avatar">
                                <i class="fas fa-user-shield text-sm"></i>
                                <span class="header-avatar-badge"></span>
                            </div>
                            <div class="header-admin-info">
                                <span class="header-admin-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
                                <span class="header-admin-role">System Administrator</span>
                                <span class="header-admin-status">
                                    <span class="online-dot" style="width:6px;height:6px;"></span>
                                    Online
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /header-inner -->
        </div><!-- /header-banner -->
    </header>

    <style>
    /* ─── Header Banner ─── */
    .header-banner {
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, #0f2233 0%, #1B3C53 30%, #234C6A 65%, #456882 100%);
        padding: 0 2rem;
        box-shadow: 0 6px 32px rgba(15,34,51,0.45), 0 1px 0 rgba(210,193,182,0.08);
    }
    /* Geometric orbs */
    .header-orb {
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
    }
    .header-orb-1 {
        width: 320px; height: 320px;
        top: -120px; right: -60px;
        background: radial-gradient(circle, rgba(69,104,130,0.35) 0%, transparent 70%);
    }
    .header-orb-2 {
        width: 180px; height: 180px;
        bottom: -80px; left: 35%;
        background: radial-gradient(circle, rgba(210,193,182,0.12) 0%, transparent 70%);
    }
    .header-orb-3 {
        width: 120px; height: 120px;
        top: -20px; left: 20%;
        background: radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 70%);
    }
    /* Subtle dot-grid overlay */
    .header-grid-lines {
        position: absolute;
        inset: 0;
        background-image: radial-gradient(circle, rgba(255,255,255,0.04) 1px, transparent 1px);
        background-size: 28px 28px;
        pointer-events: none;
    }
    .header-inner {
        position: relative;
        z-index: 2;
        max-width: 100%;
    }

    /* ── Top bar (breadcrumb + clock) ── */
    .header-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.6rem 0 0.45rem;
        border-bottom: 1px solid rgba(210,193,182,0.1);
        margin-bottom: 0.6rem;
    }
    .header-breadcrumb {
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }
    .header-crumb-sep {
        color: rgba(210,193,182,0.3);
        font-size: 11px;
        font-weight: 300;
    }
    .header-crumb-item {
        font-size: 11px;
        font-weight: 500;
        color: rgba(210,193,182,0.55);
        letter-spacing: 0.02em;
        cursor: default;
    }
    .header-crumb-active {
        color: rgba(210,193,182,0.9) !important;
        font-weight: 700;
    }
    .header-clock {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    /* ── Main Row ── */
    .header-main-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1.5rem;
        padding-bottom: 1rem;
        flex-wrap: wrap;
    }
    /* Left side */
    .header-left {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
    }
    .header-icon-wrap {
        position: relative;
        width: 52px; height: 52px;
        border-radius: 16px;
        background: linear-gradient(135deg, rgba(210,193,182,0.18), rgba(210,193,182,0.08));
        border: 1.5px solid rgba(210,193,182,0.25);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #D2C1B6;
        font-size: 1.35rem;
        flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.08);
        backdrop-filter: blur(4px);
    }
    .header-icon-ping {
        position: absolute;
        top: -3px; right: -3px;
        width: 10px; height: 10px;
        border-radius: 50%;
        background: #10b981;
        border: 2px solid #1B3C53;
        animation: ping-dot 2.5s ease-in-out infinite;
    }
    @keyframes ping-dot {
        0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.5); }
        50% { box-shadow: 0 0 0 5px rgba(16,185,129,0); }
    }
    .header-title-block { display: flex; flex-direction: column; gap: 0.2rem; }
    .header-title {
        font-size: 1.5rem;
        font-weight: 800;
        color: #ffffff;
        line-height: 1.1;
        letter-spacing: -0.01em;
        margin: 0;
    }
    .header-subtitle {
        font-size: 0.75rem;
        color: rgba(210,193,182,0.65);
        font-weight: 500;
        margin: 0;
        letter-spacing: 0.01em;
    }
    /* Status pills row */
    .header-status-pills {
        display: flex;
        gap: 0.4rem;
        margin-top: 0.4rem;
        flex-wrap: wrap;
    }
    .hpill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: 0.02em;
        backdrop-filter: blur(6px);
    }
    .hpill-dot {
        width: 6px; height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .hpill-open {
        background: rgba(245,158,11,0.15);
        color: #fbbf24;
        border: 1px solid rgba(245,158,11,0.3);
    }
    .hpill-resolved {
        background: rgba(16,185,129,0.15);
        color: #34d399;
        border: 1px solid rgba(16,185,129,0.3);
    }
    .hpill-urgent {
        background: rgba(239,68,68,0.15);
        color: #f87171;
        border: 1px solid rgba(239,68,68,0.3);
        animation: urgent-pulse 2s ease-in-out infinite;
    }
    @keyframes urgent-pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.3); }
        50% { box-shadow: 0 0 0 4px rgba(239,68,68,0); }
    }

    /* Right side */
    .header-right {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        flex-shrink: 0;
    }
    /* Admin card */
    .header-admin-card {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(210,193,182,0.18);
        border-radius: 14px;
        padding: 0.5rem 1rem 0.5rem 0.6rem;
        backdrop-filter: blur(8px);
        transition: background 0.2s ease;
        cursor: default;
    }
    .header-admin-card:hover {
        background: rgba(255,255,255,0.1);
        border-color: rgba(210,193,182,0.3);
    }
    .header-admin-avatar {
        position: relative;
        width: 38px; height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, #456882, #234C6A);
        border: 2px solid rgba(210,193,182,0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        flex-shrink: 0;
        box-shadow: 0 3px 10px rgba(0,0,0,0.25);
    }
    .header-avatar-badge {
        position: absolute;
        bottom: -1px; right: -1px;
        width: 11px; height: 11px;
        background: #22c55e;
        border-radius: 50%;
        border: 2px solid #1B3C53;
    }
    .header-admin-info {
        display: flex;
        flex-direction: column;
        gap: 1px;
    }
    .header-admin-name {
        font-size: 13px;
        font-weight: 700;
        color: #ffffff;
        line-height: 1;
    }
    .header-admin-role {
        font-size: 9.5px;
        font-weight: 600;
        color: rgba(210,193,182,0.65);
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .header-admin-status {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        font-weight: 600;
        color: #6ee7b7;
        margin-top: 1px;
    }

    /* ── Responsive ── */
    @media (max-width: 900px) {
        .header-stat-strip { display: none; } /* already removed, but keep */
        .header-vdivider { display: none; }
    }
    @media (max-width: 640px) {
        .header-banner { padding: 0 1rem; }
        .header-title { font-size: 1.2rem; }
        .header-status-pills { display: none; }
        .header-admin-info { display: none; }
    }
    </style>

    <script>
    // Live clock in header
    (function() {
        function updateClock() {
            const el = document.getElementById('clock-time');
            if (!el) return;
            const now = new Date();
            const opts = { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: 'Asia/Kolkata' };
            el.textContent = new Intl.DateTimeFormat('en-IN', opts).format(now);
        }
        updateClock();
        setInterval(updateClock, 1000);
    })();
    </script>

    <!-- Mobile Floating Sidebar Button (always visible on mobile) -->
    <div class="md:hidden fixed bottom-5 left-5 z-[999]" id="mobile-sidebar-fab">
        <button onclick="toggleSidebar()" 
                class="flex items-center justify-center w-14 h-14 rounded-full shadow-2xl active:scale-95 transition-all"
                style="background: linear-gradient(135deg, #1B3C53, #456882); border: 2px solid rgba(210,193,182,0.3);"
                aria-label="Open sidebar navigation">
            <i class="fas fa-bars text-white text-lg"></i>
        </button>
    </div>

    <!-- Main Content Grid -->
    <div class="p-4 md:p-8">
        <?php if ($ticket_message): ?>
            <div class="mb-6 p-4 rounded-xl shadow-sm flex items-center animate-fade-in
                <?= $ticket_message['type'] === 'success' ? 'bg-green-100 text-green-800 border-l-4 border-green-500' : 'bg-red-100 text-red-800 border-l-4 border-red-500' ?>">
                <i class="fas <?= $ticket_message['type'] === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500' ?> mr-3 text-lg"></i>
                <span class="font-medium"><?= htmlspecialchars($ticket_message['text']) ?></span>
            </div>
        <?php endif; ?>

        <!-- KPI header replaced with enhanced header above -->

        <!-- Enhanced KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card kpi-total">
                <div class="kpi-icon"><i class="fas fa-layer-group"></i></div>
                <div class="kpi-content">
                    <div class="kpi-label">Total Tickets</div>
                    <div class="kpi-number"><?= (int)$total_count ?></div>
                    <div class="kpi-sub">All tickets</div>
                </div>
            </div>
            <div class="kpi-card kpi-open">
                <div class="kpi-icon"><i class="fas fa-inbox"></i></div>
                <div class="kpi-content">
                    <div class="kpi-label">Open</div>
                    <div class="kpi-number"><?= (int)$open_count ?></div>
                    <div class="kpi-sub">Need attention</div>
                </div>
            </div>
            <div class="kpi-card kpi-resolved">
                <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
                <div class="kpi-content">
                    <div class="kpi-label">Resolved</div>
                    <div class="kpi-number"><?= (int)$resolved_count ?></div>
                    <div class="kpi-sub">Completed</div>
                </div>
            </div>
            <div class="kpi-card kpi-high">
                <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="kpi-content">
                    <div class="kpi-label">High Priority</div>
                    <div class="kpi-number"><?= (int)$high_priority_count ?></div>
                    <div class="kpi-sub">Urgent</div>
                </div>
            </div>
        </div>

        <!-- Enhanced Filter Section (with border & shadow) -->
        <div class="filter-card">
            <form method="GET" action="tickets.php" class="filter-form">
                <div class="filter-group" style="position:relative;">
                    <label for="search" style="color:#1B3C53; font-weight:800;"><i class="fas fa-search mr-1" style="color:#456882;"></i> Search</label>
                    <div style="position:relative;">
                        <input type="text" name="search" id="search" value="<?= htmlspecialchars($search) ?>" placeholder="ID, name, category..." style="padding-left:2.25rem;">
                        <i class="fas fa-search" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); color:#456882; font-size:0.8rem; pointer-events:none;"></i>
                    </div>
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="open" <?= $status_filter === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="category">Category</label>
                    <select name="category" id="category">
                        <option value="all" <?= $category_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="Fee Related" <?= $category_filter === 'Fee Related' ? 'selected' : '' ?>>Fee Related</option>
                        <option value="Batch/Schedule Related" <?= $category_filter === 'Batch/Schedule Related' ? 'selected' : '' ?>>Batch/Schedule</option>
                        <option value="Exam/Test Related" <?= $category_filter === 'Exam/Test Related' ? 'selected' : '' ?>>Exam/Test</option>
                        <option value="App/Technical Issue" <?= $category_filter === 'App/Technical Issue' ? 'selected' : '' ?>>App/Technical</option>
                        <option value="Other" <?= $category_filter === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="batch">Batch</label>
                    <select name="batch" id="batch">
                        <option value="all" <?= $batch_filter === 'all' ? 'selected' : '' ?>>All Batches</option>
                        <option value="none" <?= $batch_filter === 'none' ? 'selected' : '' ?>>General / No Batch</option>
                        <?php foreach ($batches_list as $b): ?>
                            <option value="<?= htmlspecialchars($b['batch_id']) ?>" <?= $batch_filter == $b['batch_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['batch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Apply</button>
                    <?php if (!empty($search) || $status_filter !== 'all' || $category_filter !== 'all' || $batch_filter !== 'all'): ?>
                        <a href="tickets.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- View Toggle & Bulk Actions -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 mt-2">
            <div class="flex items-center gap-3">
                <div class="text-sm font-semibold" style="color:#456882;">
                    Found <span class="font-extrabold" style="color:#1B3C53;"><?= count($tickets) ?></span> support tickets
                </div>
                <div id="bulk-action-bar" class="hidden flex items-center gap-2 px-3.5 py-1.5 rounded-xl animate-fade-in transition-all" style="background: rgba(27,60,83,0.08); border: 1px solid rgba(27,60,83,0.18);">
                    <span class="text-xs font-bold" style="color:#1B3C53;"><span id="selected-count">0</span> selected:</span>
                    <button type="button" onclick="triggerBulkClose()" class="btn-hover px-3 py-1.5 text-white text-[11px] font-bold rounded-xl flex items-center gap-1 shadow-sm transition-all" style="background: linear-gradient(135deg,#dc2626,#ef4444);">
                        <i class="fas fa-times-circle"></i> Close Selected
                    </button>
                </div>
            </div>
            <div class="flex items-center p-1 rounded-xl" style="background: rgba(27,60,83,0.08); border: 1px solid rgba(69,104,130,0.2);">
                <button type="button" onclick="switchViewMode('table')" id="view-toggle-table" class="px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all bg-white shadow-sm" style="color:#1B3C53; border: 1px solid rgba(69,104,130,0.2);">
                    <i class="fas fa-table"></i> Table View
                </button>
                <button type="button" onclick="switchViewMode('kanban')" id="view-toggle-kanban" class="px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all" style="color:#456882;">
                    <i class="fas fa-columns"></i> Kanban Board
                </button>
            </div>
        </div>

        <!-- Table View Container -->
        <div id="tickets-table-view">
            <!-- Tickets Table Card -->
            <div class="rounded-2xl overflow-hidden" style="box-shadow: 0 4px 20px rgba(27,60,83,0.10); border: 1px solid rgba(69,104,130,0.14);">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider w-8" style="color:rgba(210,193,182,0.8);">
                                <input type="checkbox" id="select-all-tickets" class="rounded border-gray-300 focus:ring-2 w-4 h-4 cursor-pointer" style="accent-color:#D2C1B6;" onclick="toggleSelectAll(this)">
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider" style="color:rgba(210,193,182,0.9);">Ticket</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider" style="color:rgba(210,193,182,0.9);">Date Raised</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider" style="color:rgba(210,193,182,0.9);">Student Details</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider" style="color:rgba(210,193,182,0.9);">Category</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider" style="color:rgba(210,193,182,0.9);">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider text-right" style="color:rgba(210,193,182,0.9);">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y" style="background:#fafbfc; divide-color: rgba(69,104,130,0.08)">
                        <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-16 text-center text-gray-500">
                                    <div class="bg-gray-50 inline-block p-5 rounded-full mb-3">
                                        <i class="fas fa-ticket-alt text-gray-300 text-3xl"></i>
                                    </div>
                                    <p class="font-semibold text-gray-700">No support tickets found</p>
                                    <p class="text-xs text-gray-400 mt-1">Try modifying search keywords or filters.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <!-- Main Row -->
                                <tr class="hover:bg-gray-50/50 cursor-pointer ticket-row transition-colors" data-id="<?= $ticket['id'] ?>">
                                    <td class="px-6 py-4 whitespace-nowrap w-8" onclick="event.stopPropagation()">
                                        <input type="checkbox" value="<?= $ticket['id'] ?>" class="ticket-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4 cursor-pointer" onclick="updateBulkBar()">
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="relative inline-block" onclick="event.stopPropagation();">
                                            <span onclick="copyTicketId(event, '<?= $ticket['id'] ?>')" class="ticket-capsule cursor-pointer hover:bg-slate-200 transition-colors duration-150" title="Click to copy Reference ID">
                                                🎫 #<span class="ticket-id-value"><?= $ticket['id'] ?></span>
                                                <i class="far fa-copy text-gray-400 hover:text-gray-600 transition-colors ml-1 copy-icon-<?= $ticket['id'] ?>"></i>
                                            </span>
                                            <span id="tooltip-<?= $ticket['id'] ?>" class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-2.5 py-1 text-xs text-white bg-black rounded shadow-md opacity-0 pointer-events-none transition-opacity duration-300 z-50 whitespace-nowrap">Copied!</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <div class="font-medium text-gray-700"><?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?></div>
                                        <div class="mt-1"><?= get_ticket_age_badge($ticket['created_at']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-gray-800">
                                            <?= htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']) ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?= htmlspecialchars($ticket['student_id']) ?> | <?= htmlspecialchars($ticket['student_email']) ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-gray-700">
                                            <?= get_category_chip($ticket['reason']) ?>
                                        </div>
                                        <?php if (!empty($ticket['batch_name'])): ?>
                                            <div class="text-xs text-gray-500">
                                                <i class="fas fa-graduation-cap text-gray-400 mr-1"></i><?= htmlspecialchars($ticket['batch_name']) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-xs text-gray-400">
                                                General / No Batch
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php 
                                        $status = trim($ticket['status'] ?? '');
                                        if (empty($status)) $status = 'open';
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold
                                            <?= $status === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' ?>">
                                            <?= ucfirst($status) ?>
                                        </span>
                                        <?php if (!empty($ticket['assigned_trainer_name'])): ?>
                                            <div class="mt-1.5"><span class="trainer-badge-enhanced">👨‍🏫 <?= htmlspecialchars($ticket['assigned_trainer_name']) ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" onclick="event.stopPropagation()">
                                        <div class="flex flex-col items-end gap-2">
                                            <button onclick="toggleDetails(<?= $ticket['id'] ?>)" class="btn-hover px-3.5 py-2 text-white rounded-xl text-xs font-semibold flex items-center space-x-1.5" style="background: linear-gradient(135deg, #234C6A, #456882);">
                                                <i class="fas fa-comments"></i>
                                                <span>Chat / Details</span>
                                            </button>
                                            <?php if ($ticket['status'] === 'open'): ?>
                                                <form method="POST" action="tickets.php" onsubmit="return confirm('Are you sure you want to close this ticket?');" class="inline">
                                                    <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                    <button type="submit" name="close_ticket" class="btn-red-hover px-3.5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-semibold flex items-center space-x-1.5">
                                                        <i class="fas fa-times-circle"></i>
                                                        <span>Close</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div> <!-- Close tickets-table-view -->

    <?php
    $kanban_open = [];
    $kanban_in_progress = [];
    $kanban_resolved = [];
    
    foreach ($tickets as $t) {
        if ($t['status'] === 'resolved') {
            $kanban_resolved[] = $t;
        } else {
            $has_replies = !empty($t['messages']);
            $has_trainer = !empty($t['assigned_to_trainer_id']);
            
            if ($has_replies || $has_trainer) {
                $kanban_in_progress[] = $t;
            } else {
                $kanban_open[] = $t;
            }
        }
    }
    ?>
    <!-- Kanban View Container -->
    <div id="tickets-kanban-view" class="hidden">
        <div class="kanban-board">
            <!-- Open Column -->
            <div class="kanban-column" id="column-open" ondragover="allowDrop(event)" ondragenter="dragEnter(event)" ondragleave="dragLeave(event)" ondrop="drop(event, 'open')">
                <div class="kanban-column-header">
                    <span class="flex items-center gap-1.5 font-bold">📥 Open</span>
                    <span class="bg-blue-100 text-blue-800 text-xs px-2.5 py-0.5 rounded-full font-bold column-count" id="count-open"><?= count($kanban_open) ?></span>
                </div>
                <div class="kanban-cards" id="cards-open">
                    <?php foreach ($kanban_open as $t): ?>
                        <div class="kanban-card transition-all" draggable="true" ondragstart="drag(event)" id="card-<?= $t['id'] ?>" data-id="<?= $t['id'] ?>">
                            <div class="flex justify-between items-start mb-2">
                                <span class="ticket-capsule text-xs">🎫 #<?= $t['id'] ?></span>
                            </div>
                            <div class="font-bold text-gray-800 text-sm mb-1"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></div>
                            <div class="text-[10px] text-gray-500 mb-2"><?= htmlspecialchars($t['student_id']) ?></div>
                            
                            <div class="mb-3 flex flex-wrap gap-1.5 items-center">
                                <?= get_category_chip($t['reason']) ?>
                                <?= get_ticket_age_badge($t['created_at']) ?>
                            </div>
                            
                            <div class="flex justify-between items-center border-t border-gray-100 pt-2 mt-2">
                                <?php if (!empty($t['assigned_trainer_name'])): ?>
                                    <span class="text-[9px] font-semibold text-purple-600 bg-purple-50 px-2 py-0.5 rounded-md">👨‍🏫 <?= htmlspecialchars($t['assigned_trainer_name']) ?></span>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>
                                <button onclick="toggleDetails(<?= $t['id'] ?>)" class="text-blue-600 hover:text-blue-800 text-xs font-bold flex items-center gap-1">
                                    <i class="fas fa-comments text-[10px]"></i> View Chat
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- In Progress Column -->
            <div class="kanban-column" id="column-in-progress" ondragover="allowDrop(event)" ondragenter="dragEnter(event)" ondragleave="dragLeave(event)" ondrop="drop(event, 'in-progress')">
                <div class="kanban-column-header">
                    <span class="flex items-center gap-1.5 font-bold">⚡ In Progress</span>
                    <span class="bg-purple-100 text-purple-800 text-xs px-2.5 py-0.5 rounded-full font-bold column-count" id="count-in-progress"><?= count($kanban_in_progress) ?></span>
                </div>
                <div class="kanban-cards" id="cards-in-progress">
                    <?php foreach ($kanban_in_progress as $t): ?>
                        <div class="kanban-card transition-all" draggable="true" ondragstart="drag(event)" id="card-<?= $t['id'] ?>" data-id="<?= $t['id'] ?>">
                            <div class="flex justify-between items-start mb-2">
                                <span class="ticket-capsule text-xs">🎫 #<?= $t['id'] ?></span>
                            </div>
                            <div class="font-bold text-gray-800 text-sm mb-1"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></div>
                            <div class="text-[10px] text-gray-500 mb-2"><?= htmlspecialchars($t['student_id']) ?></div>
                            
                            <div class="mb-3 flex flex-wrap gap-1.5 items-center">
                                <?= get_category_chip($t['reason']) ?>
                                <?= get_ticket_age_badge($t['created_at']) ?>
                            </div>
                            
                            <div class="flex justify-between items-center border-t border-gray-100 pt-2 mt-2">
                                <?php if (!empty($t['assigned_trainer_name'])): ?>
                                    <span class="text-[9px] font-semibold text-purple-600 bg-purple-50 px-2 py-0.5 rounded-md">👨‍🏫 <?= htmlspecialchars($t['assigned_trainer_name']) ?></span>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>
                                <button onclick="toggleDetails(<?= $t['id'] ?>)" class="text-blue-600 hover:text-blue-800 text-xs font-bold flex items-center gap-1">
                                    <i class="fas fa-comments text-[10px]"></i> View Chat
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Resolved Column -->
            <div class="kanban-column" id="column-resolved" ondragover="allowDrop(event)" ondragenter="dragEnter(event)" ondragleave="dragLeave(event)" ondrop="drop(event, 'resolved')">
                <div class="kanban-column-header">
                    <span class="flex items-center gap-1.5 font-bold">✅ Resolved</span>
                    <span class="bg-green-100 text-green-800 text-xs px-2.5 py-0.5 rounded-full font-bold column-count" id="count-resolved"><?= count($kanban_resolved) ?></span>
                </div>
                <div class="kanban-cards" id="cards-resolved">
                    <?php foreach ($kanban_resolved as $t): ?>
                        <div class="kanban-card transition-all" draggable="true" ondragstart="drag(event)" id="card-<?= $t['id'] ?>" data-id="<?= $t['id'] ?>">
                            <div class="flex justify-between items-start mb-2">
                                <span class="ticket-capsule text-xs">🎫 #<?= $t['id'] ?></span>
                            </div>
                            <div class="font-bold text-gray-800 text-sm mb-1"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></div>
                            <div class="text-[10px] text-gray-500 mb-2"><?= htmlspecialchars($t['student_id']) ?></div>
                            
                            <div class="mb-3 flex flex-wrap gap-1.5 items-center">
                                <?= get_category_chip($t['reason']) ?>
                                <?= get_ticket_age_badge($t['created_at']) ?>
                            </div>
                            
                            <div class="flex justify-between items-center border-t border-gray-100 pt-2 mt-2">
                                <?php if (!empty($t['assigned_trainer_name'])): ?>
                                    <span class="text-[9px] font-semibold text-purple-600 bg-purple-50 px-2 py-0.5 rounded-md">👨‍🏫 <?= htmlspecialchars($t['assigned_trainer_name']) ?></span>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>
                                <button onclick="toggleDetails(<?= $t['id'] ?>)" class="text-blue-600 hover:text-blue-800 text-xs font-bold flex items-center gap-1">
                                    <i class="fas fa-comments text-[10px]"></i> View Chat
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

<?php foreach ($tickets as $ticket): ?>
    <!-- Glassmorphism Side Panel for each ticket -->
    <div id="details-<?= $ticket['id'] ?>" class="ticket-side-panel-overlay fixed inset-0 z-[1000] hidden" onclick="closeDetails(<?= $ticket['id'] ?>)">
        <!-- Glassmorphic Backdrop (Frosted portrait-camera blur) -->
        <div class="absolute inset-0 portrait-blur-backdrop transition-opacity duration-300"></div>
        
        <!-- Slide-in Panel Content -->
        <div class="side-panel-inner absolute right-0 top-0 bottom-0 w-full max-w-lg portrait-blur-panel shadow-2xl transform translate-x-full transition-transform duration-300 ease-out flex flex-col h-full z-10" onclick="event.stopPropagation()">
            <!-- Header -->
            <div class="panel-header px-6 py-5 flex justify-between items-center shrink-0 border-b border-white/10">
                <div>
                    <h3 class="text-lg font-bold flex items-center gap-2" style="color:#fff !important;">
                        <span style="color:#fff !important;">🎫 Ticket #<?= $ticket['id'] ?></span>
                        <?php 
                        $status = trim($ticket['status'] ?? '');
                        if (empty($status)) $status = 'open';
                        ?>
                        <span class="text-xs px-2.5 py-1 rounded-full font-semibold uppercase tracking-wider <?= $status === 'resolved' ? 'status-badge-resolved' : 'status-badge-open' ?>" style="<?= $status === 'resolved' ? 'background:rgba(16,185,129,0.25);color:#6ee7b7;border:1px solid rgba(16,185,129,0.4);' : 'background:rgba(59,130,246,0.25);color:#93c5fd;border:1px solid rgba(59,130,246,0.4);' ?>">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </h3>
                    <p class="text-xs mt-1" style="color:rgba(255,255,255,0.7) !important;"><?= htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']) ?> | <?= htmlspecialchars($ticket['student_id']) ?></p>
                </div>
                <button onclick="closeDetails(<?= $ticket['id'] ?>)" class="close-btn text-xl focus:outline-none transition-colors" style="color:rgba(255,255,255,0.6) !important; background:none; border:none;">
                    <i class="fas fa-times" style="color:inherit !important;"></i>
                </button>
            </div>
            
            <!-- Content Area (Scrollable) -->
            <div class="panel-body p-6 space-y-5 overflow-y-auto flex-1 text-left">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-white/5 p-4 rounded-xl border border-white/5">
                    <div>
                        <strong class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Ticket Category:</strong>
                        <div class="mt-1"><?= get_category_chip($ticket['reason']) ?></div>
                    </div>
                    <div>
                        <strong class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Associated Batch:</strong>
                        <span class="text-sm text-slate-200 font-semibold">
                             <?= !empty($ticket['batch_name']) ? htmlspecialchars($ticket['batch_name']) : 'General / No Batch' ?>
                        </span>
                    </div>
                </div>

                <!-- Assign to Trainer -->
                <?php if ($ticket['status'] === 'open'): ?>
                <div class="p-4 bg-purple-500/10 border border-purple-500/20 rounded-xl">
                    <strong class="text-xs font-bold text-purple-400 uppercase tracking-wider block mb-2"><i class="fas fa-chalkboard-teacher mr-1"></i> Assign to Trainer</strong>
                    <form method="POST" action="tickets.php" class="flex items-center gap-2">
                        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                        <select name="trainer_user_id" class="flex-1 px-3 py-2 text-sm border border-white/10 rounded-lg focus:ring-2 focus:ring-purple-500 bg-slate-800 text-white">
                            <option class="bg-slate-900" value="">— None —</option>
                            <?php foreach ($trainers_list as $tr): ?>
                                <option class="bg-slate-900" value="<?= $tr['id'] ?>" <?= $ticket['assigned_to_trainer_id'] == $tr['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tr['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="assign_trainer" class="btn-purple-hover px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-xs font-semibold rounded-xl transition-all shadow-sm">
                            Assign
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if (!empty($ticket['attachment_path'])): ?>
                    <div class="text-left">
                        <strong class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Attachment File:</strong>
                        <a href="../<?= htmlspecialchars($ticket['attachment_path']) ?>" target="_blank" class="inline-flex items-center space-x-2 text-xs font-semibold text-blue-300 hover:text-blue-200 bg-blue-500/10 hover:bg-blue-500/20 px-3.5 py-2 rounded-lg transition-colors border border-blue-500/20 w-full justify-center">
                            <i class="fas fa-paperclip"></i>
                            <span>View / Download Attachment</span>
                        </a>
                    </div>
                <?php endif; ?>

                <div class="border-t border-gray-150 pt-4 text-left">
                    <h5 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Ticket Conversation</h5>
                    
                    <div class="chat-messages-area space-y-4 overflow-y-auto mb-4 p-4 bg-slate-50 border border-slate-200/80 rounded-xl flex flex-col" style="max-height: calc(100vh - 420px); min-height: 180px;">
                        <?php 
                        $last_date = null;
                        
                        // First Message Date Divider
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
                        <div class="flex items-start gap-2 max-w-[85%]">
                            <div class="w-8 h-8 rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/30 flex items-center justify-center text-xs font-bold shrink-0">
                                S
                            </div>
                            <div class="chat-bubble-left p-3 text-left">
                                <div class="flex items-center space-x-2 mb-1">
                                    <span class="text-xs font-bold text-blue-600"><?= htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']) ?> (Student)</span>
                                </div>
                                <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($ticket['description'] ?: 'No description provided.') ?></p>
                                <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium">
                                    <?= date('h:i A', strtotime($ticket['created_at'])) ?>
                                </div>
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
                                $sender_display = $is_student ? htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']) . ' (Student)' : 
                                                  ($is_mentor ? htmlspecialchars($msg['sender_name']) . ' (Trainer)' : htmlspecialchars($msg['sender_name']) . ' (Admin)');
                            ?>
                            
                            <?php if ($is_student || $is_mentor): ?>
                                <!-- Left Aligned message (Student or Trainer) -->
                                <div class="flex items-start gap-2 max-w-[85%]">
                                    <div class="w-8 h-8 rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/30 flex items-center justify-center text-xs font-bold shrink-0">
                                        <?= $avatar_initial ?>
                                    </div>
                                    <div class="chat-bubble-left p-3 text-left">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <span class="text-xs font-bold text-blue-600"><?= $sender_display ?></span>
                                        </div>
                                        <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($msg['message']) ?></p>
                                        <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium">
                                            <?= date('h:i A', strtotime($msg['created_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Right Aligned message (Admin) -->
                                <div class="flex items-start gap-2 max-w-[85%] ml-auto justify-end">
                                    <div class="chat-bubble-right p-3 text-left">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <span class="text-xs font-bold text-emerald-600"><?= $sender_display ?></span>
                                        </div>
                                        <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($msg['message']) ?></p>
                                        <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium flex items-center justify-end gap-1">
                                            <?= date('h:i A', strtotime($msg['created_at'])) ?>
                                            <span class="tick-mark">✓✓</span>
                                        </div>
                                    </div>
                                    <div class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 flex items-center justify-center text-xs font-bold shrink-0">
                                        <?= $avatar_initial ?>
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
                            <div class="flex items-start gap-2 max-w-[85%] ml-auto justify-end">
                                <div class="chat-bubble-right p-3 text-left">
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="text-xs font-bold text-emerald-600">Admin (Response)</span>
                                    </div>
                                    <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($ticket['admin_response']) ?></p>
                                    <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium flex items-center justify-end gap-1">
                                        <?php if (!empty($ticket['resolved_at'])): ?>
                                            <?= date('h:i A', strtotime($ticket['resolved_at'])) ?>
                                        <?php endif; ?>
                                        <span class="tick-mark">✓✓</span>
                                    </div>
                                </div>
                                <div class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 flex items-center justify-center text-xs font-bold shrink-0">
                                    A
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Chat Action Controls -->
                    <?php if ($ticket['status'] === 'open'): ?>
                        <form method="POST" action="tickets.php" class="flex flex-col gap-3">
                            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                            <div class="flex gap-2">
                                <input type="text" name="admin_response" required placeholder="Type reply message to student..." class="flex-1 px-4 py-2.5 text-sm border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 bg-slate-800 text-white">
                                <button type="submit" name="resolve_ticket" class="btn-hover px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm flex items-center space-x-1 shrink-0">
                                    <i class="fas fa-paper-plane"></i>
                                    <span>Reply</span>
                                </button>
                            </div>
                        </form>
                        <div class="mt-3 flex items-center justify-between border-t border-white/10 pt-3">
                            <span class="text-xs text-slate-400">Or close the ticket if resolved:</span>
                            <form method="POST" action="tickets.php" onsubmit="return confirm('Are you sure you want to close this ticket?');">
                                <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                <button type="submit" name="close_ticket" class="btn-red-hover px-4 py-2 bg-red-950/40 hover:bg-red-950/60 text-red-400 hover:text-red-300 font-semibold rounded-xl text-xs flex items-center space-x-1.5 border border-red-500/20 shadow-sm">
                                    <i class="fas fa-times-circle"></i>
                                    <span>Close Ticket</span>
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center justify-between p-3 bg-slate-950/60 rounded-xl border border-white/5">
                            <span class="text-xs font-semibold text-slate-400 flex items-center">
                                <i class="fas fa-lock mr-1.5 text-slate-500"></i> Ticket is closed. Chat is disabled.
                            </span>
                            <span class="text-[10px] text-slate-400">
                                Closed on: <strong><?= date('d M Y, h:i A', strtotime($ticket['resolved_at'])) ?></strong>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

<?php endforeach; ?>


<!-- Bulk Action Confirmation Modal -->
<div id="bulk-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] hidden">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden animate-fade-in">
        <div class="bg-gradient-to-r from-slate-700 to-slate-900 text-white p-5 flex justify-between items-center">
            <h2 class="text-base font-bold flex items-center gap-2">
                <i class="fas fa-tasks"></i>
                <span id="bulk-modal-title">Bulk Action</span>
            </h2>
            <button onclick="closeBulkModal()" class="text-white hover:text-gray-200 text-xl"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6">
            <div class="flex items-start gap-3 mb-6">
                <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center shrink-0">
                    <i class="fas fa-exclamation-triangle text-amber-600"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-800 text-sm" id="bulk-modal-desc">Are you sure?</p>
                    <p class="text-xs text-gray-500 mt-1">This action cannot be undone.</p>
                </div>
            </div>
            <div class="flex gap-3 justify-end">
                <button onclick="closeBulkModal()" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl transition-all">Cancel</button>
                <button onclick="executeBulkAction()" class="px-5 py-2 text-sm font-bold text-white bg-red-600 hover:bg-red-700 rounded-xl transition-all flex items-center gap-2 shadow-sm">
                    <i class="fas fa-check"></i> Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Hidden Form -->
<form id="bulk-action-form" method="POST" action="tickets.php" class="hidden">
    <input type="hidden" name="bulk_action" value="1">
    <input type="hidden" name="bulk_action_type" id="bulk-action-type" value="">
    <input type="hidden" name="ticket_ids" id="bulk-ticket-ids" value="">
</form>

<!-- Resolve Ticket Modal -->
<div id="resolveModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden transform transition-all duration-300 scale-95 opacity-0" id="resolveModalContainer">
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-5">
            <div class="flex justify-between items-center">
                <h2 class="text-lg font-bold flex items-center">
                    <i class="fas fa-reply mr-2.5"></i>
                    Resolve Support Ticket
                </h2>
                <button onclick="closeResolveModal()" class="text-white hover:text-gray-200 text-2xl focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <form method="POST" action="tickets.php" class="p-6 space-y-4">
            <input type="hidden" name="ticket_id" id="modal_ticket_id">
            
            <div class="text-sm text-gray-600">
                <p>Resolving ticket for student: <strong id="modal_student_name" class="text-gray-800"></strong></p>
                <p class="mt-1">Category: <strong id="modal_ticket_category" class="text-gray-800"></strong></p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Admin Response *</label>
                <textarea name="admin_response" required rows="5" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm" placeholder="Provide resolution or instructions for the student..."></textarea>
            </div>

            <div class="flex space-x-3 pt-2">
                <button type="button" onclick="closeResolveModal()" class="w-1/2 px-4 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-semibold transition-all text-sm">
                    Cancel
                </button>
                <button type="submit" name="resolve_ticket" class="w-1/2 px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-semibold shadow-md transition-all text-sm">
                    Resolve & Send Notification
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Copy Ticket ID to Clipboard
    function copyTicketId(event, id) {
        event.stopPropagation();
        const textToCopy = id.toString();
        navigator.clipboard.writeText(textToCopy).then(() => {
            const icon = document.querySelector('.copy-icon-' + id);
            const tooltip = document.getElementById('tooltip-' + id);
            
            if (icon) {
                const originalClass = icon.className;
                icon.className = 'fas fa-spinner fa-spin text-green-500 ml-1 copy-icon-' + id;
                
                setTimeout(() => {
                    icon.className = 'fas fa-check text-green-500 ml-1 copy-icon-' + id;
                }, 200);
                
                if (tooltip) {
                    tooltip.classList.remove('opacity-0');
                    tooltip.classList.add('opacity-100');
                }
                
                setTimeout(() => {
                    icon.className = originalClass;
                    if (tooltip) {
                        tooltip.classList.remove('opacity-100');
                        tooltip.classList.add('opacity-0');
                    }
                }, 1500);
            }
        }).catch(err => {
            console.error('Failed to copy Reference ID: ', err);
        });
    }

    // Slide-in side panel togglers
    function toggleDetails(id) {
        const overlay = document.getElementById('details-' + id);
        if (!overlay) return;
        
        // Close any other open panels first
        document.querySelectorAll('.ticket-side-panel-overlay').forEach(el => {
            if (el.id !== 'details-' + id && !el.classList.contains('hidden')) {
                const otherId = el.id.replace('details-', '');
                closeDetails(otherId);
            }
        });

        const isHidden = overlay.classList.contains('hidden');
        if (isHidden) {
            // Move to body to escape any overflow clipping from parent
            if (overlay.parentNode !== document.body) {
                document.body.appendChild(overlay);
            }
            overlay.classList.remove('hidden');
            // Trigger reflow
            overlay.offsetHeight;
            const backdrop = overlay.querySelector('.absolute.inset-0');
            const panel = overlay.querySelector('.side-panel-inner');
            if (backdrop) {
                backdrop.style.opacity = '0';
                backdrop.style.transition = 'opacity 0.3s ease';
                backdrop.offsetHeight;
                backdrop.style.opacity = '1';
            }
            if (panel) {
                panel.classList.remove('translate-x-full');
                panel.classList.add('translate-x-0');
            }
            // Hide mobile FAB while panel is open
            const fab = document.getElementById('mobile-sidebar-fab');
            if (fab) fab.style.display = 'none';
            // Scroll chat to bottom
            const chatArea = overlay.querySelector('.chat-messages-area');
            if (chatArea) {
                setTimeout(() => { chatArea.scrollTop = chatArea.scrollHeight; }, 350);
            }
        } else {
            closeDetails(id);
        }
    }

    function closeDetails(id) {
        const overlay = document.getElementById('details-' + id);
        if (!overlay) return;
        const backdrop = overlay.querySelector('.absolute.inset-0');
        const panel = overlay.querySelector('.side-panel-inner');
        if (backdrop) {
            backdrop.style.opacity = '0';
        }
        if (panel) {
            panel.classList.remove('translate-x-0');
            panel.classList.add('translate-x-full');
        }
        setTimeout(() => {
            overlay.classList.add('hidden');
            // Restore mobile FAB
            const fab = document.getElementById('mobile-sidebar-fab');
            if (fab) fab.style.display = '';
        }, 300);
    }

    // Modal triggers
    function openResolveModal(id, studentName, category) {
        document.getElementById('modal_ticket_id').value = id;
        document.getElementById('modal_student_name').textContent = studentName;
        document.getElementById('modal_ticket_category').textContent = category;
        
        const modal = document.getElementById('resolveModal');
        const container = document.getElementById('resolveModalContainer');
        modal.classList.remove('hidden');
        setTimeout(() => {
            container.classList.remove('scale-95', 'opacity-0');
            container.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeResolveModal() {
        const modal = document.getElementById('resolveModal');
        const container = document.getElementById('resolveModalContainer');
        container.classList.remove('scale-100', 'opacity-100');
        container.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    }

    // Row selection fallback to toggle detail side panels
    document.querySelectorAll('.ticket-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('.ticket-capsule') || e.target.closest('[onclick]')) {
                return;
            }
            const id = this.dataset.id;
            toggleDetails(id);
        });
    });

    // Sidebar toggler fallback
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar && overlay) {
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
    }

    // View Switching Logic (Table vs Kanban)
    function switchViewMode(mode) {
        const tableView = document.getElementById('tickets-table-view');
        const kanbanView = document.getElementById('tickets-kanban-view');
        const toggleTableBtn = document.getElementById('view-toggle-table');
        const toggleKanbanBtn = document.getElementById('view-toggle-kanban');
        
        if (mode === 'kanban') {
            tableView.classList.add('hidden');
            kanbanView.classList.remove('hidden');
            
            toggleTableBtn.className = "px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all text-gray-500 hover:text-gray-800";
            toggleKanbanBtn.className = "px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all bg-white text-gray-800 shadow-sm border border-gray-200/50";
            localStorage.setItem('tickets_view_mode', 'kanban');
        } else {
            kanbanView.classList.add('hidden');
            tableView.classList.remove('hidden');
            
            toggleKanbanBtn.className = "px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all text-gray-500 hover:text-gray-800";
            toggleTableBtn.className = "px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all bg-white text-gray-800 shadow-sm border border-gray-200/50";
            localStorage.setItem('tickets_view_mode', 'table');
        }
    }

    // Initialize View Mode on Load
    document.addEventListener('DOMContentLoaded', () => {
        const savedMode = localStorage.getItem('tickets_view_mode') || 'table';
        switchViewMode(savedMode);
        
        // Register dragend events on card elements
        document.querySelectorAll('.kanban-card').forEach(card => {
            card.addEventListener('dragend', (e) => {
                e.target.classList.remove('dragging');
            });
        });
    });

    // Native Drag and Drop Logic
    function drag(event) {
        const id = event.target.dataset.id;
        event.dataTransfer.setData("text/plain", id);
        event.target.classList.add('dragging');
    }

    function allowDrop(event) {
        event.preventDefault();
    }

    function dragEnter(event) {
        event.currentTarget.classList.add('dragover');
    }

    function dragLeave(event) {
        event.currentTarget.classList.remove('dragover');
    }

    function drop(event, targetColumn) {
        event.preventDefault();
        
        // Remove dragover indicators
        document.querySelectorAll('.kanban-column').forEach(col => {
            col.classList.remove('dragover');
        });
        
        const id = event.dataTransfer.getData("text/plain");
        const card = document.getElementById('card-' + id);
        if (!card) return;
        
        const targetContainer = event.currentTarget.querySelector('.kanban-cards');
        if (targetContainer) {
            targetContainer.appendChild(card);
            card.classList.remove('dragging');
            
            // Sync column counts
            updateColumnCounts();
            
            // Sync to Database via AJAX
            const formData = new FormData();
            formData.append('ajax_update_status', '1');
            formData.append('ticket_id', id);
            formData.append('status', targetColumn === 'resolved' ? 'resolved' : 'open');
            
            fetch('tickets.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert('Sync error: ' + data.message);
                    window.location.reload();
                }
            })
            .catch(err => {
                console.error(err);
                window.location.reload();
            });
        }
    }

    function updateColumnCounts() {
        const cols = ['open', 'in-progress', 'resolved'];
        cols.forEach(col => {
            const countEl = document.getElementById('count-' + col);
            const cardsContainer = document.getElementById('cards-' + col);
            if (countEl && cardsContainer) {
                countEl.textContent = cardsContainer.children.length;
            }
        });
    }

    /* ───────── Bulk Actions ───────── */
    function toggleSelectAll(master) {
        document.querySelectorAll('.ticket-checkbox').forEach(cb => {
            cb.checked = master.checked;
        });
        updateBulkBar();
    }

    function updateBulkBar() {
        const selected = document.querySelectorAll('.ticket-checkbox:checked').length;
        // Support both old id (bulk-action-bar/selected-count) and new (bulk-bar/bulk-count)
        const bar = document.getElementById('bulk-action-bar') || document.getElementById('bulk-bar');
        const countEl = document.getElementById('selected-count') || document.getElementById('bulk-count');
        if (bar) bar.classList.toggle('hidden', selected === 0);
        if (countEl) countEl.textContent = selected;
    }

    function triggerBulkClose() {
        const selected = Array.from(document.querySelectorAll('.ticket-checkbox:checked')).map(cb => cb.value);
        if (!selected.length) { alert('Please select at least one ticket.'); return; }
        document.getElementById('bulk-action-type').value = 'close';
        document.getElementById('bulk-ticket-ids').value = selected.join(',');
        document.getElementById('bulk-modal-title').textContent = 'Close Selected Tickets';
        document.getElementById('bulk-modal-desc').textContent = `Close ${selected.length} ticket(s)? This cannot be undone.`;
        document.getElementById('bulk-modal').classList.remove('hidden');
    }

    function openBulkModal(action) {
        const selected = Array.from(document.querySelectorAll('.ticket-checkbox:checked')).map(cb => cb.value);
        if (!selected.length) return;
        document.getElementById('bulk-action-type').value = action;
        document.getElementById('bulk-ticket-ids').value = selected.join(',');
        const labels = { close: 'Close Selected Tickets' };
        document.getElementById('bulk-modal-title').textContent = labels[action] || action;
        document.getElementById('bulk-modal-desc').textContent = `This will apply "${labels[action] || action}" to ${selected.length} ticket(s). Are you sure?`;
        document.getElementById('bulk-modal').classList.remove('hidden');
    }

    function closeBulkModal() {
        document.getElementById('bulk-modal').classList.add('hidden');
    }

    function executeBulkAction() {
        document.getElementById('bulk-action-form').submit();
    }
</script>


<?php include '../footer.php'; ?>