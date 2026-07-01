<?php
include '../db_connection.php';
session_start();

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$assignment_id = $_GET['id'] ?? 0;

// Get assignment details
$assignment_stmt = $db->prepare("
    SELECT u.*, b.batch_name 
    FROM uploads u
    JOIN batch_uploads bu ON u.id = bu.upload_id
    JOIN batches b ON bu.batch_id = b.batch_id
    WHERE u.id = ? AND u.file_type = 'Assignment'
");
$assignment_stmt->execute([$assignment_id]);
$assignment = $assignment_stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    die("Assignment not found");
}

// Get all submissions for this assignment
$submissions_stmt = $db->prepare("
    SELECT s.*, st.first_name, st.last_name, st.student_id, u.name as graded_by_name
    FROM assignment_submissions s
    JOIN students st ON s.student_id = st.student_id
    LEFT JOIN users u ON s.graded_by = u.id
    WHERE s.upload_id = ?
    ORDER BY s.submitted_at DESC
");
$submissions_stmt->execute([$assignment_id]);
$submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle grading
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission'])) {
    $submission_id = $_POST['submission_id'];
    $grade = $_POST['grade'];
    $feedback = $_POST['feedback'];
    
    $update = $db->prepare("
        UPDATE assignment_submissions 
        SET grade = :grade, 
            feedback = :feedback, 
            status = 'graded',
            graded_by = :graded_by,
            graded_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':grade' => $grade,
        ':feedback' => $feedback,
        ':graded_by' => $_SESSION['user_id'],
        ':id' => $submission_id
    ]);
    
    header("Location: view_submissions.php?id=$assignment_id&message=Graded successfully");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment Submissions - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-gray-50">
<?php include '../header.php'; ?>
<?php include '../sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen">
    <header class="bg-white shadow-sm px-6 py-4">
        <h1 class="text-2xl font-bold text-gray-800">
            <i class="fas fa-tasks text-blue-500 mr-2"></i>
            Assignment Submissions
        </h1>
    </header>

    <div class="p-6">
        <!-- Assignment Info -->
        <div class="bg-white rounded-lg shadow mb-6 p-6">
            <div class="flex justify-between items-start">
                <div>
                    <h2 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($assignment['title']) ?></h2>
                    <p class="text-gray-600 mt-2"><?= htmlspecialchars($assignment['description']) ?></p>
                    <div class="mt-4 flex flex-wrap gap-4">
                        <div class="text-sm">
                            <span class="font-medium">Batch:</span> <?= htmlspecialchars($assignment['batch_name']) ?>
                        </div>
                        <?php if ($assignment['due_date']): ?>
                        <div class="text-sm">
                            <span class="font-medium">Due Date & Time (IST):</span> 
                            <?= date('M j, Y', strtotime($assignment['due_date'])) ?>
                            <?php if (!empty($assignment['due_time'])): ?>
                                <?= date('h:i A', strtotime($assignment['due_time'])) ?> IST
                            <?php else: ?>
                                11:59 PM IST
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-sm">
                            <span class="font-medium">Max Marks:</span> <?= $assignment['max_marks'] ?>
                        </div>
                    </div>
                </div>
                <div class="text-right">
                    <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-medium">
                        <?= count($submissions) ?> Submissions
                    </span>
                </div>
            </div>
        </div>

        <!-- Submissions Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Submission Date (IST)</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Grade</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($submissions)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                    <i class="fas fa-inbox text-3xl mb-2 block"></i>
                                    No submissions yet
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($submissions as $submission): ?>
                                <?php
                                // Check if submission was late
                                $isLate = false;
                                if ($assignment['due_date']) {
                                    $due_datetime = new DateTime($assignment['due_date'], new DateTimeZone('Asia/Kolkata'));
                                    if (!empty($assignment['due_time'])) {
                                        $time_parts = explode(':', $assignment['due_time']);
                                        $due_datetime->setTime($time_parts[0], $time_parts[1], 0);
                                    } else {
                                        $due_datetime->setTime(23, 59, 59);
                                    }
                                    
                                    $submitted_datetime = new DateTime($submission['submitted_at'], new DateTimeZone('Asia/Kolkata'));
                                    
                                    if ($submitted_datetime > $due_datetime) {
                                        $isLate = true;
                                    }
                                }
                                ?>
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                                <i class="fas fa-user text-blue-500"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']) ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?= htmlspecialchars($submission['student_id']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M j, Y H:i', strtotime($submission['submitted_at'])) ?> IST
                                        <?php if ($isLate): ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full bg-red-100 text-red-600">
                                                Late
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status_class = '';
                                        switch($submission['status']) {
                                            case 'submitted':
                                                $status_class = 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'graded':
                                                $status_class = 'bg-green-100 text-green-800';
                                                break;
                                            case 'late':
                                                $status_class = 'bg-yellow-100 text-yellow-800';
                                                break;
                                            default:
                                                $status_class = 'bg-gray-100 text-gray-800';
                                        }
                                        ?>
                                        <span class="px-2 py-1 text-xs rounded-full <?= $status_class ?>">
                                            <?= ucfirst($submission['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($submission['grade'] !== null): ?>
                                            <span class="text-lg font-bold text-gray-900">
                                                <?= $submission['grade'] ?>/<?= $assignment['max_marks'] ?>
                                            </span>
                                            <?php if ($submission['graded_by_name']): ?>
                                                <div class="text-xs text-gray-500">
                                                    By <?= htmlspecialchars($submission['graded_by_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Not graded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="<?= htmlspecialchars($submission['file_path']) ?>" 
                                               target="_blank"
                                               class="text-blue-600 hover:text-blue-900 px-3 py-1 rounded bg-blue-50">
                                                <i class="fas fa-eye mr-1"></i> View
                                            </a>
                                            <a href="<?= htmlspecialchars($submission['file_path']) ?>" 
                                               download
                                               class="text-green-600 hover:text-green-900 px-3 py-1 rounded bg-green-50">
                                                <i class="fas fa-download mr-1"></i> Download
                                            </a>
                                            <button onclick="openGradeModal(<?= $submission['id'] ?>, <?= $submission['grade'] ? 'true' : 'false' ?>, '<?= addslashes($submission['feedback'] ?? '') ?>')"
                                                    class="text-purple-600 hover:text-purple-900 px-3 py-1 rounded bg-purple-50">
                                                <i class="fas fa-edit mr-1"></i> Grade
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Back button -->
        <div class="mt-6">
            <a href="upload_content.php" class="inline-flex items-center text-gray-600 hover:text-gray-900">
                <i class="fas fa-arrow-left mr-2"></i>
                Back to Content Management
            </a>
        </div>
    </div>
</div>

<!-- Grade Modal -->
<div id="gradeModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Grade Submission</h3>
            <form id="gradeForm" method="POST">
                <input type="hidden" name="submission_id" id="modalSubmissionId">
                <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                
                <div class="mb-4">
                    <label for="grade" class="block text-sm font-medium text-gray-700">Grade (out of <?= $assignment['max_marks'] ?>)</label>
                    <input type="number" id="grade" name="grade" step="0.01" min="0" max="<?= $assignment['max_marks'] ?>"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="mb-6">
                    <label for="feedback" class="block text-sm font-medium text-gray-700">Feedback</label>
                    <textarea id="feedback" name="feedback" rows="4"
                              class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeGradeModal()"
                            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" name="grade_submission"
                            class="px-4 py-2 bg-blue-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-blue-700">
                        Save Grade
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openGradeModal(submissionId, isGraded, feedback) {
    document.getElementById('modalSubmissionId').value = submissionId;
    document.getElementById('feedback').value = feedback;
    
    if (isGraded) {
        document.getElementById('grade').value = '<?= $submission['grade'] ?? '' ?>';
    }
    
    document.getElementById('gradeModal').classList.remove('hidden');
}

function closeGradeModal() {
    document.getElementById('gradeModal').classList.add('hidden');
    document.getElementById('gradeForm').reset();
}

// Close modal when clicking outside
document.getElementById('gradeModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeGradeModal();
    }
});

// Show success message if redirected with message
<?php if (isset($_GET['message'])): ?>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: '<?= $_GET['message'] ?>',
    confirmButtonColor: '#4f46e5',
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000
});
<?php endif; ?>
</script>

<?php include '../footer.php'; ?>
</body>
</html>