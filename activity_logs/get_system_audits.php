<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $db->query("
        SELECT s.id, s.action_type, s.description, s.created_at, u.name
        FROM system_activity_logs s
        LEFT JOIN users u ON s.user_id = u.id
        ORDER BY s.created_at DESC
        LIMIT 50
    ");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_logs = array_map(function($log) {
        return [
            'id' => $log['id'],
            'user' => $log['name'] ? htmlspecialchars($log['name']) : 'System',
            'action_type' => htmlspecialchars($log['action_type']),
            'description' => htmlspecialchars($log['description']),
            'timestamp' => date('M j, Y, g:i A', strtotime($log['created_at']))
        ];
    }, $logs);

    echo json_encode(['data' => $formatted_logs]);

} catch(PDOException $e) {
    http_response_code(500);
    error_log("System Audits API Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>
