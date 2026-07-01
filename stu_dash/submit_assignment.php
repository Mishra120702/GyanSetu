<?php
session_start();
require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$upload_id = $_GET['id'] ?? 0;

// Get student info
$student_query = $db->prepare("
    SELECT s.*, b.batch_id 
    FROM students s
    LEFT JOIN batches b ON s.batch_name = b.batch_id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get assignment details with due time
$assignment_query = $db->prepare("
    SELECT u.*, bu.batch_id, b.batch_name,
           (SELECT COUNT(*) FROM assignment_submissions WHERE upload_id = u.id AND student_id = :student_id) as has_submitted
    FROM uploads u
    JOIN batch_uploads bu ON u.id = bu.upload_id
    JOIN batches b ON bu.batch_id = b.batch_id
    LEFT JOIN batch_courses bc ON bc.batch_id = bu.batch_id AND bc.course_id = bu.course_id
    WHERE u.id = :upload_id 
    AND bu.batch_id = :batch_id
    AND u.file_type = 'Assignment'
    AND (bu.course_id IS NULL OR bc.id IS NOT NULL)
");
$assignment_query->execute([
    ':upload_id' => $upload_id,
    ':batch_id' => $student['batch_id'],
    ':student_id' => $student['student_id']
]);
$assignment = $assignment_query->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    die("Assignment not found or you don't have access");
}

// Check if submission is allowed (not past due date/time)
$submission_allowed = true;
$submission_status_message = '';

if ($assignment['due_date']) {
    $due_datetime = new DateTime($assignment['due_date'], new DateTimeZone('Asia/Kolkata'));
    
    // Set due time if available, otherwise default to 23:59:59
    if (!empty($assignment['due_time'])) {
        $time_parts = explode(':', $assignment['due_time']);
        $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
    } else {
        $due_datetime->setTime(23, 59, 59);
    }
    
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    
    if ($now > $due_datetime) {
        $submission_allowed = false;
        $submission_status_message = "This assignment is past its due date and time. Submissions are no longer accepted.";
    }
}

// Handle file upload
$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$submission_allowed) {
        $message = $submission_status_message;
    } elseif (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        // Validate file type
        $allowed_types = ['application/pdf'];
        $file_type = $_FILES['submission_file']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            $message = 'Only PDF files are allowed for submissions';
        } else {
            // Create upload directory
            $upload_dir = '../uploads/assignments/submissions/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $file_name = 'submission_' . $student['student_id'] . '_' . $upload_id . '_' . time() . '.pdf';
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $file_path)) {
                try {
                    $db->beginTransaction();
                    
                    // Determine if submission is late
                    $status = 'submitted';
                    if ($assignment['due_date']) {
                        $submission_time = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                        if ($submission_time > $due_datetime) {
                            $status = 'late';
                        }
                    }
                    
                    if ($assignment['has_submitted'] > 0) {
                        // Update existing submission
                        $stmt = $db->prepare("
                            UPDATE assignment_submissions 
                            SET file_path = :file_path, 
                                updated_at = NOW(),
                                status = :status
                            WHERE upload_id = :upload_id 
                            AND student_id = :student_id
                        ");
                        $stmt->execute([
                            ':file_path' => $file_path,
                            ':status' => $status,
                            ':upload_id' => $upload_id,
                            ':student_id' => $student['student_id']
                        ]);
                        $message = 'Submission updated successfully!';
                    } else {
                        // Insert new submission
                        $stmt = $db->prepare("
                            INSERT INTO assignment_submissions (upload_id, student_id, file_path, status)
                            VALUES (:upload_id, :student_id, :file_path, :status)
                        ");
                        $stmt->execute([
                            ':upload_id' => $upload_id,
                            ':student_id' => $student['student_id'],
                            ':file_path' => $file_path,
                            ':status' => $status
                        ]);
                        $message = 'Assignment submitted successfully!';
                    }
                    
                    $db->commit();
                    $success = true;
                    
                    // Redirect to content page
                    header("Location: my_content.php?tab=assignments&message=" . urlencode($message));
                    exit();
                    
                } catch (PDOException $e) {
                    $db->rollBack();
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                    $message = 'Database error: ' . $e->getMessage();
                }
            } else {
                $message = 'File upload failed';
            }
        }
    } else {
        $message = 'No file selected or upload error';
    }
}

// Get existing submission if any
$submission_query = $db->prepare("
    SELECT * FROM assignment_submissions 
    WHERE upload_id = :upload_id 
    AND student_id = :student_id
");
$submission_query->execute([
    ':upload_id' => $upload_id,
    ':student_id' => $student['student_id']
]);
$submission = $submission_query->fetch(PDO::FETCH_ASSOC);
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen">
    <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-upload text-blue-500"></i>
            <span>Submit Assignment</span>
        </h1>
    </header>

    <div class="p-4 md:p-6">
        <div class="max-w-4xl mx-auto">
            <!-- Assignment Details -->
            <div class="bg-white p-6 rounded-xl shadow mb-6">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($assignment['title']) ?></h2>
                        <p class="text-gray-600 mt-2"><?= htmlspecialchars($assignment['description']) ?></p>
                    </div>
                    <div class="text-right">
                        <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-medium">
                            <i class="fas fa-tasks mr-1"></i> Assignment
                        </span>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h4 class="font-semibold text-gray-700 mb-2"><i class="fas fa-users mr-2"></i>Batch Information</h4>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Batch:</span> <?= htmlspecialchars($assignment['batch_name']) ?><br>
                            <span class="font-medium">ID:</span> <?= htmlspecialchars($assignment['batch_id']) ?>
                        </p>
                    </div>
                    
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h4 class="font-semibold text-gray-700 mb-2"><i class="far fa-calendar-alt mr-2"></i>Due Date & Time (IST)</h4>
                        <p class="text-sm text-gray-600">
                            <?php if ($assignment['due_date']): ?>
                                <span class="font-medium">Due:</span> 
                                <?= date('M j, Y', strtotime($assignment['due_date'])) ?>
                                <?php if (!empty($assignment['due_time'])): ?>
                                    <?= date('h:i A', strtotime($assignment['due_time'])) ?> IST
                                <?php else: ?>
                                    11:59 PM IST
                                <?php endif; ?>
                                <br>
                                <?php
                                $due_datetime = new DateTime($assignment['due_date'], new DateTimeZone('Asia/Kolkata'));
                                if (!empty($assignment['due_time'])) {
                                    $time_parts = explode(':', $assignment['due_time']);
                                    $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
                                } else {
                                    $due_datetime->setTime(23, 59, 59);
                                }
                                $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                                $interval = $now->diff($due_datetime);
                                $days_left = $interval->format('%r%a');
                                $hours_left = $interval->h;
                                $minutes_left = $interval->i;
                                
                                if ($days_left < 0) {
                                    echo '<span class="text-red-600 font-medium">Overdue by ' . abs($days_left) . ' days</span>';
                                } elseif ($days_left == 0) {
                                    if ($hours_left > 0) {
                                        echo '<span class="text-orange-600 font-medium">' . $hours_left . 'h ' . $minutes_left . 'm left</span>';
                                    } else {
                                        echo '<span class="text-red-600 font-medium">Less than 1 hour left!</span>';
                                    }
                                } elseif ($days_left <= 3) {
                                    echo '<span class="text-yellow-600 font-medium">' . $days_left . ' days left</span>';
                                } else {
                                    echo '<span class="text-green-600 font-medium">' . $days_left . ' days left</span>';
                                }
                                ?>
                            <?php else: ?>
                                <span class="text-gray-500">No due date specified</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <?php if (!$submission_allowed && !$assignment['has_submitted']): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle text-red-400 text-xl mr-3"></i>
                        <p class="text-red-700 font-medium"><?= htmlspecialchars($submission_status_message) ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($submission): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <h4 class="font-semibold text-green-700 mb-2 flex items-center">
                        <i class="fas fa-check-circle mr-2"></i> Current Submission
                    </h4>
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-600">
                                Submitted: <?= date('M j, Y H:i', strtotime($submission['submitted_at'])) ?> IST<br>
                                Status: 
                                <span class="px-2 py-1 text-xs rounded-full 
                                    <?= $submission['status'] == 'graded' ? 'bg-green-100 text-green-800' :
                                       ($submission['status'] == 'late' ? 'bg-yellow-100 text-yellow-800' :
                                       ($submission['status'] == 'missing' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800')) ?>">
                                    <?= ucfirst($submission['status']) ?>
                                </span>
                                <?php if ($submission['grade']): ?>
                                    <span class="ml-2 font-medium">Grade: <?= $submission['grade'] ?>/<?= $assignment['max_marks'] ?? 100 ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div>
                            <a href="<?= htmlspecialchars($submission['file_path']) ?>" 
                               class="text-blue-600 hover:text-blue-800 font-medium"
                               download>
                                <i class="fas fa-download mr-1"></i> Download
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($message) && !$success): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-red-700"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($message) ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Upload Form -->
            <div class="bg-white p-6 rounded-xl shadow">
                <h3 class="text-lg font-bold text-gray-800 mb-4">Upload Your Submission</h3>
                
                <?php if ($assignment['has_submitted']): ?>
                <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-yellow-500 text-xl mr-3"></i>
                        <div>
                            <p class="text-yellow-800 font-medium">You have already submitted this assignment</p>
                            <p class="text-sm text-yellow-600 mt-1">You can update your submission below. Note that the submission status may change based on due date/time.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-gray-700">Submission File (PDF only)</label>
                        <div id="fileDropArea" class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer hover:border-blue-400 transition-colors">
                            <input type="file" id="submission_file" name="submission_file" required 
                                   accept=".pdf" class="hidden">
                            <div class="flex flex-col items-center justify-center">
                                <i class="fas fa-file-pdf text-4xl text-red-400 mb-2"></i>
                                <p class="text-sm text-gray-600">Click to browse or drag & drop PDF file</p>
                                <p class="text-xs text-gray-500 mt-1">Maximum file size: 10MB</p>
                                <div id="fileNameDisplay" class="mt-3 text-sm font-medium text-blue-600">
                                    <?= $submission ? 'Replace current file' : 'No file selected' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="pt-4 border-t">
                        <div class="flex justify-between">
                            <a href="my_content.php?tab=assignments" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Assignments
                            </a>
                            <button type="submit" 
                                    class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center"
                                    <?= (!$submission_allowed && !$assignment['has_submitted']) ? 'disabled' : '' ?>>
                                <i class="fas fa-upload mr-2"></i>
                                <?= $submission ? 'Update Submission' : 'Submit Assignment' ?>
                            </button>
                        </div>
                    </div>
                </form>
                
                <?php if (!$submission_allowed && !$assignment['has_submitted']): ?>
                <p class="text-xs text-red-500 mt-3 text-center">
                    <i class="fas fa-lock mr-1"></i> Submissions are closed for this assignment
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>

// File upload handling
const fileDropArea = document.getElementById('fileDropArea');
const fileInput = document.getElementById('submission_file');
const fileNameDisplay = document.getElementById('fileNameDisplay');

fileDropArea.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) {
        // Check file size
        const fileSize = fileInput.files[0].size;
        const maxSize = 10 * 1024 * 1024; // 10MB
        
        if (fileSize > maxSize) {
            alert('File size must be less than 10MB');
            fileInput.value = '';
            return;
        }
        
        fileNameDisplay.textContent = fileInput.files[0].name;
        fileDropArea.classList.add('border-blue-400', 'bg-blue-50');
    }
});

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    fileDropArea.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

['dragenter', 'dragover'].forEach(eventName => {
    fileDropArea.addEventListener(eventName, highlight, false);
});

['dragleave', 'drop'].forEach(eventName => {
    fileDropArea.addEventListener(eventName, unhighlight, false);
});

function highlight() {
    fileDropArea.classList.add('border-blue-500', 'bg-blue-50');
}

function unhighlight() {
    fileDropArea.classList.remove('border-blue-500');
    fileDropArea.classList.remove('bg-blue-50');
}

fileDropArea.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    
    if (files.length) {
        // Check if it's a PDF
        if (files[0].type === 'application/pdf') {
            // Check file size
            const fileSize = files[0].size;
            const maxSize = 10 * 1024 * 1024; // 10MB
            
            if (fileSize > maxSize) {
                alert('File size must be less than 10MB');
                return;
            }
            
            fileInput.files = files;
            fileNameDisplay.textContent = files[0].name;
            fileDropArea.classList.add('border-blue-400', 'bg-blue-50');
        } else {
            alert('Only PDF files are allowed');
        }
    }
}
</script>

<?php include '../footer.php'; ?>