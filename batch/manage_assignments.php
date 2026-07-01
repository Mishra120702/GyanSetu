<?php
require_once '../db_connection.php';
session_start();

// Session validation
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate and sanitize batch_id
$batch_id = isset($_GET['batch_id']) ? trim($_GET['batch_id']) : null;

if (!$batch_id) {
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

    // Get session success/error if set
    if (isset($_SESSION['success_message'])) {
        $success_message = $_SESSION['success_message'];
        unset($_SESSION['success_message']);
    }
    if (isset($_SESSION['error_message'])) {
        $error_message = $_SESSION['error_message'];
        unset($_SESSION['error_message']);
    }
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error_message'] = "Invalid CSRF token";
            header("Location: manage_assignments.php?batch_id=" . urlencode($batch_id));
            exit;
        } elseif (isset($_POST['add_assignment'])) {
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $max_marks = floatval($_POST['max_marks']);
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $due_time = !empty($_POST['due_time']) ? $_POST['due_time'] : null;
            $course_id = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
            
            $file_path = "";
            $upload_ok = true;
            
            if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/assignments/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $original_filename = basename($_FILES['assignment_file']['name']);
                $clean_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_filename);
                $file_name = 'assignment_' . $batch_id . '_' . time() . '_' . $clean_filename;
                $file_path = $upload_dir . $file_name;
                
                if (!move_uploaded_file($_FILES['assignment_file']['tmp_name'], $file_path)) {
                    $_SESSION['error_message'] = "Failed to upload file.";
                    $upload_ok = false;
                }
            } else {
                $_SESSION['error_message'] = "Assignment file is required.";
                $upload_ok = false;
            }
            
            if ($upload_ok) {
                $db->beginTransaction();
                try {
                    // 1. Insert into uploads table
                    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] === 'specific' ? 'specific' : 'all';
                    $selected_student_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? $_POST['student_ids'] : [];

                    $stmt_ins = $db->prepare("
                        INSERT INTO uploads (title, description, file_path, file_type, due_date, due_time, max_marks, uploaded_by, course_id, assigned_to, uploaded_at)
                        VALUES (:title, :description, :file_path, 'Assignment', :due_date, :due_time, :max_marks, :uploaded_by, :course_id, :assigned_to, NOW())
                    ");
                    $stmt_ins->execute([
                        ':title' => $title,
                        ':description' => $description,
                        ':file_path' => $file_path,
                        ':due_date' => $due_date,
                        ':due_time' => $due_time,
                        ':max_marks' => $max_marks,
                        ':uploaded_by' => $_SESSION['user_id'],
                        ':course_id' => $course_id,
                        ':assigned_to' => $assigned_to
                    ]);
                    
                    $upload_id = $db->lastInsertId();
                    
                    // 1.1 Insert into upload_students if target is specific
                    if ($assigned_to === 'specific' && !empty($selected_student_ids)) {
                        $stmt_us = $db->prepare("INSERT INTO upload_students (upload_id, student_id) VALUES (?, ?)");
                        foreach ($selected_student_ids as $sid) {
                            $stmt_us->execute([$upload_id, $sid]);
                        }
                    }
                    
                    // 2. Insert into batch_uploads table for all batches that can see this course
                    if ($course_id) {
                        $stmt_vis = $db->prepare("SELECT batch_id FROM course_content_visibility WHERE course_id = ?");
                        $stmt_vis->execute([$course_id]);
                        $batches_with_visibility = $stmt_vis->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!in_array($batch_id, $batches_with_visibility)) {
                            $batches_with_visibility[] = $batch_id;
                        }
                        
                        $stmt_bu = $db->prepare("
                            INSERT INTO batch_uploads (upload_id, batch_id, course_id)
                            VALUES (?, ?, ?)
                        ");
                        foreach ($batches_with_visibility as $bid) {
                            $stmt_bu->execute([$upload_id, $bid, $course_id]);
                        }
                    } else {
                        $stmt_bu = $db->prepare("
                            INSERT INTO batch_uploads (upload_id, batch_id, course_id)
                            VALUES (?, ?, ?)
                        ");
                        $stmt_bu->execute([$upload_id, $batch_id, null]);
                    }
                    
                    $db->commit();
                    $_SESSION['success_message'] = "Assignment created successfully!";
                } catch (Exception $e) {
                    $db->rollBack();
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
                }
            }
            header("Location: manage_assignments.php?batch_id=" . urlencode($batch_id));
            exit;
        } elseif (isset($_POST['delete_assignment'])) {
            $upload_id = intval($_POST['upload_id']);
            
            // Get file path to delete from storage
            $stmt_file = $db->prepare("SELECT file_path FROM uploads WHERE id = ?");
            $stmt_file->execute([$upload_id]);
            $file = $stmt_file->fetch(PDO::FETCH_ASSOC);
            
            $db->beginTransaction();
            try {
                // Delete submission files from storage
                $stmt_sub_files = $db->prepare("SELECT file_path FROM assignment_submissions WHERE upload_id = ?");
                $stmt_sub_files->execute([$upload_id]);
                $submission_files = $stmt_sub_files->fetchAll(PDO::FETCH_ASSOC);
                foreach ($submission_files as $sub_file) {
                    if (!empty($sub_file['file_path']) && file_exists($sub_file['file_path'])) {
                        unlink($sub_file['file_path']);
                    }
                }
                
                // Delete submissions from database
                $stmt_del_subs = $db->prepare("DELETE FROM assignment_submissions WHERE upload_id = ?");
                $stmt_del_subs->execute([$upload_id]);
                
                // Delete from batch_uploads
                $stmt_del_bu = $db->prepare("DELETE FROM batch_uploads WHERE upload_id = ?");
                $stmt_del_bu->execute([$upload_id]);
                
                // Delete from uploads
                $stmt_del_u = $db->prepare("DELETE FROM uploads WHERE id = ?");
                $stmt_del_u->execute([$upload_id]);
                
                $db->commit();
                
                // Delete assignment file from storage
                if ($file && !empty($file['file_path']) && file_exists($file['file_path'])) {
                    unlink($file['file_path']);
                }
                
                $_SESSION['success_message'] = "Assignment deleted successfully!";
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error_message'] = "Failed to delete assignment: " . $e->getMessage();
            }
            header("Location: manage_assignments.php?batch_id=" . urlencode($batch_id));
            exit;
        }
    }
    
    // Get assigned courses for dropdown
    $stmt_c = $db->prepare("
        SELECT c.id, c.name 
        FROM batch_courses bc 
        JOIN courses c ON bc.course_id = c.id 
        WHERE bc.batch_id = ? 
        ORDER BY c.name
    ");
    $stmt_c->execute([$batch_id]);
    $batch_courses = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

    // Get all active students in this batch for the targeting selection
    $stmt_s = $db->prepare("
        SELECT student_id, first_name, last_name, email 
        FROM students 
        WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?)
          AND current_status = 'active'
        ORDER BY first_name, last_name
    ");
    $stmt_s->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
    $batch_students = $stmt_s->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all assignments for this batch (using DISTINCT to prevent any double-listing)
    $stmt_a = $db->prepare("
        SELECT DISTINCT u.*, c.name as course_name,
               (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id) as submissions_count,
               (SELECT COUNT(*) FROM upload_students WHERE upload_id = u.id) as targeted_students_count
        FROM uploads u
        JOIN batch_uploads bu ON u.id = bu.upload_id
        LEFT JOIN courses c ON bu.course_id = c.id
        WHERE bu.batch_id = ? AND u.file_type = 'Assignment'
        ORDER BY u.uploaded_at DESC
    ");
    $stmt_a->execute([$batch_id]);
    $assignments = $stmt_a->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Assignments - <?= htmlspecialchars($batch['batch_name']) ?> - ASD Admin</title>
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
                <i class="fas fa-tasks text-blue-500"></i>
                <span>Assignments - <?= htmlspecialchars($batch['batch_name']) ?></span>
            </h1>
            <div class="flex items-center space-x-4">
                <a href="batch_view.php?batch_id=<?= urlencode($batch_id) ?>" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 transition text-sm font-medium">
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
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Create Assignment Panel -->
                <div class="bg-white shadow-md rounded-lg p-6 h-fit">
                    <h2 class="text-xl font-bold mb-4 text-gray-700 border-b pb-2 flex items-center">
                        <i class="fas fa-plus-circle text-blue-500 mr-2"></i> Create Assignment
                    </h2>
                    
                    <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        
                        <div>
                            <label for="title" class="block text-sm font-semibold text-gray-600 mb-1">Title *</label>
                            <input type="text" name="title" id="title" required class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring focus:border-blue-500 text-sm">
                        </div>
                        
                        <div>
                            <label for="description" class="block text-sm font-semibold text-gray-600 mb-1">Description</label>
                            <textarea name="description" id="description" rows="3" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring focus:border-blue-500 text-sm"></textarea>
                        </div>

                        <div>
                            <label for="course_id" class="block text-sm font-semibold text-gray-600 mb-1">Course Link (Optional)</label>
                            <select name="course_id" id="course_id" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring focus:border-blue-500 text-sm bg-white">
                                <option value="">-- No specific course link --</option>
                                <?php foreach ($batch_courses as $bc): ?>
                                    <option value="<?= $bc['id'] ?>"><?= htmlspecialchars($bc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="max_marks" class="block text-sm font-semibold text-gray-600 mb-1">Max Marks *</label>
                                <input type="number" name="max_marks" id="max_marks" step="0.5" value="100" required class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring focus:border-blue-500 text-sm">
                            </div>
                            
                            <div>
                                <label for="due_date" class="block text-sm font-semibold text-gray-600 mb-1">Due Date</label>
                                <input type="date" name="due_date" id="due_date" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring focus:border-blue-500 text-sm">
                            </div>
                        </div>

                        <div>
                            <label for="due_time" class="block text-sm font-semibold text-gray-600 mb-1">Due Time</label>
                            <input type="time" name="due_time" id="due_time" value="23:59" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring focus:border-blue-500 text-sm">
                        </div>
                        
                        <div>
                            <label for="assignment_file" class="block text-sm font-semibold text-gray-600 mb-1">Assignment File (PDF only) *</label>
                            <input type="file" name="assignment_file" id="assignment_file" accept=".pdf" required class="w-full px-2 py-1 border rounded-md focus:outline-none text-sm bg-gray-50">
                            <p class="text-xs text-gray-400 mt-1">Upload the assignment question sheet.</p>
                        </div>

                        <!-- Assign To Selection -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">Assign To *</label>
                            <div class="flex items-center space-x-4 mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="assigned_to" value="all" checked onchange="toggleStudentList(this.value)" class="text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">All Students in Batch</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="assigned_to" value="specific" onchange="toggleStudentList(this.value)" class="text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">Specific Students</span>
                                </label>
                            </div>
                        </div>

                        <!-- Specific Students Checklist -->
                        <div id="student_list_container" class="hidden border rounded-md p-3 bg-gray-50 max-h-48 overflow-y-auto">
                            <div class="flex justify-between items-center mb-2 pb-1 border-b">
                                <span class="text-xs font-semibold text-gray-600">Select Students</span>
                                <div class="space-x-2">
                                    <button type="button" onclick="toggleAllStudents(true)" class="text-xs text-blue-600 hover:underline">Select All</button>
                                    <button type="button" onclick="toggleAllStudents(false)" class="text-xs text-red-600 hover:underline">Clear All</button>
                                </div>
                            </div>
                            <?php if (count($batch_students) > 0): ?>
                                <div class="space-y-1">
                                    <?php foreach ($batch_students as $student): ?>
                                        <label class="flex items-center text-sm">
                                            <input type="checkbox" name="student_ids[]" value="<?= htmlspecialchars($student['student_id']) ?>" class="student-checkbox text-blue-600 focus:ring-blue-500 rounded">
                                            <span class="ml-2 text-gray-700"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?> <span class="text-xs text-gray-400">(<?= htmlspecialchars($student['student_id']) ?>)</span></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-xs text-gray-500 italic">No active students in this batch.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="pt-2">
                            <button type="submit" name="add_assignment" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-md transition shadow-md flex items-center justify-center text-sm">
                                <i class="fas fa-save mr-2"></i> Save & Publish Assignment
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Existing Assignments List -->
                <div class="bg-white shadow-md rounded-lg p-6 lg:col-span-2">
                    <h2 class="text-xl font-bold mb-4 text-gray-700 border-b pb-2 flex items-center">
                        <i class="fas fa-list-ul text-blue-500 mr-2"></i> Published Assignments
                    </h2>
                    
                    <?php if (count($assignments) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Assignment Details</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Due Date</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Submissions</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($assignments as $a): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-4 py-4">
                                                <div class="text-sm font-bold text-gray-800"><?= htmlspecialchars($a['title']) ?></div>
                                                <?php if (!empty($a['description'])): ?>
                                                    <div class="text-xs text-gray-500 mt-1 truncate max-w-xs"><?= htmlspecialchars($a['description']) ?></div>
                                                <?php endif; ?>
                                                <div class="flex items-center space-x-2 mt-2">
                                                    <?php if ($a['course_name']): ?>
                                                        <span class="inline-block px-2 py-0.5 text-xs bg-indigo-50 text-indigo-700 rounded-md border border-indigo-100">
                                                            <i class="fas fa-book mr-1"></i> <?= htmlspecialchars($a['course_name']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="inline-block px-2 py-0.5 text-xs bg-gray-100 text-gray-700 rounded-md border border-gray-200">
                                                        Marks: <?= $a['max_marks'] ?>
                                                    </span>
                                                    <span class="inline-block px-2 py-0.5 text-xs rounded-md <?= $a['assigned_to'] === 'specific' ? 'bg-yellow-50 text-yellow-700 border border-yellow-100' : 'bg-green-50 text-green-700 border border-green-100' ?>">
                                                        <i class="fas <?= $a['assigned_to'] === 'specific' ? 'fa-user-tag' : 'fa-users' ?> mr-1"></i> 
                                                        <?= $a['assigned_to'] === 'specific' ? htmlspecialchars($a['targeted_students_count']) . ' Students' : 'All Students' ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 whitespace-nowrap text-xs text-gray-600">
                                                <?php if ($a['due_date']): ?>
                                                    <div><i class="far fa-calendar mr-1"></i> <?= date('M j, Y', strtotime($a['due_date'])) ?></div>
                                                    <?php if ($a['due_time']): ?>
                                                        <div class="mt-0.5 text-gray-400"><i class="far fa-clock mr-1"></i> <?= date('h:i A', strtotime($a['due_time'])) ?></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-gray-400">No due date</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-4 whitespace-nowrap text-center">
                                                <a href="../content/view_submissions.php?id=<?= $a['id'] ?>" class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 hover:bg-blue-100 transition rounded-full text-xs font-bold">
                                                    <i class="fas fa-file-invoice mr-1.5"></i> <?= $a['submissions_count'] ?> submissions
                                                </a>
                                            </td>
                                            <td class="px-4 py-4 whitespace-nowrap text-center text-sm font-medium">
                                                <div class="flex items-center justify-center space-x-2">
                                                    <a href="<?= htmlspecialchars($a['file_path']) ?>" target="_blank" class="p-1.5 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition" title="View Question Sheet">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this assignment? All submissions and grades associated with it will be permanently deleted!');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="upload_id" value="<?= $a['id'] ?>">
                                                        <button type="submit" name="delete_assignment" class="p-1.5 bg-red-100 text-red-700 rounded hover:bg-red-200 transition" title="Delete Assignment">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-500 bg-gray-50 rounded-lg">
                            <i class="fas fa-tasks mb-3 text-4xl text-gray-300 block"></i>
                            <p class="font-medium text-gray-600 text-lg">No assignments published</p>
                            <p class="text-sm">Use the form on the left to add a new assignment for this batch.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleStudentList(val) {
            const container = document.getElementById('student_list_container');
            const checkboxes = document.querySelectorAll('.student-checkbox');
            if (val === 'specific') {
                container.classList.remove('hidden');
            } else {
                container.classList.add('hidden');
                // Uncheck all when switching back to all
                checkboxes.forEach(cb => cb.checked = false);
            }
        }
        function toggleAllStudents(checked) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = checked);
        }
    </script>
</body>
</html>
