<?php
// Main file: student_view.php
// This file displays a single student's profile, including personal details,
// attendance, exam results, and documents, with an enhanced, modern design.
// Updated to show data from all enrolled batches.

require_once '../db_connection.php';
session_start();

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Get student ID from URL
$student_id = isset($_GET['id']) ? $_GET['id'] : null;
$from_batch = isset($_GET['from_batch']) ? $_GET['from_batch'] : null;

// Redirect if student ID is missing
if (!$student_id) {
    header("Location: students_list.php");
    exit();
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch student details with profile picture
    $stmt = $conn->prepare("
        SELECT s.*, c.name as course
        FROM students s 
        LEFT JOIN courses c ON s.course = c.id
        WHERE s.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Redirect if student not found
    if (!$student) {
        header("Location: students_list.php");
        exit();
    }    
    
    // Get all batch IDs where student is enrolled
    $batch_ids = [];
    if (!empty($student['batch_name'])) $batch_ids[] = $student['batch_name'];
    if (!empty($student['batch_name_2'])) $batch_ids[] = $student['batch_name_2'];
    if (!empty($student['batch_name_3'])) $batch_ids[] = $student['batch_name_3'];
    $batch_ids = array_filter($batch_ids); // Remove empty values
    
    // Fetch batch details for all enrolled batches
    $all_batches = [];
    if (!empty($batch_ids)) {
        $placeholders = str_repeat('?,', count($batch_ids) - 1) . '?';
        $stmt = $conn->prepare("SELECT * FROM batches WHERE batch_id IN ($placeholders)");
        $stmt->execute($batch_ids);
        $all_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create a mapping of batch_id to batch details
        $batch_details = [];
        foreach ($all_batches as $batch) {
            $batch_details[$batch['batch_id']] = $batch;
        }
    }
    
    // Initialize arrays to store combined data from all batches
    $all_attendance = [];
    $all_exams = [];
    $attendance_stats = [];
    $batch_attendance_counts = [];
    
    // Process each batch
    foreach ($batch_ids as $batch_id) {
        // Fetch attendance records for this batch
        $stmt = $conn->prepare("
            SELECT *, ? as batch_id FROM attendance 
            WHERE student_name = ? AND batch_id = ? 
            ORDER BY date DESC
        ");
        $stmt->execute([$batch_id, $student['first_name'] . ' ' . $student['last_name'], $batch_id]);
        $batch_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add batch identifier and combine
        foreach ($batch_attendance as $record) {
            $record['batch_name'] = $batch_details[$batch_id]['batch_name'] ?? $batch_id;
            $all_attendance[] = $record;
        }
        
        // Calculate attendance stats for this batch
        $total_classes = count($batch_attendance);
        $present_count = 0;
        $absent_count = 0;
        $late_count = 0;
        
        foreach ($batch_attendance as $record) {
            if ($record['status'] === 'Present') $present_count++;
            elseif ($record['status'] === 'Absent') $absent_count++;
            elseif ($record['status'] === 'Late') $late_count++;
        }
        
        $present_percentage = $total_classes > 0 ? round(($present_count / $total_classes) * 100) : 0;
        $absent_percentage = $total_classes > 0 ? round(($absent_count / $total_classes) * 100) : 0;
        $late_percentage = $total_classes > 0 ? round(($late_count / $total_classes) * 100) : 0;
        
        $attendance_stats[$batch_id] = [
            'batch_name' => $batch_details[$batch_id]['batch_name'] ?? $batch_id,
            'total_classes' => $total_classes,
            'present_count' => $present_count,
            'absent_count' => $absent_count,
            'late_count' => $late_count,
            'present_percentage' => $present_percentage,
            'absent_percentage' => $absent_percentage,
            'late_percentage' => $late_percentage
        ];
        
        // Store attendance counts for this batch
        $batch_attendance_counts[$batch_id] = $total_classes;
        
        // Fetch exam results from exam_results table for this batch
        $stmt = $conn->prepare("
            SELECT e.*, er.obtained_marks, er.grade, er.remarks, er.mcq_marks, er.project_marks, er.viva_marks
            FROM exam_results er
            JOIN exams e ON er.exam_id = e.exam_id
            WHERE er.student_id = ? AND e.batch_id = ?
            ORDER BY e.exam_date DESC
        ");
        $stmt->execute([$student_id, $batch_id]);
        $batch_exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add batch info to each exam record
        foreach ($batch_exams as $exam) {
            $exam['batch_name'] = $batch_details[$batch_id]['batch_name'] ?? $batch_id;
            $exam['batch_id'] = $batch_id;
            $all_exams[] = $exam;
        }
        
        // Also fetch from proctored_exams for backward compatibility
        $stmt = $conn->prepare("
            SELECT pe.exam_id, pe.exam_date, pe.mode, es.score, es.is_malpractice, ? as batch_id
            FROM proctored_exams pe
            JOIN exam_students es ON pe.exam_id = es.exam_id
            WHERE es.student_name = ? AND pe.batch_id = ?
            ORDER BY pe.exam_date DESC
        ");
        $stmt->execute([$batch_id, $student['first_name'] . ' ' . $student['last_name'], $batch_id]);
        $proctored_exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add batch info to proctored exams
        foreach ($proctored_exams as $exam) {
            $exam['batch_name'] = $batch_details[$batch_id]['batch_name'] ?? $batch_id;
            $all_exams[] = $exam;
        }
    }
    
    // Calculate overall attendance stats across all batches
    $total_classes_all = array_sum(array_column($attendance_stats, 'total_classes'));
    $present_count_all = array_sum(array_column($attendance_stats, 'present_count'));
    $absent_count_all = array_sum(array_column($attendance_stats, 'absent_count'));
    $late_count_all = array_sum(array_column($attendance_stats, 'late_count'));
    
    $present_percentage_all = $total_classes_all > 0 ? round(($present_count_all / $total_classes_all) * 100) : 0;
    $absent_percentage_all = $total_classes_all > 0 ? round(($absent_count_all / $total_classes_all) * 100) : 0;
    $late_percentage_all = $total_classes_all > 0 ? round(($late_count_all / $total_classes_all) * 100) : 0;
    
    // Calculate overall exam performance
    $total_score = 0;
    $highest_score = 0;
    $lowest_score = 100;
    $valid_exams = 0;
    
    foreach ($all_exams as $exam) {
        $score = isset($exam['obtained_marks']) ? $exam['obtained_marks'] : (isset($exam['score']) ? $exam['score'] : 0);
        if ($score > 0) {
            $total_score += $score;
            $valid_exams++;
            if ($score > $highest_score) $highest_score = $score;
            if ($score < $lowest_score) $lowest_score = $score;
        }
    }
    $average_score = $valid_exams > 0 ? round($total_score / $valid_exams, 1) : 0;
    
    // Fetch student documents
    $stmt = $conn->prepare("SELECT * FROM student_documents WHERE student_id = ? ORDER BY document_type");
    $stmt->execute([$student_id]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle document upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
        $document_type = $_POST['document_type'];
        $allowed_types = ['aadhaar', 'pancard', 'tenth_marksheet', 'twelfth_marksheet', 'other'];
        
        if (in_array($document_type, $allowed_types)) {
            $upload_dir = '../uploads/student_documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = $student_id . '_' . $document_type . '_' . time() . '_' . basename($_FILES['document_file']['name']);
            $target_file = $upload_dir . $file_name;
            
            $stmt = $conn->prepare("SELECT * FROM student_documents WHERE student_id = ? AND document_type = ?");
            $stmt->execute([$student_id, $document_type]);
            $existing_doc = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_doc) {
                if (file_exists($existing_doc['file_path'])) {
                    unlink($existing_doc['file_path']);
                }
                $stmt = $conn->prepare("UPDATE student_documents SET file_path = ? WHERE document_id = ?");
                $stmt->execute([$target_file, $existing_doc['document_id']]);
            } else {
                $stmt = $conn->prepare("INSERT INTO student_documents (student_id, document_type, file_path) VALUES (?, ?, ?)");
                $stmt->execute([$student_id, $document_type, $target_file]);
            }
            
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $target_file)) {
                $_SESSION['success_message'] = "Document uploaded successfully!";
                header("Location: student_view.php?id=$student_id");
                exit();
            } else {
                $_SESSION['error_message'] = "Sorry, there was an error uploading your file.";
                $_SESSION['show_upload_modal'] = true;
                header("Location: student_view.php?id=$student_id");
                exit();
            }
        }
    }
    
    // Handle document deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
        $document_id = $_POST['document_id'];
        
        $stmt = $conn->prepare("SELECT * FROM student_documents WHERE document_id = ? AND student_id = ?");
        $stmt->execute([$document_id, $student_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            if (file_exists($doc['file_path'])) {
                unlink($doc['file_path']);
            }
            $stmt = $conn->prepare("DELETE FROM student_documents WHERE document_id = ?");
            $stmt->execute([$document_id]);
            $_SESSION['success_message'] = "Document deleted successfully!";
            header("Location: student_view.php?id=$student_id");
            exit();
        }
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?> | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ========== CUSTOM THEME COLORS ========== */
        :root {
            --primary-dark: #1B3C53;
            --primary: #234C6A;
            --primary-light: #456882;
            --accent-warm: #D2C1B6;
            --accent-warm-light: #E5D9D0;
            --accent-warm-dark: #B8A898;
            --gold: #C4A962;
            --gold-light: #D4BC7E;
            --white: #FFFFFF;
            --off-white: #F8F6F3;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --success: #059669;
            --danger: #DC2626;
            --warning: #D97706;
            --info: #0284C7;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, var(--off-white) 0%, var(--accent-warm-light) 100%);
            color: var(--gray-800);
            min-height: 100vh;
            position: relative;
        }

        /* ========== DECORATIVE BACKGROUND ========== */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 70% 30%, rgba(35, 76, 106, 0.03) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            bottom: -50%;
            left: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 30% 70%, rgba(210, 193, 182, 0.05) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* ========== SCROLLBAR STYLING ========== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        /* ========== MAIN CONTENT ========== */
        .main-content {
            margin-left: 16rem;
            transition: margin 0.3s ease;
            position: relative;
            z-index: 1;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }

        /* ========== HEADER ========== */
        .header-glass {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(27, 60, 83, 0.1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        /* ========== HERO SECTION ========== */
        .hero-section {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 30%, var(--primary-light) 70%, var(--accent-warm-dark) 100%);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.5;
            animation: patternMove 20s linear infinite;
        }

        @keyframes patternMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(60px, 60px); }
        }

        .hero-section::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(210, 193, 182, 0.15) 0%, transparent 50%);
            pointer-events: none;
        }

        .profile-picture {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            border: 4px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3),
                        0 0 80px rgba(210, 193, 182, 0.3);
            object-fit: cover;
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            z-index: 2;
        }

        .profile-picture:hover {
            transform: scale(1.05) rotate(3deg);
            border-color: var(--accent-warm);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4),
                        0 0 100px rgba(210, 193, 182, 0.5);
        }

        /* ========== STAT CARDS ========== */
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(27, 60, 83, 0.08);
            box-shadow: var(--card-shadow);
            transition: var(--transition-smooth);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-dark), var(--primary-light), var(--accent-warm));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
            border-color: rgba(27, 60, 83, 0.15);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .stat-icon.attendance {
            background: linear-gradient(135deg, rgba(35, 76, 106, 0.1), rgba(35, 76, 106, 0.05));
            color: var(--primary);
            border: 2px solid rgba(35, 76, 106, 0.2);
        }

        .stat-icon.exam {
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.1), rgba(5, 150, 105, 0.05));
            color: var(--success);
            border: 2px solid rgba(5, 150, 105, 0.2);
        }

        .stat-icon.document {
            background: linear-gradient(135deg, rgba(210, 193, 182, 0.2), rgba(210, 193, 182, 0.1));
            color: var(--accent-warm-dark);
            border: 2px solid rgba(210, 193, 182, 0.3);
        }

        /* ========== PROGRESS BARS ========== */
        .progress-bar-container {
            height: 10px;
            border-radius: 20px;
            background: var(--gray-100);
            overflow: hidden;
            position: relative;
        }

        .progress-bar {
            height: 100%;
            border-radius: 20px;
            transition: width 1.5s cubic-bezier(0.22, 1, 0.36, 1);
            position: relative;
            overflow: hidden;
        }

        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(255, 255, 255, 0.3) 50%, 
                transparent 100%);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .progress-bar.present { background: linear-gradient(135deg, var(--primary), var(--primary-light)); }
        .progress-bar.absent { background: linear-gradient(135deg, var(--danger), #EF4444); }
        .progress-bar.late { background: linear-gradient(135deg, var(--warning), #F59E0B); }
        .progress-bar.score { background: linear-gradient(135deg, var(--success), #10B981); }

        /* ========== TABS ========== */
        .tab-button {
            position: relative;
            padding: 1rem 1.5rem;
            font-weight: 600;
            color: var(--gray-600);
            transition: var(--transition-smooth);
            border-bottom: 3px solid transparent;
            cursor: pointer;
            background: none;
            border-top: none;
            border-left: none;
            border-right: none;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
        }

        .tab-button:hover {
            color: var(--primary);
            background: rgba(35, 76, 106, 0.05);
        }

        .tab-button.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(35, 76, 106, 0.03);
        }

        .tab-pane {
            animation: fadeSlideIn 0.5s ease-out;
        }

        @keyframes fadeSlideIn {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ========== BADGES ========== */
        .batch-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            background: linear-gradient(135deg, rgba(35, 76, 106, 0.08), rgba(69, 104, 130, 0.05));
            color: var(--primary);
            border: 1px solid rgba(35, 76, 106, 0.2);
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            transition: var(--transition-smooth);
        }

        .batch-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(35, 76, 106, 0.15);
            background: linear-gradient(135deg, rgba(35, 76, 106, 0.12), rgba(69, 104, 130, 0.08));
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            transition: var(--transition-smooth);
        }

        .status-badge.present {
            background: rgba(5, 150, 105, 0.1);
            color: var(--success);
            border: 1px solid rgba(5, 150, 105, 0.2);
        }

        .status-badge.absent {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }

        .status-badge.late {
            background: rgba(217, 119, 6, 0.1);
            color: var(--warning);
            border: 1px solid rgba(217, 119, 6, 0.2);
        }

        .status-badge.completed {
            background: rgba(35, 76, 106, 0.1);
            color: var(--primary);
            border: 1px solid rgba(35, 76, 106, 0.2);
        }

        /* ========== TABLES ========== */
        .table-container {
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        thead {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        }

        thead th {
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 16px 20px;
        }

        tbody tr {
            transition: var(--transition-smooth);
            border-bottom: 1px solid var(--gray-100);
        }

        tbody tr:hover {
            background: rgba(35, 76, 106, 0.03);
            transform: scale(1.002);
        }

        tbody tr:nth-child(even) {
            background: rgba(210, 193, 182, 0.05);
        }

        tbody td {
            padding: 16px 20px;
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        /* ========== DOCUMENT CARDS ========== */
        .document-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid rgba(27, 60, 83, 0.08);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
            transition: var(--transition-smooth);
            position: relative;
            overflow: hidden;
        }

        .document-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary-dark), var(--accent-warm));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .document-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--card-shadow-hover);
            border-color: rgba(27, 60, 83, 0.15);
        }

        .document-card:hover::before {
            opacity: 1;
        }

        /* ========== MODAL ========== */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(27, 60, 83, 0.7);
            backdrop-filter: blur(8px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            animation: modalIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow: hidden;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(-40px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* ========== BUTTONS ========== */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: var(--transition-smooth);
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2);
            letter-spacing: 0.5px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.3);
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            border: 2px solid rgba(27, 60, 83, 0.2);
            cursor: pointer;
            transition: var(--transition-smooth);
            letter-spacing: 0.5px;
        }

        .btn-secondary:hover {
            border-color: var(--primary);
            background: rgba(35, 76, 106, 0.05);
            transform: translateY(-2px);
        }

        /* ========== TOAST ========== */
        .toast {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 2000;
            padding: 16px 24px;
            border-radius: 16px;
            color: white;
            font-weight: 500;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: toastIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
        }

        @keyframes toastIn {
            from {
                opacity: 0;
                transform: translateX(60px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
        }

        .toast.success {
            background: linear-gradient(135deg, #059669, #047857);
        }

        .toast.error {
            background: linear-gradient(135deg, #DC2626, #B91C1C);
        }

        /* ========== SCROLL TO TOP ========== */
        #scrollToTop {
            position: fixed;
            bottom: 32px;
            right: 32px;
            z-index: 50;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            border: none;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.3);
            cursor: pointer;
            transition: var(--transition-smooth);
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px);
            font-size: 1.2rem;
        }

        #scrollToTop.visible {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        #scrollToTop:hover {
            transform: translateY(-4px) scale(1.1);
            box-shadow: 0 12px 32px rgba(27, 60, 83, 0.4);
        }

        /* ========== LOADING ANIMATIONS ========== */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .loading-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 1024px) {
            .profile-picture {
                width: 150px;
                height: 150px;
            }
        }

        @media (max-width: 640px) {
            .profile-picture {
                width: 120px;
                height: 120px;
            }
            
            .stat-card {
                padding: 1.5rem;
            }
            
            .tab-button {
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
            }
        }

        /* ========== GLASS CARD ========== */
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(27, 60, 83, 0.1);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        /* ========== GOLD ACCENT ========== */
        .gold-text {
            background: linear-gradient(135deg, var(--gold), var(--gold-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .gold-border {
            border: 2px solid var(--gold);
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <?php include '../sidebar.php'; ?>
    
    <!-- Mobile header -->
    <div class="md:hidden bg-white shadow-sm fixed w-full z-30 border-b">
        <div class="flex items-center justify-between p-4">
            <button id="mobileSidebarToggle" class="text-gray-700 hover:text-primary">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-xl font-bold" style="color: var(--primary-dark);">ASD Academy</h1>
        </div>
    </div>

    <!-- Main content wrapper -->
    <div class="flex flex-col min-h-screen main-content">
        <!-- Sticky Header -->
        <header class="sticky top-0 z-30 header-glass px-6 py-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <button class="md:hidden text-2xl hover:text-primary transition" onclick="toggleSidebarMobile()" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="flex items-center gap-3">
                    <div class="h-12 w-12 rounded-2xl flex items-center justify-center shadow-lg" 
                         style="background: linear-gradient(135deg, var(--primary-dark), var(--primary-light));">
                        <i class="fas fa-user-graduate text-white text-lg"></i>
                    </div>
                    <div>
                        <nav class="text-xs font-semibold text-gray-500 mb-0.5" aria-label="Breadcrumb">
                            <a href="#" class="hover:text-primary transition">Dashboard</a> /
                            <a href="students_list.php" class="hover:text-primary transition">Students</a> /
                            <span class="text-gray-800"><?= htmlspecialchars($student['first_name']) ?></span>
                        </nav>
                        <h1 class="text-2xl font-bold gold-text">
                            Student Profile
                        </h1>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="px-4 py-2 rounded-full text-sm font-semibold border" 
                      style="background: rgba(35, 76, 106, 0.08); color: var(--primary); border-color: rgba(35, 76, 106, 0.2);">
                    <i class="fas fa-id-badge mr-1"></i> <?= htmlspecialchars($student['student_id']) ?>
                </span>
                <a href="<?= $from_batch ? '../batch_students.php?id=' . $from_batch : 'students_list.php' ?>" 
                   class="btn-secondary">
                    <i class="fas fa-arrow-left mr-2"></i> <span class="hidden sm:inline">Back</span>
                </a>
                <a href="edit_student.php?id=<?= $student_id ?>" class="btn-primary">
                    <i class="fas fa-edit mr-2"></i> <span class="hidden sm:inline">Edit</span>
                </a>
            </div>
        </header>

        <!-- Hero Section -->
        <div class="hero-section text-white flex justify-center items-center relative py-16 md:py-24">
            <div class="container mx-auto px-6 md:px-12 flex flex-col md:flex-row items-center space-y-6 md:space-y-0 md:space-x-10 relative z-10">
                <!-- Profile Picture -->
                <div class="relative">
                    <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars($student['profile_picture']) ?>" 
                             alt="Profile Picture" 
                             class="profile-picture">
                    <?php else: ?>
                        <div class="profile-picture bg-white/20 backdrop-blur-sm flex items-center justify-center">
                            <i class="fas fa-user text-6xl" style="color: var(--accent-warm);"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Profile Info -->
                <div class="text-center md:text-left">
                    <h1 class="text-4xl md:text-5xl font-bold mb-3" style="font-family: 'Playfair Display', serif;">
                        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                    </h1>
                    <p class="text-xl md:text-2xl mb-4" style="color: var(--accent-warm-light);">
                        <?= htmlspecialchars($student['course']) ?>
                    </p>
                    
                    <div class="mb-4">
                        <p class="text-sm mb-2" style="color: var(--accent-warm);">Enrolled Batches</p>
                        <div class="flex flex-wrap justify-center md:justify-start gap-2">
                            <?php if (!empty($all_batches)): ?>
                                <?php foreach ($all_batches as $batch): ?>
                                    <span class="batch-badge" style="background: rgba(255,255,255,0.15); color: white; border-color: rgba(255,255,255,0.3);">
                                        <i class="fas fa-users"></i> <?= htmlspecialchars($batch['batch_name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color: var(--accent-warm-light);">No batches assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="flex flex-wrap justify-center md:justify-start gap-6 text-sm">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-envelope" style="color: var(--accent-warm);"></i> 
                            <span><?= htmlspecialchars($student['email']) ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-phone" style="color: var(--accent-warm);"></i> 
                            <span><?= htmlspecialchars($student['phone_number']) ?></span>
                        </div>
                        <?php if (!empty($student['state'])): ?>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-map-marker-alt" style="color: var(--accent-warm);"></i> 
                                <span><?= htmlspecialchars($student['state']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Container -->
        <div class="container mx-auto px-4 md:px-6 pb-20 -mt-6 relative z-20">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Attendance Stats -->
                <div class="stat-card">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold" style="color: var(--primary-dark);">Attendance Overview</h3>
                        <div class="stat-icon attendance">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <div class="flex justify-between text-sm mb-2">
                                <span class="font-semibold">Present</span>
                                <span class="font-bold" style="color: var(--primary);"><?= $present_count_all ?> (<?= $present_percentage_all ?>%)</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar present" style="width: <?= $present_percentage_all ?>%;"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-2">
                                <span class="font-semibold">Absent</span>
                                <span class="font-bold" style="color: var(--danger);"><?= $absent_count_all ?> (<?= $absent_percentage_all ?>%)</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar absent" style="width: <?= $absent_percentage_all ?>%;"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-2">
                                <span class="font-semibold">Late</span>
                                <span class="font-bold" style="color: var(--warning);"><?= $late_count_all ?> (<?= $late_percentage_all ?>%)</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar late" style="width: <?= $late_percentage_all ?>%;"></div>
                            </div>
                        </div>
                        <?php if (count($attendance_stats) > 1): ?>
                            <div class="mt-4 pt-4 border-t" style="border-color: rgba(27, 60, 83, 0.1);">
                                <p class="text-xs font-semibold text-gray-500 mb-2">PER BATCH BREAKDOWN</p>
                                <?php foreach ($attendance_stats as $stats): ?>
                                    <div class="flex justify-between text-xs mb-1">
                                        <span><?= htmlspecialchars($stats['batch_name']) ?></span>
                                        <span class="font-semibold"><?= $stats['present_count'] ?>/<?= $stats['total_classes'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Exam Performance -->
                <div class="stat-card">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold" style="color: var(--primary-dark);">Exam Performance</h3>
                        <div class="stat-icon exam">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <?php if (count($all_exams) > 0): ?>
                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between text-sm mb-2">
                                    <span class="font-semibold">Average Score</span>
                                    <span class="font-bold" style="color: var(--success);"><?= $average_score ?>%</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar score" style="width: <?= $average_score ?>%;"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-sm mb-2">
                                    <span class="font-semibold">Highest Score</span>
                                    <span class="font-bold" style="color: var(--success);"><?= $highest_score ?>%</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar score" style="width: <?= $highest_score ?>%;"></div>
                                </div>
                            </div>
                            <div class="text-sm text-gray-600 mt-4">
                                <i class="fas fa-clipboard-list mr-2" style="color: var(--primary);"></i>
                                Total Exams: <span class="font-bold" style="color: var(--primary-dark);"><?= count($all_exams) ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-clipboard-list text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500">No exam data available</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Documents Status -->
                <div class="stat-card">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold" style="color: var(--primary-dark);">Documents Status</h3>
                        <div class="stat-icon document">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <?php
                    $doc_types = ['aadhaar' => 'Aadhaar Card', 'pancard' => 'PAN Card', 'tenth_marksheet' => '10th Marksheet', 'twelfth_marksheet' => '12th Marksheet'];
                    $uploaded_docs = array_column($documents, 'document_type');
                    ?>
                    <div class="space-y-3">
                        <?php foreach ($doc_types as $key => $label): ?>
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-medium"><?= $label ?></span>
                                <?php if (in_array($key, $uploaded_docs)): ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold" style="background: rgba(5, 150, 105, 0.1); color: var(--success);">
                                        <i class="fas fa-check-circle mr-1"></i> Uploaded
                                    </span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold" style="background: rgba(220, 38, 38, 0.1); color: var(--danger);">
                                        <i class="fas fa-exclamation-circle mr-1"></i> Missing
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button id="uploadDocumentBtn" class="btn-primary w-full mt-6">
                        <i class="fas fa-upload mr-2"></i> Upload Document
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="border-b" style="border-color: rgba(27, 60, 83, 0.1);">
                    <nav class="flex flex-wrap -mb-px overflow-x-auto" aria-label="Profile tabs">
                        <button class="tab-button active" data-tab="personal">
                            <i class="fas fa-user mr-2"></i> Personal Info
                        </button>
                        <button class="tab-button" data-tab="attendance">
                            <i class="fas fa-calendar-alt mr-2"></i> Attendance Records
                        </button>
                        <button class="tab-button" data-tab="exams">
                            <i class="fas fa-graduation-cap mr-2"></i> Exam Results
                        </button>
                        <button class="tab-button" data-tab="documents">
                            <i class="fas fa-file-alt mr-2"></i> Documents
                        </button>
                    </nav>
                </div>
                <div class="p-6 md:p-8">
                    <!-- Personal Tab -->
                    <div id="tab-content-personal" class="tab-pane">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="space-y-6">
                                <div class="p-4 rounded-xl" style="background: rgba(210, 193, 182, 0.1);">
                                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Full Name</h4>
                                    <p class="text-lg font-bold" style="color: var(--primary-dark);">
                                        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                    </p>
                                </div>
                                <div class="p-4 rounded-xl" style="background: rgba(210, 193, 182, 0.1);">
                                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Email Address</h4>
                                    <p class="text-lg font-medium"><?= htmlspecialchars($student['email']) ?></p>
                                </div>
                                <div class="p-4 rounded-xl" style="background: rgba(210, 193, 182, 0.1);">
                                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Phone Number</h4>
                                    <p class="text-lg font-medium"><?= htmlspecialchars($student['phone_number']) ?></p>
                                </div>
                                <div class="p-4 rounded-xl" style="background: rgba(210, 193, 182, 0.1);">
                                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Date of Birth</h4>
                                    <p class="text-lg font-medium">
                                        <?= !empty($student['date_of_birth']) ? date('F j, Y', strtotime($student['date_of_birth'])) : 'Not provided' ?>
                                    </p>
                                </div>
                            </div>
                            <div class="space-y-6">
                                <div class="p-4 rounded-xl" style="background: rgba(35, 76, 106, 0.05);">
                                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Course</h4>
                                    <p class="text-lg font-bold" style="color: var(--primary);">
                                        <?= htmlspecialchars($student['course']) ?>
                                    </p>
                                </div>
                                <div class="p-4 rounded-xl" style="background: rgba(35, 76, 106, 0.05);">
                                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Batches</h4>
                                    <div class="flex flex-wrap gap-2 mt-2">
                                        <?php if (!empty($all_batches)): ?>
                                            <?php foreach ($all_batches as $batch): ?>
                                                <span class="batch-badge"><?= htmlspecialchars($batch['batch_name']) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-gray-500">No batches assigned</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="p-4 rounded-xl" style="background: rgba(35, 76, 106, 0.05);">
                                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Enrollment Date</h4>
                                    <p class="text-lg font-medium">
                                        <?= !empty($student['enrollment_date']) ? date('F j, Y', strtotime($student['enrollment_date'])) : 'Not provided' ?>
                                    </p>
                                </div>
                                <div class="p-4 rounded-xl" style="background: rgba(35, 76, 106, 0.05);">
                                    <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">State</h4>
                                    <p class="text-lg font-medium">
                                        <?= !empty($student['state']) ? htmlspecialchars($student['state']) : 'Not provided' ?>
                                    </p>
                                </div>
                                <?php if (!empty($student['emergency_contact'])): ?>
                                    <div class="p-4 rounded-xl" style="background: rgba(35, 76, 106, 0.05);">
                                        <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Emergency Contact</h4>
                                        <p class="text-lg font-medium"><?= htmlspecialchars($student['emergency_contact']) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance Tab -->
                    <div id="tab-content-attendance" class="tab-pane hidden">
                        <?php if (count($all_attendance) > 0): ?>
                            <div class="flex justify-between items-center mb-6">
                                <h4 class="text-xl font-bold" style="color: var(--primary-dark);">Attendance Records</h4>
                                <span class="px-4 py-2 rounded-full text-sm font-semibold" style="background: rgba(35, 76, 106, 0.1); color: var(--primary);">
                                    Total: <?= count($all_attendance) ?> records
                                </span>
                            </div>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Batch</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Camera</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_attendance as $record): ?>
                                            <tr>
                                                <td>
                                                    <span class="batch-badge text-xs"><?= htmlspecialchars($record['batch_name']) ?></span>
                                                </td>
                                                <td class="font-medium"><?= date('M j, Y', strtotime($record['date'])) ?></td>
                                                <td>
                                                    <span class="status-badge <?= strtolower($record['status']) ?>">
                                                        <i class="fas <?= $record['status'] === 'Present' ? 'fa-check' : ($record['status'] === 'Absent' ? 'fa-times' : 'fa-clock') ?> mr-1"></i>
                                                        <?= htmlspecialchars($record['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="px-3 py-1 rounded-full text-xs font-semibold" 
                                                          style="background: <?= $record['camera_status'] === 'On' ? 'rgba(5, 150, 105, 0.1)' : 'rgba(107, 114, 128, 0.1)' ?>; 
                                                                 color: <?= $record['camera_status'] === 'On' ? 'var(--success)' : 'var(--gray-500)' ?>;">
                                                        <?= htmlspecialchars($record['camera_status']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-sm"><?= !empty($record['remarks']) ? htmlspecialchars($record['remarks']) : '-' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Batch-wise summary -->
                            <?php if (count($attendance_stats) > 1): ?>
                                <div class="mt-8">
                                    <h5 class="text-lg font-bold mb-4" style="color: var(--primary-dark);">Batch-wise Summary</h5>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                        <?php foreach ($attendance_stats as $stats): ?>
                                            <div class="p-5 rounded-xl border" style="background: white; border-color: rgba(27, 60, 83, 0.1);">
                                                <div class="font-bold mb-2" style="color: var(--primary-dark);">
                                                    <?= htmlspecialchars($stats['batch_name']) ?>
                                                </div>
                                                <div class="text-sm text-gray-500 mb-3">Total Classes: <?= $stats['total_classes'] ?></div>
                                                <div class="space-y-2 text-xs">
                                                    <div class="flex justify-between">
                                                        <span>Present</span>
                                                        <span class="font-semibold" style="color: var(--success);"><?= $stats['present_count'] ?> (<?= $stats['present_percentage'] ?>%)</span>
                                                    </div>
                                                    <div class="progress-bar-container" style="height: 6px;">
                                                        <div class="progress-bar present" style="width: <?= $stats['present_percentage'] ?>%;"></div>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Absent</span>
                                                        <span class="font-semibold" style="color: var(--danger);"><?= $stats['absent_count'] ?> (<?= $stats['absent_percentage'] ?>%)</span>
                                                    </div>
                                                    <div class="progress-bar-container" style="height: 6px;">
                                                        <div class="progress-bar absent" style="width: <?= $stats['absent_percentage'] ?>%;"></div>
                                                    </div>
                                                    <div class="flex justify-between">
                                                        <span>Late</span>
                                                        <span class="font-semibold" style="color: var(--warning);"><?= $stats['late_count'] ?> (<?= $stats['late_percentage'] ?>%)</span>
                                                    </div>
                                                    <div class="progress-bar-container" style="height: 6px;">
                                                        <div class="progress-bar late" style="width: <?= $stats['late_percentage'] ?>%;"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-16">
                                <i class="fas fa-calendar-times text-6xl text-gray-300 mb-4"></i>
                                <p class="text-xl text-gray-500 font-medium">No attendance records found</p>
                                <p class="text-gray-400 mt-2">Attendance data will appear here once recorded</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Exams Tab -->
                    <div id="tab-content-exams" class="tab-pane hidden">
                        <?php if (count($all_exams) > 0): ?>
                            <div class="flex justify-between items-center mb-6">
                                <h4 class="text-xl font-bold" style="color: var(--primary-dark);">Exam Results</h4>
                                <span class="px-4 py-2 rounded-full text-sm font-semibold" style="background: rgba(5, 150, 105, 0.1); color: var(--success);">
                                    Total: <?= count($all_exams) ?> exams
                                </span>
                            </div>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Batch</th>
                                            <th>Exam Name</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Score</th>
                                            <th>Grade</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_exams as $exam): ?>
                                            <tr>
                                                <td>
                                                    <?php if (isset($exam['batch_name'])): ?>
                                                        <span class="batch-badge text-xs"><?= htmlspecialchars($exam['batch_name']) ?></span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="font-medium">
                                                    <?= isset($exam['exam_name']) ? htmlspecialchars($exam['exam_name']) : 'Proctored Exam' ?>
                                                </td>
                                                <td><?= date('M j, Y', strtotime($exam['exam_date'])) ?></td>
                                                <td>
                                                    <span class="px-3 py-1 rounded-full text-xs font-semibold" 
                                                          style="background: rgba(35, 76, 106, 0.1); color: var(--primary);">
                                                        <?= isset($exam['exam_type']) ? ucfirst(str_replace('_', ' ', $exam['exam_type'])) : (isset($exam['mode']) ? $exam['mode'] : 'Unknown') ?>
                                                    </span>
                                                </td>
                                                <td class="font-bold">
                                                    <?php
                                                    if (isset($exam['obtained_marks'])) {
                                                        $max = isset($exam['total_marks']) ? $exam['total_marks'] : 100;
                                                        echo '<span style="color: var(--success);">' . htmlspecialchars($exam['obtained_marks']) . '</span>/' . $max;
                                                    } elseif (isset($exam['score'])) {
                                                        echo '<span style="color: var(--success);">' . htmlspecialchars($exam['score']) . '%</span>';
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if (isset($exam['grade'])): ?>
                                                        <span class="px-3 py-1 rounded-full text-xs font-bold" 
                                                              style="background: linear-gradient(135deg, rgba(196, 169, 98, 0.2), rgba(212, 188, 126, 0.1)); 
                                                                     color: var(--gold); 
                                                                     border: 1px solid var(--gold);">
                                                            <?= htmlspecialchars($exam['grade']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (isset($exam['is_malpractice']) && $exam['is_malpractice']): ?>
                                                        <span class="status-badge absent">
                                                            <i class="fas fa-exclamation-triangle mr-1"></i> Malpractice
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="status-badge completed">
                                                            <i class="fas fa-check-circle mr-1"></i> Completed
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-16">
                                <i class="fas fa-clipboard-list text-6xl text-gray-300 mb-4"></i>
                                <p class="text-xl text-gray-500 font-medium">No exam results found</p>
                                <p class="text-gray-400 mt-2">Exam results will appear here once exams are conducted</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Documents Tab -->
                    <div id="tab-content-documents" class="tab-pane hidden">
                        <?php if (count($documents) > 0): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($documents as $doc): ?>
                                    <div class="document-card">
                                        <div class="flex items-start justify-between">
                                            <div class="flex items-center gap-3">
                                                <div class="w-12 h-12 rounded-xl flex items-center justify-center" 
                                                     style="background: rgba(220, 38, 38, 0.1);">
                                                    <i class="fas fa-file-pdf text-xl" style="color: var(--danger);"></i>
                                                </div>
                                                <div>
                                                    <h4 class="font-bold capitalize" style="color: var(--primary-dark);">
                                                        <?= str_replace('_', ' ', $doc['document_type']) ?>
                                                    </h4>
                                                    <p class="text-xs text-gray-500">
                                                        <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="flex gap-2">
                                                <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" 
                                                   class="w-8 h-8 rounded-lg flex items-center justify-center transition"
                                                   style="background: rgba(35, 76, 106, 0.1); color: var(--primary);"
                                                   onmouseover="this.style.background='rgba(35, 76, 106, 0.2)'"
                                                   onmouseout="this.style.background='rgba(35, 76, 106, 0.1)'"
                                                   title="View">
                                                    <i class="fas fa-eye text-sm"></i>
                                                </a>
                                                <a href="<?= htmlspecialchars($doc['file_path']) ?>" download 
                                                   class="w-8 h-8 rounded-lg flex items-center justify-center transition"
                                                   style="background: rgba(5, 150, 105, 0.1); color: var(--success);"
                                                   onmouseover="this.style.background='rgba(5, 150, 105, 0.2)'"
                                                   onmouseout="this.style.background='rgba(5, 150, 105, 0.1)'"
                                                   title="Download">
                                                    <i class="fas fa-download text-sm"></i>
                                                </a>
                                                <form method="POST" onsubmit="return confirm('Delete this document?');" class="inline">
                                                    <input type="hidden" name="document_id" value="<?= $doc['document_id'] ?>">
                                                    <input type="hidden" name="delete_document" value="1">
                                                    <button type="submit" 
                                                            class="w-8 h-8 rounded-lg flex items-center justify-center transition"
                                                            style="background: rgba(220, 38, 38, 0.1); color: var(--danger);"
                                                            onmouseover="this.style.background='rgba(220, 38, 38, 0.2)'"
                                                            onmouseout="this.style.background='rgba(220, 38, 38, 0.1)'"
                                                            title="Delete">
                                                        <i class="fas fa-trash text-sm"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                        <div class="mt-3 pt-3 border-t" style="border-color: rgba(27, 60, 83, 0.05);">
                                            <p class="text-xs text-gray-400 truncate">
                                                <i class="fas fa-paperclip mr-1"></i>
                                                <?= basename($doc['file_path']) ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-16">
                                <i class="fas fa-folder-open text-6xl text-gray-300 mb-4"></i>
                                <p class="text-xl text-gray-500 font-medium">No documents uploaded yet</p>
                                <p class="text-gray-400 mt-2 mb-6">Upload student documents like ID proofs and mark sheets</p>
                                <button id="uploadDocumentBtn2" class="btn-primary inline-flex items-center">
                                    <i class="fas fa-upload mr-2"></i> Upload First Document
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div id="uploadDocumentModal" class="modal <?= isset($_SESSION['show_upload_modal']) && $_SESSION['show_upload_modal'] ? 'show' : '' ?>">
        <div class="modal-content p-6">
            <div class="flex items-center justify-between border-b pb-4 mb-6" style="border-color: rgba(27, 60, 83, 0.1);">
                <h3 class="text-2xl font-bold" style="color: var(--primary-dark);">
                    <i class="fas fa-cloud-upload-alt mr-2" style="color: var(--primary);"></i>
                    Upload Document
                </h3>
                <button id="closeModal" class="w-10 h-10 rounded-full flex items-center justify-center transition hover:bg-gray-100">
                    <i class="fas fa-times text-xl text-gray-500"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-6">
                    <label for="document_type" class="block text-sm font-semibold text-gray-700 mb-2">
                        Document Type <span class="text-red-500">*</span>
                    </label>
                    <select id="document_type" name="document_type" required
                            class="w-full px-4 py-3 border rounded-xl focus:ring-2 focus:ring-primary focus:border-primary transition"
                            style="border-color: rgba(27, 60, 83, 0.2);">
                        <option value="">Select Document Type</option>
                        <option value="aadhaar">Aadhaar Card</option>
                        <option value="pancard">PAN Card</option>
                        <option value="tenth_marksheet">10th Marksheet</option>
                        <option value="twelfth_marksheet">12th Marksheet</option>
                        <option value="other">Other Document</option>
                    </select>
                </div>

                <div class="file-upload-container">
                    <input type="file" id="document_file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required 
                           style="display: none;">
                    <label for="document_file" id="file-upload-label"
                           class="block p-8 border-2 border-dashed rounded-xl text-center cursor-pointer transition"
                           style="border-color: rgba(27, 60, 83, 0.2); background: rgba(210, 193, 182, 0.05);">
                        <div class="text-4xl mb-3" style="color: var(--primary-light);">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="text-gray-600">
                            <span class="font-semibold" style="color: var(--primary);">Click to upload</span> or drag and drop
                        </div>
                        <div class="text-xs text-gray-400 mt-2">PDF, JPG, PNG, DOC up to 10MB</div>
                    </label>
                    <div id="file-preview-container" style="display: none;" class="mt-4">
                        <div class="flex items-center p-4 rounded-xl border" style="border-color: rgba(27, 60, 83, 0.1); background: white;">
                            <div class="text-2xl mr-3" style="color: var(--primary-light);">
                                <i class="fas fa-file"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-sm font-semibold" id="file-preview-name" style="color: var(--primary-dark);"></div>
                                <div class="text-xs text-gray-500" id="file-preview-size"></div>
                            </div>
                            <button type="button" id="file-preview-remove" class="text-red-500 hover:text-red-700 transition">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-8 pt-6 border-t" style="border-color: rgba(27, 60, 83, 0.1);">
                    <button type="button" id="cancelUpload" class="btn-secondary">Cancel</button>
                    <button type="submit" name="upload_document" value="1" class="btn-primary">
                        <i class="fas fa-upload mr-2"></i> Upload Document
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Back to Top -->
    <button id="scrollToTop" aria-label="Back to top">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- Toast Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="toast success" id="toast">
            <i class="fas fa-check-circle text-xl"></i> 
            <span><?= $_SESSION['success_message'] ?></span>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="toast error" id="toast">
            <i class="fas fa-exclamation-circle text-xl"></i> 
            <span><?= $_SESSION['error_message'] ?></span>
        </div>
        <?php unset($_SESSION['error_message']); ?>
        <?php unset($_SESSION['show_upload_modal']); ?>
    <?php endif; ?>

    <script>
        // ========== TABS ==========
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const tab = this.dataset.tab;
                document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('hidden'));
                document.getElementById('tab-content-' + tab).classList.remove('hidden');
            });
        });

        // ========== MODAL ==========
        const modal = document.getElementById('uploadDocumentModal');
        const uploadBtns = document.querySelectorAll('#uploadDocumentBtn, #uploadDocumentBtn2');
        const closeModal = document.getElementById('closeModal');
        const cancelUpload = document.getElementById('cancelUpload');

        uploadBtns.forEach(btn => btn.addEventListener('click', () => modal.classList.add('show')));
        [closeModal, cancelUpload].forEach(btn => btn.addEventListener('click', () => modal.classList.remove('show')));
        window.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('show'); });

        // ========== FILE UPLOAD PREVIEW ==========
        const fileInput = document.getElementById('document_file');
        const previewContainer = document.getElementById('file-preview-container');
        const previewName = document.getElementById('file-preview-name');
        const previewSize = document.getElementById('file-preview-size');
        const previewRemove = document.getElementById('file-preview-remove');
        const uploadLabel = document.getElementById('file-upload-label');

        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                previewName.textContent = file.name;
                previewSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                previewContainer.style.display = 'block';
                uploadLabel.style.borderColor = '#059669';
                uploadLabel.style.background = 'rgba(5, 150, 105, 0.05)';
            }
        });

        previewRemove.addEventListener('click', function() {
            fileInput.value = '';
            previewContainer.style.display = 'none';
            uploadLabel.style.borderColor = 'rgba(27, 60, 83, 0.2)';
            uploadLabel.style.background = 'rgba(210, 193, 182, 0.05)';
        });

        // Drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => {
            uploadLabel.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); });
        });
        ['dragenter', 'dragover'].forEach(ev => {
            uploadLabel.addEventListener(ev, () => uploadLabel.classList.add('dragover'));
        });
        ['dragleave', 'drop'].forEach(ev => {
            uploadLabel.addEventListener(ev, () => uploadLabel.classList.remove('dragover'));
        });
        uploadLabel.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            fileInput.files = dt.files;
            fileInput.dispatchEvent(new Event('change'));
        });

        // ========== SCROLL TO TOP ==========
        const scrollBtn = document.getElementById('scrollToTop');
        window.addEventListener('scroll', function() {
            if (window.scrollY > 500) scrollBtn.classList.add('visible');
            else scrollBtn.classList.remove('visible');
        });
        scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

        // ========== SIDEBAR TOGGLE (mobile) ==========
        document.getElementById('mobileSidebarToggle')?.addEventListener('click', function() {
            const sidebar = document.querySelector('aside, .sidebar');
            if (sidebar) sidebar.classList.toggle('-translate-x-full');
        });

        // ========== HIDE TOAST AFTER 5s ==========
        setTimeout(() => { 
            const t = document.getElementById('toast'); 
            if (t) {
                t.style.opacity = '0';
                t.style.transform = 'translateX(60px)';
                setTimeout(() => t.remove(), 300);
            }
        }, 5000);

        // ========== INITIALIZE FIRST TAB ==========
        document.querySelector('.tab-button.active')?.click();

        // ========== ANIMATE PROGRESS BARS ON SCROLL ==========
        const observerOptions = {
            threshold: 0.2
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const bars = entry.target.querySelectorAll('.progress-bar');
                    bars.forEach(bar => {
                        const width = bar.style.width;
                        bar.style.width = '0%';
                        setTimeout(() => {
                            bar.style.width = width;
                        }, 100);
                    });
                }
            });
        }, observerOptions);

        document.querySelectorAll('.stat-card').forEach(card => {
            observer.observe(card);
        });
    </script>
</body>
</html>