<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Get current user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

// Get batches for dropdown
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC);

// Get trainers for weekly feedback filter
$trainers = $db->query("SELECT id, name FROM trainers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get active students for filter
$students = $db->query("SELECT student_id, CONCAT(first_name, ' ', last_name) as full_name FROM students WHERE current_status = 'active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

// Determine active tab
$active_tab = $_GET['tab'] ?? 'student_feedback';

// Handle student feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $satisfied_value = ($_POST['satisfied'] === 'Yes') ? 1 : 0;
    
    $stmt = $db->prepare("INSERT INTO feedback (date, student_name, email, is_regular, batch_id, course_name, 
                         class_rating, assignment_understanding, practical_understanding, satisfied, 
                         suggestions, feedback_text) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        date('Y-m-d'),
        $_POST['student_name'],
        $_POST['email'],
        $_POST['regular_in_class'],
        $_POST['batch_id'],
        $_POST['course_name'],
        $_POST['class_rating'],
        $_POST['assignment_understanding'],
        $_POST['practical_understanding'],
        $satisfied_value,
        $_POST['suggestions'],
        $_POST['feedback_text']
    ]);
    $success = true;
}

// Handle bulk update for show_to_trainer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_trainer'])) {
    $feedback_ids = $_POST['feedback_ids'] ?? [];
    $show_value = $_POST['show_to_trainer'] ?? 0;
    
    if (!empty($feedback_ids)) {
        $placeholders = implode(',', array_fill(0, count($feedback_ids), '?'));
        $stmt = $db->prepare("UPDATE feedback SET show_to_trainer = ? WHERE id IN ($placeholders)");
        $params = array_merge([$show_value], $feedback_ids);
        $stmt->execute($params);
        $bulk_success = true;
    }
}

// Handle individual update for show_to_trainer via AJAX
if (isset($_GET['ajax_update_show_to_trainer'])) {
    header('Content-Type: application/json');
    $feedback_id = $_GET['feedback_id'] ?? 0;
    $show_value = $_GET['show_value'] ?? 0;
    
    $stmt = $db->prepare("UPDATE feedback SET show_to_trainer = ? WHERE id = ?");
    $result = $stmt->execute([$show_value, $feedback_id]);
    
    echo json_encode(['success' => $result]);
    exit;
}

// Handle action taken update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_action'])) {
    $feedback_id = $_POST['feedback_id'];
    $action_taken = $_POST['action_taken'];
    
    $stmt = $db->prepare("UPDATE feedback SET action_taken = ?, action_taken_time = NOW() WHERE id = ?");
    $result = $stmt->execute([$action_taken, $feedback_id]);
    
    if ($result) {
        $action_updated = true;
    }
}

// ==================== STUDENT FEEDBACK FILTERS & SORTING ====================
$batch_filter = $_GET['batch'] ?? '';
$rating_filter = $_GET['rating'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$satisfaction_filter = $_GET['satisfaction'] ?? '';
$regularity_filter = $_GET['regularity'] ?? '';
$action_filter = $_GET['action'] ?? '';
$student_name_filter = $_GET['student_name'] ?? '';
$course_filter = $_GET['course'] ?? '';
$show_to_trainer_filter = $_GET['show_to_trainer'] ?? '';

// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$offset = ($page - 1) * $per_page;

// Sorting parameters
$sort_column = $_GET['sort'] ?? 'date';
$sort_order = $_GET['order'] ?? 'desc';

$valid_columns = ['date', 'student_name', 'batch_id', 'course_name', 'class_rating', 'assignment_understanding', 'practical_understanding', 'satisfied', 'action_taken', 'show_to_trainer'];
if (!in_array($sort_column, $valid_columns)) {
    $sort_column = 'date';
}

$sort_order = strtolower($sort_order);
if (!in_array($sort_order, ['asc', 'desc'])) {
    $sort_order = 'desc';
}

// Build student feedback query
$student_feedback_query = "SELECT f.*, b.batch_name as actual_batch_name 
          FROM feedback f 
          LEFT JOIN batches b ON f.batch_id = b.batch_id 
          WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM feedback f WHERE 1=1";
$student_params = [];
$count_params = [];

if (!empty($batch_filter)) {
    $student_feedback_query .= " AND f.batch_id = ?";
    $count_query .= " AND batch_id = ?";
    $student_params[] = $batch_filter;
    $count_params[] = $batch_filter;
}

if (!empty($rating_filter)) {
    $student_feedback_query .= " AND (f.class_rating = ? OR f.assignment_understanding = ? OR f.practical_understanding = ?)";
    $count_query .= " AND (class_rating = ? OR assignment_understanding = ? OR practical_understanding = ?)";
    $student_params[] = $rating_filter;
    $student_params[] = $rating_filter;
    $student_params[] = $rating_filter;
    $count_params[] = $rating_filter;
    $count_params[] = $rating_filter;
    $count_params[] = $rating_filter;
}

if (!empty($date_from)) {
    $student_feedback_query .= " AND f.date >= ?";
    $count_query .= " AND date >= ?";
    $student_params[] = $date_from;
    $count_params[] = $date_from;
}

if (!empty($date_to)) {
    $student_feedback_query .= " AND f.date <= ?";
    $count_query .= " AND date <= ?";
    $student_params[] = $date_to;
    $count_params[] = $date_to;
}

if (!empty($satisfaction_filter)) {
    if ($satisfaction_filter === 'Yes') {
        $student_feedback_query .= " AND f.satisfied = 1";
        $count_query .= " AND satisfied = 1";
    } elseif ($satisfaction_filter === 'No') {
        $student_feedback_query .= " AND f.satisfied = 0";
        $count_query .= " AND satisfied = 0";
    }
}

if (!empty($regularity_filter)) {
    $student_feedback_query .= " AND f.is_regular = ?";
    $count_query .= " AND is_regular = ?";
    $student_params[] = $regularity_filter;
    $count_params[] = $regularity_filter;
}

if (!empty($action_filter)) {
    if ($action_filter === 'taken') {
        $student_feedback_query .= " AND f.action_taken IS NOT NULL AND f.action_taken != ''";
        $count_query .= " AND action_taken IS NOT NULL AND action_taken != ''";
    } elseif ($action_filter === 'pending') {
        $student_feedback_query .= " AND (f.action_taken IS NULL OR f.action_taken = '')";
        $count_query .= " AND (action_taken IS NULL OR action_taken = '')";
    }
}

if (!empty($student_name_filter)) {
    $student_feedback_query .= " AND f.student_name LIKE ?";
    $count_query .= " AND student_name LIKE ?";
    $student_params[] = '%' . $student_name_filter . '%';
    $count_params[] = '%' . $student_name_filter . '%';
}

if (!empty($course_filter)) {
    $student_feedback_query .= " AND f.course_name LIKE ?";
    $count_query .= " AND course_name LIKE ?";
    $student_params[] = '%' . $course_filter . '%';
    $count_params[] = '%' . $course_filter . '%';
}

if ($show_to_trainer_filter !== '') {
    $student_feedback_query .= " AND f.show_to_trainer = ?";
    $count_query .= " AND show_to_trainer = ?";
    $student_params[] = $show_to_trainer_filter;
    $count_params[] = $show_to_trainer_filter;
}

// Get total count
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Add sorting and pagination
$student_feedback_query .= " ORDER BY f.$sort_column $sort_order LIMIT $offset, $per_page";

$stmt = $db->prepare($student_feedback_query);
$stmt->execute($student_params);
$student_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$summary = $db->query("
    SELECT 
        COUNT(*) as total_feedback,
        AVG(class_rating) as avg_class_rating,
        AVG(assignment_understanding) as avg_assignment_rating,
        AVG(practical_understanding) as avg_practical_rating,
        SUM(CASE WHEN satisfied = 1 THEN 1 ELSE 0 END) as satisfied_count,
        SUM(CASE WHEN is_regular = 'Yes' THEN 1 ELSE 0 END) as regular_count,
        SUM(CASE WHEN action_taken IS NOT NULL AND action_taken != '' THEN 1 ELSE 0 END) as action_taken_count,
        SUM(CASE WHEN show_to_trainer = 1 THEN 1 ELSE 0 END) as show_to_trainer_count
    FROM feedback
")->fetch(PDO::FETCH_ASSOC);

// Get weekly feedback summary
$weekly_summary = $db->query("
    SELECT 
        COUNT(*) as total_weekly_feedback,
        AVG(rating) as avg_weekly_rating,
        COUNT(DISTINCT trainer_id) as total_trainers,
        COUNT(DISTINCT student_id) as total_students,
        COUNT(DISTINCT batch_id) as total_batches
    FROM weekly_feedback
")->fetch(PDO::FETCH_ASSOC);

// Get recent weeks for filter
$recent_weeks = $db->query("
    SELECT DISTINCT 
        WEEK(week_start_date) as week_number,
        YEAR(week_start_date) as year,
        MIN(week_start_date) as start_date,
        MAX(week_end_date) as end_date
    FROM weekly_feedback
    GROUP BY YEAR(week_start_date), WEEK(week_start_date)
    ORDER BY week_start_date DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Weekly feedback pagination
$weekly_page = isset($_GET['weekly_page']) ? (int)$_GET['weekly_page'] : 1;
$weekly_per_page = isset($_GET['weekly_per_page']) ? (int)$_GET['weekly_per_page'] : 20;
$weekly_offset = ($weekly_page - 1) * $weekly_per_page;

$weekly_batch_filter = $_GET['weekly_batch'] ?? '';
$weekly_trainer_filter = $_GET['weekly_trainer'] ?? '';
$weekly_student_filter = $_GET['weekly_student'] ?? '';
$weekly_rating_filter = $_GET['weekly_rating'] ?? '';
$weekly_date_from = $_GET['weekly_date_from'] ?? '';
$weekly_date_to = $_GET['weekly_date_to'] ?? '';
$weekly_week_filter = $_GET['weekly_week'] ?? '';
$weekly_year_filter = $_GET['weekly_year'] ?? date('Y');

$weekly_sort_column = $_GET['weekly_sort'] ?? 'week_start_date';
$weekly_sort_order = $_GET['weekly_order'] ?? 'desc';

$weekly_valid_columns = ['week_start_date', 'batch_id', 'rating', 'submitted_at'];
if (!in_array($weekly_sort_column, $weekly_valid_columns)) {
    $weekly_sort_column = 'week_start_date';
}

$weekly_sort_order = strtolower($weekly_sort_order);
if (!in_array($weekly_sort_order, ['asc', 'desc'])) {
    $weekly_sort_order = 'desc';
}

$weekly_feedback_query = "SELECT wf.*, 
                        b.batch_name,
                        CONCAT(s.first_name, ' ', s.last_name) as student_full_name,
                        t.name as trainer_name,
                        DATE_FORMAT(wf.week_start_date, '%Y-%m-%d') as week_start,
                        DATE_FORMAT(wf.week_end_date, '%Y-%m-%d') as week_end,
                        WEEK(wf.week_start_date) as week_number,
                        YEAR(wf.week_start_date) as year
                    FROM weekly_feedback wf
                    LEFT JOIN batches b ON wf.batch_id = b.batch_id
                    LEFT JOIN students s ON wf.student_id = s.student_id
                    LEFT JOIN trainers t ON wf.trainer_id = t.id
                    WHERE 1=1";
$weekly_count_query = "SELECT COUNT(*) as total FROM weekly_feedback wf WHERE 1=1";
$weekly_params = [];
$weekly_count_params = [];

if (!empty($weekly_batch_filter)) {
    $weekly_feedback_query .= " AND wf.batch_id = ?";
    $weekly_count_query .= " AND batch_id = ?";
    $weekly_params[] = $weekly_batch_filter;
    $weekly_count_params[] = $weekly_batch_filter;
}

if (!empty($weekly_trainer_filter)) {
    $weekly_feedback_query .= " AND wf.trainer_id = ?";
    $weekly_count_query .= " AND trainer_id = ?";
    $weekly_params[] = $weekly_trainer_filter;
    $weekly_count_params[] = $weekly_trainer_filter;
}

if (!empty($weekly_student_filter)) {
    $weekly_feedback_query .= " AND wf.student_id = ?";
    $weekly_count_query .= " AND student_id = ?";
    $weekly_params[] = $weekly_student_filter;
    $weekly_count_params[] = $weekly_student_filter;
}

if (!empty($weekly_rating_filter)) {
    $weekly_feedback_query .= " AND wf.rating = ?";
    $weekly_count_query .= " AND rating = ?";
    $weekly_params[] = $weekly_rating_filter;
    $weekly_count_params[] = $weekly_rating_filter;
}

if (!empty($weekly_date_from)) {
    $weekly_feedback_query .= " AND wf.week_start_date >= ?";
    $weekly_count_query .= " AND week_start_date >= ?";
    $weekly_params[] = $weekly_date_from;
    $weekly_count_params[] = $weekly_date_from;
}

if (!empty($weekly_date_to)) {
    $weekly_feedback_query .= " AND wf.week_end_date <= ?";
    $weekly_count_query .= " AND week_end_date <= ?";
    $weekly_params[] = $weekly_date_to;
    $weekly_count_params[] = $weekly_date_to;
}

if (!empty($weekly_week_filter)) {
    $weekly_feedback_query .= " AND WEEK(wf.week_start_date) = ? AND YEAR(wf.week_start_date) = ?";
    $weekly_count_query .= " AND WEEK(week_start_date) = ? AND YEAR(week_start_date) = ?";
    $weekly_params[] = $weekly_week_filter;
    $weekly_params[] = $weekly_year_filter;
    $weekly_count_params[] = $weekly_week_filter;
    $weekly_count_params[] = $weekly_year_filter;
}

$weekly_count_stmt = $db->prepare($weekly_count_query);
$weekly_count_stmt->execute($weekly_count_params);
$weekly_total_records = $weekly_count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$weekly_total_pages = ceil($weekly_total_records / $weekly_per_page);

$weekly_feedback_query .= " ORDER BY wf.$weekly_sort_column $weekly_sort_order LIMIT $weekly_offset, $weekly_per_page";

$stmt = $db->prepare($weekly_feedback_query);
$stmt->execute($weekly_params);
$weekly_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to generate pagination HTML
function getPaginationHtml($current_page, $total_pages, $base_url, $params) {
    if ($total_pages <= 1) return '';
    
    $html = '<div class="flex items-center justify-between mt-6 pt-4 border-t">';
    $html .= '<div class="text-sm text-gray-600">';
    $html .= 'Showing page ' . $current_page . ' of ' . $total_pages;
    $html .= '</div>';
    $html .= '<div class="flex gap-2">';
    
    if ($current_page > 1) {
        $params['page'] = $current_page - 1;
        $html .= '<a href="?' . http_build_query($params) . '" class="px-3 py-1 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">';
        $html .= '<i class="fas fa-chevron-left"></i> Previous';
        $html .= '</a>';
    } else {
        $html .= '<button class="px-3 py-1 bg-gray-100 text-gray-400 rounded-lg cursor-not-allowed" disabled>';
        $html .= '<i class="fas fa-chevron-left"></i> Previous';
        $html .= '</button>';
    }
    
    if ($current_page < $total_pages) {
        $params['page'] = $current_page + 1;
        $html .= '<a href="?' . http_build_query($params) . '" class="px-3 py-1 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">';
        $html .= 'Next <i class="fas fa-chevron-right"></i>';
        $html .= '</a>';
    } else {
        $html .= '<button class="px-3 py-1 bg-gray-100 text-gray-400 rounded-lg cursor-not-allowed" disabled>';
        $html .= 'Next <i class="fas fa-chevron-right"></i>';
        $html .= '</button>';
    }
    
    $html .= '</div>';
    $html .= '<div class="flex items-center gap-2">';
    $html .= '<label class="text-sm text-gray-600">Rows:</label>';
    $html .= '<select onchange="changePerPage(this.value)" class="px-2 py-1 border rounded-lg text-sm">';
    $per_page_options = [10, 20, 50, 100];
    $current_per_page = $params['per_page'] ?? 20;
    foreach ($per_page_options as $option) {
        $selected = ($current_per_page == $option) ? 'selected' : '';
        $html .= "<option value='$option' $selected>$option</option>";
    }
    $html .= '</select>';
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

// Function to generate sort URL
function getSortUrl($column, $current_sort, $current_order, $tab = 'student_feedback') {
    $params = $_GET;
    $params['tab'] = $tab;
    
    if ($tab === 'student_feedback') {
        $params['sort'] = $column;
        if ($current_sort === $column && $current_order === 'desc') {
            $params['order'] = 'asc';
        } else {
            $params['order'] = 'desc';
        }
    } else {
        $params['weekly_sort'] = $column;
        if ($current_sort === $column && $current_order === 'desc') {
            $params['weekly_order'] = 'asc';
        } else {
            $params['weekly_order'] = 'desc';
        }
    }
    
    return '?' . http_build_query($params);
}

// Function to get sort icon
function getSortIcon($column, $current_sort, $current_order) {
    if ($current_sort !== $column) {
        return '<i class="fas fa-sort text-gray-400 ml-1"></i>';
    }
    
    if ($current_order === 'asc') {
        return '<i class="fas fa-sort-up text-blue-500 ml-1"></i>';
    } else {
        return '<i class="fas fa-sort-down text-blue-500 ml-1"></i>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback System | ASD Academy</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.15);
            color: #d97706;
        }
        
        .status-completed {
            background: rgba(16, 185, 129, 0.15);
            color: #059669;
        }
        
        .status-show-trainer {
            background: rgba(139, 92, 246, 0.15);
            color: #7c3aed;
        }
        
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .select2-container--default .select2-selection--single {
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: 8px 12px;
            height: 46px;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .tab-nav {
            display: flex;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }
        
        .tab-button {
            flex: 1;
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .tab-button.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .tab-button .badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .filter-panel {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }
        
        .filter-panel h3 {
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .filter-panel h3 i {
            transition: transform 0.3s ease;
        }
        
        .filter-panel.collapsed h3 i {
            transform: rotate(-90deg);
        }
        
        .filter-panel.collapsed .filter-content {
            display: none;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #4b5563;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.875rem;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            padding: 1rem;
            text-align: left;
        }
        
        .data-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        
        .data-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .data-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .rating-5 { color: #10b981; }
        .rating-4 { color: #34d399; }
        .rating-3 { color: #f59e0b; }
        .rating-2 { color: #f97316; }
        .rating-1 { color: #ef4444; }
        
        .week-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .action-btn.view {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }
        
        .action-btn.action {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .action-btn.delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .sortable-header {
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
            text-decoration: none;
        }
        
        .sortable-header:hover {
            opacity: 0.9;
        }
        
        .checkbox-select-all,
        .checkbox-select-row {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .bulk-actions-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: none;
            align-items: center;
            justify-content: space-between;
        }
        
        .bulk-actions-bar.show {
            display: flex;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #8b5cf6;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        
        /* Expandable Row Styles */
        .expand-row {
            cursor: pointer;
        }
        
        .row-details {
            display: none;
            background: #f9fafb;
        }
        
        .row-details.active {
            display: table-row;
        }
        
        .details-content {
            padding: 24px;
            background: white;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
        }
        
        .details-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
        }
        
        .details-section h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .detail-label {
            font-weight: 500;
            color: #4a5568;
            font-size: 0.875rem;
        }
        
        .detail-value {
            color: #2d3748;
            font-weight: 600;
            text-align: right;
            max-width: 200px;
            word-break: break-word;
        }
        
        .expand-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            color: #667eea;
            transition: transform 0.3s ease;
        }
        
        .expand-toggle i {
            transition: transform 0.3s ease;
        }
        
        .expand-toggle.expanded i {
            transform: rotate(180deg);
        }
        
        .action-form {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .action-textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            resize: vertical;
        }
        
        .action-textarea:focus {
            outline: none;
            border-color: #059669;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #4b5563;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid #d1d5db;
            cursor: pointer;
        }
        
        .new-indicator {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 10px;
            height: 10px;
            background-color: #ef4444;
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.5); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .relative {
            position: relative;
        }
        
        .stars-inline {
            font-size: 14px;
            letter-spacing: 2px;
        }
        
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .details-grid {
                grid-template-columns: 1fr;
            }
            .data-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay hidden">
        <div class="spinner"></div>
    </div>
    
    <!-- Sidebar -->
    <?php include '../sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 transition-all duration-300 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-lg sticky top-0 z-40">
            <div class="px-6 py-4 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; background-clip: text; color: transparent;">
                        Feedback System
                    </h1>
                    <p class="text-gray-600">Manage student and weekly feedback</p>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white font-bold shadow-lg">
                        <?php echo strtoupper(substr($_SESSION['name'] ?? 'A', 0, 2)); ?>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></p>
                        <p class="text-sm text-gray-500"><?php echo ucfirst($user_role); ?></p>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6">
            <!-- Success/Error Messages -->
            <?php if (isset($success) && $success): ?>
                <div class="glass-card p-4 mb-6 fade-in" id="successMessage">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 text-xl mr-3"></i>
                        <div>
                            <p class="font-medium text-green-800">Success!</p>
                            <p class="text-green-700">Feedback submitted successfully!</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (isset($action_updated) && $action_updated): ?>
                <div class="glass-card p-4 mb-6 fade-in" id="infoMessage">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-500 text-xl mr-3"></i>
                        <div>
                            <p class="font-medium text-blue-800">Success!</p>
                            <p class="text-blue-700">Action taken updated successfully!</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (isset($bulk_success) && $bulk_success): ?>
                <div class="glass-card p-4 mb-6 fade-in" id="infoMessage">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-500 text-xl mr-3"></i>
                        <div>
                            <p class="font-medium text-blue-800">Success!</p>
                            <p class="text-blue-700">Feedback visibility updated successfully!</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="tab-nav">
                <button class="tab-button <?= $active_tab === 'student_feedback' ? 'active' : '' ?>" onclick="switchTab('student_feedback')">
                    <i class="fas fa-user-graduate"></i>
                    Student Feedback
                    <span class="badge"><?= $summary['total_feedback'] ?? 0 ?></span>
                </button>
                <button class="tab-button <?= $active_tab === 'weekly_feedback' ? 'active' : '' ?>" onclick="switchTab('weekly_feedback')">
                    <i class="fas fa-calendar-week"></i>
                    Trainer Weekly Feedback
                    <span class="badge"><?= $weekly_summary['total_weekly_feedback'] ?? 0 ?></span>
                </button>
            </div>

            <!-- Student Feedback Tab -->
            <div id="student_feedback_tab" class="tab-content <?= $active_tab === 'student_feedback' ? 'active' : '' ?>">
                <!-- Stats Dashboard -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-comments text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Feedback</p>
                                <p class="text-2xl font-bold text-gray-800"><?= $summary['total_feedback'] ?? 0 ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-star text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Avg. Class Rating</p>
                                <p class="text-2xl font-bold text-gray-800"><?= number_format($summary['avg_class_rating'] ?? 0, 1) ?>/5.0</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-smile text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Satisfaction Rate</p>
                                <p class="text-2xl font-bold text-gray-800">
                                    <?= $summary['total_feedback'] > 0 ? round(($summary['satisfied_count'] / $summary['total_feedback']) * 100) : 0 ?>%
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Action Taken</p>
                                <p class="text-2xl font-bold text-gray-800"><?= $summary['action_taken_count'] ?? 0 ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-5 hover-lift">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-r from-indigo-500 to-purple-500 rounded-lg flex items-center justify-center text-white mr-4">
                                <i class="fas fa-chalkboard-teacher text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Show to Trainers</p>
                                <p class="text-2xl font-bold text-gray-800"><?= $summary['show_to_trainer_count'] ?? 0 ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Student Feedback Table -->
                <div class="glass-card p-6">
                    <div class="flex justify-between items-center mb-6 flex-wrap gap-4">
                        <h2 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-table mr-2 text-blue-500"></i>
                            Student Feedback Records
                        </h2>
                        <div class="flex gap-2">
                            <button class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors" onclick="toggleFilter('student')">
                                <i class="fas fa-filter mr-2"></i> Filter
                            </button>
                            <a href="?tab=student_feedback" class="px-4 py-2 bg-gradient-to-r from-blue-500 to-cyan-500 text-white rounded-lg hover:opacity-90">
                                <i class="fas fa-redo mr-2"></i> Reset
                            </a>
                        </div>
                    </div>
                    
                    <!-- Student Feedback Filter Panel -->
                    <div class="filter-panel <?= empty(array_filter([$batch_filter, $rating_filter, $date_from, $date_to, $satisfaction_filter, $regularity_filter, $action_filter, $student_name_filter, $course_filter, $show_to_trainer_filter])) ? 'collapsed' : '' ?>" id="studentFilterPanel">
                        <h3 onclick="toggleFilterPanel('student')">
                            <span><i class="fas fa-filter mr-2"></i> Filter Student Feedback</span>
                            <i class="fas fa-chevron-down"></i>
                        </h3>
                        <div class="filter-content mt-4">
                            <form method="GET" action="" id="filterForm">
                                <input type="hidden" name="tab" value="student_feedback">
                                <input type="hidden" name="sort" value="<?= $sort_column ?>">
                                <input type="hidden" name="order" value="<?= $sort_order ?>">
                                <input type="hidden" name="page" value="1">
                                <input type="hidden" name="per_page" value="<?= $per_page ?>">
                                <div class="filter-grid">
                                    <div class="filter-group">
                                        <label>Batch</label>
                                        <select name="batch">
                                            <option value="">All Batches</option>
                                            <?php foreach ($batches as $batch): ?>
                                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $batch_filter === $batch['batch_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($batch['batch_id']) ?> - <?= htmlspecialchars($batch['batch_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Student Name</label>
                                        <input type="text" name="student_name" placeholder="Search by name..." value="<?= htmlspecialchars($student_name_filter) ?>">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Course</label>
                                        <input type="text" name="course" placeholder="Search by course..." value="<?= htmlspecialchars($course_filter) ?>">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Rating</label>
                                        <select name="rating">
                                            <option value="">Any Rating</option>
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                <option value="<?= $i ?>" <?= $rating_filter == $i ? 'selected' : '' ?>><?= $i ?> Stars</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="filter-grid mt-4">
                                    <div class="filter-group">
                                        <label>Satisfaction</label>
                                        <select name="satisfaction">
                                            <option value="">Any</option>
                                            <option value="Yes" <?= $satisfaction_filter === 'Yes' ? 'selected' : '' ?>>Satisfied</option>
                                            <option value="No" <?= $satisfaction_filter === 'No' ? 'selected' : '' ?>>Not Satisfied</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Regularity</label>
                                        <select name="regularity">
                                            <option value="">Any</option>
                                            <option value="Yes" <?= $regularity_filter === 'Yes' ? 'selected' : '' ?>>Regular</option>
                                            <option value="No" <?= $regularity_filter === 'No' ? 'selected' : '' ?>>Not Regular</option>
                                            <option value="Sometimes" <?= $regularity_filter === 'Sometimes' ? 'selected' : '' ?>>Sometimes</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Action Status</label>
                                        <select name="action">
                                            <option value="">Any</option>
                                            <option value="taken" <?= $action_filter === 'taken' ? 'selected' : '' ?>>Action Taken</option>
                                            <option value="pending" <?= $action_filter === 'pending' ? 'selected' : '' ?>>Pending Action</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Show to Trainer</label>
                                        <select name="show_to_trainer">
                                            <option value="">Any</option>
                                            <option value="1" <?= $show_to_trainer_filter === '1' ? 'selected' : '' ?>>Yes</option>
                                            <option value="0" <?= $show_to_trainer_filter === '0' ? 'selected' : '' ?>>No</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Date From</label>
                                        <input type="date" name="date_from" value="<?= $date_from ?>">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Date To</label>
                                        <input type="date" name="date_to" value="<?= $date_to ?>">
                                    </div>
                                </div>
                                
                                <div class="flex justify-between items-center mt-4">
                                    <div class="flex gap-2">
                                        <button type="submit" class="px-4 py-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-lg hover:opacity-90">
                                            <i class="fas fa-filter mr-2"></i> Apply Filters
                                        </button>
                                        <a href="feedback.php?tab=student_feedback" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg">
                                            <i class="fas fa-redo mr-2"></i> Reset All
                                        </a>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        Found <?= $total_records ?> records
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Bulk Actions Bar -->
                    <div class="bulk-actions-bar" id="bulkActionsBar">
                        <div>
                            <i class="fas fa-check-circle mr-2"></i>
                            <span id="selectedCount">0</span> feedback(s) selected
                        </div>
                        <div class="flex gap-2">
                            <select id="bulkShowToTrainer" class="px-3 py-1 rounded-lg text-gray-700">
                                <option value="1">Show to Trainers</option>
                                <option value="0">Hide from Trainers</option>
                            </select>
                            <button onclick="applyBulkAction()" class="px-4 py-1 bg-white text-purple-600 rounded-lg hover:bg-gray-100">
                                Apply
                            </button>
                            <button onclick="clearSelection()" class="px-4 py-1 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Cancel
                            </button>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="w-8">
                                        <input type="checkbox" class="checkbox-select-all" onclick="selectAllRows(this)">
                                    </th>
                                    <th class="w-12"></th>
                                    <th>
                                        <a href="<?= getSortUrl('date', $sort_column, $sort_order) ?>" class="sortable-header">
                                            Date
                                            <?= getSortIcon('date', $sort_column, $sort_order) ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="<?= getSortUrl('student_name', $sort_column, $sort_order) ?>" class="sortable-header">
                                            Student
                                            <?= getSortIcon('student_name', $sort_column, $sort_order) ?>
                                        </a>
                                    </th>
                                    <th>Email</th>
                                    <th>Batch</th>
                                    <th>
                                        <a href="<?= getSortUrl('course_name', $sort_column, $sort_order) ?>" class="sortable-header">
                                            Course
                                            <?= getSortIcon('course_name', $sort_column, $sort_order) ?>
                                        </a>
                                    </th>
                                    <th>Class</th>
                                    <th>Assign</th>
                                    <th>Pract</th>
                                    <th>Satis</th>
                                    <th>Show to Trainer</th>
                                    <th>Action</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $today = new DateTime();
                                foreach ($student_feedback as $index => $item): 
                                    $feedback_date = new DateTime($item['date']);
                                    $days_diff = $today->diff($feedback_date)->days;
                                    $is_new = $days_diff <= 7;
                                    
                                    $initials = '';
                                    if (!empty($item['student_name'])) {
                                        $name_parts = explode(' ', $item['student_name']);
                                        $initials = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : substr($name_parts[0], 1, 1)));
                                    }
                                ?>
                                <!-- Main Row -->
                                <tr class="expand-row" id="row-<?= $item['id']; ?>" onclick="toggleRowDetails(<?= $item['id']; ?>)">
                                    <td onclick="event.stopPropagation()">
                                        <input type="checkbox" class="checkbox-select-row" value="<?= $item['id'] ?>" onchange="updateSelectionCount()">
                                    </td>
                                    <td class="w-12" onclick="event.stopPropagation()">
                                        <div class="expand-toggle" id="expand-icon-<?= $item['id'] ?>">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="relative">
                                            <div class="text-sm font-medium"><?= date('M j, Y', strtotime($item['date'])) ?></div>
                                            <div class="text-xs text-gray-500"><?= date('D', strtotime($item['date'])) ?></div>
                                            <?php if ($is_new): ?>
                                                <div class="new-indicator" title="New feedback (within 7 days)"></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex items-center">
                                            <div class="avatar bg-gradient-to-r from-blue-500 to-cyan-500 mr-3">
                                                <?= $initials ?>
                                            </div>
                                            <div>
                                                <div class="font-medium"><?= htmlspecialchars($item['student_name']) ?></div>
                                                <div class="text-xs text-gray-500">
                                                    <?= $item['is_regular'] === 'Yes' ? 'Regular' : ($item['is_regular'] === 'No' ? 'Irregular' : 'Sometimes') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-sm"><?= htmlspecialchars($item['email']) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-sm font-medium"><?= htmlspecialchars($item['batch_id']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($item['actual_batch_name'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div class="text-sm"><?= htmlspecialchars($item['course_name']) ?></div>
                                    </td>
                                    <td>
                                        <div class="stars-inline rating-<?= $item['class_rating'] ?>">
                                            <?= str_repeat('★', $item['class_rating']) ?><?= str_repeat('☆', 5 - $item['class_rating']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stars-inline rating-<?= $item['assignment_understanding'] ?>">
                                            <?= str_repeat('★', $item['assignment_understanding']) ?><?= str_repeat('☆', 5 - $item['assignment_understanding']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stars-inline rating-<?= $item['practical_understanding'] ?>">
                                            <?= str_repeat('★', $item['practical_understanding']) ?><?= str_repeat('☆', 5 - $item['practical_understanding']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($item['satisfied'] == 1 || $item['satisfied'] === 'Yes'): ?>
                                            <span class="status-badge status-completed">Satisfied</span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">Not Satisfied</span>
                                        <?php endif; ?>
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <label class="toggle-switch">
                                            <input type="checkbox" class="show-to-trainer-toggle" data-id="<?= $item['id'] ?>" <?= $item['show_to_trainer'] ? 'checked' : '' ?> onchange="updateShowToTrainer(<?= $item['id'] ?>, this.checked)">
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['action_taken'])): ?>
                                            <span class="status-badge status-completed">
                                                <i class="fas fa-check mr-1"></i> Taken
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">
                                                <i class="fas fa-clock mr-1"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <div class="action-buttons">
                                            <button class="action-btn view" onclick="viewStudentFeedback(<?= $item['id'] ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="action-btn action" onclick="markActionTaken(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['action_taken'] ?? '')) ?>')">
                                                <i class="fas fa-check"></i> Action
                                            </button>
                                            <?php if ($user_role === 'admin'): ?>
                                                <button class="action-btn delete" onclick="deleteStudentFeedback(<?= $item['id'] ?>)">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Expanded Details Row -->
                                <tr class="row-details" id="details-<?= $item['id'] ?>">
                                    <td colspan="14" class="px-0">
                                        <div class="details-content">
                                            <div class="details-grid">
                                                <div class="details-section">
                                                    <h4><i class="fas fa-user-graduate"></i> Student Information</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Student Name:</span>
                                                        <span class="detail-value"><?= htmlspecialchars($item['student_name']) ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Email:</span>
                                                        <span class="detail-value"><?= htmlspecialchars($item['email']) ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Regular in Class:</span>
                                                        <span class="detail-value">
                                                            <?= $item['is_regular'] === 'Yes' ? 'Regular' : ($item['is_regular'] === 'No' ? 'Not Regular' : 'Sometimes') ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Submission Date:</span>
                                                        <span class="detail-value"><?= date('F d, Y', strtotime($item['date'])) ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="details-section">
                                                    <h4><i class="fas fa-book"></i> Course Information</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Course Name:</span>
                                                        <span class="detail-value"><?= htmlspecialchars($item['course_name']) ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Batch:</span>
                                                        <span class="detail-value"><?= htmlspecialchars($item['batch_id']) ?> - <?= htmlspecialchars($item['actual_batch_name'] ?? '') ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="details-section">
                                                    <h4><i class="fas fa-star"></i> Ratings</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Class Rating:</span>
                                                        <span class="detail-value rating-<?= $item['class_rating'] ?>">
                                                            <?= str_repeat('★', $item['class_rating']) ?><?= str_repeat('☆', 5 - $item['class_rating']) ?> (<?= $item['class_rating'] ?>/5)
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Assignment Understanding:</span>
                                                        <span class="detail-value rating-<?= $item['assignment_understanding'] ?>">
                                                            <?= str_repeat('★', $item['assignment_understanding']) ?><?= str_repeat('☆', 5 - $item['assignment_understanding']) ?> (<?= $item['assignment_understanding'] ?>/5)
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Practical Understanding:</span>
                                                        <span class="detail-value rating-<?= $item['practical_understanding'] ?>">
                                                            <?= str_repeat('★', $item['practical_understanding']) ?><?= str_repeat('☆', 5 - $item['practical_understanding']) ?> (<?= $item['practical_understanding'] ?>/5)
                                                        </span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Overall Satisfaction:</span>
                                                        <span class="detail-value">
                                                            <?php if ($item['satisfied'] == 1): ?>
                                                                <span class="status-badge status-completed">Satisfied</span>
                                                            <?php else: ?>
                                                                <span class="status-badge status-pending">Not Satisfied</span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                <div class="details-section">
                                                    <h4><i class="fas fa-comments"></i> Feedback Details</h4>
                                                    <?php if (!empty($item['suggestions'])): ?>
                                                        <div class="detail-item">
                                                            <span class="detail-label">Suggestions/Issues:</span>
                                                            <span class="detail-value"><?= nl2br(htmlspecialchars($item['suggestions'])) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($item['feedback_text'])): ?>
                                                        <div class="detail-item">
                                                            <span class="detail-label">Additional Feedback:</span>
                                                            <span class="detail-value"><?= nl2br(htmlspecialchars($item['feedback_text'])) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="detail-item">
                                                        <span class="detail-label">Action Taken:</span>
                                                        <span class="detail-value">
                                                            <?php if (!empty($item['action_taken'])): ?>
                                                                <div class="text-left">
                                                                    <span class="status-badge status-completed mb-2 inline-block">✓ Taken</span>
                                                                    <div class="text-sm text-gray-700 mt-2"><?= nl2br(htmlspecialchars($item['action_taken'])) ?></div>
                                                                    <?php if (!empty($item['action_taken_time'])): ?>
                                                                        <div class="text-xs text-gray-500 mt-1">on <?= date('M j, Y h:i A', strtotime($item['action_taken_time'])) ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="status-badge status-pending">Pending</span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <div class="detail-item">
                                                        <span class="detail-label">Show to Trainer:</span>
                                                        <span class="detail-value">
                                                            <?php if ($item['show_to_trainer']): ?>
                                                                <span class="status-badge status-show-trainer">✓ Yes</span>
                                                            <?php else: ?>
                                                                <span class="status-badge status-pending">✗ No</span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Action Taken Form -->
                                            <div class="action-form" id="action-form-<?= $item['id'] ?>" style="display: none;">
                                                <h5 class="font-semibold mb-3"><i class="fas fa-check-circle text-green-600"></i> Update Action Taken</h5>
                                                <form method="POST" action="" class="action-update-form">
                                                    <input type="hidden" name="update_action" value="1">
                                                    <input type="hidden" name="feedback_id" value="<?= $item['id'] ?>">
                                                    <textarea name="action_taken" class="action-textarea" placeholder="Describe the action taken to address this feedback..." required><?= htmlspecialchars($item['action_taken'] ?? '') ?></textarea>
                                                    <div class="flex gap-3 justify-end mt-4">
                                                        <button type="button" class="btn-secondary" onclick="cancelActionForm(<?= $item['id'] ?>)">
                                                            Cancel
                                                        </button>
                                                        <button type="submit" class="btn-primary">
                                                            <i class="fas fa-save"></i> Save Action
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($student_feedback)): ?>
                                    <tr>
                                        <td colspan="14" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-inbox text-4xl mb-2 block"></i>
                                            No feedback records found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php 
                    $pagination_params = $_GET;
                    $pagination_params['tab'] = 'student_feedback';
                    $pagination_params['per_page'] = $per_page;
                    echo getPaginationHtml($page, $total_pages, '', $pagination_params);
                    ?>
                </div>
            </div>
            
            <!-- Weekly Feedback Tab -->
            <div id="weekly_feedback_tab" class="tab-content <?= $active_tab === 'weekly_feedback' ? 'active' : '' ?>">
                <div class="glass-card p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-calendar-week mr-2 text-purple-500"></i>
                            Weekly Feedback Records
                        </h2>
                        <div class="flex gap-2">
                            <button class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg" onclick="toggleFilter('weekly')">
                                <i class="fas fa-filter mr-2"></i> Filter
                            </button>
                            <a href="?tab=weekly_feedback" class="px-4 py-2 bg-gradient-to-r from-blue-500 to-cyan-500 text-white rounded-lg">
                                <i class="fas fa-redo mr-2"></i> Reset
                            </a>
                        </div>
                    </div>
                    
                    <!-- Weekly Feedback Filter Panel -->
                    <div class="filter-panel <?= empty(array_filter([$weekly_batch_filter, $weekly_trainer_filter, $weekly_student_filter, $weekly_rating_filter, $weekly_date_from, $weekly_date_to, $weekly_week_filter])) ? 'collapsed' : '' ?>" id="weeklyFilterPanel">
                        <h3 onclick="toggleFilterPanel('weekly')">
                            <span><i class="fas fa-filter mr-2"></i> Filter Weekly Feedback</span>
                            <i class="fas fa-chevron-down"></i>
                        </h3>
                        <div class="filter-content mt-4">
                            <form method="GET" action="">
                                <input type="hidden" name="tab" value="weekly_feedback">
                                <input type="hidden" name="weekly_sort" value="<?= $weekly_sort_column ?>">
                                <input type="hidden" name="weekly_order" value="<?= $weekly_sort_order ?>">
                                <input type="hidden" name="weekly_page" value="1">
                                <input type="hidden" name="weekly_per_page" value="<?= $weekly_per_page ?>">
                                <div class="filter-grid">
                                    <div class="filter-group">
                                        <label>Batch</label>
                                        <select name="weekly_batch">
                                            <option value="">All Batches</option>
                                            <?php foreach ($batches as $batch): ?>
                                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $weekly_batch_filter === $batch['batch_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($batch['batch_id']) ?> - <?= htmlspecialchars($batch['batch_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Trainer</label>
                                        <select name="weekly_trainer">
                                            <option value="">All Trainers</option>
                                            <?php foreach ($trainers as $trainer): ?>
                                                <option value="<?= $trainer['id'] ?>" <?= $weekly_trainer_filter == $trainer['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($trainer['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Student</label>
                                        <select name="weekly_student">
                                            <option value="">All Students</option>
                                            <?php foreach ($students as $student): ?>
                                                <option value="<?= htmlspecialchars($student['student_id']) ?>" <?= $weekly_student_filter === $student['student_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($student['full_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Rating</label>
                                        <select name="weekly_rating">
                                            <option value="">Any Rating</option>
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                <option value="<?= $i ?>" <?= $weekly_rating_filter == $i ? 'selected' : '' ?>><?= $i ?> Stars</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="filter-grid mt-4">
                                    <div class="filter-group">
                                        <label>Week</label>
                                        <select name="weekly_week">
                                            <option value="">All Weeks</option>
                                            <?php foreach ($recent_weeks as $week): ?>
                                                <option value="<?= $week['week_number'] ?>" <?= $weekly_week_filter == $week['week_number'] ? 'selected' : '' ?>>
                                                    Week <?= $week['week_number'] ?> (<?= date('M d', strtotime($week['start_date'])) ?> - <?= date('M d', strtotime($week['end_date'])) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Year</label>
                                        <select name="weekly_year">
                                            <option value="2024" <?= $weekly_year_filter == '2024' ? 'selected' : '' ?>>2024</option>
                                            <option value="2025" <?= $weekly_year_filter == '2025' || $weekly_year_filter == '' ? 'selected' : '' ?>>2025</option>
                                            <option value="2026" <?= $weekly_year_filter == '2026' ? 'selected' : '' ?>>2026</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Date From</label>
                                        <input type="date" name="weekly_date_from" value="<?= $weekly_date_from ?>">
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label>Date To</label>
                                        <input type="date" name="weekly_date_to" value="<?= $weekly_date_to ?>">
                                    </div>
                                </div>
                                
                                <div class="flex justify-between items-center mt-4">
                                    <div class="flex gap-2">
                                        <button type="submit" class="px-4 py-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-lg">
                                            <i class="fas fa-filter mr-2"></i> Apply Filters
                                        </button>
                                        <a href="feedback.php?tab=weekly_feedback" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg">
                                            <i class="fas fa-redo mr-2"></i> Reset All
                                        </a>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        Found <?= $weekly_total_records ?> records
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="w-12"></th>
                                    <th>Week</th>
                                    <th>Batch</th>
                                    <th>Student</th>
                                    <th>Trainer</th>
                                    <th>Rating</th>
                                    <th>Remarks</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($weekly_feedback as $item): 
                                    $student_initials = '';
                                    if (!empty($item['student_full_name'])) {
                                        $name_parts = explode(' ', $item['student_full_name']);
                                        $student_initials = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : substr($name_parts[0], 1, 1)));
                                    }
                                    $trainer_initials = '';
                                    if (!empty($item['trainer_name'])) {
                                        $name_parts = explode(' ', $item['trainer_name']);
                                        $trainer_initials = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : substr($name_parts[0], 1, 1)));
                                    }
                                ?>
                                <tr class="expand-row" onclick="toggleWeeklyRowDetails(<?= $item['id']; ?>)">
                                    <td class="w-12" onclick="event.stopPropagation()">
                                        <div class="expand-toggle" id="weekly-expand-icon-<?= $item['id'] ?>">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <div class="week-badge">Week <?= $item['week_number'] ?></div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?= date('M d', strtotime($item['week_start'])) ?> - <?= date('M d', strtotime($item['week_end'])) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="font-medium"><?= htmlspecialchars($item['batch_id']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($item['batch_name'] ?? '') ?></div>
                                    </div>
                                    <td>
                                        <div class="flex items-center">
                                            <div class="avatar bg-gradient-to-r from-green-500 to-emerald-500 mr-3">
                                                <?= $student_initials ?>
                                            </div>
                                            <div>
                                                <div class="font-medium"><?= htmlspecialchars($item['student_full_name'] ?? $item['student_id']) ?></div>
                                                <div class="text-xs text-gray-500">ID: <?= htmlspecialchars($item['student_id']) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <td>
                                        <div class="flex items-center">
                                            <div class="avatar bg-gradient-to-r from-purple-500 to-pink-500 mr-3">
                                                <?= $trainer_initials ?>
                                            </div>
                                            <div>
                                                <div class="font-medium"><?= htmlspecialchars($item['trainer_name'] ?? 'Unknown') ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <td>
                                        <div class="stars-inline rating-<?= $item['rating'] ?>">
                                            <?= str_repeat('★', $item['rating']) ?><?= str_repeat('☆', 5 - $item['rating']) ?>
                                        </div>
                                        <div class="font-bold rating-<?= $item['rating'] ?>"><?= $item['rating'] ?>/5</div>
                                    </div>
                                    <td>
                                        <?php if (!empty($item['remarks'])): ?>
                                            <div class="text-sm"><?= htmlspecialchars(substr($item['remarks'], 0, 80)) . (strlen($item['remarks']) > 80 ? '...' : '') ?></div>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">No remarks</span>
                                        <?php endif; ?>
                                    </div>
                                    <td>
                                        <div class="text-sm"><?= date('M j, Y', strtotime($item['submitted_at'])) ?></div>
                                    </div>
                                    <td>
                                        <div class="action-buttons" onclick="event.stopPropagation()">
                                            <button class="action-btn view" onclick="viewWeeklyFeedback(<?= $item['id'] ?>)">View</button>
                                            <?php if ($user_role === 'admin'): ?>
                                                <button class="action-btn delete" onclick="deleteWeeklyFeedback(<?= $item['id'] ?>)">Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </tr>
                                <tr class="row-details" id="weekly-details-<?= $item['id'] ?>">
                                    <td colspan="9" class="px-0">
                                        <div class="details-content">
                                            <div class="details-grid">
                                                <div class="details-section">
                                                    <h4><i class="fas fa-calendar-week"></i> Weekly Information</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Week Period:</span>
                                                        <span class="detail-value"><?= date('F d, Y', strtotime($item['week_start'])) ?> - <?= date('F d, Y', strtotime($item['week_end'])) ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Submitted On:</span>
                                                        <span class="detail-value"><?= date('F d, Y h:i A', strtotime($item['submitted_at'])) ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="details-section">
                                                    <h4><i class="fas fa-users"></i> Student & Trainer</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Student:</span>
                                                        <span class="detail-value"><?= htmlspecialchars($item['student_full_name'] ?? $item['student_id']) ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Trainer:</span>
                                                        <span class="detail-value"><?= htmlspecialchars($item['trainer_name'] ?? 'Unknown') ?></span>
                                                    </div>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Batch:</span>
                                                        <span class="detail-value"><?= htmlspecialchars($item['batch_id']) ?> - <?= htmlspecialchars($item['batch_name'] ?? '') ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="details-section">
                                                    <h4><i class="fas fa-star"></i> Rating Details</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Rating:</span>
                                                        <span class="detail-value rating-<?= $item['rating'] ?>">
                                                            <?= str_repeat('★', $item['rating']) ?><?= str_repeat('☆', 5 - $item['rating']) ?> (<?= $item['rating'] ?>/5)
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                <div class="details-section">
                                                    <h4><i class="fas fa-comment-dots"></i> Remarks</h4>
                                                    <div class="detail-item">
                                                        <span class="detail-label">Trainer Remarks:</span>
                                                        <span class="detail-value text-left"><?= nl2br(htmlspecialchars($item['remarks'] ?? 'No remarks provided')) ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($weekly_feedback)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-inbox text-4xl mb-2 block"></i>
                                            No weekly feedback records found
                                        </div>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Weekly Pagination -->
                    <?php 
                    $weekly_pagination_params = $_GET;
                    $weekly_pagination_params['tab'] = 'weekly_feedback';
                    $weekly_pagination_params['weekly_per_page'] = $weekly_per_page;
                    echo getPaginationHtml($weekly_page, $weekly_total_pages, '', $weekly_pagination_params);
                    ?>
                </div>
            </div>
        </main>
    </div>

    <script>
    $(document).ready(function() {
        // Initialize Select2 for dropdowns
        $('select').select2({
            width: '100%',
            placeholder: "Select an option...",
            allowClear: true
        });
        
        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            $('#successMessage, #infoMessage').fadeOut('slow', function() {
                $(this).remove();
            });
        }, 5000);
    });
    
    // Selected rows for bulk actions
    let selectedRows = new Set();
    
    function selectAllRows(checkbox) {
        const checkboxes = document.querySelectorAll('.checkbox-select-row');
        checkboxes.forEach(cb => {
            cb.checked = checkbox.checked;
            if (checkbox.checked) {
                selectedRows.add(cb.value);
            } else {
                selectedRows.delete(cb.value);
            }
        });
        updateSelectionCount();
    }
    
    function updateSelectionCount() {
        const checkboxes = document.querySelectorAll('.checkbox-select-row');
        selectedRows.clear();
        checkboxes.forEach(cb => {
            if (cb.checked) {
                selectedRows.add(cb.value);
            }
        });
        
        const count = selectedRows.size;
        const bulkBar = document.getElementById('bulkActionsBar');
        const selectedCountSpan = document.getElementById('selectedCount');
        
        if (count > 0) {
            bulkBar.classList.add('show');
            selectedCountSpan.textContent = count;
        } else {
            bulkBar.classList.remove('show');
        }
    }
    
    function clearSelection() {
        const checkboxes = document.querySelectorAll('.checkbox-select-row');
        checkboxes.forEach(cb => {
            cb.checked = false;
        });
        selectedRows.clear();
        updateSelectionCount();
        
        const selectAllCheckbox = document.querySelector('.checkbox-select-all');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = false;
        }
    }
    
    function applyBulkAction() {
        if (selectedRows.size === 0) {
            alert('Please select at least one feedback');
            return;
        }
        
        const showToTrainer = document.getElementById('bulkShowToTrainer').value;
        
        if (confirm(`Are you sure you want to update ${selectedRows.size} feedback(s)?`)) {
            showLoading();
            
            const formData = new FormData();
            formData.append('bulk_update_trainer', '1');
            formData.append('show_to_trainer', showToTrainer);
            selectedRows.forEach(id => {
                formData.append('feedback_ids[]', id);
            });
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    hideLoading();
                    alert('Error updating feedback visibility');
                }
            }).catch(error => {
                hideLoading();
                alert('Error: ' + error);
            });
        }
    }
    
    function updateShowToTrainer(id, checked) {
        showLoading();
        fetch(`?ajax_update_show_to_trainer=1&feedback_id=${id}&show_value=${checked ? 1 : 0}`)
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (!data.success) {
                    alert('Error updating visibility');
                }
            })
            .catch(error => {
                hideLoading();
                alert('Error: ' + error);
            });
    }
    
    function changePerPage(value) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('per_page', value);
        urlParams.set('page', 1);
        window.location.search = urlParams.toString();
    }
    
    function switchTab(tabName) {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.location.href = url.toString();
    }
    
    function toggleFilterPanel(type) {
        const panel = document.getElementById(`${type}FilterPanel`);
        panel.classList.toggle('collapsed');
    }
    
    function toggleFilter(type) {
        toggleFilterPanel(type);
    }
    
    // Toggle row details for student feedback
    function toggleRowDetails(rowId) {
        const detailsRow = document.getElementById(`details-${rowId}`);
        const expandIcon = document.getElementById(`expand-icon-${rowId}`);
        
        if (detailsRow) {
            if (detailsRow.classList.contains('active')) {
                detailsRow.classList.remove('active');
                if (expandIcon) expandIcon.classList.remove('expanded');
            } else {
                detailsRow.classList.add('active');
                if (expandIcon) expandIcon.classList.add('expanded');
            }
        }
    }
    
    // Toggle row details for weekly feedback
    function toggleWeeklyRowDetails(rowId) {
        const detailsRow = document.getElementById(`weekly-details-${rowId}`);
        const expandIcon = document.getElementById(`weekly-expand-icon-${rowId}`);
        
        if (detailsRow) {
            if (detailsRow.classList.contains('active')) {
                detailsRow.classList.remove('active');
                if (expandIcon) expandIcon.classList.remove('expanded');
            } else {
                detailsRow.classList.add('active');
                if (expandIcon) expandIcon.classList.add('expanded');
            }
        }
    }
    
    function viewStudentFeedback(id) {
        toggleRowDetails(id);
    }
    
    function viewWeeklyFeedback(id) {
        toggleWeeklyRowDetails(id);
    }
    
    function markActionTaken(id, existingAction) {
        toggleRowDetails(id);
        
        setTimeout(() => {
            const actionForm = document.getElementById(`action-form-${id}`);
            if (actionForm) {
                actionForm.style.display = 'block';
                actionForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const textarea = actionForm.querySelector('textarea');
                if (textarea) textarea.focus();
            }
        }, 300);
    }
    
    function cancelActionForm(id) {
        const actionForm = document.getElementById(`action-form-${id}`);
        if (actionForm) {
            actionForm.style.display = 'none';
        }
    }
    
    function deleteStudentFeedback(id) {
        if (confirm('Are you sure you want to delete this feedback?')) {
            showLoading();
            $.ajax({
                url: 'delete_feedback.php',
                type: 'POST',
                data: { id: id, type: 'student' },
                success: function() { location.reload(); },
                error: function() { hideLoading(); alert('Error deleting feedback.'); }
            });
        }
    }
    
    function deleteWeeklyFeedback(id) {
        if (confirm('Are you sure you want to delete this weekly feedback?')) {
            showLoading();
            $.ajax({
                url: 'delete_weekly_feedback.php',
                type: 'POST',
                data: { id: id },
                success: function() { location.reload(); },
                error: function() { hideLoading(); alert('Error deleting weekly feedback.'); }
            });
        }
    }
    
    function showLoading() {
        $('#loadingOverlay').removeClass('hidden');
    }
    
    function hideLoading() {
        $('#loadingOverlay').addClass('hidden');
    }
    
    // Handle form submission for action taken
    $(document).on('submit', '.action-update-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const formData = form.serialize();
        
        showLoading();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            success: function() { location.reload(); },
            error: function() { hideLoading(); alert('Error updating action taken.'); }
        });
    });
    </script>
</body>
</html>