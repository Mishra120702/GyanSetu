<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../logout_a.php");
    exit;
}
$student_id = isset($_GET['id']) ? $_GET['id'] : null;
$from_batch = isset($_GET['from_batch']) ? $_GET['from_batch'] : null;

if (!$student_id) {
    header("Location: ../student_list.php");
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
        header("Location: ../batch_list.php");
        exit();
    }    
    
    // Get batch details
    $stmt = $conn->prepare("SELECT * FROM batches WHERE batch_id = ?");
    $stmt->execute([$student['batch_name']]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get attendance records with absent reasons
    $stmt = $conn->prepare("
    SELECT *, 
           CASE 
               WHEN status = 'Absent' AND (remarks IS NULL OR remarks = '') THEN 'No reason provided'
               ELSE remarks
           END AS display_remarks
    FROM attendance 
    WHERE student_id = ? AND batch_id = ? 
    ORDER BY date DESC
");
$stmt->execute([$student_id, $student['batch_name']]);
    $stmt->execute([$student['first_name'] . ' ' . $student['last_name'], $student['batch_name']]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate attendance stats
    $total_classes = count($attendance);
    $present_count = 0;
    $absent_count = 0;
    $late_count = 0;
    $absent_without_reason = 0;
    
    foreach ($attendance as $record) {
        if ($record['status'] === 'Present') $present_count++;
        elseif ($record['status'] === 'Absent') {
            $absent_count++;
            if (empty($record['remarks'])) $absent_without_reason++;
        }
        elseif ($record['status'] === 'Late') $late_count++;
    }
    
    // Get exam results
    $stmt = $conn->prepare("SELECT pe.exam_id, pe.exam_date, pe.mode, es.score, es.is_malpractice 
                      FROM proctored_exams pe
                      JOIN exam_students es ON pe.exam_id = es.exam_id
                      WHERE es.student_id = ? AND pe.batch_id = ?
                      ORDER BY pe.exam_date DESC");
$stmt->execute([$student_id, $student['batch_name']]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get student documents
    $stmt = $conn->prepare("SELECT * FROM student_documents WHERE student_id = ? ORDER BY document_type");
    $stmt->execute([$student_id]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle document upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
        $document_type = $_POST['document_type'];
        $allowed_types = ['aadhaar', 'pancard', 'tenth_marksheet', 'twelfth_marksheet', 'other'];
        
        if (in_array($document_type, $allowed_types)) {
            $upload_dir = '../uploads/student_documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = $student_id . '_' . $document_type . '_' . time() . '_' . basename($_FILES['document_file']['name']);
            $target_file = $upload_dir . $file_name;
            
            // Check if file already exists for this document type
            $stmt = $conn->prepare("SELECT * FROM student_documents WHERE student_id = ? AND document_type = ?");
            $stmt->execute([$student_id, $document_type]);
            $existing_doc = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_doc) {
                // Delete old file
                if (file_exists($existing_doc['file_path'])) {
                    unlink($existing_doc['file_path']);
                }
                
                // Update record
                $stmt = $conn->prepare("UPDATE student_documents SET file_path = ? WHERE document_id = ?");
                $stmt->execute([$target_file, $existing_doc['document_id']]);
            } else {
                // Insert new record
                $stmt = $conn->prepare("INSERT INTO student_documents (student_id, document_type, file_path) VALUES (?, ?, ?)");
                $stmt->execute([$student_id, $document_type, $target_file]);
            }
            
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $target_file)) {
                $_SESSION['success_message'] = "Document uploaded successfully!";
                header("Location: student_view.php?id=$student_id");
                exit();
            } else {
                $_SESSION['error_message'] = "Sorry, there was an error uploading your file.";
            }
        }
    }
    
    // Handle document deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
        $document_id = $_POST['document_id'];
        
        $stmt = $conn->prepare("SELECT * FROM student_documents WHERE document_id = ? AND student_id = ?");
        $stmt->execute([$document_id, $student_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            if (file_exists($doc['file_path'])) {
                unlink($doc['file_path']);
            }
            
            $stmt = $conn->prepare("DELETE FROM student_documents WHERE document_id = ?");
            $stmt->execute([$document_id]);
            
            $_SESSION['success_message'] = "Document deleted successfully!";
            header("Location: student_view.php?id=$student_id");
            exit();
        }
    }
    
    // Handle attendance status update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_attendance'])) {
        $attendance_id = $_POST['attendance_id'];
        $status = $_POST['status'];
        $remarks = $_POST['remarks'];
        
        $stmt = $conn->prepare("UPDATE attendance SET status = ?, remarks = ? WHERE id = ?");
        $stmt->execute([$status, $remarks, $attendance_id]);
        
        $_SESSION['success_message'] = "Attendance record updated successfully!";
        header("Location: student_view.php?id=$student_id");
        exit();
    }
    
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
        
        .profile-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            object-fit: cover;
        }
        
        .stat-card {
            transition: all 0.3s ease;
            border-radius: 10px;
            overflow: hidden;
            color: black;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .attendance-present {
            background-color: rgba(75, 181, 67, 0.1);
            color: var(--success-color);
        }
        
        .attendance-absent {
            background-color: rgba(249, 65, 68, 0.1);
            color: var(--danger-color);
        }
        
        .attendance-late {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            font-weight: 500;
        }
        
        .nav-tabs .nav-link {
            color: var(--light-text);
            border: none;
            padding: 12px 20px;
            font-weight: 500;
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
        
        .document-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .document-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .document-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
        }
        
        .document-actions a {
            margin-right: 8px;
        }
        
        .document-type-badge {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        
        .reason-popover {
            max-width: 400px;
        }
        
        .absent-reason-badge {
            cursor: pointer;
        }
    </style>
</head>

<body class="bg-gray-50">
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
                <?php if ($from_batch): ?>
                    <a href="../batch/batch_view.php?id=<?= $student['batch_name'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Batch
                    </a>
                <?php else: ?>
                    <a href="students_list.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-left mr-2"></i> Back
                    </a>
                <?php endif; ?>
            </div>
        </header>
        
        <div class="p-4 md:p-6">
            <!-- Profile Card -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-blue-500 to-indigo-600 p-6 text-white">
                    <div class="flex flex-col md:flex-row items-center">
                        <!-- Profile Picture -->
                        <div class="mb-4 md:mb-0 md:mr-6">
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
                        
                        <!-- Profile Info -->
                        <div class="flex-1 text-center md:text-left">
                            <h2 class="text-3xl font-bold mb-1"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h2>
                            <p class="text-blue-100 mb-4"><?= htmlspecialchars($student['student_id']) ?></p>
                            
                            <div class="flex flex-wrap justify-center md:justify-start gap-4 mb-4">
                                <div class="stat-card bg-white bg-opacity-20 p-3 rounded-lg text-center min-w-[120px]">
                                    <div class="text-2xl font-bold"><?= $total_classes ?></div>
                                    <div class="text-sm text-blue-300">Total Classes</div>
                                </div>
                                <div class="stat-card bg-white bg-opacity-20 p-3 rounded-lg text-center min-w-[120px]">
                                    <div class="text-2xl font-bold"><?= $present_count ?></div>
                                    <div class="text-sm text-blue-300">Present</div>
                                </div>
                                <div class="stat-card bg-white bg-opacity-20 p-3 rounded-lg text-center min-w-[120px]">
                                    <div class="text-2xl font-bold"><?= round(($present_count / max(1, $total_classes)) * 100) ?>%</div>
                                    <div class="text-sm text-blue-300">Attendance</div>
                                </div>
                                <?php if ($absent_count > 0): ?>
                                <div class="stat-card bg-white bg-opacity-20 p-3 rounded-lg text-center min-w-[120px]">
                                    <div class="text-2xl font-bold"><?= $absent_without_reason ?></div>
                                    <div class="text-sm text-blue-300">No Reason</div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex flex-wrap justify-center md:justify-start gap-2">
                                <a href="mailto:<?= htmlspecialchars($student['email']) ?>" class="btn btn-sm btn-light">
                                    <i class="fas fa-envelope mr-2"></i> Email
                                </a>
                                <a href="tel:<?= htmlspecialchars($student['phone_number']) ?>" class="btn btn-sm btn-outline-light">
                                    <i class="fas fa-phone mr-2"></i> Call
                                </a>
                                <a href="edit_student.php?id=<?= $student['student_id'] ?>" class="btn btn-sm btn-outline-light">
                                    <i class="fas fa-edit mr-2"></i> Edit
                                </a>
                                <form action="drop_student.php" method="POST" class="inline">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($student['student_id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" 
                                            onclick="return confirm('Are you sure you want to drop this student?')">
                                        <i class="fas fa-user-minus mr-2"></i> Drop
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Details Section -->
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800 mb-4 pb-2 border-b border-gray-200">
                                <i class="fas fa-user-circle mr-2 text-blue-500"></i> Personal Information
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-start">
                                    <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-envelope mr-2"></i> Email:</span>
                                    <span><?= htmlspecialchars($student['email'] ?? 'N/A') ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-phone mr-2"></i> Phone:</span>
                                    <span><?= htmlspecialchars($student['phone_number'] ?? 'N/A') ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-birthday-cake mr-2"></i> DOB:</span>
                                    <span><?= $student['date_of_birth'] ? date('M j, Y', strtotime($student['date_of_birth'])) : 'N/A' ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-user-friends mr-2"></i> Father:</span>
                                    <span><?= htmlspecialchars($student['father_name'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="text-lg font-bold text-gray-800 mb-4 pb-2 border-b border-gray-200">
                                <i class="fas fa-graduation-cap mr-2 text-blue-500"></i> Academic Information
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-start">
                                    <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-users mr-2"></i> Batch:</span>
                                    <span><?= htmlspecialchars($batch['batch_id'] ?? 'N/A') ?> - <?= htmlspecialchars($batch['course_name'] ?? 'N/A') ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-calendar-alt mr-2"></i> Enrolled:</span>
                                    <span><?= date('M j, Y', strtotime($student['enrollment_date'])) ?></span>
                                </div>
                                <div class="flex items-start">
                                    <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-info-circle mr-2"></i> Status:</span>
                                    <span class="px-3 py-1 text-xs rounded-full 
                                        <?= $student['current_status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                           ($student['current_status'] === 'dropped' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') ?>">
                                        <?= ucfirst($student['current_status']) ?>
                                    </span>
                                </div>
                                <?php if ($student['current_status'] === 'dropped'): ?>
                                    <div class="flex items-start">
                                        <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-calendar-times mr-2"></i> Dropout:</span>
                                        <span><?= date('M j, Y', strtotime($student['dropout_date'])) ?></span>
                                    </div>
                                    <div class="flex items-start">
                                        <span class="text-gray-600 w-24 flex-shrink-0"><i class="fas fa-comment mr-2"></i> Reason:</span>
                                        <span><?= htmlspecialchars($student['dropout_reason'] ?? 'N/A') ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Stats Tabs -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6">
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
                        <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents-tab-pane" type="button" role="tab">
                            <i class="fas fa-file-alt mr-2"></i> Documents
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="performance-tab" data-bs-toggle="tab" data-bs-target="#performance-tab-pane" type="button" role="tab">
                            <i class="fas fa-chart-line mr-2"></i> Performance
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content p-4" id="studentTabsContent">
                    <!-- Attendance Tab -->
                    <div class="tab-pane fade show active" id="attendance-tab-pane" role="tabpanel">
                        <div class="mb-6">
                            <h4 class="font-bold text-gray-800 mb-4">Attendance Summary</h4>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                                <div class="stat-card p-4 attendance-present">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="font-bold">Present</span>
                                        <span class="text-lg font-bold"><?= $present_count ?></span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" style="width: <?= $total_classes ? ($present_count/$total_classes)*100 : 0 ?>%" aria-valuenow="<?= $present_count ?>" aria-valuemin="0" aria-valuemax="<?= $total_classes ?>"></div>
                                    </div>
                                </div>
                                <div class="stat-card p-4 attendance-absent">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="font-bold">Absent</span>
                                        <span class="text-lg font-bold"><?= $absent_count ?></span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $total_classes ? ($absent_count/$total_classes)*100 : 0 ?>%" aria-valuenow="<?= $absent_count ?>" aria-valuemin="0" aria-valuemax="<?= $total_classes ?>"></div>
                                    </div>
                                </div>
                                <div class="stat-card p-4 attendance-late">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="font-bold">Late</span>
                                        <span class="text-lg font-bold"><?= $late_count ?></span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $total_classes ? ($late_count/$total_classes)*100 : 0 ?>%" aria-valuenow="<?= $late_count ?>" aria-valuemin="0" aria-valuemax="<?= $total_classes ?>"></div>
                                    </div>
                                </div>
                                <?php if ($absent_count > 0): ?>
                                <div class="stat-card p-4 bg-gray-100">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="font-bold">No Reason</span>
                                        <span class="text-lg font-bold"><?= $absent_without_reason ?></span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-secondary" role="progressbar" style="width: <?= $absent_count ? ($absent_without_reason/$absent_count)*100 : 0 ?>%" aria-valuenow="<?= $absent_without_reason ?>" aria-valuemin="0" aria-valuemax="<?= $absent_count ?>"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
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
                                            <th>Reason/Remarks</th>
                                            <th>Actions</th>
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
                                                <td>
                                                    <?php if ($record['status'] === 'Absent'): ?>
                                                        <span class="absent-reason-badge" 
                                                              data-bs-toggle="popover" 
                                                              data-bs-trigger="hover" 
                                                              data-bs-placement="top" 
                                                              data-bs-content="<?= htmlspecialchars($record['display_remarks']) ?>">
                                                            <?= strlen($record['display_remarks']) > 30 ? substr($record['display_remarks'], 0, 30) . '...' : $record['display_remarks'] ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($record['display_remarks'] ?? '') ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'teacher'): ?>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editAttendanceModal"
                                                                data-id="<?= $record['id'] ?>"
                                                                data-status="<?= $record['status'] ?>"
                                                                data-remarks="<?= htmlspecialchars($record['remarks'] ?? '') ?>">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
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
                    
                    <!-- Documents Tab -->
                    <div class="tab-pane fade" id="documents-tab-pane" role="tabpanel">
                        <div class="mb-4">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                                <i class="fas fa-upload mr-2"></i> Upload Document
                            </button>
                        </div>
                        
                        <?php if (isset($_SESSION['success_message'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?= $_SESSION['success_message'] ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php unset($_SESSION['success_message']); ?>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['error_message'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?= $_SESSION['error_message'] ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php unset($_SESSION['error_message']); ?>
                        <?php endif; ?>
                        
                        <div class="row">
                            <?php if (count($documents) > 0): ?>
                                <?php foreach ($documents as $doc): ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="document-card p-4 position-relative">
                                            <span class="document-type-badge badge bg-primary">
                                                <?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?>
                                            </span>
                                            
                                            <div class="text-center mb-3">
                                                <?php 
                                                    $icon = 'fa-file';
                                                    if (strpos($doc['file_path'], '.pdf') !== false) $icon = 'fa-file-pdf';
                                                    elseif (strpos($doc['file_path'], '.jpg') !== false || strpos($doc['file_path'], '.jpeg') !== false || strpos($doc['file_path'], '.png') !== false) $icon = 'fa-file-image';
                                                    elseif (strpos($doc['file_path'], '.doc') !== false) $icon = 'fa-file-word';
                                                ?>
                                                <i class="fas <?= $icon ?> document-icon mb-2"></i>
                                                <h5 class="mb-1"><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?></h5>
                                                <small class="text-muted">Uploaded: <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></small>
                                            </div>
                                            
                                            <div class="document-actions text-center">
                                                <a href="<?= $doc['file_path'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye mr-1"></i> View
                                                </a>
                                                <a href="<?= $doc['file_path'] ?>" download class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-download mr-1"></i> Download
                                                </a>
                                                <form action="" method="POST" class="d-inline">
                                                    <input type="hidden" name="document_id" value="<?= $doc['document_id'] ?>">
                                                    <button type="submit" name="delete_document" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this document?')">
                                                        <i class="fas fa-trash mr-1"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12 text-center py-8">
                                    <i class="fas fa-file-alt text-gray-400 fa-4x mb-3"></i>
                                    <h5 class="text-gray-600">No documents uploaded yet</h5>
                                    <p class="text-muted">Upload documents using the button above</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Performance Tab -->
                    <div class="tab-pane fade" id="performance-tab-pane" role="tabpanel">
                        <div class="text-center py-8">
                            <i class="fas fa-chart-pie text-gray-400 fa-4x mb-3"></i>
                            <h5 class="text-gray-600">Performance analytics coming soon</h5>
                            <p class="text-muted">This section will display charts and graphs showing the student's performance trends.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title" id="uploadDocumentModalLabel">Upload Document</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="document_type" class="form-label">Document Type</label>
                            <select class="form-select" id="document_type" name="document_type" required>
                                <option value="">Select document type</option>
                                <option value="aadhaar">Aadhaar Card</option>
                                <option value="pancard">PAN Card</option>
                                <option value="tenth_marksheet">10th Marksheet</option>
                                <option value="twelfth_marksheet">12th Marksheet</option>
                                <option value="other">Other Document</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="document_file" class="form-label">Document File</label>
                            <input class="form-control" type="file" id="document_file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                            <small class="text-muted">Allowed formats: PDF, JPG, JPEG, PNG, DOC, DOCX</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="upload_document" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Attendance Modal -->
    <div class="modal fade" id="editAttendanceModal" tabindex="-1" aria-labelledby="editAttendanceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editAttendanceModalLabel">Edit Attendance Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="attendance_id" id="editAttendanceId">
                        <div class="mb-3">
                            <label for="editAttendanceStatus" class="form-label">Status</label>
                            <select class="form-select" id="editAttendanceStatus" name="status" required>
                                <option value="Present">Present</option>
                                <option value="Absent">Absent</option>
                                <option value="Late">Late</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="editAttendanceRemarks" class="form-label">Reason/Remarks</label>
                            <textarea class="form-control" id="editAttendanceRemarks" name="remarks" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_attendance" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        
        // Initialize tooltips and popovers
        $(function () {
            $('[data-bs-toggle="tooltip"]').tooltip();
            
            // Initialize popovers for absent reasons
            $('.absent-reason-badge').popover({
                container: 'body',
                template: '<div class="popover reason-popover" role="tooltip"><div class="popover-arrow"></div><h3 class="popover-header"></h3><div class="popover-body"></div></div>'
            });
            
            // Set up edit attendance modal
            $('#editAttendanceModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                var id = button.data('id');
                var status = button.data('status');
                var remarks = button.data('remarks');
                
                var modal = $(this);
                modal.find('#editAttendanceId').val(id);
                modal.find('#editAttendanceStatus').val(status);
                modal.find('#editAttendanceRemarks').val(remarks);
            });
        });
        
        // Show modal if there was an error
        <?php if (isset($_SESSION['show_upload_modal'])): ?>
            $(document).ready(function() {
                $('#uploadDocumentModal').modal('show');
            });
            <?php unset($_SESSION['show_upload_modal']); ?>
        <?php endif; ?>
    </script>
</body>
</html>