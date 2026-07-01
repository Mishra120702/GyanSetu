<?php
// logout.php

// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != 0) {
    require_once 'db_connection.php';
    try {
        $db->prepare("INSERT INTO system_activity_logs (user_id, action_type, description) VALUES (?, 'LOGOUT', 'User logged out')")->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {}
}

// Unset all session variables
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>