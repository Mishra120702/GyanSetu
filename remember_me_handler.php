<?php
/**
 * SECURE REMEMBER ME HANDLER
 * Prevents token theft and replay attacks
 */

if (!isset($_COOKIE['remember_token'])) {
    return;
}

require_once 'db_connection.php';

$token = $_COOKIE['remember_token'];
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Hash the token for database lookup
$token_hash = hash('sha256', $token);

// Look for valid token with IP and user agent validation
$stmt = $db->prepare("
    SELECT rt.user_id, rt.token, rt.expires_at, rt.ip_address, rt.user_agent, u.role, u.name, u.email
    FROM remember_tokens rt
    JOIN users u ON rt.user_id = u.id
    WHERE rt.token = ? 
    AND rt.expires_at > NOW()
    AND rt.is_used = 0
");

$stmt->execute([$token_hash]);
$token_data = $stmt->fetch();

if ($token_data) {
    // Verify IP and User Agent match (prevents token theft)
    if ($token_data['ip_address'] !== $ip_address || $token_data['user_agent'] !== $user_agent) {
        // Potential token theft - invalidate all tokens for this user
        $invalidate_stmt = $db->prepare("UPDATE remember_tokens SET is_used = 1 WHERE user_id = ?");
        $invalidate_stmt->execute([$token_data['user_id']]);
        
        // Clear the cookie
        setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        
        error_log("Potential remember token theft detected for user: " . $token_data['user_id']);
        return;
    }
    
    // Mark token as used (one-time use)
    $use_stmt = $db->prepare("UPDATE remember_tokens SET is_used = 1, used_at = NOW() WHERE token = ?");
    $use_stmt->execute([$token_hash]);
    
    // Regenerate session
    session_regenerate_id(true);
    
    // Set session variables
    $_SESSION['user_id'] = $token_data['user_id'];
    $_SESSION['user_role'] = $token_data['role'];
    $_SESSION['user_name'] = $token_data['name'];
    $_SESSION['user_email'] = $token_data['email'];
    $_SESSION['last_activity'] = time();
    $_SESSION['session_hash'] = hash('sha256', $user_agent . $ip_address);
    $_SESSION['login_time'] = time();
    $_SESSION['remember_me_restored'] = true;
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    
    // Generate new remember token for next time (renewal)
    $new_token = bin2hex(random_bytes(32));
    $new_expiry = time() + (7 * 24 * 60 * 60);
    $new_token_hash = hash('sha256', $new_token);
    
    $new_stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at, ip_address, user_agent, created_at) VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, NOW())");
    $new_stmt->execute([$token_data['user_id'], $new_token_hash, $new_expiry, $ip_address, $user_agent]);
    
    setcookie('remember_token', $new_token, [
        'expires' => $new_expiry,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    // Redirect to dashboard
    header("Location: dash_t/dashboard/dashboard.php");
    exit;
} else {
    // Invalid or expired token - clear cookie
    setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
}
?>