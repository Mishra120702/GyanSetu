<?php
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: workshop_list.php");
    exit;
}

$workshop_id = $_GET['id'];

// Get workshop details
$stmt = $db->prepare("
    SELECT w.*, t.name as trainer_name 
    FROM workshops w
    LEFT JOIN trainers t ON w.trainer_id = t.id
    WHERE w.workshop_id = ?
");
$stmt->execute([$workshop_id]);
$workshop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$workshop) {
    header("Location: workshop_list.php");
    exit;
}

// Get workshop schedule
$schedule = $db->prepare("
    SELECT * FROM workshop_schedule 
    WHERE workshop_id = ?
    ORDER BY start_time
");
$schedule->execute([$workshop_id]);
$sessions = $schedule->fetchAll(PDO::FETCH_ASSOC);

// Get workshop materials
$materials = $db->prepare("
    SELECT * FROM workshop_materials 
    WHERE workshop_id = ?
    ORDER BY uploaded_at DESC
");
$materials->execute([$workshop_id]);
$materials = $materials->fetchAll(PDO::FETCH_ASSOC);

// Get registration count
$registrations = $db->prepare("
    SELECT COUNT(*) as count FROM workshop_registrations 
    WHERE workshop_id = ?
");
$registrations->execute([$workshop_id]);
$registration_count = $registrations->fetch(PDO::FETCH_ASSOC)['count'];

// Check if current user is registered
$is_registered = false;
if ($_SESSION['user_role'] === 'student') {
    $check_reg = $db->prepare("
        SELECT 1 FROM workshop_registrations 
        WHERE workshop_id = ? AND student_id = ?
    ");
    $check_reg->execute([$workshop_id, $_SESSION['user_student_id']]);
    $is_registered = $check_reg->fetch() !== false;
}

// Get trainer details if available
$trainer = null;
if ($workshop['trainer_id']) {
    $trainer_stmt = $db->prepare("
        SELECT * FROM trainers 
        WHERE id = ?
    ");
    $trainer_stmt->execute([$workshop['trainer_id']]);
    $trainer = $trainer_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get certificate template if exists
$certificate_template = null;
if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor') {
    $template_stmt = $db->prepare("
        SELECT * FROM certificate_templates 
        WHERE workshop_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $template_stmt->execute([$workshop_id]);
    $certificate_template = $template_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get registered students with certificates
$registered_students = [];
if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor' || $is_registered) {
    $students_stmt = $db->prepare("
        SELECT wr.*, s.first_name, s.last_name, s.profile_picture
        FROM workshop_registrations wr
        JOIN students s ON wr.student_id = s.student_id
        WHERE wr.workshop_id = ?
        ORDER BY wr.registration_date DESC
    ");
    $students_stmt->execute([$workshop_id]);
    $registered_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get attendance statistics for chart
$attendance_stats = [];
if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor') {
    $attendance_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN attendance_status IS NULL THEN 1 ELSE 0 END) as not_recorded
        FROM workshop_attendance
        WHERE workshop_id = ?
    ");
    $attendance_stmt->execute([$workshop_id]);
    $attendance_stats = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
}

// Format dates for display
$start_date = new DateTime($workshop['start_datetime']);
$end_date = new DateTime($workshop['end_datetime']);
$duration = $start_date->diff($end_date);
$duration_hours = $duration->h + ($duration->days * 24);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($workshop['title']) ?> - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css">
    <link rel="stylesheet" href="../css/tailwind.css">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-light: #93c5fd;
            --primary-dark: #1d4ed8;
            --secondary: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1f2937;
            --light: #f3f4f6;
        }
        
        .card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .card-header {
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .workshop-cover {
            width: 100%;
            max-height: 400px;
            object-fit: cover;
            border-radius: 0.75rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .workshop-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .workshop-title {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.5rem;
        }
        
        .workshop-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #4b5563;
        }
        
        .meta-icon {
            color: var(--primary);
            font-size: 1.25rem;
        }
        
        .meta-value {
            font-weight: 600;
            color: #111827;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            cursor: pointer;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
            border: 1px solid var(--primary);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background-color: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        
        .btn-outline:hover {
            background-color: var(--primary-light);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background-color: var(--secondary);
            color: white;
            border: 1px solid var(--secondary);
        }
        
        .btn-success:hover {
            background-color: #059669;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
            border: 1px solid var(--danger);
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
            transform: translateY(-2px);
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-primary {
            background-color: var(--primary-light);
            color: var(--primary-dark);
        }
        
        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        
        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .floating-actions {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            z-index: 30;
        }
        
        .floating-btn {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .floating-btn:hover {
            transform: translateY(-5px) scale(1.1);
        }
        
        .floating-btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .floating-btn-danger {
            background-color: var(--danger);
            color: white;
        }
        
        .registration-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 50;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 0.75rem;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        
        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
            transition: color 0.2s ease;
        }
        
        .close-modal:hover {
            color: #111827;
        }
        
        .alert {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 1rem;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            z-index: 100;
            transform: translateX(150%);
            transition: transform 0.3s ease;
        }
        
        .alert.show {
            transform: translateX(0);
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid #ef4444;
        }
        
        .alert-icon {
            font-size: 1.25rem;
        }
        
        .schedule-item {
            display: flex;
            padding: 1rem;
            border-radius: 0.5rem;
            background: white;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .schedule-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .schedule-time {
            min-width: 120px;
            font-weight: 600;
            color: var(--primary-dark);
        }
        
        .schedule-details {
            flex-grow: 1;
        }
        
        .schedule-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .schedule-desc {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .material-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 0.5rem;
            background: white;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .material-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .material-icon {
            font-size: 1.5rem;
            color: var(--primary);
            margin-right: 1rem;
        }
        
        .material-details {
            flex-grow: 1;
        }
        
        .material-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .material-meta {
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        .trainer-card {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            border-radius: 0.75rem;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }
        
        .trainer-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .trainer-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1.5rem;
            border: 3px solid var(--primary-light);
        }
        
        .trainer-details {
            flex-grow: 1;
        }
        
        .trainer-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .trainer-title {
            color: var(--primary);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .trainer-bio {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }
        
        .trainer-social {
            display: flex;
            gap: 0.75rem;
        }
        
        .social-link {
            color: #6b7280;
            transition: color 0.2s ease;
        }
        
        .social-link:hover {
            color: var(--primary);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 1.5rem;
        }
        
        .certificate-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 0.5rem;
            background: white;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .certificate-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .certificate-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
            border: 2px solid var(--primary-light);
        }
        
        .certificate-details {
            flex-grow: 1;
        }
        
        .certificate-student {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .certificate-meta {
            font-size: 0.75rem;
            color: #718096;
        }
        
        .certificate-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .certificate-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-issued {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .upload-certificate-btn {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            background-color: var(--info);
            color: white;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
            transition: all 0.2s ease;
        }
        
        .upload-certificate-btn:hover {
            background-color: #3a7bd0;
            transform: translateY(-2px);
        }
        
        .upload-certificate-btn i {
            margin-right: 0.5rem;
        }
        
        .certificate-template-card {
            background: white;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .template-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .workshop-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen p-4 md:p-6">
        <!-- Workshop Header -->
        <div class="workshop-header animate__animated animate__fadeIn">
            <div>
                <h1 class="workshop-title"><?= htmlspecialchars($workshop['title']) ?></h1>
                <div class="flex items-center gap-2">
                    <span class="badge <?= $workshop['status'] === 'upcoming' ? 'badge-warning' : ($workshop['status'] === 'ongoing' ? 'badge-success' : 'badge-danger') ?>">
                        <?= ucfirst($workshop['status']) ?>
                    </span>
                    <?php if ($workshop['certificate_available']): ?>
                        <span class="badge badge-info">
                            <i class="fas fa-certificate mr-1"></i> Certificate Available
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor'): ?>
                <div>
                    <a href="edit_workshop.php?id=<?= $workshop_id ?>" class="btn btn-outline">
                        <i class="fas fa-edit"></i> Edit Workshop
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Workshop Cover Image -->
        <?php if ($workshop['cover_image']): ?>
        <img src="<?= htmlspecialchars($workshop['cover_image']) ?>" 
             alt="<?= htmlspecialchars($workshop['title']) ?>" 
             class="workshop-cover animate__animated animate__fadeIn animate-delay-2">
        <?php endif; ?>
        
        <!-- Workshop Meta Information -->
        <div class="workshop-meta animate__animated animate__fadeIn animate-delay-3">
            <div class="meta-item">
                <i class="fas fa-calendar-alt meta-icon"></i>
                <div>
                    <div class="meta-value">
                        <?= $start_date->format('F j, Y') ?> - <?= $end_date->format('F j, Y') ?>
                    </div>
                    <div class="text-xs text-gray-500">Workshop Dates</div>
                </div>
            </div>
            <div class="meta-item">
                <i class="fas fa-clock meta-icon"></i>
                <div>
                    <div class="meta-value"><?= $duration_hours ?> hours</div>
                    <div class="text-xs text-gray-500">Duration</div>
                </div>
            </div>
            <div class="meta-item">
                <i class="fas fa-users meta-icon"></i>
                <div>
                    <div class="meta-value"><?= $registration_count ?> registered</div>
                    <div class="text-xs text-gray-500">Participants</div>
                </div>
            </div>
            <div class="meta-item">
                <i class="fas fa-map-marker-alt meta-icon"></i>
                <div>
                    <div class="meta-value"><?= htmlspecialchars($workshop['location'] ?: 'Online') ?></div>
                    <div class="text-xs text-gray-500">Location</div>
                </div>
            </div>
        </div>
        
        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Column (Workshop Details) -->
            <div class="lg:col-span-2 space-y-6">
                <!-- About Workshop -->
                <div class="card animate__animated animate__fadeIn animate-delay-4">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-info-circle"></i>
                            About This Workshop
                        </h3>
                    </div>
                    <div>
                        <p class="text-gray-700 mb-4"><?= nl2br(htmlspecialchars($workshop['description'])) ?></p>
                        
                        <?php if ($workshop['description']): ?>
                            <h4 class="font-semibold mb-2">Learning Outcomes</h4>
                            <ul class="list-disc pl-5 mb-4 text-gray-700 space-y-1">
                                <?php foreach (explode("\n", $workshop['description']) as $outcome): ?>
                                    <?php if (trim($outcome)): ?>
                                        <li><?= htmlspecialchars(trim($outcome)) ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if ($workshop['requirements']): ?>
                            <h4 class="font-semibold mb-2">Prerequisites</h4>
                            <p class="text-gray-700"><?= nl2br(htmlspecialchars($workshop['requirements'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Stats and Charts -->
                <?php if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor'): ?>
                    <div class="card animate__animated animate__fadeIn animate-delay-5">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-chart-bar"></i>
                                Workshop Statistics
                            </h3>
                        </div>
                        <div>
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-value"><?= $registration_count ?></div>
                                    <div class="stat-label">Total Registrations</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value">
                                        <?= $attendance_stats ? $attendance_stats['present'] : 0 ?>
                                    </div>
                                    <div class="stat-label">Participants Attended</div>
                                </div>
                            </div>
                            
                            <?php if ($attendance_stats): ?>
                                <div class="chart-container">
                                    <canvas id="attendanceChart"></canvas>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Workshop Schedule -->
                <div class="card animate__animated animate__fadeIn animate-delay-5">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-calendar-day"></i>
                            Workshop Schedule
                        </h3>
                    </div>
                    <div>
                        <?php if (count($sessions) > 0): ?>
                            <?php foreach ($sessions as $session): ?>
                                <div class="schedule-item">
                                    <div class="schedule-time">
                                        <?= date('h:i A', strtotime($session['start_time'])) ?> - 
                                        <?= date('h:i A', strtotime($session['end_time'])) ?>
                                    </div>
                                    <div class="schedule-details">
                                        <h4 class="schedule-title"><?= htmlspecialchars($session['session_title']) ?></h4>
                                        <p class="schedule-desc"><?= htmlspecialchars($session['session_description']) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-gray-500">
                                No schedule available yet
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Workshop Materials -->
                <?php if (count($materials) > 0): ?>
                <div class="card animate__animated animate__fadeIn animate-delay-6">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-file-download"></i>
                            Workshop Materials
                        </h3>
                    </div>
                    <div>
                        <?php foreach ($materials as $material): ?>
                            <div class="material-item">
                                <?php 
                                    $icon = 'fa-file-alt';
                                    $ext = pathinfo($material['file_path'], PATHINFO_EXTENSION);
                                    if (in_array($ext, ['pdf'])) $icon = 'fa-file-pdf';
                                    elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word';
                                    elseif (in_array($ext, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
                                    elseif (in_array($ext, ['ppt', 'pptx'])) $icon = 'fa-file-powerpoint';
                                    elseif (in_array($ext, ['zip', 'rar', '7z'])) $icon = 'fa-file-archive';
                                    elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'fa-file-image';
                                ?>
                                <i class="fas <?= $icon ?> material-icon"></i>
                                <div class="material-details">
                                    <div class="material-name"><?= htmlspecialchars($material['title']) ?></div>
                                    <div class="material-meta">
                                        Uploaded on <?= date('M j, Y', strtotime($material['uploaded_at'])) ?> • 
                                        <?= strtoupper($ext) ?> • 
                                        <?= round(filesize($_SERVER['DOCUMENT_ROOT'] . '/' . $material['file_path']) / 1024) ?> KB
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($material['file_path']) ?>" class="btn btn-primary btn-sm" download>
                                    <i class="fas fa-download"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Certificates Section -->
                <?php if (($workshop['certificate_available'] && $is_registered) || ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor')): ?>
                <div class="card animate__animated animate__fadeIn animate-delay-7">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-certificate"></i>
                            Certificates
                        </h3>
                    </div>
                    <div>
                        <?php if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor'): ?>
                            <!-- Certificate Template Management -->
                            <div class="certificate-template-card">
                                <h4 class="font-semibold mb-2">Certificate Template</h4>
                                <?php if ($certificate_template): ?>
                                    <p class="text-sm text-gray-600 mb-2">Template uploaded on <?= date('M j, Y', strtotime($certificate_template['created_at'])) ?></p>
                                    <div class="template-actions">
                                        <a href="<?= htmlspecialchars($certificate_template['template_path']) ?>" 
                                           class="btn btn-outline btn-sm" download>
                                            <i class="fas fa-download"></i> Download Template
                                        </a>
                                        <button id="uploadTemplateBtn" class="btn btn-primary btn-sm">
                                            <i class="fas fa-upload"></i> Update Template
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <p class="text-sm text-gray-600 mb-2">No template uploaded yet</p>
                                    <button id="uploadTemplateBtn" class="btn btn-primary btn-sm">
                                        <i class="fas fa-upload"></i> Upload Template
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Certificate Management for Admin/Mentor -->
                            <div class="mb-4">
                                <button id="bulkGenerateBtn" class="btn btn-primary btn-sm mr-2" <?= $certificate_template ? '' : 'disabled' ?>>
                                    <i class="fas fa-cogs"></i> Generate Certificates
                                </button>
                                <button id="uploadCertificatesBtn" class="btn btn-outline btn-sm">
                                    <i class="fas fa-upload"></i> Upload Certificates
                                </button>
                            </div>
                            
                            <h4 class="font-semibold mb-3">Participant Certificates</h4>
                        <?php endif; ?>
                        
                        <?php if (count($registered_students) > 0): ?>
                            <?php foreach ($registered_students as $student): ?>
                                <div class="certificate-item">
                                    <img src="<?= $student['profile_picture'] ? htmlspecialchars($student['profile_picture']) : '../images/default-student.jpg' ?>" 
                                        alt="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>"                                         class="certificate-avatar">
                                    <div class="certificate-details">
                                        <div class="certificate-student">
                                            <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                        </div>
                                        <div class="certificate-meta">
                                            Registered on <?= date('M j, Y', strtotime($student['registration_date'])) ?>
                                        </div>
                                    </div>
                                    <div class="certificate-actions">
                                        <?php if ($student['certificate_path']): ?>
                                            <span class="certificate-badge badge-issued">
                                                <i class="fas fa-check-circle"></i> Issued
                                            </span>
                                            <a href="<?= htmlspecialchars($student['certificate_path']) ?>" 
                                               class="btn btn-primary btn-sm" download>
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="certificate-badge badge-pending">
                                                <i class="fas fa-clock"></i> Pending
                                            </span>
                                            <?php if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor'): ?>
                                                <button class="btn btn-outline btn-sm upload-single-certificate" 
                                                        data-student-id="<?= htmlspecialchars($student['student_id']) ?>">
                                                    <i class="fas fa-upload"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-gray-500">
                                No registered participants yet
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column (Trainer & Actions) -->
            <div class="space-y-6">
                <!-- Trainer Card -->
                <?php if ($trainer): ?>
                <div class="card animate__animated animate__fadeIn animate-delay-5">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chalkboard-teacher"></i>
                            Workshop Trainer
                        </h3>
                    </div>
                    <div class="trainer-card">
                        <img src="<?= $trainer['profile_picture'] ? htmlspecialchars($trainer['profile_picture']) : '../images/default-trainer.jpg' ?>" 
                             alt="<?= htmlspecialchars($trainer['name']) ?>" 
                             class="trainer-avatar">
                        <div class="trainer-details">
                            <h3 class="trainer-name"><?= htmlspecialchars($trainer['name']) ?></h3>
                            <div class="trainer-title"><?= htmlspecialchars($trainer['specialization']) ?></div>
                            <p class="trainer-bio"><?= htmlspecialchars($trainer['bio']) ?></p>
                            <!-- <div class="trainer-social">
                                <?php if ($trainer['linkedin']): ?>
                                    <a href="<?= htmlspecialchars($trainer['linkedin']) ?>" class="social-link" target="_blank">
                                        <i class="fab fa-linkedin"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($trainer['twitter']): ?>
                                    <a href="<?= htmlspecialchars($trainer['twitter']) ?>" class="social-link" target="_blank">
                                        <i class="fab fa-twitter"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($trainer['website']): ?>
                                    <a href="<?= htmlspecialchars($trainer['website']) ?>" class="social-link" target="_blank">
                                        <i class="fas fa-globe"></i>
                                    </a>
                                <?php endif; ?>
                            </div> -->
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Registration Card -->
                <div class="card animate__animated animate__fadeIn animate-delay-6">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user-plus"></i>
                            Registration
                        </h3>
                    </div>
                    <div class="p-4">
                        <?php if ($workshop['status'] === 'completed'): ?>
                            <div class="text-center py-4 text-gray-500">
                                This workshop has already ended
                            </div>
                        <?php elseif ($is_registered): ?>
                            <div class="text-center mb-4">
                                <div class="text-green-600 mb-2">
                                    <i class="fas fa-check-circle text-4xl"></i>
                                </div>
                                <p class="font-semibold">You are registered for this workshop</p>
                                <p class="text-sm text-gray-500 mt-1">Registered on <?= date('M j, Y', strtotime($registered_students[0]['registration_date'])) ?></p>
                            </div>
                            <button id="cancelRegistrationBtn" class="btn btn-danger w-full">
                                <i class="fas fa-times"></i> Cancel Registration
                            </button>
                        <?php else: ?>
                            <?php if ($workshop['status'] === 'upcoming'): ?>
                                <p class="text-gray-700 mb-4">Register now to secure your spot in this workshop.</p>
                                <button id="registerBtn" class="btn btn-primary w-full">
                                    <i class="fas fa-user-plus"></i> Register Now
                                </button>
                            <?php else: ?>
                                <div class="text-center py-4 text-gray-500">
                                    Registration is closed for this workshop
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Workshop Stats -->
                <div class="card animate__animated animate__fadeIn animate-delay-7">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-pie"></i>
                            Quick Stats
                        </h3>
                    </div>
                    <div class="p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-gray-600">Capacity</span>
                            <span class="font-semibold"><?= $workshop['max_participants'] ? htmlspecialchars($workshop['max_participants']) : 'Unlimited' ?></span>
                        </div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-gray-600">Registered:</span>
                            <span class="font-semibold"><?= $registration_count ?></span>
                        </div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-gray-600">Available:</span>
                            <span class="font-semibold">
                                <?= $workshop['max_participants'] ? htmlspecialchars($workshop['max_participants'] - $registration_count) : 'Unlimited' ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">Difficulty:</span>
                            <span class="font-semibold">
                                <?php 
                                    $difficulty = $workshop['difficulty_level'];
                                    $color = 'text-blue-600';
                                    if ($difficulty === 'beginner') $color = 'text-green-600';
                                    elseif ($difficulty === 'intermediate') $color = 'text-yellow-600';
                                    elseif ($difficulty === 'advanced') $color = 'text-red-600';
                                ?>
                                <span class="<?= $color ?>"><?= ucfirst($difficulty) ?></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Floating Action Buttons -->
    <div class="floating-actions">
        <?php if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'mentor'): ?>
            <button class="floating-btn floating-btn-primary" title="Edit Workshop" onclick="window.location.href='edit_workshop.php?id=<?= $workshop_id ?>'">
                <i class="fas fa-edit"></i>
            </button>
        <?php endif; ?>
        
        <?php if ($is_registered && $workshop['status'] !== 'completed'): ?>
            <button id="floatingCancelBtn" class="floating-btn floating-btn-danger" title="Cancel Registration">
                <i class="fas fa-times"></i>
            </button>
        <?php elseif (!$is_registered && $workshop['status'] === 'upcoming'): ?>
            <button id="floatingRegisterBtn" class="floating-btn floating-btn-primary" title="Register Now">
                <i class="fas fa-user-plus"></i>
            </button>
        <?php endif; ?>
    </div>
    
    <!-- Registration Modal -->
    <div id="registrationModal" class="registration-modal">
        <div class="modal-content animate__animated animate__fadeInDown">
            <div class="modal-header">
                <h3 class="text-xl font-bold">Register for Workshop</h3>
                <span class="close-modal">&times;</span>
            </div>
            <form id="registrationForm">
                <div class="modal-body">
                    <input type="hidden" name="workshop_id" value="<?= htmlspecialchars($workshop_id) ?>">
                    <input type="hidden" name="action" value="register">
                    
                    <p class="mb-4">You are about to register for <strong><?= htmlspecialchars($workshop['title']) ?></strong>.</p>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Registration Notes (Optional)</label>
                        <textarea name="notes" class="w-full px-3 py-2 border border-gray-300 rounded-md" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline mr-2 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Registration</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Cancellation Modal -->
    <div id="cancellationModal" class="registration-modal">
        <div class="modal-content animate__animated animate__fadeInDown">
            <div class="modal-header">
                <h3 class="text-xl font-bold">Cancel Workshop Registration</h3>
                <span class="close-modal">&times;</span>
            </div>
            <form id="cancellationForm">
                <div class="modal-body">
                    <input type="hidden" name="workshop_id" value="<?= htmlspecialchars($workshop_id) ?>">
                    <input type="hidden" name="action" value="cancel">
                    
                    <p class="mb-4">You are about to cancel your registration for <strong><?= htmlspecialchars($workshop['title']) ?></strong>.</p>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reason for Cancellation (Optional)</label>
                        <textarea name="reason" class="w-full px-3 py-2 border border-gray-300 rounded-md" rows="3"></textarea>
                    </div>
                    
                    <div class="p-4 bg-yellow-50 rounded-lg border border-yellow-100">
                        <h4 class="font-bold text-yellow-800 mb-2">Note</h4>
                        <p class="text-sm text-yellow-600">
                            Canceling your registration may free up your spot for other participants.
                            You can re-register if spots are still available.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline mr-2 close-modal">Go Back</button>
                    <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Certificate Template Upload Modal -->
    <div id="templateModal" class="registration-modal">
        <div class="modal-content animate__animated animate__fadeInDown">
            <div class="modal-header">
                <h3 class="text-xl font-bold">
                    <?= $certificate_template ? 'Update' : 'Upload' ?> Certificate Template
                </h3>
                <span class="close-modal">&times;</span>
            </div>
            <form id="templateForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="workshop_id" value="<?= htmlspecialchars($workshop_id) ?>">
                    <input type="hidden" name="action" value="upload_template">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Certificate Template (PDF or Image)
                        </label>
                        <input type="file" name="template_file" id="templateFile" 
                               class="block w-full text-sm text-gray-500
                                      file:mr-4 file:py-2 file:px-4
                                      file:rounded-md file:border-0
                                      file:text-sm file:font-semibold
                                      file:bg-blue-50 file:text-blue-700
                                      hover:file:bg-blue-100" required>
                        <p class="mt-1 text-xs text-gray-500">
                            Upload a template file (PDF, JPG, PNG) for certificates
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline mr-2 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Template</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Certificate Upload Modal -->
    <div id="certificateModal" class="registration-modal">
        <div class="modal-content animate__animated animate__fadeInDown">
            <div class="modal-header">
                <h3 class="text-xl font-bold">Upload Certificate</h3>
                <span class="close-modal">&times;</span>
            </div>
            <form id="certificateForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="workshop_id" value="<?= htmlspecialchars($workshop_id) ?>">
                    <input type="hidden" name="action" value="upload_certificate">
                    <input type="hidden" name="student_id" id="certStudentId" value="">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Certificate File (PDF or Image)
                        </label>
                        <input type="file" name="certificate_file" id="certificateFile" 
                               class="block w-full text-sm text-gray-500
                                      file:mr-4 file:py-2 file:px-4
                                      file:rounded-md file:border-0
                                      file:text-sm file:font-semibold
                                      file:bg-blue-50 file:text-blue-700
                                      hover:file:bg-blue-100" required>
                        <p class="mt-1 text-xs text-gray-500">
                            Upload the certificate file (PDF, JPG, PNG)
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline mr-2 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Certificate</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Bulk Certificate Generation Modal -->
    <div id="bulkGenerateModal" class="registration-modal">
        <div class="modal-content animate__animated animate__fadeInDown">
            <div class="modal-header">
                <h3 class="text-xl font-bold">Generate Certificates</h3>
                <span class="close-modal">&times;</span>
            </div>
            <form id="bulkGenerateForm">
                <div class="modal-body">
                    <input type="hidden" name="workshop_id" value="<?= htmlspecialchars($workshop_id) ?>">
                    <input type="hidden" name="action" value="generate_certificates">
                    
                    <p class="mb-4">This will generate certificates for all registered participants who don't have one yet.</p>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Certificate Date
                        </label>
                        <input type="date" name="certificate_date" class="w-full px-3 py-2 border border-gray-300 rounded-md" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="p-4 bg-yellow-50 rounded-lg border border-yellow-100">
                        <h4 class="font-bold text-yellow-800 mb-2">Note</h4>
                        <p class="text-sm text-yellow-600">
                            Certificate generation might take some time depending on the number of participants.
                            Please do not close this window until the process is complete.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline mr-2 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate Certificates</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Alert Notification -->
    <div id="alert" class="alert hidden">
        <i id="alertIcon" class="alert-icon"></i>
        <div>
            <h4 id="alertTitle" class="font-bold"></h4>
            <p id="alertMessage" class="text-sm"></p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    <script>
        // Initialize modals
        const modals = document.querySelectorAll('.registration-modal');
        const modalTriggers = document.querySelectorAll('[data-modal]');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        // Show modal
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        // Hide modal
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Close modal when clicking outside
        modals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    hideModal(modal.id);
                }
            });
        });
        
        // Close modal with close button
        closeButtons.forEach(button => {
            button.addEventListener('click', () => {
                const modal = button.closest('.registration-modal');
                hideModal(modal.id);
            });
        });
        
        // Show alert
        function showAlert(type, message, title = '') {
            const alert = document.getElementById('alert');
            const alertIcon = document.getElementById('alertIcon');
            const alertTitle = document.getElementById('alertTitle');
            const alertMessage = document.getElementById('alertMessage');
            
            alert.className = `alert alert-${type}`;
            alertIcon.className = `alert-icon fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}`;
            alertTitle.textContent = title || (type === 'success' ? 'Success!' : 'Error!');
            alertMessage.textContent = message;
            
            alert.classList.remove('hidden');
            alert.classList.add('show');
            
            setTimeout(() => {
                alert.classList.remove('show');
                alert.classList.add('hidden');
            }, 5000);
        }
        
        // Registration form
        document.getElementById('registerBtn')?.addEventListener('click', () => {
            showModal('registrationModal');
        });
        
        document.getElementById('floatingRegisterBtn')?.addEventListener('click', () => {
            showModal('registrationModal');
        });
        
        document.getElementById('registrationForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('handle_registration.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    hideModal('registrationModal');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('error', data.message || 'Failed to register');
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred. Please try again.');
                console.error('Error:', error);
            });
        });
        
        // Cancellation form
        document.getElementById('cancelRegistrationBtn')?.addEventListener('click', () => {
            showModal('cancellationModal');
        });
        
        document.getElementById('floatingCancelBtn')?.addEventListener('click', () => {
            showModal('cancellationModal');
        });
        
        document.getElementById('cancellationForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('handle_registration.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    hideModal('cancellationModal');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('error', data.message || 'Failed to cancel registration');
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred. Please try again.');
                console.error('Error:', error);
            });
        });
        
        // Certificate Template Modal
        const uploadTemplateBtn = document.getElementById('uploadTemplateBtn');
        const templateModal = document.getElementById('templateModal');
        
        if (uploadTemplateBtn) {
            uploadTemplateBtn.addEventListener('click', () => {
                showModal('templateModal');
            });
        }
        
        // Certificate Upload Modal
        const uploadCertificatesBtn = document.getElementById('uploadCertificatesBtn');
        const certificateModal = document.getElementById('certificateModal');
        const uploadSingleCertificateBtns = document.querySelectorAll('.upload-single-certificate');
        
        if (uploadCertificatesBtn) {
            uploadCertificatesBtn.addEventListener('click', () => {
                document.getElementById('certStudentId').value = '';
                showModal('certificateModal');
            });
        }
        
        if (uploadSingleCertificateBtns) {
            uploadSingleCertificateBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('certStudentId').value = btn.dataset.studentId;
                    showModal('certificateModal');
                });
            });
        }
        
        // Bulk Generate Modal
        const bulkGenerateBtn = document.getElementById('bulkGenerateBtn');
        const bulkGenerateModal = document.getElementById('bulkGenerateModal');
        
        if (bulkGenerateBtn) {
            bulkGenerateBtn.addEventListener('click', () => {
                showModal('bulkGenerateModal');
            });
        }
        
        // Handle template form submission
        document.getElementById('templateForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('handle_certificates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    hideModal('templateModal');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('error', data.message || 'Failed to upload template');
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred. Please try again.');
                console.error('Error:', error);
            });
        });
        
        // Handle certificate form submission
        document.getElementById('certificateForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('handle_certificates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    hideModal('certificateModal');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('error', data.message || 'Failed to upload certificate');
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred. Please try again.');
                console.error('Error:', error);
            });
        });
        
        // Handle bulk generate form submission
        document.getElementById('bulkGenerateForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('handle_certificates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    hideModal('bulkGenerateModal');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('error', data.message || 'Failed to generate certificates');
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred. Please try again.');
                console.error('Error:', error);
            });
        });
        
        // Initialize attendance chart
        <?php if ($attendance_stats): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('attendanceChart').getContext('2d');
            const attendanceChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent', 'Not Recorded'],
                    datasets: [{
                        data: [
                            <?= $attendance_stats['present'] ?>,
                            <?= $attendance_stats['absent'] ?>,
                            <?= $attendance_stats['not_recorded'] ?>
                        ],
                        backgroundColor: [
                            '#10B981',
                            '#EF4444',
                            '#9CA3AF'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>