<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use absolute path for better reliability
require_once __DIR__ . '/../db_connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to access this page";
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../unauthorized.php");
    exit;
}

// Handle both GET (view) and POST (submit) methods
$student_id = isset($_REQUEST['id']) ? trim($_REQUEST['id']) : null;

if (!$student_id) {
    $_SESSION['error'] = "Student ID not provided";
    header("Location: students_list.php");
    exit;
}

// Get student details for the form
$student = [];
try {
    $stmt = $db->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $_SESSION['error'] = "Student not found";
        header("Location: students_list.php");
        exit;
    }
    
    // Check if student is already dropped
    if ($student['current_status'] === 'dropped') {
        $_SESSION['error'] = "Student is already dropped";
        header("Location: students_list.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header("Location: students_list.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = isset($_POST['id']) ? trim($_POST['id']) : null;
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    $confirm = isset($_POST['confirm_drop']) ? true : false;
    
    // Validate inputs
    if (empty($student_id)) {
        $_SESSION['error'] = "Student ID not provided";
        header("Location: students_list.php");
        exit;
    }
    
    if (empty($reason)) {
        $_SESSION['error'] = "Please provide a reason for dropping the student";
        header("Location: drop_student.php?id=" . urlencode($student_id));
        exit;
    }
    
    if (!$confirm) {
        $_SESSION['error'] = "Please confirm that you want to drop this student";
        header("Location: drop_student.php?id=" . urlencode($student_id));
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Update student status
        $stmt = $db->prepare("UPDATE students SET 
                             current_status = 'dropped', 
                             dropout_date = CURDATE(), 
                             dropout_reason = ?,
                             dropout_processed_by = ?,
                             dropout_processed_at = NOW(),
                             updated_at = NOW()
                             WHERE student_id = ?");
        $stmt->execute([$reason, $_SESSION['user_id'], $student_id]);
        
        // Verify the update worked
        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) {
            throw new Exception("No rows were updated - student may not exist or already dropped");
        }
        
        // Log the action
        $log_stmt = $db->prepare("INSERT INTO student_status_log 
                                 (student_id, action, reason, processed_by, created_at)
                                 VALUES (?, 'dropped', ?, ?, NOW())");
        $log_stmt->execute([$student_id, $reason, $_SESSION['user_id']]);
        
        $db->commit();
        
        $_SESSION['success'] = "Student successfully dropped";
        header("Location: drop_list.php");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Failed to drop student: " . $e->getMessage();
        header("Location: drop_student.php?id=" . urlencode($student_id));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drop Student - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .status-badge {
            @apply px-3 py-1 text-xs font-semibold rounded-full;
        }
        .status-active {
            @apply bg-green-100 text-green-800;
        }
        .status-dropped {
            @apply bg-red-100 text-red-800;
        }
        .status-pending {
            @apply bg-yellow-100 text-yellow-800;
        }
        .btn-primary {
            @apply bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded transition duration-300;
        }
        .btn-danger {
            @apply bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition duration-300;
        }
        .btn-secondary {
            @apply bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition duration-300;
        }
        .form-input {
            @apply w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent;
        }
        .form-label {
            @apply block text-gray-700 font-medium mb-2;
        }
        .card {
            @apply bg-white rounded-lg shadow-md p-6;
        }
        .alert {
            @apply px-4 py-3 rounded mb-4;
        }
        .alert-error {
            @apply bg-red-100 border border-red-400 text-red-700;
        }
        .alert-success {
            @apply bg-green-100 border border-green-400 text-green-700;
        }
        .alert-warning {
            @apply bg-yellow-100 border border-yellow-400 text-yellow-700;
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php include __DIR__ . '/../header.php'; ?>
    <?php include __DIR__ . '/../sidebar.php'; ?>
    
    <div class="ml-64 p-8">
        <div class="max-w-3xl mx-auto fade-in">
            <!-- Breadcrumb -->
            <nav class="text-sm mb-6">
                <ol class="list-reset flex text-gray-700">
                    <li><a href="../dashboard.php" class="text-blue-600 hover:text-blue-800">Dashboard</a></li>
                    <li><span class="mx-2">/</span></li>
                    <li><a href="students_list.php" class="text-blue-600 hover:text-blue-800">Students</a></li>
                    <li><span class="mx-2">/</span></li>
                    <li class="text-gray-600">Drop Student</li>
                </ol>
            </nav>

            <div class="card">
                <div class="flex items-center justify-between mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-user-minus text-red-600 mr-2"></i>Drop Student
                    </h1>
                    <span class="text-sm text-gray-500">
                        <i class="far fa-calendar-alt mr-1"></i> <?= date('F d, Y') ?>
                    </span>
                </div>
                
                <!-- Display Messages -->
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-error fade-in">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($_SESSION['error']) ?>
                        <?php unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success fade-in">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?= htmlspecialchars($_SESSION['success']) ?>
                        <?php unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Warning Message -->
                <div class="alert alert-warning fade-in mb-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-lg"></i>
                        </div>
                        <div class="ml-3">
                            <p class="font-medium">Warning: This action cannot be easily undone!</p>
                            <p class="text-sm">Please ensure you have the proper authorization before dropping this student.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Student Information -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <h2 class="text-lg font-semibold text-gray-700 mb-3">
                        <i class="fas fa-user mr-2"></i>Student Information
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-600">Full Name</p>
                            <p class="font-medium text-gray-800">
                                <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Student ID</p>
                            <p class="font-medium text-gray-800">
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm">
                                    <?= htmlspecialchars($student['student_id']) ?>
                                </span>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Email</p>
                            <p class="font-medium text-gray-800">
                                <?= htmlspecialchars($student['email'] ?? 'N/A') ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Program</p>
                            <p class="font-medium text-gray-800">
                                <?= htmlspecialchars($student['program'] ?? 'N/A') ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Current Status</p>
                            <p>
                                <span class="status-badge status-active">
                                    <?= htmlspecialchars(ucfirst($student['current_status'])) ?>
                                </span>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Enrollment Date</p>
                            <p class="font-medium text-gray-800">
                                <?= htmlspecialchars($student['enrollment_date'] ?? 'N/A') ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Drop Form -->
                <form method="POST" onsubmit="return confirmDrop();">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($student_id) ?>">
                    
                    <div class="mb-4">
                        <label for="reason" class="form-label">
                            <i class="fas fa-pen mr-2"></i>Reason for Dropping <span class="text-red-600">*</span>
                        </label>
                        <textarea id="reason" name="reason" rows="4" 
                                  class="form-input" 
                                  placeholder="Please provide a detailed reason for dropping this student (e.g., academic performance, personal reasons, transfer, etc.)"
                                  required><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>Minimum 10 characters required
                        </p>
                    </div>
                    
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                        <label class="flex items-start">
                            <input type="checkbox" name="confirm_drop" value="1" 
                                   class="form-checkbox h-5 w-5 text-red-600 mt-0.5" required>
                            <span class="ml-2 text-gray-700">
                                <strong>I confirm that I want to drop this student</strong>
                                <br>
                                <span class="text-sm text-gray-600">I understand that this action will change the student's status to "Dropped" and cannot be easily undone.</span>
                            </span>
                        </label>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-4">
                        <a href="students_list.php" class="btn-secondary text-center">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn-danger">
                            <i class="fas fa-user-minus mr-2"></i>Confirm Drop Student
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Additional Information -->
            <div class="mt-6 text-center text-sm text-gray-500">
                <p>
                    <i class="fas fa-shield-alt mr-1"></i>
                    This action will be logged for audit purposes.
                </p>
            </div>
        </div>
    </div>

    <script>
        function confirmDrop() {
            const reason = document.getElementById('reason').value.trim();
            const confirmCheckbox = document.querySelector('input[name="confirm_drop"]');
            
            if (reason.length < 10) {
                alert('Please provide a detailed reason (minimum 10 characters).');
                document.getElementById('reason').focus();
                return false;
            }
            
            if (!confirmCheckbox.checked) {
                alert('Please confirm that you want to drop this student.');
                confirmCheckbox.focus();
                return false;
            }
            
            return confirm(
                '⚠️ WARNING: Are you sure you want to drop this student?\n\n' +
                'Student: <?= addslashes($student['first_name'] . ' ' . $student['last_name']) ?>\n' +
                'ID: <?= addslashes($student['student_id']) ?>\n\n' +
                'This action will change their status to "Dropped" and cannot be easily undone.\n' +
                'All associated records will remain but the student will be marked as dropped.\n\n' +
                'Click OK to proceed or Cancel to abort.'
            );
        }
        
        // Auto-resize textarea
        document.addEventListener('DOMContentLoaded', function() {
            const textarea = document.getElementById('reason');
            if (textarea) {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = this.scrollHeight + 'px';
                });
            }
        });
    </script>
</body>
</html>