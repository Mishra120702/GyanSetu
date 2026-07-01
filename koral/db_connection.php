<?php
// session_start([
//     'cookie_httponly' => true,
//     'cookie_secure' => isset($_SERVER['HTTPS']),
//     'use_strict_mode' => true,
//     'cookie_samesite' => 'Strict',
// ]);

$host = 'localhost';
$dbname = 'u621399201_koral';
// Local development credentials
$user = 'root';
$pass = '';
// Production credentials (original):
// $user = 'u621399201_koral';
// $pass = 'X$P!:wjWLzg4';

try {
    // PDO with strict error mode and prepared statements by default
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    
    // Set PDO attributes for security
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Disable emulated prepares
    
    // Set connection charset explicitly
    $db->exec("SET NAMES utf8mb4");
    $db->exec("SET CHARACTER SET utf8mb4");
    
} catch(PDOException $e) {
    // Log error without exposing details
    error_log("Database connection failed: " . $e->getMessage());
    
    // Show generic error message to user
    die("System is temporarily unavailable. Please try again later.");
}

// Function to sanitize input - but primarily use prepared statements
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
?>

