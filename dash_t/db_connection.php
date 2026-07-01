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
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}
?>
