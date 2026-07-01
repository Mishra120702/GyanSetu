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

// Get student's leave applications
$applications_query = $db->prepare("
    SELECT * FROM leave_applications 
    WHERE student_id = :student_id 
    ORDER BY created_at DESC
");
$applications_query->execute([':student_id' => $student['student_id']]);
$applications = $applications_query->fetchAll(PDO::FETCH_ASSOC);

// Get pending count
$pending_count = 0;
foreach ($applications as $app) {
    if ($app['status'] === 'pending') $pending_count++;
}

// Get approved count
$approved_count = 0;
foreach ($applications as $app) {
    if ($app['status'] === 'approved') $approved_count++;
}

// Get rejected count
$rejected_count = 0;
foreach ($applications as $app) {
    if ($app['status'] === 'rejected') $rejected_count++;
}

// Get cancelled count
$cancelled_count = 0;
foreach ($applications as $app) {
    if ($app['status'] === 'cancelled') $cancelled_count++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Leave Applications - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(-30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.8s ease-out forwards;
        }
        
        .animate-slide-in {
            animation: slideIn 0.6s ease-out forwards;
        }
        
        .status-badge {
            transition: all 0.3s ease;
        }
        
        .status-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .gradient-border {
            position: relative;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        
        .gradient-border::before {
            content: '';
            position: absolute;
            top: -2px;
            right: -2px;
            bottom: -2px;
            left: -2px;
            background: linear-gradient(45deg, #3b82f6, #8b5cf6, #ec4899);
            border-radius: inherit;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .gradient-border:hover::before {
            opacity: 1;
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
                    <i class="fas fa-calendar-alt text-white text-sm"></i>
                </div>
                <span>My Leave Applications</span>
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
                    <i class="fas fa-calendar-alt text-white text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-white">My Leave Applications</h1>
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
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in delay-100">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-blue-100 text-sm">Total Applications</p>
                            <h3 class="text-3xl font-bold mt-2"><?= count($applications) ?></h3>
                        </div>
                        <div class="bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-file-alt text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-yellow-500 to-orange-500 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in delay-200">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-yellow-100 text-sm">Pending</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $pending_count ?></h3>
                        </div>
                        <div class="bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-green-500 to-emerald-500 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in delay-300">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-green-100 text-sm">Approved</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $approved_count ?></h3>
                        </div>
                        <div class="bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-red-500 to-pink-500 text-white p-6 rounded-2xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-fade-in delay-400">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-red-100 text-sm">Rejected</p>
                            <h3 class="text-3xl font-bold mt-2"><?= $rejected_count ?></h3>
                        </div>
                        <div class="bg-white bg-opacity-30 p-3 rounded-lg">
                            <i class="fas fa-times-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Applications List -->
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden animate-fade-in delay-500">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-bold text-gray-800">All Leave Applications</h3>
                        <a href="leaves/apply_leave.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all transform hover:scale-105">
                            <i class="fas fa-plus mr-2"></i> New Application
                        </a>
                    </div>

                    <?php if (empty($applications)): ?>
                        <div class="text-center py-12">
                            <div class="bg-gray-100 inline-block p-6 rounded-full mb-4">
                                <i class="fas fa-calendar-times text-gray-400 text-4xl"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800 mb-2">No Applications Yet</h3>
                            <p class="text-gray-600">You haven't submitted any leave applications.</p>
                            <a href="leaves/apply_leave.php" class="mt-4 inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                Apply for Leave
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($applications as $app): ?>
                                <div class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-lg transition-all transform hover:-translate-y-1 animate-fade-in">
                                    <div class="flex flex-col md:flex-row md:items-center justify-between">
                                        <div class="flex items-start space-x-4">
                                            <div class="bg-gradient-to-br from-blue-500 to-indigo-500 text-white p-3 rounded-lg">
                                                <i class="fas fa-calendar-alt"></i>
                                            </div>
                                            <div>
                                                <div class="flex items-center space-x-2">
                                                    <h4 class="font-bold text-gray-800"><?= htmlspecialchars($app['application_no']) ?></h4>
                                                    <span class="status-badge px-3 py-1 rounded-full text-xs font-medium
                                                        <?= $app['status'] === 'approved' ? 'bg-green-100 text-green-800' : 
                                                           ($app['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 
                                                           ($app['status'] === 'cancelled' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800')) ?>">
                                                        <?= ucfirst($app['status']) ?>
                                                    </span>
                                                </div>
                                                <p class="text-sm text-gray-600 mt-1">
                                                    <i class="fas fa-calendar mr-1"></i> <?= date('d M Y', strtotime($app['start_date'])) ?> - <?= date('d M Y', strtotime($app['end_date'])) ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="fas fa-clock mr-1"></i> <?= $app['total_days'] ?> day(s)
                                                </p>
                                                <p class="text-sm text-gray-600">
                                                    <i class="fas fa-tag mr-1"></i> <?= htmlspecialchars($app['reason_category']) ?>
                                                </p>
                                                <?php if ($app['status'] === 'approved' && $app['approved_at']): ?>
                                                    <p class="text-xs text-green-600 mt-1">
                                                        <i class="fas fa-check-circle"></i> Approved on <?= date('d M Y, h:i A', strtotime($app['approved_at'])) ?>
                                                    </p>
                                                <?php elseif ($app['status'] === 'rejected' && $app['rejection_reason']): ?>
                                                    <p class="text-xs text-red-600 mt-1">
                                                        <i class="fas fa-times-circle"></i> Reason: <?= htmlspecialchars($app['rejection_reason']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="mt-4 md:mt-0 flex space-x-2">
                                            <button onclick="viewApplication(<?= $app['id'] ?>)" class="px-3 py-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-colors">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($app['status'] === 'pending'): ?>
                                                <button onclick="cancelApplication(<?= $app['id'] ?>)" class="px-3 py-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-colors">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button onclick="downloadApplication(<?= $app['id'] ?>)" class="px-3 py-2 bg-gray-50 text-gray-600 rounded-lg hover:bg-gray-100 transition-colors">
                                                <i class="fas fa-download"></i>
                                            </button>
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

    <!-- View Application Modal -->
    <div id="viewModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-y-auto">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-6 rounded-t-2xl sticky top-0">
                <div class="flex justify-between items-center">
                    <h2 class="text-2xl font-bold flex items-center">
                        <i class="fas fa-file-alt mr-3"></i>
                        Application Details
                    </h2>
                    <button onclick="closeViewModal()" class="text-white hover:text-gray-200 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="p-6" id="applicationDetails">
                <!-- Dynamic content will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        // View application
        function viewApplication(id) {
            // Load application details via AJAX
            fetch(`leaves/get_application.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('applicationDetails').innerHTML = data.html;
                        document.getElementById('viewModal').classList.remove('hidden');
                    } else {
                        showToast('Failed to load application details', 'error');
                    }
                });
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.add('hidden');
        }

        // Cancel application
        function cancelApplication(id) {
            if (confirm('Are you sure you want to cancel this application?')) {
                fetch('leaves/cancel_application.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'id=' + id
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Application cancelled successfully', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast(data.message || 'Failed to cancel application', 'error');
                    }
                });
            }
        }

        // Download application
        function downloadApplication(id) {
            window.location.href = `leaves/download_application.php?id=${id}`;
        }

        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg text-white z-50 transform transition-all duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500'}`;
            toast.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.add('translate-x-0', 'opacity-100');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
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