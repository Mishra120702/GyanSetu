<?php
/**
 * SECURE LOGOUT SCRIPT
 * Properly invalidates all session data
 */

// Start session to destroy it
session_start();

if (isset($_SESSION['user_id'])) {
    require_once 'db_connection.php';
    try {
        $db->prepare("INSERT INTO system_activity_logs (user_id, action_type, description) VALUES (?, 'LOGOUT', 'Trainer logged out')")->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {}
}

// Clear all session variables
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    
    // Optional: Invalidate token in database
    if (isset($_SESSION['user_id'])) {
        require_once 'db_connection.php';
        $token_hash = hash('sha256', $_COOKIE['remember_token']);
        $stmt = $db->prepare("UPDATE remember_tokens SET is_used = 1 WHERE token = ?");
        $stmt->execute([$token_hash]);
    }
}

// Destroy session
session_destroy();

// Redirect to login page
header("Location: log2.php?logout=success");
exit;
?>