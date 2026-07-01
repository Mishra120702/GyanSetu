<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $today = date('Y-m-d');
    
    // Top Cards
    // Total Logs
    $totalLogsStmt = $db->query("SELECT COUNT(*) FROM student_activity_log");
    $totalLogs = $totalLogsStmt->fetchColumn();

    // Unique Pages Visited
    $uniquePagesStmt = $db->query("SELECT COUNT(DISTINCT page_url) FROM student_activity_log");
    $uniquePages = $uniquePagesStmt->fetchColumn();

    // Active Students Today
    $activeTodayStmt = $db->prepare("SELECT COUNT(DISTINCT student_id) FROM student_activity_log WHERE DATE(last_ping_time) = ?");
    $activeTodayStmt->execute([$today]);
    $activeStudentsToday = $activeTodayStmt->fetchColumn();

    // Total Time Spent
    $timeSpentStmt = $db->query("SELECT TIMESTAMPDIFF(SECOND, session_start_time, last_ping_time) as diff FROM student_activity_log");
    $timeDiffs = $timeSpentStmt->fetchAll(PDO::FETCH_COLUMN);
    $totalSeconds = array_sum($timeDiffs);
    
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $totalTimeSpent = "{$hours}h {$minutes}m";

    // Charts Section
    // Top 5 Most Visited Pages
    $topPagesStmt = $db->query("SELECT page_url, COUNT(*) as visits FROM student_activity_log GROUP BY page_url ORDER BY visits DESC LIMIT 5");
    $topPagesRaw = $topPagesStmt->fetchAll(PDO::FETCH_ASSOC);
    $topPagesLabels = [];
    $topPagesData = [];
    foreach ($topPagesRaw as $row) {
        $clean = basename(parse_url($row['page_url'], PHP_URL_PATH));
        if (empty($clean)) $clean = '/';
        $topPagesLabels[] = $clean;
        $topPagesData[] = (int)$row['visits'];
    }

    // Activity Trend (Last 7 Days)
    $trendStmt = $db->query("
        SELECT DATE(last_ping_time) as date, COUNT(*) as activity_count 
        FROM student_activity_log 
        WHERE last_ping_time >= DATE(NOW()) - INTERVAL 6 DAY 
        GROUP BY DATE(last_ping_time) 
        ORDER BY date ASC
    ");
    $trendRaw = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fill in missing days
    $trendLabels = [];
    $trendData = [];
    $trendMap = [];
    foreach ($trendRaw as $row) {
        $trendMap[$row['date']] = (int)$row['activity_count'];
    }
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $trendLabels[] = date('D', strtotime($date));
        $trendData[] = $trendMap[$date] ?? 0;
    }

    // Leaderboard Section: Top 10 Active Students
    $leaderboardStmt = $db->query("
        SELECT a.student_id, u.name, 
               SUM(TIMESTAMPDIFF(SECOND, a.session_start_time, a.last_ping_time)) as total_seconds
        FROM student_activity_log a
        JOIN users u ON a.student_id = u.id
        GROUP BY a.student_id, u.name
        ORDER BY total_seconds DESC
        LIMIT 10
    ");
    $leaderboardRaw = $leaderboardStmt->fetchAll(PDO::FETCH_ASSOC);
    $leaderboard = array_map(function($row) {
        $secs = $row['total_seconds'];
        $hrs = floor($secs / 3600);
        $mins = floor(($secs % 3600) / 60);
        $time_str = $hrs > 0 ? "{$hrs}h {$mins}m" : "{$mins}m";
        if ($hrs == 0 && $mins == 0) $time_str = "{$secs}s";
        return [
            'name' => htmlspecialchars($row['name']),
            'time_spent' => $time_str
        ];
    }, $leaderboardRaw);

    echo json_encode([
        'cards' => [
            'total_logs' => number_format($totalLogs),
            'unique_pages' => number_format($uniquePages),
            'time_spent' => $totalTimeSpent,
            'active_today' => number_format($activeStudentsToday)
        ],
        'charts' => [
            'top_pages' => [
                'labels' => $topPagesLabels,
                'data' => $topPagesData
            ],
            'trend' => [
                'labels' => $trendLabels,
                'data' => $trendData
            ]
        ],
        'leaderboard' => $leaderboard
    ]);

} catch(PDOException $e) {
    http_response_code(500);
    error_log("Activity Analytics Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>
