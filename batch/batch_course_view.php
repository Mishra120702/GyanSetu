<?php
session_start();
require_once '../db_connection.php';
require_once 'sync_curriculum.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$course_id = $_GET['course_id'] ?? '';
$batch_id = $_GET['batch_id'] ?? '';

if (!$course_id || !$batch_id) {
    die("Course ID and Batch ID are required");
}

// Fetch course details
$course_stmt = $db->prepare("SELECT * FROM courses WHERE id = ?");
$course_stmt->execute([$course_id]);
$course = $course_stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Course not found");
}

// Fetch batch details
$batch_stmt = $db->prepare("SELECT * FROM batches WHERE batch_id = ?");
$batch_stmt->execute([$batch_id]);
$batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// Handle Sample CSV Download
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_curriculum.csv"');
    $output = fopen('php://output', 'w');
    // Add BOM for UTF-8 Excel compatibility
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Chapter Number', 'Topic Name', 'Topic Type', 'Sub Topic Name']);
    fputcsv($output, ['1', 'Introduction to Course', 'both', 'What is this course about?']);
    fputcsv($output, ['1', 'Introduction to Course', 'both', 'Prerequisites']);
    fputcsv($output, ['2', 'Core Concepts', 'theory', '']);
    fputcsv($output, ['2', 'Core Concepts', 'practical', 'Hands-on exercise']);
    fclose($output);
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_main_topics'])) {
        $chapters = $_POST['chapter'] ?? [];
        $topic_names = $_POST['topic_name'] ?? [];
        $topic_types = $_POST['topic_type'] ?? [];
        
        $added_count = 0;
        for ($i = 0; $i < count($chapters); $i++) {
            if (!empty($chapters[$i]) && !empty($topic_names[$i])) {
                $topic_type = $topic_types[$i] ?? 'both';
                $stmt = $db->prepare("INSERT INTO course_main_topics (course_id, chapter, topic_name, topic_type, batch_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$course_id, $chapters[$i], $topic_names[$i], $topic_type, $batch_id]);
                $added_count++;
            }
        }
        
        $_SESSION['success'] = "$added_count main topic(s) added successfully!";
    } 
    elseif (isset($_POST['add_sub_topics'])) {
        $main_topic_ids = $_POST['main_topic_id'] ?? [];
        $sub_topic_names = $_POST['sub_topic_name'] ?? [];
        
        $added_count = 0;
        
        for ($i = 0; $i < count($sub_topic_names); $i++) {
            if (!empty($sub_topic_names[$i]) && !empty($main_topic_ids[$i])) {
                $main_topic_id = $main_topic_ids[$i];
                $sub_topic_name = $sub_topic_names[$i];
                
                $stmt = $db->prepare("INSERT INTO course_sub_topics (course_main_topic_id, sub_topic_name, batch_id) VALUES (?, ?, ?)");
                $stmt->execute([$main_topic_id, $sub_topic_name, $batch_id]);
                $added_count++;
            }
        }
        
        $_SESSION['success'] = "$added_count sub topic(s) added successfully!";
    }
    elseif (isset($_POST['delete_main_topic'])) {
        $main_topic_id = $_POST['main_topic_id'];
        // Check if it's a global topic or batch-specific
        $check = $db->prepare("SELECT batch_id FROM course_main_topics WHERE id = ?");
        $check->execute([$main_topic_id]);
        $topic = $check->fetch();
        if ($topic) {
            if ($topic['batch_id'] === $batch_id) {
                $stmt = $db->prepare("DELETE FROM course_main_topics WHERE id = ?");
                $stmt->execute([$main_topic_id]);
            } else {
                // Global topic, hide it for this batch
                $stmt = $db->prepare("UPDATE course_main_topics SET deleted_in_batches = CONCAT(IFNULL(deleted_in_batches, ''), ?) WHERE id = ?");
                $stmt->execute(["[$batch_id]", $main_topic_id]);
            }
        }
        $_SESSION['success'] = "Chapter removed for this batch successfully!";
    }
    elseif (isset($_POST['delete_sub_topic'])) {
        $sub_topic_id = $_POST['sub_topic_id'];
        $check = $db->prepare("SELECT batch_id FROM course_sub_topics WHERE id = ?");
        $check->execute([$sub_topic_id]);
        $topic = $check->fetch();
        if ($topic) {
            if ($topic['batch_id'] === $batch_id) {
                $stmt = $db->prepare("DELETE FROM course_sub_topics WHERE id = ?");
                $stmt->execute([$sub_topic_id]);
            } else {
                // Global sub-topic, hide it for this batch
                $stmt = $db->prepare("UPDATE course_sub_topics SET deleted_in_batches = CONCAT(IFNULL(deleted_in_batches, ''), ?) WHERE id = ?");
                $stmt->execute(["[$batch_id]", $sub_topic_id]);
            }
        }
        $_SESSION['success'] = "Sub topic removed for this batch successfully!";
    }
    elseif (isset($_POST['bulk_delete'])) {
        $main_topic_ids = $_POST['bulk_main_topics'] ?? [];
        $sub_topic_ids = $_POST['bulk_sub_topics'] ?? [];
        
        foreach ($main_topic_ids as $id) {
            $check = $db->prepare("SELECT batch_id FROM course_main_topics WHERE id = ?");
            $check->execute([$id]);
            $topic = $check->fetch();
            if ($topic) {
                if ($topic['batch_id'] === $batch_id) {
                    $stmt = $db->prepare("DELETE FROM course_main_topics WHERE id = ?");
                    $stmt->execute([$id]);
                } else {
                    $stmt = $db->prepare("UPDATE course_main_topics SET deleted_in_batches = CONCAT(IFNULL(deleted_in_batches, ''), ?) WHERE id = ?");
                    $stmt->execute(["[$batch_id]", $id]);
                }
            }
        }
        foreach ($sub_topic_ids as $id) {
            $check = $db->prepare("SELECT batch_id FROM course_sub_topics WHERE id = ?");
            $check->execute([$id]);
            $topic = $check->fetch();
            if ($topic) {
                if ($topic['batch_id'] === $batch_id) {
                    $stmt = $db->prepare("DELETE FROM course_sub_topics WHERE id = ?");
                    $stmt->execute([$id]);
                } else {
                    $stmt = $db->prepare("UPDATE course_sub_topics SET deleted_in_batches = CONCAT(IFNULL(deleted_in_batches, ''), ?) WHERE id = ?");
                    $stmt->execute(["[$batch_id]", $id]);
                }
            }
        }
        $_SESSION['success'] = "Selected topics removed successfully!";
    }
    elseif (isset($_POST['edit_main_topic'])) {
        $id = $_POST['topic_id'];
        $new_name = $_POST['new_topic_name'];
        $new_chapter = $_POST['new_chapter'];
        $new_topic_type = $_POST['new_topic_type'];
        $stmt = $db->prepare("UPDATE course_main_topics SET topic_name = ?, chapter = ?, topic_type = ? WHERE id = ?");
        $stmt->execute([$new_name, $new_chapter, $new_topic_type, $id]);
        $_SESSION['success'] = "Topic updated successfully!";
    }
    elseif (isset($_POST['edit_sub_topic'])) {
        $id = $_POST['sub_topic_id'];
        $new_name = $_POST['new_sub_topic_name'];
        $stmt = $db->prepare("UPDATE course_sub_topics SET sub_topic_name = ? WHERE id = ?");
        $stmt->execute([$new_name, $id]);
        $_SESSION['success'] = "Sub-topic updated successfully!";
    }
    elseif (isset($_POST['upload_csv']) && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file']['tmp_name'];
        if (is_uploaded_file($file)) {
            $handle = fopen($file, "r");
            $main_topics_added = 0;
            $sub_topics_added = 0;
            
            // Skip the first row if it's a header
            $first_row = fgetcsv($handle, 1000, ",");
            if ($first_row && stripos(implode(',', $first_row), 'chapter') === false) {
                rewind($handle);
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 2) continue; // Skip invalid rows
                
                $chapter = trim($data[0]);
                // Remove BOM if present
                $chapter = preg_replace('/^[\xef\xbb\xbf]+/', '', $chapter);
                
                $topic_name = trim($data[1]);
                $topic_type = isset($data[2]) ? trim(strtolower($data[2])) : 'both';
                if (!in_array($topic_type, ['both', 'theory', 'practical'])) {
                    $topic_type = 'both';
                }
                $sub_topic_name = isset($data[3]) ? trim($data[3]) : '';
                
                if (empty($chapter) || empty($topic_name)) continue;
                
                // Find or create Main Topic
                $stmt = $db->prepare("SELECT id, deleted_in_batches FROM course_main_topics WHERE course_id = ? AND chapter = ? AND topic_name = ? AND (batch_id = ? OR batch_id IS NULL)");
                $stmt->execute([$course_id, $chapter, $topic_name, $batch_id]);
                $existing_main = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existing_main) {
                    $insert_main = $db->prepare("INSERT INTO course_main_topics (course_id, chapter, topic_name, topic_type, batch_id) VALUES (?, ?, ?, ?, ?)");
                    $insert_main->execute([$course_id, $chapter, $topic_name, $topic_type, $batch_id]);
                    $main_topic_id = $db->lastInsertId();
                    $main_topics_added++;
                } else {
                    $main_topic_id = $existing_main['id'];
                    // If it exists globally but was deleted in this batch, restore it
                    if (!empty($existing_main['deleted_in_batches']) && strpos($existing_main['deleted_in_batches'], "[$batch_id]") !== false) {
                        $new_deleted = str_replace("[$batch_id]", "", $existing_main['deleted_in_batches']);
                        $unhide_stmt = $db->prepare("UPDATE course_main_topics SET deleted_in_batches = ? WHERE id = ?");
                        $unhide_stmt->execute([$new_deleted === '' ? null : $new_deleted, $main_topic_id]);
                        $main_topics_added++;
                    }
                }
                
                // Find or create Sub Topic
                if (!empty($sub_topic_name)) {
                    $check_sub = $db->prepare("SELECT id, deleted_in_batches FROM course_sub_topics WHERE course_main_topic_id = ? AND sub_topic_name = ? AND (batch_id = ? OR batch_id IS NULL)");
                    $check_sub->execute([$main_topic_id, $sub_topic_name, $batch_id]);
                    $existing_sub = $check_sub->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$existing_sub) {
                        $insert_sub = $db->prepare("INSERT INTO course_sub_topics (course_main_topic_id, sub_topic_name, batch_id) VALUES (?, ?, ?)");
                        $insert_sub->execute([$main_topic_id, $sub_topic_name, $batch_id]);
                        $sub_topics_added++;
                    } else {
                        if (!empty($existing_sub['deleted_in_batches']) && strpos($existing_sub['deleted_in_batches'], "[$batch_id]") !== false) {
                            $new_deleted = str_replace("[$batch_id]", "", $existing_sub['deleted_in_batches']);
                            $unhide_stmt = $db->prepare("UPDATE course_sub_topics SET deleted_in_batches = ? WHERE id = ?");
                            $unhide_stmt->execute([$new_deleted === '' ? null : $new_deleted, $existing_sub['id']]);
                            $sub_topics_added++;
                        }
                    }
                }
            }
            fclose($handle);
            $_SESSION['success'] = "CSV Uploaded! Added $main_topics_added new chapters and $sub_topics_added new sub-topics.";
            sync_course_to_all_batches($db, $course_id);
        } else {
            $_SESSION['error'] = "Failed to upload CSV file.";
        }
    }
    
    elseif (isset($_POST['action']) && $_POST['action'] === 'assign_visibility') {
        $course_id_post = $_POST['course_id'];
        $batch_ids = $_POST['batch_ids'] ?? [];

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("DELETE FROM course_content_visibility WHERE course_id = ?");
            $stmt->execute([$course_id_post]);

            if (!empty($batch_ids)) {
                $insert_stmt = $db->prepare("INSERT INTO course_content_visibility (course_id, batch_id) VALUES (?, ?)");
                foreach ($batch_ids as $bid) {
                    $insert_stmt->execute([$course_id_post, $bid]);
                }
            }

            $stmt_del = $db->prepare("DELETE FROM batch_uploads WHERE course_id = ?");
            $stmt_del->execute([$course_id_post]);

            if (!empty($batch_ids)) {
                $sync_stmt = $db->prepare("
                    INSERT INTO batch_uploads (upload_id, batch_id, course_id)
                    SELECT u.id, ccv.batch_id, u.course_id
                    FROM uploads u
                    JOIN course_content_visibility ccv ON u.course_id = ccv.course_id
                    WHERE u.course_id = ?
                ");
                $sync_stmt->execute([$course_id_post]);
            }

            $db->commit();
            $_SESSION['success'] = "Content visibility updated successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Error updating visibility: " . $e->getMessage();
        }
        
        header("Location: batch_course_view.php?batch_id=" . urlencode($batch_id) . "&course_id=" . urlencode($course_id));
        exit();
    }
    elseif (isset($_POST['action']) && $_POST['action'] === 'assign_trainer') {
        $course_id_post = $_POST['course_id'];
        $batch_id_post = $_POST['batch_id'];
        $trainer_id = !empty($_POST['trainer_id']) ? $_POST['trainer_id'] : null;

        try {
            $stmt = $db->prepare("UPDATE batch_courses SET trainer_id = ? WHERE batch_id = ? AND course_id = ?");
            $stmt->execute([$trainer_id, $batch_id_post, $course_id_post]);
            
            // Send notification to trainer
            if ($trainer_id) {
                // Get course name and batch name for notification
                $c_stmt = $db->prepare("SELECT name FROM courses WHERE id = ?");
                $c_stmt->execute([$course_id_post]);
                $c_name = $c_stmt->fetchColumn();
                
                $b_stmt = $db->prepare("SELECT batch_name FROM batches WHERE batch_id = ?");
                $b_stmt->execute([$batch_id_post]);
                $b_name = $b_stmt->fetchColumn();
                
                $title = "New Course Assigned";
                $message = "You have been assigned to teach '" . $c_name . "' in batch '" . $b_name . "'.";
                
                // Fetch the actual user_id for this trainer
                $u_stmt = $db->prepare("SELECT user_id FROM trainers WHERE id = ?");
                $u_stmt->execute([$trainer_id]);
                $real_user_id = $u_stmt->fetchColumn();
                
                if ($real_user_id) {
                    $n_stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) VALUES (?, 'course_assignment', ?, ?, ?, 0)");
                    $n_stmt->execute([$real_user_id, $title, $message, $course_id_post]);
                }
            }
            $_SESSION['success'] = "Trainer assigned successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error assigning trainer: " . $e->getMessage();
        }
        header("Location: batch_course_view.php?batch_id=" . urlencode($batch_id_post) . "&course_id=" . urlencode($course_id_post));
        exit();
    }
    
    // Auto sync to all assigned batches
    sync_course_to_all_batches($db, $course_id);
    
    header("Location: batch_course_view.php?batch_id=" . urlencode($batch_id) . "&course_id=" . urlencode($course_id));
    exit();
}

// Fetch main topics for this course
$main_topics_stmt = $db->prepare("
    SELECT * FROM course_main_topics 
    WHERE course_id = ? 
      AND (batch_id IS NULL OR batch_id = ?) 
      AND (deleted_in_batches IS NULL OR deleted_in_batches NOT LIKE ?) 
    ORDER BY chapter
");
$main_topics_stmt->execute([$course_id, $batch_id, "%[$batch_id]%"]);
$main_topics = $main_topics_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique chapters for dropdown
$chapters = [];
foreach ($main_topics as $topic) {
    $chapters[$topic['chapter']] = $topic['chapter'];
}
sort($chapters);

$main_topics_with_sub_topics = [];
foreach ($main_topics as $main_topic) {
    $sub_topics_stmt = $db->prepare("
        SELECT * FROM course_sub_topics 
        WHERE course_main_topic_id = ? 
          AND (batch_id IS NULL OR batch_id = ?)
          AND (deleted_in_batches IS NULL OR deleted_in_batches NOT LIKE ?)
    ");
    $sub_topics_stmt->execute([$main_topic['id'], $batch_id, "%[$batch_id]%"]);
    $main_topic['sub_topics'] = $sub_topics_stmt->fetchAll(PDO::FETCH_ASSOC);
    $main_topics_with_sub_topics[] = $main_topic;
}

// Fetch batches and visibility for the modal
$batches_query = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name ASC");
$all_batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);

$content_visibility_query = $db->prepare("SELECT batch_id FROM course_content_visibility WHERE course_id = ?");
$content_visibility_query->execute([$course_id]);
$visible_batches_raw = $content_visibility_query->fetchAll(PDO::FETCH_COLUMN);

// Fetch trainers
$trainers_query = $db->query("SELECT id, name FROM trainers WHERE is_active = 1 ORDER BY name ASC");
$all_trainers = $trainers_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch currently assigned trainer for this course in this batch
$assigned_trainer_query = $db->prepare("SELECT trainer_id FROM batch_courses WHERE batch_id = ? AND course_id = ?");
$assigned_trainer_query->execute([$batch_id, $course_id]);
$assigned_trainer = $assigned_trainer_query->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($course['name']) ?> - <?= htmlspecialchars($batch['batch_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .fancy-card { background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .dynamic-field-group { position: relative; padding: 1rem; border: 2px dashed #d1d5db; border-radius: 8px; margin-bottom: 1rem; background-color: #fafafa; }
        .remove-field { position: absolute; top: 0.5rem; right: 0.5rem; color: #ef4444; cursor: pointer; }
        .topic-type-theory { background-color: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .topic-type-practical { background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .topic-type-both { background-color: #f3e8ff; color: #5b21b6; border: 1px solid #c4b5fd; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .icon-wrapper { width: 40px; height: 40px; border-radius: 10px; background-color: rgba(67, 97, 238, 0.1); display: flex; align-items: center; justify-content: center; }
        .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .hover-lift:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <div class="icon-wrapper mr-2">
                    <i class="fas fa-book-open text-blue-500 text-lg"></i>
                </div>
                <span>Course Details: <?= htmlspecialchars($course['name']) ?></span>
            </h1>
            <div class="flex items-center space-x-4">
                <a href="batch_view.php?batch_id=<?= urlencode($batch_id) ?>" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 transition text-sm flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Batch
                </a>
            </div>
        </header>

        <div class="p-6">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md relative mb-6">
                    <span class="block sm:inline"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md relative mb-6">
                    <span class="block sm:inline"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                </div>
            <?php endif; ?>

            <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800 flex items-center">
                        <?= htmlspecialchars($course['name']) ?>
                    </h2>
                    <p class="text-gray-500 mt-1"><i class="fas fa-users-class mr-1"></i> <?= htmlspecialchars($batch['batch_name']) ?> (Course ID: <?= htmlspecialchars($course['id']) ?>)</p>
                </div>
                <div class="mt-4 md:mt-0">
                    <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-semibold">
                        <i class="fas fa-graduation-cap mr-1"></i> Active Course
                    </span>
                </div>
            </div>

            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Left Column: Curriculum (Larger) -->
                <div class="w-full lg:w-3/4 space-y-6">
                    
                    <!-- Curriculum Structure -->
                    <div class="fancy-card p-6 border border-gray-100">
                        <header class="mb-4 border-b pb-3 flex justify-between items-center">
                            <h5 class="text-xl font-semibold text-gray-700 flex items-center">
                                <i class="fas fa-book-reader mr-2 text-indigo-500"></i> Course Curriculum
                            </h5>
                        </header>

                        <!-- Import from Excel/CSV -->
                        <div class="mb-6 p-5 border border-purple-100 rounded-lg bg-purple-50/30">
                            <header class="mb-4 border-b pb-3 flex justify-between items-center">
                                <h5 class="font-semibold text-gray-700 flex items-center">
                                    <i class="fas fa-file-csv mr-2 text-purple-500"></i> Import from CSV
                                </h5>
                                <a href="?batch_id=<?php echo urlencode($batch_id); ?>&course_id=<?php echo urlencode($course_id); ?>&download_sample=1" class="text-xs px-3 py-1.5 bg-green-100 text-green-700 rounded-md hover:bg-green-200 transition font-semibold flex items-center shadow-sm" title="Download Sample CSV">
                                    <i class="fas fa-download mr-1"></i> Sample Format
                                </a>
                            </header>
                            <div class="mb-4 text-sm text-gray-600 bg-white p-3 rounded-lg border border-purple-100 shadow-sm">
                                <p class="font-semibold mb-2 text-purple-800">Required CSV Format (4 Columns):</p>
                                <p class="font-mono text-xs bg-gray-50 p-2 border border-gray-200 rounded text-gray-800 break-all mb-2">Chapter Number, Topic Name, Topic Type, Sub Topic Name</p>
                                <ul class="list-disc pl-5 space-y-1 text-xs text-gray-700">
                                    <li><span class="font-medium text-gray-900">Chapter Number:</span> Numeric e.g., 1, 2</li>
                                    <li><span class="font-medium text-gray-900">Topic Name:</span> e.g., "Introduction"</li>
                                    <li><span class="font-medium text-gray-900">Topic Type:</span> both, theory, or practical (Optional)</li>
                                    <li><span class="font-medium text-gray-900">Sub Topic Name:</span> e.g., "Variables" (Optional)</li>
                                </ul>
                            </div>
                            <form method="POST" enctype="multipart/form-data" id="importForm" class="flex flex-col md:flex-row gap-4 items-end">
                                <div class="flex-1">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Select .csv file</label>
                                    <input type="file" name="csv_file" accept=".csv" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-purple-100 file:text-purple-700 hover:file:bg-purple-200 cursor-pointer border border-gray-300 rounded-lg p-1.5 bg-white">
                                </div>
                                <div>
                                    <button type="submit" name="upload_csv" class="px-6 py-2.5 bg-purple-600 text-white font-semibold rounded-lg hover:bg-purple-700 transition duration-300 shadow-sm flex items-center h-[42px]">
                                        <i class="fas fa-cloud-upload-alt mr-2"></i> Import
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="space-y-4">
                            <form method="POST" id="bulkDeleteForm" onsubmit="return confirm('Are you sure you want to delete the selected items?');"></form>
                            
                            <?php if (!empty($main_topics_with_sub_topics)): ?>
                            <div class="flex justify-between items-center bg-gray-50 p-2 rounded-lg border border-gray-200">
                                <div class="flex items-center ml-2">
                                    <input type="checkbox" id="selectAllCheckbox" class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500 cursor-pointer" onchange="document.querySelectorAll('.bulk-checkbox').forEach(cb => cb.checked = this.checked); document.getElementById('bulkDeleteBtn').disabled = !this.checked;">
                                    <label for="selectAllCheckbox" class="ml-2 text-sm text-gray-700 cursor-pointer font-medium">Select All</label>
                                </div>
                                <button type="submit" form="bulkDeleteForm" name="bulk_delete" id="bulkDeleteBtn" disabled class="px-3 py-1.5 bg-red-500 text-white text-xs font-semibold rounded hover:bg-red-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                    <i class="fas fa-trash-alt mr-1"></i> Delete Selected
                                </button>
                            </div>
                            <script>
                                document.addEventListener('change', function(e) {
                                    if(e.target.classList.contains('bulk-checkbox')) {
                                        const anyChecked = document.querySelectorAll('.bulk-checkbox:checked').length > 0;
                                        document.getElementById('bulkDeleteBtn').disabled = !anyChecked;
                                    }
                                });
                                function toggleEditMain(id) {
                                    document.getElementById('display_main_' + id).classList.toggle('hidden');
                                    document.getElementById('edit_main_' + id).classList.toggle('hidden');
                                }
                                function toggleEditSub(id) {
                                    document.getElementById('display_sub_' + id).classList.toggle('hidden');
                                    document.getElementById('edit_sub_' + id).classList.toggle('hidden');
                                }
                            </script>
                            <?php endif; ?>
                            <?php if (empty($main_topics_with_sub_topics)): ?>
                                <div class="text-center py-10 bg-gray-50 rounded-lg border border-gray-200 border-dashed">
                                    <i class="fas fa-book-reader text-gray-300 text-5xl mb-3"></i>
                                    <p class="text-gray-500">No curriculum defined yet.</p>
                                    <p class="text-sm text-gray-400 mt-1">Start by adding chapters and main topics using the form below.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($main_topics_with_sub_topics as $topic): ?>
                                    <div class="border border-gray-200 rounded-lg overflow-hidden bg-white hover:border-blue-300 transition-colors">
                                        <div class="bg-gray-50 px-4 py-3 flex justify-between items-center border-b border-gray-200">
                                            <div class="flex items-center">
                                                <input type="checkbox" name="bulk_main_topics[]" value="<?php echo $topic['id']; ?>" form="bulkDeleteForm" class="mr-3 w-4 h-4 text-red-600 rounded border-gray-300 focus:ring-red-500 cursor-pointer bulk-checkbox">
                                                <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2 py-1 rounded mr-3">Ch <?php echo $topic['chapter']; ?></span>
                                                <div id="display_main_<?php echo $topic['id']; ?>" class="flex items-center">
                                                    <h6 class="font-semibold text-gray-800 m-0"><?php echo htmlspecialchars($topic['topic_name']); ?></h6>
                                                    <span class="ml-3 topic-type-<?php echo $topic['topic_type']; ?>"><?php echo ucfirst($topic['topic_type']); ?></span>
                                                    <button type="button" onclick="toggleEditMain(<?php echo $topic['id']; ?>)" class="ml-3 text-gray-400 hover:text-blue-500 transition-colors" title="Edit Topic Name"><i class="fas fa-edit"></i></button>
                                                </div>
                                                <div id="edit_main_<?php echo $topic['id']; ?>" class="hidden flex items-center ml-2">
                                                    <form method="POST" class="inline flex items-center">
                                                        <input type="hidden" name="topic_id" value="<?php echo $topic['id']; ?>">
                                                        <input type="number" name="new_chapter" value="<?php echo htmlspecialchars($topic['chapter']); ?>" class="px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:border-blue-500 w-16 mr-2" required min="1" placeholder="Ch">
                                                        <input type="text" name="new_topic_name" value="<?php echo htmlspecialchars($topic['topic_name']); ?>" class="px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:border-blue-500 mr-2" required>
                                                        <select name="new_topic_type" class="px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:border-blue-500 mr-2" required>
                                                            <option value="both" <?php echo $topic['topic_type'] === 'both' ? 'selected' : ''; ?>>Both</option>
                                                            <option value="theory" <?php echo $topic['topic_type'] === 'theory' ? 'selected' : ''; ?>>Theory</option>
                                                            <option value="practical" <?php echo $topic['topic_type'] === 'practical' ? 'selected' : ''; ?>>Practical</option>
                                                        </select>
                                                        <button type="submit" name="edit_main_topic" class="text-green-500 hover:text-green-700 bg-green-50 hover:bg-green-100 rounded px-2 py-1 transition-colors"><i class="fas fa-check"></i></button>
                                                        <button type="button" onclick="toggleEditMain(<?php echo $topic['id']; ?>)" class="ml-1 text-gray-400 hover:text-gray-600 bg-gray-100 hover:bg-gray-200 rounded px-2 py-1 transition-colors"><i class="fas fa-times"></i></button>
                                                    </form>
                                                </div>
                                            </div>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this chapter and all its sub-topics?');">
                                                <input type="hidden" name="main_topic_id" value="<?php echo $topic['id']; ?>">
                                                <button type="submit" name="delete_main_topic" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 rounded px-2 py-1 transition-colors">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>

                                        <div class="p-0">
                                            <?php if (empty($topic['sub_topics'])): ?>
                                                <div class="px-4 py-3 text-sm text-gray-500 italic text-center">No sub-topics added yet.</div>
                                            <?php else: ?>
                                                <ul class="divide-y divide-gray-100">
                                                    <?php foreach ($topic['sub_topics'] as $index => $sub_topic): ?>
                                                        <li class="px-4 py-3 flex justify-between items-center hover:bg-blue-50 transition-colors">
                                                            <div class="flex items-center text-sm text-gray-700 flex-1">
                                                                <input type="checkbox" name="bulk_sub_topics[]" value="<?php echo $sub_topic['id']; ?>" form="bulkDeleteForm" class="mr-3 w-4 h-4 text-red-600 rounded border-gray-300 focus:ring-red-500 cursor-pointer bulk-checkbox">
                                                                <span class="text-gray-400 w-6 font-mono text-xs"><?php echo $topic['chapter'] . '.' . ($index + 1); ?></span>
                                                                <div id="display_sub_<?php echo $sub_topic['id']; ?>" class="flex items-center">
                                                                    <span class="font-medium ml-2"><?php echo htmlspecialchars($sub_topic['sub_topic_name']); ?></span>
                                                                    <button type="button" onclick="toggleEditSub(<?php echo $sub_topic['id']; ?>)" class="ml-3 text-gray-400 hover:text-blue-500 transition-colors" title="Edit Sub-topic Name"><i class="fas fa-edit text-xs"></i></button>
                                                                </div>
                                                                <div id="edit_sub_<?php echo $sub_topic['id']; ?>" class="hidden flex items-center ml-2">
                                                                    <form method="POST" class="inline flex items-center">
                                                                        <input type="hidden" name="sub_topic_id" value="<?php echo $sub_topic['id']; ?>">
                                                                        <input type="text" name="new_sub_topic_name" value="<?php echo htmlspecialchars($sub_topic['sub_topic_name']); ?>" class="px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:border-blue-500" required>
                                                                        <button type="submit" name="edit_sub_topic" class="ml-2 text-green-500 hover:text-green-700 bg-green-50 hover:bg-green-100 rounded px-2 py-0.5 transition-colors"><i class="fas fa-check text-xs"></i></button>
                                                                        <button type="button" onclick="toggleEditSub(<?php echo $sub_topic['id']; ?>)" class="ml-1 text-gray-400 hover:text-gray-600 bg-gray-100 hover:bg-gray-200 rounded px-2 py-0.5 transition-colors"><i class="fas fa-times text-xs"></i></button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this sub-topic?');">
                                                                <input type="hidden" name="sub_topic_id" value="<?php echo $sub_topic['id']; ?>">
                                                                <button type="submit" name="delete_sub_topic" class="text-red-400 hover:text-red-600 px-2">
                                                                    <i class="fas fa-times"></i>
                                                                </button>
                                                            </form>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Add Main Topics Form -->
                        <div class="fancy-card p-6 border border-gray-100">
                            <header class="flex justify-between items-center mb-4 border-b pb-3">
                                <h5 class="text-lg font-semibold text-gray-700 flex items-center">
                                    <i class="fas fa-layer-group mr-2 text-blue-500"></i> Add Main Topics
                                </h5>
                                <button type="button" class="px-3 py-1 text-xs bg-blue-100 text-blue-700 rounded-full hover:bg-blue-200 transition-colors" id="addMainTopicField">
                                    <i class="fas fa-plus mr-1"></i> Add More
                                </button>
                            </header>
                            <form method="POST" id="mainTopicsForm">
                                <div id="mainTopicsContainer">
                                    <div class="dynamic-field-group">
                                        <span class="remove-field" onclick="removeField(this)"><i class="fas fa-times-circle"></i></span>
                                        <div class="mb-3">
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Chapter Number</label>
                                            <input type="number" class="w-full p-2 text-sm border border-gray-300 rounded-md" name="chapter[]" required min="1">
                                        </div>
                                        <div class="mb-3">
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Topic Name</label>
                                            <input type="text" class="w-full p-2 text-sm border border-gray-300 rounded-md" name="topic_name[]" required>
                                        </div>
                                        <div class="mb-1">
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Topic Type</label>
                                            <select class="w-full p-2 text-sm border border-gray-300 rounded-md" name="topic_type[]" required>
                                                <option value="both">Both (Theory & Practical)</option>
                                                <option value="theory">Theory Only</option>
                                                <option value="practical">Practical Only</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" name="add_main_topics" class="w-full mt-4 px-4 py-2 text-sm bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                    Save Main Topics
                                </button>
                            </form>
                        </div>

                        <!-- Add Sub Topics Form -->
                        <div class="fancy-card p-6 border border-gray-100">
                            <header class="flex justify-between items-center mb-4 border-b pb-3">
                                <h5 class="text-lg font-semibold text-gray-700 flex items-center">
                                    <i class="fas fa-list-ul mr-2 text-green-500"></i> Add Sub Topics
                                </h5>
                                <button type="button" class="px-3 py-1 text-xs bg-green-100 text-green-700 rounded-full hover:bg-green-200 transition-colors" id="addSubTopicField">
                                    <i class="fas fa-plus mr-1"></i> Add More
                                </button>
                            </header>
                            <form method="POST" id="subTopicsForm">
                                <?php if (empty($main_topics)): ?>
                                    <div class="p-3 bg-yellow-50 text-yellow-700 rounded-lg text-sm border border-yellow-200">
                                        <i class="fas fa-exclamation-triangle mr-1"></i> Please add main topics first.
                                    </div>
                                <?php else: ?>
                                    <div id="subTopicsContainer">
                                        <div class="dynamic-field-group border-green-200">
                                            <span class="remove-field text-red-500" onclick="removeField(this)"><i class="fas fa-times-circle"></i></span>
                                            <div class="mb-3">
                                                <label class="block text-xs font-medium text-gray-700 mb-1">Select Main Topic</label>
                                                <select class="w-full p-2 text-sm border border-gray-300 rounded-md" name="main_topic_id[]" required>
                                                    <option value="">-- Select Main Topic --</option>
                                                    <?php foreach ($main_topics as $topic): ?>
                                                        <option value="<?php echo $topic['id']; ?>">
                                                            Ch <?php echo $topic['chapter']; ?>: <?php echo htmlspecialchars($topic['topic_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-1">
                                                <label class="block text-xs font-medium text-gray-700 mb-1">Sub Topic Name</label>
                                                <input type="text" class="w-full p-2 text-sm border border-gray-300 rounded-md" name="sub_topic_name[]" required placeholder="e.g. Introduction">
                                            </div>
                                        </div>
                                    </div>
                                    <button type="submit" name="add_sub_topics" class="w-full mt-4 px-4 py-2 text-sm bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition-colors">
                                        Save Sub Topics
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Actions (Smaller) -->
                <div class="w-full lg:w-1/4">
                    <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm hover-lift sticky top-24">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                            <div class="icon-wrapper mr-3">
                                <i class="fas fa-cog text-blue-500"></i>
                            </div>
                            Quick Actions
                        </h3>
                        <div class="space-y-3">
                            <div class="flex items-center gap-2">
                                <a href="../content/course_folder.php?course_id=<?= urlencode($course_id) ?>" class="flex-1 flex items-center px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-indigo-50 hover:border-indigo-200 hover:text-indigo-700 transition-colors group">
                                    <i class="fas fa-folder-open w-6 text-center text-gray-400 group-hover:text-indigo-500"></i> 
                                    <span class="ml-2">Manage Content</span>
                                </a>
                                <button type="button" onclick="openVisibilityModal()" class="px-4 py-3 border border-gray-200 rounded-lg text-gray-500 bg-white hover:bg-indigo-50 hover:border-indigo-200 hover:text-indigo-600 transition-colors" title="Content Visibility Settings">
                                    <i class="fas fa-cog"></i>
                                </button>
                            </div>

                            <a href="javascript:void(0)" onclick="openTrainerModal()" class="flex items-center w-full px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-yellow-50 hover:border-yellow-200 hover:text-yellow-700 transition-colors group">
                                <i class="fas fa-chalkboard-teacher w-6 text-center text-gray-400 group-hover:text-yellow-500"></i> 
                                <span class="ml-2">Assign Trainer</span>
                            </a>

                            <a href="progress_batch.php?batch_id=<?= urlencode($batch_id) ?>&course_id=<?= urlencode($course_id) ?>" class="flex items-center w-full px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-blue-50 hover:border-blue-200 hover:text-blue-700 transition-colors group">
                                <i class="fas fa-chart-line w-6 text-center text-gray-400 group-hover:text-blue-500"></i> 
                                <span class="ml-2">Track Progress</span>
                            </a>

                            <a href="../exam/exams.php?batch_id=<?= urlencode($batch_id) ?>&course_id=<?= urlencode($course_id) ?>" class="flex items-center w-full px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-success hover:border-success hover:text-success transition-colors group">
                                <i class="fas fa-upload w-6 text-center text-gray-400 group-hover:text-success"></i> 
                                <span class="ml-2">Upload Result</span>
                            </a>

                            <a href="../attendance/course_attendance.php?batch_id=<?= urlencode($batch_id) ?>&course_id=<?= urlencode($course_id) ?>" class="flex items-center w-full px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-teal-50 hover:border-teal-200 hover:text-teal-700 transition-colors group">
                                <i class="fas fa-clipboard-user w-6 text-center text-gray-400 group-hover:text-teal-500"></i> 
                                <span class="ml-2">Manage Attendance</span>
                            </a>

                            <a href="manage_assignments.php?batch_id=<?= urlencode($batch_id) ?>" class="flex items-center w-full px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-red-50 hover:border-red-200 hover:text-red-700 transition-colors group">
                                <i class="fas fa-tasks w-6 text-center text-gray-400 group-hover:text-red-500"></i> 
                                <span class="ml-2">Assignments</span>
                            </a>

                            <a href="../admin_test/create_test.php?batch_id=<?= urlencode($batch_id) ?>" class="flex items-center w-full px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-purple-50 hover:border-purple-200 hover:text-purple-700 transition-colors group">
                                <i class="fas fa-file-alt w-6 text-center text-gray-400 group-hover:text-purple-500"></i> 
                                <span class="ml-2">Manage Tests</span>
                            </a>

                            <a href="manage_student.php?batch_id=<?= urlencode($batch_id) ?>" class="flex items-center w-full px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-green-50 hover:border-green-200 hover:text-green-700 transition-colors group">
                                <i class="fas fa-users w-6 text-center text-gray-400 group-hover:text-green-500"></i> 
                                <span class="ml-2">Manage Students</span>
                            </a>
                            
                            <a href="../schedule/schedule.php?batch_id=<?= urlencode($batch_id) ?>&course_id=<?= urlencode($course_id) ?>" class="flex items-center w-full px-4 py-3 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-orange-50 hover:border-orange-200 hover:text-orange-700 transition-colors group">
                                <i class="fas fa-calendar-alt w-6 text-center text-gray-400 group-hover:text-orange-500"></i> 
                                <span class="ml-2">View Schedule</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Visibility Modal -->
    <div id="visibilityModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl max-w-md w-full max-h-[90vh] flex flex-col transform transition-all">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-2xl">
                <h3 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-eye text-indigo-500 mr-3"></i>
                    Content Visibility Settings
                </h3>
                <button onclick="closeVisibilityModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="p-6 overflow-y-auto flex-1">
                <p class="text-sm text-gray-600 mb-4">Select the batches that can see content for: <span class="font-bold text-indigo-600"><?= htmlspecialchars($course['name']) ?></span></p>
                
                <form id="assignVisibilityForm" method="POST">
                    <input type="hidden" name="action" value="assign_visibility">
                    <input type="hidden" name="course_id" value="<?= htmlspecialchars($course_id) ?>">
                    
                    <div class="space-y-3">
                        <?php foreach ($all_batches as $b): 
                            $is_checked = in_array($b['batch_id'], $visible_batches_raw) ? 'checked' : '';
                        ?>
                        <label class="flex items-center p-3 border border-gray-200 rounded-xl hover:bg-indigo-50 hover:border-indigo-200 transition-colors cursor-pointer group">
                            <input type="checkbox" name="batch_ids[]" value="<?= htmlspecialchars($b['batch_id']) ?>" <?= $is_checked ?>
                                   class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 batch-checkbox">
                            <span class="ml-3 text-sm font-medium text-gray-700 group-hover:text-indigo-700">
                                <?= htmlspecialchars($b['batch_id'] . ' - ' . $b['batch_name']) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
            
            <div class="p-6 border-t border-gray-100 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closeVisibilityModal()" type="button" class="px-5 py-2.5 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button onclick="document.getElementById('assignVisibilityForm').submit()" type="button" class="px-5 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors shadow-md hover:shadow-lg">
                    Save Visibility
                </button>
            </div>
        </div>
    </div>

    <!-- Assign Trainer Modal -->
    <div id="trainerModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl max-w-md w-full max-h-[90vh] flex flex-col transform transition-all">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-2xl">
                <h3 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-chalkboard-teacher text-yellow-500 mr-3"></i>
                    Assign Trainer
                </h3>
                <button onclick="closeTrainerModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="p-6 overflow-y-auto flex-1">
                <p class="text-sm text-gray-600 mb-4">Select a trainer for <span class="font-bold text-yellow-600"><?= htmlspecialchars($course['name']) ?></span> in this batch.</p>
                
                <form id="assignTrainerForm" method="POST">
                    <input type="hidden" name="action" value="assign_trainer">
                    <input type="hidden" name="course_id" value="<?= htmlspecialchars($course_id) ?>">
                    <input type="hidden" name="batch_id" value="<?= htmlspecialchars($batch_id) ?>">
                    
                    <div class="space-y-3">
                        <select name="trainer_id" class="w-full p-3 border border-gray-300 rounded-xl focus:ring-yellow-500 focus:border-yellow-500">
                            <option value="">-- No Trainer Assigned --</option>
                            <?php foreach ($all_trainers as $trainer): ?>
                                <option value="<?= $trainer['id'] ?>" <?= ($assigned_trainer == $trainer['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($trainer['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            
            <div class="p-6 border-t border-gray-100 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closeTrainerModal()" type="button" class="px-5 py-2.5 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button onclick="document.getElementById('assignTrainerForm').submit()" type="button" class="px-5 py-2.5 text-sm font-medium text-white bg-yellow-600 rounded-xl hover:bg-yellow-700 transition-colors shadow-md hover:shadow-lg">
                    Save Trainer
                </button>
            </div>
        </div>
    </div>

    <script>

        function openVisibilityModal() {
            document.getElementById('visibilityModal').classList.remove('hidden');
        }

        function closeVisibilityModal() {
            document.getElementById('visibilityModal').classList.add('hidden');
        }

        function openTrainerModal() {
            document.getElementById('trainerModal').classList.remove('hidden');
        }

        function closeTrainerModal() {
            document.getElementById('trainerModal').classList.add('hidden');
        }

        function removeField(element) {
            const container = element.closest('.dynamic-field-group').parentElement;
            if (container.children.length > 1) {
                element.closest('.dynamic-field-group').remove();
            } else {
                alert('You must have at least one field.');
            }
        }

        document.getElementById('addMainTopicField').addEventListener('click', function() {
            const container = document.getElementById('mainTopicsContainer');
            const newField = container.children[0].cloneNode(true);
            newField.querySelectorAll('input').forEach(input => input.value = '');
            container.appendChild(newField);
        });

        const addSubTopicBtn = document.getElementById('addSubTopicField');
        if (addSubTopicBtn) {
            addSubTopicBtn.addEventListener('click', function() {
                const container = document.getElementById('subTopicsContainer');
                const newField = container.children[0].cloneNode(true);
                newField.querySelectorAll('input').forEach(input => input.value = '');
                container.appendChild(newField);
            });
        }
    </script>
</body>
</html>
