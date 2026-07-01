<?php
// session_start([
//     'cookie_httponly' => true,
//     'cookie_secure' => isset($_SERVER['HTTPS']),
//     'use_strict_mode' => true,
//     'cookie_samesite' => 'Strict',
// ]);

$host = 'localhost';
$dbname = 'u621399201_guru';
$user = 'u621399201_guru';
$pass = 'nDjjhAE*R9y';

try {
    // PDO with strict error mode and prepared statements by default
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    
    // Set PDO attributes for security
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Disable emulated prepares
    
    // Set connection charset and collation explicitly to match database tables
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    
} catch(PDOException $e) {
    // Log error without exposing details
    error_log("Database connection failed: " . $e->getMessage());
    
    // Show generic error message to user
    die("System is temporarily unavailable. Please try again later.");
}

// Function to sanitize input - but primarily use prepared statements
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map('sanitizeInput', $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('logSystemActivity')) {
    function logSystemActivity($db, $user_id, $action_type, $description) {
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $stmt = $db->prepare("INSERT INTO system_activity_logs (user_id, action_type, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $action_type, $description, $ip_address]);
        } catch(PDOException $e) {
            // Silently fail for activity logging so it doesn't break the main flow
            error_log("Failed to log system activity: " . $e->getMessage());
        }
    }
}
?>

