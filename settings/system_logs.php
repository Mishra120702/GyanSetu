<?php
// admin_view_terms_acceptance.php
session_start();
require_once '../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Query to get all students with terms acceptance status
$query = $db->prepare("
    SELECT 
        s.student_id,
        s.first_name,
        s.last_name,
        s.email,
        s.terms_accepted,
        s.terms_accepted_date,
        s.terms_accepted_ip,
        c.name as course_name,
        b.batch_name
    FROM students s
    LEFT JOIN courses c ON s.course = c.id
    LEFT JOIN batches b ON s.batch_name = b.batch_id
    ORDER BY s.terms_accepted, s.enrollment_date DESC
");
$query->execute();
$students = $query->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms Acceptance Report - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Brand Colour Theme: #1B3C53 · #234C6A · #456882 · #D2C1B6 */
        :root {
            --brand-darkest:  #1B3C53;
            --brand-dark:     #234C6A;
            --brand-mid:      #456882;
            --brand-light:    #A4C4D4;
            --brand-sand:     #D2C1B6;
            --primary-gradient: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
            --accent-gradient:  linear-gradient(135deg, #456882 0%, #234C6A 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(145deg, #e8eef3 0%, #d6e4ed 30%, #e4edf4 60%, #dce8f0 100%) !important;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Soft decorative blobs matching dashboard */
        body::before {
            content: '';
            position: fixed;
            top: -120px; left: -120px;
            width: 420px; height: 420px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(27,60,83,0.1) 0%, transparent 70%);
            animation: driftOrb1 20s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -100px; right: -100px;
            width: 380px; height: 380px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(69,104,130,0.09) 0%, transparent 70%);
            animation: driftOrb2 25s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        @keyframes driftOrb1 {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(60px,50px) scale(1.1); }
        }
        @keyframes driftOrb2 {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(-50px,-60px) scale(1.12); }
        }

        .content-wrapper {
            position: relative;
            z-index: 1;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            border: 1px solid rgba(27, 60, 83, 0.10) !important;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
        }

        /* Stats cards gradients */
        .mc-green {
            background: linear-gradient(135deg, #2d7a8a 0%, #1B3C53 100%) !important;
            box-shadow: 0 8px 24px rgba(45, 122, 138, 0.25) !important;
        }
        .mc-red {
            background: linear-gradient(135deg, #c0392b 0%, #922b21 100%) !important;
            box-shadow: 0 8px 24px rgba(192, 57, 43, 0.25) !important;
        }
        .mc-blue {
            background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
            box-shadow: 0 8px 24px rgba(69, 104, 130, 0.25) !important;
        }

        h1, h2, h3, h4, h5, h6, strong {
            color: #1B3C53;
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="content-wrapper ml-0 md:ml-64 min-h-screen p-4 md:p-6">
        <div class="container mx-auto px-2 py-4">
            <h1 class="text-3xl font-extrabold text-[#1B3C53] mb-6 flex items-center">
                <i class="fas fa-file-contract mr-3 text-[#234C6A]"></i>
                Terms & Conditions Acceptance Report
            </h1>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Accepted Stats -->
                <div class="mc-green p-6 rounded-2xl text-white transition-transform duration-300 hover:-translate-y-1">
                    <div class="flex items-center">
                        <div class="bg-white/20 p-3 rounded-xl mr-4">
                            <i class="fas fa-check-circle text-white text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-white/80 text-sm font-semibold uppercase tracking-wider">Accepted</p>
                            <p class="text-3xl font-extrabold mt-1">
                                <?php 
                                    $accepted = array_filter($students, function($s) { return $s['terms_accepted'] == 1; });
                                    echo count($accepted);
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Stats -->
                <div class="mc-red p-6 rounded-2xl text-white transition-transform duration-300 hover:-translate-y-1">
                    <div class="flex items-center">
                        <div class="bg-white/20 p-3 rounded-xl mr-4">
                            <i class="fas fa-times-circle text-white text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-white/80 text-sm font-semibold uppercase tracking-wider">Pending</p>
                            <p class="text-3xl font-extrabold mt-1">
                                <?php 
                                    $pending = array_filter($students, function($s) { return $s['terms_accepted'] == 0; });
                                    echo count($pending);
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Total Stats -->
                <div class="mc-blue p-6 rounded-2xl text-white transition-transform duration-300 hover:-translate-y-1">
                    <div class="flex items-center">
                        <div class="bg-white/20 p-3 rounded-xl mr-4">
                            <i class="fas fa-users text-white text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-white/80 text-sm font-semibold uppercase tracking-wider">Total Students</p>
                            <p class="text-3xl font-extrabold mt-1"><?php echo count($students); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="glass-card rounded-2xl overflow-hidden mb-6">
                <div class="table-responsive">
                    <table class="min-w-full divide-y divide-gray-200/50">
                        <thead class="bg-[#1B3C53]/5">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-bold text-[#1B3C53] uppercase tracking-wider">Student</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-[#1B3C53] uppercase tracking-wider">Course/Batch</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-[#1B3C53] uppercase tracking-wider">Status</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-[#1B3C53] uppercase tracking-wider">Accepted Date</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-[#1B3C53] uppercase tracking-wider">IP Address</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white/40 divide-y divide-gray-200/40">
                            <?php foreach ($students as $student): ?>
                            <tr class="hover:bg-[#234C6A]/5 transition-colors duration-150">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-[#234C6A]/10 rounded-full flex items-center justify-center">
                                            <i class="fas fa-user text-[#234C6A]"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-bold text-[#1B3C53]"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                            <div class="text-sm text-[#456882]"><?php echo htmlspecialchars($student['email']); ?></div>
                                            <div class="text-xs text-gray-500 font-mono">ID: <?php echo htmlspecialchars($student['student_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-[#1B3C53]"><?php echo htmlspecialchars($student['course_name'] ?? 'N/A'); ?></div>
                                    <div class="text-sm text-[#456882]"><?php echo htmlspecialchars($student['batch_name'] ?? 'N/A'); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($student['terms_accepted'] == 1): ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-teal-100 text-teal-800 border border-teal-200">
                                            <i class="fas fa-check mr-1.5 mt-0.5"></i> Accepted
                                        </span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800 border border-red-200">
                                            <i class="fas fa-times mr-1.5 mt-0.5"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-[#456882] font-medium">
                                    <?php echo $student['terms_accepted_date'] ? date('M j, Y g:i A', strtotime($student['terms_accepted_date'])) : '<span class="text-gray-400">Not accepted</span>'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-[#456882] font-mono">
                                    <?php echo htmlspecialchars($student['terms_accepted_ip'] ?? 'N/A'); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="mt-6 flex justify-start">
                <a href="export_terms_acceptance.php" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-[#234C6A] to-[#1B3C53] hover:from-[#456882] hover:to-[#234C6A] text-white rounded-xl font-semibold transition-all duration-300 transform hover:-translate-y-0.5 shadow-lg shadow-[#1B3C53]/25">
                    <i class="fas fa-file-export mr-2"></i>
                    Export Report
                </a>
            </div>
        </div>
    </div>
</body>
</html>