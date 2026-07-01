<?php
session_start();

// Verify trainer login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../logout.php");
    exit;
}

require_once '../db_connection.php';

$trainer_user_id = $_SESSION['user_id'];

// Get trainer details for sidebar
$trainer_stmt = $db->prepare("SELECT * FROM trainers t JOIN users u ON t.user_id = u.id WHERE u.id = ?");
$trainer_stmt->execute([$trainer_user_id]);
$trainer = $trainer_stmt->fetch(PDO::FETCH_ASSOC);

// Handle new ticket submission (My Tickets)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    $reason = trim($_POST['reason'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (!empty($reason) && !empty($description)) {
        try {
            $stmt = $db->prepare("INSERT INTO trainer_tickets (trainer_user_id, reason, description, status) VALUES (?, ?, ?, 'open')");
            $stmt->execute([$trainer_user_id, $reason, $description]);
            
            // Notify admins
            $ticket_id = $db->lastInsertId();
            $admin_stmt = $db->query("SELECT id FROM users WHERE role = 'admin'");
            $admins = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($admins)) {
                $notif = $db->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) VALUES (?, 'ticket', 'New Trainer Ticket', ?, ?, 0)");
                foreach ($admins as $aid) {
                    $notif->execute([$aid, "Trainer {$trainer['name']} raised a new ticket regarding '$reason'.", $ticket_id]);
                }
            }
            $_SESSION['tmsg'] = ['type' => 'success', 'text' => 'Ticket created successfully!'];
        } catch (PDOException $e) {
            $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'Database error: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'Reason and Description are required.'];
    }
    header("Location: tickets.php");
    exit();
}

// Handle reply to own ticket (My Tickets)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_my_ticket'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $message = trim($_POST['message'] ?? '');
    
    if (!empty($message)) {
        try {
            // Verify ownership
            $chk = $db->prepare("SELECT id FROM trainer_tickets WHERE id = ? AND trainer_user_id = ?");
            $chk->execute([$ticket_id, $trainer_user_id]);
            if ($chk->rowCount() > 0) {
                $ins = $db->prepare("INSERT INTO trainer_ticket_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)");
                $ins->execute([$ticket_id, $trainer_user_id, $message]);
                $_SESSION['tmsg'] = ['type' => 'success', 'text' => 'Reply sent successfully!'];
            }
        } catch (PDOException $e) {
            $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'Database error: ' . $e->getMessage()];
        }
    }
    header("Location: tickets.php");
    exit();
}

// Handle close own ticket (My Tickets)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_my_ticket'])) {
    $ticket_id = intval($_POST['ticket_id']);
    try {
        $upd = $db->prepare("UPDATE trainer_tickets SET status='resolved', resolved_at=NOW(), resolved_by=? WHERE id=? AND trainer_user_id=?");
        $upd->execute([$trainer_user_id, $ticket_id, $trainer_user_id]);
        $_SESSION['tmsg'] = ['type' => 'success', 'text' => 'Ticket closed successfully!'];
    } catch (PDOException $e) {
        $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'Database error: ' . $e->getMessage()];
    }
    header("Location: tickets.php");
    exit();
}

// Handle reply to assigned student ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_student_ticket'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $message = trim($_POST['message'] ?? '');
    
    if (!empty($message)) {
        try {
            // Verify assignment
            $chk = $db->prepare("SELECT id, student_id, reason FROM tickets WHERE id = ? AND assigned_to_trainer_id = ?");
            $chk->execute([$ticket_id, $trainer_user_id]);
            $s_ticket = $chk->fetch(PDO::FETCH_ASSOC);
            
            if ($s_ticket) {
                $ins = $db->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)");
                $ins->execute([$ticket_id, $trainer_user_id, $message]);
                
                // Notify student
                $st_stmt = $db->prepare("SELECT user_id FROM students WHERE student_id = ?");
                $st_stmt->execute([$s_ticket['student_id']]);
                $st = $st_stmt->fetch(PDO::FETCH_ASSOC);
                if ($st) {
                    $notif = $db->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) VALUES (?, 'ticket', 'Trainer Replied to Ticket', ?, ?, 0)");
                    $notif->execute([$st['user_id'], "Trainer {$trainer['name']} replied to your ticket regarding '{$s_ticket['reason']}'.", $ticket_id]);
                }
                
                $_SESSION['tmsg'] = ['type' => 'success', 'text' => 'Reply to student ticket sent!'];
            }
        } catch (PDOException $e) {
            $_SESSION['tmsg'] = ['type' => 'error', 'text' => 'Database error: ' . $e->getMessage()];
        }
    }
    header("Location: tickets.php?tab=assigned");
    exit();
}

// Fetch Trainer's Own Tickets (My Tickets)
$my_tickets_stmt = $db->prepare("SELECT * FROM trainer_tickets WHERE trainer_user_id = ? ORDER BY created_at DESC");
$my_tickets_stmt->execute([$trainer_user_id]);
$my_tickets = $my_tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($my_tickets as &$t) {
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

// Fetch Assigned Student Tickets
$assigned_tickets_stmt = $db->prepare("
    SELECT t.*, s.first_name, s.last_name, s.email as student_email, b.batch_name
    FROM tickets t
    JOIN students s ON t.student_id = s.student_id
    LEFT JOIN batches b ON t.batch_id = b.batch_id
    WHERE t.assigned_to_trainer_id = ?
    ORDER BY t.created_at DESC
");
$assigned_tickets_stmt->execute([$trainer_user_id]);
$assigned_tickets = $assigned_tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($assigned_tickets as &$t) {
    $msg = $db->prepare("
        SELECT tm.*, u.name as sender_name, u.role as sender_role
        FROM ticket_messages tm
        JOIN users u ON tm.sender_id = u.id
        WHERE tm.ticket_id = ?
        ORDER BY tm.created_at ASC
    ");
    $msg->execute([$t['id']]);
    $t['messages'] = $msg->fetchAll(PDO::FETCH_ASSOC);
}
unset($t);

// Calculate Stats
$my_total = count($my_tickets);
$my_open = count(array_filter($my_tickets, function($t) { return $t['status'] === 'open'; }));
$my_resolved = $my_total - $my_open;

$assigned_total = count($assigned_tickets);
$assigned_open = count(array_filter($assigned_tickets, function($t) { return $t['status'] === 'open'; }));
$assigned_resolved = $assigned_total - $assigned_open;

$total_tickets = $my_total + $assigned_total;
$total_open = $my_open + $assigned_open;
$total_resolved = $my_resolved + $assigned_resolved;

$tmsg = $_SESSION['tmsg'] ?? null;
unset($_SESSION['tmsg']);

$active_tab = $_GET['tab'] ?? 'my_tickets';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets | Trainer Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tab-btn.active { border-bottom-color: #234C6A; color: #234C6A; font-weight: 600; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    
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

/* ===== SUPPORT TICKETS COMPANY THEME SAFE PATCH ===== */
/* CSS-only polish. Ticket create/reply/close PHP, DB queries, notifications, filters and tabs remain untouched. */

:root {
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
    --theme-navy: linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%);
    --theme-blue: linear-gradient(135deg, #1d4ed8 0%, #2563eb 54%, #60a5fa 100%);
    --theme-green: linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%);
    --theme-orange: linear-gradient(135deg, #b45309 0%, #d97706 54%, #f59e0b 100%);
}

html, body {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,83,.09), transparent 28%),
        radial-gradient(circle at 88% 6%, rgba(69,104,130,.10), transparent 32%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 48%, #FBFAF8 100%) !important;
    color: #1B3C53 !important;
}

/* Keep sidebar stable. It has one job. Somehow this is where civilisation is now. */
@media (min-width: 1024px) {
    .lg\:ml-64 {
        margin-left: 16rem !important;
    }
}

aside {
    z-index: 9999 !important;
}

/* Main wrapper */
.flex-1.flex.flex-col.h-screen {
    background:
        radial-gradient(circle at 92% 4%, rgba(69,104,130,.10), transparent 28%),
        radial-gradient(circle at 10% 90%, rgba(210,193,182,.16), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 54%, #FBFAF8 100%) !important;
}

/* Header */
header.bg-white {
    min-height: 74px !important;
    background:
        radial-gradient(circle at 96% 0%, rgba(255,255,255,.14), transparent 30%),
        linear-gradient(135deg, #FFFFFF 0%, #F7F2EE 65%, #EEF3F6 100%) !important;
    border-bottom: 1px solid rgba(210,193,182,.58) !important;
    box-shadow: 0 16px 34px rgba(27,60,83,.08) !important;
}

header h1 {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

header h1 .w-10.h-10 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.42), transparent 34%),
        linear-gradient(135deg, #D2C1B6 0%, #EEF3F6 42%, #456882 100%) !important;
    color: #1B3C53 !important;
    border: 1.3px solid rgba(69,104,130,.25) !important;
    box-shadow:
        0 12px 24px rgba(27,60,83,.13),
        inset 0 1px 0 rgba(255,255,255,.35) !important;
}

header h1 .w-10.h-10 i {
    color: #1B3C53 !important;
}

/* New ticket button */
header button,
button[onclick*="newTicketModal"] {
    background:
        radial-gradient(circle at 92% 8%, rgba(255,255,255,.14), transparent 32%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%) !important;
    color: #ffffff !important;
    border: 1.2px solid rgba(255,255,255,.24) !important;
    border-radius: 12px !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.18) !important;
    font-weight: 900 !important;
}

header button:hover,
button[onclick*="newTicketModal"]:hover {
    transform: translateY(-2px) !important;
    filter: brightness(1.06) !important;
}

/* Status filter shell */
main > .mb-8.bg-white\/50 {
    background:
        radial-gradient(circle at 94% 6%, rgba(69,104,130,.09), transparent 32%),
        radial-gradient(circle at 8% 95%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.96), rgba(238,243,246,.86)) !important;
    border: 1.55px solid rgba(210,193,182,.70) !important;
    border-radius: 28px !important;
    box-shadow:
        0 22px 52px rgba(27,60,83,.11),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    backdrop-filter: blur(16px) !important;
}

main > .mb-8.bg-white\/50::before {
    content: "";
    position: absolute;
    inset: 0 0 auto 0;
    height: 5px;
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
}

main > .mb-8.bg-white\/50 h2 {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    background: rgba(255,255,255,.88);
    border: 1px solid rgba(210,193,182,.60);
    border-radius: 999px;
    padding: .55rem .85rem;
    width: fit-content;
    box-shadow: 0 10px 22px rgba(27,60,83,.06);
}

main > .mb-8.bg-white\/50 .text-xs.text-gray-400 {
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
    font-weight: 800 !important;
}

/* Four filter cards */
main > .mb-8.bg-white\/50 .grid > div[onclick] {
    border-radius: 22px !important;
    min-height: 136px !important;
    padding: 24px !important;
    border: 1.45px solid rgba(255,255,255,.38) !important;
    box-shadow:
        0 20px 42px rgba(27,60,83,.16),
        inset 0 1px 0 rgba(255,255,255,.22) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

/* Total */
main > .mb-8.bg-white\/50 .grid > div[onclick]:nth-child(1) {
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.16), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%) !important;
}

/* Open */
main > .mb-8.bg-white\/50 .grid > div[onclick]:nth-child(2) {
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.18), transparent 34%),
        linear-gradient(135deg, #1d4ed8 0%, #2563eb 54%, #60a5fa 100%) !important;
}

/* Resolved */
main > .mb-8.bg-white\/50 .grid > div[onclick]:nth-child(3) {
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.18), transparent 34%),
        linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%) !important;
}

/* Assigned Open */
main > .mb-8.bg-white\/50 .grid > div[onclick]:nth-child(4) {
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.18), transparent 34%),
        linear-gradient(135deg, #b45309 0%, #d97706 54%, #f59e0b 100%) !important;
}

main > .mb-8.bg-white\/50 .grid > div[onclick]:hover {
    transform: translateY(-6px) scale(1.012) !important;
    filter: brightness(1.05) !important;
    box-shadow:
        0 30px 58px rgba(27,60,83,.22),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

main > .mb-8.bg-white\/50 .grid > div[onclick] .w-12.h-12 {
    width: 62px !important;
    height: 62px !important;
    min-width: 62px !important;
    min-height: 62px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.42), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.45px solid rgba(255,255,255,.46) !important;
    box-shadow:
        0 14px 28px rgba(0,0,0,.17),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

main > .mb-8.bg-white\/50 .grid > div[onclick] i,
main > .mb-8.bg-white\/50 .grid > div[onclick] div {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

main > .mb-8.bg-white\/50 .grid > div[onclick] .text-2xl {
    font-size: 2rem !important;
    line-height: 1 !important;
    text-shadow: 0 1px 8px rgba(0,0,0,.16) !important;
}

main > .mb-8.bg-white\/50 .grid > div[onclick] .text-\[10px\] {
    opacity: 1 !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.13) !important;
}

/* Ticket table shell */
main > .bg-white.rounded-2xl {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.08), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.18), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.55px solid rgba(210,193,182,.72) !important;
    border-radius: 28px !important;
    box-shadow:
        0 22px 52px rgba(27,60,83,.12),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    overflow: hidden !important;
}

main > .bg-white.rounded-2xl::before {
    content: "";
    display: block;
    height: 5px;
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
}

/* Tabs */
.tab-btn {
    border-radius: 16px 16px 0 0 !important;
    font-weight: 900 !important;
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
}

.tab-btn.active {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    background: rgba(255,255,255,.88) !important;
    border-bottom-color: #1B3C53 !important;
    box-shadow: 0 10px 22px rgba(27,60,83,.07) !important;
}

.tab-btn:hover {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

/* Table */
table thead {
    background: linear-gradient(135deg, rgba(238,243,246,.98), rgba(210,193,182,.34)) !important;
}

table thead th {
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
    font-weight: 950 !important;
    letter-spacing: .07em !important;
}

tbody.bg-white,
tbody.bg-white.divide-y {
    background: rgba(255,255,255,.78) !important;
}

.ticket-row {
    transition: transform .18s ease, background .18s ease, box-shadow .18s ease !important;
}

.ticket-row:hover {
    background:
        linear-gradient(90deg, rgba(238,243,246,.88), rgba(247,242,238,.74)) !important;
    transform: translateX(4px) !important;
    box-shadow: inset 4px 0 0 rgba(35,76,106,.42) !important;
}

.ticket-row td {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

/* Status pills */
.bg-green-100.text-green-800 {
    background: rgba(16,185,129,.18) !important;
    color: #047857 !important;
    -webkit-text-fill-color: #047857 !important;
    border: 1px solid rgba(16,185,129,.35) !important;
}

.bg-purple-100.text-purple-800,
.bg-blue-100.text-blue-800 {
    background: rgba(37,99,235,.15) !important;
    color: #1d4ed8 !important;
    -webkit-text-fill-color: #1d4ed8 !important;
    border: 1px solid rgba(37,99,235,.28) !important;
}

/* View reply buttons */
.ticket-row span.bg-purple-600,
.ticket-row span.bg-blue-600 {
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.14), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1px solid rgba(255,255,255,.20) !important;
    border-radius: 12px !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.18) !important;
}

.ticket-row span.bg-purple-600:hover,
.ticket-row span.bg-blue-600:hover {
    transform: translateY(-2px) !important;
    filter: brightness(1.07) !important;
}

/* Expanded conversation area */
tr[id^="my-"],
tr[id^="assign-"] {
    background:
        linear-gradient(135deg, rgba(238,243,246,.72), rgba(247,242,238,.68)) !important;
}

tr[id^="my-"] .bg-white,
tr[id^="assign-"] .bg-white {
    background: rgba(255,255,255,.88) !important;
    border-color: rgba(210,193,182,.58) !important;
}

/* Forms and modal */
input,
textarea,
select {
    color: #102A3A !important;
    -webkit-text-fill-color: #102A3A !important;
    border-color: rgba(69,104,130,.28) !important;
}

input:focus,
textarea:focus,
select:focus {
    border-color: #234C6A !important;
    box-shadow: 0 0 0 4px rgba(35,76,106,.14) !important;
}

#newTicketModal > div {
    border-radius: 26px !important;
    border: 1.5px solid rgba(210,193,182,.70) !important;
    box-shadow: 0 30px 70px rgba(15,23,42,.30) !important;
}

#newTicketModal .bg-purple-600 {
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.16), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%) !important;
}

#newTicketModal button[name="create_ticket"] {
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.14), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%) !important;
}

/* Mobile */
@media (max-width: 768px) {
    main > .mb-8.bg-white\/50,
    main > .bg-white.rounded-2xl {
        border-radius: 22px !important;
    }

    main > .mb-8.bg-white\/50 .grid > div[onclick] {
        min-height: 118px !important;
    }
}

</style>

</head>
<body class="bg-gray-50 flex h-screen overflow-hidden text-gray-800">

    <?php include '../t_sidebar.php'; ?>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col h-screen overflow-hidden bg-gray-50 w-full transition-all duration-300 lg:ml-64">
        
        <!-- Header -->
        <header class="bg-white border-b border-gray-200 h-16 flex items-center justify-between px-4 sm:px-6 lg:px-8 z-10 shrink-0 shadow-sm">
            <div class="flex items-center">
                <h1 class="text-xl sm:text-2xl font-bold text-gray-800 flex items-center space-x-3">
                    <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center shadow-sm">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <span>Support Center</span>
                </h1>
            </div>
            <div class="flex items-center space-x-4">
                <button class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm" onclick="document.getElementById('newTicketModal').classList.remove('hidden')">
                    <i class="fas fa-plus mr-2"></i>New Ticket
                </button>
            </div>
        </header>

        <!-- Main Scrollable Area -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            
            <?php if ($tmsg): ?>
                <div class="mb-6 p-4 rounded-xl shadow-sm flex items-center <?= $tmsg['type'] === 'success' ? 'bg-green-100 text-green-800 border-l-4 border-green-500' : 'bg-red-100 text-red-800 border-l-4 border-red-500' ?>">
                    <i class="fas <?= $tmsg['type'] === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500' ?> mr-3 text-lg"></i>
                    <span class="font-medium"><?= htmlspecialchars($tmsg['text']) ?></span>
                </div>
            <?php endif; ?>

            <div class="mb-8 bg-white/50 backdrop-blur-md rounded-2xl border border-gray-200/50 shadow-sm p-5 relative overflow-hidden">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center"><i class="fas fa-filter mr-2"></i> Ticket Status Filters</h2>
                    <span class="text-xs text-gray-400 font-medium">Click a card to filter current tickets</span>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- Total -->
                    <div onclick="filterTickets('all')" class="cursor-pointer relative overflow-hidden bg-gradient-to-br from-slate-700 to-slate-800 rounded-xl p-4 text-white shadow-md hover:shadow-lg transition-all transform hover:-translate-y-1">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-white/10 rounded-full blur-xl"></div>
                        <div class="flex items-center gap-4 relative z-10">
                            <div class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center border border-white/20 shrink-0">
                                <i class="fas fa-ticket-alt text-xl text-white"></i>
                            </div>
                            <div>
                                <div class="text-[10px] font-bold text-white/70 uppercase tracking-wider mb-0.5">Total Tickets</div>
                                <div class="text-2xl font-black leading-none mb-1"><?= $total_tickets ?></div>
                                <div class="text-[10px] font-medium text-white/60"><?= $my_total ?> mine · <?= $assigned_total ?> assigned</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Open -->
                    <div onclick="filterTickets('open')" class="cursor-pointer relative overflow-hidden bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-4 text-white shadow-md hover:shadow-lg transition-all transform hover:-translate-y-1">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-white/20 rounded-full blur-xl"></div>
                        <div class="flex items-center gap-4 relative z-10">
                            <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center border border-white/20 shrink-0">
                                <i class="fas fa-folder-open text-xl text-white"></i>
                            </div>
                            <div>
                                <div class="text-[10px] font-bold text-white/80 uppercase tracking-wider mb-0.5">Open Tickets</div>
                                <div class="text-2xl font-black leading-none mb-1"><?= $total_open ?></div>
                                <div class="text-[10px] font-medium text-white/70"><?= $my_open ?> mine · <?= $assigned_open ?> assigned</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Resolved -->
                    <div onclick="filterTickets('resolved')" class="cursor-pointer relative overflow-hidden bg-gradient-to-br from-emerald-400 to-emerald-500 rounded-xl p-4 text-white shadow-md hover:shadow-lg transition-all transform hover:-translate-y-1">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-white/20 rounded-full blur-xl"></div>
                        <div class="flex items-center gap-4 relative z-10">
                            <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center border border-white/20 shrink-0">
                                <i class="fas fa-check-circle text-xl text-white"></i>
                            </div>
                            <div>
                                <div class="text-[10px] font-bold text-white/80 uppercase tracking-wider mb-0.5">Resolved Tickets</div>
                                <div class="text-2xl font-black leading-none mb-1"><?= $total_resolved ?></div>
                                <div class="text-[10px] font-medium text-white/70"><?= $my_resolved ?> mine · <?= $assigned_resolved ?> assigned</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Assigned Open -->
                    <div onclick="filterTickets('assigned_open')" class="cursor-pointer relative overflow-hidden bg-gradient-to-br from-amber-500 to-amber-600 rounded-xl p-4 text-white shadow-md hover:shadow-lg transition-all transform hover:-translate-y-1">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-white/20 rounded-full blur-xl"></div>
                        <div class="flex items-center gap-4 relative z-10">
                            <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center border border-white/20 shrink-0">
                                <i class="fas fa-users text-xl text-white"></i>
                            </div>
                            <div>
                                <div class="text-[10px] font-bold text-white/80 uppercase tracking-wider mb-0.5">Assigned Open</div>
                                <div class="text-2xl font-black leading-none mb-1"><?= $assigned_open ?></div>
                                <div class="text-[10px] font-medium text-white/70">Student tickets needing reply</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                <!-- Tabs -->
                <div class="flex border-b border-gray-200 bg-gray-50/50 px-2 pt-2">
                    <button class="tab-btn px-6 py-4 text-sm text-gray-500 hover:text-purple-600 border-b-2 border-transparent transition-colors focus:outline-none <?= $active_tab === 'my_tickets' ? 'active' : '' ?>" onclick="switchTab('my_tickets', this)">
                        <i class="fas fa-user-tag mr-2"></i>My Tickets
                    </button>
                    <button class="tab-btn px-6 py-4 text-sm text-gray-500 hover:text-purple-600 border-b-2 border-transparent transition-colors focus:outline-none <?= $active_tab === 'assigned' ? 'active' : '' ?>" onclick="switchTab('assigned', this)">
                        <i class="fas fa-users-cog mr-2"></i>Assigned Student Tickets
                        <?php 
                        $open_assigned_count = 0;
                        foreach ($assigned_tickets as $t) {
                            if ($t['status'] === 'open') $open_assigned_count++;
                        }
                        if ($open_assigned_count > 0): 
                        ?>
                            <span class="ml-2 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-purple-100 bg-purple-600 rounded-full"><?= $open_assigned_count ?></span>
                        <?php endif; ?>
                    </button>
                </div>

                <div class="p-6">
                    <!-- Tab 1: My Tickets -->
                    <div id="my_tickets" class="tab-content <?= $active_tab === 'my_tickets' ? 'active' : '' ?>">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date Raised</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Category</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if(empty($my_tickets)): ?>
                                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No tickets raised by you yet.</td></tr>
                                    <?php else: foreach($my_tickets as $ticket): ?>
                                        <tr class="hover:bg-gray-50 cursor-pointer trow ticket-row" data-id="my-<?= $ticket['id'] ?>" data-status="<?= $ticket['status'] === 'open' ? 'open' : 'resolved' ?>">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-700">#<?= $ticket['id'] ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-700"><?= htmlspecialchars($ticket['reason']) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $ticket['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-purple-100 text-purple-800' ?>"><?= ucfirst($ticket['status']) ?></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <span class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs transition-colors cursor-pointer inline-block"><i class="fas fa-comments mr-1"></i> View / Reply</span>
                                            </td>
                                        </tr>
                                        <tr id="my-<?= $ticket['id'] ?>" class="hidden bg-gray-50/50">
                                            <td colspan="5" class="p-6 border-t border-b border-gray-100">
                                                <div class="max-w-3xl">
                                                    <!-- Conversation -->
                                                    <div class="mb-4">
                                                        <h5 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Conversation</h5>
                                                        <div class="space-y-3 p-4 bg-white rounded-xl border border-gray-200 max-h-80 overflow-y-auto">
                                                            <!-- Original Description -->
                                                            <div class="flex items-start space-x-2">
                                                                <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-xs font-bold shrink-0">Me</div>
                                                                <div class="bg-purple-50 text-gray-800 p-3 rounded-2xl rounded-tl-none max-w-[85%] text-sm text-left whitespace-pre-wrap"><?= htmlspecialchars($ticket['description']) ?></div>
                                                            </div>
                                                            <!-- Messages -->
                                                            <?php foreach($ticket['messages'] as $msg): 
                                                                $is_me = ($msg['sender_role'] === 'mentor');
                                                                $bbg = $is_me ? 'bg-purple-50' : 'bg-gray-100';
                                                                $abg = $is_me ? 'bg-purple-100 text-purple-600' : 'bg-gray-200 text-gray-600';
                                                                $lbl = $is_me ? 'Me' : 'A';
                                                            ?>
                                                            <div class="flex items-start space-x-2">
                                                                <div class="w-8 h-8 rounded-full <?= $abg ?> flex items-center justify-center text-xs font-bold shrink-0"><?= $lbl ?></div>
                                                                <div class="<?= $bbg ?> text-gray-800 p-3 rounded-2xl rounded-tl-none max-w-[85%] text-left">
                                                                    <div class="text-[10px] text-gray-400 mb-1"><?= $is_me ? 'Me' : 'Admin' ?> • <?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?></div>
                                                                    <div class="text-sm whitespace-pre-wrap"><?= htmlspecialchars($msg['message']) ?></div>
                                                                </div>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <!-- Actions -->
                                                    <?php if($ticket['status'] !== 'resolved' && $ticket['status'] !== 'closed'): ?>
                                                    <form method="POST" action="tickets.php" class="flex gap-2 mb-3">
                                                        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                        <input type="text" name="message" required placeholder="Type reply..." class="flex-1 px-4 py-2 text-sm border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500">
                                                        <button type="submit" name="reply_my_ticket" class="px-5 py-2 bg-purple-600 text-white rounded-xl text-sm font-semibold">Reply</button>
                                                    </form>
                                                    <form method="POST" action="tickets.php" onsubmit="return confirm('Close ticket?');">
                                                        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                        <button type="submit" name="close_my_ticket" class="text-xs text-red-500 hover:text-red-700 font-semibold"><i class="fas fa-times-circle mr-1"></i> Mark as resolved & Close</button>
                                                    </form>
                                                    <?php else: ?>
                                                    <div class="text-xs font-semibold text-gray-500 flex items-center"><i class="fas fa-lock mr-2 text-gray-400"></i> Ticket is closed.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Assigned Student Tickets -->
                    <div id="assigned" class="tab-content <?= $active_tab === 'assigned' ? 'active' : '' ?>">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Student</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Category</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if(empty($assigned_tickets)): ?>
                                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No student tickets assigned to you.</td></tr>
                                    <?php else: foreach($assigned_tickets as $ticket): ?>
                                        <tr class="hover:bg-gray-50 cursor-pointer trow ticket-row" data-id="assign-<?= $ticket['id'] ?>" data-status="<?= $ticket['status'] === 'open' ? 'open' : 'resolved' ?>">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-700">#<?= $ticket['id'] ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']) ?></div>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($ticket['batch_name'] ?? 'General') ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-700"><?= htmlspecialchars($ticket['reason']) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $ticket['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' ?>"><?= ucfirst($ticket['status']) ?></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <span class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs transition-colors cursor-pointer inline-block"><i class="fas fa-comments mr-1"></i> View / Reply</span>
                                            </td>
                                        </tr>
                                        <tr id="assign-<?= $ticket['id'] ?>" class="hidden bg-gray-50/50">
                                            <td colspan="5" class="p-6 border-t border-b border-gray-100">
                                                <div class="max-w-3xl">
                                                    <!-- Conversation -->
                                                    <div class="mb-4">
                                                        <h5 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Conversation History</h5>
                                                        <div class="space-y-3 p-4 bg-white rounded-xl border border-gray-200 max-h-80 overflow-y-auto">
                                                            <!-- Original Description -->
                                                            <div class="flex items-start space-x-2">
                                                                <div class="w-8 h-8 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center text-xs font-bold shrink-0">S</div>
                                                                <div class="bg-gray-50 text-gray-800 p-3 rounded-2xl rounded-tl-none max-w-[85%] text-left">
                                                                    <div class="text-[10px] text-gray-400 mb-1">Student • <?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?></div>
                                                                    <div class="text-sm whitespace-pre-wrap"><?= htmlspecialchars($ticket['description'] ?: 'No description.') ?></div>
                                                                </div>
                                                            </div>
                                                            <!-- Messages -->
                                                            <?php foreach($ticket['messages'] as $msg): 
                                                                $is_me = ($msg['sender_role'] === 'mentor');
                                                                $is_stu = ($msg['sender_role'] === 'student');
                                                                $bbg = $is_me ? 'bg-blue-50' : ($is_stu ? 'bg-gray-50' : 'bg-green-50');
                                                                $abg = $is_me ? 'bg-blue-100 text-blue-600' : ($is_stu ? 'bg-gray-100 text-gray-600' : 'bg-green-100 text-green-600');
                                                                $lbl = $is_me ? 'Me' : ($is_stu ? 'S' : 'A');
                                                                $name = $is_me ? 'Me (Trainer)' : ($is_stu ? 'Student' : 'Admin');
                                                            ?>
                                                            <div class="flex items-start space-x-2">
                                                                <div class="w-8 h-8 rounded-full <?= $abg ?> flex items-center justify-center text-xs font-bold shrink-0"><?= $lbl ?></div>
                                                                <div class="<?= $bbg ?> text-gray-800 p-3 rounded-2xl rounded-tl-none max-w-[85%] text-left">
                                                                    <div class="text-[10px] text-gray-400 mb-1"><?= $name ?> • <?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?></div>
                                                                    <div class="text-sm whitespace-pre-wrap"><?= htmlspecialchars($msg['message']) ?></div>
                                                                </div>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <!-- Actions -->
                                                    <?php if($ticket['status'] !== 'resolved' && $ticket['status'] !== 'closed'): ?>
                                                    <form method="POST" action="tickets.php?tab=assigned" class="flex gap-2 mb-3">
                                                        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                                        <input type="text" name="message" required placeholder="Type reply to student/admin..." class="flex-1 px-4 py-2 text-sm border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                                                        <button type="submit" name="reply_student_ticket" class="px-5 py-2 bg-blue-600 text-white rounded-xl text-sm font-semibold">Reply</button>
                                                    </form>
                                                    <?php else: ?>
                                                    <div class="text-xs font-semibold text-gray-500 flex items-center"><i class="fas fa-lock mr-2 text-gray-400"></i> Ticket is closed by Admin/Student.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
        </main>
    </div>

    <!-- Modal for New Trainer Ticket -->
    <div id="newTicketModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden transition-all duration-300">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
            <div class="bg-purple-600 text-white p-5 flex justify-between items-center">
                <h2 class="text-lg font-bold flex items-center"><i class="fas fa-ticket-alt mr-2"></i> Raise Support Ticket</h2>
                <button onclick="document.getElementById('newTicketModal').classList.add('hidden')" class="text-white hover:text-purple-200 text-xl"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" action="tickets.php" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Category / Reason *</label>
                    <select name="reason" required class="w-full px-4 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 text-sm">
                        <option value="">Select Category</option>
                        <option value="Batch Schedule Request">Batch Schedule Request</option>
                        <option value="Technical Issue (Portal)">Technical Issue (Portal)</option>
                        <option value="Student Related Issue">Student Related Issue</option>
                        <option value="Payment / Salary Query">Payment / Salary Query</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Description *</label>
                    <textarea name="description" required rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 text-sm" placeholder="Provide detailed information..."></textarea>
                </div>
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="document.getElementById('newTicketModal').classList.add('hidden')" class="w-1/2 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-semibold text-sm">Cancel</button>
                    <button type="submit" name="create_ticket" class="w-1/2 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl font-semibold text-sm">Submit Ticket</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
            
            // update URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);
        }
        
        document.querySelectorAll('.trow').forEach(row => {
            row.addEventListener('click', function(e) {
                if(e.target.closest('button') || e.target.closest('a') || e.target.closest('form')) return;
                const r = document.getElementById(this.dataset.id);
                r.classList.toggle('hidden');
            });
        });
        
        function filterTickets(filter) {
            if (filter === 'assigned_open') {
                document.querySelector('button[onclick="switchTab(\\\'assigned\\\', this)"]').click();
                filter = 'open';
            }
            
            document.querySelectorAll('.ticket-row').forEach(row => {
                const status = row.getAttribute('data-status');
                const detailsRow = document.getElementById(row.dataset.id);
                
                if (filter === 'all' || status === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                    if (detailsRow) detailsRow.classList.add('hidden');
                }
            });
        }

        // Hide sidebar overlay logic from t_sidebar if needed
        function hideSidebar() {
            document.querySelector('aside').classList.add('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.add('hidden');
        }
        document.getElementById('mobileSidebarToggle')?.addEventListener('click', () => {
            document.querySelector('aside').classList.remove('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.remove('hidden');
        });
    </script>
</body>
</html>
