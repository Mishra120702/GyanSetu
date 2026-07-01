<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
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
        $topic_types = $_POST['topic_type'] ?? [];
        
        $added_count = 0;
        for ($i = 0; $i < count($chapters); $i++) {
            if (!empty($chapters[$i]) && !empty($topic_names[$i])) {
                $topic_type = $topic_types[$i] ?? 'both';
                $stmt = $db->prepare("INSERT INTO main_topics (batch_name, chapter, topic_name, topic_type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$batch_id, $chapters[$i], $topic_names[$i], $topic_type]);
                $added_count++;
            }
        }
        
        $_SESSION['success'] = "$added_count main topic(s) added successfully!";
    } 
    elseif (isset($_POST['add_sub_topics'])) {
        // Add multiple sub topics for multiple main topics
        $main_topic_ids = $_POST['main_topic_id'] ?? [];
        $sub_topic_names = $_POST['sub_topic_name'] ?? [];
        $theory_completed = $_POST['theory_completed'] ?? [];
        $practical_completed = $_POST['practical_completed'] ?? [];
        
        $added_count = 0;
        
        for ($i = 0; $i < count($sub_topic_names); $i++) {
            if (!empty($sub_topic_names[$i]) && !empty($main_topic_ids[$i])) {
                $main_topic_id = $main_topic_ids[$i];
                $sub_topic_name = $sub_topic_names[$i];
                $theory = isset($theory_completed[$i]) ? 1 : 0;
                $practical = isset($practical_completed[$i]) ? 1 : 0;
                
                // Get main topic details to check topic type
                $main_topic_stmt = $db->prepare("SELECT topic_type FROM main_topics WHERE id = ?");
                $main_topic_stmt->execute([$main_topic_id]);
                $main_topic = $main_topic_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($main_topic) {
                    $topic_type = $main_topic['topic_type'];
                    
                    // Adjust based on main topic type
                    $theory_completed_val = ($topic_type === 'theory' || $topic_type === 'both') ? $theory : 0;
                    $practical_completed_val = ($topic_type === 'practical' || $topic_type === 'both') ? $practical : 0;
                    
                    $stmt = $db->prepare("INSERT INTO sub_topics (main_topic_id, sub_topic_name, theory_completed, practical_completed, completed_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$main_topic_id, $sub_topic_name, $theory_completed_val, $practical_completed_val, $_SESSION['user_id']]);
                    $added_count++;
                }
            }
        }
        
        $_SESSION['success'] = "$added_count sub topic(s) added successfully!";
    }
    elseif (isset($_POST['update_progress'])) {
        // Update sub topic progress
        $sub_topic_id = $_POST['sub_topic_id'];
        $theory_completed = !empty($_POST['theory_completed']) && $_POST['theory_completed'] !== '0' ? 1 : 0;
        $practical_completed = !empty($_POST['practical_completed']) && $_POST['practical_completed'] !== '0' ? 1 : 0;
        
        $stmt = $db->prepare("UPDATE sub_topics SET theory_completed = ?, practical_completed = ?, completed_by = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([$theory_completed, $practical_completed, $_SESSION['user_id'], $sub_topic_id]);
        
        logSystemActivity($db, $_SESSION['user_id'], 'TOPIC_COMPLETED', "Admin/Mentor updated subtopic progress (ID: $sub_topic_id) to Theory: $theory_completed, Practical: $practical_completed.");
        
        $_SESSION['success'] = "Progress updated successfully!";
    }
    elseif (isset($_POST['edit_main_topic'])) {
        // Edit main topic
        $main_topic_id = $_POST['main_topic_id'];
        $chapter = $_POST['chapter'];
        $topic_name = $_POST['topic_name'];
        $topic_type = $_POST['topic_type'] ?? 'both';
        
        $stmt = $db->prepare("UPDATE main_topics SET chapter = ?, topic_name = ?, topic_type = ? WHERE id = ? AND batch_name = ?");
        $stmt->execute([$chapter, $topic_name, $topic_type, $main_topic_id, $batch_id]);
        
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
        
        logSystemActivity($db, $_SESSION['user_id'], 'TOPIC_COMPLETED', "Admin/Mentor updated subtopic (ID: $sub_topic_id, Name: '$sub_topic_name') to Theory: $theory_completed, Practical: $practical_completed.");
        
        $_SESSION['success'] = "Sub topic updated successfully!";
    }
    elseif (isset($_POST['delete_sub_topic'])) {
        // Delete sub topic
        $sub_topic_id = $_POST['sub_topic_id'];
        
        $stmt = $db->prepare("DELETE FROM sub_topics WHERE id = ?");
        $stmt->execute([$sub_topic_id]);
        
        $_SESSION['success'] = "Sub topic deleted successfully!";
    }
    elseif (isset($_POST['toggle_main_topic'])) {
        // Toggle main topic status
        $main_topic_id = $_POST['main_topic_id'];
        
        $stmt = $db->prepare("UPDATE main_topics SET is_active = NOT is_active WHERE id = ? AND batch_name = ?");
        $stmt->execute([$main_topic_id, $batch_id]);
        
        $_SESSION['success'] = "Chapter status toggled successfully!";
    }
    elseif (isset($_POST['import_excel'])) {
        // Handle Excel/CSV import
        $import_type = $_POST['import_type'];
        
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
                                           $data[0] === 'sub_topic_name' || $data[0] === 'Sub Topic Name' ||
                                           $data[0] === 'sub_topic' || $data[0] === 'Sub Topic')) {
                            continue;
                        }
                        
                        if ($import_type === 'main_topics') {
                            // Import main topics
                            if (count($data) >= 2) {
                                $chapter = trim($data[0]);
                                $topic_name = trim($data[1]);
                                $topic_type = isset($data[2]) ? trim($data[2]) : 'both';
                                
                                if (!empty($chapter) && !empty($topic_name)) {
                                    try {
                                        // Check if chapter already exists for this batch
                                        $check_stmt = $db->prepare("SELECT id FROM main_topics WHERE batch_name = ? AND chapter = ?");
                                        $check_stmt->execute([$batch_id, $chapter]);
                                        $existing = $check_stmt->fetch();
                                        
                                        if ($existing) {
                                            // Update existing
                                            $stmt = $db->prepare("UPDATE main_topics SET topic_name = ?, topic_type = ? WHERE id = ?");
                                            $stmt->execute([$topic_name, $topic_type, $existing['id']]);
                                        } else {
                                            // Insert new
                                            $stmt = $db->prepare("INSERT INTO main_topics (batch_name, chapter, topic_name, topic_type) VALUES (?, ?, ?, ?)");
                                            $stmt->execute([$batch_id, $chapter, $topic_name, $topic_type]);
                                        }
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
                        } elseif ($import_type === 'sub_topics') {
                            // Import sub topics using chapter number directly
                            if (count($data) >= 4) {
                                $chapter = trim($data[0]);
                                $sub_topic_name = trim($data[1]);
                                $theory_completed = strtolower(trim($data[2])) === 'yes' || $data[2] === '1' || strtolower(trim($data[2])) === 'true' || strtolower(trim($data[2])) === 'completed' ? 1 : 0;
                                $practical_completed = strtolower(trim($data[3])) === 'yes' || $data[3] === '1' || strtolower(trim($data[3])) === 'true' || strtolower(trim($data[3])) === 'completed' ? 1 : 0;
                                
                                if (!empty($chapter) && !empty($sub_topic_name)) {
                                    try {
                                        // Get main_topic_id from chapter number
                                        $chapter_stmt = $db->prepare("SELECT id, topic_type FROM main_topics WHERE batch_name = ? AND chapter = ?");
                                        $chapter_stmt->execute([$batch_id, $chapter]);
                                        $main_topic = $chapter_stmt->fetch();
                                        
                                        if ($main_topic) {
                                            $main_topic_id = $main_topic['id'];
                                            $topic_type = $main_topic['topic_type'];
                                            
                                            // Adjust based on main topic type
                                            $theory_completed_val = ($topic_type === 'theory' || $topic_type === 'both') ? $theory_completed : 0;
                                            $practical_completed_val = ($topic_type === 'practical' || $topic_type === 'both') ? $practical_completed : 0;
                                            
                                            // Check if sub-topic already exists for this main topic
                                            $check_stmt = $db->prepare("SELECT id FROM sub_topics WHERE main_topic_id = ? AND sub_topic_name = ?");
                                            $check_stmt->execute([$main_topic_id, $sub_topic_name]);
                                            $existing = $check_stmt->fetch();
                                            
                                            if ($existing) {
                                                // Update existing
                                                $stmt = $db->prepare("UPDATE sub_topics SET theory_completed = ?, practical_completed = ?, completed_by = ?, completed_at = NOW() WHERE id = ?");
                                                $stmt->execute([$theory_completed_val, $practical_completed_val, $_SESSION['user_id'], $existing['id']]);
                                            } else {
                                                // Insert new
                                                $stmt = $db->prepare("INSERT INTO sub_topics (main_topic_id, sub_topic_name, theory_completed, practical_completed, completed_by) VALUES (?, ?, ?, ?, ?)");
                                                $stmt->execute([$main_topic_id, $sub_topic_name, $theory_completed_val, $practical_completed_val, $_SESSION['user_id']]);
                                            }
                                            $added_count++;
                                        } else {
                                            $error_messages[] = "Row $row: Chapter $chapter not found. Please add main topic for chapter $chapter first.";
                                        }
                                    } catch (PDOException $e) {
                                        $error_messages[] = "Row $row: " . $e->getMessage();
                                    }
                                } else {
                                    $error_messages[] = "Row $row: Missing chapter number or sub topic name";
                                }
                            } else {
                                $error_messages[] = "Row $row: Invalid format. Expected: chapter,sub_topic_name,theory_completed,practical_completed";
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

// Fetch main topics for this batch (only active ones)
$main_topics_stmt = $db->prepare("SELECT * FROM main_topics WHERE batch_name = ? AND is_active = 1 ORDER BY chapter");
$main_topics_stmt->execute([$batch_id]);
$main_topics = $main_topics_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique chapters for dropdown
$chapters = [];
foreach ($main_topics as $topic) {
    $chapters[$topic['chapter']] = $topic['chapter'];
}
sort($chapters);

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
            --color-primary: #3b82f6;
            --color-secondary: #10b981;
            --color-info: #06b6d4;
            --color-bg: #f3f4f6;
            --color-card: #ffffff;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--color-bg);
            transition: all 0.3s ease;
        }
        
        .custom-progress-bar {
            height: 8px;
            border-radius: 9999px;
            overflow: hidden;
            background-color: #e5e7eb;
        }
        .custom-progress-bar-fill {
            height: 100%;
            transition: width 0.7s ease-in-out;
        }

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

        .dynamic-field-group {
            position: relative;
            padding: 1rem;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            margin-bottom: 1rem;
            background-color: #fafafa;
        }
        .remove-field {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            color: #ef4444;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .remove-field:hover {
            transform: scale(1.1);
        }

        .topic-item-row {
            transition: background-color 0.2s ease, transform 0.2s ease;
            cursor: pointer;
        }
        .topic-item-row:hover {
            background-color: #f3f4f6;
        }

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

        .scrollable-topics {
            max-height: 130vh;
            overflow-y: auto;
        }
        
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

        .action-btn {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            transition: all 0.2s ease;
        }
        .action-btn:hover {
            transform: translateY(-1px);
        }

        @media (max-width: 768px) {
            .progress-grid {
                grid-template-columns: 1fr;
            }
        }

        .import-section {
            border-left: 4px solid #8b5cf6;
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
        
        .progress-checkbox {
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid #d1d5db;
        }
        
        .progress-checkbox.checked {
            background-color: #10b981;
            border-color: #10b981;
        }
        
        .progress-checkbox.checked::after {
            content: '✓';
            color: white;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
        }
        
        .topic-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .topic-type-theory {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }
        
        .topic-type-practical {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .topic-type-both {
            background-color: #f3e8ff;
            color: #5b21b6;
            border: 1px solid #c4b5fd;
        }
        
        .inline-edit-form {
            display: inline-flex;
            gap: 0.5rem;
            align-items: center;
            margin-left: 1rem;
        }
        
        .sub-topic-main-topic-select {
            min-width: 300px;
            max-width: 100%;
            position: relative;
        }
        
        .sub-topic-main-topic-select select {
            width: 100%;
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            padding: 0.5rem 2.5rem 0.5rem 0.75rem;
            font-size: 0.875rem;
            appearance: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .sub-topic-main-topic-select select:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .sub-topic-main-topic-select::after {
            content: '▾';
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            pointer-events: none;
        }
        
        .main-topic-info-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            background-color: #f1f5f9;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .csv-info-box {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .csv-info-box h5 {
            color: #0369a1;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .csv-info-box code {
            background-color: #e2e8f0;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-family: monospace;
            font-size: 0.875rem;
        }
        
        .csv-info-box ul {
            list-style-type: disc;
            padding-left: 1.5rem;
            margin-top: 0.5rem;
        }
        
        .csv-info-box li {
            margin-bottom: 0.25rem;
            color: #4b5563;
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
                        
                        <!-- CSV Format Information -->
                        <div class="csv-info-box hidden" id="subTopicInfoBox">
                            <h5><i class="fas fa-info-circle"></i> CSV Format for Sub Topics</h5>
                            <p class="text-sm text-gray-600 mb-2">Upload a CSV file with the following columns:</p>
                            <code>chapter,sub_topic_name,theory_completed,practical_completed</code>
                            <ul class="mt-3">
                                <li><strong>chapter</strong>: Chapter number (must exist in main topics)</li>
                                <li><strong>sub_topic_name</strong>: Name of the sub topic</li>
                                <li><strong>theory_completed</strong>: "YES"/"NO", "1"/"0", "TRUE"/"FALSE", or "COMPLETED"/"NOT COMPLETED"</li>
                                <li><strong>practical_completed</strong>: "YES"/"NO", "1"/"0", "TRUE"/"FALSE", or "COMPLETED"/"NOT COMPLETED"</li>
                            </ul>
                            <p class="text-sm text-gray-600 mt-3"><i class="fas fa-exclamation-triangle text-yellow-500"></i> Note: Main topics must be added first before importing sub topics.</p>
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
                            <div class="flex flex-col space-y-2">
                                <div>
                                    <a href="#" class="download-template" onclick="downloadTemplate('main_topics')">
                                        <i class="fas fa-download mr-2"></i> Main Topics Template
                                    </a>
                                    <span class="text-xs text-gray-500 ml-2">Format: chapter,topic_name,topic_type</span>
                                </div>
                                <div>
                                    <a href="#" class="download-template" onclick="downloadTemplate('sub_topics')">
                                        <i class="fas fa-download mr-2"></i> Sub Topics Template
                                    </a>
                                    <span class="text-xs text-gray-500 ml-2">Format: chapter,sub_topic_name,theory_completed,practical_completed</span>
                                </div>
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
                                <div class="mb-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Topic Name</label>
                                    <input type="text" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150" name="topic_name[]" required>
                                </div>
                                <div class="mb-1">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Topic Type</label>
                                    <select class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150" name="topic_type[]">
                                        <option value="both">Theory & Practical</option>
                                        <option value="theory">Theory Only</option>
                                        <option value="practical">Practical Only</option>
                                    </select>
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
                        <div id="subTopicsContainer">
                            <div class="dynamic-field-group">
                                <span class="remove-field" onclick="removeField(this)"><i class="fas fa-times-circle"></i></span>
                                <div class="sub-topic-group-header">
                                    <span class="sub-topic-group-title">Sub Topic #1</span>
                                    <span class="main-topic-info-badge">
                                        <i class="fas fa-info-circle mr-1"></i> Select a chapter
                                    </span>
                                </div>
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Chapter</label>
                                    <div class="sub-topic-main-topic-select">
                                        <select class="w-full p-2 border border-gray-300 rounded-md focus:ring-emerald-500 focus:border-emerald-500 transition duration-150 main-topic-select" name="main_topic_id[]" required onchange="updateTopicTypeInfo(this)">
                                            <option value="">Select Chapter</option>
                                            <?php foreach ($main_topics as $topic): ?>
                                                <option value="<?php echo $topic['id']; ?>" data-topic-type="<?php echo $topic['topic_type']; ?>">
                                                    Ch. <?php echo $topic['chapter']; ?>: <?php echo htmlspecialchars($topic['topic_name']); ?> 
                                                    (<?php echo $topic['topic_type']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Sub Topic Name</label>
                                    <input type="text" class="w-full p-2 border border-gray-300 rounded-md focus:ring-emerald-500 focus:border-emerald-500 transition duration-150" name="sub_topic_name[]" required>
                                </div>
                                <div class="flex space-x-4" id="progress-checkboxes-0">
                                    <div class="flex items-center">
                                        <input class="w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500 theory-checkbox" type="checkbox" name="theory_completed[]" value="1" id="theory_0">
                                        <label class="ml-2 text-sm font-medium text-gray-700" for="theory_0">Theory Completed</label>
                                    </div>
                                    <div class="flex items-center">
                                        <input class="w-4 h-4 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500 practical-checkbox" type="checkbox" name="practical_completed[]" value="1" id="practical_0">
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
                                            <div class="flex items-center">
                                                <i class="fas fa-sitemap mr-3 text-blue-500 text-lg"></i>
                                                <div>
                                                    <h6 class="text-lg font-bold text-gray-800">
                                                        Ch. <?php echo $main_topic['chapter']; ?>: <?php echo htmlspecialchars($main_topic['topic_name']); ?>
                                                        <span class="ml-2 topic-type-badge topic-type-<?php echo $main_topic['topic_type']; ?>">
                                                            <?php echo ucfirst($main_topic['topic_type']); ?>
                                                        </span>
                                                    </h6>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <?php
                                                        $total_sub = count($main_topic['sub_topics']);
                                                        $completed_theory = 0;
                                                        $completed_practical = 0;
                                                        foreach ($main_topic['sub_topics'] as $sub) {
                                                            if ($sub['theory_completed']) $completed_theory++;
                                                            if ($sub['practical_completed']) $completed_practical++;
                                                        }
                                                        ?>
                                                        Progress: Theory <?php echo $completed_theory; ?>/<?php echo $total_sub; ?>, 
                                                        Practical <?php echo $completed_practical; ?>/<?php echo $total_sub; ?>
                                                    </div>
                                                    <?php
                                                    // Get chapter-level verification stats for this specific chapter
                                                    $ch_stmt = $db->prepare("
                                                        SELECT COALESCE(SUM(CASE WHEN tv.status='verified' THEN 1 ELSE 0 END), 0) as verified_count,
                                                               COALESCE(SUM(CASE WHEN tv.status='rejected' THEN 1 ELSE 0 END), 0) as rejected_count,
                                                               COALESCE(SUM(CASE WHEN tv.status='pending' THEN 1 ELSE 0 END), 0) as pending_count,
                                                               COUNT(tv.id) as total_verif
                                                        FROM topic_verifications tv
                                                        WHERE tv.main_topic_id = ? AND tv.batch_id = ?
                                                    ");
                                                    $ch_stmt->execute([$main_topic['id'], $batch_id]);
                                                    $cv = $ch_stmt->fetch(PDO::FETCH_ASSOC);
                                                    ?>
                                                    <?php if ($cv && $cv['total_verif'] > 0 && isset($main_topic['covered_by_trainer']) && $main_topic['covered_by_trainer'] == 1): ?>
                                                    <div class="mt-2 flex gap-1 flex-wrap">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded bg-green-100 text-green-700 text-[10px] font-bold">
                                                            <i class="fas fa-check mr-1"></i><?php echo $cv['verified_count']; ?> Verified
                                                        </span>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded bg-red-100 text-red-700 text-[10px] font-bold">
                                                            <i class="fas fa-times mr-1"></i><?php echo $cv['rejected_count']; ?> Rejected
                                                        </span>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded bg-yellow-100 text-yellow-700 text-[10px] font-bold">
                                                            <i class="fas fa-clock mr-1"></i><?php echo $cv['pending_count']; ?> Pending
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flex space-x-2">
                                                <form method="POST" class="inline-edit-form" id="quickProgressForm-<?php echo $main_topic['id']; ?>">
                                                    <input type="hidden" name="main_topic_id" value="<?php echo $main_topic['id']; ?>">
                                                    <div class="flex items-center space-x-2">
                                                        <?php if ($main_topic['topic_type'] !== 'practical'): ?>
                                                        <div class="flex items-center">
                                                            <span class="text-xs mr-1 text-gray-600">Theory</span>
                                                            <div class="progress-checkbox <?php echo ($completed_theory == $total_sub && $total_sub > 0) ? 'checked' : ''; ?>" 
                                                                 onclick="toggleMainTopicProgress(<?php echo $main_topic['id']; ?>, 'theory', this)"></div>
                                                        </div>
                                                        <?php endif; ?>
                                                        <?php if ($main_topic['topic_type'] !== 'theory'): ?>
                                                        <div class="flex items-center">
                                                            <span class="text-xs mr-1 text-gray-600">Practical</span>
                                                            <div class="progress-checkbox <?php echo ($completed_practical == $total_sub && $total_sub > 0) ? 'checked' : ''; ?>" 
                                                                 onclick="toggleMainTopicProgress(<?php echo $main_topic['id']; ?>, 'practical', this)"></div>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </form>
                                                <button type="button" 
                                                        class="action-btn bg-blue-500 text-white hover:bg-blue-600"
                                                        onclick="openEditMainTopicModal(<?php echo $main_topic['id']; ?>, <?php echo $main_topic['chapter']; ?>, '<?php echo htmlspecialchars($main_topic['topic_name']); ?>', '<?php echo $main_topic['topic_type']; ?>')">
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
                                                            onclick="document.getElementById('chapterSelect').value = 'all'; document.getElementById('chapterSelect').dispatchEvent(new Event('change')); scrollToSubTopicForm();">
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
                                                            <div class="mt-1 flex items-center space-x-2">
                                                                <?php if ($main_topic['topic_type'] !== 'practical'): ?>
                                                                <!-- Theory Status -->
                                                                <div class="flex items-center">
                                                                    <div class="progress-checkbox <?php echo $sub_topic['theory_completed'] ? 'checked' : ''; ?>" 
                                                                         onclick="toggleSubTopicProgress(<?php echo $sub_topic['id']; ?>, 'theory', this)"></div>
                                                                    <span class="ml-1 text-xs <?php echo $sub_topic['theory_completed'] ? 'text-emerald-600' : 'text-gray-500'; ?>">
                                                                        Theory
                                                                    </span>
                                                                </div>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($main_topic['topic_type'] !== 'theory'): ?>
                                                                <!-- Practical Status -->
                                                                <div class="flex items-center">
                                                                    <div class="progress-checkbox <?php echo $sub_topic['practical_completed'] ? 'checked' : ''; ?>" 
                                                                         onclick="toggleSubTopicProgress(<?php echo $sub_topic['id']; ?>, 'practical', this)"></div>
                                                                    <span class="ml-1 text-xs <?php echo $sub_topic['practical_completed'] ? 'text-cyan-600' : 'text-gray-500'; ?>">
                                                                        Practical
                                                                    </span>
                                                                </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if (!empty($sub_topic['completed_at'])): ?>
                                                            <div class="mt-2 text-[11px] text-gray-400 flex items-center">
                                                                <i class="far fa-clock mr-1"></i> Updated on <?php echo date('d M Y, h:i A', strtotime($sub_topic['completed_at'])); ?>
                                                            </div>
                                                            <?php endif; ?>
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
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Topic Type</label>
                            <select id="edit_topic_type" name="topic_type" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <option value="both">Theory & Practical</option>
                                <option value="theory">Theory Only</option>
                                <option value="practical">Practical Only</option>
                            </select>
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
        // Function to update checkboxes based on main topic type
        function updateTopicTypeInfo(selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const topicType = selectedOption.getAttribute('data-topic-type');
            const fieldGroup = selectElement.closest('.dynamic-field-group');
            
            // Get checkboxes in this field group
            const theoryCheckbox = fieldGroup.querySelector('.theory-checkbox');
            const practicalCheckbox = fieldGroup.querySelector('.practical-checkbox');
            
            // Update the info badge
            const infoBadge = fieldGroup.querySelector('.main-topic-info-badge');
            if (infoBadge && selectedOption.value) {
                const chapter = selectedOption.text.split(':')[0];
                infoBadge.innerHTML = `<i class="fas fa-info-circle mr-1"></i> ${chapter} - ${topicType}`;
            }
            
            // Enable/disable checkboxes based on topic type
            if (topicType === 'theory') {
                if (theoryCheckbox) {
                    theoryCheckbox.disabled = false;
                    theoryCheckbox.parentElement.style.opacity = '1';
                }
                if (practicalCheckbox) {
                    practicalCheckbox.disabled = true;
                    practicalCheckbox.checked = false;
                    practicalCheckbox.parentElement.style.opacity = '0.5';
                }
            } else if (topicType === 'practical') {
                if (theoryCheckbox) {
                    theoryCheckbox.disabled = true;
                    theoryCheckbox.checked = false;
                    theoryCheckbox.parentElement.style.opacity = '0.5';
                }
                if (practicalCheckbox) {
                    practicalCheckbox.disabled = false;
                    practicalCheckbox.parentElement.style.opacity = '1';
                }
            } else { // 'both'
                if (theoryCheckbox) {
                    theoryCheckbox.disabled = false;
                    theoryCheckbox.parentElement.style.opacity = '1';
                }
                if (practicalCheckbox) {
                    practicalCheckbox.disabled = false;
                    practicalCheckbox.parentElement.style.opacity = '1';
                }
            }
        }

        // Scroll to sub-topic form function
        function scrollToSubTopicForm() {
            const subTopicForm = document.getElementById('subTopicsForm');
            if (subTopicForm) {
                subTopicForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // AJAX function to toggle sub-topic progress
        function toggleSubTopicProgress(subTopicId, progressType, checkboxElement) {
            const isChecked = checkboxElement.classList.contains('checked');
            
            // Determine new values based on topic type
            let theoryCompleted = false;
            let practicalCompleted = false;
            
            if (progressType === 'theory') {
                theoryCompleted = !isChecked;
                practicalCompleted = checkboxElement.closest('.topic-item-row').querySelector('.progress-checkbox[onclick*="practical"]')?.classList.contains('checked') || false;
            } else if (progressType === 'practical') {
                practicalCompleted = !isChecked;
                theoryCompleted = checkboxElement.closest('.topic-item-row').querySelector('.progress-checkbox[onclick*="theory"]')?.classList.contains('checked') || false;
            }
            
            // Send AJAX request
            const formData = new FormData();
            formData.append('sub_topic_id', subTopicId);
            formData.append('theory_completed', theoryCompleted ? '1' : '0');
            formData.append('practical_completed', practicalCompleted ? '1' : '0');
            formData.append('update_progress', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                // Toggle checkbox visually
                checkboxElement.classList.toggle('checked');
                
                // Update the data attributes
                const row = checkboxElement.closest('.topic-item-row');
                if (row) {
                    if (progressType === 'theory') {
                        row.setAttribute('data-theory-completed', theoryCompleted ? '1' : '0');
                    } else {
                        row.setAttribute('data-practical-completed', practicalCompleted ? '1' : '0');
                    }
                    
                    // Update row background if both are completed
                    const theoryValue = row.getAttribute('data-theory-completed') === '1';
                    const practicalValue = row.getAttribute('data-practical-completed') === '1';
                    if (theoryValue && practicalValue) {
                        row.classList.add('bg-green-50');
                    } else {
                        row.classList.remove('bg-green-50');
                    }
                }
                
                // Reload page to update progress statistics
                setTimeout(() => {
                    location.reload();
                }, 500);
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to update progress. Please try again.');
            });
        }
        
        // AJAX function to toggle all sub-topics in a main topic
        function toggleMainTopicProgress(mainTopicId, progressType, checkboxElement) {
            const mainTopicRow = checkboxElement.closest('.chapter-container');
            const subTopicRows = mainTopicRow.querySelectorAll('.topic-item-row');
            const isChecked = checkboxElement.classList.contains('checked');
            
            // Collect all sub-topic IDs
            const subTopicIds = [];
            subTopicRows.forEach(row => {
                subTopicIds.push(row.getAttribute('data-sub-topic-id'));
            });
            
            if (subTopicIds.length === 0) {
                alert('No sub-topics to update');
                return;
            }
            
            // Determine new values based on main topic type
            let theoryCompleted = false;
            let practicalCompleted = false;
            
            if (progressType === 'theory') {
                theoryCompleted = !isChecked;
                const practicalCheckbox = checkboxElement.closest('form').querySelector('.progress-checkbox[onclick*="practical"]');
                practicalCompleted = practicalCheckbox?.classList.contains('checked') || false;
            } else if (progressType === 'practical') {
                practicalCompleted = !isChecked;
                const theoryCheckbox = checkboxElement.closest('form').querySelector('.progress-checkbox[onclick*="theory"]');
                theoryCompleted = theoryCheckbox?.classList.contains('checked') || false;
            }
            
            // Send AJAX request for each sub-topic
            let completedRequests = 0;
            subTopicIds.forEach(subTopicId => {
                const formData = new FormData();
                formData.append('sub_topic_id', subTopicId);
                formData.append('theory_completed', theoryCompleted ? '1' : '0');
                formData.append('practical_completed', practicalCompleted ? '1' : '0');
                formData.append('update_progress', '1');
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    completedRequests++;
                    
                    // Update visual checkboxes for each sub-topic row
                    const row = mainTopicRow.querySelector(`[data-sub-topic-id="${subTopicId}"]`);
                    if (row) {
                        if (progressType === 'theory') {
                            const theoryCheckbox = row.querySelector('.progress-checkbox[onclick*="theory"]');
                            if (theoryCheckbox) {
                                theoryCheckbox.classList.toggle('checked', theoryCompleted);
                            }
                            row.setAttribute('data-theory-completed', theoryCompleted ? '1' : '0');
                        } else {
                            const practicalCheckbox = row.querySelector('.progress-checkbox[onclick*="practical"]');
                            if (practicalCheckbox) {
                                practicalCheckbox.classList.toggle('checked', practicalCompleted);
                            }
                            row.setAttribute('data-practical-completed', practicalCompleted ? '1' : '0');
                        }
                        
                        // Update row background if both are completed
                        const theoryValue = row.getAttribute('data-theory-completed') === '1';
                        const practicalValue = row.getAttribute('data-practical-completed') === '1';
                        if (theoryValue && practicalValue) {
                            row.classList.add('bg-green-50');
                        } else {
                            row.classList.remove('bg-green-50');
                        }
                    }
                    
                    // When all requests are complete, reload the page
                    if (completedRequests === subTopicIds.length) {
                        checkboxElement.classList.toggle('checked');
                        setTimeout(() => {
                            location.reload();
                        }, 500);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    completedRequests++;
                });
            });
        }
        
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
                    if (topicRow && !e.target.closest('button') && !e.target.classList.contains('progress-checkbox')) {
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
            const subTopicInfoBox = document.getElementById('subTopicInfoBox');
            
            if (importType) {
                importType.addEventListener('change', function() {
                    if (this.value === 'sub_topics') {
                        subTopicInfoBox.classList.remove('hidden');
                    } else {
                        subTopicInfoBox.classList.add('hidden');
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
                csvContent = 'chapter,topic_name,topic_type\n1,Introduction to Programming,both\n2,Data Types and Variables,theory\n3,Practical Lab Setup,practical';
                filename = 'main_topics_template.csv';
            } else if (type === 'sub_topics') {
                csvContent = 'chapter,sub_topic_name,theory_completed,practical_completed\n1,Variables and Data Types,Yes,No\n1,Operators and Expressions,No,Yes\n2,Conditional Statements,Yes,Yes\n2,Loops and Iterations,No,No';
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
        function openEditMainTopicModal(id, chapter, topicName, topicType) {
            document.getElementById('edit_main_topic_id').value = id;
            document.getElementById('edit_chapter').value = chapter;
            document.getElementById('edit_topic_name').value = topicName;
            document.getElementById('edit_topic_type').value = topicType || 'both';
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

        // Dynamic Field Handling
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
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Topic Name</label>
                    <input type="text" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150" name="topic_name[]" required>
                </div>
                <div class="mb-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Topic Type</label>
                    <select class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition duration-150" name="topic_type[]">
                        <option value="both">Theory & Practical</option>
                        <option value="theory">Theory Only</option>
                        <option value="practical">Practical Only</option>
                    </select>
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
                <div class="sub-topic-group-header">
                    <span class="sub-topic-group-title">Sub Topic #${uniqueId}</span>
                    <span class="main-topic-info-badge">
                        <i class="fas fa-info-circle mr-1"></i> Select a chapter
                    </span>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Chapter</label>
                    <div class="sub-topic-main-topic-select">
                        <select class="w-full p-2 border border-gray-300 rounded-md focus:ring-emerald-500 focus:border-emerald-500 transition duration-150 main-topic-select" name="main_topic_id[]" required onchange="updateTopicTypeInfo(this)">
                            <option value="">Select Chapter</option>
                            <?php foreach ($main_topics as $topic): ?>
                                <option value="<?php echo $topic['id']; ?>" data-topic-type="<?php echo $topic['topic_type']; ?>">
                                    Ch. <?php echo $topic['chapter']; ?>: <?php echo htmlspecialchars($topic['topic_name']); ?> 
                                    (<?php echo $topic['topic_type']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sub Topic Name</label>
                    <input type="text" class="w-full p-2 border border-gray-300 rounded-md focus:ring-emerald-500 focus:border-emerald-500 transition duration-150" name="sub_topic_name[]" required>
                </div>
                <div class="flex space-x-4" id="progress-checkboxes-${uniqueId - 1}">
                    <div class="flex items-center">
                        <input class="w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500 theory-checkbox" type="checkbox" name="theory_completed[]" value="1" id="theory_${uniqueId - 1}">
                        <label class="ml-2 text-sm font-medium text-gray-700" for="theory_${uniqueId - 1}">Theory Completed</label>
                    </div>
                    <div class="flex items-center">
                        <input class="w-4 h-4 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500 practical-checkbox" type="checkbox" name="practical_completed[]" value="1" id="practical_${uniqueId - 1}">
                        <label class="ml-2 text-sm font-medium text-gray-700" for="practical_${uniqueId - 1}">Practical Completed</label>
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