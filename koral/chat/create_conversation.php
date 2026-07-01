<?php
// create_conversation.php - ULTIMATE FIXED VERSION WITH ALL ADMINS
session_start();
require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Clear any previous output
ob_clean();

header('Content-Type: application/json');

// Log everything
$debug_log = [
    'time' => date('Y-m-d H:i:s'),
    'session' => $_SESSION,
    'post' => $_POST,
    'method' => $_SERVER['REQUEST_METHOD']
];
error_log("create_conversation.php - " . json_encode($debug_log));

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get action
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_POST['ajax_action']) ? $_POST['ajax_action'] : '');
if ($action !== 'create_conversation') {
    echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
    exit;
}

$type = isset($_POST['type']) ? $_POST['type'] : '';

try {
    // Get all admin users
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
    $stmt->execute();
    $all_admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // ONE-TO-ONE CHAT
    if ($type === 'one_to_one') {
        $other_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$other_user_id) {
            echo json_encode(['success' => false, 'error' => 'Please select a user']);
            exit;
        }
        
        // Check if users exist
        $stmt = $db->prepare("SELECT id, name FROM users WHERE id = ?");
        $stmt->execute([$other_user_id]);
        $other_user = $stmt->fetch();
        
        if (!$other_user) {
            echo json_encode(['success' => false, 'error' => 'Selected user not found']);
            exit;
        }
        
        // Check if conversation already exists with any admin
        $stmt = $db->prepare("
            SELECT c.id 
            FROM conversations c
            JOIN conversation_members cm ON c.id = cm.conversation_id
            WHERE c.type = 'one_to_one' 
            AND c.batch_id IS NULL
            AND cm.user_id IN (" . implode(',', array_fill(0, count($all_admins), '?')) . ")
            AND EXISTS (
                SELECT 1 FROM conversation_members cm2 
                WHERE cm2.conversation_id = c.id AND cm2.user_id = ?
            )
            LIMIT 1
        ");
        
        $params = array_merge($all_admins, [$other_user_id]);
        $stmt->execute($params);
        $existing = $stmt->fetch();
        
        if ($existing) {
            echo json_encode(['success' => true, 'conversation_id' => $existing['id']]);
            exit;
        }
        
        // Create new conversation
        $db->beginTransaction();
        
        // Get current IST time
        $ist_time = date('Y-m-d H:i:s');
        
        $stmt = $db->prepare("INSERT INTO conversations (type, created_by, created_at, updated_at) VALUES (?, ?, ?, ?)");
        $stmt->execute(['one_to_one', $user_id, $ist_time, $ist_time]);
        $conversation_id = $db->lastInsertId();
        
        // Add ALL admins as members
        $stmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id, joined_at) VALUES (?, ?, ?)");
        
        // Add all admins
        foreach ($all_admins as $admin_id) {
            $stmt->execute([$conversation_id, $admin_id, $ist_time]);
        }
        
        // Add the other user
        $stmt->execute([$conversation_id, $other_user_id, $ist_time]);
        
        $db->commit();
        
        echo json_encode([
            'success' => true, 
            'conversation_id' => $conversation_id,
            'message' => 'Conversation created with ' . $other_user['name'] . ' (All admins added)'
        ]);
        exit;
    }
    
    // BATCH CHAT - ULTIMATE FIXED VERSION WITH DIRECT QUERY
    else if ($type === 'batch') {
        $batch_id = $_POST['batch_id'] ?? '';
        $batch_name = $_POST['batch_name'] ?? '';
        
        if (!$batch_id || !$batch_name) {
            echo json_encode(['success' => false, 'error' => 'Please select a batch']);
            exit;
        }
        
        error_log("=== BATCH CHAT CREATION ===");
        error_log("Batch ID: " . $batch_id);
        error_log("Batch Name: " . $batch_name);
        
        // Check if batch conversation already exists
        $stmt = $db->prepare("
            SELECT c.id 
            FROM conversations c
            WHERE c.type = 'group' AND c.batch_id = ?
        ");
        $stmt->execute([$batch_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            echo json_encode(['success' => true, 'conversation_id' => $existing['id']]);
            exit;
        }
        
        // DIRECT QUERY - Get students with this batch_id in ANY batch column
        $query = "
            SELECT 
                s.student_id,
                s.user_id,
                s.first_name,
                s.last_name
            FROM students s
            WHERE s.batch_name = '$batch_id' 
               OR s.batch_name_2 = '$batch_id'
               OR s.batch_name_3 = '$batch_id'
               OR s.batch_name_4 = '$batch_id'
        ";
        
        error_log("Executing query: " . $query);
        $result = $db->query($query);
        $students = $result->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Found " . count($students) . " students directly from students table");
        error_log("Students data: " . print_r($students, true));
        
        if (empty($students)) {
            // Try with prepared statement as fallback (safer)
            $stmt = $db->prepare("
                SELECT 
                    s.student_id,
                    s.user_id,
                    s.first_name,
                    s.last_name
                FROM students s
                WHERE s.batch_name = ? 
                   OR s.batch_name_2 = ?
                   OR s.batch_name_3 = ?
                   OR s.batch_name_4 = ?
            ");
            $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Fallback query found: " . count($students) . " students");
            
            if (empty($students)) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'No students found with batch ID: ' . $batch_id . ' in students table'
                ]);
                exit;
            }
        }
        
        // Now get the user IDs for these students
        $user_ids = [];
        foreach ($students as $student) {
            if (!empty($student['user_id'])) {
                // Check if this user exists and is active in users table
                $user_check = $db->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
                $user_check->execute([$student['user_id']]);
                if ($user_check->fetch()) {
                    $user_ids[] = $student['user_id'];
                    error_log("Valid user found: ID " . $student['user_id'] . " for student " . $student['first_name'] . " " . $student['last_name']);
                } else {
                    error_log("User ID " . $student['user_id'] . " for student " . $student['first_name'] . " " . $student['last_name'] . " not found or not active in users table");
                }
            } else {
                error_log("Student " . $student['first_name'] . " " . $student['last_name'] . " has no user_id");
            }
        }
        
        if (empty($user_ids)) {
            echo json_encode([
                'success' => false, 
                'error' => 'Found ' . count($students) . ' students but none have valid user accounts. Please check user_id mapping.'
            ]);
            exit;
        }
        
        // Create batch conversation
        $db->beginTransaction();
        
        $conversation_name = "Batch: " . $batch_name;
        $ist_time = date('Y-m-d H:i:s');
        
        $stmt = $db->prepare("
            INSERT INTO conversations (type, name, batch_id, created_by, created_at, updated_at) 
            VALUES ('group', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$conversation_name, $batch_id, $user_id, $ist_time, $ist_time]);
        $conversation_id = $db->lastInsertId();
        
        // Add ALL admins as members
        $stmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id, joined_at) VALUES (?, ?, ?)");
        
        // Add all admins
        foreach ($all_admins as $admin_id) {
            $stmt->execute([$conversation_id, $admin_id, $ist_time]);
        }
        
        // Add all students as members
        $member_count = 0;
        foreach ($user_ids as $uid) {
            $stmt->execute([$conversation_id, $uid, $ist_time]);
            $member_count++;
            error_log("Added user $uid to conversation $conversation_id");
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true, 
            'conversation_id' => $conversation_id,
            'message' => 'Batch chat created for ' . $batch_name . ' with ' . $member_count . ' students (All admins added)'
        ]);
        exit;
    }
    
    // CUSTOM GROUP CHAT
    else if ($type === 'group') {
        $name = trim($_POST['name'] ?? '');
        $members = isset($_POST['members']) ? json_decode($_POST['members'], true) : [];
        
        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Group name required']);
            exit;
        }
        
        if (empty($members)) {
            echo json_encode(['success' => false, 'error' => 'Select at least one member']);
            exit;
        }
        
        // Create group conversation
        $db->beginTransaction();
        
        $ist_time = date('Y-m-d H:i:s');
        
        $stmt = $db->prepare("INSERT INTO conversations (type, name, created_by, created_at, updated_at) VALUES ('group', ?, ?, ?, ?)");
        $stmt->execute([$name, $user_id, $ist_time, $ist_time]);
        $conversation_id = $db->lastInsertId();
        
        // Add ALL admins as members
        $stmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id, joined_at) VALUES (?, ?, ?)");
        
        // Add all admins
        foreach ($all_admins as $admin_id) {
            $stmt->execute([$conversation_id, $admin_id, $ist_time]);
        }
        
        // Add selected members (excluding duplicates with admins)
        foreach ($members as $member_id) {
            // Verify member exists
            $check = $db->prepare("SELECT id FROM users WHERE id = ?");
            $check->execute([$member_id]);
            if ($check->fetch()) {
                // Check if not already added as admin
                if (!in_array($member_id, $all_admins)) {
                    $stmt->execute([$conversation_id, $member_id, $ist_time]);
                }
            }
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true, 
            'conversation_id' => $conversation_id,
            'message' => 'Group chat created with all admins'
        ]);
        exit;
    }
    
    else {
        echo json_encode(['success' => false, 'error' => 'Invalid conversation type']);
    }
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    error_log("Error creating conversation: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>