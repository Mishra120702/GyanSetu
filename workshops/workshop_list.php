<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle delete action
if (isset($_POST['delete_workshop'])) {
    $workshop_id = $_POST['workshop_id'];
    $stmt = $db->prepare("DELETE FROM workshops WHERE workshop_id = ?");
    $stmt->execute([$workshop_id]);
}

// Get workshop statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total_workshops,
        SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming,
        SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(current_registrations) as total_registrations,
        SUM(max_participants) as total_capacity
    FROM workshops
")->fetch(PDO::FETCH_ASSOC);

// Get registration trends (last 6 months)
$registrationTrends = $db->query("
    SELECT 
        DATE_FORMAT(start_datetime, '%Y-%m') as month,
        COUNT(*) as workshops_count,
        SUM(current_registrations) as registrations
    FROM workshops
    WHERE start_datetime >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(start_datetime, '%Y-%m')
    ORDER BY month ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get the last workshop ID from the database
$lastWorkshop = $db->query("SELECT workshop_id FROM workshops ORDER BY workshop_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$nextWorkshopId = 'WS001'; // Default if no workshops exist

if ($lastWorkshop) {
    // Extract the numeric part and increment
    $lastNumber = (int) substr($lastWorkshop['workshop_id'], 2);
    $nextNumber = $lastNumber + 1;
    $nextWorkshopId = 'WS' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
}

// Handle add new workshop action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_workshop'])) {
    $cover_image = ''; // Default empty
    
    // Handle file upload
    if (isset($_FILES['cover_image_file']) && $_FILES['cover_image_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/workshops/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileExt = pathinfo($_FILES['cover_image_file']['name'], PATHINFO_EXTENSION);
        $fileName = 'workshop_' . $nextWorkshopId . '_' . time() . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['cover_image_file']['tmp_name'], $filePath)) {
            $cover_image = $filePath;
        }
    }
    
    $stmt = $db->prepare("INSERT INTO workshops (
        workshop_id, title, description, start_datetime, end_datetime, location, 
        max_participants, current_registrations, trainer_id, fee,
        status, created_by, cover_image, requirements, certificate_available
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute([
        $_POST['workshop_id'],
        $_POST['title'],
        $_POST['description'],
        $_POST['start_datetime'],
        $_POST['end_datetime'],
        $_POST['location'],
        $_POST['max_participants'],
        $_POST['current_registrations'],
        $_POST['trainer_id'],
        $_POST['fee'],
        $_POST['status'],
        $_SESSION['user_id'],
        $cover_image,
        $_POST['requirements'],
        isset($_POST['certificate_available']) ? 1 : 0
    ]);
    
    // Refresh the page to show the new workshop
    header("Location: workshop_list.php");
    exit();
}

// Get filter values from GET parameters
$title_filter = $_GET['title'] ?? '';
$status_filter = $_GET['status'] ?? '';
$trainer_filter = $_GET['trainer'] ?? '';
$date_filter = $_GET['date_range'] ?? '';

// Build the query with filters
$query = "SELECT w.*, t.name as trainer_name 
          FROM workshops w
          LEFT JOIN trainers t ON w.trainer_id = t.id
          WHERE 1=1";
$params = [];

if (!empty($title_filter)) {
    $query .= " AND w.title LIKE ?";
    $params[] = "%$title_filter%";
}

if (!empty($status_filter)) {
    $query .= " AND w.status = ?";
    $params[] = $status_filter;
}

if (!empty($trainer_filter)) {
    $query .= " AND w.trainer_id = ?";
    $params[] = $trainer_filter;
}

if (!empty($date_filter)) {
    $dates = explode(' to ', $date_filter);
    if (count($dates) === 2) {
        $query .= " AND w.start_datetime >= ? AND w.end_datetime <= ?";
        $params[] = $dates[0] . ' 00:00:00';
        $params[] = $dates[1] . ' 23:59:59';
    } elseif (count($dates) === 1) {
        $query .= " AND DATE(w.start_datetime) = ?";
        $params[] = $dates[0];
    }
}

// Execute the query
$stmt = $db->prepare($query);
$stmt->execute($params);
$workshops = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all trainers for dropdown
$trainers = $db->query("SELECT id, name FROM trainers")->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for charts
$chartLabels = [];
$chartWorkshops = [];
$chartRegistrations = [];

foreach ($registrationTrends as $trend) {
    $chartLabels[] = date('M Y', strtotime($trend['month'] . '-01'));
    $chartWorkshops[] = $trend['workshops_count'];
    $chartRegistrations[] = $trend['registrations'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workshop Dashboard - ASD Academy</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css">
    <link rel="stylesheet" href="../css/workshop.css">
    <style>
        /* =========================================================
           ASD ACADEMY THEME — Navy/Sand Palette
           ========================================================= */
        :root {
            --navy-deep:   #1B3C53;
            --navy-mid:    #234C6A;
            --navy-light:  #456882;
            --sand:        #D2C1B6;
            --sand-light:  #e8ddd8;
            --sand-faint:  #f5f0ee;
            --white:       #ffffff;
            --text-primary: #1B3C53;
            --text-secondary: #456882;
            --text-muted:  #7a9ab0;
            --border-light: rgba(69,104,130,0.18);
            --border-medium: rgba(69,104,130,0.30);
            --shadow-sm: 0 2px 8px rgba(27,60,83,0.06);
            --shadow-md: 0 4px 20px rgba(27,60,83,0.10);
            --shadow-lg: 0 12px 36px rgba(27,60,83,0.14);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 18px;
            --radius-xl: 24px;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(160deg, var(--sand-faint) 0%, var(--sand-light) 100%);
            color: var(--text-primary);
        }

        .dashboard-header {
            background: linear-gradient(135deg, var(--navy-deep) 0%, var(--navy-mid) 50%, var(--navy-light) 100%);
            color: white;
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border-left: 4px solid var(--navy-deep);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.upcoming {
            border-left-color: var(--navy-light);
        }

        .stat-card.ongoing {
            border-left-color: #10b981;
        }

        .stat-card.completed {
            border-left-color: var(--navy-mid);
        }

        .stat-card.cancelled {
            border-left-color: #dc2626;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0.5rem 0;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .chart-container {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--border-light);
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .badge {
            padding: 0.35rem 0.65rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }

        .badge i {
            margin-right: 0.25rem;
        }

        .badge-upcoming {
            background-color: #e8f0f5;
            color: var(--navy-deep);
        }

        .badge-ongoing {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge-completed {
            background-color: #dbeafe;
            color: var(--navy-mid);
        }

        .badge-cancelled {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            color: white;
            box-shadow: 0 2px 8px rgba(27,60,83,0.25);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(27,60,83,0.35);
            color: #fff;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--navy-deep);
            color: var(--navy-deep);
        }

        .btn-outline:hover {
            background-color: rgba(27,60,83,0.08);
            border-color: var(--navy-mid);
            color: var(--navy-deep);
        }

        .action-link {
            color: var(--navy-deep);
            transition: all 0.2s ease;
            margin: 0 0.25rem;
        }

        .action-link:hover {
            color: var(--navy-mid);
            transform: scale(1.1);
        }

        .action-link.delete {
            color: #dc2626;
        }

        .action-link.delete:hover {
            color: #b91c1c;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 100;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 2rem;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 800px;
            animation: fadeInDown 0.4s;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-light);
        }

        .close-modal {
            color: var(--text-muted);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .close-modal:hover {
            color: var(--text-primary);
            transform: rotate(90deg);
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            transition: all 0.2s ease;
            background-color: var(--sand-faint);
            color: var(--text-primary);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--navy-light);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
            background-color: var(--white);
        }

        .filter-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-light);
        }

        .minimal-input {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            transition: all 0.2s ease;
            background-color: var(--sand-faint);
            color: var(--text-primary);
        }

        .minimal-input:focus {
            outline: none;
            border-color: var(--navy-light);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
            background-color: var(--white);
        }

        .progress-bar {
            height: 0.5rem;
            background-color: var(--border-light);
            border-radius: 0.25rem;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--navy-deep), var(--navy-light));
            border-radius: 0.25rem;
            transition: width 0.6s ease;
        }

        .thumbnail-preview {
            width: 100%;
            max-height: 200px;
            object-fit: contain;
            margin-bottom: 1rem;
            border-radius: var(--radius-sm);
            border: 1px dashed var(--border-medium);
            padding: 0.5rem;
            background-color: var(--sand-faint);
        }

        .file-input-container {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-button {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 0.5rem 1rem;
            background-color: var(--sand-faint);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .file-input-button:hover {
            background-color: var(--sand-light);
        }

        .file-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .animate-pulse {
            animation: pulse 2s infinite;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-light);
            margin-left: 0.25rem;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            color: white !important;
            border-color: var(--navy-mid);
        }
        
        /* DataTables search box styling */
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 0.375rem 0.75rem;
            background-color: var(--sand-faint);
            color: var(--text-primary);
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--navy-light);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
        }
        
        /* Table header */
        #workshopTable thead th {
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            color: white;
            border: none;
        }
        #workshopTable tbody tr:hover {
            background-color: var(--sand-faint);
        }
        #workshopTable tbody td {
            border-bottom: 1px solid var(--border-light);
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen p-4 md:p-6">
        <!-- Dashboard Header -->
        <div class="dashboard-header animate__animated animate__fadeIn">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-chalkboard-teacher mr-3"></i>
                        Workshop Management Dashboard
                    </h1>
                    <p class="text-blue-100 mt-2">Manage and monitor all workshops and training sessions</p>
                </div>
                <button id="openModalBtn" class="btn btn-primary mt-4 md:mt-0 animate__animated animate__pulse">
                    <i class="fas fa-plus"></i> Add New Workshop
                </button>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.1s">
                <div class="stat-label">Total Workshops</div>
                <div class="stat-value"><?= $stats['total_workshops'] ?></div>
                <div class="text-sm text-gray-500">Across all categories</div>
            </div>
            
            <div class="stat-card upcoming animate__animated animate__fadeInUp" style="animation-delay: 0.2s">
                <div class="stat-label">Upcoming</div>
                <div class="stat-value"><?= $stats['upcoming'] ?></div>
                <div class="progress-bar mt-2">
                    <div class="progress-fill" style="width: <?= $stats['total_workshops'] > 0 ? ($stats['upcoming'] / $stats['total_workshops']) * 100 : 0 ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card ongoing animate__animated animate__fadeInUp" style="animation-delay: 0.3s">
                <div class="stat-label">Ongoing</div>
                <div class="stat-value"><?= $stats['ongoing'] ?></div>
                <div class="progress-bar mt-2">
                    <div class="progress-fill" style="width: <?= $stats['total_workshops'] > 0 ? ($stats['ongoing'] / $stats['total_workshops']) * 100 : 0 ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card completed animate__animated animate__fadeInUp" style="animation-delay: 0.4s">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $stats['completed'] ?></div>
                <div class="progress-bar mt-2">
                    <div class="progress-fill" style="width: <?= $stats['total_workshops'] > 0 ? ($stats['completed'] / $stats['total_workshops']) * 100 : 0 ?>%"></div>
                </div>
            </div>
        </div>
        
        
        <!-- Filter Card -->
        <div class="filter-card animate__animated animate__fadeIn">
            <form method="GET" action="">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <input type="text" name="title" id="titleFilter" placeholder="Filter by title..." 
                           class="minimal-input" value="<?= htmlspecialchars($title_filter) ?>">
                    
                    <input type="text" name="date_range" id="dateRangeFilter" placeholder="Select date range" 
                           class="minimal-input date-picker" value="<?= htmlspecialchars($date_filter) ?>">
                    
                    <select name="status" id="statusFilter" class="minimal-input">
                        <option value="">All Statuses</option>
                        <option value="upcoming" <?= $status_filter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                        <option value="ongoing" <?= $status_filter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                    
                    <select name="trainer" id="trainerFilter" class="minimal-input">
                        <option value="">All Trainers</option>
                        <?php foreach ($trainers as $trainer): ?>
                            <option value="<?= $trainer['id'] ?>" <?= $trainer_filter == $trainer['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($trainer['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div class="flex space-x-2">
                        <button type="submit" class="btn btn-primary flex-1">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="workshop_list.php" class="btn btn-outline">
                            <i class="fas fa-sync-alt"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Workshop Table Card -->
        <div class="card animate__animated animate__fadeIn">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-list-ul mr-2 text-blue-500"></i>
                    All Workshops
                </h3>
                <div class="text-sm text-gray-500">
                    Showing <?= count($workshops) ?> workshop(s)
                </div>
            </div>
            
            <table id="workshopTable" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Dates & Times</th>
                        <th>Location</th>
                        <th>Participants</th>
                        <th>Trainer</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workshops as $workshop): ?>
                    <tr>
                        <td><?= htmlspecialchars($workshop['workshop_id']) ?></td>
                        <td>
                            <div class="font-medium text-gray-900"><?= htmlspecialchars($workshop['title']) ?></div>
                            <div class="text-sm text-gray-500">₹<?= number_format($workshop['fee'], 2) ?></div>
                        </td>
                        <td>
                            <div class="text-sm">
                                <div><?= date('d M Y', strtotime($workshop['start_datetime'])) ?></div>
                                <div class="text-gray-500"><?= date('H:i', strtotime($workshop['start_datetime'])) ?> - <?= date('H:i', strtotime($workshop['end_datetime'])) ?></div>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($workshop['location']) ?></td>
                        <td>
                            <div class="text-sm">
                                <div><?= $workshop['current_registrations'] ?>/<?= $workshop['max_participants'] ?></div>
                                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                                    <div class="bg-blue-600 h-1.5 rounded-full" style="width: <?= $workshop['max_participants'] > 0 ? ($workshop['current_registrations'] / $workshop['max_participants']) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($workshop['trainer_name'] ?? 'N/A') ?></td>
                        <td>
                            <?php 
                            $badgeClass = '';
                            if ($workshop['status'] === 'ongoing') $badgeClass = 'badge-ongoing';
                            else if ($workshop['status'] === 'completed') $badgeClass = 'badge-completed';
                            else if ($workshop['status'] === 'upcoming') $badgeClass = 'badge-upcoming';
                            else if ($workshop['status'] === 'cancelled') $badgeClass = 'badge-cancelled';
                            
                            $icon = '';
                            if ($workshop['status'] === 'ongoing') $icon = 'fa-play-circle';
                            else if ($workshop['status'] === 'completed') $icon = 'fa-check-circle';
                            else if ($workshop['status'] === 'upcoming') $icon = 'fa-clock';
                            else if ($workshop['status'] === 'cancelled') $icon = 'fa-times-circle';
                            ?>
                            <span class="badge <?= $badgeClass ?>">
                                <i class="fas <?= $icon ?>"></i>
                                <?= htmlspecialchars(ucfirst($workshop['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="flex items-center space-x-2">
                                <a href="workshop_view.php?id=<?= $workshop['workshop_id'] ?>" 
                                   class="action-link" 
                                   title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit_workshop.php?id=<?= $workshop['workshop_id'] ?>" 
                                   class="action-link" 
                                   title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="delete_workshop.php?id=<?= $workshop['workshop_id'] ?>" 
                                   class="action-link delete" 
                                   title="Delete"
                                   onclick="return confirm('Are you sure you want to delete this workshop?')">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Add Workshop Modal -->
    <div id="addWorkshopModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-xl font-semibold text-gray-800">Add New Workshop</h3>
                <span class="close-modal">&times;</span>
            </div>
            <form id="workshopForm" method="post" class="space-y-4" enctype="multipart/form-data">
                <input type="hidden" name="add_workshop" value="1">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Workshop ID -->
                    <div class="form-group">
                        <label for="workshop_id" class="form-label">Workshop ID*</label>
                        <input type="text" id="workshop_id" name="workshop_id" 
                               class="form-control bg-gray-100"
                               value="<?= htmlspecialchars($nextWorkshopId) ?>" readonly required>
                    </div>
                    
                    <!-- Title -->
                    <div class="form-group">
                        <label for="title" class="form-label">Title*</label>
                        <input type="text" id="title" name="title" 
                               class="form-control"
                               required>
                    </div>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" rows="3"
                              class="form-control"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Start Date/Time -->
                    <div class="form-group">
                        <label for="start_datetime" class="form-label">Start Date & Time*</label>
                        <input type="datetime-local" id="start_datetime" name="start_datetime" 
                               class="form-control"
                               required>
                    </div>
                    
                    <!-- End Date/Time -->
                    <div class="form-group">
                        <label for="end_datetime" class="form-label">End Date & Time*</label>
                        <input type="datetime-local" id="end_datetime" name="end_datetime" 
                               class="form-control"
                               required>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Location -->
                    <div class="form-group">
                        <label for="location" class="form-label">Location*</label>
                        <input type="text" id="location" name="location" 
                               class="form-control"
                               required>
                    </div>
                    
                    <!-- Max Participants -->
                    <div class="form-group">
                        <label for="max_participants" class="form-label">Max Participants*</label>
                        <input type="number" id="max_participants" name="max_participants" min="1" 
                               class="form-control"
                               required>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Current Registrations -->
                    <div class="form-group">
                        <label for="current_registrations" class="form-label">Current Registrations</label>
                        <input type="number" id="current_registrations" name="current_registrations" min="0" 
                               class="form-control"
                               value="0">
                    </div>
                    
                    <!-- Trainer -->
                    <div class="form-group">
                        <label for="trainer_id" class="form-label">Trainer</label>
                        <select id="trainer_id" name="trainer_id" 
                                class="form-control">
                            <option value="">Select Trainer</option>
                            <?php foreach ($trainers as $trainer): ?>
                                <option value="<?= $trainer['id'] ?>"><?= htmlspecialchars($trainer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Fee -->
                    <div class="form-group">
                        <label for="fee" class="form-label">Fee (₹)*</label>
                        <input type="number" id="fee" name="fee" min="0" step="0.01" 
                               class="form-control"
                               required>
                    </div>
                    
                    <!-- Cover Image -->
                    <div class="form-group">
                        <label for="cover_image_file" class="form-label">Cover Image</label>
                        <div class="thumbnail-preview mb-2 bg-gray-100 flex items-center justify-center" id="thumbnailPreview">
                            <i class="fas fa-image text-gray-400 text-4xl"></i>
                        </div>
                        <div class="file-input-container">
                            <div class="file-input-button">
                                <i class="fas fa-upload mr-2"></i>
                                <span id="fileLabel">Choose thumbnail image</span>
                            </div>
                            <input type="file" id="cover_image_file" name="cover_image_file" 
                                   class="file-input" accept="image/*">
                        </div>
                        <small class="text-gray-500">Recommended size: 1200x630 pixels</small>
                    </div>
                </div>

                <!-- Requirements -->
                <div class="form-group">
                    <label for="requirements" class="form-label">Requirements</label>
                    <textarea id="requirements" name="requirements" rows="2"
                              class="form-control"></textarea>
                </div>

                <!-- Certificate Available -->
                <div class="form-group flex items-center">
                    <input type="checkbox" id="certificate_available" name="certificate_available" 
                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    <label for="certificate_available" class="ml-2 block text-sm text-gray-700">
                        Certificate Available
                    </label>
                </div>

                <!-- Status -->
                <div class="form-group">
                    <label for="status" class="form-label">Status*</label>
                    <select id="status" name="status" 
                            class="form-control"
                            required>
                        <option value="upcoming">Upcoming</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <!-- Form Actions -->
                <div class="flex justify-end space-x-4 pt-4 border-t border-gray-200">
                    <button type="button" onclick="document.getElementById('addWorkshopModal').style.display='none'" 
                            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Create Workshop
                    </button>
                </div>
            </form>
        </div>
    </div>
    
        <!-- Include JS libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#workshopTable').DataTable({
                responsive: true,
                dom: '<"top"f>rt<"bottom"lip><"clear">',
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search workshops...",
                    lengthMenu: "Show _MENU_ workshops per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ workshops",
                    infoEmpty: "No workshops found",
                    infoFiltered: "(filtered from _MAX_ total workshops)"
                },
                columnDefs: [
                    { responsivePriority: 1, targets: 1 }, // Title
                    { responsivePriority: 2, targets: 7 }, // Actions
                    { responsivePriority: 3, targets: 2 }, // Dates
                    { responsivePriority: 4, targets: 6 }, // Status
                    { responsivePriority: 5, targets: 0 }, // ID
                    { responsivePriority: 6, targets: 3 }, // Location
                    { responsivePriority: 7, targets: 4 }, // Participants
                    { responsivePriority: 8, targets: 5 }  // Trainer
                ]
            });
            
            // Initialize date range picker
            flatpickr("#dateRangeFilter", {
                mode: "range",
                dateFormat: "Y-m-d",
                allowInput: true,
                placeholder: "Select date range"
            });
            
            // Modal functionality
            const modal = document.getElementById("addWorkshopModal");
            const btn = document.getElementById("openModalBtn");
            const span = document.getElementsByClassName("close-modal")[0];
            
            btn.onclick = function() {
                modal.style.display = "block";
                document.body.style.overflow = "hidden"; // Prevent scrolling
            }
            
            span.onclick = function() {
                modal.style.display = "none";
                document.body.style.overflow = "auto"; // Re-enable scrolling
            }
            
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                    document.body.style.overflow = "auto"; // Re-enable scrolling
                }
            }
            
            // Form validation
            document.getElementById("workshopForm").addEventListener("submit", function(e) {
                const startDate = new Date(document.getElementById("start_datetime").value);
                const endDate = new Date(document.getElementById("end_datetime").value);
                
                if (startDate >= endDate) {
                    alert("End date/time must be after start date/time");
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
            
            // Initialize charts
            const trendsCtx = document.getElementById('trendsChart').getContext('2d');
            const trendsChart = new Chart(trendsCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [
                        {
                            label: 'Workshops',
                            data: <?= json_encode($chartWorkshops) ?>,
                            backgroundColor: 'rgba(67, 97, 238, 0.7)',
                            borderColor: 'rgba(67, 97, 238, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Registrations',
                            data: <?= json_encode($chartRegistrations) ?>,
                            backgroundColor: 'rgba(76, 201, 240, 0.7)',
                            borderColor: 'rgba(76, 201, 240, 1)',
                            borderWidth: 1,
                            type: 'line',
                            tension: 0.3,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
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
            
            // Auto-set end date to be 2 hours after start date by default
            document.getElementById('start_datetime').addEventListener('change', function() {
                const startInput = this.value;
                if (startInput) {
                    const startDate = new Date(startInput);
                    const endDate = new Date(startDate.getTime() + (2 * 60 * 60 * 1000)); // Add 2 hours
                    
                    // Format for datetime-local input
                    const endDateStr = endDate.toISOString().slice(0, 16);
                    document.getElementById('end_datetime').value = endDateStr;
                }
            });
            
            // Set default fee to 0 if empty
            document.getElementById('fee').addEventListener('blur', function() {
                if (this.value === '') {
                    this.value = '0';
                }
            });
            
            // Auto-capitalize workshop title
            document.getElementById('title').addEventListener('blur', function() {
                this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
            });
            
            // Cover image preview
            document.getElementById('cover_image_file').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.getElementById('thumbnailPreview');
                        preview.innerHTML = ''; // Clear the icon
                        preview.style.backgroundImage = `url(${e.target.result})`;
                        preview.style.backgroundSize = 'contain';
                        preview.style.backgroundPosition = 'center';
                        preview.style.backgroundRepeat = 'no-repeat';
                        document.getElementById('fileLabel').textContent = file.name;
                    }
                    reader.readAsDataURL(file);
                }
            });
        });
        
        // Confirmation for delete actions
        function confirmDelete(workshopId, workshopTitle) {
            return confirm(`Are you sure you want to delete the workshop "${workshopTitle}" (ID: ${workshopId})? This action cannot be undone.`);
        }
    </script>
</body>
</html>
