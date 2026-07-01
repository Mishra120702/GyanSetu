<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db_connection.php';
require_once '../batch/sync_curriculum.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$course_id = $_GET['course_id'] ?? '';

if (!$course_id) {
    die("Course ID is required");
}

// Fetch course details
$course_stmt = $db->prepare("SELECT * FROM courses WHERE id = ?");
$course_stmt->execute([$course_id]);
$course = $course_stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Course not found");
}

// Handle Sample CSV Download
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_curriculum.csv"');
    $output = fopen('php://output', 'w');
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
                $stmt = $db->prepare("INSERT INTO course_main_topics (course_id, topic_name, topic_type) VALUES (?, ?, ?)");
                $stmt->execute([$course_id, $topic_names[$i], $topic_type]);
                $added_count++;
            }
        }
        
        $_SESSION['success_message'] = "$added_count main topic(s) added successfully!";
    } 
    elseif (isset($_POST['add_sub_topics'])) {
        $main_topic_ids = $_POST['main_topic_id'] ?? [];
        $sub_topic_names = $_POST['sub_topic_name'] ?? [];
        
        $added_count = 0;
        
        for ($i = 0; $i < count($sub_topic_names); $i++) {
            if (!empty($sub_topic_names[$i]) && !empty($main_topic_ids[$i])) {
                $main_topic_id = $main_topic_ids[$i];
                $sub_topic_name = $sub_topic_names[$i];
                
                $stmt = $db->prepare("INSERT INTO course_sub_topics (course_main_topic_id, sub_topic_name) VALUES (?, ?)");
                $stmt->execute([$main_topic_id, $sub_topic_name]);
                $added_count++;
            }
        }
        
        $_SESSION['success_message'] = "$added_count sub topic(s) added successfully!";
    }
    elseif (isset($_POST['delete_main_topic'])) {
        $main_topic_id = $_POST['main_topic_id'];
        $stmt = $db->prepare("DELETE FROM course_sub_topics WHERE course_main_topic_id = ?");
        $stmt->execute([$main_topic_id]);
        $stmt = $db->prepare("DELETE FROM course_main_topics WHERE id = ? AND course_id = ?");
        $stmt->execute([$main_topic_id, $course_id]);
        $_SESSION['success_message'] = "Chapter deleted successfully!";
    }
    elseif (isset($_POST['bulk_delete_main_topics'])) {
        $topic_ids = $_POST['selected_topics'] ?? [];
        if (!empty($topic_ids)) {
            $placeholders = implode(',', array_fill(0, count($topic_ids), '?'));
            $stmt = $db->prepare("DELETE FROM course_sub_topics WHERE course_main_topic_id IN ($placeholders)");
            $stmt->execute($topic_ids);
            
            $params = $topic_ids;
            $params[] = $course_id;
            $stmt = $db->prepare("DELETE FROM course_main_topics WHERE id IN ($placeholders) AND course_id = ?");
            $stmt->execute($params);
            $_SESSION['success_message'] = count($topic_ids) . " chapter(s) deleted successfully!";
        } else {
            $_SESSION['error_message'] = "No chapters selected for deletion.";
        }
    }
    elseif (isset($_POST['edit_main_topic'])) {
        $main_topic_id = $_POST['main_topic_id'];
        $topic_name = $_POST['topic_name'];
        $topic_type = $_POST['topic_type'];
        
        $stmt = $db->prepare("UPDATE course_main_topics SET topic_name = ?, topic_type = ? WHERE id = ? AND course_id = ?");
        $stmt->execute([$topic_name, $topic_type, $main_topic_id, $course_id]);
        $_SESSION['success_message'] = "Chapter updated successfully!";
    }
    elseif (isset($_POST['delete_sub_topic'])) {
        $sub_topic_id = $_POST['sub_topic_id'];
        $stmt = $db->prepare("DELETE FROM course_sub_topics WHERE id = ?");
        $stmt->execute([$sub_topic_id]);
        $_SESSION['success_message'] = "Sub topic deleted successfully!";
    }
    elseif (isset($_POST['edit_sub_topic'])) {
        $sub_topic_id = $_POST['sub_topic_id'];
        $sub_topic_name = $_POST['sub_topic_name'];
        $stmt = $db->prepare("UPDATE course_sub_topics SET sub_topic_name = ? WHERE id = ?");
        $stmt->execute([$sub_topic_name, $sub_topic_id]);
        $_SESSION['success_message'] = "Sub topic updated successfully!";
    }
    elseif (isset($_POST['upload_csv']) && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file']['tmp_name'];
        if (is_uploaded_file($file)) {
            $handle = fopen($file, "r");
            $main_topics_added = 0;
            $sub_topics_added = 0;
            
            $first_row = fgetcsv($handle, 1000, ",");
            if ($first_row && stripos(implode(',', $first_row), 'chapter') === false) {
                rewind($handle);
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 2) continue;
                
                $chapter = trim($data[0]);
                $topic_name = trim($data[1]);
                $topic_type = isset($data[2]) ? trim(strtolower($data[2])) : 'both';
                if (!in_array($topic_type, ['both', 'theory', 'practical'])) {
                    $topic_type = 'both';
                }
                $sub_topic_name = isset($data[3]) ? trim($data[3]) : '';
                
                if (empty($chapter) || empty($topic_name)) continue;
                
                $stmt = $db->prepare("SELECT id FROM course_main_topics WHERE course_id = ? AND topic_name = ?");
                $stmt->execute([$course_id, $topic_name]);
                $main_topic_id = $stmt->fetchColumn();
                
                if (!$main_topic_id) {
                    $insert_main = $db->prepare("INSERT INTO course_main_topics (course_id, topic_name, topic_type) VALUES (?, ?, ?)");
                    $insert_main->execute([$course_id, $topic_name, $topic_type]);
                    $main_topic_id = $db->lastInsertId();
                    $main_topics_added++;
                }
                
                if (!empty($sub_topic_name)) {
                    $check_sub = $db->prepare("SELECT id FROM course_sub_topics WHERE course_main_topic_id = ? AND sub_topic_name = ?");
                    $check_sub->execute([$main_topic_id, $sub_topic_name]);
                    if (!$check_sub->fetchColumn()) {
                        $insert_sub = $db->prepare("INSERT INTO course_sub_topics (course_main_topic_id, sub_topic_name) VALUES (?, ?)");
                        $insert_sub->execute([$main_topic_id, $sub_topic_name]);
                        $sub_topics_added++;
                    }
                }
            }
            fclose($handle);
            $_SESSION['success_message'] = "CSV Uploaded! Added $main_topics_added new chapters and $sub_topics_added new sub-topics.";
        } else {
            $_SESSION['error_message'] = "Failed to upload CSV file.";
        }
    }
    
    sync_course_to_all_batches($db, $course_id);
    
    header("Location: manage_curriculum.php?course_id=" . $course_id);
    exit();
}

// Fetch main topics for this course
$main_topics_stmt = $db->prepare("SELECT * FROM course_main_topics WHERE course_id = ? ORDER BY id");
$main_topics_stmt->execute([$course_id]);
$main_topics = $main_topics_stmt->fetchAll(PDO::FETCH_ASSOC);

$main_topics_with_sub_topics = [];
foreach ($main_topics as $main_topic) {
    $sub_topics_stmt = $db->prepare("SELECT * FROM course_sub_topics WHERE course_main_topic_id = ?");
    $sub_topics_stmt->execute([$main_topic['id']]);
    $main_topic['sub_topics'] = $sub_topics_stmt->fetchAll(PDO::FETCH_ASSOC);
    $main_topics_with_sub_topics[] = $main_topic;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Curriculum - <?php echo htmlspecialchars($course['name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        /* ================================================================
           BRAND PALETTE (locked)
           ================================================================ */
        :root {
            --deepest-navy: #1B3C53;
            --dark-steel: #234C6A;
            --mid-steel: #456882;
            --warm-sand: #D2C1B6;
            --soft-sky: #A4C4D4;
            --white: #ffffff;
            --danger-red: #C0392B;
            --danger-light: #ef4444;
            --terracotta: #C97B50;
            --amber: #f59e0b;
            --success-green: #166534;
            --success-bg: #dcfce7;
            --error-red: #991b1b;
            --error-bg: #fee2e2;
            --warning-amber: #92400e;
            --warning-bg: #fef3c7;
            --upload-purple: #7C5CBF;
        }

        /* ================================================================
           BASE
           ================================================================ */
        * {
            transition: all 0.25s ease;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background:
                radial-gradient(1100px 500px at 100% -8%, rgba(69,104,130,.22), transparent 55%),
                radial-gradient(900px 450px at -10% 108%, rgba(27,60,83,.16), transparent 55%),
                radial-gradient(rgba(27,60,83,.045) 1px, transparent 1px) 0 0 / 22px 22px,
                linear-gradient(165deg, #e8e2db 0%, #e4ddd5 44%, #d9e3ec 100%);
            background-attachment: fixed;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ================================================================
           SCROLLBAR
           ================================================================ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--warm-sand);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--mid-steel), var(--dark-steel));
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--dark-steel), var(--deepest-navy));
        }

        /* ================================================================
           SECTION HEADINGS
           ================================================================ */
        .section-heading {
            color: var(--dark-steel);
            font-weight: 700;
            font-size: 1.15rem;
            letter-spacing: -0.01em;
        }

        .form-label {
            color: var(--dark-steel);
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
            display: block;
        }

        /* ================================================================
           HERO BANNER
           ================================================================ */
        .hero-banner {
            background: linear-gradient(135deg, var(--deepest-navy) 0%, var(--dark-steel) 45%, var(--mid-steel) 100%);
            color: var(--white);
            border-radius: 16px;
            padding: 1.5rem 2rem;
            box-shadow: 0 4px 24px rgba(27,60,83,.2);
        }

        .hero-banner h1 {
            font-weight: 800;
            letter-spacing: -0.02em;
            font-size: 1.5rem;
        }

        /* ================================================================
           CARDS / PANELS
           ================================================================ */
        .card-brand {
            background: var(--white);
            border-radius: 16px;
            border: none;
            box-shadow: 0 4px 20px rgba(27,60,83,.13);
            position: relative;
            overflow: hidden;
            padding: 1.5rem;
            height: 100%;
            transition: box-shadow 0.4s ease, transform 0.3s ease;
        }

        .card-brand::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            border-radius: 4px 0 0 4px;
            z-index: 2;
        }

        .card-brand:hover {
            box-shadow: 0 8px 32px rgba(27,60,83,.18);
            transform: translateY(-2px);
        }

        /* Animated conic border ring on hover */
        .card-brand::after {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 18px;
            background: conic-gradient(
                var(--deepest-navy), var(--dark-steel), var(--mid-steel),
                var(--warm-sand), var(--soft-sky), var(--deepest-navy)
            );
            z-index: -1;
            opacity: 0;
            transition: opacity 0.5s ease;
            animation: conicSpin 6s linear infinite;
            animation-play-state: paused;
        }

        @keyframes conicSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .card-brand:hover::after {
            opacity: 0.35;
            animation-play-state: running;
        }

        /* Section-specific accent overrides */
        .card-add::before { background: var(--terracotta); }
        .card-preview::before { background: var(--mid-steel); }
        .card-sub::before { background: var(--upload-purple); }
        .card-csv::before { background: var(--soft-sky); }

        /* ================================================================
           BUTTONS
           ================================================================ */
        .btn-brand {
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.6rem 1.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
            letter-spacing: 0.01em;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-brand:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,.15);
        }

        .btn-brand:active {
            transform: translateY(0);
        }

        /* Primary/CTA - Amber to Terracotta */
        .btn-primary-brand {
            background: linear-gradient(135deg, var(--amber), var(--terracotta));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(201,123,80,.35);
        }
        .btn-primary-brand:hover {
            box-shadow: 0 8px 24px rgba(201,123,80,.45);
            color: var(--white);
        }

        /* Success/Confirm - Steel Blue */
        .btn-success-brand {
            background: linear-gradient(135deg, var(--mid-steel), var(--dark-steel));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(35,76,106,.35);
        }
        .btn-success-brand:hover {
            box-shadow: 0 8px 24px rgba(35,76,106,.45);
            color: var(--white);
        }

        /* Danger/Delete - Red */
        .btn-danger-brand {
            background: linear-gradient(135deg, var(--danger-light), var(--danger-red));
            color: var(--white);
            box-shadow: 0 4px 14px rgba(192,57,43,.35);
        }
        .btn-danger-brand:hover {
            box-shadow: 0 8px 24px rgba(192,57,43,.45);
            color: var(--white);
        }

        /* Secondary/Neutral - Sand */
        .btn-secondary-brand {
            background: linear-gradient(135deg, #EAE4E0, var(--warm-sand));
            color: var(--deepest-navy);
            box-shadow: 0 4px 14px rgba(210,193,182,.35);
        }
        .btn-secondary-brand:hover {
            box-shadow: 0 8px 24px rgba(210,193,182,.45);
            color: var(--deepest-navy);
        }

        /* Small button variant */
        .btn-brand-sm {
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }

        .btn-full {
            width: 100%;
            justify-content: center;
        }

        /* ================================================================
           INPUTS
           ================================================================ */
        .input-brand {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--warm-sand);
            border-radius: 12px;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            background: var(--white);
            color: var(--deepest-navy);
            transition: all 0.3s ease;
            outline: none;
        }

        .input-brand:focus {
            border-color: var(--dark-steel);
            box-shadow: 0 0 0 4px rgba(35,76,106,.12);
            background: var(--white);
        }

        /* ================================================================
           BADGES
           ================================================================ */
        .badge-tag {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .badge-theory { background: #dbeafe; color: #1e40af; }
        .badge-practical { background: #dcfce7; color: #065f46; }
        .badge-both { background: var(--warm-sand); color: var(--deepest-navy); }
        .badge-course {
            background: rgba(255,255,255,.2);
            color: var(--white);
            border-radius: 9999px;
            padding: 0.35rem 1rem;
            font-weight: 600;
            font-size: 0.85rem;
            border: 1px solid rgba(255,255,255,.25);
        }

        /* ================================================================
           DYNAMIC FIELDS
           ================================================================ */
        .dynamic-field {
            position: relative;
            padding: 20px;
            border: 2px dashed var(--warm-sand);
            border-radius: 14px;
            margin-bottom: 12px;
            background: rgba(210,193,182,.06);
            transition: border-color 0.3s ease;
        }

        .dynamic-field:hover {
            border-color: var(--mid-steel);
        }

        .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 28px;
            height: 28px;
            background: var(--error-bg);
            border: none;
            border-radius: 8px;
            color: var(--danger-light);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .remove-btn:hover {
            background: #fecaca;
            transform: scale(1.1);
        }

        /* ================================================================
           CURRICULUM ITEMS
           ================================================================ */
        .curriculum-item {
            border: 1px solid rgba(210,193,182,.4);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 10px;
            background: var(--white);
            transition: box-shadow 0.3s ease;
        }

        .curriculum-item:hover {
            box-shadow: 0 4px 16px rgba(27,60,83,.08);
        }

        .curriculum-header {
            background: rgba(210,193,182,.08);
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(210,193,182,.2);
        }

        .curriculum-sub {
            padding: 10px 18px 10px 44px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(210,193,182,.1);
            font-size: 14px;
            transition: background 0.2s ease;
        }

        .curriculum-sub:last-child { border-bottom: none; }
        .curriculum-sub:hover { background: rgba(210,193,182,.06); }

        .edit-form {
            padding: 14px 18px;
            background: var(--warning-bg);
            border-bottom: 1px solid #fde68a;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ================================================================
           ALERTS
           ================================================================ */
        .alert-brand {
            border-radius: 14px;
            border: none;
            padding: 1rem 1.25rem;
            font-weight: 500;
            box-shadow: 0 4px 16px rgba(0,0,0,.06);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
        }

        .alert-success-brand {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border-left: 5px solid var(--success-green);
            color: var(--success-green);
        }

        .alert-error-brand {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-left: 5px solid var(--error-red);
            color: var(--error-red);
        }

        /* ================================================================
           CSV UPLOAD ZONE
           ================================================================ */
        .upload-zone {
            border: 2px dashed var(--warm-sand);
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            background: rgba(210,193,182,.05);
            transition: border-color 0.3s ease, background 0.3s ease;
            margin-bottom: 1rem;
        }

        .upload-zone:hover {
            border-color: var(--soft-sky);
            background: rgba(164,196,212,.06);
        }

        /* ================================================================
           CHECKBOX
           ================================================================ */
        .checkbox-brand {
            width: 18px;
            height: 18px;
            accent-color: var(--deepest-navy);
            cursor: pointer;
        }

        /* ================================================================
           RESPONSIVE
           ================================================================ */
        @media (max-width: 1024px) {
            .hero-banner {
                padding: 1rem 1.25rem;
            }
            .card-brand {
                padding: 1rem;
            }
        }

        @media (max-width: 768px) {
            .btn-brand {
                font-size: 0.8rem;
                padding: 0.45rem 1rem;
            }
            .curriculum-header {
                flex-wrap: wrap;
                gap: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <!-- Hero Banner -->
        <div class="hero-banner mx-4 mt-4 md:mx-6 md:mt-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <button class="md:hidden text-white p-2 rounded-xl hover:bg-white/10" onclick="toggleSidebar()">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <span class="rounded-xl p-2.5 flex items-center justify-center" style="background: rgba(255,255,255,.15);">
                        <i class="fas fa-book-open text-white text-xl"></i>
                    </span>
                    <div>
                        <h1 class="mb-1">Curriculum Management</h1>
                        <p class="opacity-80 text-sm mb-0">
                            <span class="badge-course">
                                <i class="fas fa-graduation-cap mr-1.5"></i> <?php echo htmlspecialchars($course['name']); ?>
                            </span>
                        </p>
                    </div>
                </div>
                <a href="index.php" class="btn-brand btn-secondary-brand" style="background: rgba(255,255,255,.15); color: var(--white); border: 1px solid rgba(255,255,255,.25); box-shadow: none;">
                    <i class="fas fa-arrow-left"></i> Back to Courses
                </a>
            </div>
        </div>

        <!-- Page Content -->
        <div class="p-4 md:p-6 max-w-7xl mx-auto">
            <!-- Alerts -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert-brand alert-success-brand">
                    <i class="fas fa-check-circle text-xl flex-shrink-0" style="color: var(--success-green);"></i>
                    <span><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert-brand alert-error-brand">
                    <i class="fas fa-exclamation-circle text-xl flex-shrink-0" style="color: var(--error-red);"></i>
                    <span><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></span>
                </div>
            <?php endif; ?>

            <!-- Main Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Add Topics Card -->
                <div class="card-brand card-add">
                    <div class="flex justify-between items-center mb-5 pb-4" style="border-bottom: 2px solid rgba(210,193,182,.25);">
                        <h3 class="section-heading flex items-center gap-2 mb-0">
                            <span class="rounded-lg p-2 flex items-center justify-center" style="background: rgba(201,123,80,.12);">
                                <i class="fas fa-layer-group text-lg" style="color: var(--terracotta);"></i>
                            </span>
                            Add Main Topics
                        </h3>
                        <button type="button" id="addMainTopicField" class="btn-brand btn-secondary-brand btn-brand-sm">
                            <i class="fas fa-plus"></i> Add More
                        </button>
                    </div>
                    <form method="POST" id="mainTopicsForm">
                        <div id="mainTopicsContainer">
                            <div class="dynamic-field">
                                <button type="button" class="remove-btn" onclick="removeField(this)"><i class="fas fa-times"></i></button>
                                <div class="mb-4">
                                    <label class="form-label">Topic Name *</label>
                                    <input type="text" class="input-brand" name="topic_name[]" placeholder="e.g. Introduction to Web Development" required>
                                </div>
                                <div>
                                    <label class="form-label">Topic Type *</label>
                                    <select class="input-brand" name="topic_type[]" required>
                                        <option value="both">Both (Theory & Practical)</option>
                                        <option value="theory">Theory Only</option>
                                        <option value="practical">Practical Only</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="add_main_topics" class="btn-brand btn-primary-brand btn-full mt-4">
                            <i class="fas fa-save"></i> Save Topics
                        </button>
                    </form>
                </div>

                <!-- Curriculum Structure Card -->
                <div class="card-brand card-preview">
                    <div class="flex justify-between items-center mb-5 pb-4" style="border-bottom: 2px solid rgba(210,193,182,.25);">
                        <h3 class="section-heading flex items-center gap-2 mb-0">
                            <span class="rounded-lg p-2 flex items-center justify-center" style="background: rgba(69,104,130,.12);">
                                <i class="fas fa-sitemap text-lg" style="color: var(--mid-steel);"></i>
                            </span>
                            Curriculum Structure
                        </h3>
                        <?php if (!empty($main_topics_with_sub_topics)): ?>
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2 text-sm cursor-pointer font-medium" style="color: var(--deepest-navy);">
                                <input type="checkbox" id="selectAllTopics" class="checkbox-brand">
                                <span class="hidden sm:inline">Select All</span>
                            </label>
                            <button type="button" onclick="submitBulkDelete()" class="btn-brand btn-danger-brand btn-brand-sm">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="max-height: 500px; overflow-y: auto; padding-right: 4px;" class="scrollbar-thin">
                        <form id="bulkDeleteForm" method="POST" class="hidden"></form>

                        <?php if (empty($main_topics_with_sub_topics)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-book-open text-5xl mb-4 block opacity-25" style="color: var(--warm-sand);"></i>
                                <p class="font-semibold" style="color: var(--mid-steel);">No curriculum defined yet</p>
                                <p class="text-sm mt-1 opacity-70" style="color: var(--mid-steel);">Start by adding topics using the form</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($main_topics_with_sub_topics as $index => $topic): ?>
                                <div class="curriculum-item">
                                    <div class="curriculum-header" id="topic-display-<?php echo $topic['id']; ?>">
                                        <div class="flex items-center gap-3 min-w-0 flex-1">
                                            <input type="checkbox" value="<?php echo $topic['id']; ?>" class="topic-checkbox checkbox-brand flex-shrink-0">
                                            <span class="badge-tag badge-<?php echo $topic['topic_type']; ?> flex-shrink-0"><?php echo ucfirst($topic['topic_type']); ?></span>
                                            <span class="font-semibold truncate" style="color: var(--deepest-navy);"><?php echo htmlspecialchars($topic['topic_name']); ?></span>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <button type="button" onclick="toggleEdit(<?php echo $topic['id']; ?>)" class="btn-brand btn-secondary-brand btn-brand-sm">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this topic and all its sub-topics?');">
                                                <input type="hidden" name="main_topic_id" value="<?php echo $topic['id']; ?>">
                                                <button type="submit" name="delete_main_topic" class="btn-brand btn-danger-brand btn-brand-sm">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <form method="POST" id="topic-edit-<?php echo $topic['id']; ?>" class="edit-form hidden">
                                        <input type="hidden" name="main_topic_id" value="<?php echo $topic['id']; ?>">
                                        <div class="flex flex-col sm:flex-row gap-3 items-end">
                                            <div class="flex-1">
                                                <label class="form-label" style="font-size: 0.7rem;">Topic Name</label>
                                                <input type="text" name="topic_name" value="<?php echo htmlspecialchars($topic['topic_name']); ?>" class="input-brand" required>
                                            </div>
                                            <div>
                                                <label class="form-label" style="font-size: 0.7rem;">Type</label>
                                                <select name="topic_type" class="input-brand" required>
                                                    <option value="both" <?php echo $topic['topic_type'] == 'both' ? 'selected' : ''; ?>>Both</option>
                                                    <option value="theory" <?php echo $topic['topic_type'] == 'theory' ? 'selected' : ''; ?>>Theory</option>
                                                    <option value="practical" <?php echo $topic['topic_type'] == 'practical' ? 'selected' : ''; ?>>Practical</option>
                                                </select>
                                            </div>
                                            <div class="flex gap-2">
                                                <button type="submit" name="edit_main_topic" class="btn-brand btn-success-brand btn-brand-sm">Save</button>
                                                <button type="button" onclick="toggleEdit(<?php echo $topic['id']; ?>)" class="btn-brand btn-secondary-brand btn-brand-sm">Cancel</button>
                                            </div>
                                        </div>
                                    </form>

                                    <?php if (!empty($topic['sub_topics'])): ?>
                                        <?php foreach ($topic['sub_topics'] as $sub_index => $sub_topic): ?>
                                            <div class="curriculum-sub" id="subtopic-display-<?php echo $sub_topic['id']; ?>">
                                                <div class="flex items-center gap-3">
                                                    <span class="text-xs font-mono font-bold" style="color: var(--mid-steel);"><?php echo $sub_index + 1; ?>.</span>
                                                    <span style="color: var(--deepest-navy); font-size: 0.9rem;"><?php echo htmlspecialchars($sub_topic['sub_topic_name']); ?></span>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <button type="button" onclick="toggleSubEdit(<?php echo $sub_topic['id']; ?>)" class="btn-brand btn-secondary-brand btn-brand-sm">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this sub-topic?');">
                                                        <input type="hidden" name="sub_topic_id" value="<?php echo $sub_topic['id']; ?>">
                                                        <button type="submit" name="delete_sub_topic" class="btn-brand btn-danger-brand btn-brand-sm">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                            <form method="POST" id="subtopic-edit-<?php echo $sub_topic['id']; ?>" class="edit-form hidden">
                                                <input type="hidden" name="sub_topic_id" value="<?php echo $sub_topic['id']; ?>">
                                                <div class="flex gap-2">
                                                    <input type="text" name="sub_topic_name" value="<?php echo htmlspecialchars($sub_topic['sub_topic_name']); ?>" class="input-brand flex-1" required>
                                                    <button type="submit" name="edit_sub_topic" class="btn-brand btn-success-brand btn-brand-sm">Save</button>
                                                    <button type="button" onclick="toggleSubEdit(<?php echo $sub_topic['id']; ?>)" class="btn-brand btn-secondary-brand btn-brand-sm">Cancel</button>
                                                </div>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="curriculum-sub text-sm italic" style="color: #9ca3af;">No sub-topics added yet</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add Sub Topics Card -->
                <div class="card-brand card-sub">
                    <div class="flex justify-between items-center mb-5 pb-4" style="border-bottom: 2px solid rgba(210,193,182,.25);">
                        <h3 class="section-heading flex items-center gap-2 mb-0">
                            <span class="rounded-lg p-2 flex items-center justify-center" style="background: rgba(124,92,191,.12);">
                                <i class="fas fa-list-ul text-lg" style="color: var(--upload-purple);"></i>
                            </span>
                            Add Sub Topics
                        </h3>
                        <button type="button" id="addSubTopicField" class="btn-brand btn-secondary-brand btn-brand-sm">
                            <i class="fas fa-plus"></i> Add More
                        </button>
                    </div>
                    <form method="POST" id="subTopicsForm">
                        <?php if (empty($main_topics)): ?>
                            <div class="text-center py-10">
                                <i class="fas fa-exclamation-triangle text-3xl mb-3 block" style="color: var(--amber);"></i>
                                <p class="font-semibold" style="color: var(--mid-steel);">No topics available</p>
                                <p class="text-sm opacity-70" style="color: var(--mid-steel);">Please add main topics first</p>
                            </div>
                        <?php else: ?>
                            <div id="subTopicsContainer">
                                <div class="dynamic-field">
                                    <button type="button" class="remove-btn" onclick="removeField(this)"><i class="fas fa-times"></i></button>
                                    <div class="mb-4">
                                        <label class="form-label">Select Main Topic *</label>
                                        <select class="input-brand" name="main_topic_id[]" required>
                                            <option value="">-- Select Topic --</option>
                                            <?php foreach ($main_topics as $topic): ?>
                                                <option value="<?php echo $topic['id']; ?>"><?php echo htmlspecialchars($topic['topic_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Sub Topic Name *</label>
                                        <input type="text" class="input-brand" name="sub_topic_name[]" placeholder="e.g. Introduction to Variables" required>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_sub_topics" class="btn-brand btn-success-brand btn-full mt-4">
                                <i class="fas fa-save"></i> Save Sub Topics
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Bulk Upload CSV Card -->
                <div class="card-brand card-csv">
                    <div class="flex justify-between items-center mb-5 pb-4" style="border-bottom: 2px solid rgba(210,193,182,.25);">
                        <h3 class="section-heading flex items-center gap-2 mb-0">
                            <span class="rounded-lg p-2 flex items-center justify-center" style="background: rgba(164,196,212,.15);">
                                <i class="fas fa-file-csv text-lg" style="color: var(--dark-steel);"></i>
                            </span>
                            Bulk Upload CSV
                        </h3>
                        <a href="?course_id=<?php echo htmlspecialchars($course_id); ?>&download_sample=1" class="btn-brand btn-secondary-brand btn-brand-sm">
                            <i class="fas fa-download"></i> Sample CSV
                        </a>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="upload-zone">
                            <i class="fas fa-cloud-upload-alt text-4xl mb-3 block" style="color: var(--warm-sand);"></i>
                            <p class="font-semibold mb-1" style="color: var(--deepest-navy);">Choose CSV file to upload</p>
                            <p class="text-xs mb-4 opacity-70" style="color: var(--mid-steel);">
                                Format: Chapter Number, Topic Name, Topic Type, Sub Topic Name (optional)
                            </p>
                            <input type="file" name="csv_file" accept=".csv" required class="input-brand" style="max-width: 320px; margin: 0 auto;">
                        </div>
                        <button type="submit" name="upload_csv" class="btn-brand btn-primary-brand btn-full">
                            <i class="fas fa-upload"></i> Upload & Import
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.toggle('open');
            }
        }

        function removeField(element) {
            const container = element.closest('.dynamic-field').parentElement;
            if (container.children.length > 1) {
                element.closest('.dynamic-field').remove();
            } else {
                alert('You must have at least one field.');
            }
        }

        document.getElementById('addMainTopicField').addEventListener('click', function() {
            const container = document.getElementById('mainTopicsContainer');
            const newField = container.children[0].cloneNode(true);
            newField.querySelectorAll('input').forEach(input => input.value = '');
            newField.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
            container.appendChild(newField);
        });

        const addSubTopicBtn = document.getElementById('addSubTopicField');
        if (addSubTopicBtn) {
            addSubTopicBtn.addEventListener('click', function() {
                const container = document.getElementById('subTopicsContainer');
                const newField = container.children[0].cloneNode(true);
                newField.querySelectorAll('input').forEach(input => input.value = '');
                newField.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
                container.appendChild(newField);
            });
        }

        function toggleEdit(id) {
            const editForm = document.getElementById('topic-edit-' + id);
            if (editForm) editForm.classList.toggle('hidden');
        }

        function toggleSubEdit(id) {
            const editForm = document.getElementById('subtopic-edit-' + id);
            if (editForm) editForm.classList.toggle('hidden');
        }

        const selectAllCheckbox = document.getElementById('selectAllTopics');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function(e) {
                document.querySelectorAll('.topic-checkbox').forEach(cb => cb.checked = e.target.checked);
            });
        }

        function submitBulkDelete() {
            const checked = document.querySelectorAll('.topic-checkbox:checked');
            if (checked.length === 0) {
                alert('Please select at least one topic to delete.');
                return;
            }
            if (confirm('Are you sure you want to delete ' + checked.length + ' topic(s) and their sub-topics?')) {
                const form = document.getElementById('bulkDeleteForm');
                form.innerHTML = '<input type="hidden" name="bulk_delete_main_topics" value="1">';
                checked.forEach(cb => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_topics[]';
                    input.value = cb.value;
                    form.appendChild(input);
                });
                form.submit();
            }
        }
    </script>
</body>
</html>