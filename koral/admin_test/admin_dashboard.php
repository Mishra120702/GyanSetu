<?php
// admin_dashboard.php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle test deletion
if (isset($_GET['delete_test'])) {
    $testId = $_GET['delete_test'];
    try {
        $db->beginTransaction();
        
        // Delete test answers
        $db->prepare("DELETE FROM test_answers WHERE attempt_id IN (SELECT id FROM test_attempts WHERE test_id = ?)")->execute([$testId]);
        // Delete test attempts
        $db->prepare("DELETE FROM test_attempts WHERE test_id = ?")->execute([$testId]);
        // Delete test questions
        $db->prepare("DELETE FROM test_questions WHERE test_id = ?")->execute([$testId]);
        // Delete test
        $db->prepare("DELETE FROM tests WHERE id = ?")->execute([$testId]);
        
        $db->commit();
        $_SESSION['success'] = "Test deleted successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Error deleting test: " . $e->getMessage();
    }
    header("Location: admin_dashboard.php");
    exit;
}

// Get all tests with statistics and batch name
$testsStmt = $db->prepare("
    SELECT 
        t.*,
        b.batch_name,
        COUNT(DISTINCT tq.id) as question_count,
        COUNT(DISTINCT ta.id) as total_attempts,
        COUNT(DISTINCT CASE WHEN ta.status = 'submitted' THEN ta.student_id END) as completed_attempts,
        ROUND(AVG(CASE WHEN ta.status = 'submitted' THEN ta.percentage END), 2) as avg_score
    FROM tests t
    LEFT JOIN batches b ON t.batch_id = b.batch_id
    LEFT JOIN test_questions tq ON t.id = tq.test_id
    LEFT JOIN test_attempts ta ON t.id = ta.test_id
    GROUP BY t.id
    ORDER BY t.created_at DESC
");
$testsStmt->execute();
$tests = $testsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all batches for filter
$batchesStmt = $db->query("SELECT DISTINCT batch_id, batch_name FROM batches WHERE status IN ('ongoing', 'upcoming') ORDER BY batch_name");
$batches = $batchesStmt->fetchAll(PDO::FETCH_ASSOC);

// Filter tests if batch selected
$filterBatch = $_GET['batch'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$filteredTests = $tests;
if ($filterBatch) {
    $filteredTests = array_filter($filteredTests, fn($t) => $t['batch_id'] == $filterBatch);
}
if ($filterStatus) {
    if ($filterStatus == 'active') {
        $filteredTests = array_filter($filteredTests, fn($t) => $t['is_active'] == 1);
    } elseif ($filterStatus == 'inactive') {
        $filteredTests = array_filter($filteredTests, fn($t) => $t['is_active'] == 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Test Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="none"/><path d="M0,50 Q25,40 50,50 T100,50" stroke="white" stroke-width="0.5" fill="none" opacity="0.1"/><path d="M0,30 Q25,20 50,30 T100,30" stroke="white" stroke-width="0.5" fill="none" opacity="0.1"/></svg>');
            pointer-events: none;
            z-index: -1;
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .test-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 5px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .test-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.7s ease;
        }
        
        .test-card:hover::before {
            left: 100%;
        }
        
        .test-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            border-left-color: #667eea;
        }
        
        .card-enter {
            animation: cardEnter 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        
        @keyframes cardEnter {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .status-active {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            font-weight: 600;
        }
        
        .status-inactive {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
            font-weight: 600;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            animation: modalBgFade 0.3s ease;
        }
        
        @keyframes modalBgFade {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 0;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            transform: translateY(-20px);
            animation: modalSlide 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        @keyframes modalSlide {
            to { transform: translateY(0); }
        }
        
        .filter-section {
            transition: all 0.3s ease;
        }
        
        .filter-section:hover {
            transform: translateY(-2px);
        }
        
        .stats-number {
            position: relative;
            overflow: hidden;
        }
        
        .stats-number::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transform: rotate(45deg);
            transition: transform 0.6s ease;
        }
        
        .stats-number:hover::after {
            transform: rotate(45deg) translate(50%, 50%);
        }
        
        .search-bar {
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .search-bar:focus-within {
            background: white;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .dropdown-menu {
            animation: dropdownFade 0.2s ease-out;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        @keyframes dropdownFade {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .float {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .progress-ring {
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .floating-btn {
            animation: float 3s ease-in-out infinite;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .highlight {
            position: relative;
        }
        
        .highlight::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }
        
        .highlight:hover::after {
            transform: scaleX(1);
        }
        
        .batch-badge {
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
        }
    </style>
</head>
<body class="min-h-screen">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen p-4 md:p-8">
        <!-- Animated Background Elements -->
        <div class="fixed top-0 right-0 w-96 h-96 bg-purple-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-pulse"></div>
        <div class="fixed bottom-0 left-0 w-96 h-96 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float"></div>
        
        <div class="max-w-7xl mx-auto relative z-10">
            <!-- Header with enhanced design -->
            <div class="glass-effect rounded-2xl p-6 md:p-8 mb-8 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-blue-400 to-purple-400 opacity-10 rounded-full -mt-16 -mr-16"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 bg-gradient-to-tr from-blue-400 to-purple-400 opacity-10 rounded-full -mb-24 -ml-24"></div>
                
                <div class="flex flex-col md:flex-row md:items-center justify-between relative z-10">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-800 mb-2 gradient-text">Test Management Dashboard</h1>
                        <p class="text-gray-600 text-lg flex items-center">
                            <i class="fas fa-chart-line text-purple-500 mr-2"></i>
                            Create, manage, and monitor MCQ tests with advanced analytics
                        </p>
                    </div>
                    <div class="mt-6 md:mt-0">
                        <a href="create_test.php" 
                           class="floating-btn bg-gradient-to-r from-blue-600 to-purple-600 text-white px-6 py-3 rounded-xl hover:from-blue-700 hover:to-purple-700 transition-all duration-300 flex items-center shadow-lg group">
                            <i class="fas fa-plus-circle text-xl mr-3 group-hover:rotate-90 transition-transform duration-300"></i>
                            <span class="font-semibold">Create New Test</span>
                            <i class="fas fa-arrow-right ml-3 transform group-hover:translate-x-2 transition-transform duration-300"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-8">
                    <div class="glass-card p-4 rounded-xl">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-100 rounded-lg mr-4">
                                <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-800"><?= count($tests) ?></div>
                                <div class="text-gray-600 text-sm">Total Tests</div>
                            </div>
                        </div>
                    </div>
                    <div class="glass-card p-4 rounded-xl">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-100 rounded-lg mr-4">
                                <i class="fas fa-check-circle text-green-600 text-xl"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-800"><?= count(array_filter($tests, fn($t) => $t['is_active'])) ?></div>
                                <div class="text-gray-600 text-sm">Active Tests</div>
                            </div>
                        </div>
                    </div>
                    <div class="glass-card p-4 rounded-xl">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-100 rounded-lg mr-4">
                                <i class="fas fa-users text-purple-600 text-xl"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-800"><?= array_sum(array_column($tests, 'total_attempts')) ?></div>
                                <div class="text-gray-600 text-sm">Total Attempts</div>
                            </div>
                        </div>
                    </div>
                    <div class="glass-card p-4 rounded-xl">
                        <div class="flex items-center">
                            <div class="p-3 bg-yellow-100 rounded-lg mr-4">
                                <i class="fas fa-star text-yellow-600 text-xl"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-800">
                                    <?= round(array_sum(array_filter(array_column($tests, 'avg_score'))) / max(count(array_filter(array_column($tests, 'avg_score'))), 1), 2) ?>%
                                </div>
                                <div class="text-gray-600 text-sm">Avg Score</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alerts with animations -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-gradient-to-r from-green-400 to-emerald-500 text-white px-6 py-4 rounded-xl mb-6 transform transition-all duration-300 animate-[slideIn_0.5s_ease-out] shadow-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-xl mr-3"></i>
                        <span class="font-medium"><?= $_SESSION['success'] ?></span>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-gradient-to-r from-red-400 to-pink-500 text-white px-6 py-4 rounded-xl mb-6 transform transition-all duration-300 animate-[slideIn_0.5s_ease-out] shadow-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-xl mr-3"></i>
                        <span class="font-medium"><?= $_SESSION['error'] ?></span>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Enhanced Filter & Search Section -->
            <div class="glass-effect rounded-2xl p-6 mb-8 shadow-xl">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 md:mb-0 highlight">
                        <i class="fas fa-filter text-blue-500 mr-2"></i>
                        Filter & Search Tests
                    </h2>
                    
                    <!-- Search Bar -->
                    <div class="search-bar rounded-full px-4 py-2 w-full md:w-auto">
                        <div class="flex items-center">
                            <i class="fas fa-search text-gray-400 mr-2"></i>
                            <input type="text" 
                                   id="searchInput" 
                                   placeholder="Search tests by title, subject, batch, or description..." 
                                   class="bg-transparent outline-none w-full text-gray-700 placeholder-gray-400">
                            <div id="searchClear" class="hidden ml-2 cursor-pointer text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Form -->
                <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="filter-section">
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-users mr-2 text-blue-500"></i>
                            Filter by Batch
                        </label>
                        <select name="batch" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300">
                            <option value="">All Batches</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?= $batch['batch_id'] ?>" <?= $filterBatch == $batch['batch_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($batch['batch_name']) ?> (<?= $batch['batch_id'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-section">
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-power-off mr-2 text-green-500"></i>
                            Filter by Status
                        </label>
                        <select name="status" class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300">
                            <option value="">All Status</option>
                            <option value="active" <?= $filterStatus == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $filterStatus == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="filter-section flex items-end space-x-3">
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-blue-500 to-purple-600 text-white px-6 py-3 rounded-xl hover:from-blue-600 hover:to-purple-700 transition-all duration-300 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5 flex items-center justify-center">
                            <i class="fas fa-sliders-h mr-2"></i>
                            Apply Filters
                        </button>
                        <a href="admin_dashboard.php" 
                           class="flex-1 bg-gradient-to-r from-gray-200 to-gray-300 text-gray-700 px-6 py-3 rounded-xl hover:from-gray-300 hover:to-gray-400 transition-all duration-300 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5 flex items-center justify-center">
                            <i class="fas fa-redo mr-2"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Tests Grid Header -->
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-list-alt text-purple-500 mr-3"></i>
                    All Tests
                    <span class="ml-3 bg-gradient-to-r from-blue-500 to-purple-500 text-white text-sm font-semibold px-3 py-1 rounded-full">
                        <?= count($filteredTests) ?> found
                    </span>
                </h2>
                
                <div class="flex items-center space-x-4">
                    <button id="gridViewBtn" class="p-2 rounded-lg bg-blue-100 text-blue-600">
                        <i class="fas fa-th-large"></i>
                    </button>
                    <button id="listViewBtn" class="p-2 rounded-lg text-gray-400 hover:text-gray-600">
                        <i class="fas fa-list"></i>
                    </button>
                </div>
            </div>
            
            <!-- Tests Grid -->
            <div id="testsContainer" class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 transition-all duration-500">
                <?php if (empty($filteredTests)): ?>
                    <div class="col-span-full glass-card rounded-2xl p-12 text-center transform transition-all duration-500 card-enter">
                        <div class="bg-gradient-to-br from-blue-100 to-purple-100 p-4 rounded-full inline-block mb-6 pulse">
                            <i class="fas fa-file-alt text-4xl text-gradient-to-r from-blue-500 to-purple-500"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-700 mb-3">No tests found</h3>
                        <p class="text-gray-500 mb-6">Create your first test or adjust your filters</p>
                        <a href="create_test.php" class="inline-flex items-center bg-gradient-to-r from-blue-500 to-purple-600 text-white px-6 py-3 rounded-xl hover:from-blue-600 hover:to-purple-700 transition-all duration-300 font-semibold">
                            <i class="fas fa-plus mr-2"></i>
                            Create New Test
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($filteredTests as $index => $test): ?>
                        <div class="test-card glass-card rounded-2xl p-6 transform transition-all duration-500 card-enter" style="animation-delay: <?= $index * 0.1 ?>s;" 
                             data-searchable="<?= htmlspecialchars(strtolower($test['title'] . ' ' . $test['subject'] . ' ' . $test['description'] . ' ' . ($test['batch_name'] ?? '') . ' ' . ($test['batch_id'] ?? ''))) ?>">
                            <!-- Test Header -->
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex-1">
                                    <div class="flex items-center mb-3">
                                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-purple-500 flex items-center justify-center text-white font-bold mr-3 shadow-md">
                                            <?= strtoupper(substr($test['title'], 0, 1)) ?>
                                        </div>
                                        <div class="flex-1">
                                            <h3 class="text-lg font-bold text-gray-800 truncate">
                                                <?= htmlspecialchars($test['title']) ?>
                                            </h3>
                                            <div class="flex items-center space-x-2 mt-1">
                                                <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $test['is_active'] ? 'status-active' : 'status-inactive' ?> shadow-sm">
                                                    <?= $test['is_active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                                <?php if ($test['batch_name']): ?>
                                                    <span class="px-3 py-1 text-xs bg-gradient-to-r from-blue-100 to-blue-50 text-blue-700 font-semibold rounded-full batch-badge" title="<?= htmlspecialchars($test['batch_name']) ?> (<?= htmlspecialchars($test['batch_id'] ?? 'N/A') ?>)">
                                                        <i class="fas fa-users mr-1"></i>
                                                        <?= htmlspecialchars(strlen($test['batch_name']) > 20 ? substr($test['batch_name'], 0, 20) . '...' : $test['batch_name']) ?>
                                                        <?php if ($test['batch_id']): ?>
                                                            <span class="text-blue-500 ml-1">(<?= htmlspecialchars($test['batch_id']) ?>)</span>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php elseif ($test['batch_id']): ?>
                                                    <span class="px-3 py-1 text-xs bg-gradient-to-r from-blue-100 to-blue-50 text-blue-700 font-semibold rounded-full">
                                                        <i class="fas fa-users mr-1"></i>
                                                        <?= htmlspecialchars($test['batch_id']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Action Menu -->
                                <div class="relative">
                                    <button onclick="toggleDropdown(<?= $test['id'] ?>)" 
                                            class="p-2 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-700 transition-colors">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div id="dropdown-<?= $test['id'] ?>" 
                                         class="dropdown-menu absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-xl z-50 hidden border border-gray-100">
                                        <div class="p-2">
                                            <a href="view_test_results.php?test_id=<?= $test['id'] ?>" 
                                               class="flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-blue-50 rounded-lg transition-colors group">
                                                <i class="fas fa-chart-bar mr-3 text-blue-500 group-hover:text-blue-600"></i>
                                                <span>View Detailed Results</span>
                                                <i class="fas fa-arrow-right ml-auto text-gray-400 group-hover:text-blue-500"></i>
                                            </a>
                                            <a href="edit_test.php?test_id=<?= $test['id'] ?>" 
                                               class="flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-green-50 rounded-lg transition-colors group">
                                                <i class="fas fa-edit mr-3 text-green-500 group-hover:text-green-600"></i>
                                                <span>Edit Test Settings</span>
                                                <i class="fas fa-external-link-alt ml-auto text-gray-400 group-hover:text-green-500"></i>
                                            </a>
                                            <button onclick="toggleTestStatus(<?= $test['id'] ?>, <?= $test['is_active'] ?>)" 
                                                    class="flex items-center w-full px-4 py-3 text-sm text-gray-700 hover:bg-yellow-50 rounded-lg transition-colors group">
                                                <i class="fas fa-power-off mr-3 text-yellow-500 group-hover:text-yellow-600"></i>
                                                <span><?= $test['is_active'] ? 'Deactivate Test' : 'Activate Test' ?></span>
                                                <i class="fas fa-sync-alt ml-auto text-gray-400 group-hover:text-yellow-500"></i>
                                            </button>
                                            <div class="border-t my-2"></div>
                                            <button onclick="confirmDelete(<?= $test['id'] ?>, '<?= htmlspecialchars(addslashes($test['title'])) ?>')" 
                                                    class="flex items-center w-full px-4 py-3 text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors group">
                                                <i class="fas fa-trash mr-3 text-red-500"></i>
                                                <span>Delete Test</span>
                                                <i class="fas fa-exclamation-triangle ml-auto text-red-400"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Test Details -->
                            <div class="space-y-4 mb-6">
                                <div class="flex flex-wrap gap-2">
                                    <?php if ($test['subject']): ?>
                                    <span class="flex items-center px-3 py-1.5 bg-gray-100 rounded-lg text-sm text-gray-700">
                                        <i class="fas fa-book mr-2 text-blue-500"></i>
                                        <?= htmlspecialchars($test['subject']) ?>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($test['batch_name']): ?>
                                    <span class="flex items-center px-3 py-1.5 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg text-sm text-blue-700 border border-blue-200">
                                        <i class="fas fa-user-friends mr-2 text-blue-500"></i>
                                        <span class="truncate max-w-[120px]" title="<?= htmlspecialchars($test['batch_name']) ?>">
                                            <?= htmlspecialchars(strlen($test['batch_name']) > 15 ? substr($test['batch_name'], 0, 15) . '...' : $test['batch_name']) ?>
                                        </span>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <span class="flex items-center px-3 py-1.5 bg-gray-100 rounded-lg text-sm text-gray-700">
                                        <i class="fas fa-question-circle mr-2 text-green-500"></i>
                                        <?= $test['question_count'] ?> questions
                                    </span>
                                    
                                    <?php if ($test['duration_minutes']): ?>
                                    <span class="flex items-center px-3 py-1.5 bg-gray-100 rounded-lg text-sm text-gray-700">
                                        <i class="fas fa-clock mr-2 text-yellow-500"></i>
                                        <?= $test['duration_minutes'] ?> mins
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($test['total_marks']): ?>
                                    <span class="flex items-center px-3 py-1.5 bg-gray-100 rounded-lg text-sm text-gray-700">
                                        <i class="fas fa-star mr-2 text-purple-500"></i>
                                        <?= $test['total_marks'] ?> marks
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($test['description']): ?>
                                    <p class="text-gray-600 text-sm line-clamp-2 bg-gray-50 p-3 rounded-lg">
                                        <i class="fas fa-align-left mr-2 text-gray-400"></i>
                                        <?= htmlspecialchars(substr($test['description'], 0, 120)) ?>...
                                    </p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Stats Cards -->
                            <div class="grid grid-cols-3 gap-3 mb-6">
                                <div class="stats-number text-center bg-gradient-to-br from-blue-50 to-blue-100 p-3 rounded-xl">
                                    <div class="text-xl font-bold text-blue-700"><?= $test['total_attempts'] ?></div>
                                    <div class="text-xs text-blue-600 font-semibold">Attempts</div>
                                </div>
                                <div class="stats-number text-center bg-gradient-to-br from-green-50 to-green-100 p-3 rounded-xl">
                                    <div class="text-xl font-bold text-green-700"><?= $test['completed_attempts'] ?></div>
                                    <div class="text-xs text-green-600 font-semibold">Completed</div>
                                </div>
                                <div class="stats-number text-center bg-gradient-to-br from-purple-50 to-purple-100 p-3 rounded-xl">
                                    <div class="text-xl font-bold text-purple-700"><?= $test['avg_score'] ?? '0' ?>%</div>
                                    <div class="text-xs text-purple-600 font-semibold">Avg Score</div>
                                </div>
                            </div>
                            
                            <!-- Footer -->
                            <div class="border-t pt-4">
                                <div class="flex justify-between items-center text-xs text-gray-500">
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-plus mr-2 text-gray-400"></i>
                                        <?= date('M j, Y', strtotime($test['created_at'])) ?>
                                    </div>
                                    <?php if ($test['start_date']): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-hourglass-start mr-2 text-gray-400"></i>
                                            <?= date('M j', strtotime($test['start_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex items-center">
                                        <i class="fas fa-chart-line mr-2 text-gray-400"></i>
                                        Last updated: <?= date('M j', strtotime($test['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-red-50 to-pink-50 p-8 rounded-t-2xl">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-16 h-16 bg-gradient-to-r from-red-500 to-pink-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-white text-2xl"></i>
                        </div>
                    </div>
                    <div class="ml-6">
                        <h3 class="text-2xl font-bold text-red-800">Delete Test</h3>
                        <p id="deleteMessage" class="text-red-600 mt-2 font-medium"></p>
                    </div>
                </div>
            </div>
            
            <div class="p-8">
                <div class="bg-gradient-to-r from-red-100 to-pink-50 border-l-4 border-red-500 p-4 rounded-r-lg mb-6">
                    <div class="flex">
                        <i class="fas fa-radiation text-red-500 mr-3 mt-1"></i>
                        <div>
                            <h4 class="font-bold text-red-700 mb-1">Warning: This action cannot be undone</h4>
                            <p class="text-red-600 text-sm">
                                All test attempts, questions, results, and statistics will be permanently deleted.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-4">
                    <button onclick="closeModal('deleteModal')" 
                            class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 hover:border-gray-400 transition-all duration-300 font-semibold">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </button>
                    <a href="#" id="confirmDeleteBtn" 
                       class="px-6 py-3 bg-gradient-to-r from-red-600 to-pink-600 text-white rounded-xl hover:from-red-700 hover:to-pink-700 transition-all duration-300 font-semibold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                        <i class="fas fa-trash mr-2"></i>
                        Delete Permanently
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Card animation delays
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card-enter');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Search functionality
            const searchInput = document.getElementById('searchInput');
            const searchClear = document.getElementById('searchClear');
            const testCards = document.querySelectorAll('.test-card');
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                let visibleCards = 0;
                
                testCards.forEach(card => {
                    const searchableText = card.getAttribute('data-searchable') || '';
                    if (searchableText.includes(searchTerm) || searchTerm === '') {
                        card.style.display = 'block';
                        visibleCards++;
                        // Add animation
                        card.style.animation = 'cardEnter 0.5s ease forwards';
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Show/hide clear button
                searchClear.classList.toggle('hidden', searchTerm === '');
                
                // Update count
                const countBadge = document.querySelector('.bg-gradient-to-r.from-blue-500');
                if (countBadge) {
                    countBadge.textContent = `${visibleCards} found`;
                }
            });
            
            searchClear.addEventListener('click', function() {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.focus();
            });
            
            // View toggle
            const gridViewBtn = document.getElementById('gridViewBtn');
            const listViewBtn = document.getElementById('listViewBtn');
            const testsContainer = document.getElementById('testsContainer');
            
            gridViewBtn.addEventListener('click', function() {
                testsContainer.className = 'grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 transition-all duration-500';
                gridViewBtn.className = 'p-2 rounded-lg bg-blue-100 text-blue-600';
                listViewBtn.className = 'p-2 rounded-lg text-gray-400 hover:text-gray-600';
            });
            
            listViewBtn.addEventListener('click', function() {
                testsContainer.className = 'grid grid-cols-1 gap-6 transition-all duration-500';
                listViewBtn.className = 'p-2 rounded-lg bg-blue-100 text-blue-600';
                gridViewBtn.className = 'p-2 rounded-lg text-gray-400 hover:text-gray-600';
            });
            
            // Initialize tooltips for truncated batch names
            document.querySelectorAll('.batch-badge').forEach(badge => {
                badge.addEventListener('mouseenter', function() {
                    if (this.scrollWidth > this.clientWidth) {
                        const tooltip = document.createElement('div');
                        tooltip.className = 'fixed bg-gray-900 text-white px-3 py-2 rounded-lg text-sm z-50';
                        tooltip.textContent = this.getAttribute('title');
                        document.body.appendChild(tooltip);
                        
                        const rect = this.getBoundingClientRect();
                        tooltip.style.top = (rect.top - 40) + 'px';
                        tooltip.style.left = (rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)) + 'px';
                        
                        this._tooltip = tooltip;
                    }
                });
                
                badge.addEventListener('mouseleave', function() {
                    if (this._tooltip) {
                        this._tooltip.remove();
                        this._tooltip = null;
                    }
                });
            });
        });
        
        function toggleDropdown(testId) {
            const dropdown = document.getElementById(`dropdown-${testId}`);
            dropdown.classList.toggle('hidden');
            
            // Close other dropdowns with animation
            document.querySelectorAll('[id^="dropdown-"]').forEach(d => {
                if (d.id !== `dropdown-${testId}` && !d.classList.contains('hidden')) {
                    d.style.animation = 'dropdownFade 0.2s ease-out reverse';
                    setTimeout(() => {
                        d.classList.add('hidden');
                        d.style.animation = '';
                    }, 200);
                }
            });
        }
        
        function confirmDelete(testId, testTitle) {
            const modal = document.getElementById('deleteModal');
            const message = document.getElementById('deleteMessage');
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            
            message.textContent = `Delete "${testTitle}"?`;
            confirmBtn.href = `admin_dashboard.php?delete_test=${testId}`;
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.animation = 'modalSlide 0.4s cubic-bezier(0.4, 0, 0.2, 1) reverse forwards';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.animation = '';
                document.body.style.overflow = 'auto';
            }, 400);
        }
        
        function toggleTestStatus(testId, isActive) {
            const button = event.target.closest('button');
            const originalHTML = button.innerHTML;
            
            // Show loading state
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-3"></i> Updating...';
            button.disabled = true;
            
            fetch('toggle_test_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    test_id: testId,
                    status: isActive ? 0 : 1
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Success animation
                    button.innerHTML = '<i class="fas fa-check mr-3"></i> Updated!';
                    button.classList.remove('hover:bg-yellow-50');
                    button.classList.add('bg-green-50', 'text-green-700');
                    
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    // Error state
                    button.innerHTML = '<i class="fas fa-exclamation-circle mr-3"></i> Error!';
                    button.classList.remove('hover:bg-yellow-50');
                    button.classList.add('bg-red-50', 'text-red-700');
                    
                    setTimeout(() => {
                        button.innerHTML = originalHTML;
                        button.disabled = false;
                        button.classList.remove('bg-red-50', 'text-red-700');
                        button.classList.add('hover:bg-yellow-50');
                    }, 2000);
                    
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                button.innerHTML = '<i class="fas fa-exclamation-circle mr-3"></i> Network Error!';
                button.classList.remove('hover:bg-yellow-50');
                button.classList.add('bg-red-50', 'text-red-700');
                
                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.disabled = false;
                    button.classList.remove('bg-red-50', 'text-red-700');
                    button.classList.add('hover:bg-yellow-50');
                }, 2000);
            });
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.relative')) {
                document.querySelectorAll('[id^="dropdown-"]').forEach(d => {
                    if (!d.classList.contains('hidden')) {
                        d.style.animation = 'dropdownFade 0.2s ease-out reverse';
                        setTimeout(() => {
                            d.classList.add('hidden');
                            d.style.animation = '';
                        }, 200);
                    }
                });
            }
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    closeModal(modal.id);
                }
            });
        }
        
        // Add hover effect to stats numbers
        document.querySelectorAll('.stats-number').forEach(stat => {
            stat.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
            });
            
            stat.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>