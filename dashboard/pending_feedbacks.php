<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}

// Handle bulk action form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_feedbacks']) && !empty($_POST['selected_feedbacks'])) {
        $action_taken = trim($_POST['bulk_action_text']);
        
        if (empty($action_taken)) {
            $_SESSION['feedback_message'] = [
                'type' => 'error',
                'text' => 'Please enter action taken for selected feedbacks.'
            ];
        } else {
            try {
                $placeholders = str_repeat('?,', count($_POST['selected_feedbacks']) - 1) . '?';
                $stmt = $db->prepare("UPDATE feedback SET action_taken = ? WHERE id IN ($placeholders)");
                
                // Combine action_taken with all selected IDs
                $params = array_merge([$action_taken], $_POST['selected_feedbacks']);
                $stmt->execute($params);
                
                $_SESSION['feedback_message'] = [
                    'type' => 'success',
                    'text' => 'Successfully marked ' . count($_POST['selected_feedbacks']) . ' feedback(s) as addressed!'
                ];
                
                // Redirect to prevent form resubmission
                header("Location: pending_feedbacks.php");
                exit;
            } catch (PDOException $e) {
                $_SESSION['feedback_message'] = [
                    'type' => 'error',
                    'text' => 'Error updating feedbacks: ' . $e->getMessage()
                ];
            }
        }
    }
    // Handle single feedback update (if you want to keep the old functionality too)
    elseif (isset($_POST['feedback_id'], $_POST['action_taken'])) {
        $feedback_id = $_POST['feedback_id'];
        $action_taken = trim($_POST['action_taken']);
        
        try {
            $stmt = $db->prepare("UPDATE feedback SET action_taken = ? WHERE id = ?");
            $stmt->execute([$action_taken, $feedback_id]);
            
            $_SESSION['feedback_message'] = [
                'type' => 'success',
                'text' => 'Feedback marked as addressed successfully!'
            ];
            
            // Redirect to prevent form resubmission
            header("Location: pending_feedbacks.php");
            exit;
        } catch (PDOException $e) {
            $_SESSION['feedback_message'] = [
                'type' => 'error',
                'text' => 'Error updating feedback: ' . $e->getMessage()
            ];
        }
    }
}

include '../header.php';
include '../sidebar.php';

// Get pending feedback (where action_taken is empty)
$pending_feedback = $db->query("
    SELECT f.*, b.batch_name 
    FROM feedback f
    LEFT JOIN batches b ON f.batch_id = b.batch_id
    WHERE f.action_taken IS NULL OR f.action_taken = ''
    ORDER BY f.date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Check for any feedback messages
$feedback_message = $_SESSION['feedback_message'] ?? null;
unset($_SESSION['feedback_message']);
?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen">
    <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-comment-dots text-red-500"></i>
            <span>Pending Feedback</span>
        </h1>
    </header>

    <div class="p-4 md:p-6">
        <?php if ($feedback_message): ?>
        <div class="mb-4 p-4 rounded-md <?= $feedback_message['type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
            <?= htmlspecialchars($feedback_message['text']) ?>
        </div>
        <?php endif; ?>

        <!-- Bulk Action Section -->
        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-3">Bulk Actions</h2>
            <form method="POST" action="pending_feedbacks.php" id="bulkActionForm">
                <div class="flex flex-col md:flex-row gap-4 items-start md:items-center">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Action Taken (for selected feedbacks)</label>
                        <input type="text" name="bulk_action_text" 
                               placeholder="Enter common action taken for selected feedbacks..."
                               class="w-full p-2 border rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               required>
                    </div>
                    <div class="mt-4 md:mt-6">
                        <button type="submit" name="bulk_action" value="1"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium transition-colors">
                            <i class="fas fa-check-circle mr-2"></i>
                            Mark Selected as Addressed
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="overflow-x-auto">
                <form method="POST" action="pending_feedbacks.php" id="feedbackForm">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <input type="checkbox" id="selectAll" class="rounded border-gray-300">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Course</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rating</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Feedback</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($pending_feedback as $feedback): ?>
                            <!-- Main Row -->
                            <tr class="hover:bg-gray-50 cursor-pointer feedback-row" data-id="<?= $feedback['id'] ?>">
                                <td class="px-4 py-4 whitespace-nowrap" onclick="event.stopPropagation()">
                                    <input type="checkbox" name="selected_feedbacks[]" value="<?= $feedback['id'] ?>" 
                                           class="feedback-checkbox rounded border-gray-300">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('M d, Y', strtotime($feedback['date'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($feedback['student_name']) ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?= htmlspecialchars($feedback['email']) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($feedback['batch_id'] ?? 'N/A') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($feedback['batch_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div class="stars-inline text-yellow-500">
                                        <?= str_repeat('★', $feedback['class_rating']) ?><?= str_repeat('☆', 5 - $feedback['class_rating']) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 max-w-xs">
                                    <div class="flex items-center justify-between">
                                        <div class="truncate flex-1" title="<?= htmlspecialchars($feedback['feedback_text']) ?>">
                                            <?= htmlspecialchars(substr($feedback['feedback_text'], 0, 50)) ?>...
                                        </div>
                                        <button class="ml-2 text-blue-600 hover:text-blue-800 expand-btn" onclick="toggleExpand(<?= $feedback['id'] ?>, event)">
                                            <i class="fas fa-chevron-down"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium" onclick="event.stopPropagation()">
                                    <!-- Single feedback action form -->
                                    <form method="POST" action="pending_feedbacks.php" class="inline">
                                        <input type="hidden" name="feedback_id" value="<?= $feedback['id'] ?>">
                                        <div class="flex items-center space-x-2">
                                            <input type="text" name="action_taken" 
                                                   placeholder="Action..."
                                                   class="text-xs p-2 border rounded w-32"
                                                   required>
                                            <button type="submit" 
                                                    class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-xs transition-colors">
                                                Mark
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <!-- Expandable Row -->
                            <tr id="expand-<?= $feedback['id'] ?>" class="expandable-row hidden bg-gray-50">
                                <td colspan="8" class="px-6 py-4">
                                    <div class="flex items-start space-x-4">
                                        <div class="flex-1">
                                            <h4 class="text-sm font-semibold text-gray-700 mb-2">Complete Feedback:</h4>
                                            <p class="text-sm text-gray-600 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($feedback['feedback_text'])) ?></p>
                                            
                                            <?php if (!empty($feedback['suggestions'])): ?>
                                            <h4 class="text-sm font-semibold text-gray-700 mt-4 mb-2">Suggestions:</h4>
                                            <p class="text-sm text-gray-600 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($feedback['suggestions'])) ?></p>
                                            <?php endif; ?>
                                            
                                            <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                                                <?php if (!empty($feedback['is_regular'])): ?>
                                                <div>
                                                    <span class="text-xs text-gray-500">Regular Student:</span>
                                                    <span class="text-sm font-medium text-gray-700 block"><?= $feedback['is_regular'] ?></span>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($feedback['assignment_understanding'])): ?>
                                                <div>
                                                    <span class="text-xs text-gray-500">Assignment Understanding:</span>
                                                    <span class="text-sm font-medium text-gray-700 block"><?= $feedback['assignment_understanding'] ?>/5</span>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($feedback['practical_understanding'])): ?>
                                                <div>
                                                    <span class="text-xs text-gray-500">Practical Understanding:</span>
                                                    <span class="text-sm font-medium text-gray-700 block"><?= $feedback['practical_understanding'] ?>/5</span>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($feedback['satisfied'])): ?>
                                                <div>
                                                    <span class="text-xs text-gray-500">Satisfied:</span>
                                                    <span class="text-sm font-medium text-gray-700 block"><?= $feedback['satisfied'] ? 'Yes' : 'No' ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <button class="text-gray-400 hover:text-gray-600" onclick="toggleExpand(<?= $feedback['id'] ?>, event)">
                                            <i class="fas fa-chevron-up"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($pending_feedback)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500">
                                    No pending feedback found. Great job!
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all functionality
    const selectAllCheckbox = document.getElementById('selectAll');
    const feedbackCheckboxes = document.querySelectorAll('.feedback-checkbox');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            feedbackCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
        });
        
        // Update "Select All" checkbox state when individual checkboxes change
        feedbackCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(feedbackCheckboxes).every(cb => cb.checked);
                const anyChecked = Array.from(feedbackCheckboxes).some(cb => cb.checked);
                
                if (allChecked) {
                    selectAllCheckbox.checked = true;
                    selectAllCheckbox.indeterminate = false;
                } else if (anyChecked) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = true;
                } else {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                }
            });
        });
    }
    
    // Make rows clickable to expand/collapse
    const feedbackRows = document.querySelectorAll('.feedback-row');
    feedbackRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Don't expand if clicking on checkbox, action buttons, or expand button
            if (e.target.closest('input[type="checkbox"]') || 
                e.target.closest('form') || 
                e.target.closest('.expand-btn')) {
                return;
            }
            const id = this.dataset.id;
            toggleExpand(id, e);
        });
    });
    
    // Bulk action form validation
    const bulkActionForm = document.getElementById('bulkActionForm');
    if (bulkActionForm) {
        bulkActionForm.addEventListener('submit', function(e) {
            const selectedFeedbacks = document.querySelectorAll('.feedback-checkbox:checked');
            if (selectedFeedbacks.length === 0) {
                e.preventDefault();
                alert('Please select at least one feedback to perform bulk action.');
                return false;
            }
            
            const actionText = this.querySelector('[name="bulk_action_text"]').value.trim();
            if (!actionText) {
                e.preventDefault();
                alert('Please enter the action taken for selected feedbacks.');
                return false;
            }
            
            // Add selected feedbacks to the bulk action form
            selectedFeedbacks.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_feedbacks[]';
                input.value = checkbox.value;
                this.appendChild(input);
            });
            
            return true;
        });
    }
});

function toggleExpand(id, event) {
    if (event) {
        event.stopPropagation();
    }
    
    const expandRow = document.getElementById('expand-' + id);
    const mainRow = document.querySelector(`.feedback-row[data-id="${id}"]`);
    const chevron = mainRow.querySelector('.expand-btn i');
    
    if (expandRow.classList.contains('hidden')) {
        expandRow.classList.remove('hidden');
        if (chevron) {
            chevron.classList.remove('fa-chevron-down');
            chevron.classList.add('fa-chevron-up');
        }
    } else {
        expandRow.classList.add('hidden');
        if (chevron) {
            chevron.classList.remove('fa-chevron-up');
            chevron.classList.add('fa-chevron-down');
        }
    }
}
</script>

<style>
.stars-inline {
    display: inline-block;
    font-size: 14px;
    letter-spacing: 1px;
}

/* Custom checkbox styling */
input[type="checkbox"]:indeterminate {
    background-color: #3b82f6;
    border-color: #3b82f6;
}

.truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.feedback-row {
    cursor: pointer;
    transition: background-color 0.2s;
}

.feedback-row:hover {
    background-color: #f9fafb;
}

.expandable-row {
    transition: all 0.3s ease;
}

.expandable-row td {
    border-top: 1px dashed #e5e7eb;
}

.expand-btn {
    transition: transform 0.2s;
}

.expand-btn:hover {
    transform: scale(1.1);
}

.whitespace-pre-wrap {
    white-space: pre-wrap;
    word-wrap: break-word;
}
</style>

<?php include '../footer.php'; ?>