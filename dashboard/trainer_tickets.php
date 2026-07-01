<?php
include '../db_connection.php';
session_start();

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
            $stmt = $db->prepare("UPDATE trainer_tickets SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE id = ?");
            $stmt->execute([$admin_id, $ticket_id]);
            echo json_encode(['success' => true, 'message' => 'Trainer ticket resolved successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
    } else {
        try {
            $stmt = $db->prepare("UPDATE trainer_tickets SET status = 'open', resolved_at = NULL, resolved_by = NULL WHERE id = ?");
            $stmt->execute([$ticket_id]);
            echo json_encode(['success' => true, 'message' => 'Trainer ticket status updated to open.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
    }
    exit();
}

// Ensure priority column exists
try {
    $checkCol = $db->query("SHOW COLUMNS FROM trainer_tickets LIKE 'priority'");
    if (!$checkCol->fetch()) {
        $db->exec("ALTER TABLE trainer_tickets ADD COLUMN priority VARCHAR(20) DEFAULT 'low'");
    }
} catch (PDOException $e) {
    // Fail silently or handle
}

// Handle reply to trainer ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $message   = trim($_POST['message'] ?? '');
    if (!empty($message)) {
        try {
            $db->beginTransaction();
            $ins = $db->prepare("INSERT INTO trainer_ticket_messages (ticket_id, sender_id, message) VALUES (?,?,?)");
            $ins->execute([$ticket_id, $admin_id, $message]);

            // Notify trainer
            $tk = $db->prepare("SELECT trainer_user_id, reason FROM trainer_tickets WHERE id = ?");
            $tk->execute([$ticket_id]);
            $row = $tk->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $notif = $db->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) VALUES (?,?,?,?,?,0)");
                $notif->execute([$row['trainer_user_id'], 'ticket', 'Admin replied to your ticket', "Admin responded to your ticket regarding '{$row['reason']}'.", $ticket_id]);
            }
            $db->commit();
            $_SESSION['tmsg'] = ['type' => 'success', 'text' => 'Reply sent.'];
        } catch (PDOException $e) {
            $db->rollBack();
            $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'DB error: ' . $e->getMessage()];
        }
    }
    header("Location: trainer_tickets.php");
    exit();
}

// Handle close trainer ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    $ticket_id = intval($_POST['ticket_id']);
    try {
        $upd = $db->prepare("UPDATE trainer_tickets SET status='resolved', resolved_at=NOW(), resolved_by=? WHERE id=?");
        $upd->execute([$admin_id, $ticket_id]);

        $tk = $db->prepare("SELECT trainer_user_id, reason FROM trainer_tickets WHERE id = ?");
        $tk->execute([$ticket_id]);
        $row = $tk->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $notif = $db->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) VALUES (?,?,?,?,?,0)");
            $notif->execute([$row['trainer_user_id'], 'ticket', 'Your ticket has been closed', "Your ticket regarding '{$row['reason']}' was closed by admin.", $ticket_id]);
        }
        $_SESSION['tmsg'] = ['type' => 'success', 'text' => "Ticket #$ticket_id closed."];
    } catch (PDOException $e) {
        $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'DB error: ' . $e->getMessage()];
    }
    header("Location: trainer_tickets.php");
    exit();
}

// Handle priority update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_priority'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $priority = ucfirst(strtolower(trim($_POST['priority'] ?? 'low')));
    $valid_priorities = ['Low', 'Medium', 'High', 'Urgent'];
    if (in_array($priority, $valid_priorities)) {
        try {
            $upd = $db->prepare("UPDATE trainer_tickets SET priority = ? WHERE id = ?");
            $upd->execute([$priority, $ticket_id]);
            $_SESSION['tmsg'] = ['type' => 'success', 'text' => "Priority updated to " . $priority . " for ticket #$ticket_id."];
        } catch (PDOException $e) {
            $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'DB error: ' . $e->getMessage()];
        }
    }
    header("Location: trainer_tickets.php");
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
                $stmt = $db->prepare("UPDATE trainer_tickets SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE id IN ($placeholders)");
                $stmt->execute(array_merge([$admin_id], $ticket_ids));
                $_SESSION['tmsg'] = ['type' => 'success', 'text' => count($ticket_ids) . ' tickets closed successfully.'];
            } catch (PDOException $e) {
                $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'DB error: ' . $e->getMessage()];
            }
        } elseif ($action === 'priority') {
            $priority = trim($_POST['bulk_priority'] ?? 'Low');
            $valid_priorities = ['Low', 'Medium', 'High', 'Urgent'];
            if (in_array($priority, $valid_priorities)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
                    $stmt = $db->prepare("UPDATE trainer_tickets SET priority = ? WHERE id IN ($placeholders)");
                    $stmt->execute(array_merge([$priority], $ticket_ids));
                    $_SESSION['tmsg'] = ['type' => 'success', 'text' => count($ticket_ids) . ' tickets set to ' . $priority . ' priority.'];
                } catch (PDOException $e) {
                    $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'DB error: ' . $e->getMessage()];
                }
            }
        }
    }
    header("Location: trainer_tickets.php");
    exit();
}



// Fetch filters
$search          = trim($_GET['search'] ?? '');
$status_filter   = trim($_GET['status'] ?? 'all');
$priority_filter = trim($_GET['priority'] ?? 'all');
$sort_by         = trim($_GET['sort_by'] ?? 'date_desc');

$sql = "
    SELECT tt.*, u.name as trainer_name, u.email as trainer_email, ru.name as resolved_by_name
    FROM trainer_tickets tt
    JOIN users u ON tt.trainer_user_id = u.id
    LEFT JOIN users ru ON tt.resolved_by = ru.id
    WHERE 1=1
";
$params = [];
if (!empty($search)) {
    $sql .= " AND (u.name LIKE :s1 OR tt.reason LIKE :s2)";
    $sp = '%' . $search . '%';
    $params[':s1'] = $sp;
    $params[':s2'] = $sp;
}
if ($status_filter !== 'all') {
    $sql .= " AND tt.status = :status";
    $params[':status'] = $status_filter;
}
if ($priority_filter !== 'all') {
    $sql .= " AND tt.priority = :priority";
    $params[':priority'] = strtolower($priority_filter);
}

if ($sort_by === 'priority_desc') {
    $sql .= " ORDER BY FIELD(tt.priority, 'urgent', 'high', 'medium', 'low') ASC, tt.created_at DESC";
} else {
    $sql .= " ORDER BY tt.created_at DESC";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch messages for each ticket
foreach ($tickets as &$t) {
    $msg = $db->prepare("
        SELECT ttm.*, u.name as sender_name, u.role as sender_role
        FROM trainer_ticket_messages ttm
        JOIN users u ON ttm.sender_id = u.id
        WHERE ttm.ticket_id = ?
        ORDER BY ttm.created_at ASC
    ");
    $msg->execute([$t['id']]);
    $t['messages'] = $msg->fetchAll(PDO::FETCH_ASSOC);
}
unset($t);

$tmsg = $_SESSION['tmsg'] ?? null;
unset($_SESSION['tmsg']);

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

// Helper function: Priority Badge (blue-purple SaaS theme)
function getTrainerPriorityBadge($priority) {
    $p = strtolower(trim($priority ?? ''));
    if ($p === 'urgent') {
        return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200">🔴 Urgent</span>';
    }
    if ($p === 'high') {
        return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-orange-50 text-orange-700 border border-orange-200">🟠 High</span>';
    }
    if ($p === 'medium') {
        return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">🟡 Medium</span>';
    }
    return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">🟢 Low</span>';
}

// Helper function: Status Badge (theme-matching)
function getTrainerStatusBadge($status) {
    $s = strtolower(trim($status ?? ''));
    if ($s === 'resolved') {
        return '<span class="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Resolved</span>';
    }
    return '<span class="px-3 py-1 rounded-full text-xs font-semibold bg-purple-50 text-purple-700 border border-purple-200">Open</span>';
}

// Helper function for age calculation
function getTicketAgeBadge($created_at) {
    $created = new DateTime($created_at);
    $now = new DateTime();
    $interval = $created->diff($now);
    
    $hours = ($interval->days * 24) + $interval->h;
    
    if ($hours < 24) {
        $color_class = 'bg-green-100 text-green-800 border border-green-200';
        if ($hours === 0) {
            $time_str = ($interval->i ?: 1) . ' mins ago';
        } else {
            $time_str = $hours . ' hrs ago';
        }
    } elseif ($hours < 72) { // 1-3 days
        $color_class = 'bg-yellow-100 text-yellow-800 border border-yellow-200';
        $time_str = $interval->days . ' ' . ($interval->days == 1 ? 'day' : 'days') . ' old';
    } else { // > 3 days
        $color_class = 'bg-red-100 text-red-800 border border-red-200';
        $time_str = $interval->days . ' days old';
    }
    
    return "<span class='px-2.5 py-1 rounded-full text-xs font-semibold flex items-center gap-1 {$color_class}'>⏱️ {$time_str}</span>";
}

// Helper function: Category Chips with Icons & Colors
function get_category_chip($category) {
    $c = trim($category ?? '');
    if (stripos($c, 'fee') !== false || stripos($c, 'payment') !== false || stripos($c, 'salary') !== false) {
        return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200">💰 ' . htmlspecialchars($c) . '</span>';
    } elseif (stripos($c, 'batch') !== false || stripos($c, 'schedule') !== false) {
        return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 border border-blue-200">📅 ' . htmlspecialchars($c) . '</span>';
    } elseif (stripos($c, 'exam') !== false || stripos($c, 'test') !== false) {
        return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200">📝 ' . htmlspecialchars($c) . '</span>';
    } elseif (stripos($c, 'app') !== false || stripos($c, 'technical') !== false || stripos($c, 'issue') !== false) {
        return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 border border-red-200">🔧 ' . htmlspecialchars($c) . '</span>';
    } else {
        return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 border border-gray-200">📌 ' . htmlspecialchars($c ?: 'Other') . '</span>';
    }
}


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
.trow {
    position: relative;
    border-left: 3px solid transparent;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.trow:hover {
    transform: translateX(2px);
    border-left-color: #7c3aed;
    background: linear-gradient(90deg, rgba(245, 243, 255, 0.9), rgba(255,255,255,0.6)) !important;
    box-shadow: inset 0 0 0 1px rgba(124, 58, 237, 0.08);
}

/* Smooth Button Hover */
.btn-hover {
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px -3px rgba(124, 58, 237, 0.45);
}
.btn-red-hover {
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-red-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px -3px rgba(239, 68, 68, 0.45);
}

/* KPI Cards - Premium Elevated */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.kpi-card {
    background: white;
    border-radius: 1rem;
    padding: 1.1rem 1.25rem;
    box-shadow: 0 2px 12px -4px rgba(27,60,83,0.18), 0 1px 3px rgba(0,0,0,0.06);
    border: 1px solid rgba(255,255,255,0.9);
    border-top: 3px solid transparent;
    transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 1rem;
    position: relative;
    overflow: hidden;
}
.kpi-card::after {
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 60px;
    background: linear-gradient(135deg, transparent, rgba(255,255,255,0.4));
    pointer-events: none;
}
.kpi-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 16px 32px -8px rgba(27,60,83,0.22), 0 4px 8px rgba(0,0,0,0.08);
}
.kpi-total { border-top-color: #1B3C53; }
.kpi-open  { border-top-color: #f59e0b; }
.kpi-resolved { border-top-color: #10b981; }
.kpi-high  { border-top-color: #ef4444; }

.kpi-card .kpi-icon {
    width: 46px;
    height: 46px;
    border-radius: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    color: white;
    flex-shrink: 0;
    box-shadow: 0 4px 12px -2px rgba(0,0,0,0.25);
}
.kpi-total .kpi-icon { background: linear-gradient(135deg, #1B3C53, #456882); }
.kpi-open .kpi-icon  { background: linear-gradient(135deg, #d97706, #fbbf24); }
.kpi-resolved .kpi-icon { background: linear-gradient(135deg, #059669, #34d399); }
.kpi-high .kpi-icon  { background: linear-gradient(135deg, #dc2626, #f87171); }

.kpi-card .kpi-content .kpi-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    font-weight: 700;
}
.kpi-card .kpi-content .kpi-number {
    font-size: 1.65rem;
    font-weight: 800;
    color: #1B3C53;
    line-height: 1.1;
    letter-spacing: -0.02em;
}
.kpi-card .kpi-content .kpi-sub {
    font-size: 0.68rem;
    color: #94a3b8;
    font-weight: 500;
}

/* Filter Card */
.filter-card {
    background: white;
    border-radius: 1rem;
    padding: 1rem 1.25rem;
    box-shadow: 0 2px 12px -4px rgba(27,60,83,0.14), 0 1px 3px rgba(0,0,0,0.05);
    border: 1px solid #e2e8f0;
    margin-bottom: 1.25rem;
}
.filter-card .filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: flex-end;
}
.filter-card .filter-form .filter-group {
    flex: 1 1 150px;
    min-width: 130px;
}
.filter-card .filter-form .filter-group label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 0.3rem;
}
.filter-card .filter-form .filter-group input,
.filter-card .filter-form .filter-group select {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 0.6rem;
    font-size: 0.85rem;
    background: #f8fafc;
    color: #1e293b;
    transition: all 0.2s;
}
.filter-card .filter-form .filter-group input:focus,
.filter-card .filter-form .filter-group select:focus {
    outline: none;
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
    background: white;
}
/* Highlighted search */
.filter-card .filter-form .filter-group input#search {
    border-color: #a78bfa;
    background: #faf5ff;
    padding-left: 0.85rem;
    font-weight: 500;
}
.filter-card .filter-form .filter-group input#search:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.15);
    background: white;
}
.filter-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.filter-actions button,
.filter-actions a {
    padding: 0.5rem 1.1rem;
    border-radius: 0.6rem;
    font-weight: 700;
    font-size: 0.82rem;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    text-decoration: none;
}
.filter-actions .btn-primary {
    background: linear-gradient(135deg, #1B3C53, #456882);
    color: white;
    box-shadow: 0 2px 8px -2px rgba(27,60,83,0.4);
}
.filter-actions .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px -2px rgba(27,60,83,0.5);
}
.filter-actions .btn-clear {
    background: #f1f5f9;
    color: #475569;
    border: 1.5px solid #e2e8f0;
}
.filter-actions .btn-clear:hover { background: #e2e8f0; }

/* Enhanced Table */
.table thead tr {
    background: linear-gradient(135deg, #1B3C53, #2d5870) !important;
    border-bottom: none !important;
}
.table thead th {
    color: rgba(255,255,255,0.85) !important;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.06em;
    font-size: 0.72rem;
    padding: 0.9rem 1.25rem;
}
.table thead th:first-child { border-radius: 0.75rem 0 0 0; }
.table thead th:last-child { border-radius: 0 0.75rem 0 0; }

/* Table wrapper */
.bg-white.rounded-\[16px\] {
    box-shadow: 0 4px 24px -8px rgba(27,60,83,0.2), 0 1px 4px rgba(0,0,0,0.06) !important;
}

/* Ticket ID Capsule */
.ticket-capsule {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: linear-gradient(135deg, #faf5ff, #f3e8ff);
    padding: 3px 10px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 12px;
    color: #7c3aed;
    border: 1px solid #ddd6fe;
}

/* Online Indicator */
.online-dot {
    width: 7px;
    height: 7px;
    background: #4ade80;
    border-radius: 50%;
    display: inline-block;
    animation: pulse-dot 2s infinite;
}
@keyframes pulse-dot {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.5); }
    50% { box-shadow: 0 0 0 5px rgba(74, 222, 128, 0); }
}

/* Kanban */
.kanban-board {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.25rem;
    margin-top: 1rem;
    align-items: start;
}
@media (max-width: 1024px) { .kanban-board { grid-template-columns: 1fr; } }

/* ── Responsive overrides ── */
@media (max-width: 768px) {
    .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .filter-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-actions { width: 100%; justify-content: flex-end; }
}
@media (max-width: 480px) {
    .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    /* Hide less-critical table columns on very small screens */
    .table thead th:nth-child(4),
    .table tbody td:nth-child(4),
    .table thead th:nth-child(5),
    .table tbody td:nth-child(5) { display: none; }
    #view-toggle-table span,
    #view-toggle-kanban span { display: none; }
}
/* ── Kanban Column base ── */
.kanban-column {
    border-radius: 1.1rem;
    padding: 0;
    display: flex;
    flex-direction: column;
    min-height: 480px;
    transition: all 0.25s ease;
    overflow: hidden;
    border: none;
    box-shadow: 0 2px 16px rgba(0,0,0,0.07);
}
.kanban-column-header {
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-bottom: 0;
    padding: 0.9rem 1.1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    text-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.kanban-cards { display: flex; flex-direction: column; gap: 0.85rem; flex-grow: 1; padding: 1rem; }

/* Shared card base */
.kanban-card {
    background: #fff;
    border-radius: 0.75rem;
    padding: 0.9rem;
    cursor: grab;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    user-select: none;
}
.kanban-card:active { cursor: grabbing; }
.kanban-card.dragging { opacity: 0.4; transform: scale(0.97); }

/* ── OPEN — Blue ── */
#column-open {
    background: linear-gradient(160deg, #dbeafe 0%, #eff6ff 60%, #f0f9ff 100%);
    border-top: 4px solid #3b82f6;
}
#column-open .kanban-column-header {
    background: linear-gradient(90deg, #1d4ed8, #3b82f6);
    color: #fff;
}
#column-open .column-count {
    background: rgba(255,255,255,0.25) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,0.4);
}
#column-open .kanban-card {
    border: 1.5px solid #bfdbfe;
    border-left: 4px solid #3b82f6;
    box-shadow: 0 2px 8px rgba(59,130,246,0.1);
}
#column-open .kanban-card:hover {
    border-left-color: #1d4ed8;
    box-shadow: 0 6px 20px rgba(59,130,246,0.22);
    transform: translateY(-2px);
}
#column-open.dragover {
    background: linear-gradient(160deg, #bfdbfe 0%, #dbeafe 100%);
    box-shadow: inset 0 0 0 2px #3b82f6;
}

/* ── IN PROGRESS — Amber ── */
#column-in-progress {
    background: linear-gradient(160deg, #fef3c7 0%, #fffbeb 60%, #fff7ed 100%);
    border-top: 4px solid #f59e0b;
}
#column-in-progress .kanban-column-header {
    background: linear-gradient(90deg, #b45309, #f59e0b);
    color: #fff;
}
#column-in-progress .column-count {
    background: rgba(255,255,255,0.25) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,0.4);
}
#column-in-progress .kanban-card {
    border: 1.5px solid #fde68a;
    border-left: 4px solid #f59e0b;
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

/* ── RESOLVED — Green ── */
#column-resolved {
    background: linear-gradient(160deg, #d1fae5 0%, #ecfdf5 60%, #f0fdf4 100%);
    border-top: 4px solid #10b981;
}
#column-resolved .kanban-column-header {
    background: linear-gradient(90deg, #065f46, #10b981);
    color: #fff;
}
#column-resolved .column-count {
    background: rgba(255,255,255,0.25) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,0.4);
}
#column-resolved .kanban-card {
    border: 1.5px solid #a7f3d0;
    border-left: 4px solid #10b981;
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

/* Slide-in panel container */
.ticket-panel-container {
    position: fixed !important;
    inset: 0 !important;
    z-index: 1000 !important;
}

/* Frosted panel */
.portrait-blur-backdrop {
    background: rgba(15, 23, 42, 0.2) !important;
    backdrop-filter: blur(2px) !important;
    -webkit-backdrop-filter: blur(2px) !important;
}
.portrait-blur-panel {
    background: rgba(255, 253, 248, 0.92) !important;
    backdrop-filter: blur(10px) !important;
    -webkit-backdrop-filter: blur(10px) !important;
    border-left: 1px solid rgba(0,0,0,0.08) !important;
    color: #1e293b !important;
}
/* Scope color overrides to panel body ONLY — not the dark header */
.portrait-blur-panel .panel-body h3,
.portrait-blur-panel .panel-body h5,
.portrait-blur-panel .panel-body span,
.portrait-blur-panel .panel-body p,
.portrait-blur-panel .panel-body div { color: #1e293b; }
.portrait-blur-panel .panel-body strong,
.portrait-blur-panel .panel-body label,
.portrait-blur-panel .panel-body .text-slate-400,
.portrait-blur-panel .panel-body .text-gray-400,
.portrait-blur-panel .panel-body .text-gray-500 { color: #475569 !important; }
.portrait-blur-panel .panel-body select,
.portrait-blur-panel .panel-body input[type="text"],
.portrait-blur-panel .panel-body textarea { background-color: #fff !important; color: #1e293b !important; border: 1px solid #cbd5e1 !important; }
.portrait-blur-panel .panel-body select option { background-color: #fff !important; color: #1e293b !important; }
.portrait-blur-panel .panel-body .bg-slate-950\/40 { background-color: rgba(241,245,249,0.7) !important; }
.portrait-blur-panel .panel-body .bg-blue-950\/40 { background-color: rgba(239,246,255,0.8) !important; }
.portrait-blur-panel .panel-body .bg-purple-950\/40 { background-color: rgba(250,245,255,0.8) !important; }
.portrait-blur-panel .panel-body .bg-amber-500\/10 { background-color: rgba(255,251,235,0.9) !important; border-color: rgba(245,158,11,0.3) !important; }
.portrait-blur-panel .panel-body .bg-emerald-950\/40 { background-color: rgba(236,253,245,0.8) !important; }
.portrait-blur-panel .panel-body .text-slate-100,
.portrait-blur-panel .panel-body .text-slate-200,
.portrait-blur-panel .panel-body .text-slate-300 { color: #334155 !important; }
.portrait-blur-panel .panel-body .border-white\/10 { border-color: rgba(0,0,0,0.08) !important; }
.portrait-blur-panel .panel-body button,
.portrait-blur-panel .panel-body button[type="submit"] { color: #fff !important; }
.portrait-blur-panel .panel-body button[name="update_priority"] { background-color: #d97706 !important; }
.portrait-blur-panel .panel-body button[name="send_reply"] { background-color: #7c3aed !important; }

/* Panel footer light theme */
.panel-footer {
    background: #f1f5f9 !important;
    border-top: 1px solid #e2e8f0 !important;
}
.panel-footer input[type="text"] {
    background: #fff !important; color: #1e293b !important;
    border: 1.5px solid #cbd5e1 !important;
}
.panel-footer input[type="text"]::placeholder { color: #94a3b8; }
.panel-footer button[name="send_reply"] {
    background: linear-gradient(135deg, #7c3aed, #4f46e5) !important; color: #fff !important;
}
.panel-footer button[name="close_ticket"] {
    background: rgba(254,226,226,0.8) !important;
    color: #dc2626 !important;
    border-color: rgba(239,68,68,0.3) !important;
}
.panel-footer .text-slate-400 { color: #64748b !important; }
.panel-footer .bg-slate-950\/60 { background: rgba(241,245,249,0.9) !important; }
.panel-footer .border-white\/10 { border-color: #e2e8f0 !important; }
.panel-footer .border-white\/5 { border-color: #e2e8f0 !important; }

/* Panel header — always white text on dark gradient */
.t-panel-header {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 60%, #7c3aed 100%) !important;
}
.t-panel-header,
.t-panel-header h3,
.t-panel-header span:not(.ticket-capsule):not(.tooltip),
.t-panel-header p,
.t-panel-header i,
.t-panel-header div,
.t-panel-header button { color: #ffffff !important; }
.t-panel-header .ticket-capsule {
    background: rgba(124,58,237,0.35) !important;
    color: #d8b4fe !important;
    border-color: rgba(167,139,250,0.4) !important;
}
.t-panel-header .ticket-capsule .copy-icon { color: #a78bfa !important; }
.t-panel-header .status-badge-resolved {
    background: rgba(16,185,129,0.25) !important;
    color: #6ee7b7 !important;
    border-color: rgba(16,185,129,0.4) !important;
}
.t-panel-header .status-badge-open {
    background: rgba(124,58,237,0.25) !important;
    color: #c4b5fd !important;
    border-color: rgba(124,58,237,0.4) !important;
}

/* Panel inner responsive width */
.t-panel-inner {
    position: absolute !important;
    right: 0 !important; top: 0 !important; bottom: 0 !important;
    width: 100% !important; max-width: 520px; height: 100% !important;
}
@media (max-width: 640px) { .t-panel-inner { max-width: 100% !important; } }
@media (min-width: 641px) and (max-width: 1024px) { .t-panel-inner { max-width: 420px; } }

/* Chat dynamic height */
.chat-messages-area {
    max-height: calc(100vh - 380px) !important;
    min-height: 150px; overflow-y: auto;
}

/* Chat Bubbles */
.chat-bubble-left {
    position: relative;
    background-color: rgba(239,246,255,0.9) !important;
    border: 1px solid #bfdbfe !important;
    border-radius: 16px;
    border-top-left-radius: 2px !important;
    color: #1e293b !important;
    animation: slideInLeft 0.3s ease-out;
}
.chat-bubble-right {
    position: relative;
    background-color: rgba(236,253,245,0.95) !important;
    border: 1px solid #a7f3d0 !important;
    border-radius: 16px;
    border-top-right-radius: 2px !important;
    color: #1e293b !important;
    animation: slideInRight 0.3s ease-out;
    margin-left: auto;
}
.chat-bubble-left::before { content:''; position:absolute; left:-7px; top:0; width:0; height:0; border-top:8px solid #bfdbfe; border-left:8px solid transparent; }
.chat-bubble-right::before { content:''; position:absolute; right:-7px; top:0; width:0; height:0; border-top:8px solid #a7f3d0; border-right:8px solid transparent; }
@keyframes slideInLeft { from { opacity:0; transform:translateX(-12px); } to { opacity:1; transform:translateX(0); } }
@keyframes slideInRight { from { opacity:0; transform:translateX(12px); } to { opacity:1; transform:translateX(0); } }
.date-divider { display:flex; align-items:center; text-align:center; margin:14px 0; color:#64748b !important; font-size:10px; font-weight:700; text-transform:uppercase; }
.date-divider::before,.date-divider::after { content:''; flex:1; border-bottom:1px solid rgba(0,0,0,0.08); }
.date-divider:not(:empty)::before { margin-right:.5em; }
.date-divider:not(:empty)::after { margin-left:.5em; }
.tick-mark { font-size:10px; color:#10b981 !important; margin-left:4px; font-weight:bold; }

/* ============================================================
   PREMIUM HEADER
   ============================================================ */
.header-premium {
    position: sticky;
    top: 0;
    z-index: 30;
    background: linear-gradient(100deg, #0f2233 0%, #1B3C53 35%, #234C6A 70%, #2e6080 100%);
    box-shadow: 0 2px 24px -4px rgba(10,30,50,0.55), 0 1px 0 rgba(255,255,255,0.06) inset;
    overflow: hidden;
}

/* subtle animated shimmer blobs */
.header-blob {
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
    opacity: 0.18;
    filter: blur(48px);
}
.header-blob-1 {
    width: 260px; height: 160px;
    top: -60px; left: -40px;
    background: radial-gradient(circle, #7c3aed, transparent 70%);
    animation: blobDrift 8s ease-in-out infinite alternate;
}
.header-blob-2 {
    width: 200px; height: 200px;
    top: -80px; right: 80px;
    background: radial-gradient(circle, #0ea5e9, transparent 70%);
    animation: blobDrift 11s ease-in-out infinite alternate-reverse;
}
@keyframes blobDrift {
    from { transform: translateY(0) scale(1); }
    to   { transform: translateY(18px) scale(1.08); }
}

.header-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.25rem 1.75rem;
    min-height: 110px;
}
@media (max-width: 640px) {
    .header-inner {
        padding: 1rem 1rem 0.85rem;
        min-height: unset;
        flex-wrap: wrap;
        gap: 0.6rem;
    }
}

/* --- LEFT --- */
.header-left {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    flex: 1;
    min-width: 0;
}
.header-hamburger {
    background: none;
    border: none;
    color: rgba(255,255,255,0.75);
    font-size: 1.15rem;
    cursor: pointer;
    padding: 0.3rem;
    transition: color 0.2s;
}
.header-hamburger:hover { color: #fff; }

.header-icon-wrap {
    width: 42px; height: 42px;
    border-radius: 12px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.22);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.15);
    flex-shrink: 0;
}
.header-icon-wrap i {
    font-size: 1.1rem;
    color: #fff;
    filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3));
}

.header-title-group { line-height: 1; min-width: 0; flex: 1; }
.header-title {
    font-size: 1.25rem;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.01em;
    margin: 0 0 4px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.3);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.header-subtitle {
    font-size: 0.8rem;
    color: rgba(186,220,255,0.8);
    font-weight: 500;
    margin: 0 0 6px;
    letter-spacing: 0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.header-status-pills {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 4px;
}
.hpill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    border: 1px solid rgba(255,255,255,0.15);
    background: rgba(255,255,255,0.1);
    color: #fff;
}
.hpill-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
@media (max-width: 640px) {
    .header-title { font-size: 1rem; white-space: normal; }
    .header-subtitle { font-size: 0.72rem; white-space: normal; }
    .header-status-pills { display: flex; }
}

/* --- CENTER breadcrumb pill --- */
.header-breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 999px;
    padding: 0.35rem 1rem;
    backdrop-filter: blur(6px);
    flex-shrink: 0;
}
.header-bc-home {
    font-size: 0.68rem;
    color: rgba(255,255,255,0.5);
}
.header-bc-sep {
    font-size: 0.7rem;
    color: rgba(255,255,255,0.3);
    line-height: 1;
}
.header-bc-item {
    font-size: 0.72rem;
    font-weight: 600;
    color: rgba(255,255,255,0.6);
}
.header-bc-active {
    color: #fff !important;
    font-weight: 700;
}

/* --- RIGHT profile card --- */
.header-profile {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.13);
    border-radius: 14px;
    padding: 0.45rem 0.75rem 0.45rem 0.85rem;
    backdrop-filter: blur(8px);
    box-shadow: 0 2px 12px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
    flex-shrink: 0;
    transition: background 0.2s;
}
@media (max-width: 640px) {
    .header-profile {
        padding: 0.35rem 0.6rem;
        border-radius: 10px;
    }
}
.header-profile:hover {
    background: rgba(255,255,255,0.11);
}

.header-profile-text {
    text-align: right;
    line-height: 1;
}
.header-admin-name {
    display: block;
    font-size: 0.82rem;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.01em;
    text-shadow: 0 1px 3px rgba(0,0,0,0.3);
    margin-bottom: 2px;
}
.header-admin-role {
    display: block;
    font-size: 0.62rem;
    font-weight: 700;
    color: rgba(186,220,255,0.65);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 4px;
}
.header-online-row {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.35rem;
}
.header-online-label {
    font-size: 0.66rem;
    font-weight: 700;
    color: #4ade80;
    letter-spacing: 0.03em;
}

.header-avatar-wrap {
    position: relative;
    flex-shrink: 0;
}
.header-avatar {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 3px 10px rgba(124,58,237,0.45), 0 0 0 2px rgba(255,255,255,0.2);
    transition: box-shadow 0.2s;
}
.header-profile:hover .header-avatar {
    box-shadow: 0 4px 14px rgba(124,58,237,0.6), 0 0 0 3px rgba(255,255,255,0.3);
}
.header-avatar i {
    font-size: 0.95rem;
    color: #fff;
}
.header-avatar-badge {
    position: absolute;
    bottom: 0; right: 0;
    width: 11px; height: 11px;
    background: #22c55e;
    border-radius: 50%;
    border: 2px solid #1B3C53;
    box-shadow: 0 0 0 2px rgba(34,197,94,0.35);
}

/* Online dot (used in header) */
.online-dot {
    width: 7px; height: 7px;
    background: #4ade80;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 6px rgba(74,222,128,0.7);
    animation: pulse-dot 2s infinite;
}
@keyframes pulse-dot {
    0%,100% { box-shadow: 0 0 0 0 rgba(74,222,128,0.5); }
    50%      { box-shadow: 0 0 0 5px rgba(74,222,128,0); }
}

/* Responsive */
@media (max-width: 768px) {
    .kpi-grid { grid-template-columns: repeat(2,1fr); }
    .filter-card .filter-form .filter-group { flex: 1 1 100%; }
    .filter-actions { flex: 1 1 100%; justify-content: flex-end; }
    .header-inner { padding: 1rem 1rem 0.85rem; }
    .header-breadcrumb { display: none !important; }
}
@media (max-width: 480px) {
    .kpi-grid { grid-template-columns: 1fr; }
}
</style>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out relative overflow-hidden" style="background: #eef2f7;">
    <div class="absolute inset-0 pointer-events-none" style="background: radial-gradient(900px 300px at 20% 0%, rgba(27, 60, 83, 0.06), transparent 60%), radial-gradient(700px 260px at 75% 15%, rgba(69, 104, 130, 0.07), transparent 55%);"></div>
    <!-- Header -->
    <!-- ===== PREMIUM HEADER ===== -->
    <header class="sticky top-0 z-30 header-premium">
        <!-- Decorative blobs -->
        <span class="header-blob header-blob-1"></span>
        <span class="header-blob header-blob-2"></span>

        <div class="header-inner">
            <!-- LEFT: hamburger + icon + title -->
            <div class="header-left">
                <button class="md:hidden header-hamburger flex items-center justify-center w-10 h-10 rounded-xl focus:outline-none transition-all active:scale-95"
                        style="background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.25);"
                        onclick="toggleSidebar()" aria-label="Open navigation menu">
                    <i class="fas fa-bars text-white text-base"></i>
                </button>
                <div class="header-icon-wrap">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="header-title-group">
                    <h1 class="header-title">Trainer Support Tickets</h1>
                    <p class="header-subtitle">Manage &amp; resolve trainer support requests</p>
                    <div class="header-status-pills">
                        <span class="hpill">
                            <span class="hpill-dot" style="background:#f59e0b;"></span>
                            <?= (int)$open_count ?> Open
                        </span>
                        <span class="hpill">
                            <span class="hpill-dot" style="background:#10b981;"></span>
                            <?= (int)$resolved_count ?> Resolved
                        </span>
                        <?php if ($high_priority_count > 0): ?>
                        <span class="hpill">
                            <i class="fas fa-exclamation-triangle" style="font-size:8px;"></i>
                            <?= (int)$high_priority_count ?> Urgent
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- CENTER: breadcrumb pill -->
            <div class="header-breadcrumb hidden lg:flex">
                <i class="fas fa-home header-bc-home"></i>
                <span class="header-bc-sep">/</span>
                <span class="header-bc-item">Support</span>
                <span class="header-bc-sep">/</span>
                <span class="header-bc-item header-bc-active">Trainer Tickets</span>
            </div>

            <!-- RIGHT: admin card -->
            <div class="header-profile">
                <div class="header-profile-text hidden sm:block">
                    <span class="header-admin-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
                    <span class="header-admin-role">System Administrator</span>
                    <span class="header-online-row">
                        <span class="online-dot"></span>
                        <span class="header-online-label">Online</span>
                    </span>
                </div>
                <div class="header-avatar-wrap">
                    <div class="header-avatar">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <span class="header-avatar-badge"></span>
                </div>
            </div>
        </div>
    </header>
    <!-- ===== /PREMIUM HEADER ===== -->

    <!-- Mobile Floating Sidebar Button -->
    <div class="md:hidden fixed bottom-5 left-5 z-[999]" id="mobile-sidebar-fab">
        <button onclick="toggleSidebar()"
                class="flex items-center justify-center w-14 h-14 rounded-full shadow-2xl active:scale-95 transition-all"
                style="background: linear-gradient(135deg, #1B3C53, #7c3aed); border: 2px solid rgba(210,193,182,0.3);"
                aria-label="Open sidebar navigation">
            <i class="fas fa-bars text-white text-lg"></i>
        </button>
    </div>

    <div class="p-3 md:p-5">
        <?php if ($tmsg): ?>
            <div class="mb-6 p-4 rounded-xl shadow-sm flex items-center border-l-4
                <?= $tmsg['type'] === 'success' ? 'bg-emerald-50 text-emerald-800 border-emerald-500/70' : 'bg-red-50 text-red-800 border-red-500/70' ?>">

                <i class="fas <?= $tmsg['type'] === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500' ?> mr-3 text-lg"></i>
                <span class="font-medium"><?= htmlspecialchars($tmsg['text']) ?></span>
            </div>
        <?php endif; ?>

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

        <!-- Enhanced Filter Section (with border, shadow, highlighted search) -->
        <div class="filter-card">
            <form method="GET" action="trainer_tickets.php" class="filter-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" name="search" id="search" value="<?= htmlspecialchars($search) ?>" placeholder="🔍  Trainer name or category..." class="search-enhanced">
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
                    <label for="priority">Priority</label>
                    <select name="priority" id="priority">
                        <option value="all" <?= $priority_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="low" <?= strtolower($priority_filter) === 'low' ? 'selected' : '' ?>>Low</option>
                        <option value="medium" <?= strtolower($priority_filter) === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="high" <?= strtolower($priority_filter) === 'high' ? 'selected' : '' ?>>High</option>
                        <option value="urgent" <?= strtolower($priority_filter) === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="sort_by">Sort By</label>
                    <select name="sort_by" id="sort_by">
                        <option value="date_desc" <?= $sort_by === 'date_desc' ? 'selected' : '' ?>>Date (Newest)</option>
                        <option value="priority_desc" <?= $sort_by === 'priority_desc' ? 'selected' : '' ?>>Priority (Urgent First)</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Apply</button>
                    <?php if (!empty($search) || $status_filter !== 'all' || $priority_filter !== 'all' || $sort_by !== 'date_desc'): ?>
                        <a href="trainer_tickets.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- View Toggle & Bulk Actions -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 mt-3">
            <div class="flex items-center gap-3">
                <div class="text-sm font-semibold text-gray-500">
                    Found <span class="text-purple-600 font-bold"><?= count($tickets) ?></span> trainer tickets
                </div>
                <div id="bulk-action-bar" class="hidden flex items-center gap-2 bg-purple-50 border border-purple-200/60 px-3.5 py-1.5 rounded-xl animate-fade-in transition-all">
                    <span class="text-xs font-bold text-purple-700"><span id="selected-count">0</span> selected:</span>
                    <button type="button" onclick="triggerBulkClose()" class="btn-hover px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-[11px] font-bold rounded-xl flex items-center gap-1 shadow-sm transition-all">
                        <i class="fas fa-times-circle"></i> Close Selected
                    </button>
                    <div class="flex items-center gap-1.5 pl-2 border-l border-purple-200">
                        <select id="bulk-priority-select" class="px-2 py-1 text-[11px] font-semibold bg-white border border-gray-300 rounded-lg focus:outline-none">
                            <option value="Low">Low</option>
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                        </select>
                        <button type="button" onclick="triggerBulkPriority()" class="btn-hover px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-[11px] font-bold rounded-xl shadow-sm transition-all">
                            Set Priority
                        </button>
                    </div>
                </div>
            </div>
            <div class="flex items-center bg-gray-200/60 p-1 rounded-xl border border-gray-300/40">
                <button type="button" onclick="switchViewMode('table')" id="view-toggle-table" class="px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all bg-white text-gray-800 shadow-sm border border-gray-200/50">
                    <i class="fas fa-table"></i> Table View
                </button>
                <button type="button" onclick="switchViewMode('kanban')" id="view-toggle-kanban" class="px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all text-gray-500 hover:text-gray-800">
                    <i class="fas fa-columns"></i> Kanban Board
                </button>
            </div>
        </div>

        <!-- Table View Container -->
        <div id="tickets-table-view" class="mt-1">
            <!-- Table (elevated white card wrapper) -->
            <div class="bg-white rounded-[16px] shadow-[0_18px_40px_-30px_rgba(2,6,23,0.35)] border border-slate-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-8">
                                <input type="checkbox" id="select-all-tickets" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4 cursor-pointer" onclick="toggleSelectAll(this)">
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Priority</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date Raised</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Age / SLA</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Trainer</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="9" class="px-6 py-16 text-center text-gray-500">
                                    <div class="bg-gray-50 inline-block p-5 rounded-full mb-3">
                                        <i class="fas fa-chalkboard-teacher text-gray-300 text-3xl"></i>
                                    </div>
                                    <p class="font-semibold text-gray-700">No trainer tickets found</p>
                                    <p class="text-xs text-gray-400 mt-1">Trainers haven't raised any support tickets yet.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <!-- Main Row -->
                                <tr class="hover:bg-gray-50/50 cursor-pointer trow transition-colors" data-id="<?= $ticket['id'] ?>">
                                    <td class="px-6 py-4 whitespace-nowrap w-8" onclick="event.stopPropagation()">
                                        <input type="checkbox" value="<?= $ticket['id'] ?>" class="ticket-checkbox rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4 cursor-pointer" onclick="updateBulkBar()">
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="ticket-capsule relative cursor-pointer group" onclick="event.stopPropagation(); copyTicketId(<?= $ticket['id'] ?>, this)">
                                            🎫 #<?= $ticket['id'] ?>
                                            <i class="far fa-copy text-xs ml-1 text-purple-400 group-hover:text-purple-700 copy-icon"></i>
                                            <span class="tooltip hidden absolute -top-8 left-1/2 -translate-x-1/2 bg-black text-white text-[10px] px-2 py-1 rounded shadow-md z-50">Copied!</span>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?= getTrainerPriorityBadge($ticket['priority'] ?? '') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?= getTicketAgeBadge($ticket['created_at']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($ticket['trainer_name']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($ticket['trainer_email']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-700"><?= get_category_chip($ticket['reason']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?= getTrainerStatusBadge($ticket['status'] ?? '') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" onclick="event.stopPropagation()">
                                        <div class="flex items-center justify-end space-x-2">
                                            <button onclick="toggleTDetail(<?= $ticket['id'] ?>)" class="btn-hover px-3.5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-semibold flex items-center space-x-1.5">
                                                <i class="fas fa-comments"></i><span>Chat</span>
                                            </button>
                                            <?php if ($ticket['status'] === 'open'): ?>
                                                <form method="POST" action="trainer_tickets.php" onsubmit="return confirm('Close this ticket?');" class="inline">
                                                    <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                    <button type="submit" name="close_ticket" class="btn-red-hover px-3.5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-semibold flex items-center space-x-1.5">
                                                        <i class="fas fa-times-circle"></i><span>Close</span>
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
            if ($has_replies) {
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
                                <?php
                                $p = strtolower($t['priority'] ?? 'low');
                                if ($p === 'urgent') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-200">🔴 Urgent</span>';
                                elseif ($p === 'high') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-orange-100 text-orange-800 border border-orange-200">🟠 High</span>';
                                elseif ($p === 'medium') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-800 border border-yellow-200">🟡 Medium</span>';
                                else echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800 border border-green-200">🟢 Low</span>';
                                ?>
                            </div>
                            <div class="font-bold text-gray-800 text-sm mb-1"><?= htmlspecialchars($t['trainer_name']) ?></div>
                            <div class="text-[10px] text-gray-500 mb-2"><?= htmlspecialchars($t['trainer_email']) ?></div>
                            
                            <div class="mb-3 flex flex-wrap gap-1.5 items-center">
                                <?= get_category_chip($t['reason']) ?>
                                <?= getTicketAgeBadge($t['created_at']) ?>
                            </div>
                            
                            <div class="flex justify-between items-center border-t border-gray-100 pt-2 mt-2">
                                <span></span>
                                <button onclick="toggleTDetail(<?= $t['id'] ?>)" class="text-purple-600 hover:text-purple-800 text-xs font-bold flex items-center gap-1">
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
                                <?php
                                $p = strtolower($t['priority'] ?? 'low');
                                if ($p === 'urgent') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-200">🔴 Urgent</span>';
                                elseif ($p === 'high') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-orange-100 text-orange-800 border border-orange-200">🟠 High</span>';
                                elseif ($p === 'medium') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-800 border border-yellow-200">🟡 Medium</span>';
                                else echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800 border border-green-200">🟢 Low</span>';
                                ?>
                            </div>
                            <div class="font-bold text-gray-800 text-sm mb-1"><?= htmlspecialchars($t['trainer_name']) ?></div>
                            <div class="text-[10px] text-gray-500 mb-2"><?= htmlspecialchars($t['trainer_email']) ?></div>
                            
                            <div class="mb-3 flex flex-wrap gap-1.5 items-center">
                                <?= get_category_chip($t['reason']) ?>
                                <?= getTicketAgeBadge($t['created_at']) ?>
                            </div>
                            
                            <div class="flex justify-between items-center border-t border-gray-100 pt-2 mt-2">
                                <span></span>
                                <button onclick="toggleTDetail(<?= $t['id'] ?>)" class="text-purple-600 hover:text-purple-800 text-xs font-bold flex items-center gap-1">
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
                                <?php
                                $p = strtolower($t['priority'] ?? 'low');
                                if ($p === 'urgent') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-200">🔴 Urgent</span>';
                                elseif ($p === 'high') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-orange-100 text-orange-800 border border-orange-200">🟠 High</span>';
                                elseif ($p === 'medium') echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-800 border border-yellow-200">🟡 Medium</span>';
                                else echo '<span class="px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800 border border-green-200">🟢 Low</span>';
                                ?>
                            </div>
                            <div class="font-bold text-gray-800 text-sm mb-1"><?= htmlspecialchars($t['trainer_name']) ?></div>
                            <div class="text-[10px] text-gray-500 mb-2"><?= htmlspecialchars($t['trainer_email']) ?></div>
                            
                            <div class="mb-3 flex flex-wrap gap-1.5 items-center">
                                <?= get_category_chip($t['reason']) ?>
                                <?= getTicketAgeBadge($t['created_at']) ?>
                            </div>
                            
                            <div class="flex justify-between items-center border-t border-gray-100 pt-2 mt-2">
                                <span></span>
                                <button onclick="toggleTDetail(<?= $t['id'] ?>)" class="text-purple-600 hover:text-purple-800 text-xs font-bold flex items-center gap-1">
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
    <!-- Slide-in Details Panel -->
    <div id="tdetail-<?= $ticket['id'] ?>" class="ticket-panel-container fixed inset-0 z-[1000] hidden transition-all duration-300">
        <!-- Backdrop (Frosted portrait-camera blur) -->
        <div class="absolute inset-0 portrait-blur-backdrop pointer-events-auto transition-opacity duration-300" onclick="closeTDetail(<?= $ticket['id'] ?>)"></div>
        <!-- Panel -->
        <div class="t-panel-inner portrait-blur-panel flex flex-col pointer-events-auto transform translate-x-full transition-transform duration-300 z-10" onclick="event.stopPropagation()">
            <!-- Header -->
            <div class="t-panel-header px-6 py-5 border-b border-white/10 flex items-center justify-between shrink-0">
                <div class="flex flex-col text-left">
                    <div class="flex items-center gap-2">
                        <span class="ticket-capsule cursor-pointer relative group" onclick="copyTicketId(<?= $ticket['id'] ?>, this)" style="color:#d8b4fe !important;">
                            🎫 #<?= $ticket['id'] ?>
                            <i class="far fa-copy text-xs ml-1 copy-icon" style="color:#a78bfa !important;"></i>
                            <span class="tooltip hidden absolute -top-8 left-1/2 -translate-x-1/2 bg-black text-white text-[10px] px-2 py-1 rounded shadow-md z-50">Copied!</span>
                        </span>
                        <span class="text-sm font-bold" style="color:#fff !important;">Ticket Details</span>
                        <?php
                        $st = strtolower(trim($ticket['status'] ?? 'open'));
                        if ($st === 'resolved') {
                            echo '<span class="status-badge-resolved text-xs px-2.5 py-0.5 rounded-full font-semibold uppercase tracking-wider" style="background:rgba(16,185,129,0.25);color:#6ee7b7 !important;border:1px solid rgba(16,185,129,0.4);">Resolved</span>';
                        } else {
                            echo '<span class="status-badge-open text-xs px-2.5 py-0.5 rounded-full font-semibold uppercase tracking-wider" style="background:rgba(124,58,237,0.25);color:#c4b5fd !important;border:1px solid rgba(124,58,237,0.4);">Open</span>';
                        }
                        ?>
                    </div>
                    <span class="text-xs mt-1.5" style="color:rgba(255,255,255,0.7) !important;">Trainer: <?= htmlspecialchars($ticket['trainer_name']) ?> (<?= htmlspecialchars($ticket['trainer_email']) ?>)</span>
                </div>
                <button onclick="toggleTDetail(<?= $ticket['id'] ?>)" class="transition-colors p-1" style="color:rgba(255,255,255,0.6) !important; background:none; border:none;">
                    <i class="fas fa-times text-xl" style="color:inherit !important;"></i>
                </button>
            </div>

            <!-- Content Area (Scrollable) -->
            <div class="panel-body flex-1 overflow-y-auto p-6 space-y-6">
                <!-- Metadata Grid -->
                <div class="grid grid-cols-2 gap-4 bg-white/5 p-4 rounded-xl border border-white/5">
                    <div class="text-left">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Category</span>
                        <div class="mt-1"><?= get_category_chip($ticket['reason']) ?></div>
                    </div>
                    <div class="text-left">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Created At</span>
                        <span class="text-xs text-slate-300 font-medium"><?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?></span>
                    </div>
                </div>

                <!-- Priority Management Dropdown -->
                <div class="bg-amber-500/10 p-4 rounded-xl border border-amber-500/20 text-left">
                    <span class="text-[10px] font-bold text-amber-400 uppercase tracking-wider block mb-2">Change Priority Level</span>
                    <form method="POST" action="trainer_tickets.php" class="flex gap-2">
                        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                        <select name="priority" class="flex-1 px-3 py-2 text-sm border border-white/10 rounded-xl focus:ring-2 focus:ring-amber-500 bg-slate-800 text-white">
                            <option class="bg-slate-900" value="Low" <?= strcasecmp($ticket['priority'] ?? 'low', 'low') == 0 ? 'selected' : '' ?>>Low</option>
                            <option class="bg-slate-900" value="Medium" <?= strcasecmp($ticket['priority'] ?? 'low', 'medium') == 0 ? 'selected' : '' ?>>Medium</option>
                            <option class="bg-slate-900" value="High" <?= strcasecmp($ticket['priority'] ?? 'low', 'high') == 0 ? 'selected' : '' ?>>High</option>
                            <option class="bg-slate-900" value="Urgent" <?= strcasecmp($ticket['priority'] ?? 'low', 'urgent') == 0 ? 'selected' : '' ?>>Urgent</option>
                        </select>
                        <button type="submit" name="update_priority" class="btn-hover px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-xl text-xs shadow-sm">
                            Update
                        </button>
                    </form>
                </div>

                <!-- Attachment -->
                <?php if (!empty($ticket['attachment_path'])): ?>
                    <div class="text-left">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-2">Attachment</span>
                        <a href="../<?= htmlspecialchars($ticket['attachment_path']) ?>" target="_blank" class="inline-flex items-center space-x-2 text-xs font-semibold text-purple-300 hover:text-purple-200 bg-purple-500/10 hover:bg-purple-500/20 px-3.5 py-2.5 rounded-xl transition-colors border border-purple-500/20 w-full justify-center">
                            <i class="fas fa-paperclip"></i><span>View / Download Attachment</span>
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Conversation Section -->
                <div>
                    <h5 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3 text-left">Conversation</h5>
                    <div class="chat-messages-area space-y-4 mb-4 p-4 bg-slate-950/40 rounded-xl border border-white/5 flex flex-col">
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

                        <!-- Original Message -->
                        <div class="flex items-start gap-2 max-w-[85%]">
                            <div class="w-8 h-8 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30 flex items-center justify-center text-xs font-bold shrink-0">T</div>
                            <div class="chat-bubble-left p-3 text-left">
                                <div class="flex items-center space-x-2 mb-1">
                                    <span class="text-xs font-bold text-purple-600"><?= htmlspecialchars($ticket['trainer_name']) ?> (Trainer)</span>
                                </div>
                                <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($ticket['description'] ?: 'No description provided.') ?></p>
                                <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium">
                                    <?= date('h:i A', strtotime($ticket['created_at'])) ?>
                                </div>
                            </div>
                        </div>

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

                                $is_trainer = ($msg['sender_role'] === 'mentor');
                                $init = $is_trainer ? 'T' : 'A';
                                $who = $is_trainer ? htmlspecialchars($msg['sender_name']) . ' (Trainer)' : htmlspecialchars($msg['sender_name']) . ' (Admin)';
                            ?>
                            
                            <?php if ($is_trainer): ?>
                                <!-- Left Aligned message (Trainer) -->
                                <div class="flex items-start gap-2 max-w-[85%]">
                                    <div class="w-8 h-8 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30 flex items-center justify-center text-xs font-bold shrink-0"><?= $init ?></div>
                                    <div class="chat-bubble-left p-3 text-left">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <span class="text-xs font-bold text-purple-600"><?= $who ?></span>
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
                                            <span class="text-xs font-bold text-emerald-600"><?= $who ?></span>
                                        </div>
                                        <p class="text-sm whitespace-pre-wrap leading-relaxed text-slate-800"><?= htmlspecialchars($msg['message']) ?></p>
                                        <div class="text-[9px] text-slate-500 mt-1.5 text-right font-medium flex items-center justify-end gap-1">
                                            <?= date('h:i A', strtotime($msg['created_at'])) ?>
                                            <span class="tick-mark">✓✓</span>
                                        </div>
                                    </div>
                                    <div class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 flex items-center justify-center text-xs font-bold shrink-0"><?= $init ?></div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div><!-- /panel-body -->

            <!-- Footer Area (Actions/Reply Form) -->
            <div class="panel-footer p-6 shrink-0">
                <?php if ($ticket['status'] === 'open'): ?>
                    <form method="POST" action="trainer_tickets.php" class="flex flex-col gap-3">
                        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                        <div class="flex gap-2">
                            <input type="text" name="message" required placeholder="Type reply to trainer..." class="flex-1 px-4 py-2.5 text-sm border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-purple-500 bg-slate-800 text-white">
                            <button type="submit" name="send_reply" class="btn-hover px-5 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm flex items-center space-x-1 shrink-0">
                                <i class="fas fa-paper-plane"></i><span>Reply</span>
                            </button>
                        </div>
                    </form>
                    <div class="mt-3 flex items-center justify-between border-t border-white/10 pt-3">
                        <span class="text-xs text-slate-400">Or close ticket if resolved:</span>
                        <form method="POST" action="trainer_tickets.php" onsubmit="return confirm('Close this ticket?');">
                            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                            <button type="submit" name="close_ticket" class="btn-red-hover px-4 py-2 bg-red-950/40 hover:bg-red-950/60 text-red-400 hover:text-red-300 font-semibold rounded-xl text-xs flex items-center space-x-1.5 border border-red-500/20 shadow-sm">
                                <i class="fas fa-times-circle"></i><span>Close Ticket</span>
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="flex flex-col gap-2 p-3 bg-slate-950/60 rounded-xl border border-white/5 text-left">
                        <span class="text-xs font-semibold text-slate-400 flex items-center">
                            <i class="fas fa-lock mr-1.5 text-slate-500"></i> Ticket is closed. Chat is disabled.
                        </span>
                        <span class="text-[10px] text-slate-400">Closed on: <strong><?= date('d M Y, h:i A', strtotime($ticket['resolved_at'])) ?></strong></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php endforeach; ?>


<!-- Bulk Action Confirmation Modal (Trainer Tickets) -->
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

<!-- Bulk Action Hidden Form (Trainer Tickets) -->
<form id="bulk-action-form" method="POST" action="trainer_tickets.php" class="hidden">
    <input type="hidden" name="bulk_action" value="1">
    <input type="hidden" name="bulk_action_type" id="bulk-action-type" value="">
    <input type="hidden" name="ticket_ids" id="bulk-ticket-ids" value="">
    <input type="hidden" name="bulk_priority" id="bulk-priority-value" value="">
</form>

<script>
function toggleTDetail(id) {
    const panel = document.getElementById('tdetail-' + id);
    if (!panel) return;

    // Close any other open panels
    document.querySelectorAll('.ticket-panel-container').forEach(el => {
        if (el.id !== 'tdetail-' + id && !el.classList.contains('hidden')) {
            const otherId = el.id.replace('tdetail-', '');
            closeTDetail(otherId);
        }
    });

    if (panel.classList.contains('hidden')) {
        // Move to body to escape any parent overflow clipping
        if (panel.parentNode !== document.body) {
            document.body.appendChild(panel);
        }
        panel.classList.remove('hidden');
        panel.offsetHeight; // reflow
        const backdrop = panel.querySelector('.absolute.inset-0');
        const panelBody = panel.querySelector('.t-panel-inner');
        if (backdrop) {
            backdrop.style.opacity = '0';
            backdrop.style.transition = 'opacity 0.3s ease';
            backdrop.offsetHeight;
            backdrop.style.opacity = '1';
        }
        if (panelBody) panelBody.classList.remove('translate-x-full');
        // Hide mobile FAB
        const fab = document.getElementById('mobile-sidebar-fab');
        if (fab) fab.style.display = 'none';
        // Scroll chat to bottom
        const chatArea = panel.querySelector('.chat-messages-area');
        if (chatArea) setTimeout(() => { chatArea.scrollTop = chatArea.scrollHeight; }, 350);
    } else {
        closeTDetail(id);
    }
}

function closeTDetail(id) {
    const panel = document.getElementById('tdetail-' + id);
    if (!panel) return;
    const backdrop = panel.querySelector('.absolute.inset-0');
    const panelBody = panel.querySelector('.t-panel-inner');
    if (backdrop) backdrop.style.opacity = '0';
    if (panelBody) panelBody.classList.add('translate-x-full');
    setTimeout(() => {
        panel.classList.add('hidden');
        // Restore mobile FAB
        const fab = document.getElementById('mobile-sidebar-fab');
        if (fab) fab.style.display = '';
    }, 300);
}

function copyTicketId(id, element) {
    navigator.clipboard.writeText(id).then(() => {
        const icon = element.querySelector('.copy-icon');
        const tooltip = element.querySelector('.tooltip');
        
        icon.className = 'fas fa-spinner fa-spin text-green-500 text-xs ml-1 copy-icon';
        setTimeout(() => {
            icon.className = 'fas fa-check text-green-500 text-xs ml-1 copy-icon';
        }, 200);
        
        tooltip.classList.remove('hidden');
        
        setTimeout(() => {
            icon.className = 'far fa-copy text-xs ml-1 text-purple-400 copy-icon';
            tooltip.classList.add('hidden');
        }, 1500);
    }).catch(err => {
        console.error('Failed to copy: ', err);
    });
}

document.querySelectorAll('.trow').forEach(row => {
    row.addEventListener('click', function(e) {
        if (e.target.closest('button') || e.target.closest('a') || e.target.closest('form') || e.target.closest('.ticket-capsule')) return;
        toggleTDetail(this.dataset.id);
    });
});

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
        localStorage.setItem('trainer_tickets_view_mode', 'kanban');
    } else {
        kanbanView.classList.add('hidden');
        tableView.classList.remove('hidden');
        
        toggleKanbanBtn.className = "px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all text-gray-500 hover:text-gray-800";
        toggleTableBtn.className = "px-4 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all bg-white text-gray-800 shadow-sm border border-gray-200/50";
        localStorage.setItem('trainer_tickets_view_mode', 'table');
    }
}

// Initialize View Mode on Load
document.addEventListener('DOMContentLoaded', () => {
    const savedMode = localStorage.getItem('trainer_tickets_view_mode') || 'table';
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
        
        fetch('trainer_tickets.php', {
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

function triggerBulkPriority() {
    const selected = Array.from(document.querySelectorAll('.ticket-checkbox:checked')).map(cb => cb.value);
    if (!selected.length) { alert('Please select at least one ticket.'); return; }
    const priority = document.getElementById('bulk-priority-select')?.value || 'Medium';
    document.getElementById('bulk-action-type').value = 'priority';
    document.getElementById('bulk-ticket-ids').value = selected.join(',');
    document.getElementById('bulk-priority-value').value = priority;
    document.getElementById('bulk-modal-title').textContent = 'Set Priority';
    document.getElementById('bulk-modal-desc').textContent = `Set priority to "${priority}" for ${selected.length} ticket(s)?`;
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