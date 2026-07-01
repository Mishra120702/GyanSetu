<?php
require_once '../db_connection.php';
session_start();

// Check user role - using the same check as sidebar.php
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    echo "Access denied. You do not have permission to view this page.";
    exit;
}

$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$year = isset($_GET['year']) ? intval($_GET['year']) : '';

$results = [];
$student_info = null;

// Function to calculate grade based on percentage
function calculateGrade($obtained_marks, $total_marks) {
    if ($total_marks == 0) return 'N/A';
    
    $percentage = ($obtained_marks / $total_marks) * 100;
    
    if ($percentage >= 90) return 'A+';
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C';
    if ($percentage >= 40) return 'D';
    return 'F';
}

if (!empty($search_query)) {
    try {
        // Search for student
        $sql = "SELECT s.*, b.batch_name, c.name as course_name 
                FROM students s 
                LEFT JOIN batches b ON s.batch_name = b.batch_id 
                LEFT JOIN courses c ON s.course = c.id 
                WHERE s.student_id LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ? 
                LIMIT 1";
        
        $stmt = $db->prepare($sql);
        $search_param = "%$search_query%";
        $stmt->execute([$search_param, $search_param]);
        $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student_info) {
            // Get student results
            $sql = "SELECT e.*, er.obtained_marks, er.grade, er.remarks, b.batch_name 
                    FROM exam_results er 
                    JOIN exams e ON er.exam_id = e.exam_id 
                    JOIN batches b ON e.batch_id = b.batch_id 
                    WHERE er.student_id = ?";
            
            $params = [$student_info['student_id']];
            
            // Filter by academic year if specified
            if (!empty($year)) {
                $sql .= " AND YEAR(e.exam_date) = ?";
                $params[] = $year;
            }
            
            $sql .= " ORDER BY e.exam_date DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        echo "<div class='alert alert-danger'>Database error: " . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Results - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Color Palette */
        :root {
            --primary: #1B3C53;
            --secondary: #234C6A;
            --accent: #456882;
            --neutral: #D2C1B6;
            --light-bg: #f5f3f1;
            --card-shadow: 0 4px 6px rgba(27, 60, 83, 0.07);
            --hover-shadow: 0 12px 30px rgba(27, 60, 83, 0.15);
            --gradient-primary: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
        }

        /* Base Styles */
        body {
            background: var(--light-bg);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #2d3436;
            overflow-x: hidden;
        }

        /* Main content adjustments for sidebar */
        .main-content {
            margin-left: 16rem;
            padding: 24px 30px;
            min-height: 100vh;
            background: var(--light-bg);
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes pulseGlow {
            0%, 100% {
                box-shadow: 0 0 20px rgba(27, 60, 83, 0.1);
            }
            50% {
                box-shadow: 0 0 40px rgba(27, 60, 83, 0.2);
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes floatUp {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-5px);
            }
        }

        @media (max-width: 767.98px) {
            .main-content {
                margin-left: 0;
                padding: 16px;
            }
        }

        /* Custom scrollbar */
        .scrollbar-thin {
            scrollbar-width: thin;
            scrollbar-color: var(--neutral) #f0f9ff;
        }
        
        .scrollbar-thin::-webkit-scrollbar {
            width: 6px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-track {
            background: #f0f9ff;
            border-radius: 3px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb {
            background: var(--neutral);
            border-radius: 3px;
            transition: background 0.3s ease;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb:hover {
            background: var(--accent);
        }

        /* Card Styling */
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            margin-bottom: 24px;
            background: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .card:hover::before {
            opacity: 1;
        }

        .card:hover {
            box-shadow: var(--hover-shadow);
            transform: translateY(-4px);
        }

        .card-header {
            background: #ffffff;
            border-bottom: 2px solid var(--neutral);
            padding: 18px 24px;
            border-radius: 16px 16px 0 0 !important;
            position: relative;
        }

        .card-header h5 {
            color: var(--primary);
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-header h5 i {
            color: var(--accent);
            transition: transform 0.3s ease;
        }

        .card:hover .card-header h5 i {
            transform: rotate(-5deg) scale(1.1);
        }

        .card-body {
            padding: 24px;
            animation: fadeIn 0.6s ease-out;
        }

        /* Header Section */
        .page-header {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: var(--card-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            transition: all 0.3s ease;
            animation: slideIn 0.5s ease-out;
            position: relative;
            overflow: hidden;
        }

        .page-header::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            animation: pulseGlow 3s infinite;
        }

        .page-header h2 {
            color: var(--primary);
            font-weight: 700;
            font-size: 1.8rem;
            margin: 0;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h2 i {
            color: var(--accent);
            background: var(--light-bg);
            padding: 12px;
            border-radius: 12px;
            font-size: 1.4rem;
            transition: all 0.3s ease;
            animation: floatUp 3s ease-in-out infinite;
        }

        .page-header h2 i:hover {
            transform: rotate(10deg) scale(1.1);
            background: var(--neutral);
        }

        /* Button Styling with Animations */
        .btn {
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            overflow: hidden;
        }

        .btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transition: width 0.6s ease, height 0.6s ease, top 0.6s ease, left 0.6s ease;
        }

        .btn:active::after {
            width: 300px;
            height: 300px;
            top: -100px;
            left: -100px;
        }

        .btn-primary {
            background: var(--gradient-primary);
            border: none;
            padding: 10px 24px;
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(27, 60, 83, 0.3);
        }

        .btn-primary:active {
            transform: translateY(0px);
        }

        .btn-outline-primary {
            color: var(--primary);
            border: 2px solid var(--primary);
            padding: 8px 20px;
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--gradient-primary);
            color: #fff;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(27, 60, 83, 0.2);
            border-color: transparent;
        }

        .btn-outline-primary:active {
            transform: translateY(0px);
        }

        .btn-outline-primary i {
            transition: transform 0.3s ease;
        }

        .btn-outline-primary:hover i {
            transform: translateX(-5px);
        }

        /* Form Styling with Focus Effects */
        .form-control, .form-select {
            border: 2px solid #e8e5e2;
            border-radius: 10px;
            padding: 10px 14px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            background: #faf8f7;
        }

        .form-control:hover, .form-select:hover {
            background: #ffffff;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(27, 60, 83, 0.1);
            background: #ffffff;
            transform: scale(1.02);
        }

        .form-label {
            color: var(--primary);
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label i {
            color: var(--accent);
        }

        /* Student Information with Animations */
        .student-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .student-info-item {
            padding: 14px 18px;
            background: var(--light-bg);
            border-radius: 12px;
            border-left: 4px solid var(--accent);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            animation: slideIn 0.6s ease-out forwards;
            opacity: 0;
            cursor: default;
            position: relative;
            overflow: hidden;
        }

        .student-info-item:nth-child(1) { animation-delay: 0.1s; }
        .student-info-item:nth-child(2) { animation-delay: 0.2s; }
        .student-info-item:nth-child(3) { animation-delay: 0.3s; }
        .student-info-item:nth-child(4) { animation-delay: 0.4s; }

        .student-info-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gradient-primary);
            opacity: 0;
            transition: opacity 0.4s ease;
            z-index: 0;
        }

        .student-info-item:hover::before {
            opacity: 0.05;
        }

        .student-info-item:hover {
            transform: translateX(8px);
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.1);
            border-left-color: var(--primary);
        }

        .student-info-item p {
            margin: 0;
            position: relative;
            z-index: 1;
        }

        .student-info-item strong {
            color: var(--primary);
            display: block;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: 700;
        }

        .student-info-item strong i {
            color: var(--accent);
            margin-right: 4px;
        }

        .student-info-item span {
            font-size: 1rem;
            font-weight: 500;
            color: #2d3436;
        }

        /* Table Styling with Animations */
        .table {
            margin-bottom: 0;
            font-size: 0.95rem;
        }

        .table thead th {
            background: var(--light-bg);
            color: var(--primary);
            font-weight: 600;
            border-bottom: 2px solid var(--neutral);
            padding: 14px 12px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #f0eeec;
            transition: all 0.3s ease;
        }

        .table tbody tr {
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            animation: fadeIn 0.5s ease-out forwards;
            opacity: 0;
        }

        .table tbody tr:nth-child(1) { animation-delay: 0.05s; }
        .table tbody tr:nth-child(2) { animation-delay: 0.1s; }
        .table tbody tr:nth-child(3) { animation-delay: 0.15s; }
        .table tbody tr:nth-child(4) { animation-delay: 0.2s; }
        .table tbody tr:nth-child(5) { animation-delay: 0.25s; }
        .table tbody tr:nth-child(6) { animation-delay: 0.3s; }
        .table tbody tr:nth-child(7) { animation-delay: 0.35s; }
        .table tbody tr:nth-child(8) { animation-delay: 0.4s; }
        .table tbody tr:nth-child(9) { animation-delay: 0.45s; }
        .table tbody tr:nth-child(10) { animation-delay: 0.5s; }

        .table tbody tr:hover {
            background: #faf8f7;
            transform: scale(1.01);
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.05);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Grade Styling with Animations */
        .grade-Aplus { background: linear-gradient(135deg, #d4edda, #b8e0c4); }
        .grade-A { background: linear-gradient(135deg, #d1ecf1, #b8dde3); }
        .grade-Bplus { background: linear-gradient(135deg, #fff3cd, #ffe69b); }
        .grade-B { background: linear-gradient(135deg, #ffeaa7, #ffd93d); }
        .grade-C { background: linear-gradient(135deg, #f8d7da, #f5c6cb); }
        .grade-D { background: linear-gradient(135deg, #f5c6cb, #f1b0b7); }
        .grade-F { background: linear-gradient(135deg, #f8d7da, #f5c6cb); }

        .grade-badge {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.85rem;
            min-width: 40px;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .grade-badge::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, transparent 60%);
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .grade-badge:hover::after {
            opacity: 1;
        }

        .grade-badge:hover {
            transform: scale(1.1) rotate(-3deg);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* Overall row */
        .table-primary {
            background: linear-gradient(135deg, #e8f0f8, #d4e3f0) !important;
            border-top: 3px solid var(--primary);
            position: relative;
            animation: pulseGlow 3s infinite;
        }

        .table-primary td {
            font-weight: 600;
            color: var(--primary);
        }

        .table-primary:hover {
            transform: scale(1.005) !important;
        }

        /* Badge */
        .badge-info {
            background: var(--neutral);
            color: var(--primary);
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-info:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(210, 193, 182, 0.4);
        }

        /* Alert with Animations */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            animation: scaleIn 0.4s ease-out;
            position: relative;
            overflow: hidden;
        }

        .alert::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }

        .alert-info {
            background: #e8f0f8;
            color: var(--primary);
        }

        .alert-info::before {
            background: var(--primary);
        }

        .alert-warning {
            background: #fff8f0;
            color: #856404;
        }

        .alert-warning::before {
            background: #ffc107;
        }

        .alert-danger {
            background: #fde8e8;
            color: #721c24;
        }

        .alert-danger::before {
            background: #dc3545;
        }

        .alert i {
            margin-right: 8px;
        }

        /* Responsive Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
        }

        /* Mobile responsiveness */
        @media (max-width: 767.98px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
                padding: 16px;
            }

            .page-header h2 {
                font-size: 1.4rem;
                justify-content: center;
            }

            .page-header h2 i {
                padding: 8px;
                font-size: 1.2rem;
            }

            .page-header .btn {
                width: 100%;
            }

            .card-body {
                padding: 16px;
            }

            .card-header {
                padding: 14px 16px;
            }

            .student-info-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .student-info-item {
                padding: 12px 14px;
                animation: slideIn 0.4s ease-out forwards;
            }

            .student-info-item:hover {
                transform: translateX(4px) scale(1.01);
            }

            .table thead th {
                font-size: 0.7rem;
                padding: 10px 8px;
                white-space: nowrap;
            }

            .table tbody td {
                font-size: 0.82rem;
                padding: 10px 8px;
            }

            .table tbody td:first-child {
                font-weight: 500;
            }

            .grade-badge {
                padding: 3px 10px;
                font-size: 0.72rem;
                min-width: 30px;
            }

            .grade-badge:hover {
                transform: scale(1.05) !important;
            }

            .form-label {
                font-size: 0.85rem;
            }

            .btn {
                font-size: 0.9rem;
                padding: 10px 16px;
            }

            .btn-outline-primary {
                padding: 8px 16px;
            }

            .badge-info {
                font-size: 0.75rem;
                padding: 4px 12px;
            }

            .alert {
                font-size: 0.9rem;
                padding: 12px 16px;
            }

            /* Touch feedback */
            .btn:active {
                transform: scale(0.95) !important;
            }

            .student-info-item:active {
                transform: scale(0.98) !important;
            }

            .table tbody tr:active {
                transform: scale(0.99) !important;
            }
        }

        /* Tablet */
        @media (min-width: 768px) and (max-width: 1024px) {
            .main-content {
                padding: 20px 24px;
            }

            .student-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .page-header {
                padding: 18px 20px;
            }

            .page-header h2 {
                font-size: 1.6rem;
            }

            .table thead th {
                font-size: 0.8rem;
                padding: 12px 10px;
            }

            .table tbody td {
                font-size: 0.9rem;
                padding: 12px 10px;
            }
        }

        /* Desktop */
        @media (min-width: 1025px) {
            .main-content {
                padding: 24px 40px;
            }

            .student-info-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .card:hover .grade-badge {
                animation: pulseGlow 2s infinite;
            }
        }

        /* Touch-friendly hover alternatives */
        @media (hover: none) {
            .card:hover {
                transform: none !important;
                box-shadow: var(--card-shadow);
            }

            .card:hover::before {
                opacity: 0;
            }

            .btn:hover {
                transform: none !important;
            }

            .student-info-item:hover {
                transform: none !important;
            }

            .table tbody tr:hover {
                transform: none !important;
                background: transparent;
            }

            .grade-badge:hover {
                transform: none !important;
            }

            .badge-info:hover {
                transform: none !important;
            }
        }

        /* Loading shimmer effect for cards */
        .card-loading {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 16px;
        }

        /* Scroll reveal animation */
        .scroll-reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        .scroll-reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Status indicator dot */
        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
            animation: pulseGlow 2s infinite;
        }

        .status-dot.passed {
            background: #28a745;
        }

        .status-dot.failed {
            background: #dc3545;
        }

        .status-dot.pending {
            background: #ffc107;
        }
    </style>
</head>
<body>
    <!-- Include the enhanced sidebar -->
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <!-- Main content -->
    <div class="main-content">
        <div class="page-header">
            <h2>
                <i class="fas fa-graduation-cap"></i> Student Results
            </h2>
            <a href="exams.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left"></i> Back to Exams
            </a>
        </div>

        <!-- Search Form -->
        <div class="card scroll-reveal">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="student_search" class="form-label">
                            <i class="fas fa-user"></i> Student Name or ID
                        </label>
                        <input type="text" class="form-control" id="student_search" name="q" 
                               value="<?php echo htmlspecialchars($search_query); ?>" 
                               placeholder="Enter student name or ID" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="year" class="form-label">
                            <i class="fas fa-calendar"></i> Academic Year
                        </label>
                        <select class="form-select" id="year" name="year">
                            <option value="">All Years</option>
                            <?php
                            $current_year = date('Y');
                            for ($y = $current_year; $y >= $current_year - 5; $y--) {
                                $next_year = $y + 1;
                                $selected = ($year == $y) ? 'selected' : '';
                                echo "<option value='$y' $selected>$y-$next_year</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label d-none d-md-block">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($student_info): ?>
            <!-- Student Information -->
            <div class="card scroll-reveal">
                <div class="card-header">
                    <h5><i class="fas fa-id-card"></i> Student Information</h5>
                </div>
                <div class="card-body">
                    <div class="student-info-grid">
                        <div class="student-info-item">
                            <p>
                                <strong><i class="fas fa-id-badge"></i> Student ID</strong>
                                <span><?php echo htmlspecialchars($student_info['student_id']); ?></span>
                            </p>
                        </div>
                        <div class="student-info-item">
                            <p>
                                <strong><i class="fas fa-user"></i> Name</strong>
                                <span><?php echo htmlspecialchars($student_info['first_name'] . ' ' . $student_info['last_name']); ?></span>
                            </p>
                        </div>
                        <div class="student-info-item">
                            <p>
                                <strong><i class="fas fa-users"></i> Batch</strong>
                                <span><?php echo htmlspecialchars($student_info['batch_name']); ?></span>
                            </p>
                        </div>
                        <div class="student-info-item">
                            <p>
                                <strong><i class="fas fa-book"></i> Course</strong>
                                <span><?php echo htmlspecialchars($student_info['course_name']); ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Table -->
            <div class="card scroll-reveal">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap: 10px;">
                    <h5><i class="fas fa-list"></i> Exam Results</h5>
                    <?php if (!empty($year)): ?>
                        <span class="badge-info">
                            <i class="fas fa-calendar-alt"></i> <?php echo $year . '-' . ($year + 1); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge-info">
                            <i class="fas fa-globe"></i> All Academic Years
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (count($results) > 0): ?>
                        <div class="table-responsive scrollbar-thin">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Exam Name</th>
                                        <th>Subject</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Total</th>
                                        <th>Obtained</th>
                                        <th>%</th>
                                        <th>Grade</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_marks = 0;
                                    $obtained_marks = 0;
                                    ?>
                                    <?php foreach ($results as $result): ?>
                                        <?php
                                        $percentage = ($result['obtained_marks'] / $result['total_marks']) * 100;
                                        $total_marks += $result['total_marks'];
                                        $obtained_marks += $result['obtained_marks'];
                                        $grade_class = 'grade-' . str_replace('+', 'plus', $result['grade']);
                                        $status_class = ($percentage >= 40) ? 'passed' : 'failed';
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($result['exam_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($result['subject']); ?></td>
                                            <td><?php echo date('d M Y', strtotime($result['exam_date'])); ?></td>
                                            <td><?php echo ucfirst(str_replace('_', ' ', $result['exam_type'])); ?></td>
                                            <td><?php echo $result['total_marks']; ?></td>
                                            <td><?php echo $result['obtained_marks']; ?></td>
                                            <td>
                                                <span class="status-dot <?php echo $status_class; ?>"></span>
                                                <?php echo number_format($percentage, 1); ?>%
                                            </td>
                                            <td>
                                                <span class="grade-badge <?php echo $grade_class; ?>">
                                                    <?php echo $result['grade']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($result['remarks']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (count($results) > 1): ?>
                                        <?php
                                        $overall_percentage = ($total_marks > 0) ? ($obtained_marks / $total_marks) * 100 : 0;
                                        $overall_grade = calculateGrade($obtained_marks, $total_marks);
                                        $grade_class = 'grade-' . str_replace('+', 'plus', $overall_grade);
                                        $overall_status = ($overall_percentage >= 40) ? 'passed' : 'failed';
                                        ?>
                                        <tr class="table-primary">
                                            <td colspan="4"><strong>Overall Performance</strong></td>
                                            <td><strong><?php echo $total_marks; ?></strong></td>
                                            <td><strong><?php echo $obtained_marks; ?></strong></td>
                                            <td>
                                                <span class="status-dot <?php echo $overall_status; ?>"></span>
                                                <strong><?php echo number_format($overall_percentage, 1); ?>%</strong>
                                            </td>
                                            <td>
                                                <span class="grade-badge <?php echo $grade_class; ?>">
                                                    <?php echo $overall_grade; ?>
                                                </span>
                                            </td>
                                            <td></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            No exam results found for this student.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif (!empty($search_query)): ?>
            <div class="card scroll-reveal">
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        No student found with the search query "<strong><?php echo htmlspecialchars($search_query); ?></strong>".
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Scroll Reveal Animation
        document.addEventListener('DOMContentLoaded', function() {
            const revealElements = document.querySelectorAll('.scroll-reveal');
            
            const revealObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            });
            
            revealElements.forEach(el => revealObserver.observe(el));
        });

        // Smooth hover effect for grade badges on mobile
        if ('ontouchstart' in window) {
            document.querySelectorAll('.grade-badge').forEach(badge => {
                badge.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(1.15)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 300);
                });
            });
        }

        // Animate student info items on scroll
        document.addEventListener('DOMContentLoaded', function() {
            const infoItems = document.querySelectorAll('.student-info-item');
            
            const itemObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateX(0)';
                    }
                });
            }, {
                threshold: 0.2
            });
            
            infoItems.forEach(item => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-30px)';
                itemObserver.observe(item);
            });
        });

        // Table row hover animation enhancement
        document.querySelectorAll('.table tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transition = 'all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
            });
        });

        // Input focus animation
        document.querySelectorAll('.form-control, .form-select').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transition = 'all 0.3s ease';
            });
        });

        // Button ripple effect enhancement
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                let rect = this.getBoundingClientRect();
                let x = e.clientX - rect.left;
                let y = e.clientY - rect.top;
                
                let ripple = document.createElement('span');
                ripple.style.cssText = `
                    position: absolute;
                    top: ${y}px;
                    left: ${x}px;
                    width: 0;
                    height: 0;
                    border-radius: 50%;
                    background: rgba(255,255,255,0.4);
                    transform: translate(-50%, -50%);
                    animation: rippleEffect 0.6s ease-out forwards;
                    pointer-events: none;
                `;
                
                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);
                
                setTimeout(() => ripple.remove(), 600);
            });
        });

        // Add ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes rippleEffect {
                0% {
                    width: 0;
                    height: 0;
                    opacity: 0.5;
                }
                100% {
                    width: 300px;
                    height: 300px;
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Performance optimization - reduce animations on low-end devices
        if ('connection' in navigator && navigator.connection.saveData) {
            document.querySelectorAll('.card, .btn, .student-info-item, .grade-badge').forEach(el => {
                el.style.transition = 'none';
                el.style.animation = 'none';
            });
        }

        // Smooth page load animation
        window.addEventListener('load', function() {
            document.querySelector('.main-content').style.opacity = '1';
        });

        // Mobile touch feedback for cards
        if ('ontouchstart' in window) {
            document.querySelectorAll('.card').forEach(card => {
                card.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 200);
                });
            });
        }
    </script>
</body>
</html>