<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;

if (!$student_id) {
    header("Location: ../batch/batch_view.php");
    exit();
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get student details with profile picture
    $stmt = $conn->prepare("
        SELECT * 
        FROM students 
        WHERE student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        header("Location: ../batch/batch_view.php");
        exit();
    }    
    
    // Get batch details
    $stmt = $conn->prepare("SELECT * FROM batches WHERE batch_id = ?");
    $stmt->execute([$student['batch_name']]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get attendance records
    $stmt = $conn->prepare("SELECT * FROM attendance WHERE student_name = ? AND batch_id = ? ORDER BY date DESC");
    $stmt->execute([$student['first_name'] . ' ' . $student['last_name'], $student['batch_name']]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate attendance stats
    $total_classes = count($attendance);
    $present_count = 0;
    $absent_count = 0;
    $late_count = 0;
    
    foreach ($attendance as $record) {
        if ($record['status'] === 'Present') $present_count++;
        elseif ($record['status'] === 'Absent') $absent_count++;
        elseif ($record['status'] === 'Late') $late_count++;
    }
    
    // Get exam results
    $stmt = $conn->prepare("SELECT pe.exam_id, pe.exam_date, pe.mode, es.score, es.is_malpractice 
                          FROM proctored_exams pe
                          JOIN exam_students es ON pe.exam_id = es.exam_id
                          WHERE es.student_name = ? AND pe.batch_id = ?
                          ORDER BY pe.exam_date DESC");
    $stmt->execute([$student['first_name'] . ' ' . $student['last_name'], $student['batch_name']]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get transfer history
    $stmt = $conn->prepare("
        SELECT sbh.*, 
               from_batch.batch_name as from_batch_name,
               to_batch.batch_name as to_batch_name,
               users.name as transferred_by_name
        FROM student_batch_history sbh
        LEFT JOIN batches from_batch ON sbh.from_batch_id = from_batch.batch_id
        LEFT JOIN batches to_batch ON sbh.to_batch_id = to_batch.batch_id
        LEFT JOIN users ON sbh.transferred_by = users.id
        WHERE sbh.student_id = ?
        ORDER BY sbh.transfer_date DESC
    ");
    $stmt->execute([$student_id]);
    $transfer_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?> | ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4cc9f0;
            --light-bg: #f8f9fa;
            --dark-text: #212529;
            --light-text: #6c757d;
            --success-color: #4bb543;
            --danger-color: #f94144;
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .profile-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            object-fit: cover;
        }
        
        .contact-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .contact-header {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            color: white;
            padding: 24px;
        }
        
        .contact-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .contact-details {
            padding: 24px;
        }
        
        .detail-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .detail-icon {
            width: 24px;
            margin-right: 12px;
            color: #4361ee;
            text-align: center;
        }
        
        .detail-content {
            flex: 1;
        }
        
        .detail-label {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-weight: 500;
            color: #212529;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-active {
            background-color: rgba(75, 181, 67, 0.1);
            color: var(--success-color);
        }
        
        .status-dropped {
            background-color: rgba(249, 65, 68, 0.1);
            color: var(--danger-color);
        }
        
        .status-onhold {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .stats-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stats-label {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .attendance-present {
            color: var(--success-color);
        }
        
        .attendance-absent {
            color: var(--danger-color);
        }
        
        .attendance-late {
            color: #ffc107;
        }
        
        .tab-content {
            background: white;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        
        .nav-tabs {
            border-bottom: 1px solid #e9ecef;
            padding: 0 24px;
            background: white;
            border-radius: 12px 12px 0 0;
        }
        
        .nav-tabs .nav-link {
            border: none;
            padding: 16px 20px;
            font-weight: 500;
            color: #6c757d;
            position: relative;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background: transparent;
        }
        
        .nav-tabs .nav-link.active:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background-color: var(--primary-color);
            border-radius: 3px 3px 0 0;
        }
        
        .nav-tabs .nav-link:hover {
            border: none;
            color: var(--primary-color);
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .progress-bar {
            background-color: var(--primary-color);
        }
        
        .back-btn {
            background: white;
            border: 1px solid #e9ecef;
            color: #6c757d;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: #f8f9fa;
            color: #495057;
        }
        
        .table th {
            font-weight: 600;
            color: #495057;
            border-top: none;
            font-size: 0.875rem;
            padding: 12px 16px;
        }
        
        .table td {
            padding: 12px 16px;
            vertical-align: middle;
        }
        
        .badge {
            font-size: 0.75rem;
            padding: 4px 8px;
        }
    </style>
</head>

<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-user-graduate text-blue-500"></i>
                <span>Student Profile</span>
            </h1>
            <div class="flex items-center space-x-4">
                <a href="batch_view.php?batch_id=<?= $student['batch_name'] ?>" class="back-btn">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Batch
                </a>
            </div>
        </header>
        
        <div class="p-4 md:p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column - Contact Card -->
                <div class="lg:col-span-1">
                    <div class="contact-card">
                        <div class="contact-header">
                            <div class="flex flex-col items-center text-center">
                                <!-- Profile Picture -->
                                <div class="mb-4">
                                    <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                        <img src="<?= htmlspecialchars($student['profile_picture']) ?>" 
                                             alt="Profile Picture" 
                                             class="profile-picture">
                                    <?php else: ?>
                                        <div class="profile-picture bg-white flex items-center justify-center">
                                            <i class="fas fa-user text-4xl text-blue-500"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Name and ID -->
                                <h2 class="text-2xl font-bold mb-1"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h2>
                                <p class="text-blue-100 mb-4"><?= htmlspecialchars($student['student_id']) ?></p>
                                
                                <!-- Status -->
                                <div class="mb-4">
                                    <span class="status-badge <?= 
                                        $student['current_status'] === 'active' ? 'status-active' : 
                                        ($student['current_status'] === 'dropped' ? 'status-dropped' : 'status-onhold')
                                    ?>">
                                        <?= ucfirst($student['current_status']) ?>
                                    </span>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="contact-actions">
                                    <a href="mailto:<?= htmlspecialchars($student['email']) ?>" class="action-btn" title="Email">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                    <a href="tel:<?= htmlspecialchars($student['phone_number']) ?>" class="action-btn" title="Call">
                                        <i class="fas fa-phone"></i>
                                    </a>
                                    <a href="../student/edit_student.php?id=<?= $student['student_id'] ?>" class="action-btn" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="../student/drop_student.php" method="POST" class="inline">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($student['student_id']) ?>">
                                        <button type="submit" class="action-btn" title="Drop Student" 
                                                onclick="return confirm('Are you sure you want to drop this student?')">
                                            <i class="fas fa-user-minus"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="contact-details">
                            <!-- Contact Information -->
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Email</div>
                                    <div class="detail-value"><?= htmlspecialchars($student['email'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-phone"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Phone</div>
                                    <div class="detail-value"><?= htmlspecialchars($student['phone_number'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-birthday-cake"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Date of Birth</div>
                                    <div class="detail-value"><?= $student['date_of_birth'] ? date('M j, Y', strtotime($student['date_of_birth'])) : 'N/A' ?></div>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-user-friends"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Father's Name</div>
                                    <div class="detail-value"><?= htmlspecialchars($student['father_name'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            
                            <!-- Academic Information -->
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Batch</div>
                                    <div class="detail-value"><?= htmlspecialchars($batch['batch_id'] ?? 'N/A') ?> - <?= htmlspecialchars($batch['batch_name'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Enrollment Date</div>
                                    <div class="detail-value"><?= date('M j, Y', strtotime($student['enrollment_date'])) ?></div>
                                </div>
                            </div>
                            
                            <?php if ($student['current_status'] === 'dropped'): ?>
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-calendar-times"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Dropout Date</div>
                                        <div class="detail-value"><?= date('M j, Y', strtotime($student['dropout_date'])) ?></div>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <div class="detail-icon">
                                        <i class="fas fa-comment"></i>
                                    </div>
                                    <div class="detail-content">
                                        <div class="detail-label">Dropout Reason</div>
                                        <div class="detail-value"><?= htmlspecialchars($student['dropout_reason'] ?? 'N/A') ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="grid grid-cols-2 gap-4 mt-6">
                        <div class="stats-card text-center">
                            <div class="stats-value attendance-present"><?= $total_classes ?></div>
                            <div class="stats-label">Total Classes</div>
                        </div>
                        <div class="stats-card text-center">
                            <div class="stats-value attendance-present"><?= $present_count ?></div>
                            <div class="stats-label">Present</div>
                        </div>
                        <div class="stats-card text-center">
                            <div class="stats-value attendance-absent"><?= $absent_count ?></div>
                            <div class="stats-label">Absent</div>
                        </div>
                        <div class="stats-card text-center">
                            <div class="stats-value"><?= round(($present_count / max(1, $total_classes)) * 100) ?>%</div>
                            <div class="stats-label">Attendance</div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column - Tabs Content -->
                <div class="lg:col-span-2">
                    <div class="tab-content">
                        <ul class="nav nav-tabs" id="studentTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance-tab-pane" type="button" role="tab">
                                    <i class="fas fa-calendar-check mr-2"></i> Attendance
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="exams-tab" data-bs-toggle="tab" data-bs-target="#exams-tab-pane" type="button" role="tab">
                                    <i class="fas fa-clipboard-list mr-2"></i> Exams
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="transfers-tab" data-bs-toggle="tab" data-bs-target="#transfers-tab-pane" type="button" role="tab">
                                    <i class="fas fa-exchange-alt mr-2"></i> Transfer History
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content p-4" id="studentTabsContent">
                            <!-- Attendance Tab -->
                            <div class="tab-pane fade show active" id="attendance-tab-pane" role="tabpanel">
                                <div class="mb-6">
                                    <h4 class="font-bold text-gray-800 mb-4">Attendance Summary</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                        <div class="stats-card">
                                            <div class="flex justify-between items-center mb-2">
                                                <span class="font-bold attendance-present">Present</span>
                                                <span class="text-lg font-bold attendance-present"><?= $present_count ?></span>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $total_classes ? ($present_count/$total_classes)*100 : 0 ?>%" aria-valuenow="<?= $present_count ?>" aria-valuemin="0" aria-valuemax="<?= $total_classes ?>"></div>
                                            </div>
                                        </div>
                                        <div class="stats-card">
                                            <div class="flex justify-between items-center mb-2">
                                                <span class="font-bold attendance-absent">Absent</span>
                                                <span class="text-lg font-bold attendance-absent"><?= $absent_count ?></span>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $total_classes ? ($absent_count/$total_classes)*100 : 0 ?>%" aria-valuenow="<?= $absent_count ?>" aria-valuemin="0" aria-valuemax="<?= $total_classes ?>"></div>
                                            </div>
                                        </div>
                                        <div class="stats-card">
                                            <div class="flex justify-between items-center mb-2">
                                                <span class="font-bold attendance-late">Late</span>
                                                <span class="text-lg font-bold attendance-late"><?= $late_count ?></span>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $total_classes ? ($late_count/$total_classes)*100 : 0 ?>%" aria-valuenow="<?= $late_count ?>" aria-valuemin="0" aria-valuemax="<?= $total_classes ?>"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <h4 class="font-bold text-gray-800 mb-4">Attendance Records</h4>
                                <?php if (count($attendance) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Remarks</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($attendance as $record): ?>
                                                    <tr>
                                                        <td><?= date('M j, Y', strtotime($record['date'])) ?></td>
                                                        <td>
                                                            <span class="badge 
                                                                <?= $record['status'] === 'Present' ? 'bg-success' : 
                                                                   ($record['status'] === 'Absent' ? 'bg-danger' : 'bg-warning') ?>">
                                                                <?= $record['status'] ?>
                                                            </span>
                                                        </td>
                                                        <td><?= htmlspecialchars($record['remarks'] ?? '') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-8">
                                        <i class="fas fa-calendar-times text-gray-400 fa-4x mb-3"></i>
                                        <h5 class="text-gray-600">No attendance records found</h5>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Exams Tab -->
                            <div class="tab-pane fade" id="exams-tab-pane" role="tabpanel">
                                <?php if (count($exams) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th>Exam ID</th>
                                                    <th>Date</th>
                                                    <th>Mode</th>
                                                    <th>Score</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($exams as $exam): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($exam['exam_id']) ?></td>
                                                        <td><?= date('M j, Y', strtotime($exam['exam_date'])) ?></td>
                                                        <td><?= htmlspecialchars($exam['mode']) ?></td>
                                                        <td>
                                                            <?php if ($exam['score'] !== null): ?>
                                                                <span class="badge <?= $exam['score'] >= 70 ? 'bg-success' : ($exam['score'] >= 50 ? 'bg-warning' : 'bg-danger') ?>">
                                                                    <?= htmlspecialchars($exam['score']) ?>
                                                                </span>
                                                            <?php else: ?>
                                                                N/A
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($exam['is_malpractice']): ?>
                                                                <span class="badge bg-danger">Malpractice</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">Clean</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-8">
                                        <i class="fas fa-file-alt text-gray-400 fa-4x mb-3"></i>
                                        <h5 class="text-gray-600">No exam records found</h5>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Transfer History Tab -->
                            <div class="tab-pane fade" id="transfers-tab-pane" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="font-bold text-gray-800">Transfer History</h4>
                                    <?php if ($student['current_status'] === 'active'): ?>
                                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#transferStudentModal">
                                        <i class="fas fa-exchange-alt me-1"></i> Transfer Student
                                    </button>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (count($transfer_history) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>From Batch</th>
                                                    <th>To Batch</th>
                                                    <th>Transferred By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($transfer_history as $transfer): ?>
                                                    <tr>
                                                        <td><?= date('M j, Y H:i', strtotime($transfer['transfer_date'])) ?></td>
                                                        <td><?= htmlspecialchars($transfer['from_batch_name'] ?? $transfer['from_batch_id']) ?></td>
                                                        <td><?= htmlspecialchars($transfer['to_batch_name'] ?? $transfer['to_batch_id']) ?></td>
                                                        <td><?= htmlspecialchars($transfer['transferred_by_name'] ?? 'System') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-8">
                                        <i class="fas fa-exchange-alt text-gray-400 fa-4x mb-3"></i>
                                        <h5 class="text-gray-600">No transfer history found</h5>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Transfer Student Modal -->
    <div class="modal fade" id="transferStudentModal" tabindex="-1" aria-labelledby="transferStudentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="transferStudentModalLabel">Transfer Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="../student/transfer_student.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                        <div class="mb-3">
                            <label for="from_batch" class="form-label">Current Batch</label>
                            <input type="text" class="form-control" id="from_batch" value="<?= htmlspecialchars($batch['batch_id'] ?? 'N/A') ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="to_batch_id" class="form-label">Transfer To Batch</label>
                            <select class="form-select" id="to_batch_id" name="to_batch_id" required>
                                <option value="">Select Batch</option>
                                <?php
                                // Fetch all active batches
                                $stmt = $conn->prepare("SELECT batch_id, batch_name FROM batches WHERE batch_id != ? ORDER BY batch_name");
                                $stmt->execute([$student['batch_name']]);
                                $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($batches as $batch_option):
                                ?>
                                    <option value="<?= htmlspecialchars($batch_option['batch_id']) ?>">
                                        <?= htmlspecialchars($batch_option['batch_id']) ?> - <?= htmlspecialchars($batch_option['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Transfer Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        
        // Initialize tooltips
        $(function () {
            $('[data-bs-toggle="tooltip"]').tooltip()
        })
    </script>
</body>
</html>