<?php
// ============================================================
// ENABLE ERROR REPORTING - ADD THIS AT THE VERY TOP
// ============================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ============================================================
// FIX: Start session and handle headers BEFORE any output
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection with error handling
$db_connection_file = '../db_connection.php';
if (!file_exists($db_connection_file)) {
    die('ERROR: Database connection file not found at: ' . $db_connection_file);
}

require_once $db_connection_file;

// Verify database connection
if (!isset($db) || !($db instanceof PDO)) {
    die('ERROR: Database connection is not properly initialized.');
}

// Function to generate default SVG thumbnails if they don't exist
function ensure_dummy_images() {
    $dirs = ['../uploads/course_thumbnails/', '../uploads/batch_thumbnails/'];
    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
    }
    
    $default_course = '../uploads/course_thumbnails/default_course.svg';
    $default_batch = '../uploads/batch_thumbnails/default_batch.svg';
    
    if (!file_exists($default_course)) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" width="100%" height="100%">
  <defs>
    <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#4361ee;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#3f37c9;stop-opacity:1" />
    </linearGradient>
  </defs>
  <rect width="800" height="600" fill="url(#grad1)" />
  <circle cx="400" cy="300" r="200" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="30" />
  <text x="50%" y="48%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="\'Segoe UI\', Roboto, sans-serif" font-weight="bold" font-size="54">ASD ACADEMY</text>
  <text x="50%" y="58%" dominant-baseline="middle" text-anchor="middle" fill="rgba(255,255,255,0.8)" font-family="\'Segoe UI\', Roboto, sans-serif" font-size="24">Course Thumbnail</text>
</svg>';
        file_put_contents($default_course, $svg);
    }
    
    if (!file_exists($default_batch)) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" width="100%" height="100%">
  <defs>
    <linearGradient id="grad2" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#7209b7;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#5c0099;stop-opacity:1" />
    </linearGradient>
  </defs>
  <rect width="800" height="600" fill="url(#grad2)" />
  <circle cx="400" cy="300" r="200" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="30" />
  <text x="50%" y="48%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="\'Segoe UI\', Roboto, sans-serif" font-weight="bold" font-size="54">ASD ACADEMY</text>
  <text x="50%" y="58%" dominant-baseline="middle" text-anchor="middle" fill="rgba(255,255,255,0.8)" font-family="\'Segoe UI\', Roboto, sans-serif" font-size="24">Batch Thumbnail</text>
</svg>';
        file_put_contents($default_batch, $svg);
    }
}
ensure_dummy_images();

// ============================================================
// AUTH CHECK
// ============================================================
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// PAGINATION CONFIGURATION
// ============================================================
// Get records per page from session or use default
if (!isset($_SESSION['records_per_page'])) {
    $_SESSION['records_per_page'] = 5;
}

// Allow user to change records per page
if (isset($_POST['records_per_page'])) {
    $_SESSION['records_per_page'] = (int)$_POST['records_per_page'];
    // Redirect to remove POST data and prevent resubmission
    header("Location: index.php?page=1");
    exit;
}

$records_per_page = $_SESSION['records_per_page'];
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$offset = ($page - 1) * $records_per_page;

// ============================================================
// CRUD HANDLERS
// ============================================================

// Handle Add Course
if (isset($_POST['add_course'])) {
    $name = trim($_POST['name'] ?? '');
    if (!empty($name)) {
        $thumbnail_path = 'uploads/course_thumbnails/default_course.svg';
        
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/course_thumbnails/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_extension = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
            $file_name = 'course_' . time() . '_' . rand(100, 999) . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_path)) {
                $thumbnail_path = 'uploads/course_thumbnails/' . $file_name;
            }
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO courses (name, thumbnail) VALUES (?, ?)");
            if ($stmt->execute([$name, $thumbnail_path])) {
                $_SESSION['success_message'] = "Course added successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to add course.";
            }
        } catch (PDOException $e) {
            error_log('Add Course Error: ' . $e->getMessage());
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Course name is required.";
    }
    header("Location: index.php?page=" . $page);
    exit;
}

// Handle Edit Course
if (isset($_POST['edit_course'])) {
    $course_id = (int)$_POST['course_id'];
    $name = trim($_POST['name'] ?? '');
    
    if (!empty($name)) {
        try {
            $stmt = $db->prepare("SELECT thumbnail FROM courses WHERE id = ?");
            $stmt->execute([$course_id]);
            $current_thumbnail = $stmt->fetchColumn();
            
            $thumbnail_path = $current_thumbnail;
            
            if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/course_thumbnails/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file_extension = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
                $file_name = 'course_' . $course_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_path)) {
                    if ($current_thumbnail && file_exists('../' . $current_thumbnail) && !str_contains($current_thumbnail, 'default_course')) {
                        unlink('../' . $current_thumbnail);
                    }
                    $thumbnail_path = 'uploads/course_thumbnails/' . $file_name;
                }
            }
            
            $stmt = $db->prepare("UPDATE courses SET name = ?, thumbnail = ? WHERE id = ?");
            if ($stmt->execute([$name, $thumbnail_path, $course_id])) {
                $_SESSION['success_message'] = "Course updated successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to update course.";
            }
        } catch (PDOException $e) {
            error_log('Edit Course Error: ' . $e->getMessage());
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Course name is required.";
    }
    header("Location: index.php?page=" . $page);
    exit;
}

// Handle Delete Course
if (isset($_POST['delete_course'])) {
    $course_id = (int)$_POST['course_id'];
    
    try {
        $check = $db->prepare("SELECT COUNT(*) FROM batch_courses WHERE course_id = ?");
        $check->execute([$course_id]);
        $is_used = $check->fetchColumn();
        
        if ($is_used > 0) {
            $_SESSION['error_message'] = "Cannot delete this course because it is assigned to one or more batches.";
        } else {
            $stmt = $db->prepare("SELECT thumbnail FROM courses WHERE id = ?");
            $stmt->execute([$course_id]);
            $current_thumbnail = $stmt->fetchColumn();
            
            $stmt = $db->prepare("DELETE FROM courses WHERE id = ?");
            if ($stmt->execute([$course_id])) {
                if ($current_thumbnail && file_exists('../' . $current_thumbnail) && !str_contains($current_thumbnail, 'default_course')) {
                    unlink('../' . $current_thumbnail);
                }
                $_SESSION['success_message'] = "Course deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to delete course.";
            }
        }
    } catch (PDOException $e) {
        error_log('Delete Course Error: ' . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    }
    header("Location: index.php?page=" . $page);
    exit;
}

// ============================================================
// FETCH COURSES WITH PAGINATION
// ============================================================

try {
    // Get total number of courses for pagination
    $total_stmt = $db->query("SELECT COUNT(*) FROM courses");
    $total_records = $total_stmt->fetchColumn();
    $total_pages = ceil($total_records / $records_per_page);
    
    // Ensure page doesn't exceed total pages
    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
        $offset = ($page - 1) * $records_per_page;
    }
    
    // Fetch courses with pagination
    $stmt = $db->prepare("SELECT * FROM courses ORDER BY name LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Fetch Courses Error: ' . $e->getMessage());
    $total_records = 0;
    $total_pages = 0;
    $courses = [];
    $_SESSION['error_message'] = "Error loading courses: " . $e->getMessage();
}

// ============================================================
// CREATE FALLBACK FILES IF THEY DON'T EXIST
// ============================================================
$content_file = '../content/upload_content.php';
$curriculum_file = 'manage_curriculum.php';

// Create content directory if it doesn't exist
if (!file_exists('../content')) {
    mkdir('../content', 0777, true);
}

// Create fallback content page if it doesn't exist
if (!file_exists($content_file)) {
    $fallback_content = '<?php
session_start();
if (!isset($_SESSION[\'user_id\']) || $_SESSION[\'user_role\'] !== \'admin\') {
    header("Location: ../login.php");
    exit;
}
$course_id = isset($_GET[\'course_id\']) ? (int)$_GET[\'course_id\'] : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Content Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f6f3f0; font-family: system-ui; }
        .container { max-width: 800px; margin: 50px auto; }
        .card { border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.08); border: none; }
        .card-header { background: linear-gradient(135deg, #1B3C53, #234C6A); color: white; border-radius: 20px 20px 0 0; }
        .btn-primary { background: #1B3C53; border: none; }
        .btn-primary:hover { background: #234C6A; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header p-4">
                <h4 class="mb-0"><i class="fas fa-file-alt me-2"></i>Content Management</h4>
            </div>
            <div class="card-body p-4 text-center">
                <i class="fas fa-upload fa-4x mb-4" style="color: #1B3C53;"></i>
                <h5 style="color: #1B3C53;">Upload Course Content</h5>
                <p class="text-muted">This feature is under development. Please check back later.</p>
                <p class="text-muted small">Course ID: <?= $course_id ?: "Not specified" ?></p>
                <a href="../courses/index.php" class="btn btn-primary mt-3"><i class="fas fa-arrow-left me-2"></i>Back to Courses</a>
            </div>
        </div>
    </div>
</body>
</html>';
    file_put_contents($content_file, $fallback_content);
}

// Create fallback curriculum page if it doesn't exist
if (!file_exists($curriculum_file)) {
    $fallback_curriculum = '<?php
session_start();
if (!isset($_SESSION[\'user_id\']) || $_SESSION[\'user_role\'] !== \'admin\') {
    header("Location: ../login.php");
    exit;
}
require_once "../db_connection.php";

$course_id = isset($_GET[\'course_id\']) ? (int)$_GET[\'course_id\'] : 0;
if (!$course_id) {
    header("Location: index.php");
    exit;
}

// Get course info
$stmt = $db->prepare("SELECT name FROM courses WHERE id = ?");
$stmt->execute([$course_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    header("Location: index.php");
    exit;
}

// Handle chapter addition
if (isset($_POST[\'add_chapter\'])) {
    $chapter_name = trim($_POST[\'chapter_name\'] ?? \'\');
    if (!empty($chapter_name)) {
        $stmt = $db->prepare("INSERT INTO chapters (course_id, name) VALUES (?, ?)");
        $stmt->execute([$course_id, $chapter_name]);
        $_SESSION[\'success_message\'] = "Chapter added successfully!";
        header("Location: manage_curriculum.php?course_id=" . $course_id);
        exit;
    }
}

// Handle chapter deletion
if (isset($_POST[\'delete_chapter\'])) {
    $chapter_id = (int)$_POST[\'chapter_id\'];
    $stmt = $db->prepare("DELETE FROM chapters WHERE id = ? AND course_id = ?");
    $stmt->execute([$chapter_id, $course_id]);
    $_SESSION[\'success_message\'] = "Chapter deleted successfully!";
    header("Location: manage_curriculum.php?course_id=" . $course_id);
    exit;
}

// Fetch chapters
$chapters = $db->prepare("SELECT * FROM chapters WHERE course_id = ? ORDER BY id");
$chapters->execute([$course_id]);
$chapters = $chapters->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Curriculum</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f6f3f0; font-family: system-ui; }
        .container { max-width: 900px; margin: 30px auto; }
        .card { border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.08); border: none; }
        .card-header { background: linear-gradient(135deg, #1B3C53, #234C6A); color: white; border-radius: 20px 20px 0 0; padding: 20px 25px; }
        .btn-primary { background: #1B3C53; border: none; }
        .btn-primary:hover { background: #234C6A; }
        .btn-outline-danger { border-color: #dc3545; color: #dc3545; }
        .btn-outline-danger:hover { background: #dc3545; color: white; }
        .table-hover tbody tr:hover { background: rgba(27, 60, 83, 0.05); }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-book me-2"></i>Manage Curriculum: <?= htmlspecialchars($course[\'name\']) ?></h4>
                <a href="index.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
            </div>
            <div class="card-body p-4">
                <?php if (isset($_SESSION[\'success_message\'])): ?>
                    <div class="alert alert-success"><?= $_SESSION[\'success_message\']; unset($_SESSION[\'success_message\']); ?></div>
                <?php endif; ?>
                
                <form method="POST" class="mb-4">
                    <div class="input-group">
                        <input type="text" class="form-control" name="chapter_name" placeholder="Enter chapter name" required>
                        <button type="submit" name="add_chapter" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Add Chapter</button>
                    </div>
                </form>
                
                <?php if (count($chapters) > 0): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Chapter Name</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($chapters as $index => $chapter): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($chapter[\'name\']) ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm(\'Delete this chapter?\');">
                                        <input type="hidden" name="chapter_id" value="<?= $chapter[\'id\'] ?>">
                                        <button type="submit" name="delete_chapter" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-book-open fa-3x mb-3 opacity-25"></i>
                        <p>No chapters added yet. Add your first chapter above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>';
    file_put_contents($curriculum_file, $fallback_curriculum);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Management · ASD Academy</title>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
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
            font-size: 1.25rem;
            letter-spacing: -0.01em;
        }

        .form-label {
            color: var(--dark-steel);
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
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
            transition: box-shadow 0.4s ease, transform 0.3s ease;
        }

        .card-brand::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--mid-steel);
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
        .card-brand.card-create {
            border-left: 4px solid var(--terracotta);
        }
        .card-brand.card-create::before {
            background: var(--terracotta);
        }
        .card-brand.card-delete {
            border-left: 4px solid var(--danger-red);
        }
        .card-brand.card-delete::before {
            background: var(--danger-red);
        }
        .card-brand.card-upload {
            border-left: 4px solid var(--upload-purple);
        }
        .card-brand.card-upload::before {
            background: var(--upload-purple);
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
        }

        /* ================================================================
           HEADER BAR
           ================================================================ */
        .header-bar {
            background: linear-gradient(90deg, var(--deepest-navy), var(--dark-steel), var(--mid-steel), var(--warm-sand));
            color: var(--white);
            padding: 1rem 2rem;
            box-shadow: 0 2px 16px rgba(27,60,83,.15);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .header-bar h1 {
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--white);
        }

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

        /* ================================================================
           TABLE
           ================================================================ */
        .table-wrapper {
            background: var(--white);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(27,60,83,.13);
        }

        .table-brand {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table-brand thead th {
            background: linear-gradient(90deg, var(--deepest-navy), var(--dark-steel), var(--mid-steel));
            color: var(--white);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.9rem 1.25rem;
            border: none;
        }

        .table-brand tbody td {
            padding: 0.9rem 1.25rem;
            vertical-align: middle;
            border-bottom: 1px solid rgba(210,193,182,.25);
        }

        .table-brand tbody tr:nth-child(even) {
            background: #f4ede7;
        }
        .table-brand tbody tr:hover {
            background: #e8dfd8;
        }
        .table-brand tbody tr:last-child td {
            border-bottom: none;
        }

        /* ================================================================
           COURSE NAME
           ================================================================ */
        .course-name {
            color: var(--deepest-navy);
            font-weight: 600;
            font-size: 1rem;
            word-break: break-word;
            line-height: 1.3;
        }

        /* ================================================================
           THUMBNAIL
           ================================================================ */
        .thumb-brand {
            border-radius: 12px;
            border: 2px solid rgba(210,193,182,.4);
            box-shadow: 0 4px 12px rgba(27,60,83,.08);
            object-fit: cover;
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .thumb-brand:hover {
            transform: scale(1.08);
            box-shadow: 0 8px 24px rgba(27,60,83,.15);
            border-color: var(--dark-steel);
        }

        /* ================================================================
           ACTION BUTTONS ROW
           ================================================================ */
        .action-row {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-wrap: nowrap;
        }

        .action-row form {
            display: inline-flex;
            margin: 0;
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
           BADGE
           ================================================================ */
        .badge-brand {
            background: var(--deepest-navy);
            color: var(--white);
            border-radius: 9999px;
            padding: 0.5rem 1.25rem;
            font-weight: 600;
            font-size: 0.85rem;
            box-shadow: 0 3px 10px rgba(27,60,83,.2);
        }

        /* ================================================================
           INPUTS
           ================================================================ */
        .input-brand {
            border-radius: 12px;
            border: 2px solid var(--warm-sand);
            padding: 0.65rem 1rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background: var(--white);
            color: var(--deepest-navy);
        }
        .input-brand:focus {
            border-color: var(--dark-steel);
            box-shadow: 0 0 0 4px rgba(35,76,106,.12);
            outline: none;
        }

        /* ================================================================
           MODAL
           ================================================================ */
        .modal-brand .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 30px 60px rgba(0,0,0,.25);
            overflow: hidden;
        }

        .modal-brand .modal-header {
            background: linear-gradient(135deg, var(--deepest-navy), var(--dark-steel), var(--mid-steel));
            color: var(--white);
            border-bottom: none;
            padding: 1.25rem 1.75rem;
        }

        .modal-brand .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        .modal-brand .modal-header .btn-close:hover {
            opacity: 1;
        }

        .modal-brand .modal-body {
            padding: 1.5rem 1.75rem;
        }

        .modal-brand .modal-footer {
            padding: 1rem 1.75rem 1.5rem 1.75rem;
            border-top: 1px solid rgba(210,193,182,.25);
        }

        /* ================================================================
           PAGINATION
           ================================================================ */
        .pagination-wrapper {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.25rem;
            background: rgba(248,245,242,.3);
            border-top: 1px solid rgba(210,193,182,.25);
        }

        .pagination-brand {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .pagination-brand .page-link {
            border-radius: 9999px;
            border: 2px solid rgba(210,193,182,.3);
            padding: 0.5rem 0.9rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--dark-steel);
            background: var(--white);
            transition: all 0.25s ease;
            text-decoration: none;
            min-width: 40px;
            text-align: center;
        }

        .pagination-brand .page-link:hover {
            background: var(--dark-steel);
            color: var(--white);
            border-color: var(--dark-steel);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(35,76,106,.25);
        }

        .pagination-brand .page-link.active {
            background: linear-gradient(135deg, var(--deepest-navy), var(--dark-steel));
            color: var(--white);
            border-color: var(--deepest-navy);
            box-shadow: 0 4px 14px rgba(27,60,83,.3);
            cursor: default;
        }

        .pagination-brand .page-link.disabled {
            opacity: 0.4;
            pointer-events: none;
            cursor: default;
        }

        .pagination-brand .page-info {
            color: var(--mid-steel);
            font-weight: 500;
            font-size: 0.85rem;
            padding: 0 0.75rem;
        }

        /* Records per page selector */
        .records-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .records-selector label {
            color: var(--mid-steel);
            font-weight: 600;
            font-size: 0.85rem;
            margin: 0;
        }

        .records-selector select {
            border-radius: 9999px;
            border: 2px solid rgba(210,193,182,.3);
            padding: 0.4rem 1.2rem 0.4rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--dark-steel);
            background: var(--white);
            cursor: pointer;
            outline: none;
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
            appearance: auto;
        }

        .records-selector select:hover {
            border-color: var(--dark-steel);
            box-shadow: 0 2px 8px rgba(35,76,106,.15);
        }

        .records-selector select:focus {
            border-color: var(--deepest-navy);
            box-shadow: 0 0 0 3px rgba(27,60,83,.1);
        }

        /* ================================================================
           RESPONSIVE
           ================================================================ */
        @media (max-width: 768px) {
            .action-row {
                flex-wrap: wrap;
                justify-content: flex-start;
                margin-top: 0.5rem;
            }
            .hero-banner {
                padding: 1rem 1.25rem;
            }
            .header-bar {
                padding: 0.75rem 1rem;
            }
            .btn-brand {
                font-size: 0.8rem;
                padding: 0.45rem 1rem;
            }
            .table-brand thead th,
            .table-brand tbody td {
                padding: 0.7rem 0.75rem;
                font-size: 0.8rem;
            }
            .pagination-brand .page-link {
                padding: 0.35rem 0.7rem;
                font-size: 0.75rem;
                min-width: 32px;
            }
            .pagination-wrapper {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
            }
            .records-selector {
                justify-content: center;
            }
            .pagination-brand {
                justify-content: center;
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
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <button class="d-md-none btn btn-link text-white p-2" onclick="toggleSidebar()">
                        <i class="fas fa-bars fa-lg"></i>
                    </button>
                    <span class="rounded-3 p-2.5 d-inline-flex align-items-center justify-content-center" style="background: rgba(255,255,255,.15);">
                        <i class="fas fa-graduation-cap text-white" style="font-size: 1.5rem;"></i>
                    </span>
                    <div>
                        <h1 class="mb-0" style="font-size: 1.5rem; font-weight: 800;">Course Management</h1>
                        <p class="mb-0 opacity-75" style="font-size: 0.85rem;">Create, edit and organise your courses</p>
                    </div>
                </div>
                <span class="badge-brand">
                    <i class="fas fa-layer-group me-1.5"></i> <?= $total_records ?> Courses
                </span>
            </div>
        </div>

        <div class="p-4 md:p-6 max-w-7xl mx-auto">
            <!-- Alerts -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success-brand alert-brand alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i> 
                    <?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error-brand alert-brand alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> 
                    <?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Add Course Card -->
                <div class="col-12 col-lg-5 col-xl-4">
                    <div class="card-brand card-create p-4 h-100">
                        <h3 class="section-heading mb-4 d-flex align-items-center gap-2">
                            <span class="rounded-3 p-2 d-inline-flex align-items-center justify-content-center" style="background: rgba(201,123,80,.12);">
                                <i class="fas fa-plus-circle" style="color: var(--terracotta); font-size: 1.25rem;"></i>
                            </span>
                            Create New Course
                        </h3>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="name" class="form-label">Course Name</label>
                                <input type="text" class="form-control input-brand" id="name" name="name" required placeholder="e.g. UI/UX Design">
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Thumbnail</label>
                                <input type="file" class="form-control input-brand" name="thumbnail" accept="image/*">
                                <small class="d-block mt-1.5" style="color: var(--mid-steel); font-size: 0.8rem;">
                                    <i class="far fa-image me-1"></i> PNG, JPG, SVG
                                </small>
                            </div>
                            <button type="submit" name="add_course" class="btn-brand btn-primary-brand w-100">
                                <i class="fas fa-plus me-2"></i> Create Course
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Courses List -->
                <div class="col-12 col-lg-7 col-xl-8">
                    <div class="card-brand p-0 h-100" style="border-left: 4px solid var(--mid-steel);">
                        <div class="p-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3" style="border-color: rgba(210,193,182,.25) !important; background: rgba(248,245,242,.3);">
                            <h3 class="section-heading mb-0 d-flex align-items-center gap-2">
                                <span class="rounded-3 p-2 d-inline-flex align-items-center justify-content-center" style="background: rgba(69,104,130,.12);">
                                    <i class="fas fa-book-open" style="color: var(--mid-steel); font-size: 1.25rem;"></i>
                                </span>
                                All Courses
                            </h3>
                            <span class="badge-brand">
                                <i class="fas fa-layer-group me-1.5"></i> <?= $total_records ?>
                            </span>
                        </div>

                        <div class="table-responsive">
                            <table class="table-brand mb-0">
                                <thead>
                                    <tr>
                                        <th>Course</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($courses) > 0): ?>
                                        <?php foreach ($courses as $course): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-3">
                                                    <?php 
                                                    $thumb = !empty($course['thumbnail']) ? '../' . $course['thumbnail'] : '../uploads/course_thumbnails/default_course.svg';
                                                    ?>
                                                    <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($course['name']) ?>" class="thumb-brand">
                                                    <span class="course-name"><?= htmlspecialchars($course['name']) ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-row">
                                                    <button type="button" class="btn-brand btn-secondary-brand btn-brand-sm edit-course-btn" 
                                                            data-id="<?= $course['id'] ?>" 
                                                            data-name="<?= htmlspecialchars($course['name']) ?>"
                                                            data-thumbnail="<?= htmlspecialchars($thumb) ?>"
                                                            data-bs-toggle="modal" data-bs-target="#editCourseModal">
                                                        <i class="fas fa-pen me-1"></i> Edit
                                                    </button>
                                                    <a href="../content/course_folder.php?course_id=<?= $course['id'] ?>" class="btn-brand btn-secondary-brand btn-brand-sm" style="text-decoration: none;">
                                                        <i class="fas fa-folder-open me-1"></i> Content
                                                    </a>
                                                    <a href="manage_curriculum.php?course_id=<?= $course['id'] ?>" class="btn-brand btn-secondary-brand btn-brand-sm" style="text-decoration: none;">
                                                        <i class="fas fa-sitemap me-1"></i> Curriculum
                                                    </a>
                                                    <form method="POST" onsubmit="return confirm('Delete this course permanently?');">
                                                        <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                                        <button type="submit" name="delete_course" class="btn-brand btn-danger-brand btn-brand-sm">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="2" class="text-center py-5" style="color: var(--mid-steel);">
                                                <i class="fas fa-folder-open d-block mb-3 opacity-25" style="font-size: 3.5rem; color: var(--warm-sand);"></i>
                                                <p class="mb-0" style="font-size: 1.1rem;">No courses yet. Create your first one!</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination with Records Per Page Selector -->
                        <?php if ($total_records > 0): ?>
                        <div class="pagination-wrapper">
                            <!-- Records per page selector -->
                            <div class="records-selector">
                                <label for="records_per_page"><i class="fas fa-list-ul me-1"></i> Show:</label>
                                <form method="POST" id="recordsPerPageForm" style="display: flex; align-items: center; gap: 0.5rem;">
                                    <select name="records_per_page" id="records_per_page" onchange="this.form.submit()">
                                        <option value="5" <?= $records_per_page == 5 ? 'selected' : '' ?>>5</option>
                                        <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10</option>
                                        <option value="20" <?= $records_per_page == 20 ? 'selected' : '' ?>>20</option>
                                        <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                                        <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100</option>
                                    </select>
                                </form>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <div class="pagination-brand">
                                <!-- Previous button -->
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?>" class="page-link" aria-label="Previous">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
                                <?php endif; ?>

                                <!-- Page numbers -->
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?page=1" class="page-link">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span class="page-link disabled">…</span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    $active = $i === $page ? 'active' : '';
                                    echo '<a href="?page=' . $i . '" class="page-link ' . $active . '">' . $i . '</a>';
                                }
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span class="page-link disabled">…</span>';
                                    }
                                    echo '<a href="?page=' . $total_pages . '" class="page-link">' . $total_pages . '</a>';
                                }
                                ?>

                                <!-- Next button -->
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?= $page + 1 ?>" class="page-link" aria-label="Next">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
                                <?php endif; ?>

                                <!-- Page info -->
                                <span class="page-info">
                                    Page <?= $page ?> of <?= $total_pages ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <div class="pagination-brand">
                                <span class="page-info">
                                    Showing <?= count($courses) ?> of <?= $total_records ?> courses
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade modal-brand" id="editCourseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                        <i class="fas fa-pen-fancy"></i> Edit Course
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="edit_course" value="1">
                    <input type="hidden" id="edit_course_id" name="course_id">

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Course Name</label>
                            <input type="text" class="form-control input-brand" id="edit_name" name="name" required>
                        </div>
                        <div>
                            <label class="form-label">Thumbnail</label>
                            <div class="d-flex align-items-center gap-3">
                                <img id="edit_thumbnail_preview" src="" alt="preview" class="thumb-brand" style="width: 72px; height: 72px;">
                                <div class="flex-grow-1">
                                    <input type="file" class="form-control input-brand" id="edit_thumbnail" name="thumbnail" accept="image/*" onchange="previewEditImage(this)">
                                    <div class="form-text mt-1" style="color: var(--mid-steel); font-size: 0.8rem;">
                                        <i class="fas fa-upload me-1"></i> Replace image (optional)
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-brand btn-secondary-brand" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-brand btn-success-brand">
                            <i class="fas fa-save me-2"></i> Update Course
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit modal data population
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.edit-course-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.dataset.id;
                    const name = this.dataset.name;
                    const thumb = this.dataset.thumbnail;
                    document.getElementById('edit_course_id').value = id;
                    document.getElementById('edit_name').value = name;
                    document.getElementById('edit_thumbnail_preview').src = thumb;
                });
            });
        });

        // Live preview for edit thumbnail
        function previewEditImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    document.getElementById('edit_thumbnail_preview').src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html>