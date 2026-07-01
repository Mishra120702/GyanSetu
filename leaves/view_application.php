<?php
session_start();
require_once '../db_connection.php';

// Check if admin/mentor is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'mentor'])) {
    header("Location: ../login.php");
    exit();
}

$application_id = $_GET['id'] ?? 0;

$query = $db->prepare("
    SELECT l.*, 
           b.batch_name as batch_title,
           u.name as approved_by_name,
           u2.name as rejected_by_name,
           u3.name as student_user_name,
           CASE 
               WHEN EXISTS (SELECT 1 FROM students s WHERE s.student_id = l.student_id) THEN 'student'
               WHEN EXISTS (SELECT 1 FROM trainers t WHERE t.user_id = l.student_id) THEN 'trainer'
               ELSE 'unknown'
           END as applicant_type,
           CASE 
               WHEN EXISTS (SELECT 1 FROM students s WHERE s.student_id = l.student_id) 
               THEN (SELECT CONCAT(s2.first_name, ' ', s2.last_name) FROM students s2 WHERE s2.student_id = l.student_id)
               WHEN EXISTS (SELECT 1 FROM trainers t WHERE t.user_id = l.student_id) 
               THEN (SELECT t2.name FROM trainers t2 WHERE t2.user_id = l.student_id)
               ELSE l.student_name
           END as applicant_full_name,
           CASE 
               WHEN EXISTS (SELECT 1 FROM students s WHERE s.student_id = l.student_id) 
               THEN (SELECT s2.email FROM students s2 WHERE s2.student_id = l.student_id)
               WHEN EXISTS (SELECT 1 FROM trainers t WHERE t.user_id = l.student_id) 
               THEN (SELECT t2.email FROM trainers t2 WHERE t2.user_id = l.student_id)
               ELSE l.email
           END as applicant_email,
           CASE 
               WHEN EXISTS (SELECT 1 FROM students s WHERE s.student_id = l.student_id) 
               THEN (SELECT s2.phone_number FROM students s2 WHERE s2.student_id = l.student_id)
               WHEN EXISTS (SELECT 1 FROM trainers t WHERE t.user_id = l.student_id) 
               THEN (SELECT t2.phone FROM trainers t2 WHERE t2.user_id = l.student_id)
               ELSE NULL
           END as applicant_phone
    FROM leave_applications l
    LEFT JOIN batches b ON l.batch_id = b.batch_id
    LEFT JOIN users u ON l.approved_by = u.id
    LEFT JOIN users u2 ON l.rejected_by = u2.id
    LEFT JOIN users u3 ON l.student_id = u3.id
    WHERE l.id = :id
");
$query->execute([':id' => $application_id]);
$application = $query->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    die("Application not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Application - <?= htmlspecialchars($application['application_no']) ?> - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in { animation: fadeIn 0.6s ease-out forwards; }

        /* Page background */
        body { background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdf4 100%) !important; }

        /* Page header */
        .page-header-title { color: #4f46e5; }
        .page-header-sub  { color: #7c3aed; }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .status-pending  { background: linear-gradient(135deg,#fef9c3,#fde68a); color: #92400e; border: 1px solid #fbbf24; }
        .status-approved { background: linear-gradient(135deg,#d1fae5,#a7f3d0); color: #065f46; border: 1px solid #34d399; }
        .status-rejected { background: linear-gradient(135deg,#fee2e2,#fecaca); color: #991b1b; border: 1px solid #f87171; }
        .status-cancelled{ background: linear-gradient(135deg,#f3f4f6,#e5e7eb); color: #374151; border: 1px solid #9ca3af; }
        
        .user-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .user-student { background: linear-gradient(135deg,#dbeafe,#bfdbfe); color: #1e40af; border: 1px solid #60a5fa; }
        .user-trainer { background: linear-gradient(135deg,#fce7f3,#fbcfe8); color: #9d174d; border: 1px solid #f472b6; }

        /* Coloured section cards */
        .info-section {
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .info-section:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }

        /* Each section gets its own tint */
        .info-section.section-basic    { background: linear-gradient(135deg,#eff6ff,#dbeafe); border-left-color: #3b82f6; }
        .info-section.section-reason   { background: linear-gradient(135deg,#fffbeb,#fef3c7); border-left-color: #f59e0b; }
        .info-section.section-course   { background: linear-gradient(135deg,#f5f3ff,#ede9fe); border-left-color: #8b5cf6; }
        .info-section.section-reflect  { background: linear-gradient(135deg,#f0fdf4,#dcfce7); border-left-color: #22c55e; }
        .info-section.section-trainer  { background: linear-gradient(135deg,#fdf4ff,#f3e8ff); border-left-color: #a855f7; }
        .info-section.section-admin    { background: linear-gradient(135deg,#eef2ff,#e0e7ff); border-left-color: #6366f1; }
        
        .section-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        
        .section-title i { margin-right: 0.5rem; font-size: 1.25rem; }
        
        .info-row {
            display: flex;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(0,0,0,0.07);
        }
        
        .info-label {
            width: 180px;
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .info-value {
            flex: 1;
            font-size: 0.875rem;
            color: #1f2937;
        }

        /* Back button */
        .btn-back {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            transition: all 0.2s;
        }
        .btn-back:hover { background: linear-gradient(135deg, #4f46e5, #4338ca); transform: translateY(-1px); }

        /* Action buttons */
        .btn-approve {
            background: linear-gradient(135deg, #22c55e, #16a34a) !important;
            box-shadow: 0 4px 12px rgba(34,197,94,0.3);
        }
        .btn-approve:hover { background: linear-gradient(135deg, #16a34a, #15803d) !important; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(34,197,94,0.4); }
        .btn-reject {
            background: linear-gradient(135deg, #ef4444, #dc2626) !important;
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }
        .btn-reject:hover { background: linear-gradient(135deg, #dc2626, #b91c1c) !important; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(239,68,68,0.4); }

        /* Application header card accent */
        .app-header-card {
            background: white;
            border-top: 4px solid;
            border-image: linear-gradient(90deg,#6366f1,#8b5cf6,#ec4899) 1;
        }

        @media print {
            .no-print { display: none; }
            body { background: white; padding: 20px; }
            .info-section { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    
    <div class="flex">
        <?php include '../sidebar.php'; ?>
        
        <div class="flex-1 ml-64 p-8">
            <div class="max-w-5xl mx-auto">
                <!-- Header -->
                <div class="mb-6 animate-fade-in">
                    <div class="flex justify-between items-center">
                        <div>
                            <h1 class="text-2xl font-bold page-header-title">Application Details</h1>
                            <p class="page-header-sub mt-1 text-sm font-medium">Complete leave application information</p>
                        </div>
                        <div class="flex space-x-3 no-print">
                            <a href="leave_management.php" class="btn-back px-4 py-2 rounded-lg font-medium">
                                <i class="fas fa-arrow-left mr-2"></i> Back
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Application Header Card -->
                <div class="app-header-card rounded-2xl shadow-lg p-6 mb-6 animate-fade-in">
                    <div class="flex justify-between items-start flex-wrap gap-4">
                        <div>
                            <div class="flex items-center space-x-3">
                                <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl shadow-lg flex items-center justify-center">
                                    <i class="fas fa-file-alt text-white text-2xl"></i>
                                </div>
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($application['application_no']) ?></h2>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <i class="far fa-calendar-alt mr-1"></i> Applied on <?= date('d M Y, h:i A', strtotime($application['created_at'])) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span class="user-badge <?= $application['applicant_type'] === 'student' ? 'user-student' : 'user-trainer' ?>">
                                <i class="fas <?= $application['applicant_type'] === 'student' ? 'fa-user-graduate' : 'fa-chalkboard-teacher' ?> mr-2"></i>
                                <?= ucfirst($application['applicant_type']) ?>
                            </span>
                            <span class="status-badge <?= 
                                $application['status'] === 'approved' ? 'status-approved' : 
                                ($application['status'] === 'rejected' ? 'status-rejected' : 
                                ($application['status'] === 'cancelled' ? 'status-cancelled' : 'status-pending')) ?>">
                                <i class="fas <?= 
                                    $application['status'] === 'approved' ? 'fa-check-circle' : 
                                    ($application['status'] === 'rejected' ? 'fa-times-circle' : 
                                    ($application['status'] === 'cancelled' ? 'fa-ban' : 'fa-clock')) ?> mr-2"></i>
                                <?= strtoupper($application['status']) ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Basic Information -->
                <div class="info-section section-basic animate-fade-in">
                    <div class="section-title">
                        <i class="fas fa-info-circle text-blue-600"></i>
                        Basic Information
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="info-row">
                                <div class="info-label">Applicant Name</div>
                                <div class="info-value font-medium"><?= htmlspecialchars($application['applicant_full_name'] ?? $application['student_name']) ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Applicant ID</div>
                                <div class="info-value"><?= htmlspecialchars($application['student_id']) ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?= htmlspecialchars($application['applicant_email'] ?? $application['email']) ?></div>
                            </div>
                        </div>
                        <div>
                            <div class="info-row">
                                <div class="info-label">Phone Number</div>
                                <div class="info-value"><?= htmlspecialchars($application['applicant_phone'] ?? 'Not provided') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Batch</div>
                                <div class="info-value"><?= htmlspecialchars($application['batch_title'] ?? $application['batch_id']) ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Leave Duration</div>
                                <div class="info-value">
                                    <?= date('d M Y', strtotime($application['start_date'])) ?> - <?= date('d M Y', strtotime($application['end_date'])) ?>
                                    <span class="text-gray-500 text-xs ml-2">(<?= $application['total_days'] ?> days)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Reason for Absence -->
                <div class="info-section section-reason animate-fade-in">
                    <div class="section-title">
                        <i class="fas fa-question-circle text-yellow-600"></i>
                        Reason for Absence
                    </div>
                    <div class="space-y-4">
                        <div>
                            <div class="info-label mb-1">Category</div>
                            <div class="info-value font-medium"><?= htmlspecialchars($application['reason_category']) ?></div>
                        </div>
                        <div>
                            <div class="info-label mb-1">Detailed Reason</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($application['reason_detail'])) ?></div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <div class="info-label mb-1">Absence Type</div>
                                <div class="info-value"><?= htmlspecialchars($application['absence_type']) ?></div>
                            </div>
                            <div>
                                <div class="info-label mb-1">Informed Academy</div>
                                <div class="info-value"><?= htmlspecialchars($application['informed_academy']) ?></div>
                            </div>
                        </div>
                        <?php if (!empty($application['medical_prescription'])): ?>
                        <div>
                            <div class="info-label mb-1">Medical Prescription</div>
                            <div class="info-value">
                                <a href="../stu_dash/<?= $application['medical_prescription'] ?>" target="_blank" class="text-blue-600 hover:underline flex items-center">
                                    <i class="fas fa-file-medical mr-2"></i> View Prescription
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Course Value & Learning Feedback (For Students) -->
                <?php if ($application['applicant_type'] === 'student'): ?>
                <div class="info-section section-course animate-fade-in">
                    <div class="section-title">
                        <i class="fas fa-graduation-cap text-purple-600"></i>
                        Course Value & Learning Feedback
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="info-row">
                                <div class="info-label">Course Importance</div>
                                <div class="info-value"><?= htmlspecialchars($application['course_importance'] ?? 'Not specified') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Content Value</div>
                                <div class="info-value"><?= htmlspecialchars($application['content_value'] ?? 'Not specified') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Topic Understanding</div>
                                <div class="info-value"><?= htmlspecialchars($application['topic_understanding'] ?? 'Not specified') ?></div>
                            </div>
                        </div>
                        <div>
                            <div class="info-row">
                                <div class="info-label">Practical Ability</div>
                                <div class="info-value"><?= htmlspecialchars($application['practical_ability'] ?? 'Not specified') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Unique Learning</div>
                                <div class="info-value"><?= htmlspecialchars($application['unique_learning'] ?? 'Not specified') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Self-Reflection & Commitment (For Students) -->
                <div class="info-section section-reflect animate-fade-in">
                    <div class="section-title">
                        <i class="fas fa-brain text-green-600"></i>
                        Self-Reflection & Commitment
                    </div>
                    <div class="space-y-4">
                        <div>
                            <div class="info-label mb-1">What will you lose by missing classes?</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($application['loss_reflection'] ?? 'Not specified')) ?></div>
                        </div>
                        <div>
                            <div class="info-label mb-1">Future Commitment</div>
                            <div class="info-value font-medium"><?= htmlspecialchars($application['future_commitment'] ?? 'Not specified') ?></div>
                        </div>
                        <div>
                            <div class="info-label mb-1">Responsibility Acceptance</div>
                            <div class="info-value">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm <?= ($application['responsibility_acceptance'] ?? 0) ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                    <i class="fas <?= ($application['responsibility_acceptance'] ?? 0) ? 'fa-check-circle' : 'fa-times-circle' ?> mr-1"></i>
                                    <?= ($application['responsibility_acceptance'] ?? 0) ? 'Accepted full responsibility' : 'Did not accept responsibility' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Trainer-specific note -->
                <div class="info-section section-trainer animate-fade-in">
                    <div class="section-title">
                        <i class="fas fa-chalkboard-teacher text-purple-600"></i>
                        Trainer Information
                    </div>
                    <div class="bg-purple-50 p-4 rounded-lg">
                        <p class="text-purple-800 text-sm">This is a leave application submitted by a trainer. Please review the reason and take appropriate action.</p>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Admin Response -->
                <?php if ($application['status'] !== 'pending'): ?>
                <div class="info-section section-admin animate-fade-in">
                    <div class="section-title">
                        <i class="fas fa-comment-dots text-indigo-600"></i>
                        Admin Response
                    </div>
                    <div class="space-y-3">
                        <?php if (!empty($application['admin_remarks'])): ?>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <div class="info-label mb-1">Remarks</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($application['admin_remarks'])) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($application['status'] === 'approved' && !empty($application['approved_by_name'])): ?>
                        <div class="bg-green-50 p-3 rounded-lg border-l-4 border-green-500">
                            <div class="info-label mb-1">Approved By</div>
                            <div class="info-value font-medium text-green-700"><?= htmlspecialchars($application['approved_by_name']) ?></div>
                            <div class="info-value text-sm text-green-600 mt-1">on <?= date('d M Y, h:i A', strtotime($application['approved_at'])) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($application['status'] === 'rejected' && !empty($application['rejection_reason'])): ?>
                        <div class="bg-red-50 p-3 rounded-lg border-l-4 border-red-500">
                            <div class="info-label mb-1">Rejection Reason</div>
                            <div class="info-value text-red-700"><?= nl2br(htmlspecialchars($application['rejection_reason'])) ?></div>
                            <?php if (!empty($application['rejected_by_name'])): ?>
                            <div class="info-value text-sm text-red-600 mt-1">Rejected by <?= htmlspecialchars($application['rejected_by_name']) ?> on <?= date('d M Y, h:i A', strtotime($application['rejected_at'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Action Buttons for Pending Applications -->
                <?php if ($application['status'] === 'pending'): ?>
                <div class="bg-white rounded-2xl shadow-lg p-6 mb-6 no-print">
                    <h3 class="text-lg font-bold text-indigo-700 mb-4">⚡ Take Action</h3>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="accept_leave.php?id=<?= $application['id'] ?>" class="btn-approve flex-1 px-6 py-3 text-white rounded-lg transition-all text-center font-semibold">
                            <i class="fas fa-check mr-2"></i> Approve Application
                        </a>
                        <a href="reject_leave.php?id=<?= $application['id'] ?>" class="btn-reject flex-1 px-6 py-3 text-white rounded-lg transition-all text-center font-semibold">
                            <i class="fas fa-times mr-2"></i> Reject Application
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>