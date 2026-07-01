<?php
// student_delete.php
require_once '../db_connection.php';
session_start();

// Redirect to login if user is not authenticated or not an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get student ID from URL
$student_id = isset($_GET['id']) ? $_GET['id'] : null;

// Redirect if student ID is missing
if (!$student_id) {
    $_SESSION['error_message'] = "No student specified for deletion.";
    header("Location: students_list.php");
    exit();
}

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if student exists
    $stmt = $db->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $_SESSION['error_message'] = "Student not found.";
        header("Location: students_list.php");
        exit();
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['confirm_delete'])) {
            // Begin transaction for multiple operations if needed
            $db->beginTransaction();
            
            try {
                // Delete related records first to maintain referential integrity
                // Delete attendance records
                $stmt = $db->prepare("DELETE FROM attendance WHERE student_name = ?");
                $student_name = $student['first_name'] . ' ' . $student['last_name'];
                $stmt->execute([$student_name]);
                
                // Delete exam records
                $stmt = $db->prepare("DELETE es FROM exam_students es 
                                    JOIN proctored_exams pe ON es.exam_id = pe.exam_id 
                                    WHERE es.student_name = ? AND pe.batch_id = ?");
                $stmt->execute([$student_name, $student['batch_name']]);
                
                // Delete document records and files
                $stmt = $db->prepare("SELECT * FROM student_documents WHERE student_id = ?");
                $stmt->execute([$student_id]);
                $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($documents as $doc) {
                    if (file_exists($doc['file_path'])) {
                        unlink($doc['file_path']);
                    }
                }
                
                $stmt = $db->prepare("DELETE FROM student_documents WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // Delete the student
                $stmt = $db->prepare("DELETE FROM students WHERE student_id = ?");
                $stmt->execute([$student_id]);
                
                // Commit the transaction
                $db->commit();
                
                $_SESSION['success_message'] = "Student deleted successfully!";
                header("Location: students_list.php");
                exit();
                
            } catch (Exception $e) {
                // Rollback the transaction on error
                $db->rollBack();
                throw $e;
            }
        } else {
            // User cancelled the deletion
            $_SESSION['info_message'] = "Deletion cancelled.";
            header("Location: students_list.php");
            exit();
        }
    }
    
} catch(PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header("Location: students_list.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Student - ASD Academy</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f7f9fc;
        }
        .card {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Sidebar -->
    <?php include '../sidebar.php'; ?>
    
    <div class="md:ml-64">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex items-center">
            <button class="md:hidden text-xl text-gray-600 mr-4" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800">
                <i class="fas fa-trash-alt text-red-500 mr-2"></i> Delete Student
            </h1>
        </header>

        <div class="container mx-auto px-4 py-8">
            <div class="max-w-2xl mx-auto">
                <!-- Alert Messages -->
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= $_SESSION['error_message'] ?>
                        <?php unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>

                <!-- Confirmation Card -->
                <div class="card bg-white p-6 md:p-8">
                    <div class="text-center mb-6">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
                        </div>
                        <h2 class="text-xl font-bold text-gray-800 mb-2">Confirm Deletion</h2>
                        <p class="text-gray-600">Are you sure you want to delete this student? This action cannot be undone.</p>
                    </div>

                    <!-- Student Details -->
                    <div class="bg-gray-50 p-4 rounded-lg mb-6">
                        <h3 class="font-semibold text-gray-700 mb-2">Student Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-600">Student ID</p>
                                <p class="font-medium"><?= htmlspecialchars($student['student_id']) ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Name</p>
                                <p class="font-medium"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Email</p>
                                <p class="font-medium"><?= htmlspecialchars($student['email'] ?: 'N/A') ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Status</p>
                                <p class="font-medium">
                                    <span class="px-2 py-1 text-xs rounded-full 
                                        <?= $student['current_status'] == 'active' ? 'bg-green-100 text-green-800' : 
                                           ($student['current_status'] == 'inactive' ? 'bg-red-100 text-red-800' : 
                                           'bg-gray-100 text-gray-800') ?>">
                                        <?= htmlspecialchars(ucfirst($student['current_status'])) ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Warning Box -->
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-circle text-red-400 mt-1"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Warning</h3>
                                <div class="mt-2 text-sm text-red-700">
                                    <p>This action will permanently delete:</p>
                                    <ul class="list-disc pl-5 mt-1 space-y-1">
                                        <li>Student record</li>
                                        <li>All attendance records</li>
                                        <li>All exam results</li>
                                        <li>All uploaded documents</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Confirmation Form -->
                    <form method="POST" class="flex flex-col sm:flex-row justify-center space-y-3 sm:space-y-0 sm:space-x-4">
                        <a href="students_list.php" class="px-6 py-3 bg-gray-200 text-gray-800 rounded-lg text-center font-medium hover:bg-gray-300 transition">
                            <i class="fas fa-times mr-2"></i> Cancel
                        </a>
                        <button type="submit" name="confirm_delete" class="px-6 py-3 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition">
                            <i class="fas fa-trash-alt mr-2"></i> Confirm Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    </script>
</body>
</html>