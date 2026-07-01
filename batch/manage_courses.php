<?php
require_once '../db_connection.php';
require_once 'sync_curriculum.php';
session_start();

// Enhanced session validation
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../log.php");
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate and sanitize batch_id
$batch_id = isset($_GET['batch_id']) ? trim($_GET['batch_id']) : null;

if (!$batch_id || !preg_match('/^[A-Z0-9_-]+$/i', $batch_id)) {
    $_SESSION['error'] = "Invalid batch ID";
    header("Location: batch_list.php");
    exit();
}

$success_message = '';
$error_message = '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get batch details
    $stmt = $db->prepare("SELECT * FROM batches WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$batch) {
        $_SESSION['error'] = "Batch not found";
        header("Location: batch_list.php");
        exit();
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $error_message = "Invalid CSRF token";
        } elseif (isset($_POST['update_courses'])) {
            $selected_courses = isset($_POST['courses']) && is_array($_POST['courses']) ? array_unique($_POST['courses']) : [];
            
            $db->beginTransaction();
            try {
                // Fetch existing assignments to preserve status if not provided in POST
                $existing_stmt = $db->prepare("SELECT course_id, status FROM batch_courses WHERE batch_id = ?");
                $existing_stmt->execute([$batch_id]);
                $existing_courses = $existing_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                // Delete existing assignments for this batch
                $delStmt = $db->prepare("DELETE FROM batch_courses WHERE batch_id = ?");
                $delStmt->execute([$batch_id]);
                
                // Insert new assignments
                if (!empty($selected_courses)) {
                    $insStmt = $db->prepare("INSERT INTO batch_courses (batch_id, course_id, status) VALUES (?, ?, ?)");
                    foreach ($selected_courses as $course_id) {
                        $status = isset($_POST['status'][$course_id]) ? $_POST['status'][$course_id] : (isset($existing_courses[$course_id]) ? $existing_courses[$course_id] : 'pending');
                        $insStmt->execute([$batch_id, $course_id, $status]);
                        sync_course_curriculum_to_batch($db, $batch_id, $course_id);
                    }
                }
                
                $db->commit();
                $success_message = "Courses updated successfully!";
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Failed to update courses: " . $e->getMessage();
            }
        }
    }
    
    // Get all courses
    $stmt = $db->query("SELECT * FROM courses ORDER BY name");
    $all_courses_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get assigned courses
    $stmt = $db->prepare("SELECT course_id, status FROM batch_courses WHERE batch_id = ? ORDER BY id ASC");
    $stmt->execute([$batch_id]);
    $assigned_courses_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $assigned_courses = [];
    $course_statuses = [];
    foreach ($assigned_courses_raw as $row) {
        $assigned_courses[] = (int)$row['course_id'];
        $course_statuses[$row['course_id']] = $row['status'];
    }

    $assigned_courses_details = [];
    $unassigned_courses = [];
    foreach ($all_courses_raw as $course) {
        $index = array_search($course['id'], $assigned_courses);
        if ($index !== false) {
            $assigned_courses_details[$index] = $course;
        } else {
            $unassigned_courses[] = $course;
        }
    }
    ksort($assigned_courses_details);
    $all_courses = array_merge($assigned_courses_details, $unassigned_courses);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - <?= htmlspecialchars($batch['batch_name']) ?> - ASD Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 font-sans antialiased text-gray-800">

    <?php include '../sidebar.php'; ?>
    <?php include '../header.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-book-open text-blue-500"></i>
                <span>Manage Courses - <?= htmlspecialchars($batch['batch_name']) ?></span>
            </h1>
            <div class="flex items-center space-x-4">
                <a href="batch_view.php?batch_id=<?= urlencode($batch_id) ?>" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 transition text-sm">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Batch
                </a>
            </div>
        </header>
        
        <main class="p-6">
            <?php if ($success_message): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md shadow-sm">
                    <div class="flex">
                        <i class="fas fa-check-circle mt-1 mr-3"></i>
                        <div><?= htmlspecialchars($success_message) ?></div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow-sm">
                    <div class="flex">
                        <i class="fas fa-exclamation-circle mt-1 mr-3"></i>
                        <div><?= htmlspecialchars($error_message) ?></div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="bg-white shadow-md rounded-lg p-6">
                <h2 class="text-xl font-semibold mb-4 text-gray-700 border-b pb-2">Select Courses for this Batch</h2>
                
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    
                    <div class="mb-2 text-sm text-gray-600"><i class="fas fa-info-circle mr-1 text-blue-500"></i> <strong class="text-blue-600">Drag and drop</strong> courses to arrange their display order. Courses will be saved in the order they appear here.</div>
                    <div id="courses-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                        <?php if (count($all_courses) > 0): ?>
                            <?php foreach ($all_courses as $course): ?>
                                <?php 
                                    $is_checked = in_array($course['id'], $assigned_courses) ? 'checked' : ''; 
                                    $current_status = isset($course_statuses[$course['id']]) ? $course_statuses[$course['id']] : 'pending';
                                ?>
                                <div class="border rounded-lg p-4 hover:bg-blue-50 transition flex flex-col cursor-move" onclick="if(event.target.tagName !== 'SELECT' && event.target.tagName !== 'OPTION') document.getElementById('course_<?= $course['id'] ?>').click()">
                                    <div class="flex items-start space-x-3 w-full">
                                        <i class="fas fa-grip-vertical text-gray-400 mt-1"></i>
                                        <div class="mt-1">
                                            <input type="checkbox" name="courses[]" value="<?= $course['id'] ?>" id="course_<?= $course['id'] ?>" class="h-5 w-5 text-blue-600 rounded focus:ring-blue-500" <?= $is_checked ?> onclick="event.stopPropagation()">
                                        </div>
                                        <div class="flex-1">
                                            <label for="course_<?= $course['id'] ?>" class="font-medium text-gray-800 cursor-pointer text-sm block">
                                                <?= htmlspecialchars($course['name']) ?>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="mt-3 pl-8">
                                        <select name="status[<?= $course['id'] ?>]" class="w-full text-xs border border-gray-300 rounded p-1" onclick="event.stopPropagation()">
                                            <option value="pending" <?= $current_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="in_progress" <?= $current_status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                            <option value="completed" <?= $current_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                                        </select>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-span-full text-center py-6 text-gray-500 bg-gray-50 rounded-lg">
                                <i class="fas fa-book mb-2 text-3xl"></i>
                                <p>No courses found in the system. Please add courses first.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex justify-end pt-4 border-t border-gray-100">
                        <button type="submit" name="update_courses" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition shadow-sm font-medium">
                            <i class="fas fa-save mr-2"></i> Save Courses
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>

        document.addEventListener("DOMContentLoaded", function() {
            const coursesGrid = document.getElementById('courses-grid');
            if (coursesGrid) {
                new Sortable(coursesGrid, {
                    animation: 150,
                    ghostClass: 'bg-blue-100',
                });
            }
        });
    </script>
</body>
</html>
