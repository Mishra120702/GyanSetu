<?php

require_once '../db_connection.php';

// Check if student is logged in
session_start();
if (!isset($_SESSION['user_id'])|| $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit;
}

try {
    $student_id = $_SESSION['user_id'];
    
    // Get student information and all batches
    $student_query = $db->prepare("
        SELECT s.*, 
               c.name as course_name
        FROM students s
        LEFT JOIN courses c ON s.course = c.id
        WHERE s.user_id = :user_id
    ");
    $student_query->execute([':user_id' => $student_id]);
    $student = $student_query->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        die("Student information not found");
    }

    $student_name = $student['first_name'] . ' ' . $student['last_name'];
    $success = '';
    $error = '';

    // Get all batches for the student (batch_name, batch_name_2, batch_name_3)
    $current_batches = [];
    $batch_fields = ['batch_name', 'batch_name_2', 'batch_name_3'];
    
    foreach ($batch_fields as $batch_field) {
        if (!empty($student[$batch_field])) {
            $batch_query = $db->prepare("
                SELECT * 
                FROM batches 
                WHERE batch_id = :batch_id
            ");
            $batch_query->execute([':batch_id' => $student[$batch_field]]);
            $batch_data = $batch_query->fetch(PDO::FETCH_ASSOC);
            
            if ($batch_data) {
                $current_batches[] = [
                    'field_name' => $batch_field,
                    'batch_name' => $student[$batch_field],
                    'batch_data' => $batch_data
                ];
            }
        }
    }

    // Get selected batch from URL or default to first
    $selected_batch_index = isset($_GET['batch_index']) ? intval($_GET['batch_index']) : 0;
    if ($selected_batch_index >= count($current_batches)) {
        $selected_batch_index = 0;
    }

    $selected_batch = null;
    $batch_feedback_history = [];
    $selected_batch_id = null;

    if (!empty($current_batches) && isset($current_batches[$selected_batch_index])) {
        $selected_batch = $current_batches[$selected_batch_index]['batch_data'];
        $selected_batch_id = $selected_batch['batch_id'];

        // Handle feedback submission for selected batch
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
            // Validate required fields
            $required_fields = ['class_rating', 'assignment_understanding', 'practical_understanding', 'satisfied', 'regular_in_class'];
            $valid = true;
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    $valid = false;
                    $error = "Please fill in all required fields marked with *";
                    break;
                }
            }
            
            if ($valid) {
                // Convert 'Yes'/'No' to 1/0 for satisfied field
                $satisfied_value = ($_POST['satisfied'] === 'Yes') ? 1 : 0;
                
                $stmt = $db->prepare("INSERT INTO feedback (date, student_name, email, batch_id, course_name, 
                                     class_rating, assignment_understanding, practical_understanding, satisfied, 
                                     suggestions, feedback_text, is_regular) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $result = $stmt->execute([
                    date('Y-m-d'),
                    $student_name,
                    $student['email'],
                    $selected_batch_id,
                    $student['course_name'] ?? 'Unknown Course',
                    intval($_POST['class_rating']),
                    intval($_POST['assignment_understanding']),
                    intval($_POST['practical_understanding']),
                    $satisfied_value,
                    $_POST['suggestions'] ?? '',
                    $_POST['feedback_text'] ?? '',
                    $_POST['regular_in_class']
                ]);
                
                if ($result) {
                    $success = "Feedback submitted successfully for " . htmlspecialchars($selected_batch['batch_name']) . "!";
                    // Clear form data after successful submission
                    $_POST = array();
                } else {
                    $error = "Failed to submit feedback. Please try again.";
                }
            }
        }

        // Get student's feedback history for selected batch
        $feedback_query = $db->prepare("
            SELECT * FROM feedback 
            WHERE student_name = :student_name AND batch_id = :batch_id
            ORDER BY date DESC
        ");
        $feedback_query->execute([
            ':student_name' => $student_name, 
            ':batch_id' => $selected_batch_id
        ]);
        $batch_feedback_history = $feedback_query->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get aggregated feedback stats for all batches
    $all_feedback_stats = [];
    foreach ($current_batches as $index => $batch_info) {
        $batch_id = $batch_info['batch_name'];
        $stats_query = $db->prepare("
            SELECT 
                COUNT(*) as total_feedback,
                AVG(class_rating) as avg_class_rating,
                AVG(assignment_understanding) as avg_assignment_rating,
                AVG(practical_understanding) as avg_practical_rating,
                SUM(CASE WHEN satisfied = 1 THEN 1 ELSE 0 END) as satisfied_count,
                MAX(date) as last_feedback_date
            FROM feedback 
            WHERE student_name = :student_name AND batch_id = :batch_id
        ");
        $stats_query->execute([
            ':student_name' => $student_name, 
            ':batch_id' => $batch_id
        ]);
        $stats = $stats_query->fetch(PDO::FETCH_ASSOC);
        
        $all_feedback_stats[$batch_id] = $stats ?: [
            'total_feedback' => 0,
            'avg_class_rating' => 0,
            'avg_assignment_rating' => 0,
            'avg_practical_rating' => 0,
            'satisfied_count' => 0,
            'last_feedback_date' => null
        ];
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <!-- Mobile Header (Visible only on mobile) -->
    <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden">
        <!-- Mobile Menu Button -->
        <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
        
        <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-comment-dots text-indigo-600 text-sm"></i>
            </div>
            <span>Student Feedback</span>
        </h1>
        
        <div class="flex items-center space-x-3">
            <!-- User Profile/Indicator -->
            <div class="relative">
                <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-graduate text-indigo-600"></i>
                </div>
            </div>
        </div>
    </header>

    <!-- Desktop Header (Hidden on mobile) -->
    <header class="hidden md:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
        <div class="flex-1"></div> <!-- Spacer for centering -->
        
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-comment-dots text-indigo-600 text-xl"></i>
            </div>
            <span>Student Feedback</span>
        </h1>
        
        <div class="flex-1 flex justify-end items-center space-x-4">
            <div class="animate-pulse bg-indigo-100 rounded-full p-2">
                <i class="fas fa-user-graduate text-indigo-600"></i>
            </div>
        </div>
    </header>

    <!-- Mobile Navigation Menu -->
    <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden">
        <!-- ... (same mobile menu content as before) ... -->
    </div>

    <div class="p-4 md:p-6 bg-gray-50 min-h-screen">
        <!-- Student Information Card -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 rounded-xl shadow mb-6 border border-blue-200">
            <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-user-graduate mr-2 text-blue-500"></i>
                Student Information
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="font-medium text-gray-600">Name:</span>
                    <span class="text-gray-800"><?= htmlspecialchars($student_name) ?></span>
                </div>
                <div>
                    <span class="font-medium text-gray-600">Total Batches:</span>
                    <span class="text-gray-800"><?= count($current_batches) ?></span>
                </div>
                <div>
                    <span class="font-medium text-gray-600">Course:</span>
                    <span class="text-gray-800"><?= htmlspecialchars($student['course_name'] ?? 'N/A') ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 animate-fade-in-up">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 animate-fade-in-up">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Batch Selection Tabs -->
        <?php if (count($current_batches) > 1): ?>
        <div class="mb-6 glass-card p-4">
            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-exchange-alt text-indigo-600 mr-2"></i>
                Select Batch for Feedback
            </h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($current_batches as $index => $batch_info): 
                    $batch_label = "Batch ";
                    if ($batch_info['field_name'] == 'batch_name') $batch_label .= "1";
                    elseif ($batch_info['field_name'] == 'batch_name_2') $batch_label .= "2";
                    elseif ($batch_info['field_name'] == 'batch_name_3') $batch_label .= "3";
                    
                    $stats = $all_feedback_stats[$batch_info['batch_name']];
                    $total_feedback = $stats['total_feedback'];
                ?>
                    <a href="?batch_index=<?= $index ?>" 
                       class="px-4 py-3 rounded-lg transition-all duration-300 flex items-center space-x-3 <?= $selected_batch_index == $index ? 'bg-indigo-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <div class="flex items-center">
                            <div class="mr-2">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <div>
                                <div class="font-medium"><?= $batch_label ?>: <?= htmlspecialchars($batch_info['batch_data']['batch_name']) ?></div>
                                <div class="text-xs opacity-80 mt-1">
                                    <?= $total_feedback ?> feedback <?= $selected_batch_index == $index ? 'submitted' : '' ?>
                                </div>
                            </div>
                            <?php if ($selected_batch_index == $index): ?>
                                <i class="fas fa-check ml-2"></i>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($selected_batch): ?>
                <p class="text-sm text-gray-500 mt-2">
                    Currently viewing: <span class="font-semibold text-indigo-600">
                        <?php 
                        $current_label = "Batch ";
                        if ($current_batches[$selected_batch_index]['field_name'] == 'batch_name') $current_label .= "1";
                        elseif ($current_batches[$selected_batch_index]['field_name'] == 'batch_name_2') $current_label .= "2";
                        elseif ($current_batches[$selected_batch_index]['field_name'] == 'batch_name_3') $current_label .= "3";
                        echo $current_label . " - " . htmlspecialchars($selected_batch['batch_name']);
                        ?>
                    </span>
                    <span class="ml-2 text-xs bg-indigo-100 text-indigo-800 px-2 py-1 rounded-full">
                        <?= $all_feedback_stats[$selected_batch_id]['total_feedback'] ?> feedback submissions
                    </span>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Batch Overview Cards -->
        <?php if ($selected_batch): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
            <!-- Batch Info Card -->
            <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-3 rounded-lg mr-3">
                            <i class="fas fa-layer-group text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-800">Current Batch</h3>
                            <p class="text-sm text-gray-500">Selected for feedback</p>
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs rounded-full <?= $selected_batch['status'] === 'ongoing' ? 'bg-blue-100 text-blue-800' : 
                           ($selected_batch['status'] === 'upcoming' ? 'bg-yellow-100 text-yellow-800' : 
                           ($selected_batch['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')) ?>">
                        <?= ucfirst($selected_batch['status']) ?>
                    </span>
                </div>
                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Batch ID</span>
                        <span class="font-bold text-blue-600"><?= htmlspecialchars($selected_batch['batch_id']) ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Course</span>
                        <span class="font-bold text-blue-600"><?= htmlspecialchars($selected_batch['batch_name']) ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Schedule</span>
                        <span class="font-bold text-blue-600"><?= htmlspecialchars($selected_batch['time_slot']) ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Mode</span>
                        <span class="font-bold text-blue-600"><?= ucfirst($selected_batch['mode']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Feedback Stats Card -->
            <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <div class="bg-green-100 p-3 rounded-lg mr-3">
                            <i class="fas fa-chart-bar text-green-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-800">Feedback Summary</h3>
                            <p class="text-sm text-gray-500">For this batch</p>
                        </div>
                    </div>
                </div>
                <div class="space-y-3">
                    <?php 
                    $stats = $all_feedback_stats[$selected_batch_id];
                    $avg_class = round($stats['avg_class_rating'] * 20); // Convert to percentage
                    $avg_assignment = round($stats['avg_assignment_rating'] * 20);
                    $avg_practical = round($stats['avg_practical_rating'] * 20);
                    $satisfaction_rate = $stats['total_feedback'] > 0 ? 
                        round(($stats['satisfied_count'] / $stats['total_feedback']) * 100) : 0;
                    ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-600">Avg Class Rating</span>
                            <span class="font-bold text-green-600"><?= $avg_class ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full transition-all duration-1000 ease-out" 
                                 style="width: <?= $avg_class ?>%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-600">Avg Assignment Rating</span>
                            <span class="font-bold text-green-600"><?= $avg_assignment ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full transition-all duration-1000 ease-out" 
                                 style="width: <?= $avg_assignment ?>%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-600">Satisfaction Rate</span>
                            <span class="font-bold text-green-600"><?= $satisfaction_rate ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full transition-all duration-1000 ease-out" 
                                 style="width: <?= $satisfaction_rate ?>%"></div>
                        </div>
                    </div>
                </div>
                <div class="text-xs text-gray-500 mt-3">
                    <span>Based on <?= $stats['total_feedback'] ?> feedback submissions</span>
                    <?php if ($stats['last_feedback_date']): ?>
                        <span class="ml-2">Last: <?= date('M j, Y', strtotime($stats['last_feedback_date'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Compare Batches Card -->
            <?php if (count($current_batches) > 1): ?>
            <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <div class="bg-purple-100 p-3 rounded-lg mr-3">
                            <i class="fas fa-exchange-alt text-purple-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-800">Compare Batches</h3>
                            <p class="text-sm text-gray-500">Feedback comparison</p>
                        </div>
                    </div>
                </div>
                <div class="space-y-2">
                    <?php 
                    $current_stats = $all_feedback_stats[$selected_batch_id];
                    $current_avg = ($current_stats['avg_class_rating'] + $current_stats['avg_assignment_rating'] + $current_stats['avg_practical_rating']) / 3;
                    ?>
                    
                    <?php foreach ($current_batches as $index => $batch_info): 
                        if ($index == $selected_batch_index) continue;
                        
                        $batch_id = $batch_info['batch_name'];
                        $batch_stats = $all_feedback_stats[$batch_id];
                        $batch_avg = ($batch_stats['avg_class_rating'] + $batch_stats['avg_assignment_rating'] + $batch_stats['avg_practical_rating']) / 3;
                        $diff = $current_avg - $batch_avg;
                        $batch_label = "Batch ";
                        if ($batch_info['field_name'] == 'batch_name') $batch_label .= "1";
                        elseif ($batch_info['field_name'] == 'batch_name_2') $batch_label .= "2";
                        elseif ($batch_info['field_name'] == 'batch_name_3') $batch_label .= "3";
                    ?>
                    <div class="flex justify-between items-center p-2 hover:bg-gray-50 rounded-lg transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-layer-group text-gray-400 mr-2"></i>
                            <span class="text-sm font-medium text-gray-700"><?= $batch_label ?></span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm font-bold <?= $diff >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                <?= $diff >= 0 ? '+' : '' ?><?= round($diff, 1) ?>
                            </span>
                            <a href="?batch_index=<?= $index ?>" class="text-xs text-indigo-600 hover:text-indigo-800 transition-colors">
                                View
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-xs text-gray-500 mt-3 text-center">
                    <i class="fas fa-info-circle mr-1"></i>
                    Shows rating difference compared to other batches
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Feedback Form -->
        <?php if ($selected_batch): ?>
        <div class="bg-white p-6 rounded-2xl shadow-lg mb-6 transform transition-transform duration-300 hover:scale-[1.005]">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <div class="bg-indigo-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-pencil-alt text-indigo-600"></i>
                </div>
                Submit Feedback for <?= htmlspecialchars($selected_batch['batch_name']) ?>
                <span class="ml-2 text-sm font-normal text-gray-500">
                    (Batch <?= $selected_batch_index + 1 ?> of <?= count($current_batches) ?>)
                </span>
            </h2>
            <form method="POST" class="space-y-4" id="feedbackForm">
                <input type="hidden" name="email" value="<?= htmlspecialchars($student['email']) ?>">
                <input type="hidden" name="student_name" value="<?= htmlspecialchars($student_name) ?>">
                <input type="hidden" name="batch_id" value="<?= htmlspecialchars($selected_batch_id) ?>">
                <input type="hidden" name="course_name" value="<?= htmlspecialchars($student['course_name'] ?? 'Unknown Course') ?>">
                
                <div class="mb-4 transition-all duration-300 hover:shadow-sm hover:border-blue-300">
                    <label for="regular_in_class" class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-calendar-check mr-1 text-blue-500"></i>
                        Are you regular in class? *
                    </label>
                    <select name="regular_in_class" id="regular_in_class" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300 hover:border-blue-400">
                        <option value="">Select an option</option>
                        <option value="Yes" <?= (isset($_POST['regular_in_class']) && $_POST['regular_in_class'] === 'Yes') ? 'selected' : '' ?>>Yes</option>
                        <option value="No" <?= (isset($_POST['regular_in_class']) && $_POST['regular_in_class'] === 'No') ? 'selected' : '' ?>>No</option>
                        <option value="Sometimes" <?= (isset($_POST['regular_in_class']) && $_POST['regular_in_class'] === 'Sometimes') ? 'selected' : '' ?>>Sometimes</option>
                    </select>
                </div>

                <div class="space-y-6">
                    <!-- Rating Sections -->
                    <div class="rating-section transition-all duration-300 hover:bg-gray-50 p-3 rounded-lg">
                        <h4 class="block text-gray-700 mb-2 font-medium">
                            <i class="fas fa-chalkboard-teacher mr-1 text-blue-500"></i>
                            Class Rating for <?= htmlspecialchars($selected_batch['batch_name']) ?> *
                        </h4>
                        <div class="flex items-center">
                            <div class="star-rating mr-4" data-target="class_rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span data-value="<?= $i ?>" class="text-3xl cursor-pointer transition-all duration-200 hover:scale-125 <?= (isset($_POST['class_rating']) && $_POST['class_rating'] == $i) ? 'active' : '' ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <div class="rating-description text-sm text-gray-500 italic opacity-0 transition-opacity duration-300" id="class_rating_desc"></div>
                        </div>
                        <input type="hidden" name="class_rating" id="class_rating" value="<?= isset($_POST['class_rating']) ? htmlspecialchars($_POST['class_rating']) : '' ?>" required>
                        <div class="text-red-500 text-sm mt-1 hidden" id="class_rating_error">Please select a class rating</div>
                    </div>

                    <div class="rating-section transition-all duration-300 hover:bg-gray-50 p-3 rounded-lg">
                        <h4 class="block text-gray-700 mb-2 font-medium">
                            <i class="fas fa-tasks mr-1 text-blue-500"></i>
                            Assignment Understanding *
                        </h4>
                        <div class="flex items-center">
                            <div class="star-rating mr-4" data-target="assignment_understanding">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span data-value="<?= $i ?>" class="text-3xl cursor-pointer transition-all duration-200 hover:scale-125 <?= (isset($_POST['assignment_understanding']) && $_POST['assignment_understanding'] == $i) ? 'active' : '' ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <div class="rating-description text-sm text-gray-500 italic opacity-0 transition-opacity duration-300" id="assignment_rating_desc"></div>
                        </div>
                        <input type="hidden" name="assignment_understanding" id="assignment_understanding" value="<?= isset($_POST['assignment_understanding']) ? htmlspecialchars($_POST['assignment_understanding']) : '' ?>" required>
                        <div class="text-red-500 text-sm mt-1 hidden" id="assignment_understanding_error">Please select an assignment understanding rating</div>
                    </div>

                    <div class="rating-section transition-all duration-300 hover:bg-gray-50 p-3 rounded-lg">
                        <h4 class="block text-gray-700 mb-2 font-medium">
                            <i class="fas fa-laptop-code mr-1 text-blue-500"></i>
                            Practical Understanding *
                        </h4>
                        <div class="flex items-center">
                            <div class="star-rating mr-4" data-target="practical_understanding">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span data-value="<?= $i ?>" class="text-3xl cursor-pointer transition-all duration-200 hover:scale-125 <?= (isset($_POST['practical_understanding']) && $_POST['practical_understanding'] == $i) ? 'active' : '' ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <div class="rating-description text-sm text-gray-500 italic opacity-0 transition-opacity duration-300" id="practical_rating_desc"></div>
                        </div>
                        <input type="hidden" name="practical_understanding" id="practical_understanding" value="<?= isset($_POST['practical_understanding']) ? htmlspecialchars($_POST['practical_understanding']) : '' ?>" required>
                        <div class="text-red-500 text-sm mt-1 hidden" id="practical_understanding_error">Please select a practical understanding rating</div>
                    </div>
                </div>

                <div class="mb-4 transition-all duration-300 hover:shadow-sm hover:border-blue-300">
                    <label for="satisfied" class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-smile mr-1 text-blue-500"></i>
                        Are you satisfied with <?= htmlspecialchars($selected_batch['batch_name']) ?>? *
                    </label>
                    <select name="satisfied" id="satisfied" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300 hover:border-blue-400">
                        <option value="">Select an option</option>
                        <option value="Yes" <?= (isset($_POST['satisfied']) && $_POST['satisfied'] === 'Yes') ? 'selected' : '' ?>>Yes</option>
                        <option value="No" <?= (isset($_POST['satisfied']) && $_POST['satisfied'] === 'No') ? 'selected' : '' ?>>No</option>
                    </select>
                </div>

                <div class="mb-4 transition-all duration-300 hover:shadow-sm">
                    <label for="suggestions" class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-lightbulb mr-1 text-blue-500"></i>
                        Your suggestions or issues for <?= htmlspecialchars($selected_batch['batch_name']) ?>
                    </label>
                    <textarea id="suggestions" name="suggestions" rows="3" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300 hover:border-blue-400" 
                        placeholder="Share your suggestions or any issues you faced in this batch..."
                        maxlength="500"><?= isset($_POST['suggestions']) ? htmlspecialchars($_POST['suggestions']) : '' ?></textarea>
                    <div class="word-counter text-sm text-right mt-1 text-gray-500 transition-colors duration-300" id="suggestions-counter">0/500 characters</div>
                </div>

                <div class="mb-4 transition-all duration-300 hover:shadow-sm">
                    <label for="feedback_text" class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-comment-dots mr-1 text-blue-500"></i>
                        Additional Feedback for <?= htmlspecialchars($selected_batch['batch_name']) ?>
                    </label>
                    <textarea id="feedback_text" name="feedback_text" rows="5" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300 hover:border-blue-400" 
                        placeholder="Share your thoughts about this batch, instructor, or any suggestions for improvement..."
                        maxlength="1000"><?= isset($_POST['feedback_text']) ? htmlspecialchars($_POST['feedback_text']) : '' ?></textarea>
                    <div class="word-counter text-sm text-right mt-1 text-gray-500 transition-colors duration-300" id="feedback-counter">0/1000 characters</div>
                </div>

                <div class="flex justify-between items-center">
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        You're submitting feedback for <span class="font-semibold text-indigo-600"><?= htmlspecialchars($selected_batch['batch_name']) ?></span>
                    </div>
                    <button type="submit" name="submit_feedback" 
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-6 rounded-lg shadow-md transition-all duration-300 hover:shadow-lg transform hover:-translate-y-1 flex items-center justify-center space-x-2">
                        <i class="fas fa-paper-plane"></i>
                        <span>Submit Feedback for Batch <?= $selected_batch_index + 1 ?></span>
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Feedback History -->
        <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-transform duration-300 hover:scale-[1.005]">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-gray-800 flex items-center">
                    <div class="bg-indigo-100 p-2 rounded-lg mr-3">
                        <i class="fas fa-history text-indigo-600"></i>
                    </div>
                    Feedback History
                </h2>
                <?php if ($selected_batch): ?>
                    <span class="text-sm font-medium text-gray-600">
                        For: <span class="text-indigo-600"><?= htmlspecialchars($selected_batch['batch_name']) ?></span>
                        (<?= count($batch_feedback_history) ?> submissions)
                    </span>
                <?php endif; ?>
            </div>
            
            <?php if (count($batch_feedback_history) > 0): ?>
                <div class="space-y-4">
                    <?php foreach ($batch_feedback_history as $feedback): ?>
                        <div class="border border-gray-200 rounded-lg p-4 transition-all duration-300 hover:border-indigo-300 hover:shadow-md feedback-card transform hover:-translate-y-1">
                            <div class="flex justify-between items-start">
                                <div>
                                    <span class="text-gray-600 text-sm font-medium bg-indigo-50 px-2 py-1 rounded-full">
                                        <i class="far fa-calendar-alt mr-1"></i>
                                        <?= date('M j, Y', strtotime($feedback['date'])) ?>
                                    </span>
                                    <span class="ml-2 text-sm text-gray-500">
                                        <i class="fas fa-layer-group mr-1"></i>
                                        Batch: <?= htmlspecialchars($feedback['batch_id']) ?>
                                    </span>
                                </div>
                                <span class="text-sm px-2 py-1 rounded-full <?= $feedback['satisfied'] == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $feedback['satisfied'] == 1 ? 'Satisfied with course' : 'Not satisfied with course' ?>
                                </span>
                            </div>
                            <div class="mt-3 space-y-2">
                                <div class="flex items-center">
                                    <span class="text-sm font-medium text-gray-700 w-24">Class:</span>
                                    <div class="flex">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?= $i <= $feedback['class_rating'] ? 'text-yellow-400 animate-bounce' : 'text-gray-300' ?>" 
                                                style="animation-delay: <?= $i * 0.1 ?>s"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div class="flex items-center">
                                    <span class="text-sm font-medium text-gray-700 w-24">Assignments:</span>
                                    <div class="flex">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?= $i <= $feedback['assignment_understanding'] ? 'text-yellow-400 animate-bounce' : 'text-gray-300' ?>" 
                                                style="animation-delay: <?= $i * 0.1 ?>s"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div class="flex items-center">
                                    <span class="text-sm font-medium text-gray-700 w-24">Practical:</span>
                                    <div class="flex">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?= $i <= $feedback['practical_understanding'] ? 'text-yellow-400 animate-bounce' : 'text-gray-300' ?>" 
                                                style="animation-delay: <?= $i * 0.1 ?>s"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-800">Class Regularity:</p>
                                    <p class="text-sm text-gray-700 mt-1 px-2 py-1 bg-gray-50 rounded-full inline-block">
                                        <?= htmlspecialchars($feedback['is_regular']) ?>
                                    </p>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-800">Response Status:</p>
                                    <p class="text-sm mt-1 px-2 py-1 rounded-full inline-block 
                                        <?= !empty($feedback['action_taken']) ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800' ?>">
                                        <?= !empty($feedback['action_taken']) ? 'Responded' : 'Pending' ?>
                                    </p>
                                </div>
                            </div>
                            <?php if (!empty($feedback['suggestions'])): ?>
                                <div class="mt-3 bg-gray-50 p-3 rounded-lg transition-all duration-300 hover:bg-indigo-50">
                                    <p class="text-sm font-medium text-gray-800 flex items-center">
                                        <i class="fas fa-lightbulb mr-1 text-yellow-500"></i>
                                        Your Suggestions:
                                    </p>
                                    <p class="text-sm text-gray-700 mt-1"><?= htmlspecialchars($feedback['suggestions']) ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="mt-3 bg-gray-50 p-3 rounded-lg transition-all duration-300 hover:bg-indigo-50">
                                <p class="text-sm font-medium text-gray-800 flex items-center">
                                    <i class="fas fa-comment mr-1 text-indigo-500"></i>
                                    Your Feedback:
                                </p>
                                <p class="text-sm text-gray-700 mt-1"><?= !empty($feedback['feedback_text']) ? htmlspecialchars($feedback['feedback_text']) : 'No additional feedback provided.' ?></p>
                            </div>
                            <?php if (!empty($feedback['action_taken'])): ?>
                                <div class="mt-3 p-3 bg-indigo-50 rounded-lg border border-indigo-100 transition-all duration-300 hover:shadow-inner">
                                    <p class="text-sm font-medium text-indigo-800 flex items-center">
                                        <i class="fas fa-reply mr-1 text-indigo-500"></i>
                                        Instructor Response:
                                    </p>
                                    <p class="text-sm text-indigo-700 mt-1"><?= htmlspecialchars($feedback['action_taken']) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <div class="bg-indigo-50 inline-block p-4 rounded-full mb-4">
                        <i class="fas fa-comment-slash text-indigo-500 text-3xl"></i>
                    </div>
                    <p class="text-gray-500 text-lg">
                        <?php if ($selected_batch): ?>
                            You haven't submitted any feedback for <?= htmlspecialchars($selected_batch['batch_name']) ?> yet.
                        <?php else: ?>
                            No batch selected or you haven't submitted any feedback yet.
                        <?php endif; ?>
                    </p>
                    <p class="text-gray-400 mt-2">Your feedback helps us improve the learning experience.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- All Batches Feedback Summary -->
        <?php if (count($current_batches) > 1): ?>
        <div class="bg-white p-6 rounded-2xl shadow-lg mt-6 transform transition-transform duration-300 hover:scale-[1.005]">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <div class="bg-indigo-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-chart-pie text-indigo-600"></i>
                </div>
                All Batches Feedback Summary
            </h2>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-blue-50 to-indigo-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-layer-group mr-2"></i> Batch
                                </div>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-star mr-2"></i> Avg Class
                                </div>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-tasks mr-2"></i> Avg Assignment
                                </div>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-laptop-code mr-2"></i> Avg Practical
                                </div>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-smile mr-2"></i> Satisfaction
                                </div>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-list-ol mr-2"></i> Total Feedback
                                </div>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-alt mr-2"></i> Last Feedback
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($current_batches as $index => $batch_info): 
                            $batch_id = $batch_info['batch_name'];
                            $batch_data = $batch_info['batch_data'];
                            $stats = $all_feedback_stats[$batch_id];
                            $batch_label = "Batch ";
                            if ($batch_info['field_name'] == 'batch_name') $batch_label .= "1";
                            elseif ($batch_info['field_name'] == 'batch_name_2') $batch_label .= "2";
                            elseif ($batch_info['field_name'] == 'batch_name_3') $batch_label .= "3";
                        ?>
                        <tr class="transition-all duration-200 hover:bg-blue-50 <?= $index == $selected_batch_index ? 'bg-indigo-50' : '' ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="bg-indigo-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-layer-group text-indigo-600"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= $batch_label ?>: <?= htmlspecialchars($batch_data['batch_name']) ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?= htmlspecialchars($batch_data['time_slot']) ?> | <?= ucfirst($batch_data['mode']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="mr-3">
                                        <div class="text-sm font-bold text-gray-900">
                                            <?= round($stats['avg_class_rating'], 1) ?>/5
                                        </div>
                                    </div>
                                    <div class="w-16 bg-gray-200 rounded-full h-2">
                                        <div class="bg-blue-600 h-2 rounded-full" 
                                             style="width: <?= $stats['avg_class_rating'] * 20 ?>%"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="mr-3">
                                        <div class="text-sm font-bold text-gray-900">
                                            <?= round($stats['avg_assignment_rating'], 1) ?>/5
                                        </div>
                                    </div>
                                    <div class="w-16 bg-gray-200 rounded-full h-2">
                                        <div class="bg-green-600 h-2 rounded-full" 
                                             style="width: <?= $stats['avg_assignment_rating'] * 20 ?>%"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="mr-3">
                                        <div class="text-sm font-bold text-gray-900">
                                            <?= round($stats['avg_practical_rating'], 1) ?>/5
                                        </div>
                                    </div>
                                    <div class="w-16 bg-gray-200 rounded-full h-2">
                                        <div class="bg-purple-600 h-2 rounded-full" 
                                             style="width: <?= $stats['avg_practical_rating'] * 20 ?>%"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?php if ($stats['total_feedback'] > 0): ?>
                                        <?= round(($stats['satisfied_count'] / $stats['total_feedback']) * 100) ?>%
                                    <?php else: ?>
                                        0%
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-bold">
                                    <?= $stats['total_feedback'] ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-500">
                                    <?php if ($stats['last_feedback_date']): ?>
                                        <?= date('M j, Y', strtotime($stats['last_feedback_date'])) ?>
                                    <?php else: ?>
                                        No feedback yet
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-4 text-sm text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>
                This table shows your feedback statistics across all your batches. Click on any batch to submit feedback or view details.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add some custom animations -->
<style>
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    @keyframes slideIn {
        from { transform: translateX(-100%); }
        to { transform: translateX(0); }
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes bounce {
        0%, 100% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-5px);
        }
    }
    
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        50% { transform: translateX(5px); }
        75% { transform: translateX(-5px); }
    }
    
    .animate-fade-in {
        animation: fadeIn 0.6s ease-out forwards;
    }
    
    .animate-fade-in-up {
        animation: fadeInUp 0.5s ease-out forwards;
    }
    
    .animate-slide-in {
        animation: slideIn 0.3s ease-out forwards;
    }
    
    .animate-bounce {
        animation: bounce 0.5s ease-in-out;
    }
    
    .animate-shake {
        animation: shake 0.5s ease-in-out;
    }
    
    .delay-100 { animation-delay: 0.1s; }
    .delay-200 { animation-delay: 0.2s; }
    .delay-300 { animation-delay: 0.3s; }
    
    tr {
        animation: fadeIn 0.5s ease-out forwards;
    }
    
    .progress-ring {
        transform: rotate(-90deg);
    }
    
    .progress-ring-circle {
        transition: stroke-dashoffset 0.35s;
        transform: rotate(-90deg);
        transform-origin: 50% 50%;
    }
    
    /* Star rating styles */
    .star-rating span {
        color: #e0e6ed;
        margin-right: 2px;
        transition: all 0.2s;
    }
    
    .star-rating span.active {
        color: #f39c12;
    }
    
    .word-counter.limit-reached {
        color: #e53e3e;
        font-weight: bold;
    }
    
    .feedback-card:hover {
        transform: translateY(-3px);
    }
    
    .rating-description.show {
        opacity: 1;
    }
    
    /* Smooth transitions for form elements */
    input, select, textarea {
        transition: all 0.3s ease;
    }
    
    /* Hover effects for buttons */
    button:hover {
        transform: translateY(-2px);
    }
    
    .border-red-500 {
        border-color: #e53e3e;
    }
    
    /* Glass card effect */
    .glass-card {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 8px 32px rgba(31, 38, 135, 0.1);
    }
    
    /* Mobile navigation styles */
    .mobile-nav-link.active {
        background-color: #ffffff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        font-weight: 600;
    }
    
    .mobile-nav-link i.active {
        transform: scale(1.1);
    }
    
    /* Mobile menu overlay */
    #mobileMenu {
        transition: opacity 0.3s ease-in-out;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .text-sm-mobile {
            font-size: 0.875rem !important;
        }
        
        .text-lg-mobile {
            font-size: 1.125rem !important;
        }
        
        .star-rating span {
            font-size: 1.5rem !important;
        }
    }
</style>

<script>
// Function to toggle mobile menu
function toggleMobileMenu() {
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileMenuContent = mobileMenu.querySelector('div');
    
    if (mobileMenu.classList.contains('hidden')) {
        mobileMenu.classList.remove('hidden');
        setTimeout(() => {
            mobileMenuContent.classList.remove('-translate-x-full');
        }, 10);
    } else {
        mobileMenuContent.classList.add('-translate-x-full');
        setTimeout(() => {
            mobileMenu.classList.add('hidden');
        }, 300);
    }
}

// Close mobile menu when clicking outside
document.getElementById('mobileMenu').addEventListener('click', function(e) {
    if (e.target.id === 'mobileMenu') {
        toggleMobileMenu();
    }
});

// Add staggered animations for feedback cards
document.addEventListener('DOMContentLoaded', function() {
    const feedbackCards = document.querySelectorAll('.feedback-card');
    feedbackCards.forEach((card, index) => {
        card.classList.add('animate-fade-in');
        card.style.animationDelay = `${index * 0.1}s`;
    });
    
    // Animate form on page load
    const formSections = document.querySelectorAll('.rating-section, .mb-4');
    formSections.forEach((section, index) => {
        section.classList.add('animate-fade-in');
        section.style.animationDelay = `${index * 0.05}s`;
    });
    
    // Animate table rows
    const tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach((row, index) => {
        row.style.animationDelay = `${index * 0.05}s`;
    });
});

// Handle ESC key to close mobile menu
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const mobileMenu = document.getElementById('mobileMenu');
        if (!mobileMenu.classList.contains('hidden')) {
            toggleMobileMenu();
        }
    }
});

// Add active state to current page link in mobile menu
document.addEventListener('DOMContentLoaded', function() {
    const currentPage = '<?= basename($_SERVER['PHP_SELF']) ?>';
    const mobileLinks = document.querySelectorAll('.mobile-nav-link');
    
    mobileLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href.includes(currentPage)) {
            link.classList.add('bg-white', 'shadow-md', 'text-indigo-600');
            const icon = link.querySelector('i');
            if (icon) {
                icon.classList.add('text-indigo-600');
            }
        }
    });
});

// Rating system functionality
document.addEventListener('DOMContentLoaded', function() {
    const ratingDescriptions = {
        'class_rating': {
            1: "Very poor - The classes didn't meet my expectations at all",
            2: "Below average - There's significant room for improvement",
            3: "Average - The classes were okay but could be better",
            4: "Good - I learned a lot from the classes",
            5: "Excellent - The classes exceeded my expectations"
        },
        'assignment_understanding': {
            1: "Very difficult - I couldn't understand most assignments",
            2: "Somewhat difficult - I struggled with many assignments",
            3: "Moderate - Some assignments were clear, others weren't",
            4: "Mostly clear - I understood most assignments well",
            5: "Very clear - All assignments were well explained"
        },
        'practical_understanding': {
            1: "Very poor - I didn't gain practical skills",
            2: "Below average - Practical skills were hard to grasp",
            3: "Average - I gained some practical understanding",
            4: "Good - I can apply most concepts practically",
            5: "Excellent - I'm confident in my practical skills"
        }
    };
    
    // Initialize stars based on existing values
    function initializeStars() {
        document.querySelectorAll('.star-rating').forEach(ratingDiv => {
            const target = ratingDiv.getAttribute('data-target');
            const currentValue = document.getElementById(target).value;
            if (currentValue) {
                const stars = ratingDiv.querySelectorAll('span');
                stars.forEach(star => {
                    if (parseInt(star.getAttribute('data-value')) <= parseInt(currentValue)) {
                        star.classList.add('active');
                    }
                });
                const descElement = document.getElementById(target + '_desc');
                if (descElement) {
                    descElement.textContent = ratingDescriptions[target][currentValue];
                    descElement.style.opacity = '1';
                }
            }
        });
    }
    
    // Call initialization
    initializeStars();
    
    // Enhanced star rating interaction
    document.querySelectorAll('.star-rating span').forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.getAttribute('data-value');
            const target = this.closest('.star-rating').getAttribute('data-target');
            const descElement = document.getElementById(target + '_desc');
            const errorElement = document.getElementById(target + '_error');
            const inputElement = document.getElementById(target);
            
            // Update stars appearance
            const stars = this.closest('.star-rating').querySelectorAll('span');
            stars.forEach(s => s.classList.remove('active'));
            
            for (let i = 0; i < rating; i++) {
                stars[i].classList.add('active');
            }
            
            // Update hidden input value
            inputElement.value = rating;
            
            // Show rating description and hide error
            if (descElement) {
                descElement.textContent = ratingDescriptions[target][rating];
                descElement.style.opacity = '1';
            }
            
            if (errorElement) {
                errorElement.style.display = 'none';
            }
            
            // Remove error styling
            this.closest('.star-rating').classList.remove('border-red-500');
        });
        
        // Hover effect for stars with description preview
        star.addEventListener('mouseover', function() {
            const rating = this.getAttribute('data-value');
            const target = this.closest('.star-rating').getAttribute('data-target');
            const descElement = document.getElementById(target + '_desc');
            const stars = this.closest('.star-rating').querySelectorAll('span');
            
            // Highlight stars up to hovered one
            stars.forEach(s => s.style.color = '#e0e6ed');
            for (let i = 0; i < rating; i++) {
                stars[i].style.color = '#f39c12';
            }
            
            if (descElement) {
                descElement.textContent = ratingDescriptions[target][rating];
                descElement.style.opacity = '0.7';
            }
        });
        
        star.addEventListener('mouseout', function() {
            const target = this.closest('.star-rating').getAttribute('data-target');
            const descElement = document.getElementById(target + '_desc');
            const stars = this.closest('.star-rating').querySelectorAll('span');
            const activeStars = this.closest('.star-rating').querySelectorAll('.active');
            
            if (activeStars.length > 0) {
                stars.forEach(s => s.style.color = '#e0e6ed');
                activeStars.forEach(s => s.style.color = '#f39c12');
                if (descElement) {
                    descElement.style.opacity = '1';
                }
            } else {
                stars.forEach(s => s.style.color = '#e0e6ed');
                if (descElement) {
                    descElement.style.opacity = '0';
                }
            }
        });
    });
    
    // Character counter functionality
    function updateCharCounter(textarea, counterId, maxChars) {
        const text = textarea.value;
        const charCount = text.length;
        const counterElement = document.getElementById(counterId);
        
        counterElement.textContent = charCount + '/' + maxChars + ' characters';
        
        if (charCount > maxChars) {
            counterElement.classList.add('text-red-500', 'font-medium');
            // Trim the text to max characters
            textarea.value = text.substring(0, maxChars);
            counterElement.textContent = maxChars + '/' + maxChars + ' characters (limit reached)';
            
            // Add visual feedback for limit reached
            counterElement.classList.add('animate-shake');
            setTimeout(() => {
                counterElement.classList.remove('animate-shake');
            }, 500);
        } else {
            counterElement.classList.remove('text-red-500', 'font-medium');
            
            // Visual feedback when approaching limit
            if (charCount > maxChars * 0.8) {
                counterElement.classList.add('text-yellow-500');
            } else {
                counterElement.classList.remove('text-yellow-500');
            }
        }
    }

    // Initialize character counters
    const suggestionsTextarea = document.getElementById('suggestions');
    const feedbackTextarea = document.getElementById('feedback_text');
    
    if (suggestionsTextarea) {
        suggestionsTextarea.addEventListener('input', function() {
            updateCharCounter(this, 'suggestions-counter', 500);
        });
        
        // Initial count
        updateCharCounter(suggestionsTextarea, 'suggestions-counter', 500);
    }
    
    if (feedbackTextarea) {
        feedbackTextarea.addEventListener('input', function() {
            updateCharCounter(this, 'feedback-counter', 1000);
        });
        
        // Initial count
        updateCharCounter(feedbackTextarea, 'feedback-counter', 1000);
    }

    // Form validation
    const feedbackForm = document.getElementById('feedbackForm');
    if (feedbackForm) {
        feedbackForm.addEventListener('submit', function(e) {
            let isValid = true;
            
            // Reset previous errors
            document.querySelectorAll('.text-red-500').forEach(el => {
                if (el.id.includes('_error')) {
                    el.style.display = 'none';
                }
            });
            document.querySelectorAll('.border-red-500').forEach(el => {
                el.classList.remove('border-red-500');
            });
            
            // Check star ratings
            const ratingFields = ['class_rating', 'assignment_understanding', 'practical_understanding'];
            ratingFields.forEach(field => {
                const value = document.getElementById(field).value;
                if (!value) {
                    document.getElementById(field + '_error').style.display = 'block';
                    const ratingDiv = document.querySelector('[data-target="' + field + '"]');
                    ratingDiv.classList.add('border-red-500', 'animate-shake');
                    isValid = false;
                    
                    setTimeout(() => {
                        ratingDiv.classList.remove('animate-shake');
                    }, 500);
                }
            });
            
            // Check dropdowns
            const dropdownFields = ['regular_in_class', 'satisfied'];
            dropdownFields.forEach(field => {
                const element = document.getElementById(field);
                const value = element.value;
                if (!value) {
                    element.classList.add('border-red-500', 'animate-shake');
                    isValid = false;
                    
                    setTimeout(() => {
                        element.classList.remove('animate-shake');
                    }, 500);
                } else {
                    element.classList.remove('border-red-500');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                
                // Show error message
                const errorMsg = document.createElement('div');
                errorMsg.className = 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 animate-fade-in-up';
                errorMsg.innerHTML = '<div class="flex items-center"><i class="fas fa-exclamation-circle mr-2"></i> Please fill in all required fields marked with *</div>';
                
                // Remove existing error messages
                document.querySelectorAll('.bg-green-100, .bg-red-100').forEach(el => el.remove());
                
                // Insert error message at the beginning of the form
                this.parentElement.insertBefore(errorMsg, this.parentElement.firstChild);
                
                // Scroll to first error
                const firstError = document.querySelector('.border-red-500');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    }
    
    // Real-time validation for dropdowns
    document.querySelectorAll('select').forEach(select => {
        select.addEventListener('change', function() {
            if (this.value) {
                this.classList.remove('border-red-500');
            }
        });
    });
    
    // Quick batch switching with keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        <?php if (count($current_batches) > 1): ?>
        if (e.ctrlKey) {
            const currentIndex = <?= $selected_batch_index ?>;
            const totalBatches = <?= count($current_batches) ?>;
            
            if (e.key === 'ArrowLeft' && currentIndex > 0) {
                window.location.href = `?batch_index=${currentIndex - 1}`;
            } else if (e.key === 'ArrowRight' && currentIndex < totalBatches - 1) {
                window.location.href = `?batch_index=${currentIndex + 1}`;
            }
        }
        <?php endif; ?>
    });
    
    // Add tooltip for keyboard shortcuts
    <?php if (count($current_batches) > 1): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const batchSelector = document.querySelector('.glass-card');
        if (batchSelector) {
            const tooltip = document.createElement('div');
            tooltip.className = 'text-xs text-gray-500 mt-2';
            tooltip.innerHTML = '<i class="fas fa-keyboard mr-1"></i>Tip: Use Ctrl+← or Ctrl+→ to quickly switch between batches';
            batchSelector.appendChild(tooltip);
        }
    });
    <?php endif; ?>
});
</script>

<?php include '../footer.php'; ?>