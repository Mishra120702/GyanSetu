<?php
// doubts.php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

// Get student information
$student_user_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT s.*, b.batch_id, b.batch_name, u.email
    FROM students s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN batches b ON s.batch_name = b.batch_id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

$student_id = $student['student_id'];
$batch_id = $student['batch_id'] ?? null;
$student_name = $student['first_name'] . ' ' . $student['last_name'];

// Handle new doubt submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_doubt'])) {
        $subject = trim($_POST['subject']);
        $question = trim($_POST['question']);
        $priority = $_POST['priority'] ?? 'medium';
        
        // Validate inputs
        if (empty($subject) || empty($question)) {
            $error_message = "Subject and question are required!";
        } else {
            // Handle file upload
            $attachment_path = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/doubts/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = time() . '_' . basename($_FILES['attachment']['name']);
                $file_path = $upload_dir . $file_name;
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'text/plain'];
                
                if (in_array($_FILES['attachment']['type'], $allowed_types) && 
                    $_FILES['attachment']['size'] <= 5 * 1024 * 1024) { // 5MB max
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $file_path)) {
                        $attachment_path = 'uploads/doubts/' . $file_name;
                    }
                }
            }
            
            // Insert doubt into database
            $stmt = $db->prepare("
                INSERT INTO doubts (student_id, batch_id, subject, question, attachment_path, priority, status)
                VALUES (:student_id, :batch_id, :subject, :question, :attachment_path, :priority, 'pending')
            ");
            
            $result = $stmt->execute([
                ':student_id' => $student_id,
                ':batch_id' => $batch_id,
                ':subject' => $subject,
                ':question' => $question,
                ':attachment_path' => $attachment_path,
                ':priority' => $priority
            ]);
            
            if ($result) {
                $_SESSION['success_message'] = "Your doubt has been submitted successfully!";
                header("Location: doubts.php");
                exit();
            } else {
                $error_message = "Failed to submit doubt. Please try again.";
            }
        }
    }
    
    // Handle response submission (if student replies)
    if (isset($_POST['submit_response'])) {
        $doubt_id = $_POST['doubt_id'];
        $response = trim($_POST['response']);
        
        if (!empty($response)) {
            $stmt = $db->prepare("
                INSERT INTO doubt_responses (doubt_id, responded_by, response, is_trainer_response)
                VALUES (:doubt_id, :responded_by, :response, 0)
            ");
            
            $result = $stmt->execute([
                ':doubt_id' => $doubt_id,
                ':responded_by' => $student_user_id,
                ':response' => $response
            ]);
            
            if ($result) {
                // Update doubt status
                $update_stmt = $db->prepare("
                    UPDATE doubts 
                    SET status = 'in_progress', updated_at = NOW() 
                    WHERE id = :doubt_id AND student_id = :student_id
                ");
                $update_stmt->execute([
                    ':doubt_id' => $doubt_id,
                    ':student_id' => $student_id
                ]);
                
                $_SESSION['success_message'] = "Response added successfully!";
                header("Location: doubts.php");
                exit();
            }
        }
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_subject = $_GET['subject'] ?? '';
$filter_priority = $_GET['priority'] ?? 'all';

// Build query for fetching doubts
$query_params = [':student_id' => $student_id];
$where_clauses = ["d.student_id = :student_id"];

if ($filter_status !== 'all') {
    $where_clauses[] = "d.status = :status";
    $query_params[':status'] = $filter_status;
}

if (!empty($filter_subject)) {
    $where_clauses[] = "d.subject LIKE :subject";
    $query_params[':subject'] = "%$filter_subject%";
}

if ($filter_priority !== 'all') {
    $where_clauses[] = "d.priority = :priority";
    $query_params[':priority'] = $filter_priority;
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get doubts with responses
$doubts_query = $db->prepare("
    SELECT d.*, 
           COUNT(dr.id) as response_count,
           GROUP_CONCAT(
               CONCAT(
                   dr.id, '|', 
                   dr.responded_by, '|',
                   dr.response, '|',
                   dr.is_trainer_response, '|',
                   dr.created_at, '|',
                   COALESCE(u.name, 'Student')
               ) SEPARATOR ';;'
           ) as responses_data
    FROM doubts d
    LEFT JOIN doubt_responses dr ON d.id = dr.doubt_id
    LEFT JOIN users u ON dr.responded_by = u.id AND dr.is_trainer_response = 1
    $where_sql
    GROUP BY d.id
    ORDER BY d.created_at DESC
");

$doubts_query->execute($query_params);
$doubts = $doubts_query->fetchAll(PDO::FETCH_ASSOC);

// Process responses data
foreach ($doubts as &$doubt) {
    $responses = [];
    if (!empty($doubt['responses_data'])) {
        $response_items = explode(';;', $doubt['responses_data']);
        foreach ($response_items as $item) {
            $parts = explode('|', $item);
            if (count($parts) >= 6) {
                $responses[] = [
                    'id' => $parts[0],
                    'responded_by' => $parts[1],
                    'response' => $parts[2],
                    'is_trainer_response' => $parts[3],
                    'created_at' => $parts[4],
                    'responder_name' => $parts[5]
                ];
            }
        }
    }
    $doubt['responses'] = $responses;
    unset($doubt['responses_data']);
}
unset($doubt);

// Get distinct subjects for filter
$subjects_query = $db->prepare("
    SELECT DISTINCT subject 
    FROM doubts 
    WHERE student_id = :student_id 
    ORDER BY subject
");
$subjects_query->execute([':student_id' => $student_id]);
$subjects = $subjects_query->fetchAll(PDO::FETCH_COLUMN);
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<!-- Main Content -->
<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-500 ease-in-out bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Header -->
    <header class="bg-white shadow-lg px-6 py-4 flex justify-between items-center sticky top-0 z-30 transition-all duration-500 ease-in-out backdrop-blur-sm bg-white/90">
        <button class="md:hidden text-xl text-gray-600 hover:text-blue-600 transition-all duration-300 transform hover:scale-110" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-question-circle text-blue-500 transition-all duration-700 hover:rotate-360"></i>
            <span class="bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">Ask Your Doubts</span>
        </h1>
        <div class="flex items-center space-x-4">
            <div class="hidden md:block text-sm text-gray-500">
                <?= date('l, F j, Y') ?>
            </div>
            <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center shadow-inner">
                <i class="fas fa-user text-blue-600"></i>
            </div>
        </div>
    </header>

    <div class="p-4 md:p-6">
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span><?= $_SESSION['success_message'] ?></span>
                </div>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span><?= $error_message ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="bg-gradient-to-r from-blue-500 to-purple-600 text-white p-6 rounded-2xl shadow-lg mb-6 transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div class="mb-4 md:mb-0">
                    <h2 class="text-2xl font-bold mb-2">Need Help with Your Studies?</h2>
                    <p class="text-blue-100">Ask questions about sessions, practicals, or any course-related topics</p>
                </div>
                <button onclick="toggleDoubtForm()" 
                        class="px-6 py-3 bg-white text-blue-600 font-semibold rounded-lg hover:bg-blue-50 transition-all duration-300 transform hover:scale-105 flex items-center space-x-2 shadow-lg">
                    <i class="fas fa-plus-circle"></i>
                    <span>Ask New Doubt</span>
                </button>
            </div>
        </div>

        <!-- Doubt Form (Initially Hidden) -->
        <div id="doubtForm" class="hidden bg-white p-6 rounded-2xl shadow-lg mb-6 border border-gray-100">
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-edit text-blue-500 mr-2"></i>
                Submit Your Doubt
            </h3>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">
                            Subject/Topic *
                        </label>
                        <input type="text" id="subject" name="subject" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300"
                               placeholder="e.g., Database Concepts, Java Programming, etc.">
                    </div>
                    
                    <div>
                        <label for="priority" class="block text-sm font-medium text-gray-700 mb-1">
                            Priority
                        </label>
                        <select id="priority" name="priority"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300">
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label for="question" class="block text-sm font-medium text-gray-700 mb-1">
                        Your Question *
                    </label>
                    <textarea id="question" name="question" rows="5" required
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 resize-none"
                              placeholder="Describe your doubt in detail. Be specific about what you need help with..."></textarea>
                    <p class="text-xs text-gray-500 mt-1">Maximum 1000 characters</p>
                </div>
                
                <div>
                    <label for="attachment" class="block text-sm font-medium text-gray-700 mb-1">
                        Attach File (Optional)
                    </label>
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-blue-400 transition-all duration-300">
                        <input type="file" id="attachment" name="attachment" 
                               class="hidden" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt">
                        <div class="space-y-2">
                            <i class="fas fa-cloud-upload-alt text-3xl text-gray-400"></i>
                            <p class="text-sm text-gray-600">Click to upload or drag and drop</p>
                            <p class="text-xs text-gray-500">Images, PDF, DOC, TXT (Max 5MB)</p>
                            <button type="button" onclick="document.getElementById('attachment').click()"
                                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm">
                                Choose File
                            </button>
                        </div>
                        <div id="filePreview" class="mt-2 hidden">
                            <div class="flex items-center justify-between bg-blue-50 p-2 rounded">
                                <div class="flex items-center">
                                    <i class="fas fa-file text-blue-500 mr-2"></i>
                                    <span id="fileName" class="text-sm truncate"></span>
                                </div>
                                <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-700">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3 pt-4 border-t">
                    <button type="button" onclick="toggleDoubtForm()"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" name="submit_doubt"
                            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2">
                        <i class="fas fa-paper-plane"></i>
                        <span>Submit Doubt</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Filters -->
        <div class="bg-white p-4 rounded-xl shadow-md mb-6 border border-gray-100">
            <h3 class="text-lg font-medium text-gray-700 mb-3">Filter Doubts</h3>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in_progress" <?= $filter_status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="answered" <?= $filter_status === 'answered' ? 'selected' : '' ?>>Answered</option>
                        <option value="resolved" <?= $filter_status === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Subject</label>
                    <input type="text" name="subject" value="<?= htmlspecialchars($filter_subject) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                           placeholder="Filter by subject...">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Priority</label>
                    <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        <option value="all" <?= $filter_priority === 'all' ? 'selected' : '' ?>>All Priorities</option>
                        <option value="low" <?= $filter_priority === 'low' ? 'selected' : '' ?>>Low</option>
                        <option value="medium" <?= $filter_priority === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="high" <?= $filter_priority === 'high' ? 'selected' : '' ?>>High</option>
                    </select>
                </div>
                
                <div class="flex items-end">
                    <button type="submit" 
                            class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center space-x-2">
                        <i class="fas fa-filter"></i>
                        <span>Apply Filters</span>
                    </button>
                    <?php if ($filter_status !== 'all' || !empty($filter_subject) || $filter_priority !== 'all'): ?>
                    <a href="doubts.php" 
                       class="ml-2 px-3 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors flex items-center">
                        <i class="fas fa-times"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Doubts List -->
        <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-list-alt text-blue-500 mr-2"></i>
                    Your Doubts (<?= count($doubts) ?>)
                </h3>
                <div class="text-sm text-gray-600">
                    <span class="px-2 py-1 rounded-full bg-blue-100 text-blue-800">Pending: <?= count(array_filter($doubts, fn($d) => $d['status'] === 'pending')) ?></span>
                    <span class="px-2 py-1 rounded-full bg-green-100 text-green-800 ml-2">Resolved: <?= count(array_filter($doubts, fn($d) => $d['status'] === 'resolved')) ?></span>
                </div>
            </div>
            
            <?php if (count($doubts) > 0): ?>
                <div class="space-y-4">
                    <?php foreach ($doubts as $doubt): ?>
                        <div class="border border-gray-200 rounded-xl overflow-hidden hover:shadow-md transition-all duration-300">
                            <!-- Doubt Header -->
                            <div class="bg-gray-50 px-4 py-3 flex justify-between items-center border-b">
                                <div class="flex items-center space-x-3">
                                    <div class="flex flex-col">
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($doubt['subject']) ?></span>
                                        <span class="text-xs text-gray-500">
                                            Asked on <?= date('M j, Y', strtotime($doubt['created_at'])) ?>
                                            <?= date('h:i A', strtotime($doubt['created_at'])) ?>
                                        </span>
                                    </div>
                                    <?php if ($doubt['batch_id']): ?>
                                        <span class="text-xs px-2 py-1 bg-blue-100 text-blue-800 rounded">Batch: <?= htmlspecialchars($doubt['batch_id']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <span class="text-xs px-2 py-1 rounded-full 
                                        <?= $doubt['priority'] === 'high' ? 'bg-red-100 text-red-800' : 
                                           ($doubt['priority'] === 'medium' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800') ?>">
                                        <?= ucfirst($doubt['priority']) ?>
                                    </span>
                                    <span class="text-xs px-2 py-1 rounded-full 
                                        <?= $doubt['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 
                                           ($doubt['status'] === 'answered' ? 'bg-blue-100 text-blue-800' : 
                                           ($doubt['status'] === 'in_progress' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800')) ?>">
                                        <?= ucwords(str_replace('_', ' ', $doubt['status'])) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Doubt Content -->
                            <div class="p-4">
                                <div class="mb-4">
                                    <p class="text-gray-700 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($doubt['question'])) ?></p>
                                    
                                    <?php if ($doubt['attachment_path']): ?>
                                        <div class="mt-3">
                                            <a href="../<?= htmlspecialchars($doubt['attachment_path']) ?>" 
                                               target="_blank"
                                               class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors text-sm">
                                                <i class="fas fa-paperclip mr-1"></i>
                                                View Attachment
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Responses -->
                                <?php if (!empty($doubt['responses'])): ?>
                                    <div class="space-y-3 mb-4">
                                        <h4 class="font-medium text-gray-700 flex items-center">
                                            <i class="fas fa-comments text-green-500 mr-2"></i>
                                            Responses (<?= count($doubt['responses']) ?>)
                                        </h4>
                                        
                                        <?php foreach ($doubt['responses'] as $response): ?>
                                            <div class="pl-4 border-l-2 
                                                <?= $response['is_trainer_response'] ? 'border-green-500 bg-green-50' : 'border-blue-500 bg-blue-50' ?> 
                                                p-3 rounded-r-lg">
                                                <div class="flex justify-between items-start mb-2">
                                                    <div class="flex items-center">
                                                        <div class="w-8 h-8 rounded-full 
                                                            <?= $response['is_trainer_response'] ? 'bg-green-100 text-green-600' : 'bg-blue-100 text-blue-600' ?>
                                                            flex items-center justify-center mr-2">
                                                            <i class="fas fa-user"></i>
                                                        </div>
                                                        <div>
                                                            <span class="font-medium text-sm 
                                                                <?= $response['is_trainer_response'] ? 'text-green-700' : 'text-blue-700' ?>">
                                                                <?= htmlspecialchars($response['responder_name']) ?>
                                                            </span>
                                                            <?php if ($response['is_trainer_response']): ?>
                                                                <span class="ml-2 text-xs px-2 py-0.5 bg-green-200 text-green-800 rounded">Trainer</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <span class="text-xs text-gray-500">
                                                        <?= date('M j, Y h:i A', strtotime($response['created_at'])) ?>
                                                    </span>
                                                </div>
                                                <p class="text-gray-700 text-sm whitespace-pre-wrap"><?= nl2br(htmlspecialchars($response['response'])) ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Add Response Form (if not resolved) -->
                                <?php if ($doubt['status'] !== 'resolved'): ?>
                                    <div class="mt-4 pt-4 border-t">
                                        <form method="POST" class="space-y-3">
                                            <input type="hidden" name="doubt_id" value="<?= $doubt['id'] ?>">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                                    <?= empty($doubt['responses']) ? 'Add more details (optional)' : 'Reply to response' ?>
                                                </label>
                                                <textarea name="response" rows="3"
                                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm resize-none"
                                                          placeholder="Add more details or ask follow-up questions..."></textarea>
                                            </div>
                                            <div class="flex justify-end">
                                                <button type="submit" name="submit_response"
                                                        class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2">
                                                    <i class="fas fa-reply"></i>
                                                    <span>Submit Response</span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Actions -->
                            <?php if ($doubt['status'] !== 'resolved'): ?>
                                <div class="bg-gray-50 px-4 py-3 border-t flex justify-end space-x-3">
                                    <form method="POST" onsubmit="return confirm('Mark this doubt as resolved?');">
                                        <input type="hidden" name="doubt_id" value="<?= $doubt['id'] ?>">
                                        <button type="submit" name="mark_resolved"
                                                class="px-3 py-1 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors flex items-center space-x-1">
                                            <i class="fas fa-check"></i>
                                            <span>Mark as Resolved</span>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-12 text-gray-500">
                    <div class="mb-4">
                        <i class="fas fa-question-circle text-5xl text-gray-300"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-700 mb-2">No doubts found</h3>
                    <p class="text-gray-600 mb-6">You haven't asked any questions yet.</p>
                    <button onclick="toggleDoubtForm()"
                            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2 mx-auto">
                        <i class="fas fa-plus-circle"></i>
                        <span>Ask Your First Question</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- FAQ Section -->
        <div class="mt-8 bg-white p-6 rounded-2xl shadow-lg border border-gray-100">
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-info-circle text-purple-500 mr-2"></i>
                How to Get the Best Help?
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-purple-50 p-4 rounded-lg border border-purple-100">
                    <div class="flex items-center mb-3">
                        <div class="bg-purple-100 text-purple-600 p-2 rounded-lg mr-3">
                            <i class="fas fa-search"></i>
                        </div>
                        <h4 class="font-medium text-gray-800">Be Specific</h4>
                    </div>
                    <p class="text-sm text-gray-600">Mention the exact topic, chapter, or practical you need help with.</p>
                </div>
                
                <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                    <div class="flex items-center mb-3">
                        <div class="bg-blue-100 text-blue-600 p-2 rounded-lg mr-3">
                            <i class="fas fa-code"></i>
                        </div>
                        <h4 class="font-medium text-gray-800">Include Code/Error</h4>
                    </div>
                    <p class="text-sm text-gray-600">Share your code or error messages to get accurate solutions.</p>
                </div>
                
                <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                    <div class="flex items-center mb-3">
                        <div class="bg-green-100 text-green-600 p-2 rounded-lg mr-3">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h4 class="font-medium text-gray-800">Response Time</h4>
                    </div>
                    <p class="text-sm text-gray-600">Trainers typically respond within 24-48 hours during weekdays.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sticky Floating Button (Will be added to dashboard.php) -->
<!-- The floating button code should be added to dashboard.php -->

<style>
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .animate-fade-in {
        animation: fadeIn 0.5s ease-out forwards;
    }
    
    /* Floating Button Styles */
    .floating-doubt-btn {
        position: fixed;
        bottom: 30px;
        left: 30px;
        z-index: 1000;
        animation: float 3s ease-in-out infinite;
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
    
    .floating-doubt-btn:hover {
        animation: none;
        transform: scale(1.1);
    }
    
    @media (max-width: 768px) {
        .floating-doubt-btn {
            bottom: 20px;
            left: 20px;
        }
    }
</style>

<script>
// Toggle doubt form visibility
function toggleDoubtForm() {
    const form = document.getElementById('doubtForm');
    form.classList.toggle('hidden');
    if (!form.classList.contains('hidden')) {
        form.scrollIntoView({ behavior: 'smooth' });
        document.getElementById('subject').focus();
    }
}

// File upload handling
document.getElementById('attachment').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('filePreview').classList.remove('hidden');
    }
});

function clearFile() {
    document.getElementById('attachment').value = '';
    document.getElementById('filePreview').classList.add('hidden');
}

// Character counter for textareas
document.querySelectorAll('textarea').forEach(textarea => {
    const maxLength = textarea.getAttribute('maxlength') || 1000;
    const counter = document.createElement('div');
    counter.className = 'text-xs text-gray-500 text-right mt-1';
    counter.textContent = `0/${maxLength} characters`;
    
    textarea.parentNode.appendChild(counter);
    
    textarea.addEventListener('input', function() {
        const currentLength = this.value.length;
        counter.textContent = `${currentLength}/${maxLength} characters`;
        
        if (currentLength > maxLength * 0.9) {
            counter.classList.add('text-red-500');
        } else {
            counter.classList.remove('text-red-500');
        }
    });
});

// Mark doubt as resolved
function markAsResolved(doubtId) {
    if (confirm('Are you sure you want to mark this doubt as resolved?')) {
        // This would typically be an AJAX call
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `doubt_id=${doubtId}&mark_resolved=1`
        }).then(response => {
            window.location.reload();
        });
    }
}

// Add smooth scrolling to doubt form
document.querySelectorAll('a[href="#doubtForm"]').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        toggleDoubtForm();
    });
});

// Auto-resize textareas
function autoResize(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
}

document.querySelectorAll('textarea').forEach(textarea => {
    textarea.addEventListener('input', function() {
        autoResize(this);
    });
    // Initial resize
    autoResize(textarea);
});
</script>

<?php 
// Handle mark as resolved
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_resolved'])) {
    $doubt_id = $_POST['doubt_id'];
    $stmt = $db->prepare("
        UPDATE doubts 
        SET status = 'resolved', resolved_at = NOW(), updated_at = NOW()
        WHERE id = :doubt_id AND student_id = :student_id
    ");
    $stmt->execute([
        ':doubt_id' => $doubt_id,
        ':student_id' => $student_id
    ]);
    $_SESSION['success_message'] = "Doubt marked as resolved!";
    header("Location: doubts.php");
    exit();
}
?>

<?php include '../footer.php'; ?>