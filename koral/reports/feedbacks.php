<?php
session_start();
require_once '../db_connection.php';
require_once '../header.php';
require_once '../sidebar.php';

// Check admin permissions
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get filter parameters
$batch_id = $_GET['batch_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$trainer_id = $_GET['trainer_id'] ?? '';
$view_type = $_GET['view'] ?? 'overview';
$export_format = $_GET['export'] ?? '';

// Get all batches and trainers for filter dropdowns
$batches_query = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY start_date DESC");
$batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);

$trainers_query = $db->query("SELECT id, name FROM trainers WHERE is_active = 1 ORDER BY name");
$trainers = $trainers_query->fetchAll(PDO::FETCH_ASSOC);

// Initialize feedback data
$feedback_data = [];
$workshop_feedback_data = [];
$summary_stats = [];
$trend_data = [];

// Get feedback data based on filters
$feedback_query = "
    SELECT 
        f.*,
        b.batch_name,
        t.name as trainer_name,
        CONCAT(s.first_name, ' ', s.last_name) as student_name
    FROM feedback f
    LEFT JOIN batches b ON f.batch_id = b.batch_id
    LEFT JOIN trainers t ON b.batch_mentor_id = t.id
    LEFT JOIN students s ON f.student_name = CONCAT(s.first_name, ' ', s.last_name)
    WHERE f.date BETWEEN ? AND ?
";

$params = [$start_date, $end_date];

if (!empty($batch_id)) {
    $feedback_query .= " AND f.batch_id = ?";
    $params[] = $batch_id;
}

if (!empty($trainer_id)) {
    $feedback_query .= " AND b.batch_mentor_id = ?";
    $params[] = $trainer_id;
}

$feedback_query .= " ORDER BY f.date DESC";

$feedback_stmt = $db->prepare($feedback_query);
$feedback_stmt->execute($params);
$feedback_data = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get workshop feedback data
$workshop_feedback_query = "
    SELECT 
        wf.*,
        w.title as workshop_title,
        w.trainer_id,
        t.name as trainer_name,
        CONCAT(s.first_name, ' ', s.last_name) as student_name
    FROM workshop_feedback wf
    JOIN workshops w ON wf.workshop_id = w.workshop_id
    LEFT JOIN trainers t ON w.trainer_id = t.id
    JOIN students s ON wf.student_id = s.student_id
    WHERE wf.submitted_at BETWEEN ? AND ?
";

$workshop_params = [$start_date, $end_date];

if (!empty($trainer_id)) {
    $workshop_feedback_query .= " AND w.trainer_id = ?";
    $workshop_params[] = $trainer_id;
}

$workshop_feedback_query .= " ORDER BY wf.submitted_at DESC";

$workshop_feedback_stmt = $db->prepare($workshop_feedback_query);
$workshop_feedback_stmt->execute($workshop_params);
$workshop_feedback_data = $workshop_feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if ($export_format === 'csv') {
    exportToCSV($feedback_data, $workshop_feedback_data, $view_type);
} elseif ($export_format === 'excel') {
    exportToExcel($feedback_data, $workshop_feedback_data, $view_type);
}

function exportToCSV($feedback_data, $workshop_feedback_data, $view_type) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=feedback_export_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    if ($view_type === 'workshops' && !empty($workshop_feedback_data)) {
        fputcsv($output, ['Date', 'Workshop', 'Trainer', 'Student', 'Content Rating', 'Delivery Rating', 'Organization Rating', 'Overall Rating']);
        foreach ($workshop_feedback_data as $row) {
            fputcsv($output, [
                date('Y-m-d', strtotime($row['submitted_at'])),
                $row['workshop_title'],
                $row['trainer_name'],
                $row['student_name'],
                $row['content_rating'] ?? '',
                $row['trainer_rating'] ?? '',
                $row['organization_rating'] ?? '',
                $row['rating'] ?? ''
            ]);
        }
    } else {
        fputcsv($output, ['Date', 'Student', 'Batch', 'Trainer', 'Class Rating', 'Assignment Rating', 'Practical Rating', 'Satisfied']);
        foreach ($feedback_data as $row) {
            fputcsv($output, [
                $row['date'],
                $row['student_name'],
                $row['batch_name'],
                $row['trainer_name'],
                $row['class_rating'],
                $row['assignment_understanding'],
                $row['practical_understanding'],
                $row['satisfied']
            ]);
        }
    }
    
    fclose($output);
    exit;
}

function exportToExcel($feedback_data, $workshop_feedback_data, $view_type) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename=feedback_export_' . date('Y-m-d') . '.xls');
    
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th { background: #f0f0f0; font-weight: bold; padding: 8px; border: 1px solid #ddd; }';
    echo 'td { padding: 8px; border: 1px solid #ddd; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    if ($view_type === 'workshops' && !empty($workshop_feedback_data)) {
        echo '<h2>Workshop Feedback Export</h2>';
        echo '<table>';
        echo '<tr><th>Date</th><th>Workshop</th><th>Trainer</th><th>Student</th><th>Content</th><th>Delivery</th><th>Organization</th><th>Overall</th></tr>';
        foreach ($workshop_feedback_data as $row) {
            echo '<tr>';
            echo '<td>' . date('Y-m-d', strtotime($row['submitted_at'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['workshop_title']) . '</td>';
            echo '<td>' . htmlspecialchars($row['trainer_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['student_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['content_rating']) . '</td>';
            echo '<td>' . htmlspecialchars($row['trainer_rating']) . '</td>';
            echo '<td>' . htmlspecialchars($row['organization_rating']) . '</td>';
            echo '<td>' . htmlspecialchars($row['rating']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<h2>Feedback Export</h2>';
        echo '<table>';
        echo '<tr><th>Date</th><th>Student</th><th>Batch</th><th>Trainer</th><th>Class</th><th>Assignment</th><th>Practical</th><th>Satisfied</th></tr>';
        foreach ($feedback_data as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['date']) . '</td>';
            echo '<td>' . htmlspecialchars($row['student_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['batch_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['trainer_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['class_rating']) . '/5</td>';
            echo '<td>' . htmlspecialchars($row['assignment_understanding']) . '/5</td>';
            echo '<td>' . htmlspecialchars($row['practical_understanding']) . '/5</td>';
            echo '<td>' . ($row['satisfied'] ? 'Yes' : 'No') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    echo '</body>';
    echo '</html>';
    exit;
}

// Calculate summary statistics
if (!empty($feedback_data)) {
    // Student Satisfaction Rate
    $total_feedback = count($feedback_data);
    $satisfied_count = count(array_filter($feedback_data, function($f) {
        return $f['satisfied'] == 1 || strtolower($f['satisfied']) === 'yes';
    }));
    $satisfaction_rate = $total_feedback > 0 ? round(($satisfied_count / $total_feedback) * 100, 1) : 0;
    
    // Average Ratings
    $avg_class_rating = round(array_sum(array_column($feedback_data, 'class_rating')) / $total_feedback, 1);
    $avg_assignment_rating = round(array_sum(array_column($feedback_data, 'assignment_understanding')) / $total_feedback, 1);
    $avg_practical_rating = round(array_sum(array_column($feedback_data, 'practical_understanding')) / $total_feedback, 1);
    
    // Regular vs Irregular Analysis
    $regular_feedback = array_filter($feedback_data, function($f) {
        return strtolower($f['is_regular']) === 'yes';
    });
    $irregular_feedback = array_filter($feedback_data, function($f) {
        return strtolower($f['is_regular']) === 'no';
    });
    
    $regular_satisfaction = count($regular_feedback) > 0 ? 
        round(count(array_filter($regular_feedback, function($f) {
            return $f['satisfied'] == 1 || strtolower($f['satisfied']) === 'yes';
        })) / count($regular_feedback) * 100, 1) : 0;
        
    $irregular_satisfaction = count($irregular_feedback) > 0 ? 
        round(count(array_filter($irregular_feedback, function($f) {
            return $f['satisfied'] == 1 || strtolower($f['satisfied']) === 'yes';
        })) / count($irregular_feedback) * 100, 1) : 0;
    
    // Feedback Response Rate (Estimation)
    // This would ideally come from comparing total students vs feedback submissions
    $response_rate = 85; // Placeholder - would need actual student count data
    
    $summary_stats = [
        'total_feedback' => $total_feedback,
        'satisfaction_rate' => $satisfaction_rate,
        'avg_class_rating' => $avg_class_rating,
        'avg_assignment_rating' => $avg_assignment_rating,
        'avg_practical_rating' => $avg_practical_rating,
        'regular_satisfaction' => $regular_satisfaction,
        'irregular_satisfaction' => $irregular_satisfaction,
        'response_rate' => $response_rate
    ];
    
    // Trainer Performance Comparison
    $trainer_performance = [];
    foreach ($feedback_data as $feedback) {
        if (!empty($feedback['trainer_name'])) {
            $trainer = $feedback['trainer_name'];
            if (!isset($trainer_performance[$trainer])) {
                $trainer_performance[$trainer] = [
                    'total_feedback' => 0,
                    'total_rating' => 0,
                    'satisfied_count' => 0
                ];
            }
            
            $trainer_performance[$trainer]['total_feedback']++;
            $trainer_performance[$trainer]['total_rating'] += 
                ($feedback['class_rating'] + $feedback['assignment_understanding'] + $feedback['practical_understanding']) / 3;
            
            if ($feedback['satisfied'] == 1 || strtolower($feedback['satisfied']) === 'yes') {
                $trainer_performance[$trainer]['satisfied_count']++;
            }
        }
    }
    
    // Calculate averages for each trainer
    foreach ($trainer_performance as $trainer => $data) {
        $trainer_performance[$trainer]['avg_rating'] = 
            round($data['total_rating'] / $data['total_feedback'], 1);
        $trainer_performance[$trainer]['satisfaction_rate'] = 
            round(($data['satisfied_count'] / $data['total_feedback']) * 100, 1);
    }
    
    // Workshop vs Regular Classes Comparison
    $workshop_ratings = [];
    $regular_class_ratings = [];
    
    foreach ($workshop_feedback_data as $workshop_feedback) {
        $workshop_ratings[] = $workshop_feedback['rating'];
    }
    
    foreach ($feedback_data as $feedback) {
        $regular_class_ratings[] = ($feedback['class_rating'] + $feedback['assignment_understanding'] + $feedback['practical_understanding']) / 3;
    }
    
    $avg_workshop_rating = !empty($workshop_ratings) ? round(array_sum($workshop_ratings) / count($workshop_ratings), 1) : 0;
    $avg_regular_class_rating = !empty($regular_class_ratings) ? round(array_sum($regular_class_ratings) / count($regular_class_ratings), 1) : 0;
    
    // Monthly Trends
    $monthly_data = [];
    foreach ($feedback_data as $feedback) {
        $month = date('Y-m', strtotime($feedback['date']));
        if (!isset($monthly_data[$month])) {
            $monthly_data[$month] = [
                'total' => 0,
                'satisfied' => 0,
                'class_rating' => 0,
                'assignment_rating' => 0,
                'practical_rating' => 0
            ];
        }
        
        $monthly_data[$month]['total']++;
        if ($feedback['satisfied'] == 1 || strtolower($feedback['satisfied']) === 'yes') {
            $monthly_data[$month]['satisfied']++;
        }
        $monthly_data[$month]['class_rating'] += $feedback['class_rating'];
        $monthly_data[$month]['assignment_rating'] += $feedback['assignment_understanding'];
        $monthly_data[$month]['practical_rating'] += $feedback['practical_understanding'];
    }
    
    // Calculate monthly averages
    $monthly_trends = [];
    foreach ($monthly_data as $month => $data) {
        $monthly_trends[$month] = [
            'satisfaction_rate' => round(($data['satisfied'] / $data['total']) * 100, 1),
            'avg_class_rating' => round($data['class_rating'] / $data['total'], 1),
            'avg_assignment_rating' => round($data['assignment_rating'] / $data['total'], 1),
            'avg_practical_rating' => round($data['practical_rating'] / $data['total'], 1)
        ];
    }
    
    // Workshop Effectiveness (Content vs Delivery)
    $workshop_effectiveness = [
        'content_ratings' => [],
        'delivery_ratings' => [],
        'organization_ratings' => []
    ];
    
    foreach ($workshop_feedback_data as $workshop_feedback) {
        if (!empty($workshop_feedback['content_rating'])) {
            $workshop_effectiveness['content_ratings'][] = $workshop_feedback['content_rating'];
        }
        if (!empty($workshop_feedback['trainer_rating'])) {
            $workshop_effectiveness['delivery_ratings'][] = $workshop_feedback['trainer_rating'];
        }
        if (!empty($workshop_feedback['organization_rating'])) {
            $workshop_effectiveness['organization_ratings'][] = $workshop_feedback['organization_rating'];
        }
    }
    
    $workshop_effectiveness['avg_content'] = !empty($workshop_effectiveness['content_ratings']) ? 
        round(array_sum($workshop_effectiveness['content_ratings']) / count($workshop_effectiveness['content_ratings']), 1) : 0;
    $workshop_effectiveness['avg_delivery'] = !empty($workshop_effectiveness['delivery_ratings']) ? 
        round(array_sum($workshop_effectiveness['delivery_ratings']) / count($workshop_effectiveness['delivery_ratings']), 1) : 0;
    $workshop_effectiveness['avg_organization'] = !empty($workshop_effectiveness['organization_ratings']) ? 
        round(array_sum($workshop_effectiveness['organization_ratings']) / count($workshop_effectiveness['organization_ratings']), 1) : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback Analytics Dashboard</title>
    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        /* Print Styles - Only hide sidebar */
        @media print {
            /* Hide only the sidebar during print */
            .sidebar,
            #sidebar,
            [class*="sidebar"],
            aside {
                display: none !important;
            }
            
            /* Adjust main content margin since sidebar is hidden */
            .ml-64,
            [class*="ml-64"] {
                margin-left: 0 !important;
            }
            
            /* Keep everything else exactly as is */
            body {
                background: white;
            }
            
            /* Ensure all other elements keep their original styles */
            .bg-white,
            .rounded-xl,
            .shadow-md,
            .shadow-lg {
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
            }
            
            /* Keep buttons visible */
            button,
            .btn,
            [class*="btn"] {
                display: inline-block !important;
            }
            
            /* Keep all other elements as they are */
            .no-print {
                display: block !important;
            }
            
            /* Remove any other print-specific overrides */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
        
        /* Regular styles - keep exactly as before */
        .ml-64 {
            margin-left: 16rem;
            padding: 2rem;
            transition: all 0.3s;
        }
        
        .bg-white {
            background-color: white;
        }
        
        .rounded-xl {
            border-radius: 0.75rem;
        }
        
        .shadow-md {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .shadow-lg {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .hover\:shadow-lg:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .transition-all {
            transition-property: all;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 150ms;
        }
        
        .duration-300 {
            transition-duration: 300ms;
        }
        
        .transform {
            --tw-translate-x: 0;
            --tw-translate-y: 0;
            --tw-rotate: 0;
            --tw-skew-x: 0;
            --tw-skew-y: 0;
            --tw-scale-x: 1;
            --tw-scale-y: 1;
            transform: translateX(var(--tw-translate-x)) translateY(var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y));
        }
        
        .hover\:scale-105:hover {
            --tw-scale-x: 1.05;
            --tw-scale-y: 1.05;
        }
        
        .flex {
            display: flex;
        }
        
        .justify-between {
            justify-content: space-between;
        }
        
        .items-center {
            align-items: center;
        }
        
        .mb-8 {
            margin-bottom: 2rem;
        }
        
        .mb-6 {
            margin-bottom: 1.5rem;
        }
        
        .mb-4 {
            margin-bottom: 1rem;
        }
        
        .mt-2 {
            margin-top: 0.5rem;
        }
        
        .mt-4 {
            margin-top: 1rem;
        }
        
        .mr-2 {
            margin-right: 0.5rem;
        }
        
        .p-6 {
            padding: 1.5rem;
        }
        
        .p-8 {
            padding: 2rem;
        }
        
        .px-4 {
            padding-left: 1rem;
            padding-right: 1rem;
        }
        
        .py-2 {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }
        
        .px-6 {
            padding-left: 1.5rem;
            padding-right: 1.5rem;
        }
        
        .py-3 {
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }
        
        .text-3xl {
            font-size: 1.875rem;
            line-height: 2.25rem;
        }
        
        .text-xl {
            font-size: 1.25rem;
            line-height: 1.75rem;
        }
        
        .text-lg {
            font-size: 1.125rem;
            line-height: 1.75rem;
        }
        
        .text-sm {
            font-size: 0.875rem;
            line-height: 1.25rem;
        }
        
        .text-xs {
            font-size: 0.75rem;
            line-height: 1rem;
        }
        
        .font-bold {
            font-weight: 700;
        }
        
        .font-semibold {
            font-weight: 600;
        }
        
        .font-medium {
            font-weight: 500;
        }
        
        .text-gray-800 {
            color: #2d3748;
        }
        
        .text-gray-700 {
            color: #4a5568;
        }
        
        .text-gray-600 {
            color: #718096;
        }
        
        .text-gray-500 {
            color: #a0aec0;
        }
        
        .text-blue-600 {
            color: #3182ce;
        }
        
        .text-green-600 {
            color: #38a169;
        }
        
        .text-purple-600 {
            color: #805ad5;
        }
        
        .text-orange-600 {
            color: #dd6b20;
        }
        
        .text-yellow-500 {
            color: #ecc94b;
        }
        
        .bg-blue-600 {
            background-color: #3182ce;
        }
        
        .bg-green-600 {
            background-color: #38a169;
        }
        
        .bg-purple-600 {
            background-color: #805ad5;
        }
        
        .bg-orange-600 {
            background-color: #dd6b20;
        }
        
        .bg-gray-200 {
            background-color: #edf2f7;
        }
        
        .bg-gray-50 {
            background-color: #fafafa;
        }
        
        .bg-blue-100 {
            background-color: #ebf8ff;
        }
        
        .bg-green-100 {
            background-color: #f0fff4;
        }
        
        .bg-purple-100 {
            background-color: #faf5ff;
        }
        
        .bg-orange-100 {
            background-color: #fffaf0;
        }
        
        .bg-red-100 {
            background-color: #fff5f5;
        }
        
        .border {
            border-width: 1px;
        }
        
        .border-gray-300 {
            border-color: #d2d6dc;
        }
        
        .border-gray-200 {
            border-color: #edf2f7;
        }
        
        .rounded-lg {
            border-radius: 0.5rem;
        }
        
        .rounded-t-lg {
            border-top-left-radius: 0.5rem;
            border-top-right-radius: 0.5rem;
        }
        
        .rounded-full {
            border-radius: 9999px;
        }
        
        .grid {
            display: grid;
        }
        
        .grid-cols-1 {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
        
        .md\:grid-cols-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        
        .lg\:grid-cols-4 {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        
        .lg\:grid-cols-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        
        .lg\:grid-cols-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        
        .gap-6 {
            gap: 1.5rem;
        }
        
        .gap-4 {
            gap: 1rem;
        }
        
        .space-x-4 > * + * {
            margin-left: 1rem;
        }
        
        .w-full {
            width: 100%;
        }
        
        .h-64 {
            height: 16rem;
        }
        
        .h-2 {
            height: 0.5rem;
        }
        
        .min-w-full {
            min-width: 100%;
        }
        
        .overflow-x-auto {
            overflow-x: auto;
        }
        
        .whitespace-nowrap {
            white-space: nowrap;
        }
        
        .divide-y > * + * {
            border-top-width: 1px;
        }
        
        .divide-gray-200 > * + * {
            border-color: #edf2f7;
        }
        
        .focus\:outline-none:focus {
            outline: 2px solid transparent;
            outline-offset: 2px;
        }
        
        .focus\:ring-2:focus {
            box-shadow: 0 0 0 2px rgba(66, 153, 225, 0.5);
        }
        
        .focus\:ring-blue-500:focus {
            --tw-ring-color: #3b82f6;
        }
        
        .hover\:bg-gray-50:hover {
            background-color: #f9fafb;
        }
        
        .hover\:bg-gray-200:hover {
            background-color: #e5e7eb;
        }
        
        .hover\:bg-gray-300:hover {
            background-color: #d1d5db;
        }
        
        .hover\:bg-blue-50:hover {
            background-color: #eff6ff;
        }
        
        .hover\:bg-blue-700:hover {
            background-color: #1d4ed8;
        }
        
        .inline-flex {
            display: inline-flex;
        }
        
        .items-center {
            align-items: center;
        }
        
        .px-2\.5 {
            padding-left: 0.625rem;
            padding-right: 0.625rem;
        }
        
        .py-0\.5 {
            padding-top: 0.125rem;
            padding-bottom: 0.125rem;
        }
        
        .text-green-800 {
            color: #276749;
        }
        
        .text-red-800 {
            color: #9b2c2c;
        }
        
        .bg-gradient-to-r {
            background-image: linear-gradient(to right, var(--tw-gradient-stops));
        }
        
        .from-blue-50 {
            --tw-gradient-from: #eff6ff;
            --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(239, 246, 255, 0));
        }
        
        .to-indigo-50 {
            --tw-gradient-to: #eef2ff;
        }
    </style>
</head>
<body>
<style>
    /* ===== STANDARDIZED BACKGROUND ===== */
    .rpt-orb1 {
        position:fixed; top:-120px; left:-120px;
        width:400px; height:400px; border-radius:50%;
        background:radial-gradient(circle,rgba(99,102,241,.12) 0%,transparent 70%);
        animation:rptOrb1 20s ease-in-out infinite alternate;
        pointer-events:none; z-index:0;
    }
    .rpt-orb2 {
        position:fixed; bottom:-100px; right:-100px;
        width:360px; height:360px; border-radius:50%;
        background:radial-gradient(circle,rgba(139,92,246,.1) 0%,transparent 70%);
        animation:rptOrb2 25s ease-in-out infinite alternate;
        pointer-events:none; z-index:0;
    }
    @keyframes rptOrb1 { from{transform:translate(0,0) scale(1)} to{transform:translate(50px,40px) scale(1.1)} }
    @keyframes rptOrb2 { from{transform:translate(0,0) scale(1)} to{transform:translate(-40px,-50px) scale(1.12)} }

    /* Glass panels */
    .glass-panel {
        background:rgba(255,255,255,.85);
        backdrop-filter:blur(12px);
        -webkit-backdrop-filter:blur(12px);
        border:1px solid rgba(99,102,241,.12);
        box-shadow:0 8px 24px rgba(99,102,241,.1);
        border-radius:20px;
    }
    
    /* Gradient Stat Cards */
    .stat-card-gradient {
        border-radius:20px; color:#fff; overflow:hidden; position:relative;
        box-shadow:0 10px 25px rgba(0,0,0,0.1);
        transition:transform 0.4s ease,box-shadow 0.4s ease; padding:24px;
        cursor:pointer;
    }
    .stat-card-gradient:hover { transform:translateY(-5px); box-shadow:0 15px 35px rgba(0,0,0,0.2); }
    .stat-card-gradient::after {
        content:''; position:absolute; top:0; left:-100%; width:50%; height:100%;
        background:linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent);
        transform:skewX(-25deg); animation:shimmer 3s infinite;
    }
    @keyframes shimmer { 100%{left:200%} }
    .scg-blue { background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%); }
    .scg-teal { background:linear-gradient(135deg,#14b8a6 0%,#0d9488 100%); }
    .scg-violet{ background:linear-gradient(135deg,#8b5cf6 0%,#7c3aed 100%); }
    .scg-orange{ background:linear-gradient(135deg,#f97316 0%,#ea580c 100%); }
    .scg-pink { background:linear-gradient(135deg,#ec4899 0%,#db2777 100%); }
    .scg-label { font-size:0.875rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; opacity:0.9; }
    .scg-number { font-size:2.5rem; font-weight:800; line-height:1; margin-top:8px; text-shadow:0 2px 10px rgba(0,0,0,0.1); }
</style>

<div class="ml-64 p-8 transition-all duration-300 min-h-screen" style="background: linear-gradient(145deg,#eef2ff 0%,#e0e7ff 30%,#f0f4ff 60%,#ede9fe 100%); position:relative; overflow-x:hidden;">
    <div class="rpt-orb1"></div>
    <div class="rpt-orb2"></div>

    <div class="relative z-10">
        <!-- Main Navigation Tabs -->
        <div class="mb-8">
            <?php include 'navbar.php'?>
        </div>

    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Feedback Analytics Dashboard</h1>
        <div class="flex space-x-4">
            <button onclick="window.print()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-print mr-2"></i> Print Report
            </button>
            <button onclick="exportToPDF()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-file-pdf mr-2"></i> Export PDF
            </button>
            <button onclick="exportToCSV()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-file-csv mr-2"></i> Export CSV
            </button>
            <button onclick="exportToExcel()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-file-excel mr-2"></i> Export Excel
            </button>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="glass-panel p-6 mb-8 transition-all">
        <h2 class="text-xl font-semibold mb-4 text-gray-700">Filter Feedback Data</h2>
        <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Batch</label>
                <select name="batch_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $batch): ?>
                        <option value="<?= $batch['batch_id'] ?>" <?= $batch_id === $batch['batch_id'] ? 'selected' : '' ?>>
                            <?= $batch['batch_name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Trainer</label>
                <select name="trainer_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                    <option value="">All Trainers</option>
                    <?php foreach ($trainers as $trainer): ?>
                        <option value="<?= $trainer['id'] ?>" <?= $trainer_id === $trainer['id'] ? 'selected' : '' ?>>
                            <?= $trainer['name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" value="<?= $start_date ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" value="<?= $end_date ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
            </div>
            
            <div class="md:col-span-4 flex justify-end space-x-4">
                <button type="reset" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300 transition-colors transform hover:scale-105">
                    <i class="fas fa-redo mr-2"></i> Reset
                </button>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors transform hover:scale-105">
                    <i class="fas fa-filter mr-2"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- View Tabs -->
    <div class="flex mb-6 border-b border-gray-200">
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'overview'])) ?>" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= $view_type === 'overview' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-chart-pie mr-2"></i> Overview
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'satisfaction'])) ?>" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= $view_type === 'satisfaction' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-smile mr-2"></i> Satisfaction
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'ratings'])) ?>" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= $view_type === 'ratings' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-star mr-2"></i> Ratings
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'trainers'])) ?>" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= $view_type === 'trainers' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-chalkboard-teacher mr-2"></i> Trainers
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'workshops'])) ?>" class="px-4 py-2 font-medium text-sm rounded-t-lg transition-all duration-300 <?= $view_type === 'workshops' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
            <i class="fas fa-microphone mr-2"></i> Workshops
        </a>
    </div>

    <!-- Overview View -->
    <?php if ($view_type === 'overview'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Student Satisfaction Rate -->
            <div class="stat-card-gradient scg-blue">
                <p class="scg-label mb-2"><i class="fas fa-smile mr-2"></i>Student Satisfaction</p>
                <h3 class="scg-number"><?= $summary_stats['satisfaction_rate'] ?? 0 ?>%</h3>
                <p class="text-sm opacity-80 mt-2">Based on <?= $summary_stats['total_feedback'] ?? 0 ?> submissions</p>
            </div>

            <!-- Average Class Rating -->
            <div class="stat-card-gradient scg-teal" style="animation-delay:.1s">
                <p class="scg-label mb-2"><i class="fas fa-chalkboard mr-2"></i>Class Rating</p>
                <h3 class="scg-number"><?= $summary_stats['avg_class_rating'] ?? 0 ?>/5</h3>
                <p class="text-sm opacity-80 mt-2">Average class experience</p>
            </div>

            <!-- Assignment Understanding -->
            <div class="stat-card-gradient scg-violet" style="animation-delay:.2s">
                <p class="scg-label mb-2"><i class="fas fa-tasks mr-2"></i>Assignments</p>
                <h3 class="scg-number"><?= $summary_stats['avg_assignment_rating'] ?? 0 ?>/5</h3>
                <p class="text-sm opacity-80 mt-2">Assignment clarity & support</p>
            </div>

            <!-- Practical Understanding -->
            <div class="stat-card-gradient scg-orange" style="animation-delay:.3s">
                <p class="scg-label mb-2"><i class="fas fa-flask mr-2"></i>Practical Skills</p>
                <h3 class="scg-number"><?= $summary_stats['avg_practical_rating'] ?? 0 ?>/5</h3>
                <p class="text-sm opacity-80 mt-2">Hands-on learning effectiveness</p>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Satisfaction Trends -->
            <div class="glass-panel p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Satisfaction Trends</h3>
                <div class="h-64">
                    <canvas id="satisfactionTrendChart"></canvas>
                </div>
            </div>

            <!-- Rating Distribution -->
            <div class="glass-panel p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Rating Distribution</h3>
                <div class="h-64">
                    <canvas id="ratingDistributionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Recent Feedback -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">Recent Feedback</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assignments</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Practical</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Satisfied</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($feedback_data)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                    No feedback records found matching your criteria
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_slice($feedback_data, 0, 10) as $index => $row): ?>
                                <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?> hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= date('M d, Y', strtotime($row['date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= $row['student_name'] ?? 'N/A' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= $row['batch_name'] ?? 'N/A' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center">
                                            <span class="text-yellow-500 mr-1">
                                                <i class="fas fa-star"></i>
                                            </span>
                                            <span><?= $row['class_rating'] ?? 0 ?>/5</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center">
                                            <span class="text-yellow-500 mr-1">
                                                <i class="fas fa-star"></i>
                                            </span>
                                            <span><?= $row['assignment_understanding'] ?? 0 ?>/5</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center">
                                            <span class="text-yellow-500 mr-1">
                                                <i class="fas fa-star"></i>
                                            </span>
                                            <span><?= $row['practical_understanding'] ?? 0 ?>/5</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            <?= ($row['satisfied'] == 1 || strtolower($row['satisfied']) === 'yes') ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= ($row['satisfied'] == 1 || strtolower($row['satisfied']) === 'yes') ? 'Yes' : 'No' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Satisfaction View -->
    <?php if ($view_type === 'satisfaction'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Overall Satisfaction -->
            <div class="bg-white rounded-xl shadow-md p-6 lg:col-span-2">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Student Satisfaction Overview</h3>
                <div class="h-64">
                    <canvas id="satisfactionOverviewChart"></canvas>
                </div>
            </div>

            <!-- Satisfaction Details -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Satisfaction Details</h3>
                <div class="space-y-4">
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-sm font-medium text-gray-700">Overall Satisfaction</span>
                            <span class="text-sm font-medium text-gray-700"><?= $summary_stats['satisfaction_rate'] ?? 0 ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-blue-600 h-2 rounded-full" style="width: <?= $summary_stats['satisfaction_rate'] ?? 0 ?>%"></div>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-sm font-medium text-gray-700">Regular Students</span>
                            <span class="text-sm font-medium text-gray-700"><?= $summary_stats['regular_satisfaction'] ?? 0 ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full" style="width: <?= $summary_stats['regular_satisfaction'] ?? 0 ?>%"></div>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-sm font-medium text-gray-700">Irregular Students</span>
                            <span class="text-sm font-medium text-gray-700"><?= $summary_stats['irregular_satisfaction'] ?? 0 ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-red-600 h-2 rounded-full" style="width: <?= $summary_stats['irregular_satisfaction'] ?? 0 ?>%"></div>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-sm font-medium text-gray-700">Feedback Response Rate</span>
                            <span class="text-sm font-medium text-gray-700"><?= $summary_stats['response_rate'] ?? 0 ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-purple-600 h-2 rounded-full" style="width: <?= $summary_stats['response_rate'] ?? 0 ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Regular vs Irregular Analysis -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Regular vs Irregular Student Satisfaction</h3>
            <div class="h-64">
                <canvas id="regularIrregularChart"></canvas>
            </div>
        </div>

        <!-- Monthly Satisfaction Trends -->
        <div class="bg-white rounded-xl shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Monthly Satisfaction Trends</h3>
            <div class="h-64">
                <canvas id="monthlySatisfactionChart"></canvas>
            </div>
        </div>
    <?php endif; ?>

    <!-- Ratings View -->
    <?php if ($view_type === 'ratings'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Rating Trends -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Rating Trends Over Time</h3>
                <div class="h-64">
                    <canvas id="ratingTrendsChart"></canvas>
                </div>
            </div>

            <!-- Rating Distribution -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Rating Distribution</h3>
                <div class="h-64">
                    <canvas id="ratingsDistributionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Detailed Ratings -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Detailed Rating Analysis</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="text-4xl font-bold text-blue-600 mb-2"><?= $summary_stats['avg_class_rating'] ?? 0 ?></div>
                    <div class="text-lg font-medium text-gray-700">Class Rating</div>
                    <div class="flex justify-center mt-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($summary_stats['avg_class_rating'] ?? 0) ? 'text-yellow-500' : 'text-gray-300' ?> mx-0.5"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="text-center">
                    <div class="text-4xl font-bold text-green-600 mb-2"><?= $summary_stats['avg_assignment_rating'] ?? 0 ?></div>
                    <div class="text-lg font-medium text-gray-700">Assignment Rating</div>
                    <div class="flex justify-center mt-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($summary_stats['avg_assignment_rating'] ?? 0) ? 'text-yellow-500' : 'text-gray-300' ?> mx-0.5"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="text-center">
                    <div class="text-4xl font-bold text-purple-600 mb-2"><?= $summary_stats['avg_practical_rating'] ?? 0 ?></div>
                    <div class="text-lg font-medium text-gray-700">Practical Rating</div>
                    <div class="flex justify-center mt-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($summary_stats['avg_practical_rating'] ?? 0) ? 'text-yellow-500' : 'text-gray-300' ?> mx-0.5"></i>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rating Comparison by Batch -->
        <div class="bg-white rounded-xl shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Rating Comparison by Batch</h3>
            <div class="h-64">
                <canvas id="batchComparisonChart"></canvas>
            </div>
        </div>
    <?php endif; ?>

    <!-- Trainers View -->
    <?php if ($view_type === 'trainers'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Trainer Performance -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Trainer Performance Overview</h3>
                <div class="h-64">
                    <canvas id="trainerPerformanceChart"></canvas>
                </div>
            </div>

            <!-- Satisfaction by Trainer -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Satisfaction by Trainer</h3>
                <div class="h-64">
                    <canvas id="trainerSatisfactionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Trainer Details Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">Trainer Performance Details</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trainer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Feedback</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Rating</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Satisfaction Rate</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Performance</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($trainer_performance)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                    No trainer performance data available
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($trainer_performance as $trainer => $data): ?>
                                <tr class="hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= $trainer ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= $data['total_feedback'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= $data['avg_rating'] ?>/5
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= $data['satisfaction_rate'] ?>%
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-24 bg-gray-200 rounded-full h-2 mr-2">
                                                <div class="bg-green-600 h-2 rounded-full" style="width: <?= ($data['avg_rating'] / 5) * 100 ?>%"></div>
                                            </div>
                                            <span class="text-sm text-gray-600"><?= $data['avg_rating'] ?>/5</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Workshops View -->
    <?php if ($view_type === 'workshops'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Workshop vs Regular Classes -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Workshop vs Regular Classes</h3>
                <div class="h-64">
                    <canvas id="workshopComparisonChart"></canvas>
                </div>
            </div>

            <!-- Workshop Effectiveness -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Workshop Effectiveness</h3>
                <div class="h-64">
                    <canvas id="workshopEffectivenessChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Workshop Details -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Workshop Performance Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="text-4xl font-bold text-blue-600 mb-2"><?= $workshop_effectiveness['avg_content'] ?? 0 ?></div>
                    <div class="text-lg font-medium text-gray-700">Content Rating</div>
                    <div class="flex justify-center mt-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($workshop_effectiveness['avg_content'] ?? 0) ? 'text-yellow-500' : 'text-gray-300' ?> mx-0.5"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="text-center">
                    <div class="text-4xl font-bold text-green-600 mb-2"><?= $workshop_effectiveness['avg_delivery'] ?? 0 ?></div>
                    <div class="text-lg font-medium text-gray-700">Delivery Rating</div>
                    <div class="flex justify-center mt-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($workshop_effectiveness['avg_delivery'] ?? 0) ? 'text-yellow-500' : 'text-gray-300' ?> mx-0.5"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="text-center">
                    <div class="text-4xl font-bold text-purple-600 mb-2"><?= $workshop_effectiveness['avg_organization'] ?? 0 ?></div>
                    <div class="text-lg font-medium text-gray-700">Organization Rating</div>
                    <div class="flex justify-center mt-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($workshop_effectiveness['avg_organization'] ?? 0) ? 'text-yellow-500' : 'text-gray-300' ?> mx-0.5"></i>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Workshop Feedback -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">Recent Workshop Feedback</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Workshop</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trainer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Content</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Delivery</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organization</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Overall</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($workshop_feedback_data)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                    No workshop feedback records found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_slice($workshop_feedback_data, 0, 10) as $index => $row): ?>
                                <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?> hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= date('M d, Y', strtotime($row['submitted_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= $row['workshop_title'] ?? 'N/A' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= $row['trainer_name'] ?? 'N/A' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center">
                                            <span class="text-yellow-500 mr-1">
                                                <i class="fas fa-star"></i>
                                            </span>
                                            <span><?= $row['content_rating'] ?? 0 ?>/5</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center">
                                            <span class="text-yellow-500 mr-1">
                                                <i class="fas fa-star"></i>
                                            </span>
                                            <span><?= $row['trainer_rating'] ?? 0 ?>/5</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center">
                                            <span class="text-yellow-500 mr-1">
                                                <i class="fas fa-star"></i>
                                            </span>
                                            <span><?= $row['organization_rating'] ?? 0 ?>/5</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center">
                                            <span class="text-yellow-500 mr-1">
                                                <i class="fas fa-star"></i>
                                            </span>
                                            <span><?= $row['rating'] ?? 0 ?>/5</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Function to export dashboard as PDF (using browser print)
function exportToPDF() {
    window.print();
}

// Export to CSV
function exportToCSV() {
    window.location.href = '?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>';
}

// Export to Excel
function exportToExcel() {
    window.location.href = '?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>';
}

// Initialize all charts when the page loads
document.addEventListener('DOMContentLoaded', function() {
    // Sample data for charts - replace with actual data from PHP
    const monthlyData = <?= json_encode($monthly_trends) ?>;
    const trainerPerformance = <?= json_encode($trainer_performance) ?>;
    const workshopEffectiveness = <?= json_encode($workshop_effectiveness) ?>;
    const summaryStats = <?= json_encode($summary_stats) ?>;
    
    // Example: Satisfaction Trend Chart
    if (document.getElementById('satisfactionTrendChart')) {
        const ctx = document.getElementById('satisfactionTrendChart').getContext('2d');
        const months = Object.keys(monthlyData);
        const satisfactionRates = months.map(month => monthlyData[month].satisfaction_rate);
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Satisfaction Rate',
                    data: satisfactionRates,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Rating Distribution Chart
    if (document.getElementById('ratingDistributionChart')) {
        const ctx = document.getElementById('ratingDistributionChart').getContext('2d');
        
        // Calculate rating distribution
        const ratings = [1,2,3,4,5];
        const distribution = [0,0,0,0,0];
        
        <?php foreach ($feedback_data as $feedback): ?>
            distribution[Math.floor(<?= $feedback['class_rating'] ?>)-1]++;
            distribution[Math.floor(<?= $feedback['assignment_understanding'] ?>)-1]++;
            distribution[Math.floor(<?= $feedback['practical_understanding'] ?>)-1]++;
        <?php endforeach; ?>
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'],
                datasets: [{
                    label: 'Number of Ratings',
                    data: distribution,
                    backgroundColor: [
                        'rgba(239, 68, 68, 0.5)',
                        'rgba(249, 115, 22, 0.5)',
                        'rgba(245, 158, 11, 0.5)',
                        'rgba(16, 185, 129, 0.5)',
                        'rgba(59, 130, 246, 0.5)'
                    ],
                    borderColor: [
                        '#ef4444',
                        '#f97316',
                        '#f59e0b',
                        '#10b981',
                        '#3b82f6'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
    
    // Trainer Performance Chart
    if (document.getElementById('trainerPerformanceChart')) {
        const ctx = document.getElementById('trainerPerformanceChart').getContext('2d');
        const trainers = <?= json_encode(array_keys($trainer_performance)) ?>;
        const avgRatings = <?= json_encode(array_column($trainer_performance, 'avg_rating')) ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: trainers,
                datasets: [{
                    label: 'Average Rating',
                    data: avgRatings,
                    backgroundColor: 'rgba(59, 130, 246, 0.5)',
                    borderColor: '#3b82f6',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 5,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
    
    // Workshop Comparison Chart
    if (document.getElementById('workshopComparisonChart')) {
        const ctx = document.getElementById('workshopComparisonChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Regular Classes', 'Workshops'],
                datasets: [{
                    label: 'Average Rating',
                    data: [
                        <?= $avg_regular_class_rating ?? 0 ?>,
                        <?= $avg_workshop_rating ?? 0 ?>
                    ],
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.5)',
                        'rgba(16, 185, 129, 0.5)'
                    ],
                    borderColor: [
                        '#3b82f6',
                        '#10b981'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 5,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
    
    // Workshop Effectiveness Chart
    if (document.getElementById('workshopEffectivenessChart')) {
        const ctx = document.getElementById('workshopEffectivenessChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'radar',
            data: {
                labels: ['Content', 'Delivery', 'Organization'],
                datasets: [{
                    label: 'Ratings',
                    data: [
                        <?= $workshop_effectiveness['avg_content'] ?? 0 ?>,
                        <?= $workshop_effectiveness['avg_delivery'] ?? 0 ?>,
                        <?= $workshop_effectiveness['avg_organization'] ?? 0 ?>
                    ],
                    backgroundColor: 'rgba(59, 130, 246, 0.2)',
                    borderColor: '#3b82f6',
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: '#3b82f6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 5,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
    
    // Additional chart initializations for other views...
    // Satisfaction Overview Chart
    if (document.getElementById('satisfactionOverviewChart')) {
        const ctx = document.getElementById('satisfactionOverviewChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Satisfied', 'Not Satisfied'],
                datasets: [{
                    data: [
                        <?= $satisfied_count ?? 0 ?>,
                        <?= ($total_feedback - ($satisfied_count ?? 0)) ?>
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#ef4444'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
    
    // Regular vs Irregular Chart
    if (document.getElementById('regularIrregularChart')) {
        const ctx = document.getElementById('regularIrregularChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Regular Students', 'Irregular Students'],
                datasets: [{
                    label: 'Satisfaction Rate (%)',
                    data: [
                        <?= $summary_stats['regular_satisfaction'] ?? 0 ?>,
                        <?= $summary_stats['irregular_satisfaction'] ?? 0 ?>
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#ef4444'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Monthly Satisfaction Chart
    if (document.getElementById('monthlySatisfactionChart')) {
        const ctx = document.getElementById('monthlySatisfactionChart').getContext('2d');
        const months = Object.keys(monthlyData);
        const satisfactionRates = months.map(month => monthlyData[month].satisfaction_rate);
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Satisfaction Rate',
                    data: satisfactionRates,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Rating Trends Chart
    if (document.getElementById('ratingTrendsChart')) {
        const ctx = document.getElementById('ratingTrendsChart').getContext('2d');
        const months = Object.keys(monthlyData);
        const classRatings = months.map(month => monthlyData[month].avg_class_rating);
        const assignmentRatings = months.map(month => monthlyData[month].avg_assignment_rating);
        const practicalRatings = months.map(month => monthlyData[month].avg_practical_rating);
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Class Rating',
                        data: classRatings,
                        borderColor: '#3b82f6',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4
                    },
                    {
                        label: 'Assignment Rating',
                        data: assignmentRatings,
                        borderColor: '#10b981',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4
                    },
                    {
                        label: 'Practical Rating',
                        data: practicalRatings,
                        borderColor: '#f59e0b',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 5,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
    
    // Ratings Distribution Chart
    if (document.getElementById('ratingsDistributionChart')) {
        const ctx = document.getElementById('ratingsDistributionChart').getContext('2d');
        
        // Calculate rating distribution percentages
        const totalRatings = <?= $total_feedback * 3 ?>; // 3 ratings per feedback
        const ratingCounts = distribution;
        const ratingPercentages = ratingCounts.map(count => ((count / totalRatings) * 100).toFixed(1));
        
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'],
                datasets: [{
                    data: ratingPercentages,
                    backgroundColor: [
                        '#ef4444',
                        '#f97316',
                        '#f59e0b',
                        '#10b981',
                        '#3b82f6'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.raw + '%';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Batch Comparison Chart
    if (document.getElementById('batchComparisonChart')) {
        const ctx = document.getElementById('batchComparisonChart').getContext('2d');
        
        // Group feedback by batch
        const batchRatings = {};
        <?php foreach ($feedback_data as $feedback): ?>
            <?php if (!empty($feedback['batch_name'])): ?>
                if (!batchRatings['<?= addslashes($feedback['batch_name']) ?>']) {
                    batchRatings['<?= addslashes($feedback['batch_name']) ?>'] = {
                        class: [],
                        assignment: [],
                        practical: []
                    };
                }
                batchRatings['<?= addslashes($feedback['batch_name']) ?>'].class.push(<?= $feedback['class_rating'] ?>);
                batchRatings['<?= addslashes($feedback['batch_name']) ?>'].assignment.push(<?= $feedback['assignment_understanding'] ?>);
                batchRatings['<?= addslashes($feedback['batch_name']) ?>'].practical.push(<?= $feedback['practical_understanding'] ?>);
            <?php endif; ?>
        <?php endforeach; ?>
        
        const batches = Object.keys(batchRatings);
        const avgClassRatings = batches.map(batch => {
            const ratings = batchRatings[batch].class;
            return (ratings.reduce((a, b) => a + b, 0) / ratings.length).toFixed(1);
        });
        const avgAssignmentRatings = batches.map(batch => {
            const ratings = batchRatings[batch].assignment;
            return (ratings.reduce((a, b) => a + b, 0) / ratings.length).toFixed(1);
        });
        const avgPracticalRatings = batches.map(batch => {
            const ratings = batchRatings[batch].practical;
            return (ratings.reduce((a, b) => a + b, 0) / ratings.length).toFixed(1);
        });
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: batches,
                datasets: [
                    {
                        label: 'Class Rating',
                        data: avgClassRatings,
                        backgroundColor: 'rgba(59, 130, 246, 0.5)',
                        borderColor: '#3b82f6',
                        borderWidth: 1
                    },
                    {
                        label: 'Assignment Rating',
                        data: avgAssignmentRatings,
                        backgroundColor: 'rgba(16, 185, 129, 0.5)',
                        borderColor: '#10b981',
                        borderWidth: 1
                    },
                    {
                        label: 'Practical Rating',
                        data: avgPracticalRatings,
                        backgroundColor: 'rgba(245, 158, 11, 0.5)',
                        borderColor: '#f59e0b',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 5,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
    
    // Trainer Satisfaction Chart
    if (document.getElementById('trainerSatisfactionChart')) {
        const ctx = document.getElementById('trainerSatisfactionChart').getContext('2d');
        const trainers = <?= json_encode(array_keys($trainer_performance)) ?>;
        const satisfactionRates = <?= json_encode(array_column($trainer_performance, 'satisfaction_rate')) ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: trainers,
                datasets: [{
                    label: 'Satisfaction Rate (%)',
                    data: satisfactionRates,
                    backgroundColor: 'rgba(16, 185, 129, 0.5)',
                    borderColor: '#10b981',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>
    </div>
</div>
<?php require_once '../footer.php'; ?>
</body>
</html>