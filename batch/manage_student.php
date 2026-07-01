<?php
require_once '../db_connection.php';
session_start();

// Enhanced session validation
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Validate and sanitize batch_id
$batch_id = isset($_GET['batch_id']) ? trim($_GET['batch_id']) : null;

if (!$batch_id || !preg_match('/^[A-Z0-9_-]+$/i', $batch_id)) {
    $_SESSION['error'] = "Invalid batch ID";
    header("Location: ../batch_list.php");
    exit();
}

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
    // Get batch details with prepared statement
    $stmt = $db->prepare("SELECT * FROM batches WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$batch) {
        $_SESSION['error'] = "Batch not found";
        header("Location: ../batch_list.php");
        exit();
    }
    
    // Get students in this batch with proper ordering
    $stmt = $db->prepare("SELECT * FROM students WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) ORDER BY first_name, last_name");
    $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $actual_enrollment = count($students);
    
    // Auto-sync current_enrollment if it's out of sync
    if ($batch['current_enrollment'] != $actual_enrollment) {
        $updateStmt = $db->prepare("UPDATE batches SET current_enrollment = ? WHERE batch_id = ?");
        $updateStmt->execute([$actual_enrollment, $batch_id]);
        $batch['current_enrollment'] = $actual_enrollment;
    }
    
    // Handle bulk actions with CSRF protection
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error'] = "Invalid CSRF token";
            header("Location: manage_student.php?batch_id=" . urlencode($batch_id));
            exit();
        }

        if (isset($_POST['action'])) {
            $selected_students = $_POST['students'] ?? [];
            
            // Validate selected students
            $valid_students = [];
            foreach ($selected_students as $student_id) {
                if (preg_match('/^[A-Z0-9_-]+$/i', $student_id)) {
                    $valid_students[] = $student_id;
                }
            }
            
            if (!empty($valid_students)) {
                $placeholders = implode(',', array_fill(0, count($valid_students), '?'));
                
                switch ($_POST['action']) {
                    case 'transfer':
                        $target_batch = isset($_POST['target_batch']) ? trim($_POST['target_batch']) : '';
                        
                        if ($target_batch && preg_match('/^[A-Z0-9_-]+$/i', $target_batch)) {
                            // Verify target batch exists and is active
                            $stmt = $db->prepare("SELECT batch_id, batch_name, max_students, current_enrollment, status FROM batches WHERE batch_id = ? AND status IN ('upcoming', 'ongoing')");
                            $stmt->execute([$target_batch]);
                            $targetBatchInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($targetBatchInfo) {
                                // Check if target batch has enough capacity
                                $studentsToTransfer = count($valid_students);
                                $availableCapacity = $targetBatchInfo['max_students'] - $targetBatchInfo['current_enrollment'];
                                
                                if ($studentsToTransfer > $availableCapacity) {
                                    $_SESSION['error'] = "Target batch does not have enough capacity. Available: " . 
                                                        $availableCapacity . 
                                                        ", Required: " . $studentsToTransfer;
                                } else {
                                    // Update students' batch in transaction
                                    $db->beginTransaction();
                                    
                                    try {
                                        // Update students' batch dynamically checking all 4 slots
                                        $stmt = $db->prepare("
                                            UPDATE students 
                                            SET 
                                                batch_name = CASE WHEN batch_name = ? THEN ? ELSE batch_name END,
                                                batch_name_2 = CASE WHEN batch_name_2 = ? THEN ? ELSE batch_name_2 END,
                                                batch_name_3 = CASE WHEN batch_name_3 = ? THEN ? ELSE batch_name_3 END,
                                                batch_name_4 = CASE WHEN batch_name_4 = ? THEN ? ELSE batch_name_4 END,
                                                current_status = 'active'
                                            WHERE student_id IN ($placeholders) 
                                            AND (? IN (batch_name, batch_name_2, batch_name_3, batch_name_4))
                                        ");
                                        $params = [
                                            $batch_id, $target_batch,
                                            $batch_id, $target_batch,
                                            $batch_id, $target_batch,
                                            $batch_id, $target_batch
                                        ];
                                        $params = array_merge($params, $valid_students);
                                        $params[] = $batch_id;
                                        $stmt->execute($params);
                                        
                                        $updatedCount = $stmt->rowCount();
                                        
                                        if ($updatedCount > 0) {
                                            // Record batch transfer history for each student
                                            $historyStmt = $db->prepare("INSERT INTO student_batch_history 
                                                                        (student_id, from_batch_id, to_batch_id, transfer_date, transferred_by, transfer_reason) 
                                                                        VALUES (?, ?, ?, NOW(), ?, ?)");
                                            
                                            $transfer_reason = "Bulk transfer from manage students page";
                                            
                                            foreach ($valid_students as $student_id) {
                                                $historyStmt->execute([
                                                    $student_id,
                                                    $batch_id, // current batch
                                                    $target_batch, // target batch
                                                    $_SESSION['user_id'], // admin who performed transfer
                                                    $transfer_reason
                                                ]);
                                                
                                                // Create notification for student
                                                $userStmt = $db->prepare("SELECT user_id, first_name, last_name, email FROM students WHERE student_id = ?");
                                                $userStmt->execute([$student_id]);
                                                $studentData = $userStmt->fetch(PDO::FETCH_ASSOC);
                                                
                                                if ($studentData && $studentData['user_id']) {
                                                    $notificationStmt = $db->prepare("INSERT INTO notifications 
                                                                                    (user_id, type, title, message, reference_id, created_at) 
                                                                                    VALUES (?, 'transfer', ?, ?, ?, NOW())");
                                                    $notificationTitle = "Batch Transfer Notification";
                                                    $notificationMessage = "You have been transferred from batch {$batch['batch_name']} to {$targetBatchInfo['batch_name']}";
                                                    $notificationStmt->execute([
                                                        $studentData['user_id'],
                                                        $notificationTitle,
                                                        $notificationMessage,
                                                        $student_id
                                                    ]);
                                                }
                                            }
                                            
                                            // Update batch enrollment counts
                                            $decrementStmt = $db->prepare("UPDATE batches SET current_enrollment = GREATEST(0, current_enrollment - ?) WHERE batch_id = ?");
                                            $decrementStmt->execute([$updatedCount, $batch_id]);
                                            
                                            $incrementStmt = $db->prepare("UPDATE batches SET current_enrollment = current_enrollment + ? WHERE batch_id = ?");
                                            $incrementStmt->execute([$updatedCount, $target_batch]);
                                            
                                            // IMPORTANT: DO NOT update attendance records
                                            // Attendance records remain with their original batch for historical accuracy
                                            
                                            $db->commit();
                                            $_SESSION['success'] = "Successfully transferred $updatedCount student(s) to {$targetBatchInfo['batch_name']}!";
                                        } else {
                                            $db->rollBack();
                                            $_SESSION['error'] = "No students were transferred. Please check if students are in the current batch.";
                                        }
                                        
                                    } catch (Exception $e) {
                                        $db->rollBack();
                                        $_SESSION['error'] = "Error transferring students: " . $e->getMessage();
                                        error_log("Transfer error in manage_student.php: " . $e->getMessage());
                                    }
                                }
                            } else {
                                $_SESSION['error'] = "Invalid target batch selected or batch is not active";
                            }
                        } else {
                            $_SESSION['error'] = "Please select a valid target batch";
                        }
                        break;
                        
                    case 'drop':
                        $dropout_date = date('Y-m-d');
                        $dropout_reason = isset($_POST['dropout_reason']) ? trim($_POST['dropout_reason']) : '';
                        
                        // Limit reason length and sanitize
                        $dropout_reason = substr($dropout_reason, 0, 255);
                        
                        $stmt = $db->prepare("UPDATE students SET current_status = 'dropped', dropout_date = ?, dropout_reason = ?, dropout_processed_by = ?, dropout_processed_at = NOW() WHERE student_id IN ($placeholders)");
                        $params = array_merge([$dropout_date, $dropout_reason, $_SESSION['user_id']], $valid_students);
                        $stmt->execute($params);
                        
                        $droppedCount = $stmt->rowCount();
                        
                        if ($droppedCount > 0) {
                            // Update batch enrollment count
                            $updateBatchStmt = $db->prepare("UPDATE batches SET current_enrollment = GREATEST(0, current_enrollment - ?) WHERE batch_id = ?");
                            $updateBatchStmt->execute([$droppedCount, $batch_id]);
                            
                            // Log status change
                            $logStmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by, processed_at) VALUES (?, 'dropped', ?, ?, NOW())");
                            foreach ($valid_students as $student_id) {
                                $logStmt->execute([$student_id, $dropout_reason, $_SESSION['user_id']]);
                            }
                            
                            $_SESSION['success'] = "Selected students marked as dropped!";
                        } else {
                            $_SESSION['error'] = "No students were marked as dropped.";
                        }
                        break;
                        
                    case 'activate':
                        $stmt = $db->prepare("UPDATE students SET current_status = 'active', dropout_date = NULL, dropout_reason = NULL, dropout_processed_by = NULL, dropout_processed_at = NULL WHERE student_id IN ($placeholders)");
                        $stmt->execute($valid_students);
                        
                        $activatedCount = $stmt->rowCount();
                        
                        if ($activatedCount > 0) {
                            // Update batch enrollment count if needed
                            $updateBatchStmt = $db->prepare("UPDATE batches SET current_enrollment = current_enrollment + ? WHERE batch_id = ?");
                            $updateBatchStmt->execute([$activatedCount, $batch_id]);
                            
                            // Log status change
                            $logStmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by, processed_at) VALUES (?, 'reactivated', 'Reactivated from dropped status', ?, NOW())");
                            foreach ($valid_students as $student_id) {
                                $logStmt->execute([$student_id, $_SESSION['user_id']]);
                            }
                            
                            $_SESSION['success'] = "Selected students activated!";
                        } else {
                            $_SESSION['error'] = "No students were activated.";
                        }
                        break;
                        
                    default:
                        $_SESSION['error'] = "Invalid action selected";
                }
                
                // Refresh students list
                $stmt = $db->prepare("SELECT * FROM students WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) ORDER BY first_name, last_name");
                $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $_SESSION['error'] = "No valid students selected";
            }
        }
        
        header("Location: manage_student.php?batch_id=" . urlencode($batch_id));
        exit();
    }
    
    // Get other batches for transfer dropdown (excluding current batch and only active ones)
    $stmt = $db->prepare("SELECT batch_id, batch_name, max_students, current_enrollment FROM batches WHERE batch_id != ? AND status IN ('upcoming', 'ongoing') ORDER BY start_date ASC");
    $stmt->execute([$batch_id]);
    $available_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate capacity for each batch
    foreach ($available_batches as &$batch_option) {
        $batch_option['available_seats'] = $batch_option['max_students'] - $batch_option['current_enrollment'];
    }
    
    // Generate CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
} catch(PDOException $e) {
    error_log("Database error in manage_student.php: " . $e->getMessage());
    $_SESSION['error'] = "A database error occurred. Please try again later.";
    header("Location: ../batch_list.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .status-badge {
            transition: all 0.2s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
        }
        .table-row-hover:hover {
            background-color: #f9fafb;
            transition: background-color 0.2s ease;
        }
    </style>
</head>
<?php
include '../header.php';
include '../sidebar.php';
?>
<body class="bg-gray-100">
    <div class="ml-0 md:ml-64 min-h-screen">
        <div class="container mx-auto px-4 py-8">
        <div class="max-w-6xl mx-auto">
            <!-- Back button -->
            <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch_id) ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-4 transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i> Back to Batch
            </a>
            
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Manage Students - <?= htmlspecialchars($batch['batch_name']) ?></h1>
                    <p class="text-sm text-gray-600 mt-1">
                        <i class="fas fa-users mr-1"></i> Total Students: <?= count($students) ?> | 
                        <i class="fas fa-calendar-alt mr-1"></i> Batch ID: <?= htmlspecialchars($batch_id) ?>
                    </p>
                </div>
                <a href="../batch/student_add.php?batch_id=<?= htmlspecialchars($batch_id) ?>" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 transition-colors duration-200 shadow-md hover:shadow-lg">
                    <i class="fas fa-plus mr-2"></i> Add New Student
                </a>
            </div>
            
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded relative mb-6 shadow-sm" role="alert">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span class="block sm:inline"><?= htmlspecialchars($_SESSION['success']) ?></span>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded relative mb-6 shadow-sm" role="alert">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span class="block sm:inline"><?= htmlspecialchars($_SESSION['error']) ?></span>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Bulk Actions Form -->
            <form method="POST" id="bulkActionForm" class="bg-white shadow-md rounded-lg mb-6 overflow-hidden">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                
                <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">
                        <i class="fas fa-tasks mr-2"></i> Bulk Actions
                    </h2>
                    <p class="text-sm text-gray-600 mt-1">Perform actions on multiple students at once</p>
                </div>
                
                <div class="p-6">
                    <div class="flex flex-col md:flex-row md:items-end md:justify-between space-y-4 md:space-y-0">
                        <div class="flex-1 md:mr-4">
                            <label for="bulkAction" class="block text-sm font-medium text-gray-700 mb-1">Select Action</label>
                            <select id="bulkAction" name="action" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                <option value="">-- Select Action --</option>
                                <option value="drop">Mark as Dropped</option>
                                <option value="activate">Mark as Active</option>
                            </select>
                        </div>
                        

                        
                        <div id="dropFields" class="hidden flex-1">
                            <label for="dropout_reason" class="block text-sm font-medium text-gray-700 mb-1">Dropout Reason (Optional)</label>
                            <input type="text" id="dropout_reason" name="dropout_reason" placeholder="e.g., Financial issues, Schedule conflict, etc." 
                                   class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md"
                                   maxlength="255">
                        </div>
                        
                        <div>
                            <button type="submit" class="px-6 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 transition-colors duration-200 shadow-sm">
                                <i class="fas fa-check mr-2"></i> Apply Action
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Students Table -->
                <div class="overflow-x-auto">
                    <?php if (count($students) > 0): ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <input type="checkbox" id="selectAll" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Details</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enrollment Date</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($students as $student): ?>
                                    <tr class="table-row-hover">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="checkbox" name="students[]" value="<?= htmlspecialchars($student['student_id']) ?>" class="student-checkbox h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gradient-to-r from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold">
                                                    <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?= htmlspecialchars($student['email'] ?? 'No email') ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-mono text-gray-900"><?= htmlspecialchars($student['student_id']) ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <i class="fas fa-phone-alt text-gray-400 mr-1"></i>
                                                <?= htmlspecialchars($student['phone_number'] ?? 'N/A') ?>
                                            </div>
                                            <?php if (!empty($student['father_phone_number'])): ?>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    <i class="fas fa-user-tie text-gray-400 mr-1"></i>
                                                    Father: <?= htmlspecialchars($student['father_phone_number']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs rounded-full status-badge 
                                                <?= $student['current_status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                   ($student['current_status'] === 'dropped' ? 'bg-red-100 text-red-800' : 
                                                   ($student['current_status'] === 'on hold' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')) ?>">
                                                <i class="fas 
                                                    <?= $student['current_status'] === 'active' ? 'fa-check-circle' : 
                                                       ($student['current_status'] === 'dropped' ? 'fa-times-circle' : 
                                                       ($student['current_status'] === 'on hold' ? 'fa-pause-circle' : 'fa-question-circle')) ?> 
                                                    mr-1"></i>
                                                <?= htmlspecialchars(ucfirst($student['current_status'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?= date('M d, Y', strtotime($student['enrollment_date'])) ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <div class="flex flex-col space-y-2">
                                                <div class="flex space-x-2">
                                                    <a href="student_view.php?student_id=<?= htmlspecialchars($student['student_id']) ?>" 
                                                       class="inline-flex items-center px-3 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-100 hover:text-blue-800 rounded-md text-xs font-medium transition-colors border border-blue-200" 
                                                       title="View Profile">
                                                        <i class="fas fa-user-circle mr-1.5"></i> View Profile
                                                    </a>
                                                    <a href="../exam/student_overall_report.php?batch_id=<?= htmlspecialchars($batch_id) ?>&student_id=<?= htmlspecialchars($student['student_id']) ?>" 
                                                       class="inline-flex items-center px-3 py-1.5 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 hover:text-indigo-800 rounded-md text-xs font-medium transition-colors border border-indigo-200" 
                                                       title="View Result">
                                                        <i class="fas fa-chart-bar mr-1.5"></i> View Result
                                                    </a>
                                                </div>
                                                <div class="flex space-x-3 mt-1 px-1">
                                                    <a href="edit_student.php?id=<?= htmlspecialchars($student['student_id']) ?>" 
                                                       class="text-green-600 hover:text-green-900 transition-colors duration-200" 
                                                       title="Edit Student">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if (!empty($student['email'])): ?>
                                                        <a href="mailto:<?= htmlspecialchars($student['email']) ?>" 
                                                           class="text-purple-600 hover:text-purple-900 transition-colors duration-200" 
                                                           title="Send Email">
                                                            <i class="fas fa-envelope"></i>
                                                        </a>
                                                    <?php endif; ?>

                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-users-slash text-6xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500 text-lg">No students found in this batch</p>
                            <a href="../batch/student_add.php?batch_id=<?= htmlspecialchars($batch_id) ?>" 
                               class="inline-block mt-4 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i> Add First Student
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
            
            <!-- Batch Information Card -->
            <div class="bg-white shadow-md rounded-lg p-6 mt-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-info-circle mr-2"></i> Batch Information
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <p class="text-sm text-gray-600">Batch Name</p>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($batch['batch_name']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Batch ID</p>
                        <p class="font-medium text-gray-900 font-mono"><?= htmlspecialchars($batch['batch_id']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Enrollment</p>
                        <p class="font-medium text-gray-900">
                            <?= $batch['current_enrollment'] ?> / <?= $batch['max_students'] ?> students
                            <span class="text-sm text-gray-500">
                                (<?= round(($batch['current_enrollment'] / $batch['max_students']) * 100) ?>% filled)
                            </span>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Batch Status</p>
                        <span class="px-2 py-1 text-xs rounded-full 
                            <?= $batch['status'] === 'ongoing' ? 'bg-green-100 text-green-800' : 
                               ($batch['status'] === 'upcoming' ? 'bg-blue-100 text-blue-800' : 
                               ($batch['status'] === 'completed' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800')) ?>">
                            <?= ucfirst($batch['status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script>
        $(document).ready(function() {
            // Select all checkbox
            $('#selectAll').change(function() {
                $('.student-checkbox').prop('checked', $(this).prop('checked'));
                updateSelectedCount();
            });
            
            // Individual checkbox change
            $('.student-checkbox').change(function() {
                updateSelectedCount();
                $('#selectAll').prop('checked', $('.student-checkbox:checked').length === $('.student-checkbox').length);
            });
            
            // Show/hide action-specific fields
            $('#bulkAction').change(function() {
                $('#transferFields').addClass('hidden');
                $('#dropFields').addClass('hidden');
                $('#capacityWarning').addClass('hidden');
                
                if ($(this).val() === 'transfer') {
                    $('#transferFields').removeClass('hidden');
                    checkCapacity();
                } else if ($(this).val() === 'drop') {
                    $('#dropFields').removeClass('hidden');
                }
            });
            
            // Check capacity when target batch changes
            $('#target_batch').change(function() {
                checkCapacity();
            });
            
            function checkCapacity() {
                const selectedCount = $('.student-checkbox:checked').length;
                const selectedOption = $('#target_batch option:selected');
                const availableSeats = selectedOption.data('available');
                
                if (selectedCount > 0 && selectedOption.val() && availableSeats !== undefined) {
                    if (selectedCount > availableSeats) {
                        $('#capacityWarning').removeClass('hidden');
                        $('#capacityWarning').html(`<i class="fas fa-exclamation-triangle mr-1"></i> Warning: You're trying to transfer ${selectedCount} student(s) but only ${availableSeats} seat(s) available in the target batch.`);
                        $('#capacityWarning').addClass('text-red-600');
                    } else if (selectedCount === availableSeats) {
                        $('#capacityWarning').removeClass('hidden');
                        $('#capacityWarning').html(`<i class="fas fa-info-circle mr-1"></i> Note: The target batch will be full after this transfer (${selectedCount}/${availableSeats} seats will be filled).`);
                        $('#capacityWarning').removeClass('text-red-600').addClass('text-blue-600');
                    } else {
                        $('#capacityWarning').addClass('hidden');
                    }
                } else {
                    $('#capacityWarning').addClass('hidden');
                }
            }
            
            function updateSelectedCount() {
                const count = $('.student-checkbox:checked').length;
                if (count > 0) {
                    $('#selectAll').parent().append(`<span class="ml-2 text-xs text-blue-600">${count} selected</span>`);
                    setTimeout(() => {
                        $('.ml-2.text-xs.text-blue-600').remove();
                    }, 2000);
                }
                checkCapacity();
            }
            
            // Form validation
            $('#bulkActionForm').submit(function(e) {
                const action = $('#bulkAction').val();
                const selectedStudents = $('.student-checkbox:checked').length;
                
                if (!action) {
                    alert('Please select an action');
                    e.preventDefault();
                    return false;
                }
                
                if (selectedStudents === 0) {
                    alert('Please select at least one student');
                    e.preventDefault();
                    return false;
                }
                
                if (action === 'transfer') {
                    const targetBatch = $('#target_batch').val();
                    if (!targetBatch) {
                        alert('Please select a target batch for transfer');
                        e.preventDefault();
                        return false;
                    }
                    
                    const selectedOption = $('#target_batch option:selected');
                    const availableSeats = selectedOption.data('available');
                    if (selectedStudents > availableSeats) {
                        if (!confirm(`Warning: You're trying to transfer ${selectedStudents} student(s) but only ${availableSeats} seat(s) available in the target batch. Do you want to continue?`)) {
                            e.preventDefault();
                            return false;
                        }
                    }
                    
                    if (!confirm(`Are you sure you want to transfer ${selectedStudents} student(s) to ${selectedOption.text()}?`)) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                if (action === 'drop') {
                    if (!confirm(`Are you sure you want to mark ${selectedStudents} student(s) as dropped? This will affect their enrollment status.`)) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                if (action === 'activate') {
                    if (!confirm(`Are you sure you want to activate ${selectedStudents} student(s)?`)) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                return true;
            });
        });
        
        function transferSingleStudent(studentId, studentName) {
            const targetBatch = prompt(`Enter target batch ID for ${studentName}:`);
            if (targetBatch && targetBatch.trim()) {
                if (confirm(`Transfer ${studentName} to batch ${targetBatch}?`)) {
                    // Create a form to submit the transfer
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = 'csrf_token';
                    csrfInput.value = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
                    form.appendChild(csrfInput);
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'transfer';
                    form.appendChild(actionInput);
                    
                    const targetBatchInput = document.createElement('input');
                    targetBatchInput.type = 'hidden';
                    targetBatchInput.name = 'target_batch';
                    targetBatchInput.value = targetBatch;
                    form.appendChild(targetBatchInput);
                    
                    const studentInput = document.createElement('input');
                    studentInput.type = 'hidden';
                    studentInput.name = 'students[]';
                    studentInput.value = studentId;
                    form.appendChild(studentInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }
    </script>
</body>
</html>