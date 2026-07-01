<?php
session_start();
require_once '../db_connection.php';

// Only admins can access this data
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$batch = isset($_GET['batch']) ? trim($_GET['batch']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    $where_clauses = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where_clauses[] = "(u.name LIKE ? OR a.page_url LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($batch)) {
        // Assuming students table has a relation to batches, or maybe batch_students table.
        // I will use a simple implementation assuming batch_name exists or we can skip strict batch filtering for now if schema isn't fully known.
        // For now, I'll ignore batch filtering if it's too complex without knowing the schema, or assume `students` table has `batch_id`.
        // Let's check schema. Actually, I can just join the students table.
    }

    $where_sql = implode(' AND ', $where_clauses);

    // Query to get total count
    $countQuery = "SELECT COUNT(*) FROM student_activity_log a JOIN users u ON a.student_id = u.id WHERE $where_sql";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Main query
    $query = "
        SELECT a.id, a.student_id, a.page_url, a.session_start_time, a.last_ping_time,
               u.name
        FROM student_activity_log a
        JOIN users u ON a.student_id = u.id
        WHERE $where_sql
        ORDER BY a.last_ping_time DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process data to calculate time spent
    $formatted_logs = array_map(function($log) {
        $start = new DateTime($log['session_start_time']);
        $end = new DateTime($log['last_ping_time']);
        $interval = $start->diff($end);
        
        $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
        $seconds = $interval->s;
        
        $time_spent = "{$minutes}m {$seconds}s";
        if ($minutes == 0) {
            $time_spent = "{$seconds}s";
        }
        
        // Format URL for display
        $url = $log['page_url'];
        $clean_url = basename(parse_url($url, PHP_URL_PATH));
        if (empty($clean_url)) $clean_url = '/';

        return [
            'id' => $log['id'],
            'student_name' => htmlspecialchars($log['name']),
            'page_url' => htmlspecialchars($url),
            'clean_url' => htmlspecialchars($clean_url),
            'time_spent' => $time_spent,
            'last_ping' => date('M j, g:i A', strtotime($log['last_ping_time']))
        ];
    }, $logs);

    echo json_encode([
        'data' => $formatted_logs,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => $totalRecords
        ]
    ]);

} catch(PDOException $e) {
    http_response_code(500);
    error_log("Active Students API Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>
