<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle Bio Update (Must be before any HTML output/includes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_bio_from_list'])) {
    try {
        $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if bio column exists first
        $checkColumn = $db->query("SHOW COLUMNS FROM students LIKE 'bio'");
        if ($checkColumn->rowCount() > 0) {
            $student_id_to_update = $_POST['student_id'];
            $new_bio = trim($_POST['bio']);
            $update_stmt = $db->prepare("UPDATE students SET bio = ? WHERE student_id = ?");
            $update_stmt->execute([$new_bio, $student_id_to_update]);
        }
        
        // Redirect to same page to prevent form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } catch(PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Handle Bulk Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_students'])) {
    try {
        $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $selectedStudents = $_POST['selected_students'];
        $bulkAction = $_POST['bulk_action'];
        
        if (!empty($selectedStudents) && is_array($selectedStudents)) {
            $placeholders = implode(',', array_fill(0, count($selectedStudents), '?'));
            
            if ($bulkAction === 'delete') {
                // Delete selected students
                $stmt = $db->prepare("DELETE FROM students WHERE student_id IN ($placeholders)");
                $stmt->execute($selectedStudents);
                $_SESSION['bulk_message'] = count($selectedStudents) . ' students deleted successfully!';
            } elseif ($bulkAction === 'export') {
                // Export selected students as CSV
                $stmt = $db->prepare("SELECT * FROM students WHERE student_id IN ($placeholders)");
                $stmt->execute($selectedStudents);
                $studentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Set headers for CSV download
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=students_export_' . date('Y-m-d') . '.csv');
                
                $output = fopen('php://output', 'w');
                // Add headers
                if (!empty($studentsData)) {
                    fputcsv($output, array_keys($studentsData[0]));
                    foreach ($studentsData as $row) {
                        fputcsv($output, $row);
                    }
                }
                fclose($output);
                exit;
            } elseif ($bulkAction === 'status_active') {
                $stmt = $db->prepare("UPDATE students SET current_status = 'active' WHERE student_id IN ($placeholders)");
                $stmt->execute($selectedStudents);
                $_SESSION['bulk_message'] = count($selectedStudents) . ' students marked as Active!';
            } elseif ($bulkAction === 'status_on_hold') {
                $stmt = $db->prepare("UPDATE students SET current_status = 'on hold' WHERE student_id IN ($placeholders)");
                $stmt->execute($selectedStudents);
                $_SESSION['bulk_message'] = count($selectedStudents) . ' students marked as On Hold!';
            } elseif ($bulkAction === 'status_dropped') {
                $stmt = $db->prepare("UPDATE students SET current_status = 'dropped' WHERE student_id IN ($placeholders)");
                $stmt->execute($selectedStudents);
                $_SESSION['bulk_message'] = count($selectedStudents) . ' students marked as Dropped!';
            } elseif ($bulkAction === 'status_completed') {
                $stmt = $db->prepare("UPDATE students SET current_status = 'completed' WHERE student_id IN ($placeholders)");
                $stmt->execute($selectedStudents);
                $_SESSION['bulk_message'] = count($selectedStudents) . ' students marked as Completed!';
            } elseif ($bulkAction === 'status_transferred') {
                $stmt = $db->prepare("UPDATE students SET current_status = 'transferred' WHERE student_id IN ($placeholders)");
                $stmt->execute($selectedStudents);
                $_SESSION['bulk_message'] = count($selectedStudents) . ' students marked as Transferred!';
            }
        }
        
        // Redirect to same page to prevent form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } catch(PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

include '../header.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if bio column exists
    $checkColumn = $db->query("SHOW COLUMNS FROM students LIKE 'bio'");
    $hasBioColumn = $checkColumn->rowCount() > 0;
    
    // Initialize filter variables
    $nameFilter = isset($_GET['name']) ? $_GET['name'] : '';
    $batchFilter = isset($_GET['batch']) ? $_GET['batch'] : '';
    $statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
    $courseFilter = isset($_GET['course']) ? $_GET['course'] : '';
    $enrollmentDateFrom = isset($_GET['enrollment_from']) ? $_GET['enrollment_from'] : '';
    $enrollmentDateTo = isset($_GET['enrollment_to']) ? $_GET['enrollment_to'] : '';
    $stateFilter = isset($_GET['state']) ? $_GET['state'] : '';
    
    // Sorting variables
    $sortField = isset($_GET['sort']) ? $_GET['sort'] : 's.first_name';
    $sortOrder = isset($_GET['order']) ? $_GET['order'] : 'ASC';
    
    // Validate sort field to prevent SQL injection
    $allowedSortFields = ['s.student_id', 'course_name', 's.first_name', 's.email', 'b.batch_id', 'b.batch_name', 's.current_status', 's.state'];
    if (!in_array($sortField, $allowedSortFields)) {
        $sortField = 's.first_name';
    }
    
    // Validate sort order
    $sortOrder = strtoupper($sortOrder);
    if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
        $sortOrder = 'ASC';
    }
    
    // Pagination variables
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    
    // Base query for counting total records
    $countQuery = "
        SELECT COUNT(DISTINCT s.student_id) as total
        FROM students s
        LEFT JOIN batches b ON s.batch_name = b.batch_id
        LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
        LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
        LEFT JOIN batches b4 ON s.batch_name_4 = b4.batch_id
        LEFT JOIN courses c ON s.course = c.id
        WHERE 1=1
    ";
    
    // Base query for fetching data - conditionally include bio column
    if ($hasBioColumn) {
        $query = "
            SELECT s.student_id, s.course, c.name as course_name, s.first_name, s.last_name, s.email, s.phone_number, 
                   s.date_of_birth, s.enrollment_date, s.current_status, s.state, s.bio,
                   s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4,
                   b.batch_id as batch1_id, b.batch_name as batch1_name,
                   b2.batch_id as batch2_id, b2.batch_name as batch2_name,
                   b3.batch_id as batch3_id, b3.batch_name as batch3_name,
                   b4.batch_id as batch4_id, b4.batch_name as batch4_name
            FROM students s
            LEFT JOIN batches b ON s.batch_name = b.batch_id
            LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
            LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
            LEFT JOIN batches b4 ON s.batch_name_4 = b4.batch_id
            LEFT JOIN courses c ON s.course = c.id
            WHERE 1=1
        ";
    } else {
        $query = "
            SELECT s.student_id, s.course, c.name as course_name, s.first_name, s.last_name, s.email, s.phone_number, 
                   s.date_of_birth, s.enrollment_date, s.current_status, s.state,
                   '' as bio,
                   s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4,
                   b.batch_id as batch1_id, b.batch_name as batch1_name,
                   b2.batch_id as batch2_id, b2.batch_name as batch2_name,
                   b3.batch_id as batch3_id, b3.batch_name as batch3_name,
                   b4.batch_id as batch4_id, b4.batch_name as batch4_name
            FROM students s
            LEFT JOIN batches b ON s.batch_name = b.batch_id
            LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
            LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
            LEFT JOIN batches b4 ON s.batch_name_4 = b4.batch_id
            LEFT JOIN courses c ON s.course = c.id
            WHERE 1=1
        ";
    }
    
    // Apply filters to both queries
    if (!empty($nameFilter)) {
        $query .= " AND (s.first_name LIKE :name OR s.last_name LIKE :name)";
        $countQuery .= " AND (s.first_name LIKE :name OR s.last_name LIKE :name)";
    }
    if (!empty($batchFilter)) {
        $query .= " AND (s.batch_name = :batch OR s.batch_name_2 = :batch OR s.batch_name_3 = :batch OR s.batch_name_4 = :batch)";
        $countQuery .= " AND (s.batch_name = :batch OR s.batch_name_2 = :batch OR s.batch_name_3 = :batch OR s.batch_name_4 = :batch)";
    }
    if (!empty($statusFilter)) {
        $query .= " AND s.current_status = :status";
        $countQuery .= " AND s.current_status = :status";
    }
    if (!empty($courseFilter)) {
        $query .= " AND (b.batch_name = :course OR b2.batch_name = :course OR b3.batch_name = :course OR b4.batch_name = :course)";
        $countQuery .= " AND (b.batch_name = :course OR b2.batch_name = :course OR b3.batch_name = :course OR b4.batch_name = :course)";
    }
    if (!empty($enrollmentDateFrom)) {
        $query .= " AND s.enrollment_date >= :enrollment_from";
        $countQuery .= " AND s.enrollment_date >= :enrollment_from";
    }
    if (!empty($enrollmentDateTo)) {
        $query .= " AND s.enrollment_date <= :enrollment_to";
        $countQuery .= " AND s.enrollment_date <= :enrollment_to";
    }
    if (!empty($stateFilter)) {
        $query .= " AND s.state LIKE :state";
        $countQuery .= " AND s.state LIKE :state";
    }
    
    $query .= " ORDER BY $sortField $sortOrder LIMIT :limit OFFSET :offset";
    
    // First get total count
    $countStmt = $db->prepare($countQuery);
    
    // Bind parameters to count query
    if (!empty($nameFilter)) {
        $countStmt->bindValue(':name', '%' . $nameFilter . '%');
    }
    if (!empty($batchFilter)) {
        $countStmt->bindValue(':batch', $batchFilter);
    }
    if (!empty($statusFilter)) {
        $countStmt->bindValue(':status', $statusFilter);
    }
    if (!empty($courseFilter)) {
        $countStmt->bindValue(':course', $courseFilter);
    }
    if (!empty($enrollmentDateFrom)) {
        $countStmt->bindValue(':enrollment_from', $enrollmentDateFrom);
    }
    if (!empty($enrollmentDateTo)) {
        $countStmt->bindValue(':enrollment_to', $enrollmentDateTo);
    }
    if (!empty($stateFilter)) {
        $countStmt->bindValue(':state', '%' . $stateFilter . '%');
    }
    
    $countStmt->execute();
    $totalResults = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalResults / $perPage);
    
    // Now get paginated data
    $stmt = $db->prepare($query);
    
    // Bind parameters to data query
    if (!empty($nameFilter)) {
        $stmt->bindValue(':name', '%' . $nameFilter . '%');
    }
    if (!empty($batchFilter)) {
        $stmt->bindValue(':batch', $batchFilter);
    }
    if (!empty($statusFilter)) {
        $stmt->bindValue(':status', $statusFilter);
    }
    if (!empty($courseFilter)) {
        $stmt->bindValue(':course', $courseFilter);
    }
    if (!empty($enrollmentDateFrom)) {
        $stmt->bindValue(':enrollment_from', $enrollmentDateFrom);
    }
    if (!empty($enrollmentDateTo)) {
        $stmt->bindValue(':enrollment_to', $enrollmentDateTo);
    }
    if (!empty($stateFilter)) {
        $stmt->bindValue(':state', '%' . $stateFilter . '%');
    }
    
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get distinct values for filters
    $batchStmt = $db->query("SELECT DISTINCT batch_id, batch_name FROM batches ORDER BY batch_name");
    $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $statusStmt = $db->query("SELECT DISTINCT current_status FROM students");
    $statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $courseStmt = $db->query("SELECT DISTINCT batch_name FROM batches ORDER BY batch_name");
    $courses = $courseStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get distinct states for filter dropdown
    $stateStmt = $db->query("SELECT DISTINCT state FROM students WHERE state IS NOT NULL AND state != '' ORDER BY state");
    $states = $stateStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get student counts by status
    $statusCountQuery = "SELECT current_status, COUNT(*) as count FROM students GROUP BY current_status";
    $statusCountStmt = $db->query($statusCountQuery);
    $statusCounts = $statusCountStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialize counts
    $totalStudents = 0;
    $activeStudents = 0;
    $droppedStudents = 0;
    $inactiveStudents = 0;
    $completedStudents = 0;
    $transferredStudents = 0;
    
    // Calculate counts
    foreach ($statusCounts as $statusCount) {
        $totalStudents += $statusCount['count'];
        switch ($statusCount['current_status']) {
            case 'active':
                $activeStudents = $statusCount['count'];
                break;
            case 'dropped':
                $droppedStudents = $statusCount['count'];
                break;
            case 'on hold':
                $inactiveStudents = $statusCount['count'];
                break;
            case 'completed':
                $completedStudents = $statusCount['count'];
                break;
            case 'transferred':
                $transferredStudents = $statusCount['count'];
                break;
        }
    }
    
    // Get bulk message from session
    $bulkMessage = isset($_SESSION['bulk_message']) ? $_SESSION['bulk_message'] : '';
    if ($bulkMessage) {
        unset($_SESSION['bulk_message']);
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&display=swap" rel="stylesheet">
    <style>
        /* ================================================================
           CSS VARIABLES - THEME COLORS
           ================================================================ */
        :root {
            --color-1: #1B3C53;
            --color-2: #234C6A;
            --color-3: #456882;
            --color-4: #D2C1B6;
            
            --bg-body: #f0ebe6;
            --bg-card: rgba(255, 255, 255, 0.85);
            --bg-card-solid: #ffffff;
            --bg-input: rgba(255, 255, 255, 0.9);
            --bg-table-header: #f8f5f2;
            --bg-table-stripe: #faf8f6;
            --bg-hover: rgba(27, 60, 83, 0.04);
            --bg-sidebar: transparent;
            --bg-header: rgba(255, 255, 255, 0.8);
            
            --text-primary: #1B3C53;
            --text-secondary: #456882;
            --text-muted: #7a8f9f;
            --text-white: #ffffff;
            --text-sidebar: #1B3C53;
            
            --border-color: rgba(210, 193, 182, 0.3);
            --border-hover: rgba(35, 76, 106, 0.2);
            
            --shadow-sm: 0 2px 8px rgba(27, 60, 83, 0.06);
            --shadow-md: 0 8px 30px rgba(27, 60, 83, 0.08);
            --shadow-lg: 0 20px 60px rgba(27, 60, 83, 0.12);
            
            --accent-primary: #1B3C53;
            --accent-secondary: #234C6A;
            --accent-tertiary: #456882;
            --accent-beige: #D2C1B6;
            
            --gradient-primary: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --gradient-secondary: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --gradient-warm: linear-gradient(135deg, #D2C1B6 0%, #c4a882 100%);
            --gradient-glass: linear-gradient(135deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0.1) 100%);
            
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-xl: 32px;
        }

        /* ================================================================
           BASE
           ================================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

       
        /* ================================================================
           HEADER - GLASS
           ================================================================ */
        .header-glass {
            background: var(--bg-header);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 0.75rem 1.5rem;
            transition: all 0.4s ease;
            position: sticky;
            top: 0;
            z-index: 30;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            margin: 0 1rem;
        }

        /* ================================================================
           MAIN CONTENT
           ================================================================ */
        .main-content {
            margin-left: 280px;
            padding: 1.5rem 2rem 2rem;
            min-height: 100vh;
            background: var(--bg-body);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            .header-glass {
                margin: 0 0.5rem;
                border-radius: 0 0 var(--radius-md) var(--radius-md);
            }
        }

        /* ================================================================
           KPI CARDS
           ================================================================ */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }

        .kpi-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gradient-glass);
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .kpi-card:hover {
            transform: translateY(-6px) scale(1.01);
            box-shadow: var(--shadow-lg);
            border-color: var(--accent-primary);
        }

        .kpi-card:hover::before {
            opacity: 1;
        }

        .kpi-card .kpi-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            position: relative;
            z-index: 1;
        }

        .kpi-card .kpi-value {
            font-size: 2.25rem;
            font-weight: 900;
            color: var(--text-primary);
            line-height: 1.1;
            margin-top: 0.25rem;
            position: relative;
            z-index: 1;
        }

        .kpi-card .kpi-icon {
            width: 3.25rem;
            height: 3.25rem;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            background: var(--gradient-primary);
            color: white;
            flex-shrink: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            z-index: 1;
            box-shadow: 0 4px 12px rgba(27,60,83,0.2);
        }

        .kpi-card:hover .kpi-icon {
            transform: scale(1.1) rotate(-5deg) translateY(-2px);
            box-shadow: 0 8px 24px rgba(27,60,83,0.3);
        }

        /* ================================================================
           FILTER SECTION
           ================================================================ */
        .filter-section {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem 1.75rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.75rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .filter-section:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--border-hover);
        }

        .filter-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            user-select: none;
            transition: all 0.3s ease;
        }

        .filter-toggle:hover {
            color: var(--accent-primary);
        }

        .filter-toggle .chevron {
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .filter-toggle.collapsed .chevron {
            transform: rotate(-90deg);
        }

        .filter-body {
            transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.4s ease;
            overflow: hidden;
            max-height: 2000px;
        }

        .filter-body.collapsed {
            max-height: 0 !important;
            opacity: 0;
            padding-top: 0;
            margin-top: 0;
        }

        /* ================================================================
           FORM CONTROLS
           ================================================================ */
        .form-control-custom {
            width: 100%;
            padding: 0.7rem 1rem;
            border-radius: var(--radius-sm);
            border: 2px solid var(--border-color);
            background: var(--bg-input);
            backdrop-filter: blur(10px);
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            outline: none;
        }

        .form-control-custom:focus {
            border-color: var(--accent-secondary);
            box-shadow: 0 0 0 4px rgba(35,76,106,0.12);
            transform: translateY(-1px);
        }

        .form-control-custom::placeholder {
            color: var(--text-muted);
        }

        .form-select-custom {
            width: 100%;
            padding: 0.7rem 1rem;
            border-radius: var(--radius-sm);
            border: 2px solid var(--border-color);
            background: var(--bg-input);
            backdrop-filter: blur(10px);
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            outline: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23456882' stroke-width='2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 12px;
            cursor: pointer;
        }

        .form-select-custom:focus {
            border-color: var(--accent-secondary);
            box-shadow: 0 0 0 4px rgba(35,76,106,0.12);
            transform: translateY(-1px);
        }

        /* ================================================================
           BUTTONS
           ================================================================ */
        .btn-primary-custom {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.7rem 1.75rem;
            border-radius: var(--radius-sm);
            background: var(--gradient-primary);
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 16px rgba(27,60,83,0.25);
            position: relative;
            overflow: hidden;
        }

        .btn-primary-custom::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.6s ease;
        }

        .btn-primary-custom:hover::after {
            left: 100%;
        }

        .btn-primary-custom:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 28px rgba(27,60,83,0.35);
        }

        .btn-secondary-custom {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.7rem 1.75rem;
            border-radius: var(--radius-sm);
            background: transparent;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.9rem;
            border: 2px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-secondary-custom:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            background: var(--bg-hover);
            border-color: var(--accent-primary);
        }

        /* ================================================================
           FILTER BADGES
           ================================================================ */
        .filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 2px 12px rgba(27,60,83,0.2);
            transition: all 0.3s ease;
            animation: fadeInScale 0.3s ease;
        }

        .filter-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(27,60,83,0.3);
        }

        .filter-badge .remove-filter {
            cursor: pointer;
            opacity: 0.7;
            transition: all 0.2s ease;
            padding: 0 0.25rem;
        }

        .filter-badge .remove-filter:hover {
            opacity: 1;
            transform: scale(1.2);
        }

        /* ================================================================
           TABLE
           ================================================================ */
        .table-container {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin-bottom: 1.75rem;
        }

        .table-container:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--border-hover);
        }

        .student-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .student-table thead th {
            background: var(--bg-table-header);
            color: var(--text-secondary);
            font-weight: 700;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 1rem 1.25rem;
            text-align: left;
            border-bottom: 2px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .student-table thead th:hover {
            color: var(--accent-primary);
            background: var(--bg-hover);
        }

        .student-table thead th i {
            opacity: 0.3;
            transition: opacity 0.3s ease;
        }

        .student-table thead th:hover i {
            opacity: 0.8;
        }

        .student-table tbody td {
            padding: 1rem 1.25rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .student-table tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 3px solid transparent;
        }

        .student-table tbody tr:hover {
            background: var(--bg-hover);
            border-left-color: var(--accent-primary);
            transform: scale(1.002);
        }

        .student-table tbody tr:nth-child(even) {
            background: var(--bg-table-stripe);
        }

        .student-table tbody tr:nth-child(even):hover {
            background: var(--bg-hover);
        }

        .student-table tbody tr.selected {
            background: rgba(27,60,83,0.08) !important;
            border-left-color: var(--accent-primary);
        }

        /* ================================================================
           STATUS BADGES
           ================================================================ */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 1rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
        }

        .status-badge .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
            animation: pulseDot 2s ease-in-out infinite;
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.3); }
        }

        .status-badge.active {
            background: rgba(74,124,89,0.12);
            color: #4a7c59;
            border-color: rgba(74,124,89,0.2);
        }
        .status-badge.active .dot { background: #4a7c59; }

        .status-badge.completed {
            background: rgba(107,91,122,0.12);
            color: #6b5b7a;
            border-color: rgba(107,91,122,0.2);
        }
        .status-badge.completed .dot { background: #6b5b7a; }

        .status-badge.dropped {
            background: rgba(165,82,74,0.12);
            color: #a5524a;
            border-color: rgba(165,82,74,0.2);
        }
        .status-badge.dropped .dot { background: #a5524a; }

        .status-badge.on-hold {
            background: rgba(184,148,74,0.12);
            color: #b8944a;
            border-color: rgba(184,148,74,0.2);
        }
        .status-badge.on-hold .dot { background: #b8944a; }

        .status-badge.transferred {
            background: rgba(35,76,106,0.12);
            color: #234C6A;
            border-color: rgba(35,76,106,0.2);
        }
        .status-badge.transferred .dot { background: #234C6A; }

        .status-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        /* ================================================================
           BATCH CHIPS
           ================================================================ */
        .batch-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.8rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--gradient-primary);
            color: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(27,60,83,0.15);
        }

        .batch-chip:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 4px 16px rgba(27,60,83,0.25);
        }

        /* ================================================================
           COURSE PILL
           ================================================================ */
        .course-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 1rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            background: var(--gradient-secondary);
            color: white;
            box-shadow: 0 2px 12px rgba(35,76,106,0.2);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .course-pill:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 4px 20px rgba(35,76,106,0.3);
        }

        /* ================================================================
           ACTION BUTTONS
           ================================================================ */
        .action-btn {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            color: var(--text-muted);
            text-decoration: none;
            position: relative;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 9999px;
            background: currentColor;
            opacity: 0;
            transition: opacity 0.3s ease;
            transform: scale(0.8);
        }

        .action-btn:hover::before {
            opacity: 0.1;
            transform: scale(1);
        }

        .action-btn:hover {
            transform: translateY(-3px) scale(1.1);
        }

        .action-btn:active {
            transform: scale(0.9);
        }

        .action-btn.text-blue-600 { color: var(--accent-secondary); }
        .action-btn.text-blue-600:hover { color: var(--accent-primary); }

        .action-btn.text-amber-600 { color: #b8944a; }
        .action-btn.text-amber-600:hover { color: #a07d3a; }

        .action-btn.text-purple-600 { color: #6b5b7a; }
        .action-btn.text-purple-600:hover { color: #5a4b69; }

        .action-btn.text-indigo-600 { color: var(--accent-tertiary); }
        .action-btn.text-indigo-600:hover { color: var(--accent-secondary); }

        .action-btn.text-red-500 { color: #a5524a; }
        .action-btn.text-red-500:hover { color: #8a443d; }

        .action-btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: var(--accent-primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 600;
            white-space: nowrap;
            box-shadow: var(--shadow-md);
            z-index: 10;
        }

        /* ================================================================
           BULK ACTION BAR
           ================================================================ */
        .bulk-action-bar {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.75rem;
            display: none;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .bulk-action-bar.visible {
            display: flex;
        }

        .bulk-action-bar .selected-count {
            font-weight: 600;
            color: var(--text-primary);
        }

        .bulk-action-bar .selected-count span {
            color: var(--accent-secondary);
        }

        .bulk-select-all {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .bulk-select-all:hover {
            color: var(--accent-primary);
        }

        /* ================================================================
           PAGINATION
           ================================================================ */
        .pagination-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.75rem;
            height: 2.75rem;
            padding: 0 0.75rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            text-decoration: none;
        }

        .pagination-btn:hover {
            background: var(--gradient-primary);
            color: white;
            border-color: var(--accent-primary);
            transform: translateY(-3px);
            box-shadow: 0 4px 16px rgba(27,60,83,0.25);
        }

        .pagination-btn.active {
            background: var(--gradient-primary);
            color: white;
            border-color: var(--accent-primary);
            box-shadow: 0 4px 16px rgba(27,60,83,0.25);
        }

        .pagination-btn.ellipsis {
            border: none;
            background: transparent;
            cursor: default;
        }

        .pagination-btn.ellipsis:hover {
            transform: none;
            box-shadow: none;
            background: transparent;
            color: var(--text-secondary);
        }

        /* ================================================================
           EMPTY STATE
           ================================================================ */
        .empty-state {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 5rem 2rem;
            text-align: center;
            box-shadow: var(--shadow-sm);
            transition: all 0.4s ease;
        }

        .empty-state:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--border-hover);
        }

        .empty-icon {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            display: inline-block;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        /* ================================================================
           MOBILE CARDS
           ================================================================ */
        @media (max-width: 1024px) {
            .desktop-table { display: none; }
            .mobile-cards { display: flex; flex-direction: column; gap: 1rem; }
        }

        @media (min-width: 1025px) {
            .mobile-cards { display: none; }
        }

        .student-mobile-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .student-mobile-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
            border-color: var(--accent-primary);
        }

        .student-mobile-card.selected {
            background: rgba(27,60,83,0.06);
            border-color: var(--accent-primary);
        }

        /* ================================================================
           BACK TO TOP
           ================================================================ */
        #backToTop {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 9999px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            box-shadow: 0 4px 20px rgba(27,60,83,0.3);
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px) scale(0.8);
        }

        #backToTop.visible {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        #backToTop:hover {
            transform: scale(1.1) translateY(-4px);
            box-shadow: 0 8px 32px rgba(27,60,83,0.4);
        }

        #backToTop:active {
            transform: scale(0.9);
        }

        /* ================================================================
           ANIMATIONS
           ================================================================ */
        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .student-table tbody tr {
            animation: slideUp 0.4s ease forwards;
            opacity: 0;
        }

        .student-table tbody tr:nth-child(1) { animation-delay: 0.02s; }
        .student-table tbody tr:nth-child(2) { animation-delay: 0.04s; }
        .student-table tbody tr:nth-child(3) { animation-delay: 0.06s; }
        .student-table tbody tr:nth-child(4) { animation-delay: 0.08s; }
        .student-table tbody tr:nth-child(5) { animation-delay: 0.10s; }
        .student-table tbody tr:nth-child(6) { animation-delay: 0.12s; }
        .student-table tbody tr:nth-child(7) { animation-delay: 0.14s; }
        .student-table tbody tr:nth-child(8) { animation-delay: 0.16s; }
        .student-table tbody tr:nth-child(9) { animation-delay: 0.18s; }
        .student-table tbody tr:nth-child(10) { animation-delay: 0.20s; }

        /* ================================================================
           NOTIFICATION
           ================================================================ */
        .notification {
            position: fixed;
            top: 2rem;
            right: 2rem;
            z-index: 999;
            padding: 1rem 1.5rem;
            border-radius: var(--radius-md);
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-lg);
            transform: translateX(120%);
            transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            font-weight: 600;
            max-width: 400px;
        }

        .notification.show {
            transform: translateX(0);
        }

        /* ================================================================
           SCROLLBAR
           ================================================================ */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: var(--text-muted); border-radius: 10px; transition: background 0.3s ease; }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent-secondary); }

        /* ================================================================
           RESPONSIVE
           ================================================================ */
        @media (max-width: 768px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .main-content { padding: 0.75rem; }
            .filter-section { padding: 1rem; }
            .kpi-card { padding: 1rem; }
            .kpi-card .kpi-value { font-size: 1.75rem; }
            .bulk-action-bar { flex-direction: column; align-items: stretch; }
        }

        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .header-glass { padding: 0.5rem 1rem; }
        }

        /* ================================================================
           BIO MODAL
           ================================================================ */
        .modal-overlay {
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-xl);
            max-width: 500px;
            width: 100%;
            margin: 2rem auto;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideUp 0.4s ease;
        }

        .modal-card textarea {
            background: var(--bg-input);
            backdrop-filter: blur(10px);
            color: var(--text-primary);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.75rem 1rem;
            width: 100%;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            resize: vertical;
        }

        .modal-card textarea:focus {
            border-color: var(--accent-secondary);
            box-shadow: 0 0 0 4px rgba(35,76,106,0.15);
            outline: none;
            transform: translateY(-1px);
        }

        .modal-card .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
        }

        .modal-card .modal-body {
            padding: 1.5rem;
        }

        .modal-card .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            background: var(--bg-table-stripe);
            border-radius: 0 0 var(--radius-md) var(--radius-md);
        }
    </style>
</head>
<body>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header-glass">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <button class="md:hidden text-2xl text-[var(--text-secondary)] hover:text-[var(--accent-primary)] transition-all duration-300" onclick="toggleSidebarMobile()" aria-label="Toggle sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <div class="h-12 w-12 rounded-xl bg-gradient-to-br from-[#1B3C53] to-[#234C6A] flex items-center justify-center shadow-lg shadow-[#1B3C53]/25">
                            <i class="fas fa-users text-white text-xl"></i>
                        </div>
                        <div>
                            <nav class="text-xs font-semibold text-[var(--text-secondary)]">
                                <a href="#" class="hover:text-[var(--accent-primary)] transition-colors">Dashboard</a>
                                <span class="mx-1 text-[var(--text-muted)]">/</span>
                                <span class="text-[var(--text-primary)]">Students</span>
                            </nav>
                            <h1 class="text-2xl font-extrabold text-[var(--text-primary)] tracking-tight">
                                Student Directory
                            </h1>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="bg-[var(--accent-secondary)]/10 text-[var(--accent-secondary)] text-sm font-semibold px-4 py-2 rounded-full border border-[var(--accent-secondary)]/20 backdrop-blur-sm">
                        <i class="fas fa-users mr-1.5"></i> <?= $totalStudents ?>
                    </span>
                    <a href="drop_list.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[var(--bg-card)] border border-[var(--border-color)] text-[#a5524a] font-medium transition-all duration-300 hover:bg-red-50 hover:shadow-md hover:-translate-y-0.5">
                        <i class="fas fa-user-minus"></i> <span class="hidden sm:inline">Drop List</span>
                    </a>
                    <a href="add_student.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-[#1B3C53] to-[#234C6A] text-white shadow-md shadow-[#1B3C53]/25 hover:shadow-lg hover:shadow-[#1B3C53]/30 transition-all duration-300 hover:-translate-y-0.5">
                        <i class="fas fa-plus-circle"></i> <span class="hidden sm:inline">Add Student</span>
                    </a>
                </div>
            </div>
        </header>

        <div class="py-6 max-w-[1640px] mx-auto">
            <!-- Notification -->
            <?php if (!empty($bulkMessage)): ?>
            <div id="bulkNotification" class="notification show">
                <i class="fas fa-check-circle mr-2"></i> <?= htmlspecialchars($bulkMessage) ?>
            </div>
            <script>
                setTimeout(() => {
                    document.getElementById('bulkNotification')?.classList.remove('show');
                }, 4000);
            </script>
            <?php endif; ?>

            <!-- KPI Cards -->
            <div class="kpi-grid">
                <a href="students_list.php?page=1&sort=s.first_name&order=ASC" class="kpi-card">
                    <div>
                        <div class="kpi-label"><i class="fas fa-users mr-1.5"></i>Total Students</div>
                        <div class="kpi-value"><?= $totalStudents ?></div>
                    </div>
                    <div class="kpi-icon"><i class="fas fa-users"></i></div>
                </a>
                <a href="students_list.php?page=1&sort=s.first_name&order=ASC&status=active" class="kpi-card">
                    <div>
                        <div class="kpi-label"><i class="fas fa-user-check mr-1.5"></i>Active</div>
                        <div class="kpi-value" style="color: #4a7c59;"><?= $activeStudents ?></div>
                    </div>
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #4a7c59, #6b9e7a);"><i class="fas fa-user-check"></i></div>
                </a>
                <a href="students_list.php?page=1&sort=s.first_name&order=ASC&status=completed" class="kpi-card">
                    <div>
                        <div class="kpi-label"><i class="fas fa-user-graduate mr-1.5"></i>Completed</div>
                        <div class="kpi-value" style="color: #6b5b7a;"><?= $completedStudents ?></div>
                    </div>
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #6b5b7a, #8b7b9a);"><i class="fas fa-user-graduate"></i></div>
                </a>
                <a href="drop_list.php" class="kpi-card">
                    <div>
                        <div class="kpi-label"><i class="fas fa-user-times mr-1.5"></i>Dropped</div>
                        <div class="kpi-value" style="color: #a5524a;"><?= $droppedStudents ?></div>
                    </div>
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #a5524a, #c97a72);"><i class="fas fa-user-times"></i></div>
                </a>
                <a href="inactive_students.php" class="kpi-card">
                    <div>
                        <div class="kpi-label"><i class="fas fa-user-clock mr-1.5"></i>Inactive</div>
                        <div class="kpi-value" style="color: #b8944a;"><?= $inactiveStudents ?></div>
                    </div>
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #b8944a, #d4b06a);"><i class="fas fa-user-clock"></i></div>
                </a>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="flex items-center gap-3 mb-4 pb-3 border-b border-[var(--border-color)] filter-toggle" onclick="toggleFilter()" role="button" aria-expanded="true">
                    <i class="fas fa-sliders-h text-[var(--accent-secondary)] text-lg"></i>
                    <h2 class="text-xl font-semibold text-[var(--text-primary)]">Advanced Filters</h2>
                    <span class="ml-auto text-xs font-medium text-[var(--text-secondary)] bg-[var(--bg-body)] px-3 py-1.5 rounded-full backdrop-blur-sm"><?= $totalResults ?> results</span>
                    <span class="ml-2 text-sm text-[var(--text-secondary)]"><i class="fas fa-chevron-down chevron transition-transform duration-400"></i></span>
                </div>
                <div id="filterBody">
                    <!-- Active Filters -->
                    <div id="activeFilters" class="flex flex-wrap gap-2 mb-4">
                        <?php
                        $hasFilter = false;
                        if (!empty($nameFilter)) {
                            echo '<span class="filter-badge"><i class="fas fa-user"></i> Name: ' . htmlspecialchars($nameFilter) . ' <span class="remove-filter" onclick="removeFilter(\'name\')">&times;</span></span>';
                            $hasFilter = true;
                        }
                        if (!empty($batchFilter)) {
                            echo '<span class="filter-badge"><i class="fas fa-layer-group"></i> Batch: ' . htmlspecialchars($batchFilter) . ' <span class="remove-filter" onclick="removeFilter(\'batch\')">&times;</span></span>';
                            $hasFilter = true;
                        }
                        if (!empty($statusFilter)) {
                            echo '<span class="filter-badge"><i class="fas fa-flag-checkered"></i> Status: ' . ucfirst(htmlspecialchars($statusFilter)) . ' <span class="remove-filter" onclick="removeFilter(\'status\')">&times;</span></span>';
                            $hasFilter = true;
                        }
                        if (!empty($courseFilter)) {
                            echo '<span class="filter-badge"><i class="fas fa-book-open"></i> Course: ' . htmlspecialchars($courseFilter) . ' <span class="remove-filter" onclick="removeFilter(\'course\')">&times;</span></span>';
                            $hasFilter = true;
                        }
                        if (!empty($enrollmentDateFrom) || !empty($enrollmentDateTo)) {
                            $dateRange = (!empty($enrollmentDateFrom) ? htmlspecialchars($enrollmentDateFrom) : '…') . ' → ' . (!empty($enrollmentDateTo) ? htmlspecialchars($enrollmentDateTo) : '…');
                            echo '<span class="filter-badge"><i class="fas fa-calendar-alt"></i> Enrollment: ' . $dateRange . ' <span class="remove-filter" onclick="removeFilter(\'enrollment\')">&times;</span></span>';
                            $hasFilter = true;
                        }
                        if (!empty($stateFilter)) {
                            echo '<span class="filter-badge"><i class="fas fa-map-marker-alt"></i> State: ' . htmlspecialchars($stateFilter) . ' <span class="remove-filter" onclick="removeFilter(\'state\')">&times;</span></span>';
                            $hasFilter = true;
                        }
                        if (!$hasFilter) {
                            echo '<span class="text-sm text-[var(--text-muted)] italic flex items-center gap-2"><i class="fas fa-info-circle"></i> No active filters</span>';
                        }
                        ?>
                        <?php if ($hasFilter): ?>
                        <span class="filter-badge" style="background: var(--text-muted); cursor:pointer;" onclick="resetFilters()"><i class="fas fa-times-circle"></i> Clear all</span>
                        <?php endif; ?>
                    </div>

                    <form id="filterForm" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <input type="hidden" name="page" value="1">
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sortField) ?>">
                        <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">

                        <div>
                            <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1.5"><i class="fas fa-user mr-1.5 text-[var(--accent-secondary)]"></i>Name</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($nameFilter) ?>" placeholder="Search by name..." class="form-control-custom">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1.5"><i class="fas fa-layer-group mr-1.5 text-[var(--accent-secondary)]"></i>Batch</label>
                            <select name="batch" class="form-select-custom">
                                <option value="">All Batches</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $batchFilter == $batch['batch_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id']) ?> - <?= htmlspecialchars($batch['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1.5"><i class="fas fa-flag-checkered mr-1.5 text-[var(--accent-secondary)]"></i>Status</label>
                            <select name="status" class="form-select-custom">
                                <option value="">All Status</option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter == $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1.5"><i class="fas fa-book-open mr-1.5 text-[var(--accent-secondary)]"></i>Course</label>
                            <select name="course" class="form-select-custom">
                                <option value="">All Courses</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= htmlspecialchars($course) ?>" <?= $courseFilter == $course ? 'selected' : '' ?>><?= htmlspecialchars($course) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1.5"><i class="fas fa-map-marker-alt mr-1.5 text-[var(--accent-secondary)]"></i>State</label>
                            <select name="state" class="form-select-custom">
                                <option value="">All States</option>
                                <?php foreach ($states as $state): ?>
                                    <option value="<?= htmlspecialchars($state) ?>" <?= $stateFilter == $state ? 'selected' : '' ?>><?= htmlspecialchars($state) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1.5"><i class="fas fa-calendar-alt mr-1.5 text-[var(--accent-secondary)]"></i>Enrollment From</label>
                            <input type="date" name="enrollment_from" value="<?= htmlspecialchars($enrollmentDateFrom) ?>" class="form-control-custom">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1.5"><i class="fas fa-calendar-check mr-1.5 text-[var(--accent-secondary)]"></i>Enrollment To</label>
                            <input type="date" name="enrollment_to" value="<?= htmlspecialchars($enrollmentDateTo) ?>" class="form-control-custom">
                        </div>
                        <div class="flex items-end gap-3">
                            <button type="submit" class="btn-primary-custom">
                                <i class="fas fa-search"></i> Apply
                            </button>
                            <button type="button" onclick="resetFilters()" class="btn-secondary-custom">
                                <i class="fas fa-undo-alt"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (count($students) > 0): ?>
            <!-- Bulk Action Form -->
            <form id="bulkActionForm" method="POST" onsubmit="return confirmBulkAction()">
                <!-- Bulk Action Bar -->
                <div id="bulkActionBar" class="bulk-action-bar">
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()" class="w-4 h-4 rounded border-[var(--border-color)] text-[var(--accent-primary)] focus:ring-[var(--accent-secondary)]">
                            <span class="text-sm font-medium text-[var(--text-secondary)]">Select All</span>
                        </label>
                        <span class="selected-count">Selected: <span id="selectedCount">0</span> students</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select name="bulk_action" id="bulkActionSelect" class="form-select-custom text-sm py-1.5 px-3" style="width: auto; min-width: 150px;">
                            <option value="">Bulk Actions</option>
                            <option value="delete">🗑️ Delete Selected</option>
                            <option value="export">📥 Export Selected</option>
                            <option value="status_active">✅ Mark as Active</option>
                            <option value="status_on_hold">⏸️ Mark as On Hold</option>
                            <option value="status_dropped">❌ Mark as Dropped</option>
                            <option value="status_completed">🎓 Mark as Completed</option>
                            <option value="status_transferred">🔄 Mark as Transferred</option>
                        </select>
                        <button type="submit" class="btn-primary-custom text-sm py-1.5 px-4">
                            <i class="fas fa-play"></i> Apply
                        </button>
                        <button type="button" onclick="clearSelection()" class="btn-secondary-custom text-sm py-1.5 px-4">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </div>

                <!-- Desktop Table -->
                <div class="desktop-table table-container">
                    <div class="overflow-x-auto">
                        <table class="student-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAllCheckboxDesktop" onchange="toggleSelectAllDesktop()" class="w-4 h-4 rounded border-[var(--border-color)] text-[var(--accent-primary)] focus:ring-[var(--accent-secondary)]">
                                    </th>
                                    <th onclick="sortTable('s.student_id')">Student ID <i class="fas fa-sort text-xs"></i></th>
                                    <th onclick="sortTable('s.first_name')">Name <i class="fas fa-sort text-xs"></i></th>
                                    <th onclick="sortTable('s.email')">Email <i class="fas fa-sort text-xs"></i></th>
                                    <th onclick="sortTable('course_name')">Course <i class="fas fa-sort text-xs"></i></th>
                                    <th>Batches</th>
                                    <th onclick="sortTable('s.state')">State <i class="fas fa-sort text-xs"></i></th>
                                    <th onclick="sortTable('s.current_status')">Status <i class="fas fa-sort text-xs"></i></th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr id="row-<?= htmlspecialchars($student['student_id']) ?>">
                                        <td>
                                            <input type="checkbox" name="selected_students[]" value="<?= htmlspecialchars($student['student_id']) ?>" class="student-checkbox w-4 h-4 rounded border-[var(--border-color)] text-[var(--accent-primary)] focus:ring-[var(--accent-secondary)]" onchange="updateBulkBar()">
                                        </td>
                                        <td class="font-mono text-sm font-semibold text-[var(--accent-secondary)]"><?= htmlspecialchars($student['student_id']) ?></td>
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <div class="h-10 w-10 rounded-full bg-gradient-to-br from-[#1B3C53] to-[#234C6A] flex items-center justify-center text-white font-bold shadow-md shadow-[#1B3C53]/20">
                                                    <?= strtoupper(substr($student['first_name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="font-semibold text-[var(--text-primary)] whitespace-nowrap"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></div>
                                                    <div class="text-xs text-[var(--text-muted)]"><i class="fas fa-phone"></i><?= htmlspecialchars($student['phone_number']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-sm text-[var(--text-secondary)]"><?= htmlspecialchars($student['email']) ?></td>
                                        <td><span class="course-pill"><i class="fas fa-graduation-cap text-[10px] mr-1"></i> <?= htmlspecialchars($student['course_name']) ?></span></td>
                                        <td>
                                            <div class="flex flex-wrap gap-1.5">
                                                <?php 
                                                $batchNames = [];
                                                if (!empty($student['batch1_name'])) $batchNames[] = $student['batch1_name'];
                                                if (!empty($student['batch2_name'])) $batchNames[] = $student['batch2_name'];
                                                if (!empty($student['batch3_name'])) $batchNames[] = $student['batch3_name'];
                                                if (!empty($student['batch4_name'])) $batchNames[] = $student['batch4_name'];
                                                
                                                if (!empty($batchNames)): 
                                                    foreach ($batchNames as $batchName):
                                                ?>
                                                    <span class="batch-chip whitespace-nowrap"><?= htmlspecialchars($batchName) ?></span>
                                                <?php 
                                                    endforeach;
                                                else: 
                                                ?>
                                                    <span class="text-xs text-[var(--text-muted)] italic">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-sm text-[var(--text-secondary)]"><?= htmlspecialchars($student['state'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php
                                                $statusClass = match($student['current_status']) {
                                                    'active' => 'active', 'completed' => 'completed', 'dropped' => 'dropped',
                                                    'on hold' => 'on-hold', 'transferred' => 'transferred', default => ''
                                                };
                                            ?>
                                            <span class="status-badge <?= $statusClass ?>"><span class="dot"></span> <?= ucfirst($student['current_status']) ?></span>
                                        </td>
                                        <td>
                                            <div class="flex justify-center gap-0.5">
                                                <a href="student_view.php?id=<?= $student['student_id'] ?>" class="action-btn text-blue-600" title="View Profile"><i class="fas fa-eye"></i></a>
                                                <a href="edit_student.php?id=<?= $student['student_id'] ?>" class="action-btn text-amber-600" title="Edit Student"><i class="fas fa-edit"></i></a>
                                                <?php if ($hasBioColumn): ?>
                                                <button type="button" onclick="openBioModal('<?= htmlspecialchars($student['student_id']) ?>', `<?= htmlspecialchars($student['bio'] ?? '', ENT_QUOTES) ?>`)" class="action-btn text-purple-600" title="Add/Edit Bio">
                                                    <i class="fas fa-comment-dots"></i>
                                                </button>
                                                <?php endif; ?>
                                                <a href="monthly_report_card.php?student_id=<?= $student['student_id'] ?>&month=<?= date('Y-m') ?>" class="action-btn text-indigo-600" title="Monthly Report Card">
                                                    <i class="fas fa-chart-line"></i>
                                                </a>
                                                <a href="delete_student.php?id=<?= $student['student_id'] ?>" class="action-btn text-red-500" onclick="return confirm('Permanently delete this student?')" title="Delete Student"><i class="fas fa-trash-alt"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="px-6 py-4 flex flex-wrap items-center justify-between gap-4 border-t border-[var(--border-color)]">
                        <div class="text-sm text-[var(--text-secondary)]">
                            Showing <span class="font-medium text-[var(--text-primary)]"><?= $offset+1 ?></span> – <span class="font-medium text-[var(--text-primary)]"><?= min($offset+$perPage, $totalResults) ?></span> of <span class="font-medium text-[var(--text-primary)]"><?= $totalResults ?></span>
                        </div>
                        <div class="flex gap-1 flex-wrap">
                            <?php if ($page > 1): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i></a>
                            <?php endif; ?>
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            if ($start > 1) {
                                echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="pagination-btn">1</a>';
                                if ($start > 2) echo '<span class="pagination-btn ellipsis">…</span>';
                            }
                            for ($i = $start; $i <= $end; $i++) {
                                $active = $i == $page ? 'active' : '';
                                echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '" class="pagination-btn ' . $active . '">' . $i . '</a>';
                            }
                            if ($end < $totalPages) {
                                if ($end < $totalPages - 1) echo '<span class="pagination-btn ellipsis">…</span>';
                                echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . '" class="pagination-btn">' . $totalPages . '</a>';
                            }
                            ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>" class="pagination-btn"><i class="fas fa-chevron-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Mobile Cards -->
            <div class="mobile-cards">
                <?php foreach ($students as $student): ?>
                    <div class="student-mobile-card" id="mobile-<?= htmlspecialchars($student['student_id']) ?>">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <input type="checkbox" name="selected_students[]" value="<?= htmlspecialchars($student['student_id']) ?>" class="student-checkbox-mobile w-4 h-4 rounded border-[var(--border-color)] text-[var(--accent-primary)] focus:ring-[var(--accent-secondary)]" onchange="updateBulkBarMobile()">
                                <div class="h-11 w-11 rounded-full bg-gradient-to-br from-[#1B3C53] to-[#234C6A] flex items-center justify-center text-white font-bold shadow-md shadow-[#1B3C53]/20 text-lg">
                                    <?= strtoupper(substr($student['first_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="font-bold text-[var(--text-primary)]"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></div>
                                    <div class="text-xs text-[var(--text-muted)]"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($student['email']) ?></div>
                                </div>
                            </div>
                            <?php
                                $statusClass = match($student['current_status']) {
                                    'active' => 'active', 'completed' => 'completed', 'dropped' => 'dropped',
                                    'on hold' => 'on-hold', 'transferred' => 'transferred', default => ''
                                };
                            ?>
                            <span class="status-badge <?= $statusClass ?>"><span class="dot"></span> <?= ucfirst($student['current_status']) ?></span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-sm text-[var(--text-secondary)]">
                            <div><span class="font-semibold text-[var(--text-primary)]">ID:</span> <?= htmlspecialchars($student['student_id']) ?></div>
                            <div><span class="font-semibold text-[var(--text-primary)]">Course:</span> <?= htmlspecialchars($student['course_name']) ?></div>
                            <div><span class="font-semibold text-[var(--text-primary)]">State:</span> <?= htmlspecialchars($student['state'] ?? 'N/A') ?></div>
                            <div><span class="font-semibold text-[var(--text-primary)]">Phone:</span> <i class="fas fa-phone"></i><?= htmlspecialchars($student['phone_number']) ?></div>
                            <?php 
                            $batchNames = [];
                            if (!empty($student['batch1_name'])) $batchNames[] = $student['batch1_name'];
                            if (!empty($student['batch2_name'])) $batchNames[] = $student['batch2_name'];
                            if (!empty($student['batch3_name'])) $batchNames[] = $student['batch3_name'];
                            if (!empty($student['batch4_name'])) $batchNames[] = $student['batch4_name'];
                            
                            if (!empty($batchNames)): 
                            ?>
                                <div class="col-span-2"><span class="font-semibold text-[var(--text-primary)]">Batches:</span> 
                                    <?php foreach ($batchNames as $batchName): ?>
                                        <span class="batch-chip text-xs whitespace-nowrap"><?= htmlspecialchars($batchName) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex justify-end gap-3 mt-3 pt-3 border-t border-[var(--border-color)]">
                            <a href="student_view.php?id=<?= $student['student_id'] ?>" class="text-[var(--accent-secondary)] text-sm font-semibold transition-colors hover:text-[var(--accent-primary)]"><i class="fas fa-eye mr-1"></i>View</a>
                            <a href="edit_student.php?id=<?= $student['student_id'] ?>" class="text-amber-600 text-sm font-semibold transition-colors hover:text-amber-700"><i class="fas fa-edit mr-1"></i>Edit</a>
                            <?php if ($hasBioColumn): ?>
                            <button type="button" onclick="openBioModal('<?= htmlspecialchars($student['student_id']) ?>', `<?= htmlspecialchars($student['bio'] ?? '', ENT_QUOTES) ?>`)" class="text-purple-600 text-sm font-semibold transition-colors hover:text-purple-700" style="background:none;border:none;cursor:pointer;"><i class="fas fa-comment-dots mr-1"></i>Bio</button>
                            <?php endif; ?>
                            <a href="monthly_report_card.php?student_id=<?= $student['student_id'] ?>&month=<?= date('Y-m') ?>" class="text-indigo-600 text-sm font-semibold transition-colors hover:text-indigo-700"><i class="fas fa-chart-line mr-1"></i>Report</a>
                            <a href="delete_student.php?id=<?= $student['student_id'] ?>" class="text-red-500 text-sm font-semibold transition-colors hover:text-red-600" onclick="return confirm('Permanently delete?')"><i class="fas fa-trash-alt mr-1"></i></a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($totalPages > 1): ?>
                <div class="flex justify-center gap-1 mt-4">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <span class="px-4 py-2 text-sm font-medium text-[var(--text-secondary)] bg-[var(--bg-card)] rounded-xl border border-[var(--border-color)] backdrop-blur-sm">Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>" class="pagination-btn"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-user-graduate"></i></div>
                <h2 class="text-2xl font-extrabold mt-4 mb-2 text-[var(--text-primary)]">No students found</h2>
                <p class="text-[var(--text-secondary)] max-w-md mx-auto mb-6">No students match your filters. Try adjusting your search or add a new student.</p>
                <a href="add_student.php" class="btn-primary-custom"><i class="fas fa-plus-circle"></i> Add Student</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($hasBioColumn): ?>
    <!-- Bio Modal -->
    <div id="editBioModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen px-4 py-8">
            <div class="fixed inset-0 modal-overlay" aria-hidden="true" onclick="closeBioModal()"></div>
            <div class="modal-card relative">
                <form action="" method="POST">
                    <input type="hidden" name="update_bio_from_list" value="1">
                    <input type="hidden" name="student_id" id="bio_student_id" value="">
                    
                    <div class="modal-header">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-full bg-purple-100 flex items-center justify-center text-purple-600" style="background: rgba(107,91,122,0.12);">
                                <i class="fas fa-comment-dots text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-[var(--text-primary)]" id="modal-title">
                                    Edit Student Bio
                                </h3>
                                <p class="text-sm text-[var(--text-muted)]">Add progress notes or remarks</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-body">
                        <textarea id="bio_textarea" name="bio" rows="6" placeholder="Enter remarks or progress about the student..." class="w-full resize-y"></textarea>
                    </div>
                    
                    <div class="modal-footer flex justify-end gap-3">
                        <button type="button" onclick="closeBioModal()" class="btn-secondary-custom">
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary-custom" style="background: linear-gradient(135deg, #6b5b7a, #8b7b9a); box-shadow: 0 4px 16px rgba(107,91,122,0.25);">
                            <i class="fas fa-save"></i> Save Bio
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Back to Top -->
    <button id="backToTop" onclick="window.scrollTo({top:0,behavior:'smooth'})">
        <i class="fas fa-chevron-up"></i>
    </button>

    <script>
        // ================================================================
        // BULK SELECTION FUNCTIONS
        // ================================================================
        function updateBulkBar() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            const selected = document.querySelectorAll('.student-checkbox:checked');
            const count = selected.length;
            
            document.getElementById('selectedCount').textContent = count;
            
            const bar = document.getElementById('bulkActionBar');
            if (count > 0) {
                bar.classList.add('visible');
            } else {
                bar.classList.remove('visible');
            }
            
            // Update row styling
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                if (row) {
                    if (cb.checked) {
                        row.classList.add('selected');
                    } else {
                        row.classList.remove('selected');
                    }
                }
            });
            
            // Update select all checkbox
            const total = checkboxes.length;
            const checked = document.querySelectorAll('.student-checkbox:checked').length;
            const selectAllDesktop = document.getElementById('selectAllCheckboxDesktop');
            const selectAll = document.getElementById('selectAllCheckbox');
            
            if (total > 0 && checked === total) {
                selectAllDesktop.checked = true;
                selectAll.checked = true;
            } else {
                selectAllDesktop.checked = false;
                selectAll.checked = false;
            }
        }

        function updateBulkBarMobile() {
            const checkboxes = document.querySelectorAll('.student-checkbox-mobile');
            const selected = document.querySelectorAll('.student-checkbox-mobile:checked');
            const count = selected.length;
            
            document.getElementById('selectedCount').textContent = count;
            
            const bar = document.getElementById('bulkActionBar');
            if (count > 0) {
                bar.classList.add('visible');
            } else {
                bar.classList.remove('visible');
            }
            
            // Update mobile card styling
            checkboxes.forEach(cb => {
                const card = cb.closest('.student-mobile-card');
                if (card) {
                    if (cb.checked) {
                        card.classList.add('selected');
                    } else {
                        card.classList.remove('selected');
                    }
                }
            });
            
            // Sync desktop checkboxes
            const desktopCheckboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach((cb, index) => {
                if (desktopCheckboxes[index]) {
                    desktopCheckboxes[index].checked = cb.checked;
                }
            });
            
            // Update select all
            const total = checkboxes.length;
            const checked = document.querySelectorAll('.student-checkbox-mobile:checked').length;
            const selectAllDesktop = document.getElementById('selectAllCheckboxDesktop');
            const selectAll = document.getElementById('selectAllCheckbox');
            
            if (total > 0 && checked === total) {
                selectAllDesktop.checked = true;
                selectAll.checked = true;
            } else {
                selectAllDesktop.checked = false;
                selectAll.checked = false;
            }
        }

        function toggleSelectAll() {
            const checked = document.getElementById('selectAllCheckbox').checked;
            const checkboxes = document.querySelectorAll('.student-checkbox');
            const mobileCheckboxes = document.querySelectorAll('.student-checkbox-mobile');
            
            checkboxes.forEach(cb => cb.checked = checked);
            mobileCheckboxes.forEach(cb => cb.checked = checked);
            
            updateBulkBar();
            updateBulkBarMobile();
        }

        function toggleSelectAllDesktop() {
            const checked = document.getElementById('selectAllCheckboxDesktop').checked;
            const checkboxes = document.querySelectorAll('.student-checkbox');
            const mobileCheckboxes = document.querySelectorAll('.student-checkbox-mobile');
            
            checkboxes.forEach(cb => cb.checked = checked);
            mobileCheckboxes.forEach(cb => cb.checked = checked);
            
            updateBulkBar();
            updateBulkBarMobile();
        }

        function clearSelection() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
            document.querySelectorAll('.student-checkbox-mobile').forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheckbox').checked = false;
            document.getElementById('selectAllCheckboxDesktop').checked = false;
            updateBulkBar();
            updateBulkBarMobile();
        }

        function confirmBulkAction() {
            const action = document.getElementById('bulkActionSelect').value;
            const selected = document.querySelectorAll('.student-checkbox:checked');
            
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }
            
            if (selected.length === 0) {
                alert('Please select at least one student.');
                return false;
            }
            
            if (action === 'delete') {
                return confirm('Are you sure you want to permanently delete ' + selected.length + ' students? This action cannot be undone!');
            }
            
            return confirm('Are you sure you want to apply "' + action.replace('_', ' ') + '" to ' + selected.length + ' students?');
        }

        // ================================================================
        // FILTER TOGGLE
        // ================================================================
        let filterCollapsed = false;

        function toggleFilter() {
            const body = document.getElementById('filterBody');
            const toggle = document.querySelector('.filter-toggle');
            filterCollapsed = !filterCollapsed;
            if (filterCollapsed) {
                body.classList.add('collapsed');
                toggle.classList.add('collapsed');
                toggle.setAttribute('aria-expanded', 'false');
            } else {
                body.classList.remove('collapsed');
                toggle.classList.remove('collapsed');
                toggle.setAttribute('aria-expanded', 'true');
            }
        }

        // ================================================================
        // REMOVE FILTER
        // ================================================================
        function removeFilter(name) {
            const url = new URL(window.location);
            if (name === 'enrollment') {
                url.searchParams.delete('enrollment_from');
                url.searchParams.delete('enrollment_to');
            } else {
                url.searchParams.delete(name);
            }
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        // ================================================================
        // RESET FILTERS
        // ================================================================
        function resetFilters() {
            const form = document.getElementById('filterForm');
            form.reset();
            const url = new URL(window.location);
            const sort = url.searchParams.get('sort') || 's.first_name';
            const order = url.searchParams.get('order') || 'ASC';
            window.location.href = '?' + new URLSearchParams({ page: '1', sort, order }).toString();
        }

        // ================================================================
        // SORT TABLE
        // ================================================================
        function sortTable(field) {
            const url = new URL(window.location);
            let currentSort = url.searchParams.get('sort') || 's.first_name';
            let currentOrder = url.searchParams.get('order') || 'ASC';
            if (currentSort === field) currentOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
            else currentOrder = 'ASC';
            url.searchParams.set('sort', field);
            url.searchParams.set('order', currentOrder);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        // ================================================================
        // BIO MODAL
        // ================================================================
        <?php if ($hasBioColumn): ?>
        function openBioModal(studentId, currentBio) {
            document.getElementById('bio_student_id').value = studentId;
            document.getElementById('bio_textarea').value = currentBio || '';
            document.getElementById('editBioModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeBioModal() {
            document.getElementById('editBioModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeBioModal();
            }
        });
        <?php endif; ?>

        // ================================================================
        // BACK TO TOP
        // ================================================================
        window.addEventListener('scroll', function() {
            const btn = document.getElementById('backToTop');
            if (window.scrollY > 600) {
                btn.classList.add('visible');
            } else {
                btn.classList.remove('visible');
            }
        });

        // ================================================================
        // SIDEBAR TOGGLE
        // ================================================================
        function toggleSidebarMobile() {
            const sidebar = document.querySelector('aside, .sidebar');
            if (sidebar) {
                sidebar.classList.toggle('hidden');
                sidebar.classList.toggle('block');
            }
        }

        // ================================================================
        // KEYBOARD ACCESS
        // ================================================================
        document.querySelector('.filter-toggle')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleFilter();
            }
        });

        // ================================================================
        // INITIALIZE BULK BAR
        // ================================================================
        document.addEventListener('DOMContentLoaded', function() {
            updateBulkBar();
            updateBulkBarMobile();
        });
    </script>
</body>
</html>