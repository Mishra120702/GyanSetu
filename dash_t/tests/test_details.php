<?php
// test_details.php - Detailed View of a Specific Test
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

// Check if user is logged in as trainer
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../logout.php");
    exit;
}

require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;

if (!$test_id) {
    header("Location: tests.php");
    exit;
}

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $trainer_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT t.* FROM trainers t WHERE t.user_id = ?");
    $stmt->execute([$trainer_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainer) {
        header("Location: ../logout.php");
        exit;
    }

    // Get specific test details
    $test_query = "
        SELECT 
            t.*,
            b.batch_name,
            COUNT(DISTINCT tq.id) as question_count
        FROM tests t
        JOIN batches b ON t.batch_id = b.batch_id
        LEFT JOIN test_questions tq ON t.id = tq.test_id
        WHERE t.id = :test_id AND b.batch_mentor_id = :trainer_id
        GROUP BY t.id
    ";
    
    $test_stmt = $db->prepare($test_query);
    $test_stmt->execute([':test_id' => $test_id, ':trainer_id' => $trainer['id']]);
    $test = $test_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$test) {
        // Test not found or not authorized
        header("Location: tests.php");
        exit;
    }

    // Get all attempts for this test
    $attempts_query = "
        SELECT 
            ta.*,
            s.first_name,
            s.last_name,
            s.student_id as student_roll,
            s.profile_picture,
            CASE 
                WHEN ta.percentage >= :passing_marks THEN 'Passed'
                ELSE 'Failed'
            END as result_status
        FROM test_attempts ta
        JOIN students s ON ta.student_id = s.student_id
        WHERE ta.test_id = :test_id AND ta.status = 'submitted'
        ORDER BY ta.percentage DESC, ta.submitted_at ASC
    ";
    
    $attempts_stmt = $db->prepare($attempts_query);
    $attempts_stmt->execute([
        ':test_id' => $test_id,
        ':passing_marks' => $test['passing_marks']
    ]);
    $attempts = $attempts_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate specific stats for this test
    $total_attempts = count($attempts);
    $passed_count = 0;
    $total_percentage = 0;
    $highest_score = 0;
    $lowest_score = 100; // Initialize to max

    foreach ($attempts as $attempt) {
        if ($attempt['percentage'] >= $test['passing_marks']) {
            $passed_count++;
        }
        $total_percentage += $attempt['percentage'];
        
        if ($attempt['percentage'] > $highest_score) $highest_score = $attempt['percentage'];
        if ($attempt['percentage'] < $lowest_score) $lowest_score = $attempt['percentage'];
    }

    if ($total_attempts === 0) {
        $lowest_score = 0;
    }

    $avg_score = $total_attempts > 0 ? round($total_percentage / $total_attempts, 2) : 0;
    $pass_rate = $total_attempts > 0 ? round(($passed_count / $total_attempts) * 100, 2) : 0;

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?= htmlspecialchars($test['title']) ?> - Test Details | ASD Academy</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        :root {
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            --brand-dark: #1B3C53;
            --brand-primary: #234C6A;
            --brand-secondary: #456882;
            --brand-soft: #D2C1B6;
        }

        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83,.13), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
                linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
            min-height: 100vh;
        }
        
        .official-gradient {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(210,193,182,.38);
            border-radius: 24px;
            box-shadow: 0 4px 20px rgba(27,60,83, 0.07);
            transition: all 0.3s ease;
        }
        
        .glass-card:hover {
            box-shadow: 0 12px 36px rgba(27,60,83, 0.15);
            transform: translateY(-2px);
        }

        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            border-radius: 24px;
            box-shadow: 0 16px 40px rgba(27, 60, 83, 0.25);
            position: relative;
            overflow: hidden;
        }

        .floating-element {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }

        .table-row-hover {
            transition: all 0.25s ease;
            border: 1px solid transparent;
        }
        
        .table-row-hover:hover {
            background: linear-gradient(135deg, rgba(27,60,83, 0.08), rgba(69, 104, 130, 0.08));
            border-color: rgba(35,76,106, 0.15);
            transform: translateX(6px);
        }

        .stat-tile {
            position: relative;
            border-radius: 16px;
            padding: 1px;
            overflow: hidden;
        }
        .stat-tile-inner {
            background: rgba(255, 255, 255, 0.94);
            border-radius: 15px;
            height: 100%;
        }
        .stat-tile-blue { background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important; }
        
        .icon-orb {
            box-shadow: 0 6px 16px -2px rgba(0,0,0,0.18), inset 0 1px 1px rgba(255,255,255,0.4);
        }

        .badge-pass { background: linear-gradient(135deg, #10b981, #34d399); color: white; }
        .badge-fail { background: linear-gradient(135deg, #ef4444, #f87171); color: white; }
        
        .card-enter {
            animation: cardEnter 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        @keyframes cardEnter {
            to { opacity: 1; transform: translateY(0); }
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            color: white;
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%);
        }
    </style>
</head>
<body class="relative overflow-x-hidden">
    <!-- Floating Background Elements -->
    <div class="fixed top-20 left-10 w-64 h-64 bg-[#456882]/30 rounded-full mix-blend-multiply filter blur-3xl opacity-20 floating-element"></div>
    <div class="fixed bottom-20 right-10 w-80 h-80 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-20 floating-element" style="animation-delay: 2s;"></div>
    
    <!-- Include Sidebar -->
    <?php include '../t_sidebar.php'; ?>
    
    <div class="flex-1 ml-0 lg:ml-64 min-h-screen transition-all duration-300 ease-in-out">
        <!-- Desktop Header -->
        <header class="hidden lg:flex official-gradient shadow-lg px-6 py-4 justify-between items-center sticky top-0 z-30">
            <div class="flex-1">
                <a href="tests.php" class="text-white hover:text-gray-200 transition-colors flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Tests
                </a>
            </div>
            
            <h1 class="text-2xl font-bold text-white flex items-center space-x-2">
                <div class="bg-white/20 backdrop-blur-sm p-2 rounded-lg">
                    <i class="fas fa-chart-bar text-white text-xl"></i>
                </div>
                <span>Test Results</span>
            </h1>
            
            <div class="flex-1 flex justify-end"></div>
        </header>

        <!-- Main Content -->
        <div class="p-4 lg:p-8">
            <div class="max-w-7xl mx-auto">
                
                <!-- Hero Banner -->
                <div class="hero-banner p-6 lg:p-8 mb-8 card-enter">
                    <div class="absolute -top-10 -right-10 w-56 h-56 bg-white opacity-10 rounded-full floating-element"></div>
                    
                    <div class="relative flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                        <div>
                            <div class="flex items-center space-x-3 mb-2">
                                <span class="px-3 py-1 bg-white/20 text-white rounded-full text-xs font-bold border border-white/30 backdrop-blur-sm">
                                    <i class="fas fa-layer-group mr-1"></i> <?= htmlspecialchars($test['batch_name']) ?>
                                </span>
                                <?php if ($test['subject']): ?>
                                <span class="px-3 py-1 bg-white/20 text-white rounded-full text-xs font-bold border border-white/30 backdrop-blur-sm">
                                    <i class="fas fa-book mr-1"></i> <?= htmlspecialchars($test['subject']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <h2 class="text-2xl lg:text-4xl font-bold text-white mb-2 drop-shadow-md">
                                <?= htmlspecialchars($test['title']) ?>
                            </h2>
                            <p class="text-[#EEF3F6] max-w-2xl text-sm lg:text-base">
                                <?= htmlspecialchars($test['description'] ?: 'No description provided.') ?>
                            </p>
                        </div>
                        
                        <div class="flex flex-wrap gap-3 mt-4 lg:mt-0">
                            <div class="px-4 py-3 bg-white text-[#234C6A] rounded-xl text-center shadow-lg min-w-[100px]">
                                <div class="text-xs text-gray-500 font-bold uppercase mb-1">Total Marks</div>
                                <div class="text-xl font-black"><?= $test['total_marks'] ?></div>
                            </div>
                            <div class="px-4 py-3 bg-white text-green-600 rounded-xl text-center shadow-lg min-w-[100px]">
                                <div class="text-xs text-gray-500 font-bold uppercase mb-1">Pass Marks</div>
                                <div class="text-xl font-black"><?= $test['passing_marks'] ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                    <div class="stat-tile stat-tile-blue card-enter" style="animation-delay: 0.1s;">
                        <div class="stat-tile-inner p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold text-gray-800"><?= $total_attempts ?></div>
                                    <div class="text-xs text-gray-500 font-semibold uppercase mt-1">Total Attempts</div>
                                </div>
                                <div class="w-12 h-12 rounded-xl icon-orb official-gradient flex items-center justify-center">
                                    <i class="fas fa-users text-white text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-tile stat-tile-blue card-enter" style="animation-delay: 0.2s;">
                        <div class="stat-tile-inner p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold text-gray-800"><?= $avg_score ?>%</div>
                                    <div class="text-xs text-gray-500 font-semibold uppercase mt-1">Avg Score</div>
                                </div>
                                <div class="w-12 h-12 rounded-xl icon-orb bg-gradient-to-br from-[#234C6A] to-[#456882] flex items-center justify-center">
                                    <i class="fas fa-chart-line text-white text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-tile stat-tile-blue card-enter" style="animation-delay: 0.3s;">
                        <div class="stat-tile-inner p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold text-gray-800"><?= $pass_rate ?>%</div>
                                    <div class="text-xs text-gray-500 font-semibold uppercase mt-1">Pass Rate</div>
                                </div>
                                <div class="w-12 h-12 rounded-xl icon-orb bg-gradient-to-br from-[#1B3C53] to-[#234C6A] flex items-center justify-center">
                                    <i class="fas fa-check-circle text-white text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-tile stat-tile-blue card-enter" style="animation-delay: 0.4s;">
                        <div class="stat-tile-inner p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold text-gray-800"><?= $highest_score ?>%</div>
                                    <div class="text-xs text-gray-500 font-semibold uppercase mt-1">Highest Score</div>
                                </div>
                                <div class="w-12 h-12 rounded-xl icon-orb bg-gradient-to-br from-yellow-400 to-orange-500 flex items-center justify-center">
                                    <i class="fas fa-trophy text-white text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Students Results Table -->
                <div class="glass-card p-6 card-enter" style="animation-delay: 0.5s;">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-list-alt text-[#234C6A] mr-3"></i>
                            Student Attempts
                        </h3>
                    </div>

                    <?php if (empty($attempts)): ?>
                        <div class="text-center py-12">
                            <div class="official-gradient p-5 rounded-full inline-block mb-4 shadow-lg">
                                <i class="fas fa-inbox text-4xl text-white"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-700 mb-2">No Attempts Yet</h3>
                            <p class="text-gray-500">No students have submitted this test.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b-2 border-gray-100">
                                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Student</th>
                                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Score</th>
                                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Status</th>
                                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Submitted At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attempts as $index => $attempt): 
                                        $rank = $index + 1;
                                    ?>
                                    <tr class="border-b border-gray-50 table-row-hover">
                                        <td class="py-3 px-4">
                                            <div class="flex items-center space-x-3">
                                                <?php if (!empty($attempt['profile_picture']) && file_exists($attempt['profile_picture'])): ?>
                                                    <img src="<?= htmlspecialchars($attempt['profile_picture']) ?>" alt="Avatar" class="w-10 h-10 rounded-lg object-cover ring-2 ring-[#D2C1B6]/50">
                                                <?php else: ?>
                                                    <div class="student-avatar shadow-sm">
                                                        <?= strtoupper(substr($attempt['first_name'], 0, 1) . substr($attempt['last_name'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="font-bold text-gray-800">
                                                        <?= htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']) ?>
                                                        <?php if ($rank === 1): ?>
                                                            <span class="ml-2 text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full font-bold">Top Scorer</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">ID: <?= htmlspecialchars($attempt['student_roll']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <div class="font-black text-lg <?= $attempt['result_status'] === 'Passed' ? 'text-green-600' : 'text-red-500' ?>">
                                                <?= $attempt['percentage'] ?>%
                                            </div>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="px-3 py-1 text-xs font-bold rounded-full <?= $attempt['result_status'] === 'Passed' ? 'badge-pass' : 'badge-fail' ?>">
                                                <?= $attempt['result_status'] ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-right text-sm text-gray-600 font-medium">
                                            <?= date('M d, Y h:i A', strtotime($attempt['submitted_at'])) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
