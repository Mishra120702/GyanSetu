<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_user_id = $_SESSION['user_id'];

// Get student information
$student_query = $db->prepare("
    SELECT s.*, u.email 
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get student's tickets
$tickets_query = $db->prepare("
    SELECT * FROM tickets 
    WHERE student_id = :student_id 
    ORDER BY created_at DESC
");
$tickets_query->execute([':student_id' => $student['student_id']]);
$tickets = $tickets_query->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$total_count = count($tickets);
$open_count = 0;
$resolved_count = 0;
foreach ($tickets as $t) {
    if ($t['status'] === 'open') $open_count++;
    if ($t['status'] === 'resolved') $resolved_count++;
}

// Handle ticket form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raise_ticket'])) {
    $reason = trim($_POST['reason'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($reason)) {
        $error_message = "Please select a reason.";
    } else {
        $attachment_path = null;
        
        // Handle file upload
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/tickets/';
            
            // Allow only specific file types
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $file_type = $_FILES['attachment']['type'];
            
            if (in_array($file_type, $allowed_types)) {
                $file_size = $_FILES['attachment']['size'];
                if ($file_size <= 5 * 1024 * 1024) { // 5MB limit
                    $file_extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                    $file_name = 'ticket_' . $student['student_id'] . '_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
                    $target_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                        $attachment_path = 'uploads/tickets/' . $file_name;
                    } else {
                        $error_message = "Failed to upload attachment.";
                    }
                } else {
                    $error_message = "Attachment file size must be less than 5MB.";
                }
            } else {
                $error_message = "Only JPG, PNG, and PDF files are allowed as attachments.";
            }
        }
        
        if (empty($error_message)) {
            try {
                $insert_stmt = $db->prepare("
                    INSERT INTO tickets (student_id, reason, description, attachment_path, status)
                    VALUES (:student_id, :reason, :description, :attachment_path, 'open')
                ");
                $result = $insert_stmt->execute([
                    ':student_id' => $student['student_id'],
                    ':reason' => $reason,
                    ':description' => !empty($description) ? $description : null,
                    ':attachment_path' => $attachment_path
                ]);
                
                if ($result) {
                    $_SESSION['ticket_success'] = "Support ticket raised successfully!";
                    header("Location: tickets.php");
                    exit();
                } else {
                    $error_message = "Failed to raise support ticket. Please try again.";
                }
            } catch (PDOException $e) {
                $error_message = "System error: " . $e->getMessage();
            }
        }
    }
}

// Get success message from session if redirected
if (isset($_SESSION['ticket_success'])) {
    $success_message = $_SESSION['ticket_success'];
    unset($_SESSION['ticket_success']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
        }
        .status-badge {
            transition: all 0.3s ease;
        }
        .status-badge:hover {
            transform: translateY(-1px);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 min-h-screen">
    <?php include '../header.php'; ?>
    <?php include '../s_sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
        <!-- Mobile Header -->
        <header class="bg-gradient-to-r from-blue-600 to-indigo-600 shadow-lg px-4 py-4 flex justify-between items-center sticky top-0 z-30 md:hidden">
            <button class="text-white text-xl" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-lg font-bold text-white flex items-center space-x-2">
                <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                    <i class="fas fa-ticket-alt text-white text-sm"></i>
                </div>
                <span>Support Tickets</span>
            </h1>
            <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                <i class="fas fa-user-graduate text-indigo-600"></i>
            </div>
        </header>

        <!-- Desktop Header -->
        <header class="hidden md:flex bg-gradient-to-r from-blue-600 to-indigo-600 shadow-lg px-8 py-5 justify-between items-center sticky top-0 z-30">
            <div class="flex-1"></div>
            <div class="flex items-center space-x-4">
                <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                    <i class="fas fa-ticket-alt text-white text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-white">Support Tickets</h1>
            </div>
            <div class="flex-1 flex justify-end items-center space-x-4">
                <div class="relative group">
                    <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center cursor-pointer group-hover:scale-110 transition-transform">
                        <i class="fas fa-user-graduate text-indigo-600"></i>
                    </div>
                    <div class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 transform group-hover:translate-y-0 translate-y-2 z-50">
                        <div class="p-3 border-b">
                            <p class="font-medium text-gray-800"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($student['student_id']) ?></p>
                        </div>
                        <a href="../stu_dash/student_profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 transition-colors">
                            <i class="fas fa-user mr-2"></i> Profile
                        </a>
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="p-4 md:p-8">
            <!-- Alert Messages -->
            <?php if ($success_message): ?>
                <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-lg shadow-md animate-fade-in flex items-center">
                    <i class="fas fa-check-circle mr-3 text-lg"></i>
                    <span><?= htmlspecialchars($success_message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg shadow-md animate-fade-in flex items-center">
                    <i class="fas fa-exclamation-circle mr-3 text-lg"></i>
                    <span><?= htmlspecialchars($error_message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-blue-100 text-sm font-medium">Total Raised</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $total_count ?></h3>
                        </div>
                        <div class="bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-ticket-alt text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in delay-100">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-indigo-100 text-sm font-medium">Open Tickets</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $open_count ?></h3>
                        </div>
                        <div class="bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-envelope-open text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-green-500 to-emerald-500 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in delay-200">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-green-100 text-sm font-medium">Resolved</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $resolved_count ?></h3>
                        </div>
                        <div class="bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tickets List & Raise Button -->
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden animate-fade-in">
                <div class="p-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">Support History</h3>
                            <p class="text-sm text-gray-500">Track and manage your support requests</p>
                        </div>
                        <button onclick="openModal()" class="px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-md transform hover:scale-105 flex items-center space-x-2">
                            <i class="fas fa-plus"></i>
                            <span>Raise Support Ticket</span>
                        </button>
                    </div>

                    <?php if (empty($tickets)): ?>
                        <div class="text-center py-16">
                            <div class="bg-gray-100 inline-block p-6 rounded-full mb-4">
                                <i class="fas fa-ticket-alt text-gray-400 text-4xl"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800 mb-1">No Tickets Found</h3>
                            <p class="text-gray-500 max-w-sm mx-auto mb-6">If you are facing issues with your course, fees, schedule, or apps, raise a support ticket and we will look into it.</p>
                            <button onclick="openModal()" class="px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-all font-semibold shadow-md">
                                Raise Your First Ticket
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($tickets as $ticket): ?>
                                <div class="bg-gray-50 border border-gray-100 rounded-2xl p-5 hover:shadow-md transition-all">
                                    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                                        <div class="flex items-start space-x-4">
                                            <div class="bg-indigo-100 text-indigo-600 p-3.5 rounded-xl">
                                                <i class="fas fa-ticket-alt text-lg"></i>
                                            </div>
                                            <div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <h4 class="font-bold text-gray-800 text-base">Ticket #<?= $ticket['id'] ?> - <?= htmlspecialchars($ticket['reason']) ?></h4>
                                                    <span class="status-badge px-3 py-1 rounded-full text-xs font-semibold
                                                        <?= $ticket['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' ?>">
                                                        <?= ucfirst($ticket['status']) ?>
                                                    </span>
                                                </div>
                                                <p class="text-xs text-gray-500 mt-1">
                                                    <i class="far fa-calendar-alt mr-1"></i> Raised on: <?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?>
                                                </p>
                                                
                                                <?php if ($ticket['description']): ?>
                                                    <div class="mt-3 text-sm text-gray-600 bg-white p-3.5 rounded-xl border border-gray-100">
                                                        <strong class="text-gray-700 block text-xs uppercase mb-1">Student Description:</strong>
                                                        <?= nl2br(htmlspecialchars($ticket['description'])) ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($ticket['attachment_path']): ?>
                                                    <div class="mt-2.5">
                                                        <a href="../<?= htmlspecialchars($ticket['attachment_path']) ?>" target="_blank" class="inline-flex items-center space-x-2 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
                                                            <i class="fas fa-paperclip"></i>
                                                            <span>View Attachment File</span>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Admin Response Section -->
                                                <?php if ($ticket['status'] === 'resolved'): ?>
                                                    <div class="mt-4 border-t border-gray-200 pt-3">
                                                        <div class="bg-green-50 p-4 rounded-xl border border-green-100">
                                                            <div class="flex items-center space-x-2 mb-1.5">
                                                                <i class="fas fa-reply text-green-600"></i>
                                                                <span class="text-xs font-bold text-green-800 uppercase tracking-wider">Admin Response:</span>
                                                                <span class="text-[10px] text-gray-500">
                                                                    Resolved on: <?= date('d M Y, h:i A', strtotime($ticket['resolved_at'])) ?>
                                                                </span>
                                                            </div>
                                                            <p class="text-sm text-green-900 leading-relaxed">
                                                                <?= nl2br(htmlspecialchars($ticket['admin_response'] ?? '')) ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Raise Ticket Modal -->
    <div id="ticketModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden transition-all duration-300">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4 overflow-hidden transform transition-all duration-300 scale-95 opacity-0" id="modalContainer">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-5">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-bold flex items-center">
                        <i class="fas fa-ticket-alt mr-2.5"></i>
                        Raise Support Ticket
                    </h2>
                    <button onclick="closeModal()" class="text-white hover:text-gray-200 text-2xl focus:outline-none">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <!-- Modal Form -->
            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Reason / Category *</label>
                    <select name="reason" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        <option value="">-- Select Category --</option>
                        <option value="Fee Related">Fee Related</option>
                        <option value="Batch/Schedule Related">Batch/Schedule Related</option>
                        <option value="Exam/Test Related">Exam/Test Related</option>
                        <option value="App/Technical Issue">App/Technical Issue</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Description (Optional)</label>
                    <textarea name="description" rows="4" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="Provide detailed explanation of the issue..."></textarea>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Attachment (Optional)</label>
                    <div class="relative border-2 border-dashed border-gray-300 rounded-xl p-5 text-center hover:border-blue-500 transition-all group cursor-pointer">
                        <input type="file" name="attachment" id="attachment" accept="image/*,.pdf" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                        <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 group-hover:text-blue-500 transition-colors"></i>
                        <p class="mt-1.5 text-sm text-gray-600">Click or drag files to upload</p>
                        <p class="text-xs text-gray-400">PDF, JPG, PNG (Max 5MB)</p>
                        <div id="file_name_display" class="mt-2 text-xs font-semibold text-green-600 hidden"></div>
                    </div>
                </div>

                <div class="flex space-x-3 pt-3">
                    <button type="button" onclick="closeModal()" class="w-1/2 px-4 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-semibold transition-all">
                        Cancel
                    </button>
                    <button type="submit" name="raise_ticket" class="w-1/2 px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-semibold shadow-md transition-all">
                        Submit Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal helpers
        function openModal() {
            const modal = document.getElementById('ticketModal');
            const container = document.getElementById('modalContainer');
            modal.classList.remove('hidden');
            setTimeout(() => {
                container.classList.remove('scale-95', 'opacity-0');
                container.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function closeModal() {
            const modal = document.getElementById('ticketModal');
            const container = document.getElementById('modalContainer');
            container.classList.remove('scale-100', 'opacity-100');
            container.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        // File upload helper
        const fileInput = document.getElementById('attachment');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                const display = document.getElementById('file_name_display');
                if (this.files && this.files[0]) {
                    display.textContent = 'Selected File: ' + this.files[0].name;
                    display.classList.remove('hidden');
                } else {
                    display.classList.add('hidden');
                }
            });
        }

        // Toggle mobile menu
        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        }
    </script>
</body>
</html>
