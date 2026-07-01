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
    $stmt = $db->prepare("SELECT * FROM students WHERE batch_name = ? ORDER BY first_name, last_name");
    $stmt->execute([$batch_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
                            // Verify target batch exists
                            $stmt = $db->prepare("SELECT 1 FROM batches WHERE batch_id = ? AND status IN ('upcoming', 'ongoing')");
                            $stmt->execute([$target_batch]);
                            
                            if ($stmt->fetch()) {
                                // Update students' batch in transaction
                                $db->beginTransaction();
                                
                                try {
                                    $stmt = $db->prepare("UPDATE students SET batch_name = ? WHERE student_id IN ($placeholders)");
                                    $params = array_merge([$target_batch], $valid_students);
                                    $stmt->execute($params);
                                    
                                    // Update attendance records
                                    foreach ($valid_students as $student_id) {
                                        $stmt = $db->prepare("SELECT first_name, last_name FROM students WHERE student_id = ?");
                                        $stmt->execute([$student_id]);
                                        $student = $stmt->fetch(PDO::FETCH_ASSOC);
                                        
                                        if ($student) {
                                            $student_name = $student['first_name'] . ' ' . $student['last_name'];
                                            $stmt = $db->prepare("UPDATE attendance SET batch_id = ? WHERE student_name = ? AND batch_id = ?");
                                            $stmt->execute([$target_batch, $student_name, $batch_id]);
                                        }
                                    }
                                    
                                    $db->commit();
                                    $_SESSION['success'] = "Selected students transferred successfully!";
                                } catch (Exception $e) {
                                    $db->rollBack();
                                    $_SESSION['error'] = "Error transferring students: " . $e->getMessage();
                                }
                            } else {
                                $_SESSION['error'] = "Invalid target batch selected";
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
                        
                        $stmt = $db->prepare("UPDATE students SET current_status = 'dropped', dropout_date = ?, dropout_reason = ? WHERE student_id IN ($placeholders)");
                        $params = array_merge([$dropout_date, $dropout_reason], $valid_students);
                        $stmt->execute($params);
                        
                        $_SESSION['success'] = "Selected students marked as dropped!";
                        break;
                        
                    case 'activate':
                        $stmt = $db->prepare("UPDATE students SET current_status = 'active', dropout_date = NULL, dropout_reason = NULL WHERE student_id IN ($placeholders)");
                        $stmt->execute($valid_students);
                        
                        $_SESSION['success'] = "Selected students activated!";
                        break;
                        
                    default:
                        $_SESSION['error'] = "Invalid action selected";
                }
                
                // Refresh students list
                $stmt = $db->prepare("SELECT * FROM students WHERE batch_name = ? ORDER BY first_name, last_name");
                $stmt->execute([$batch_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $_SESSION['error'] = "No valid students selected";
            }
        }
        
        header("Location: manage_student.php?batch_id=" . urlencode($batch_id));
        exit();
    }
    
    // Get other batches for transfer dropdown
    $stmt = $db->prepare("SELECT batch_id, batch_name FROM batches WHERE batch_id != ? AND status IN ('upcoming', 'ongoing') ORDER BY start_date ASC");
    $stmt->execute([$batch_id]);
    $available_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
</head>
<?php
include '../header.php';
include '../sidebar.php';
?>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-6xl mx-auto">
            <!-- Back button -->
            <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch_id) ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-4">
                <i class="fas fa-arrow-left mr-2"></i> Back to Batch
            </a>
            
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-gray-800">Manage Students - <?= htmlspecialchars($batch['batch_id']) ?></h1>
                <a href="../batch/student_add.php?batch_id=<?= htmlspecialchars($batch_id) ?>" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                    <i class="fas fa-plus mr-2"></i> Add New Student
                </a>
            </div>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($_SESSION['success']) ?></span>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($_SESSION['error']) ?></span>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Bulk Actions -->
            <form method="POST" id="bulkActionForm" class="bg-white shadow-md rounded-lg p-6 mb-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                
                <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                    <div>
                        <label for="bulkAction" class="block text-sm font-medium text-gray-700 mb-1">Bulk Actions</label>
                        <div class="flex space-x-2">
                            <select id="bulkAction" name="action" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                <option value="">-- Select Action --</option>
                                <option value="transfer">Transfer to Another Batch</option>
                                <option value="drop">Mark as Dropped</option>
                                <option value="activate">Mark as Active</option>
                            </select>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                                Apply
                            </button>
                        </div>
                    </div>
                    
                    <div id="transferFields" class="hidden">
                        <label for="target_batch" class="block text-sm font-medium text-gray-700 mb-1">Select Target Batch</label>
                        <select id="target_batch" name="target_batch" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                            <option value="">-- Select Batch --</option>
                            <?php foreach ($available_batches as $batch_option): ?>
                                <option value="<?= htmlspecialchars($batch_option['batch_id']) ?>">
                                    <?= htmlspecialchars($batch_option['batch_id']) ?> - <?= htmlspecialchars($batch_option['batch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="dropFields" class="hidden">
                        <label for="dropout_reason" class="block text-sm font-medium text-gray-700 mb-1">Dropout Reason</label>
                        <input type="text" id="dropout_reason" name="dropout_reason" placeholder="Optional reason" 
                               class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md"
                               maxlength="255">
                    </div>
                </div>
                
                <!-- Students Table -->
                <div class="mt-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <input type="checkbox" id="selectAll" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <input type="checkbox" name="students[]" value="<?= htmlspecialchars($student['student_id']) ?>" class="student-checkbox h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                <i class="fas fa-user text-gray-500"></i>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($student['student_id']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($student['email'] ?? 'N/A') ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($student['phone_number'] ?? 'N/A') ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs rounded-full 
                                            <?= $student['current_status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                               ($student['current_status'] === 'dropped' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') ?>">
                                            <?= htmlspecialchars(ucfirst($student['current_status'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <a href="student_view.php?id=<?= htmlspecialchars($student['student_id']) ?>" class="text-blue-600 hover:text-blue-900 mr-3" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit_student.php?id=<?= htmlspecialchars($student['student_id']) ?>" class="text-blue-600 hover:text-blue-900 mr-3" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (!empty($student['email'])): ?>
                                            <a href="mailto:<?= htmlspecialchars($student['email']) ?>" class="text-blue-600 hover:text-blue-900" title="Email">
                                                <i class="fas fa-envelope"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Select all checkbox
            $('#selectAll').change(function() {
                $('.student-checkbox').prop('checked', $(this).prop('checked'));
            });
            
            // Show/hide action-specific fields
            $('#bulkAction').change(function() {
                $('#transferFields').addClass('hidden');
                $('#dropFields').addClass('hidden');
                
                if ($(this).val() === 'transfer') {
                    $('#transferFields').removeClass('hidden');
                } else if ($(this).val() === 'drop') {
                    $('#dropFields').removeClass('hidden');
                }
            });
            
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
                    alert('Please select at least one student to transfer');
                    e.preventDefault();
                    return false;
                }
                
                if (action === 'transfer' && !$('#target_batch').val()) {
                    alert('Please select a target batch for transfer');
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>