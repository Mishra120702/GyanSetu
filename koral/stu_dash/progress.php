<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: logout.php');
    exit();
}

$batch_id = $_GET['batch_id'] ?? '';

if (!$batch_id) {
    die("Batch ID is required");
}

// Fetch batch details
$batch_stmt = $db->prepare("SELECT * FROM batches WHERE batch_id = ?");
$batch_stmt->execute([$batch_id]);
$batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_main_topics'])) {
        // Add multiple main topics
        $chapters = $_POST['chapter'] ?? [];
        $topic_names = $_POST['topic_name'] ?? [];
        
        $added_count = 0;
        for ($i = 0; $i < count($chapters); $i++) {
            if (!empty($chapters[$i]) && !empty($topic_names[$i])) {
                $stmt = $db->prepare("INSERT INTO main_topics (batch_name, chapter, topic_name) VALUES (?, ?, ?)");
                $stmt->execute([$batch_id, $chapters[$i], $topic_names[$i]]);
                $added_count++;
            }
        }
        
        $_SESSION['success'] = "$added_count main topic(s) added successfully!";
    } 
    elseif (isset($_POST['add_sub_topics'])) {
        // Add multiple sub topics
        $main_topic_id = $_POST['main_topic_id'];
        $sub_topic_names = $_POST['sub_topic_name'] ?? [];
        $theory_completed = $_POST['theory_completed'] ?? [];
        $practical_completed = $_POST['practical_completed'] ?? [];
        
        $added_count = 0;
        for ($i = 0; $i < count($sub_topic_names); $i++) {
            if (!empty($sub_topic_names[$i])) {
                $theory = isset($theory_completed[$i]) ? 1 : 0;
                $practical = isset($practical_completed[$i]) ? 1 : 0;
                
                $stmt = $db->prepare("INSERT INTO sub_topics (main_topic_id, sub_topic_name, theory_completed, practical_completed, completed_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$main_topic_id, $sub_topic_names[$i], $theory, $practical, $_SESSION['user_id']]);
                $added_count++;
            }
        }
        
        $_SESSION['success'] = "$added_count sub topic(s) added successfully!";
    }
    elseif (isset($_POST['update_progress'])) {
        // Update sub topic progress
        $sub_topic_id = $_POST['sub_topic_id'];
        $theory_completed = isset($_POST['theory_completed']) ? 1 : 0;
        $practical_completed = isset($_POST['practical_completed']) ? 1 : 0;
        
        $stmt = $db->prepare("UPDATE sub_topics SET theory_completed = ?, practical_completed = ?, completed_by = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([$theory_completed, $practical_completed, $_SESSION['user_id'], $sub_topic_id]);
        
        $_SESSION['success'] = "Progress updated successfully!";
    }
    elseif (isset($_POST['edit_main_topic'])) {
        // Edit main topic
        $main_topic_id = $_POST['main_topic_id'];
        $chapter = $_POST['chapter'];
        $topic_name = $_POST['topic_name'];
        
        $stmt = $db->prepare("UPDATE main_topics SET chapter = ?, topic_name = ? WHERE id = ? AND batch_name = ?");
        $stmt->execute([$chapter, $topic_name, $main_topic_id, $batch_id]);
        
        $_SESSION['success'] = "Chapter updated successfully!";
    }
    elseif (isset($_POST['delete_main_topic'])) {
        // Delete main topic and its subtopics
        $main_topic_id = $_POST['main_topic_id'];
        
        // First delete all subtopics
        $stmt = $db->prepare("DELETE FROM sub_topics WHERE main_topic_id = ?");
        $stmt->execute([$main_topic_id]);
        
        // Then delete the main topic
        $stmt = $db->prepare("DELETE FROM main_topics WHERE id = ? AND batch_name = ?");
        $stmt->execute([$main_topic_id, $batch_id]);
        
        $_SESSION['success'] = "Chapter and all its subtopics deleted successfully!";
    }
    elseif (isset($_POST['edit_sub_topic'])) {
        // Edit sub topic
        $sub_topic_id = $_POST['sub_topic_id'];
        $sub_topic_name = $_POST['sub_topic_name'];
        $theory_completed = isset($_POST['theory_completed']) ? 1 : 0;
        $practical_completed = isset($_POST['practical_completed']) ? 1 : 0;
        
        $stmt = $db->prepare("UPDATE sub_topics SET sub_topic_name = ?, theory_completed = ?, practical_completed = ?, completed_by = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([$sub_topic_name, $theory_completed, $practical_completed, $_SESSION['user_id'], $sub_topic_id]);
        
        $_SESSION['success'] = "Sub topic updated successfully!";
    }
    elseif (isset($_POST['delete_sub_topic'])) {
        // Delete sub topic
        $sub_topic_id = $_POST['sub_topic_id'];
        
        $stmt = $db->prepare("DELETE FROM sub_topics WHERE id = ?");
        $stmt->execute([$sub_topic_id]);
        
        $_SESSION['success'] = "Sub topic deleted successfully!";
    }
    elseif (isset($_POST['import_excel'])) {
        // Handle Excel/CSV import
        $import_type = $_POST['import_type'];
        $main_topic_id = $_POST['import_main_topic_id'] ?? null;
        
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['excel_file']['tmp_name'];
            $file_name = $_FILES['excel_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            $added_count = 0;
            $error_messages = [];
            
            if ($file_ext === 'csv') {
                // Process CSV file
                if (($handle = fopen($file, "r")) !== FALSE) {
                    $row = 0;
                    
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row++;
                        
                        // Skip header row if present
                        if ($row === 1 && ($data[0] === 'chapter' || $data[0] === 'Chapter' || 
                                           $data[0] === 'topic_name' || $data[0] === 'Topic Name' ||
                                           $data[0] === 'sub_topic_name' || $data[0] === 'Sub Topic Name')) {
                            continue;
                        }
                        
                        if ($import_type === 'main_topics') {
                            // Import main topics
                            if (count($data) >= 2) {
                                $chapter = trim($data[0]);
                                $topic_name = trim($data[1]);
                                
                                if (!empty($chapter) && !empty($topic_name)) {
                                    try {
                                        $stmt = $db->prepare("INSERT INTO main_topics (batch_name, chapter, topic_name) VALUES (?, ?, ?)");
                                        $stmt->execute([$batch_id, $chapter, $topic_name]);
                                        $added_count++;
                                    } catch (PDOException $e) {
                                        $error_messages[] = "Row $row: " . $e->getMessage();
                                    }
                                } else {
                                    $error_messages[] = "Row $row: Missing chapter or topic name";
                                }
                            } else {
                                $error_messages[] = "Row $row: Invalid format - expected chapter and topic name";
                            }
                        } elseif ($import_type === 'sub_topics' && $main_topic_id) {
                            // Import sub topics
                            if (count($data) >= 3) {
                                $sub_topic_name = trim($data[0]);
                                $theory_completed = strtolower(trim($data[1])) === 'yes' || $data[1] === '1' ? 1 : 0;
                                $practical_completed = strtolower(trim($data[2])) === 'yes' || $data[2] === '1' ? 1 : 0;
                                
                                if (!empty($sub_topic_name)) {
                                    try {
                                        $stmt = $db->prepare("INSERT INTO sub_topics (main_topic_id, sub_topic_name, theory_completed, practical_completed, completed_by) VALUES (?, ?, ?, ?, ?)");
                                        $stmt->execute([$main_topic_id, $sub_topic_name, $theory_completed, $practical_completed, $_SESSION['user_id']]);
                                        $added_count++;
                                    } catch (PDOException $e) {
                                        $error_messages[] = "Row $row: " . $e->getMessage();
                                    }
                                } else {
                                    $error_messages[] = "Row $row: Missing sub topic name";
                                }
                            } else {
                                $error_messages[] = "Row $row: Invalid format - expected sub topic name, theory status, and practical status";
                            }
                        }
                    }
                    fclose($handle);
                }
            } else {
                $_SESSION['error'] = "Only CSV files are supported for import.";
            }
            
            if ($added_count > 0) {
                $_SESSION['success'] = "$added_count " . ($import_type === 'main_topics' ? 'main topic(s)' : 'sub topic(s)') . " imported successfully!";
            }
            
            if (!empty($error_messages)) {
                $_SESSION['error'] = ($_SESSION['error'] ?? '') . " Some errors occurred during import: " . implode(', ', array_slice($error_messages, 0, 5));
                if (count($error_messages) > 5) {
                    $_SESSION['error'] .= " and " . (count($error_messages) - 5) . " more errors.";
                }
            }
        } else {
            $_SESSION['error'] = "Please select a valid file to upload.";
        }
    }
    
    header("Location: progress_batch.php?batch_id=" . $batch_id);
    exit();
}

// Fetch main topics for this batch
$main_topics_stmt = $db->prepare("SELECT * FROM main_topics WHERE batch_name = ? ORDER BY chapter");
$main_topics_stmt->execute([$batch_id]);
$main_topics = $main_topics_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate progress statistics
$total_sub_topics = 0;
$completed_theory = 0;
$completed_practical = 0;
$main_topics_with_sub_topics = [];

foreach ($main_topics as $main_topic) {
    $sub_topics_stmt = $db->prepare("SELECT * FROM sub_topics WHERE main_topic_id = ?");
    $sub_topics_stmt->execute([$main_topic['id']]);
    $sub_topics = $sub_topics_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $main_topic['sub_topics'] = $sub_topics;
    $main_topics_with_sub_topics[] = $main_topic;
    
    foreach ($sub_topics as $sub_topic) {
        $total_sub_topics++;
        if ($sub_topic['theory_completed']) $completed_theory++;
        if ($sub_topic['practical_completed']) $completed_practical++;
    }
}

$theory_progress = $total_sub_topics > 0 ? round(($completed_theory / $total_sub_topics) * 100, 2) : 0;
$practical_progress = $total_sub_topics > 0 ? round(($completed_practical / $total_sub_topics) * 100, 2) : 0;
$overall_progress = $total_sub_topics > 0 ? round((($completed_theory + $completed_practical) / ($total_sub_topics * 2)) * 100, 2) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Progress - <?php echo htmlspecialchars($batch['batch_name']); ?></title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome for Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #3b82f6; /* Tailwind blue-500 */
            --color-secondary: #10b981; /* Tailwind emerald-500 */
            --color-info: #06b6d4; /* Tailwind cyan-500 */
            --color-bg: #f3f4f6; /* Tailwind gray-100 */
            --color-card: #ffffff;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--color-bg);
            transition: all 0.3s ease;
        }
        
        /* Custom Progress Bar for a sleek look */
        .custom-progress-bar {
            height: 8px;
            border-radius: 9999px;
            overflow: hidden;
            background-color: #e5e7eb; /* gray-200 */
        }
        .custom-progress-bar-fill {
            height: 100%;
            transition: width 0.7s ease-in-out; /* Animation */
        }

        /* Card styles with subtle shadow and hover effect */
        .fancy-card {
            background-color: var(--color-card);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.06);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .fancy-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        }

        /* Input/Form Group Styles */
        .dynamic-field-group {
            position: relative;
            padding: 1rem;
            border: 2px dashed #d1d5db; /* gray-300 */
            border-radius: 8px;
            margin-bottom: 1rem;
            background-color: #fafafa;
        }
        .remove-field {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            color: #ef4444; /* red-500 */
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .remove-field:hover {
            transform: scale(1.1);
        }

        /* Topic Item Hover Effect */
        .topic-item-row {
            transition: background-color 0.2s ease, transform 0.2s ease;
            cursor: pointer;
        }
        .topic-item-row:hover {
            background-color: #f3f4f6; /* gray-100 */
        }

        /* Modal Animation */
        .modal-fade-in {
            animation: fadeIn 0.2s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.5);
            transition: opacity 0.15s linear;
        }

        /* Scrollable topics container */
        .scrollable-topics {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        /* Custom scrollbar styling */
        .scrollable-topics::-webkit-scrollbar {
            width: 8px;
        }
        
        .scrollable-topics::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .scrollable-topics::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        .scrollable-topics::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Action buttons */
        .action-btn {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            transition: all 0.2s ease;
        }
        .action-btn:hover {
            transform: translateY(-1px);
        }

        /* Utility for mobile responsiveness */
        @media (max-width: 768px) {
            .progress-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Import section styling */
        .import-section {
            border-left: 4px solid #8b5cf6; /* purple-500 */
        }
        
        .file-upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            background-color: #f9fafb;
        }
        
        .file-upload-area:hover {
            border-color: #8b5cf6;
            background-color: #f3f4f6;
        }
        
        .file-upload-area.dragover {
            border-color: #8b5cf6;
            background-color: #e0e7ff;
        }
        
        .download-template {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            background-color: #10b981;
            color: white;
            border-radius: 0.375rem;
            text-decoration: none;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        
        .download-template:hover {
            background-color: #059669;
        }
    </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <header class="flex justify-between items-center mb-6 pb-4 border-b border-gray-200">
            <div class="flex items-center space-x-3">
                <i class="fas fa-chart-line text-3xl text-blue-600"></i>
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Batch Progress Tracker</h1>
                    <h2 class="text-lg text-gray-500 font-medium">
                        <?php echo htmlspecialchars($batch['batch_name']); ?> (<?php echo htmlspecialchars($batch_id); ?>)
                    </h2>
                </div>
            </div>
            <a href="batch_view.php?batch_id=<?php echo $batch_id; ?>" 
               class="flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition duration-200 shadow-md">
                <i class="fas fa-arrow-left mr-2"></i> Back to Batch
            </a>
        </header>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md relative mb-6 transition-opacity duration-500 opacity-100" role="alert">
                <span class="block sm:inline"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" onclick="this.parentElement.style.opacity='0'; setTimeout(() => this.parentElement.remove(), 500);">
                    <svg class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.103l-2.651 2.651a1.2 1.2 0 1 1-1.697-1.697l2.651-2.651-2.651-2.651a1.2 1.2 0 0 1 1.697-1.697l2.651 2.651 2.651-2.651a1.2 1.2 0 0 1 1.697 1.697l-2.651 2.651 2.651 2.651a1.2 1.2 0 0 1 0 1.697z"/></svg>
                </span>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md relative mb-6 transition-opacity duration-500 opacity-100" role="alert">
                <span class="block sm:inline"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" onclick="this.parentElement.style.opacity='0'; setTimeout(() => this.parentElement.remove(), 500);">
                    <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.103l-2.651 2.651a1.2 1.2 0 1 1-1.697-1.697l2.651-2.651-2.651-2.651a1.2 1.2 0 0 1 1.697-1.697l2.651 2.651 2.651-2.651a1.2 1.2 0 0 1 1.697 1.697l-2.651 2.651 2.651 2.651a1.2 1.2 0 0 1 0 1.697z"/></svg>
                </span>
            </div>
        <?php endif; ?>

        <!-- Progress Overview -->
        <div class="mb-8">
            <h4 class="text-2xl font-semibold text-gray-700 mb-4">Progress Overview</h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 progress-grid">
                
                <?php
                $progress_cards = [
                    ['title' => 'Theory Progress', 'value' => $theory_progress, 'color' => 'bg-emerald-500', 'icon' => 'fas fa-brain', 'text' => $completed_theory . '/' . $total_sub_topics . ' topics'],
                    ['title' => 'Practical Progress', 'value' => $practical_progress, 'color' => 'bg-cyan-500', 'icon' => 'fas fa-laptop-code', 'text' => $completed_practical . '/' . $total_sub_topics . ' topics'],
                    ['title' => 'Overall Progress', 'value' => $overall_progress, 'color' => 'bg-blue-600', 'icon' => 'fas fa-medal', 'text' => 'Total completion rate'],
                ];
                
                foreach ($progress_cards as $card):
                ?>
                <div class="fancy-card p-5 text-center">
                    <div class="flex items-center justify-center mb-3 text-<?php echo $card['color']; ?>-600">
                        <i class="<?php echo $card['icon']; ?> text-3xl"></i>
                    </div>
                    <h5 class="text-xl font-bold text-gray-800 mb-4"><?php echo $card['title']; ?></h5>
                    
                    <div class="custom-progress-bar mb-3">
                        <div class="custom-progress-bar-fill <?php echo $card['color']; ?>" style="width: <?php echo $card['value']; ?>%;"></div>
                    </div>
                    
                    <p class="text-2xl font-extrabold text-gray-900 mb-2"><?php echo $card['value']; ?>%</p>
                    <p class="text-sm text-gray-500"><?php echo $card['text']; ?></p>
                </div>
                <?php endforeach; ?>
                
            </div>
        </div>

        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Column: Forms -->
            <div class="w-full lg:w-1/3 space-y-6">
                
                <!-- Import from Excel/CSV -->
                <div class="fancy-card p-6 import-section">
                    <header class="flex justify-between items-center mb-4 border-b pb-3">
                        <h5 class="text-xl font-semibold text-gray-700 flex items-center">
                            <i class="fas fa-file-import mr-2 text-purple-500"></i> Import from Excel/CSV
                        </h5>
                    </header>
                    <form method="POST" enctype="multipart/form-data" id="importForm">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Import Type</label>
                            <select class="w-full p-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition duration-150" id="import_type" name="import_type" required>
                                <option value="">Select Import Type</option>
                                <option value="main_topics">Main Topics</option>
                                <option value="sub_topics">Sub Topics</option>
                            </select>
                        </div>
                        
                        <div class="mb-4 hidden" id="import_main_topic_container">
                            <label for="import_main_topic_id" class="block text-sm font-medium text-gray-700 mb-1">Select Main Topic</label>
                            <select class="w-full p-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition duration-150" id="import_main_topic_id" name="import_main_topic_id">
                                <option value="">Select Main Topic</option>
                                <?php foreach ($main_topics as $topic): ?>
                                    <option value="<?php echo $topic['id']; ?>">
                                        Ch. <?php echo $topic['chapter']; ?>: <?php echo htmlspecialchars($topic['topic_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Upload CSV File</label>
                            <div class="file-upload-area" id="fileUploadArea">
                                <i class="fas fa-file-csv text-4xl text-purple-500 mb-3"></i>
                                <p class="text-gray-600 mb-2">Drag & drop your CSV file here or click to browse</p>
                                <p class="text-sm text-gray-500 mb-3">Supported format: CSV</p>
                                <input type="file" id="excel_file" name="excel_file" accept=".csv" class="hidden" required>
                                <button type="button" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition duration-200" onclick="document.getElementById('excel_file').click()">
                                    <i class="fas fa-upload mr-2"></i> Choose File
                                </button>
                                <p id="fileName" class="mt-2 text-sm text-gray-600"></p>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <p class="text-sm text-gray-600 mb-2">Download template files:</p>
                            <div class="flex space-x-2">
                                <a href="#" class="download-template" onclick="downloadTemplate('main_topics')">
                                    <i class="fas fa-download mr-2"></i> Main Topics Template
                                </a>
                                <a href="#" class="download-template" onclick="downloadTemplate('sub_topics')">
                                    <i class="fas fa-download mr-2"></i> Sub Topics Template
                                </a>
                            </div>
                        </div>
                        
                        <button type="submit" name="import_excel" class="w-full mt-4 px-4 py-2 bg-purple-600 text-white font-semibold rounded-lg hover:bg-purple-700 transition duration-300 shadow-lg">
                            <i class="fas fa-file-import mr-2"></i> Import Data
                        </button>
                    </form>
                </div>
                
                <!-- Add Main Topics Form -->
                <div class="fancy-card p-6">
                    <header class="flex justify-between items-center mb-4 border-b pb-3">
                        <h5 class="text-xl font-semibold text-gray-700 flex items-center">
                            <i class="fas fa-layer-group mr-2 text-blue-500"></i> Add Main Topics
                        </h5>
                        <button type="button" class="px-3 py-1 text-sm bg-blue-500 text-white rounded-full hover:bg-blue-600 transition duration-200 shadow-md" id="addMainTopicField">
                            <i class="fas fa-plus"></i> Add More
                        </button>
                    </header>
                    <form method="POST" id="mainTopicsForm">
                        <div id="mainTopicsContainer">
                            <div class="dynamic-field-group">
                                <span class="remove-field" onclick="removeField(this)"><i class="fas fa-times-circle"></i></span>
                                <div class="mb-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Chapter Number</label>
                                    <input type="number" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150" name="chapter[]" required min="1">
                                </div>
                                <div class="mb-1">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Topic Name</label>
                                    <input type="text" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150" name="topic_name[]" required>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="add_main_topics" class="w-full mt-4 px-4 py-2 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition duration-300 shadow-lg">
                            <i class="fas fa-plus-circle mr-2"></i> Add Main Topics
                        </button>
                    </form>
                </div>

                <!-- Add Sub Topics Form -->
                <div class="fancy-card p-6">
                    <header class="flex justify-between items-center mb-4 border-b pb-3">
                        <h5 class="text-xl font-semibold text-gray-700 flex items-center">
                            <i class="fas fa-tasks mr-2 text-emerald-500"></i> Add Sub Topics
                        </h5>
                        <button type="button" class="px-3 py-1 text-sm bg-emerald-500 text-white rounded-full hover:bg-emerald-600 transition duration-200 shadow-md" id="addSubTopicField">
                            <i class="fas fa-plus"></i> Add More
                        </button>
                    </header>
                    <form method="POST" id="subTopicsForm">
                        <div class="mb-4">
                            <label for="main_topic_id" class="block text-sm font-medium text-gray-700 mb-1">Select Main Topic</label>
                            <select class="w-full p-2 border border-gray-300 rounded-md focus:ring-emerald-500 focus:border-emerald-500 transition duration-150" id="main_topic_id" name="main_topic_id" required>
                                <option value="">Select Main Topic</option>
                                <?php foreach ($main_topics as $topic): ?>
                                    <option value="<?php echo $topic['id']; ?>">
                                        Ch. <?php echo $topic['chapter']; ?>: <?php echo htmlspecialchars($topic['topic_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="subTopicsContainer">
                            <div class="dynamic-field-group">
                                <span class="remove-field" onclick="removeField(this)"><i class="fas fa-times-circle"></i></span>
                                <div class="mb-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Sub Topic Name</label>
                                    <input type="text" class="w-full p-2 border border-gray-300 rounded-md focus:ring-emerald-500 focus:border-emerald-500 transition duration-150" name="sub_topic_name[]" required>
                                </div>
                                <div class="flex space-x-4">
                                    <div class="flex items-center">
                                        <input class="w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500" type="checkbox" name="theory_completed[]" value="1" id="theory_0">
                                        <label class="ml-2 text-sm font-medium text-gray-700" for="theory_0">Theory Completed</label>
                                    </div>
                                    <div class="flex items-center">
                                        <input class="w-4 h-4 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500" type="checkbox" name="practical_completed[]" value="1" id="practical_0">
                                        <label class="ml-2 text-sm font-medium text-gray-700" for="practical_0">Practical Completed</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="add_sub_topics" class="w-full mt-4 px-4 py-2 bg-emerald-600 text-white font-semibold rounded-lg hover:bg-emerald-700 transition duration-300 shadow-lg">
                            <i class="fas fa-clipboard-list mr-2"></i> Add Sub Topics
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Topics List -->
            <div class="w-full lg:w-2/3">
                <div class="fancy-card p-6 h-full">
                    <header class="mb-4 border-b pb-3 flex justify-between items-center">
                        <h5 class="text-xl font-semibold text-gray-700 flex items-center">
                            <i class="fas fa-book-open mr-2 text-gray-600"></i> Topics & Progress
                        </h5>
                        <div class="text-sm text-gray-500">
                            Total: <?php echo count($main_topics_with_sub_topics); ?> Chapters, <?php echo $total_sub_topics; ?> Sub-topics
                        </div>
                    </header>
                    
                    <!-- Chapter Selection Dropdown -->
                    <div class="mb-4">
                        <label for="chapterSelect" class="block text-sm font-medium text-gray-700 mb-1">Select Chapter</label>
                        <select id="chapterSelect" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150">
                            <option value="all">All Chapters</option>
                            <?php foreach ($main_topics_with_sub_topics as $main_topic): ?>
                                <option value="chapter-<?php echo $main_topic['id']; ?>">
                                    Ch. <?php echo $main_topic['chapter']; ?>: <?php echo htmlspecialchars($main_topic['topic_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="scrollable-topics">
                        <div class="space-y-4" id="topicsContainer">
                            <?php if (empty($main_topics_with_sub_topics)): ?>
                                <div class="text-center text-gray-500 py-8 border border-dashed border-gray-300 rounded-lg">
                                    <i class="fas fa-box-open fa-3x mb-3 text-gray-400"></i>
                                    <p class="text-lg">No topics added yet. Start by adding a main topic on the left.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($main_topics_with_sub_topics as $main_topic): ?>
                                    <div class="chapter-container bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden" id="chapter-<?php echo $main_topic['id']; ?>">
                                        <!-- Main Topic Header -->
                                        <div class="p-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                                            <h6 class="text-lg font-bold text-gray-800 flex items-center">
                                                <i class="fas fa-sitemap mr-3 text-blue-500"></i>
                                                Ch. <?php echo $main_topic['chapter']; ?>: <?php echo htmlspecialchars($main_topic['topic_name']); ?>
                                            </h6>
                                            <div class="flex space-x-2">
                                                <button type="button" 
                                                        class="action-btn bg-blue-500 text-white hover:bg-blue-600"
                                                        onclick="openEditMainTopicModal(<?php echo $main_topic['id']; ?>, <?php echo $main_topic['chapter']; ?>, '<?php echo htmlspecialchars($main_topic['topic_name']); ?>')">
                                                    <i class="fas fa-edit mr-1"></i> Edit
                                                </button>
                                                <button type="button" 
                                                        class="action-btn bg-red-500 text-white hover:bg-red-600"
                                                        onclick="openDeleteMainTopicModal(<?php echo $main_topic['id']; ?>, '<?php echo htmlspecialchars($main_topic['topic_name']); ?>')">
                                                    <i class="fas fa-trash mr-1"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- Sub Topics List -->
                                        <div class="divide-y divide-gray-100">
                                            <?php if (empty($main_topic['sub_topics'])): ?>
                                                <div class="p-4 text-sm text-gray-500 flex justify-between items-center">
                                                    <span>No sub topics added for this chapter.</span>
                                                    <button type="button" 
                                                            class="text-blue-500 hover:text-blue-700 text-sm font-medium"
                                                            onclick="document.getElementById('main_topic_id').value = '<?php echo $main_topic['id']; ?>'; document.getElementById('main_topic_id').scrollIntoView({behavior: 'smooth'});">
                                                        <i class="fas fa-plus mr-1"></i> Add Sub Topics
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($main_topic['sub_topics'] as $sub_topic): ?>
                                                    <?php
                                                    $is_completed = ($sub_topic['theory_completed'] && $sub_topic['practical_completed']);
                                                    $row_class = $is_completed ? 'bg-green-50' : 'hover:bg-gray-50';
                                                    ?>
                                                    <div class="topic-item-row p-4 flex justify-between items-center <?php echo $row_class; ?>"
                                                         data-sub-topic-id="<?php echo $sub_topic['id']; ?>"
                                                         data-sub-topic-name="<?php echo htmlspecialchars($sub_topic['sub_topic_name']); ?>"
                                                         data-theory-completed="<?php echo $sub_topic['theory_completed']; ?>"
                                                         data-practical-completed="<?php echo $sub_topic['practical_completed']; ?>">
                                                        
                                                        <!-- Topic Name and Status Badges -->
                                                        <div class="flex-1">
                                                            <p class="text-base font-medium text-gray-800 transition duration-200">
                                                                <?php echo htmlspecialchars($sub_topic['sub_topic_name']); ?>
                                                            </p>
                                                            <div class="mt-1 flex space-x-2">
                                                                <!-- Theory Status -->
                                                                <?php if ($sub_topic['theory_completed']): ?>
                                                                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800 flex items-center">
                                                                        <i class="fas fa-check-circle mr-1"></i> Theory
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-200 text-gray-600 flex items-center">
                                                                        <i class="fas fa-hourglass-half mr-1"></i> Theory
                                                                    </span>
                                                                <?php endif; ?>
                                                                
                                                                <!-- Practical Status -->
                                                                <?php if ($sub_topic['practical_completed']): ?>
                                                                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-cyan-100 text-cyan-800 flex items-center">
                                                                        <i class="fas fa-check-circle mr-1"></i> Practical
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-200 text-gray-600 flex items-center">
                                                                        <i class="fas fa-hourglass-half mr-1"></i> Practical
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Action Buttons -->
                                                        <div class="flex items-center space-x-2 ml-4">
                                                            <button type="button" 
                                                                    class="text-blue-500 hover:text-blue-700 transition duration-200"
                                                                    onclick="openEditSubTopicModal(<?php echo $sub_topic['id']; ?>, '<?php echo htmlspecialchars($sub_topic['sub_topic_name']); ?>', <?php echo $sub_topic['theory_completed']; ?>, <?php echo $sub_topic['practical_completed']; ?>)">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" 
                                                                    class="text-red-500 hover:text-red-700 transition duration-200"
                                                                    onclick="openDeleteSubTopicModal(<?php echo $sub_topic['id']; ?>, '<?php echo htmlspecialchars($sub_topic['sub_topic_name']); ?>')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                            <i class="fas fa-chevron-right text-gray-400 transition-transform duration-200 group-hover:translate-x-1"></i>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Progress Modal -->
    <div id="editProgressModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-backdrop fixed inset-0 bg-black opacity-50" id="modalBackdrop"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="modal-fade-in bg-white rounded-xl shadow-2xl w-full max-w-md mx-auto">
                <div class="bg-blue-600 text-white rounded-t-xl p-4 border-b-0">
                    <h5 class="text-xl font-bold flex items-center"><i class="fas fa-edit mr-2"></i> Update Progress</h5>
                </div>
                <form method="POST">
                    <div class="p-6">
                        <input type="hidden" id="edit_sub_topic_id" name="sub_topic_id">
                        <div class="mb-4">
                            <label class="block text-lg font-semibold text-gray-700 mb-2" id="sub_topic_name_label"></label>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center p-3 border border-gray-200 rounded-lg transition duration-150 hover:bg-gray-50">
                                <input class="w-5 h-5 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500" type="checkbox" id="edit_theory_completed" name="theory_completed">
                                <label class="ml-3 text-base font-medium text-gray-700" for="edit_theory_completed">Theory Completed</label>
                            </div>
                            <div class="flex items-center p-3 border border-gray-200 rounded-lg transition duration-150 hover:bg-gray-50">
                                <input class="w-5 h-5 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500" type="checkbox" id="edit_practical_completed" name="practical_completed">
                                <label class="ml-3 text-base font-medium text-gray-700" for="edit_practical_completed">Practical Completed</label>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end p-4 bg-gray-50 rounded-b-xl border-t">
                        <button type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-200 font-semibold mr-3" id="modalCancelBtn">Cancel</button>
                        <button type="submit" name="update_progress" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 font-semibold flex items-center">
                            <i class="fas fa-save mr-2"></i> Update Progress
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Main Topic Modal -->
    <div id="editMainTopicModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-backdrop fixed inset-0 bg-black opacity-50" id="editMainTopicBackdrop"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="modal-fade-in bg-white rounded-xl shadow-2xl w-full max-w-md mx-auto">
                <div class="bg-blue-600 text-white rounded-t-xl p-4 border-b-0">
                    <h5 class="text-xl font-bold flex items-center"><i class="fas fa-edit mr-2"></i> Edit Chapter</h5>
                </div>
                <form method="POST">
                    <div class="p-6">
                        <input type="hidden" id="edit_main_topic_id" name="main_topic_id">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Chapter Number</label>
                            <input type="number" id="edit_chapter" name="chapter" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" required min="1">
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Topic Name</label>
                            <input type="text" id="edit_topic_name" name="topic_name" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                    </div>
                    <div class="flex justify-end p-4 bg-gray-50 rounded-b-xl border-t">
                        <button type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-200 font-semibold mr-3" onclick="closeEditMainTopicModal()">Cancel</button>
                        <button type="submit" name="edit_main_topic" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 font-semibold flex items-center">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Main Topic Modal -->
    <div id="deleteMainTopicModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-backdrop fixed inset-0 bg-black opacity-50" id="deleteMainTopicBackdrop"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="modal-fade-in bg-white rounded-xl shadow-2xl w-full max-w-md mx-auto">
                <div class="bg-red-600 text-white rounded-t-xl p-4 border-b-0">
                    <h5 class="text-xl font-bold flex items-center"><i class="fas fa-exclamation-triangle mr-2"></i> Delete Chapter</h5>
                </div>
                <form method="POST">
                    <div class="p-6">
                        <input type="hidden" id="delete_main_topic_id" name="main_topic_id">
                        <div class="mb-4">
                            <p class="text-lg font-semibold text-gray-800">Are you sure you want to delete this chapter?</p>
                            <p class="text-gray-600 mt-2" id="delete_main_topic_name"></p>
                            <p class="text-red-500 text-sm mt-3">
                                <i class="fas fa-exclamation-circle mr-1"></i>
                                This will also delete all sub-topics under this chapter. This action cannot be undone.
                            </p>
                        </div>
                    </div>
                    <div class="flex justify-end p-4 bg-gray-50 rounded-b-xl border-t">
                        <button type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-200 font-semibold mr-3" onclick="closeDeleteMainTopicModal()">Cancel</button>
                        <button type="submit" name="delete_main_topic" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-300 font-semibold flex items-center">
                            <i class="fas fa-trash mr-2"></i> Delete Chapter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Sub Topic Modal -->
    <div id="editSubTopicModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-backdrop fixed inset-0 bg-black opacity-50" id="editSubTopicBackdrop"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="modal-fade-in bg-white rounded-xl shadow-2xl w-full max-w-md mx-auto">
                <div class="bg-emerald-600 text-white rounded-t-xl p-4 border-b-0">
                    <h5 class="text-xl font-bold flex items-center"><i class="fas fa-edit mr-2"></i> Edit Sub Topic</h5>
                </div>
                <form method="POST">
                    <div class="p-6">
                        <input type="hidden" id="edit_sub_topic_id_form" name="sub_topic_id">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Sub Topic Name</label>
                            <input type="text" id="edit_sub_topic_name" name="sub_topic_name" class="w-full p-2 border border-gray-300 rounded-md focus:ring-emerald-500 focus:border-emerald-500" required>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center p-3 border border-gray-200 rounded-lg transition duration-150 hover:bg-gray-50">
                                <input class="w-5 h-5 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500" type="checkbox" id="edit_sub_topic_theory" name="theory_completed">
                                <label class="ml-3 text-base font-medium text-gray-700" for="edit_sub_topic_theory">Theory Completed</label>
                            </div>
                            <div class="flex items-center p-3 border border-gray-200 rounded-lg transition duration-150 hover:bg-gray-50">
                                <input class="w-5 h-5 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500" type="checkbox" id="edit_sub_topic_practical" name="practical_completed">
                                <label class="ml-3 text-base font-medium text-gray-700" for="edit_sub_topic_practical">Practical Completed</label>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end p-4 bg-gray-50 rounded-b-xl border-t">
                        <button type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-200 font-semibold mr-3" onclick="closeEditSubTopicModal()">Cancel</button>
                        <button type="submit" name="edit_sub_topic" class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition duration-300 font-semibold flex items-center">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Sub Topic Modal -->
    <div id="deleteSubTopicModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-backdrop fixed inset-0 bg-black opacity-50" id="deleteSubTopicBackdrop"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="modal-fade-in bg-white rounded-xl shadow-2xl w-full max-w-md mx-auto">
                <div class="bg-red-600 text-white rounded-t-xl p-4 border-b-0">
                    <h5 class="text-xl font-bold flex items-center"><i class="fas fa-exclamation-triangle mr-2"></i> Delete Sub Topic</h5>
                </div>
                <form method="POST">
                    <div class="p-6">
                        <input type="hidden" id="delete_sub_topic_id" name="sub_topic_id">
                        <div class="mb-4">
                            <p class="text-lg font-semibold text-gray-800">Are you sure you want to delete this sub topic?</p>
                            <p class="text-gray-600 mt-2" id="delete_sub_topic_name"></p>
                            <p class="text-red-500 text-sm mt-3">
                                <i class="fas fa-exclamation-circle mr-1"></i>
                                This action cannot be undone.
                            </p>
                        </div>
                    </div>
                    <div class="flex justify-end p-4 bg-gray-50 rounded-b-xl border-t">
                        <button type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-200 font-semibold mr-3" onclick="closeDeleteSubTopicModal()">Cancel</button>
                        <button type="submit" name="delete_sub_topic" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-300 font-semibold flex items-center">
                            <i class="fas fa-trash mr-2"></i> Delete Sub Topic
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Performance-optimized modal handling
        class ProgressModal {
            constructor() {
                this.modal = document.getElementById('editProgressModal');
                this.backdrop = document.getElementById('modalBackdrop');
                this.cancelBtn = document.getElementById('modalCancelBtn');
                this.topicRows = document.querySelectorAll('.topic-item-row');
                
                this.init();
            }
            
            init() {
                // Use event delegation for better performance
                document.addEventListener('click', (e) => {
                    const topicRow = e.target.closest('.topic-item-row');
                    if (topicRow && !e.target.closest('button')) {
                        this.openModal(topicRow);
                    }
                });
                
                // Close modal events
                this.backdrop.addEventListener('click', () => this.closeModal());
                this.cancelBtn.addEventListener('click', () => this.closeModal());
                
                // Close on escape key
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && !this.modal.classList.contains('hidden')) {
                        this.closeModal();
                    }
                });
            }
            
            openModal(topicRow) {
                const subTopicId = topicRow.getAttribute('data-sub-topic-id');
                const subTopicName = topicRow.getAttribute('data-sub-topic-name');
                const theoryCompleted = topicRow.getAttribute('data-theory-completed') === '1';
                const practicalCompleted = topicRow.getAttribute('data-practical-completed') === '1';
                
                document.getElementById('edit_sub_topic_id').value = subTopicId;
                document.getElementById('sub_topic_name_label').textContent = subTopicName;
                document.getElementById('edit_theory_completed').checked = theoryCompleted;
                document.getElementById('edit_practical_completed').checked = practicalCompleted;
                
                this.modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden'; // Prevent scrolling
            }
            
            closeModal() {
                this.modal.classList.add('hidden');
                document.body.style.overflow = ''; // Restore scrolling
            }
        }

        // Import functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Import type change handler
            const importType = document.getElementById('import_type');
            const importMainTopicContainer = document.getElementById('import_main_topic_container');
            const importMainTopicId = document.getElementById('import_main_topic_id');
            
            if (importType) {
                importType.addEventListener('change', function() {
                    if (this.value === 'sub_topics') {
                        importMainTopicContainer.classList.remove('hidden');
                        importMainTopicId.required = true;
                    } else {
                        importMainTopicContainer.classList.add('hidden');
                        importMainTopicId.required = false;
                    }
                });
            }
            
            // File upload handling
            const fileInput = document.getElementById('excel_file');
            const fileUploadArea = document.getElementById('fileUploadArea');
            const fileName = document.getElementById('fileName');
            
            if (fileInput && fileUploadArea) {
                // Click on area to trigger file input
                fileUploadArea.addEventListener('click', function(e) {
                    if (e.target !== fileInput) {
                        fileInput.click();
                    }
                });
                
                // File input change
                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        fileName.textContent = this.files[0].name;
                        fileUploadArea.classList.add('dragover');
                    } else {
                        fileName.textContent = '';
                        fileUploadArea.classList.remove('dragover');
                    }
                });
                
                // Drag and drop functionality
                fileUploadArea.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('dragover');
                });
                
                fileUploadArea.addEventListener('dragleave', function() {
                    this.classList.remove('dragover');
                });
                
                fileUploadArea.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                    
                    if (e.dataTransfer.files.length > 0) {
                        fileInput.files = e.dataTransfer.files;
                        fileName.textContent = e.dataTransfer.files[0].name;
                    }
                });
            }
        });
        
        // Download template function
        function downloadTemplate(type) {
            let csvContent = '';
            let filename = '';
            
            if (type === 'main_topics') {
                csvContent = 'chapter,topic_name\n1,Introduction to Programming\n2,Data Types and Variables\n3,Control Structures';
                filename = 'main_topics_template.csv';
            } else if (type === 'sub_topics') {
                csvContent = 'sub_topic_name,theory_completed,practical_completed\nVariables and Data Types,Yes,No\nOperators and Expressions,No,Yes\nConditional Statements,Yes,Yes';
                filename = 'sub_topics_template.csv';
            }
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Main Topic Modal Functions
        function openEditMainTopicModal(id, chapter, topicName) {
            document.getElementById('edit_main_topic_id').value = id;
            document.getElementById('edit_chapter').value = chapter;
            document.getElementById('edit_topic_name').value = topicName;
            document.getElementById('editMainTopicModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeEditMainTopicModal() {
            document.getElementById('editMainTopicModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        function openDeleteMainTopicModal(id, topicName) {
            document.getElementById('delete_main_topic_id').value = id;
            document.getElementById('delete_main_topic_name').textContent = `Chapter: ${topicName}`;
            document.getElementById('deleteMainTopicModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteMainTopicModal() {
            document.getElementById('deleteMainTopicModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        // Sub Topic Modal Functions
        function openEditSubTopicModal(id, topicName, theoryCompleted, practicalCompleted) {
            document.getElementById('edit_sub_topic_id_form').value = id;
            document.getElementById('edit_sub_topic_name').value = topicName;
            document.getElementById('edit_sub_topic_theory').checked = theoryCompleted;
            document.getElementById('edit_sub_topic_practical').checked = practicalCompleted;
            document.getElementById('editSubTopicModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeEditSubTopicModal() {
            document.getElementById('editSubTopicModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        function openDeleteSubTopicModal(id, topicName) {
            document.getElementById('delete_sub_topic_id').value = id;
            document.getElementById('delete_sub_topic_name').textContent = `Sub Topic: ${topicName}`;
            document.getElementById('deleteSubTopicModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteSubTopicModal() {
            document.getElementById('deleteSubTopicModal').classList.add('hidden');
            document.body.style.overflow = '';
        }
        
        // Initialize modal when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            new ProgressModal();
            
            // Chapter selection functionality
            const chapterSelect = document.getElementById('chapterSelect');
            if (chapterSelect) {
                chapterSelect.addEventListener('change', function() {
                    const selectedValue = this.value;
                    const allChapters = document.querySelectorAll('.chapter-container');
                    
                    if (selectedValue === 'all') {
                        // Show all chapters
                        allChapters.forEach(chapter => {
                            chapter.style.display = 'block';
                        });
                    } else {
                        // Hide all chapters first
                        allChapters.forEach(chapter => {
                            chapter.style.display = 'none';
                        });
                        
                        // Show selected chapter
                        const selectedChapter = document.getElementById(selectedValue);
                        if (selectedChapter) {
                            selectedChapter.style.display = 'block';
                        }
                    }
                });
            }

            // Close modals when clicking on backdrop
            document.getElementById('editMainTopicBackdrop')?.addEventListener('click', closeEditMainTopicModal);
            document.getElementById('deleteMainTopicBackdrop')?.addEventListener('click', closeDeleteMainTopicModal);
            document.getElementById('editSubTopicBackdrop')?.addEventListener('click', closeEditSubTopicModal);
            document.getElementById('deleteSubTopicBackdrop')?.addEventListener('click', closeDeleteSubTopicModal);

            // Close modals on escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeEditMainTopicModal();
                    closeDeleteMainTopicModal();
                    closeEditSubTopicModal();
                    closeDeleteSubTopicModal();
                }
            });
        });

        // Dynamic Field Handling (JS logic updated to assign unique IDs to checkboxes)
        let mainTopicCount = 1;
        let subTopicCount = 1;
        
        // Add main topic field
        document.getElementById('addMainTopicField').addEventListener('click', function() {
            const container = document.getElementById('mainTopicsContainer');
            const newField = document.createElement('div');
            newField.className = 'dynamic-field-group';
            newField.innerHTML = `
                <span class="remove-field" onclick="removeField(this)"><i class="fas fa-times-circle"></i></span>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Chapter Number</label>
                    <input type="number" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150" name="chapter[]" required min="1">
                </div>
                <div class="mb-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Topic Name</label>
                    <input type="text" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150" name="topic_name[]" required>
                </div>
            `;
            container.appendChild(newField);
        });

        // Add sub topic field
        document.getElementById('addSubTopicField').addEventListener('click', function() {
            const container = document.getElementById('subTopicsContainer');
            const newField = document.createElement('div');
            const uniqueId = subTopicCount++;
            
            newField.className = 'dynamic-field-group';
            newField.innerHTML = `
                <span class="remove-field" onclick="removeField(this)"><i class="fas fa-times-circle"></i></span>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sub Topic Name</label>
                    <input type="text" class="w-full p-2 border border-gray-300 rounded-md focus:ring-emerald-500 focus:border-emerald-500 transition duration-150" name="sub_topic_name[]" required>
                </div>
                <div class="flex space-x-4">
                    <div class="flex items-center">
                        <input class="w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500" type="checkbox" name="theory_completed[]" value="1" id="theory_${uniqueId}">
                        <label class="ml-2 text-sm font-medium text-gray-700" for="theory_${uniqueId}">Theory Completed</label>
                    </div>
                    <div class="flex items-center">
                        <input class="w-4 h-4 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500" type="checkbox" name="practical_completed[]" value="1" id="practical_${uniqueId}">
                        <label class="ml-2 text-sm font-medium text-gray-700" for="practical_${uniqueId}">Practical Completed</label>
                    </div>
                </div>
            `;
            container.appendChild(newField);
        });

        // Remove field function
        function removeField(element) {
            // Find the closest parent with class 'dynamic-field-group' and remove it
            const group = element.closest('.dynamic-field-group');
            if (group) {
                group.remove();
            }
        }
    </script>
</body>
</html>