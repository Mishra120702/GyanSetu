<?php
session_start();
require_once '../../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$application_id = $_GET['id'] ?? 0;

$query = $db->prepare("
    SELECT l.*, b.batch_name as batch_title
    FROM leave_applications l
    LEFT JOIN batches b ON l.batch_id = b.batch_id
    WHERE l.id = :id AND l.student_id = :student_id
");
$query->execute([
    ':id' => $application_id,
    ':student_id' => $_SESSION['student_id'] ?? ''
]);
$app = $query->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    echo json_encode(['success' => false, 'message' => 'Application not found']);
    exit();
}

// Generate HTML for application details
ob_start();
?>
<div class="space-y-6">
    <!-- Application Header -->
    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-4 rounded-lg">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($app['application_no']) ?></h3>
                <p class="text-sm text-gray-600">Submitted: <?= date('d M Y, h:i A', strtotime($app['created_at'])) ?></p>
            </div>
            <span class="status-badge px-3 py-1 rounded-full text-sm font-medium
                <?= $app['status'] === 'approved' ? 'bg-green-100 text-green-800' : 
                   ($app['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 
                   ($app['status'] === 'cancelled' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800')) ?>">
                <?= ucfirst($app['status']) ?>
            </span>
        </div>
    </div>

    <!-- Basic Information -->
    <div>
        <h4 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-info-circle text-blue-600 mr-2"></i>
            Basic Information
        </h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Batch</p>
                <p class="font-medium"><?= htmlspecialchars($app['batch_title'] ?? $app['batch_id']) ?></p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Student Name</p>
                <p class="font-medium"><?= htmlspecialchars($app['student_name']) ?></p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Email</p>
                <p class="font-medium"><?= htmlspecialchars($app['email']) ?></p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Leave Duration</p>
                <p class="font-medium"><?= date('d M Y', strtotime($app['start_date'])) ?> - <?= date('d M Y', strtotime($app['end_date'])) ?> (<?= $app['total_days'] ?> days)</p>
            </div>
        </div>
    </div>

    <!-- Reason for Absence -->
    <div>
        <h4 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-question-circle text-yellow-600 mr-2"></i>
            Reason for Absence
        </h4>
        <div class="bg-gray-50 p-4 rounded-lg space-y-3">
            <div>
                <p class="text-xs text-gray-500">Main Reason</p>
                <p class="font-medium"><?= htmlspecialchars($app['reason_category']) ?></p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Detailed Reason</p>
                <p class="text-gray-700"><?= nl2br(htmlspecialchars($app['reason_detail'])) ?></p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs text-gray-500">Absence Type</p>
                    <p class="font-medium"><?= $app['absence_type'] ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Informed Academy</p>
                    <p class="font-medium"><?= $app['informed_academy'] ?></p>
                </div>
            </div>
            <?php if ($app['medical_prescription']): ?>
            <div>
                <p class="text-xs text-gray-500">Medical Prescription</p>
                <a href="<?= $app['medical_prescription'] ?>" target="_blank" class="text-blue-600 hover:underline flex items-center">
                    <i class="fas fa-file-medical mr-1"></i> View Prescription
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Course Value & Learning Feedback -->
    <div>
        <h4 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-graduation-cap text-purple-600 mr-2"></i>
            Course Value & Learning Feedback
        </h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Course Importance</p>
                <p class="font-medium"><?= htmlspecialchars($app['course_importance']) ?></p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Content Value</p>
                <p class="font-medium"><?= htmlspecialchars($app['content_value']) ?></p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Topic Understanding</p>
                <p class="font-medium"><?= htmlspecialchars($app['topic_understanding']) ?></p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Practical Ability</p>
                <p class="font-medium"><?= htmlspecialchars($app['practical_ability']) ?></p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg">
                <p class="text-xs text-gray-500">Unique Learning</p>
                <p class="font-medium"><?= htmlspecialchars($app['unique_learning']) ?></p>
            </div>
        </div>
    </div>

    <!-- Self-Reflection & Commitment -->
    <div>
        <h4 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-brain text-green-600 mr-2"></i>
            Self-Reflection & Commitment
        </h4>
        <div class="bg-gray-50 p-4 rounded-lg space-y-3">
            <div>
                <p class="text-xs text-gray-500">What you think you will lose if continue missing classes</p>
                <p class="text-gray-700"><?= nl2br(htmlspecialchars($app['loss_reflection'])) ?></p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Future Commitment</p>
                <p class="font-medium"><?= htmlspecialchars($app['future_commitment']) ?></p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Responsibility Acceptance</p>
                <p class="font-medium text-yellow-700"><?= $app['responsibility_acceptance'] ? 'Yes, I accept full responsibility' : 'Not accepted' ?></p>
            </div>
        </div>
    </div>

    <!-- Admin Response -->
    <?php if ($app['status'] !== 'pending'): ?>
    <div>
        <h4 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-comment-dots text-indigo-600 mr-2"></i>
            Admin Response
        </h4>
        <div class="bg-gray-50 p-4 rounded-lg">
            <?php if ($app['status'] === 'approved'): ?>
                <p class="text-green-600">✓ This application has been approved.</p>
                <?php if ($app['admin_remarks']): ?>
                    <p class="text-gray-700 mt-2"><strong>Remarks:</strong> <?= nl2br(htmlspecialchars($app['admin_remarks'])) ?></p>
                <?php endif; ?>
            <?php elseif ($app['status'] === 'rejected'): ?>
                <p class="text-red-600">✗ This application has been rejected.</p>
                <?php if ($app['rejection_reason']): ?>
                    <p class="text-gray-700 mt-2"><strong>Reason:</strong> <?= nl2br(htmlspecialchars($app['rejection_reason'])) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
$html = ob_get_clean();

echo json_encode(['success' => true, 'html' => $html]);
?>