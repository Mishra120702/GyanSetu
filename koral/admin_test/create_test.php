<?php
// create_test.php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Insert test
        $stmt = $db->prepare("
            INSERT INTO tests (title, description, batch_id, subject, total_marks, passing_marks, 
                             duration_minutes, max_attempts, start_date, end_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        $stmt->execute([
            $_POST['title'],
            $_POST['description'],
            $_POST['batch_id'],
            $_POST['subject'],
            $_POST['total_marks'],
            $_POST['passing_marks'],
            $_POST['duration_minutes'],
            $_POST['max_attempts'],
            $startDate,
            $endDate,
            $_SESSION['user_id']
        ]);
        
        $testId = $db->lastInsertId();
        
        // Process all questions from form (including those from CSV)
        $questionCount = count($_POST['questions']);
        $totalMarks = 0;
        
        $questionStmt = $db->prepare("
            INSERT INTO test_questions (test_id, question_text, option_a, option_b, option_c, option_d, 
                                      correct_answer, marks, explanation, question_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        for ($i = 0; $i < $questionCount; $i++) {
            if (!empty($_POST['questions'][$i])) {
                $questionStmt->execute([
                    $testId,
                    $_POST['questions'][$i],
                    $_POST['options_a'][$i],
                    $_POST['options_b'][$i],
                    $_POST['options_c'][$i],
                    $_POST['options_d'][$i],
                    $_POST['correct_answers'][$i],
                    $_POST['marks'][$i],
                    $_POST['explanations'][$i] ?? '',
                    $i + 1
                ]);
                $totalMarks += $_POST['marks'][$i];
            }
        }
        
        // Update total marks
        $updateStmt = $db->prepare("UPDATE tests SET total_marks = ? WHERE id = ?");
        $updateStmt->execute([$totalMarks, $testId]);
        
        $db->commit();
        $success = "Test created successfully with $questionCount questions!";
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error creating test: " . $e->getMessage();
    }
}

// Get batches for dropdown
$batchStmt = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name");
$batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create MCQ Test - Admin</title>
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
        
        .question-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .question-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.7s ease;
        }
        
        .question-card:hover::before {
            left: 100%;
        }
        
        .question-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
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
        
        .option-correct {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border: 2px solid #10b981;
            transform: scale(1.02);
        }
        
        .question-number {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 4px 6px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
        }
        
        .question-number:hover {
            transform: rotate(15deg) scale(1.1);
        }
        
        .floating-btn {
            animation: float 3s ease-in-out infinite;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
            100% { transform: translateY(0px); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
        
        .input-focus {
            transition: all 0.3s ease;
        }
        
        .input-focus:focus {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .option-input {
            transition: all 0.3s ease;
        }
        
        .option-input:focus {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .stats-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            animation: badgePulse 2s infinite alternate;
        }
        
        @keyframes badgePulse {
            from { box-shadow: 0 0 10px rgba(102, 126, 234, 0.5); }
            to { box-shadow: 0 0 20px rgba(102, 126, 234, 0.8); }
        }
        
        .progress-ring {
            transition: stroke-dashoffset 0.5s ease;
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
        
        .shake {
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .success-check {
            animation: successCheck 0.5s ease-in-out forwards;
        }
        
        @keyframes successCheck {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .file-drop-zone {
            border: 3px dashed #cbd5e0;
            border-radius: 16px;
            padding: 60px 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .file-drop-zone:hover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
            transform: translateY(-2px);
        }
        
        .file-drop-zone.active {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.05);
        }
        
        .file-drop-zone.dragover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.1);
        }
        
        .csv-preview {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .csv-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .csv-table th {
            background: #f8fafc;
            position: sticky;
            top: 0;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.05em;
        }
        
        .csv-table th, .csv-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        
        .csv-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .template-modal-content {
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .question-card.csv-imported {
            border-left: 4px solid #10b981;
        }
        
        .question-card.csv-imported .question-number {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Animated Background Elements -->
    <div class="fixed top-0 right-0 w-96 h-96 bg-purple-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-pulse"></div>
    <div class="fixed bottom-0 left-0 w-96 h-96 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float"></div>
    
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen p-4 md:p-8">
        <div class="max-w-7xl mx-auto relative z-10">
            <!-- Header -->
            <div class="glass-effect rounded-2xl p-6 md:p-8 mb-8 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-blue-400 to-purple-400 opacity-10 rounded-full -mt-16 -mr-16"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 bg-gradient-to-tr from-blue-400 to-purple-400 opacity-10 rounded-full -mb-24 -ml-24"></div>
                
                <div class="flex flex-col md:flex-row md:items-center justify-between relative z-10">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-800 mb-2 gradient-text">
                            Create New MCQ Test
                        </h1>
                        <p class="text-gray-600 text-lg flex items-center">
                            <i class="fas fa-puzzle-piece text-purple-500 mr-2"></i>
                            Design comprehensive tests with detailed questions and analytics
                        </p>
                    </div>
                    <div class="mt-6 md:mt-0">
                        <a href="admin_dashboard.php" 
                           class="inline-flex items-center bg-gradient-to-r from-gray-200 to-gray-300 text-gray-700 px-6 py-3 rounded-xl hover:from-gray-300 hover:to-gray-400 transition-all duration-300 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <i class="fas fa-arrow-left mr-3"></i>
                            <span>Back to Dashboard</span>
                        </a>
                    </div>
                </div>
                
                <!-- Progress Indicator -->
                <div class="mt-8">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700">Test Creation Progress</span>
                        <span class="text-sm font-bold text-blue-600" id="progressPercentage">0%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-purple-600 h-2 rounded-full transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>
            </div>
            
            <!-- Alerts with animations -->
            <?php if ($error): ?>
                <div class="bg-gradient-to-r from-red-400 to-pink-500 text-white px-6 py-4 rounded-xl mb-6 transform transition-all duration-300 animate-[slideIn_0.5s_ease-out] shadow-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-xl mr-3"></i>
                        <span class="font-medium"><?= htmlspecialchars($error) ?></span>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-gradient-to-r from-green-400 to-emerald-500 text-white px-6 py-4 rounded-xl mb-6 transform transition-all duration-300 animate-[slideIn_0.5s_ease-out] shadow-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-xl mr-3 success-check"></i>
                        <span class="font-medium"><?= htmlspecialchars($success) ?></span>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Test Creation Form -->
            <form method="POST" id="testForm" class="space-y-8" enctype="multipart/form-data">
                <!-- Test Details Card -->
                <div class="glass-card rounded-2xl p-6 md:p-8 transform transition-all duration-300 hover:shadow-xl">
                    <div class="flex items-center mb-6">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-purple-500 flex items-center justify-center text-white text-xl mr-4 shadow-md">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-1 highlight">Test Configuration</h2>
                            <p class="text-gray-600">Configure basic test settings and parameters</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-heading mr-2 text-blue-500"></i>
                                Test Title <span class="text-red-500 ml-1">*</span>
                            </label>
                            <input type="text" name="title" required 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus"
                                   placeholder="Enter test title">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-book mr-2 text-green-500"></i>
                                Subject
                            </label>
                            <input type="text" name="subject" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus"
                                   placeholder="e.g., Mathematics, Physics">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-users mr-2 text-purple-500"></i>
                                Batch Assignment
                            </label>
                            <select name="batch_id" 
                                    class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                                <option value="">All Batches (Public Test)</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= $batch['batch_id'] ?>"><?= htmlspecialchars($batch['batch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-clock mr-2 text-yellow-500"></i>
                                Duration (Minutes)
                            </label>
                            <input type="number" name="duration_minutes" value="60" min="1" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-redo mr-2 text-orange-500"></i>
                                Maximum Attempts
                            </label>
                            <input type="number" name="max_attempts" value="1" min="1" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-trophy mr-2 text-green-500"></i>
                                Passing Marks
                            </label>
                            <input type="number" name="passing_marks" value="40" min="0" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-calendar-plus mr-2 text-blue-500"></i>
                                Start Date (Optional)
                            </label>
                            <input type="datetime-local" name="start_date" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-calendar-minus mr-2 text-red-500"></i>
                                End Date (Optional)
                            </label>
                            <input type="datetime-local" name="end_date" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-align-left mr-2 text-gray-500"></i>
                            Description
                        </label>
                        <textarea name="description" rows="3" 
                                  class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus"
                                  placeholder="Provide a brief description of the test..."></textarea>
                    </div>
                </div>
                
                <!-- CSV Upload Section -->
                <div class="glass-card rounded-2xl p-6 md:p-8 transform transition-all duration-300 hover:shadow-xl">
                    <div class="flex items-center mb-6">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-teal-500 flex items-center justify-center text-white text-xl mr-4 shadow-md">
                            <i class="fas fa-file-csv"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-1 highlight">Bulk Upload Questions</h2>
                            <p class="text-gray-600">Upload a CSV file to add questions in bulk (Optional)</p>
                        </div>
                        <button type="button" onclick="showTemplateModal()" 
                                class="ml-auto bg-gradient-to-r from-blue-500 to-purple-500 text-white px-5 py-2 rounded-xl hover:from-blue-600 hover:to-purple-600 transition-all duration-300 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5 flex items-center">
                            <i class="fas fa-download mr-2"></i>
                            Download Template
                        </button>
                    </div>
                    
                    <!-- File Upload Zone -->
                    <div class="file-drop-zone" id="fileDropZone">
                        <div class="mb-4">
                            <i class="fas fa-cloud-upload-alt text-5xl text-blue-500 mb-4"></i>
                            <h3 class="text-xl font-bold text-gray-800 mb-2">Drag & Drop CSV File</h3>
                            <p class="text-gray-600 mb-4">or click to browse your files</p>
                            <div class="text-sm text-gray-500 mb-6">
                                <i class="fas fa-info-circle mr-2"></i>
                                Supported format: CSV (Max 5MB)
                            </div>
                        </div>
                        
                        <input type="file" name="csv_file" id="csvFileInput" accept=".csv" class="hidden" onchange="handleFileSelect(event)">
                        
                        <button type="button" onclick="document.getElementById('csvFileInput').click()" 
                                class="bg-gradient-to-r from-blue-500 to-purple-500 text-white px-8 py-3 rounded-xl hover:from-blue-600 hover:to-purple-600 transition-all duration-300 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <i class="fas fa-folder-open mr-2"></i>
                            Browse Files
                        </button>
                    </div>
                    
                    <!-- File Preview -->
                    <div id="filePreview" class="hidden mt-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold text-gray-800 flex items-center">
                                <i class="fas fa-file-csv text-green-500 mr-2"></i>
                                File Preview
                            </h3>
                            <div class="flex items-center space-x-3">
                                <button type="button" onclick="addCSVQuestionsToForm()" 
                                        class="bg-gradient-to-r from-green-500 to-emerald-500 text-white px-4 py-2 rounded-xl hover:from-green-600 hover:to-emerald-600 transition-all duration-300 font-semibold text-sm shadow-md hover:shadow-lg transform hover:-translate-y-0.5 flex items-center">
                                    <i class="fas fa-plus mr-2"></i>
                                    Add to Form
                                </button>
                                <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-700">
                                    <i class="fas fa-times mr-1"></i> Remove
                                </button>
                            </div>
                        </div>
                        
                        <div id="previewContent" class="csv-preview">
                            <!-- Preview content will be inserted here -->
                        </div>
                        
                        <div class="mt-4 flex items-center text-sm text-gray-600">
                            <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                            <span id="questionCountFromCSV">0 questions</span> detected. Click "Add to Form" to add them as editable questions.
                        </div>
                    </div>
                </div>
                
                <!-- Questions Section -->
                <div class="glass-card rounded-2xl p-6 md:p-8 transform transition-all duration-300 hover:shadow-xl">
                    <div class="flex flex-col md:flex-row md:items-center justify-between mb-8">
                        <div class="flex items-center mb-4 md:mb-0">
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-green-500 to-teal-500 flex items-center justify-center text-white text-xl mr-4 shadow-md">
                                <i class="fas fa-question-circle"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800 mb-1 highlight">Questions & Answers</h2>
                                <p class="text-gray-600">Add questions with multiple choice options or use CSV upload above</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-4">
                            <div class="bg-gradient-to-r from-blue-50 to-purple-50 px-4 py-2 rounded-xl">
                                <div class="text-sm text-gray-600">Total Questions</div>
                                <div class="text-xl font-bold text-gray-800" id="questionCountDisplay">1</div>
                            </div>
                            <div class="bg-gradient-to-r from-green-50 to-emerald-50 px-4 py-2 rounded-xl">
                                <div class="text-sm text-gray-600">Total Marks</div>
                                <div class="text-xl font-bold text-gray-800" id="totalMarksDisplay">1</div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="questionsContainer" class="space-y-6">
                        <!-- Question 1 -->
                        <div class="question-card glass-effect rounded-2xl p-6 transform transition-all duration-500 card-enter" data-question-index="1">
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center space-x-4">
                                    <div class="question-number pulse">1</div>
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-800">Question #1</h3>
                                        <p class="text-sm text-gray-500">Required field</p>
                                    </div>
                                </div>
                                <button type="button" onclick="removeQuestion(this)" 
                                        class="w-10 h-10 rounded-xl bg-gradient-to-r from-red-500 to-pink-500 text-white hover:from-red-600 hover:to-pink-600 transition-all duration-300 shadow-md hover:shadow-lg transform hover:-translate-y-1">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                            
                            <!-- Question Text -->
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-question mr-2 text-blue-500"></i>
                                    Question Text <span class="text-red-500 ml-1">*</span>
                                </label>
                                <textarea name="questions[]" rows="3" required 
                                          class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus"
                                          placeholder="Enter your question here..."></textarea>
                            </div>
                            
                            <!-- Options -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                <div class="option-input">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                        <i class="fas fa-dot-circle mr-2 text-blue-500"></i>
                                        Option A <span class="text-red-500 ml-1">*</span>
                                    </label>
                                    <input type="text" name="options_a[]" required 
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                           placeholder="Enter option A">
                                </div>
                                <div class="option-input">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                        <i class="fas fa-dot-circle mr-2 text-green-500"></i>
                                        Option B <span class="text-red-500 ml-1">*</span>
                                    </label>
                                    <input type="text" name="options_b[]" required 
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                           placeholder="Enter option B">
                                </div>
                                <div class="option-input">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                        <i class="fas fa-dot-circle mr-2 text-yellow-500"></i>
                                        Option C <span class="text-red-500 ml-1">*</span>
                                    </label>
                                    <input type="text" name="options_c[]" required 
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                                           placeholder="Enter option C">
                                </div>
                                <div class="option-input">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                        <i class="fas fa-dot-circle mr-2 text-purple-500"></i>
                                        Option D <span class="text-red-500 ml-1">*</span>
                                    </label>
                                    <input type="text" name="options_d[]" required 
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                           placeholder="Enter option D">
                                </div>
                            </div>
                            
                            <!-- Additional Settings -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                        <i class="fas fa-check-circle mr-2 text-green-500"></i>
                                        Correct Answer <span class="text-red-500 ml-1">*</span>
                                    </label>
                                    <select name="correct_answers[]" required 
                                            onchange="highlightCorrectOption(this)"
                                            class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-300">
                                        <option value="">Select Answer</option>
                                        <option value="a">Option A</option>
                                        <option value="b">Option B</option>
                                        <option value="c">Option C</option>
                                        <option value="d">Option D</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                        <i class="fas fa-star mr-2 text-yellow-500"></i>
                                        Marks
                                    </label>
                                    <input type="number" name="marks[]" value="1" min="1" 
                                           onchange="updateTotalMarks()"
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent transition-all duration-300">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                        <i class="fas fa-lightbulb mr-2 text-blue-500"></i>
                                        Explanation (Optional)
                                    </label>
                                    <input type="text" name="explanations[]" 
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300"
                                           placeholder="Brief explanation">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Add Question Button -->
                    <div class="mt-8 text-center">
                        <button type="button" onclick="addQuestion()" 
                                class="floating-btn bg-gradient-to-r from-blue-600 to-purple-600 text-white px-8 py-4 rounded-xl hover:from-blue-700 hover:to-purple-700 transition-all duration-300 font-semibold shadow-lg hover:shadow-xl group">
                            <i class="fas fa-plus-circle text-xl mr-3 group-hover:rotate-90 transition-transform duration-300"></i>
                            Add Another Question
                            <i class="fas fa-arrow-down ml-3 transform group-hover:translate-y-2 transition-transform duration-300"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="glass-card rounded-2xl p-6 md:p-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between">
                        <div class="mb-4 md:mb-0">
                            <div class="text-sm text-gray-600 mb-2">Ready to create your test?</div>
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 rounded-full bg-green-500 animate-pulse"></div>
                                <div class="font-medium text-gray-800">All required fields are completed</div>
                            </div>
                        </div>
                        
                        <div class="flex space-x-4">
                            <button type="reset" 
                                    class="px-8 py-3 border-2 border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 hover:border-gray-400 transition-all duration-300 font-semibold transform hover:-translate-y-0.5">
                                <i class="fas fa-redo mr-2"></i>
                                Reset Form
                            </button>
                            <button type="submit" 
                                    class="px-8 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl hover:from-green-700 hover:to-emerald-700 transition-all duration-300 font-semibold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 group">
                                <i class="fas fa-save mr-2 group-hover:rotate-12 transition-transform duration-300"></i>
                                Create Test
                                <i class="fas fa-rocket ml-2 transform group-hover:translate-x-2 transition-transform duration-300"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- CSV Template Modal -->
    <div id="templateModal" class="modal">
        <div class="modal-content template-modal-content">
            <div class="bg-gradient-to-r from-blue-50 to-purple-50 p-6 rounded-t-2xl">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-14 h-14 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-file-csv text-white text-2xl"></i>
                        </div>
                    </div>
                    <div class="ml-6">
                        <h3 class="text-2xl font-bold text-gray-800">CSV Template Format</h3>
                        <p class="text-gray-600 mt-1">Download the template and fill in your questions</p>
                    </div>
                </div>
            </div>
            
            <div class="p-6">
                <div class="mb-6">
                    <h4 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                        CSV Format Requirements
                    </h4>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <ul class="space-y-2 text-gray-700">
                            <li class="flex items-start">
                                <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                                <span>File must be in CSV format (Comma Separated Values)</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                                <span>Include all columns in the exact order shown below</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                                <span>Correct answer should be one of: a, b, c, d (lowercase)</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                                <span>Marks should be a whole number (e.g., 1, 2, 5)</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                                <span>Use double quotes (") to wrap text containing commas</span>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <div class="mb-6">
                    <h4 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-table text-green-500 mr-2"></i>
                        CSV Structure
                    </h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left font-semibold text-gray-700 border-b">Column</th>
                                    <th class="py-3 px-4 text-left font-semibold text-gray-700 border-b">Description</th>
                                    <th class="py-3 px-4 text-left font-semibold text-gray-700 border-b">Required</th>
                                    <th class="py-3 px-4 text-left font-semibold text-gray-700 border-b">Example</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="py-3 px-4 border-b">question_text</td>
                                    <td class="py-3 px-4 border-b">The question text</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs">Required</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">"What is 2+2?"</td>
                                </tr>
                                <tr>
                                    <td class="py-3 px-4 border-b">option_a</td>
                                    <td class="py-3 px-4 border-b">Option A text</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs">Required</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">"3"</td>
                                </tr>
                                <tr>
                                    <td class="py-3 px-4 border-b">option_b</td>
                                    <td class="py-3 px-4 border-b">Option B text</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs">Required</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">"4"</td>
                                </tr>
                                <tr>
                                    <td class="py-3 px-4 border-b">option_c</td>
                                    <td class="py-3 px-4 border-b">Option C text</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs">Required</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">"5"</td>
                                </tr>
                                <tr>
                                    <td class="py-3 px-4 border-b">option_d</td>
                                    <td class="py-3 px-4 border-b">Option D text</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs">Required</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">"6"</td>
                                </tr>
                                <tr>
                                    <td class="py-3 px-4 border-b">correct_answer</td>
                                    <td class="py-3 px-4 border-b">Correct option (a, b, c, or d)</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs">Required</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">b</td>
                                </tr>
                                <tr>
                                    <td class="py-3 px-4 border-b">marks</td>
                                    <td class="py-3 px-4 border-b">Marks for this question</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">Optional</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">1</td>
                                </tr>
                                <tr>
                                    <td class="py-3 px-4 border-b">explanation</td>
                                    <td class="py-3 px-4 border-b">Explanation for answer</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">Optional</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">"2+2 equals 4"</td>
                                </tr>
                                <tr>
                                    <td class="py-3 px-4 border-b">question_order</td>
                                    <td class="py-3 px-4 border-b">Display order (1, 2, 3...)</td>
                                    <td class="py-3 px-4 border-b text-center">
                                        <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">Optional</span>
                                    </td>
                                    <td class="py-3 px-4 border-b">1</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="mb-6">
                    <h4 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-file-excel text-green-500 mr-2"></i>
                        Sample CSV Data (with commas in text)
                    </h4>
                    <div class="bg-gray-900 text-gray-100 p-4 rounded-lg font-mono text-sm overflow-x-auto">
                        question_text,option_a,option_b,option_c,option_d,correct_answer,marks,explanation,question_order<br>
                        "What is 2+2, and why?","3, but it's wrong","4, the correct one","5, too high","6, too low","b",1,"2+2 equals 4, always",1<br>
                        "What is the capital of France, Europe?","London, UK","Paris, France","Berlin, Germany","Madrid, Spain","b",1,"Paris is the capital, and it's beautiful",2<br>
                        "Which planet, in our solar system, is known as the Red Planet?","Earth, our home","Mars, the red one","Venus, the hot one","Jupiter, the big one","b",2,"Mars appears red, due to iron oxide",3
                    </div>
                </div>
                
                <div class="flex justify-between items-center pt-4 border-t">
                    <button onclick="downloadCSVTemplate()" 
                            class="bg-gradient-to-r from-blue-500 to-purple-500 text-white px-6 py-3 rounded-xl hover:from-blue-600 hover:to-purple-600 transition-all duration-300 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5 flex items-center">
                        <i class="fas fa-download mr-2"></i>
                        Download Template CSV
                    </button>
                    <button onclick="closeModal('templateModal')" 
                            class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 hover:border-gray-400 transition-all duration-300 font-semibold">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-green-50 to-emerald-50 p-8 rounded-t-2xl">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-16 h-16 bg-gradient-to-r from-green-500 to-emerald-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-check text-white text-2xl success-check"></i>
                        </div>
                    </div>
                    <div class="ml-6">
                        <h3 class="text-2xl font-bold text-green-800">Test Created Successfully!</h3>
                        <p id="successMessage" class="text-green-600 mt-2 font-medium"></p>
                    </div>
                </div>
            </div>
            
            <div class="p-8">
                <div class="bg-gradient-to-r from-green-100 to-emerald-50 border-l-4 border-green-500 p-4 rounded-r-lg mb-6">
                    <div class="flex">
                        <i class="fas fa-info-circle text-green-500 mr-3 mt-1"></i>
                        <div>
                            <h4 class="font-bold text-green-700 mb-1">What's next?</h4>
                            <p class="text-green-600 text-sm">
                                Your test is now available. You can manage it from the dashboard or share it with students.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-4">
                    <a href="admin_dashboard.php" 
                       class="px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl hover:from-blue-700 hover:to-purple-700 transition-all duration-300 font-semibold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                        <i class="fas fa-th-large mr-2"></i>
                        Go to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let questionCount = 1;
        let totalMarks = 1;
        let csvData = [];
        
        // Update progress bar based on form completion
        function updateProgress() {
            const form = document.getElementById('testForm');
            const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
            const filledInputs = Array.from(inputs).filter(input => input.value.trim() !== '').length;
            const progress = (filledInputs / inputs.length) * 100;
            
            const progressBar = document.getElementById('progressBar');
            const progressPercentage = document.getElementById('progressPercentage');
            
            progressBar.style.width = `${progress}%`;
            progressPercentage.textContent = `${Math.round(progress)}%`;
        }
        
        // Attach event listeners to update progress
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('testForm');
            const inputs = form.querySelectorAll('input, textarea, select');
            
            inputs.forEach(input => {
                input.addEventListener('input', updateProgress);
                input.addEventListener('change', updateProgress);
            });
            
            updateProgress();
            updateTotalMarks();
            
            // File drag and drop
            const fileDropZone = document.getElementById('fileDropZone');
            const fileInput = document.getElementById('csvFileInput');
            
            fileDropZone.addEventListener('click', () => fileInput.click());
            
            fileDropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                fileDropZone.classList.add('dragover');
            });
            
            fileDropZone.addEventListener('dragleave', () => {
                fileDropZone.classList.remove('dragover');
            });
            
            fileDropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                fileDropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    handleFileSelect({ target: fileInput });
                }
            });
        });
        
        // Advanced CSV parser that handles quoted fields with commas
        function parseCSVLine(line) {
            const result = [];
            let current = '';
            let inQuotes = false;
            
            for (let i = 0; i < line.length; i++) {
                const char = line[i];
                
                if (char === '"') {
                    // Toggle quote state
                    inQuotes = !inQuotes;
                } else if (char === ',' && !inQuotes) {
                    // End of field
                    result.push(current.trim());
                    current = '';
                } else {
                    // Add character to current field
                    current += char;
                }
            }
            
            // Add the last field
            result.push(current.trim());
            
            return result;
        }
        
        function parseCSVContent(content) {
            const lines = content.split('\n').filter(line => line.trim() !== '');
            if (lines.length === 0) return [];
            
            const headers = parseCSVLine(lines[0]);
            const data = [];
            
            for (let i = 1; i < lines.length; i++) {
                const values = parseCSVLine(lines[i]);
                if (values.length >= 6) { // At least question + options a-d + correct answer
                    const row = {};
                    headers.forEach((header, index) => {
                        if (index < values.length) {
                            // Clean up the value (remove quotes if present)
                            let value = values[index];
                            if (value.startsWith('"') && value.endsWith('"')) {
                                value = value.substring(1, value.length - 1);
                            }
                            // Replace double quotes with single quotes
                            value = value.replace(/""/g, '"');
                            row[header.trim()] = value;
                        }
                    });
                    data.push(row);
                }
            }
            
            return data;
        }
        
        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
                alert('Please upload a CSV file.');
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                alert('File size should be less than 5MB.');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                const content = e.target.result;
                
                try {
                    // Parse CSV with proper quote handling
                    const parsedData = parseCSVContent(content);
                    
                    if (parsedData.length === 0) {
                        alert('No valid data found in CSV file.');
                        return;
                    }
                    
                    // Map to our expected format
                    csvData = parsedData.map(row => ({
                        question: row.question_text || '',
                        option_a: row.option_a || '',
                        option_b: row.option_b || '',
                        option_c: row.option_c || '',
                        option_d: row.option_d || '',
                        correct_answer: row.correct_answer ? row.correct_answer.toLowerCase().trim() : '',
                        marks: parseInt(row.marks) || 1,
                        explanation: row.explanation || '',
                        question_order: parseInt(row.question_order) || 0
                    })).filter(q => q.question && q.option_a && q.option_b && q.option_c && q.option_d && q.correct_answer);
                    
                    if (csvData.length === 0) {
                        alert('No valid questions found. Please check that all required fields are filled.');
                        return;
                    }
                    
                    // Update UI
                    const fileDropZone = document.getElementById('fileDropZone');
                    const filePreview = document.getElementById('filePreview');
                    const previewContent = document.getElementById('previewContent');
                    const questionCountFromCSV = document.getElementById('questionCountFromCSV');
                    
                    fileDropZone.classList.add('active');
                    fileDropZone.innerHTML = `
                        <div class="mb-4">
                            <i class="fas fa-file-csv text-5xl text-green-500 mb-4"></i>
                            <h3 class="text-xl font-bold text-gray-800 mb-2">${file.name}</h3>
                            <p class="text-gray-600 mb-4">${(file.size / 1024).toFixed(2)} KB</p>
                            <div class="text-sm text-green-600 font-medium">
                                <i class="fas fa-check-circle mr-2"></i>
                                CSV file uploaded successfully
                            </div>
                        </div>
                    `;
                    
                    filePreview.classList.remove('hidden');
                    questionCountFromCSV.textContent = `${csvData.length} questions`;
                    
                    // Create preview table
                    let tableHTML = `
                        <table class="csv-table">
                            <thead>
                                <tr>
                                    <th>Question</th>
                                    <th>A</th>
                                    <th>B</th>
                                    <th>C</th>
                                    <th>D</th>
                                    <th>Correct</th>
                                    <th>Marks</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    
                    // Show all rows in preview
                    for (let i = 0; i < Math.min(5, csvData.length); i++) {
                        const q = csvData[i];
                        const questionPreview = q.question.length > 50 ? q.question.substring(0, 50) + '...' : q.question;
                        tableHTML += `
                            <tr>
                                <td title="${q.question.replace(/"/g, '&quot;')}">${questionPreview}</td>
                                <td title="${q.option_a.replace(/"/g, '&quot;')}">${q.option_a.substring(0, 20)}${q.option_a.length > 20 ? '...' : ''}</td>
                                <td title="${q.option_b.replace(/"/g, '&quot;')}">${q.option_b.substring(0, 20)}${q.option_b.length > 20 ? '...' : ''}</td>
                                <td title="${q.option_c.replace(/"/g, '&quot;')}">${q.option_c.substring(0, 20)}${q.option_c.length > 20 ? '...' : ''}</td>
                                <td title="${q.option_d.replace(/"/g, '&quot;')}">${q.option_d.substring(0, 20)}${q.option_d.length > 20 ? '...' : ''}</td>
                                <td class="text-center"><span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs font-bold">${q.correct_answer.toUpperCase()}</span></td>
                                <td class="text-center">${q.marks}</td>
                            </tr>
                        `;
                    }
                    
                    tableHTML += '</tbody></table>';
                    previewContent.innerHTML = tableHTML;
                    
                    if (csvData.length > 5) {
                        previewContent.innerHTML += `<div class="text-center py-2 text-gray-500 text-sm">... and ${csvData.length - 5} more questions</div>`;
                    }
                    
                } catch (error) {
                    console.error('Error parsing CSV:', error);
                    alert('Error parsing CSV file. Please check the format.');
                }
            };
            
            reader.readAsText(file);
        }
        
        function addCSVQuestionsToForm() {
            if (csvData.length === 0) {
                alert('No CSV data to add. Please upload a CSV file first.');
                return;
            }
            
            const container = document.getElementById('questionsContainer');
            let addedCount = 0;
            
            // Add CSV questions to form
            csvData.forEach((question, index) => {
                if (question.question && question.option_a && question.option_b && 
                    question.option_c && question.option_d && question.correct_answer) {
                    
                    questionCount++;
                    const newQuestion = document.createElement('div');
                    newQuestion.className = 'question-card glass-effect rounded-2xl p-6 transform transition-all duration-500 card-enter csv-imported';
                    newQuestion.setAttribute('data-question-index', questionCount);
                    
                    // Escape any quotes for HTML attributes
                    const escapeHtml = (str) => {
                        return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    };
                    
                    newQuestion.innerHTML = `
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center space-x-4">
                                <div class="question-number">${questionCount}</div>
                                <div>
                                    <h3 class="text-lg font-bold text-gray-800">Question #${questionCount} <span class="text-xs text-green-600 bg-green-100 px-2 py-1 rounded-full">CSV Import</span></h3>
                                    <p class="text-sm text-gray-500">Imported from CSV</p>
                                </div>
                            </div>
                            <button type="button" onclick="removeQuestion(this)" 
                                    class="w-10 h-10 rounded-xl bg-gradient-to-r from-red-500 to-pink-500 text-white hover:from-red-600 hover:to-pink-600 transition-all duration-300 shadow-md hover:shadow-lg transform hover:-translate-y-1">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        
                        <!-- Question Text -->
                        <div class="mb-6">
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-question mr-2 text-blue-500"></i>
                                Question Text <span class="text-red-500 ml-1">*</span>
                            </label>
                            <textarea name="questions[]" rows="3" required 
                                      class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus"
                                      placeholder="Enter your question here..." oninput="updateProgress()">${question.question}</textarea>
                        </div>
                        
                        <!-- Options -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div class="option-input">
                                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-dot-circle mr-2 text-blue-500"></i>
                                    Option A <span class="text-red-500 ml-1">*</span>
                                </label>
                                <input type="text" name="options_a[]" required 
                                       class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="Enter option A" oninput="updateProgress()" value="${escapeHtml(question.option_a)}">
                            </div>
                            <div class="option-input">
                                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-dot-circle mr-2 text-green-500"></i>
                                    Option B <span class="text-red-500 ml-1">*</span>
                                </label>
                                <input type="text" name="options_b[]" required 
                                       class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                       placeholder="Enter option B" oninput="updateProgress()" value="${escapeHtml(question.option_b)}">
                            </div>
                            <div class="option-input">
                                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-dot-circle mr-2 text-yellow-500"></i>
                                    Option C <span class="text-red-500 ml-1">*</span>
                                </label>
                                <input type="text" name="options_c[]" required 
                                       class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                                       placeholder="Enter option C" oninput="updateProgress()" value="${escapeHtml(question.option_c)}">
                            </div>
                            <div class="option-input">
                                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-dot-circle mr-2 text-purple-500"></i>
                                    Option D <span class="text-red-500 ml-1">*</span>
                                </label>
                                <input type="text" name="options_d[]" required 
                                       class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                       placeholder="Enter option D" oninput="updateProgress()" value="${escapeHtml(question.option_d)}">
                            </div>
                        </div>
                        
                        <!-- Additional Settings -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-check-circle mr-2 text-green-500"></i>
                                    Correct Answer <span class="text-red-500 ml-1">*</span>
                                </label>
                                <select name="correct_answers[]" required 
                                        onchange="highlightCorrectOption(this); updateProgress()"
                                        class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-300">
                                    <option value="">Select Answer</option>
                                    <option value="a" ${question.correct_answer === 'a' ? 'selected' : ''}>Option A</option>
                                    <option value="b" ${question.correct_answer === 'b' ? 'selected' : ''}>Option B</option>
                                    <option value="c" ${question.correct_answer === 'c' ? 'selected' : ''}>Option C</option>
                                    <option value="d" ${question.correct_answer === 'd' ? 'selected' : ''}>Option D</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-star mr-2 text-yellow-500"></i>
                                    Marks
                                </label>
                                <input type="number" name="marks[]" value="${question.marks || 1}" min="1" 
                                       onchange="updateTotalMarks()"
                                       class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent transition-all duration-300">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-lightbulb mr-2 text-blue-500"></i>
                                    Explanation (Optional)
                                </label>
                                <input type="text" name="explanations[]" 
                                       class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300"
                                       placeholder="Brief explanation" value="${escapeHtml(question.explanation || '')}">
                            </div>
                        </div>
                    `;
                    
                    container.appendChild(newQuestion);
                    addedCount++;
                    
                    // Auto-highlight correct option
                    setTimeout(() => {
                        const select = newQuestion.querySelector('select[name="correct_answers[]"]');
                        if (select) {
                            highlightCorrectOption(select);
                        }
                    }, 100);
                }
            });
            
            // Update counts and marks
            updateTotalQuestionCount();
            updateTotalMarks();
            updateProgress();
            
            // Show success message
            if (addedCount > 0) {
                // Clear CSV data after adding to form
                csvData = [];
                clearFile();
                
                // Scroll to last added question
                const questions = document.querySelectorAll('.question-card');
                if (questions.length > 0) {
                    questions[questions.length - 1].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                
                // Show success notification
                showNotification(`${addedCount} questions added from CSV!`, 'success');
            } else {
                alert('No valid questions found in the CSV file. Please check the format.');
            }
        }
        
        function clearFile() {
            const fileInput = document.getElementById('csvFileInput');
            const fileDropZone = document.getElementById('fileDropZone');
            const filePreview = document.getElementById('filePreview');
            
            fileInput.value = '';
            csvData = [];
            
            fileDropZone.classList.remove('active');
            fileDropZone.innerHTML = `
                <div class="mb-4">
                    <i class="fas fa-cloud-upload-alt text-5xl text-blue-500 mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">Drag & Drop CSV File</h3>
                    <p class="text-gray-600 mb-4">or click to browse your files</p>
                    <div class="text-sm text-gray-500 mb-6">
                        <i class="fas fa-info-circle mr-2"></i>
                        Supported format: CSV (Max 5MB)
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('csvFileInput').click()" 
                        class="bg-gradient-to-r from-blue-500 to-purple-500 text-white px-8 py-3 rounded-xl hover:from-blue-600 hover:to-purple-600 transition-all duration-300 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                    <i class="fas fa-folder-open mr-2"></i>
                    Browse Files
                </button>
            `;
            
            filePreview.classList.add('hidden');
        }
        
        function showTemplateModal() {
            const modal = document.getElementById('templateModal');
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function downloadCSVTemplate() {
            const csvContent = `question_text,option_a,option_b,option_c,option_d,correct_answer,marks,explanation,question_order
"What is 2+2, and why?","3, but it's wrong","4, the correct one","5, too high","6, too low","b",1,"2+2 equals 4, always",1
"What is the capital of France, Europe?","London, UK","Paris, France","Berlin, Germany","Madrid, Spain","b",1,"Paris is the capital, and it's beautiful",2
"Which planet, in our solar system, is known as the Red Planet?","Earth, our home","Mars, the red one","Venus, the hot one","Jupiter, the big one","b",2,"Mars appears red, due to iron oxide",3
"What is 5x5?","20","25","30","35","b",1,"5 multiplied by 5 equals 25",4
"What is the chemical symbol for water?","H2O","CO2","O2","NaCl","a",1,"Water is H2O",5`;
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'test_questions_template.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        function addQuestion() {
            questionCount++;
            const container = document.getElementById('questionsContainer');
            const newQuestion = document.createElement('div');
            newQuestion.className = 'question-card glass-effect rounded-2xl p-6 transform transition-all duration-500 card-enter';
            newQuestion.setAttribute('data-question-index', questionCount);
            newQuestion.innerHTML = `
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-4">
                        <div class="question-number">${questionCount}</div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">Question #${questionCount}</h3>
                            <p class="text-sm text-gray-500">Required field</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeQuestion(this)" 
                            class="w-10 h-10 rounded-xl bg-gradient-to-r from-red-500 to-pink-500 text-white hover:from-red-600 hover:to-pink-600 transition-all duration-300 shadow-md hover:shadow-lg transform hover:-translate-y-1">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                
                <!-- Question Text -->
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                        <i class="fas fa-question mr-2 text-blue-500"></i>
                        Question Text <span class="text-red-500 ml-1">*</span>
                    </label>
                    <textarea name="questions[]" rows="3" required 
                              class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus"
                              placeholder="Enter your question here..." oninput="updateProgress()"></textarea>
                </div>
                
                <!-- Options -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="option-input">
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-dot-circle mr-2 text-blue-500"></i>
                            Option A <span class="text-red-500 ml-1">*</span>
                        </label>
                        <input type="text" name="options_a[]" required 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Enter option A" oninput="updateProgress()">
                    </div>
                    <div class="option-input">
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-dot-circle mr-2 text-green-500"></i>
                            Option B <span class="text-red-500 ml-1">*</span>
                        </label>
                        <input type="text" name="options_b[]" required 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent"
                               placeholder="Enter option B" oninput="updateProgress()">
                    </div>
                    <div class="option-input">
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-dot-circle mr-2 text-yellow-500"></i>
                            Option C <span class="text-red-500 ml-1">*</span>
                        </label>
                        <input type="text" name="options_c[]" required 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                               placeholder="Enter option C" oninput="updateProgress()">
                    </div>
                    <div class="option-input">
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-dot-circle mr-2 text-purple-500"></i>
                            Option D <span class="text-red-500 ml-1">*</span>
                        </label>
                        <input type="text" name="options_d[]" required 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                               placeholder="Enter option D" oninput="updateProgress()">
                    </div>
                </div>
                
                <!-- Additional Settings -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-check-circle mr-2 text-green-500"></i>
                            Correct Answer <span class="text-red-500 ml-1">*</span>
                        </label>
                        <select name="correct_answers[]" required 
                                onchange="highlightCorrectOption(this); updateProgress()"
                                class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-300">
                            <option value="">Select Answer</option>
                            <option value="a">Option A</option>
                            <option value="b">Option B</option>
                            <option value="c">Option C</option>
                            <option value="d">Option D</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-star mr-2 text-yellow-500"></i>
                            Marks
                        </label>
                        <input type="number" name="marks[]" value="1" min="1" 
                               onchange="updateTotalMarks()"
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent transition-all duration-300">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-lightbulb mr-2 text-blue-500"></i>
                            Explanation (Optional)
                        </label>
                        <input type="text" name="explanations[]" 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300"
                               placeholder="Brief explanation">
                    </div>
                </div>
            `;
            
            container.appendChild(newQuestion);
            
            // Animate the new question entry
            setTimeout(() => {
                newQuestion.style.animationDelay = '0s';
            }, 100);
            
            // Update displays
            updateTotalQuestionCount();
            updateProgress();
            
            // Scroll to new question
            newQuestion.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        function removeQuestion(button) {
            if (questionCount > 1) {
                const questionCard = button.closest('.question-card');
                const marksInput = questionCard.querySelector('input[name="marks[]"]');
                
                // Remove marks from total
                if (marksInput) {
                    totalMarks -= parseInt(marksInput.value) || 0;
                }
                
                // Animate removal
                questionCard.style.transform = 'translateX(-100%)';
                questionCard.style.opacity = '0';
                
                setTimeout(() => {
                    questionCard.remove();
                    questionCount--;
                    updateTotalQuestionCount();
                    updateTotalMarks();
                    updateProgress();
                    
                    // Update remaining question numbers
                    const questions = document.querySelectorAll('.question-card');
                    questions.forEach((card, index) => {
                        const numberDiv = card.querySelector('.question-number');
                        const title = card.querySelector('h3');
                        const questionNum = index + 1;
                        numberDiv.textContent = questionNum;
                        title.textContent = `Question #${questionNum}`;
                        card.setAttribute('data-question-index', questionNum);
                    });
                }, 300);
            } else {
                // Shake animation for minimum question warning
                const questionCard = button.closest('.question-card');
                questionCard.classList.add('shake');
                setTimeout(() => {
                    questionCard.classList.remove('shake');
                }, 500);
            }
        }
        
        function updateTotalQuestionCount() {
            const totalQuestions = document.querySelectorAll('.question-card').length;
            document.getElementById('questionCountDisplay').textContent = totalQuestions;
        }
        
        function updateTotalMarks() {
            totalMarks = 0;
            const marksInputs = document.querySelectorAll('input[name="marks[]"]');
            marksInputs.forEach(input => {
                totalMarks += parseInt(input.value) || 0;
            });
            document.getElementById('totalMarksDisplay').textContent = totalMarks;
        }
        
        function highlightCorrectOption(select) {
            const questionCard = select.closest('.question-card');
            const optionIndex = select.value; // a, b, c, or d
            
            // Remove previous highlight
            const optionInputs = questionCard.querySelectorAll('.option-input');
            optionInputs.forEach(input => {
                input.classList.remove('option-correct');
            });
            
            // Add highlight to correct option
            if (optionIndex) {
                const optionLetter = optionIndex.toUpperCase();
                const optionIndexMap = { 'A': 0, 'B': 1, 'C': 2, 'D': 3 };
                const optionInput = optionInputs[optionIndexMap[optionLetter]];
                if (optionInput) {
                    optionInput.classList.add('option-correct');
                }
            }
        }
        
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-xl shadow-lg transform transition-all duration-300 animate-[slideInRight_0.5s_ease-out] ${type === 'success' ? 'bg-gradient-to-r from-green-400 to-emerald-500 text-white' : 'bg-gradient-to-r from-blue-400 to-purple-500 text-white'}`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} text-xl mr-3"></i>
                    <span class="font-medium">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.animation = 'slideInRight 0.5s ease-out reverse';
                    setTimeout(() => notification.remove(), 500);
                }
            }, 5000);
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
        
        // Form submission handling
        document.getElementById('testForm').addEventListener('submit', function(e) {
            // Check if we have at least one question
            const hasQuestions = document.querySelectorAll('textarea[name="questions[]"]').length > 0;
            
            if (!hasQuestions) {
                e.preventDefault();
                alert('Please add at least one question.');
                return;
            }
            
            // Validate all questions
            const questionTexts = document.querySelectorAll('textarea[name="questions[]"]');
            let hasCompleteQuestion = false;
            
            questionTexts.forEach(textarea => {
                if (textarea.value.trim() !== '') {
                    // Check if all required fields for this question are filled
                    const questionCard = textarea.closest('.question-card');
                    const optionA = questionCard.querySelector('input[name="options_a[]"]');
                    const optionB = questionCard.querySelector('input[name="options_b[]"]');
                    const optionC = questionCard.querySelector('input[name="options_c[]"]');
                    const optionD = questionCard.querySelector('input[name="options_d[]"]');
                    const correctAnswer = questionCard.querySelector('select[name="correct_answers[]"]');
                    
                    if (optionA.value.trim() && optionB.value.trim() && optionC.value.trim() && 
                        optionD.value.trim() && correctAnswer.value) {
                        hasCompleteQuestion = true;
                    }
                }
            });
            
            if (!hasCompleteQuestion) {
                e.preventDefault();
                alert('Please complete at least one question with all required fields.');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Creating Test...';
            submitBtn.disabled = true;
            
            // If there's a success message from PHP, show modal
            <?php if ($success): ?>
                e.preventDefault();
                setTimeout(() => {
                    const modal = document.getElementById('successModal');
                    const message = document.getElementById('successMessage');
                    message.textContent = '<?= addslashes($success) ?>';
                    modal.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                }, 1000);
            <?php endif; ?>
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
    </script>
</body>
</html>