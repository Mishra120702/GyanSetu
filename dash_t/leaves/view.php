<?php
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

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get trainer details
    $stmt = $db->prepare("SELECT t.*, u.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
    $stmt->execute([$user_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainer) {
        header("Location: ../logout.php");
        exit;
    }

    // Handle cancellation request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_application'])) {
        $application_id = $_POST['application_id'];
        
        // Check if application belongs to this trainer and is pending
        $check_stmt = $db->prepare("
            SELECT id, status FROM leave_applications 
            WHERE id = :id AND student_id = :student_id AND status = 'pending'
        ");
        $check_stmt->execute([
            ':id' => $application_id,
            ':student_id' => $user_id
        ]);
        
        if ($check_stmt->fetch()) {
            $db->beginTransaction();
            try {
                // Update application status
                $update_stmt = $db->prepare("
                    UPDATE leave_applications 
                    SET status = 'cancelled', 
                        admin_remarks = CONCAT(IFNULL(admin_remarks, ''), '\n[Cancelled by trainer on ', NOW(), ']')
                    WHERE id = :id
                ");
                $update_stmt->execute([':id' => $application_id]);
                
                // Add to history
                $history_stmt = $db->prepare("
                    INSERT INTO leave_application_history (application_id, action, action_by, remarks)
                    VALUES (:application_id, 'cancelled', :action_by, :remarks)
                ");
                $history_stmt->execute([
                    ':application_id' => $application_id,
                    ':action_by' => $user_id,
                    ':remarks' => 'Application cancelled by trainer'
                ]);
                
                $db->commit();
                $success_message = "Application cancelled successfully!";
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Failed to cancel application: " . $e->getMessage();
            }
        } else {
            $error_message = "Application cannot be cancelled. Only pending applications can be cancelled.";
        }
    }

    // Get filter parameters
    $status_filter = $_GET['status'] ?? 'all';
    $search_query = $_GET['search'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

    // Build query to get leave applications for this trainer
    $query = "
        SELECT l.*, 
               b.batch_name as batch_title,
               u.name as approved_by_name,
               u2.name as rejected_by_name
        FROM leave_applications l
        LEFT JOIN batches b ON l.batch_id = b.batch_id
        LEFT JOIN users u ON l.approved_by = u.id
        LEFT JOIN users u2 ON l.rejected_by = u2.id
        WHERE l.student_id = :student_id
    ";

    $params = [':student_id' => $user_id];

    if ($status_filter !== 'all') {
        $query .= " AND l.status = :status";
        $params[':status'] = $status_filter;
    }

    if (!empty($search_query)) {
        $query .= " AND (l.application_no LIKE :search OR l.reason_category LIKE :search OR l.reason_detail LIKE :search)";
        $params[':search'] = "%$search_query%";
    }

    if (!empty($date_from)) {
        $query .= " AND DATE(l.created_at) >= :date_from";
        $params[':date_from'] = $date_from;
    }

    if (!empty($date_to)) {
        $query .= " AND DATE(l.created_at) <= :date_to";
        $params[':date_to'] = $date_to;
    }

    $query .= " ORDER BY l.created_at DESC";

    $applications_stmt = $db->prepare($query);
    $applications_stmt->execute($params);
    $applications = $applications_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics for this trainer
    $stats_query = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM leave_applications
        WHERE student_id = :student_id
    ");
    $stats_query->execute([':student_id' => $user_id]);
    $stats = $stats_query->fetch(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>My Leave Applications | Trainer Dashboard</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .glass-card:hover {
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-approved {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-rejected {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-cancelled {
            background-color: #f3f4f6;
            color: #374151;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.8);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        
        .application-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }
        
        .application-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .filter-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        
        .form-input, .form-select {
            transition: all 0.2s ease;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.6rem 1rem;
        }
        
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #1B3C53;
            box-shadow: 0 0 0 3px rgba(27,60,83, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #234C6A, #234C6A);
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            transition: all 0.2s ease;
        }
        
        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        
        .btn-secondary {
            background: #6b7280;
            transition: all 0.2s ease;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .mobile-nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-radius: 0.5rem;
            transition: all 0.3s;
        }
        
        .mobile-nav-link.active {
            background-color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-weight: 600;
        }
        
        #mobileMenu {
            transition: opacity 0.3s ease-in-out;
        }
        
        @media (max-width: 768px) {
            .glass-card {
                border-radius: 12px;
            }
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            border-radius: 4px;
        }
        
        .floating-shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.05;
            z-index: -1;
            filter: blur(40px);
        }
        
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }
        
        .wave-animation {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 80px;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none"><path d="M0,0V46.29c47.79,22.2,103.59,32.17,158,28,70.36-5.37,136.33-33.31,206.8-37.5C438.64,32.43,512.34,53.67,583,72.05c69.27,18,138.3,24.88,209.4,13.08,36.15-6,69.85-17.84,104.45-29.34C989.49,25,1113-14.29,1200,52.47V0Z" fill="%23667eea" opacity=".05"/></svg>');
            background-size: cover;
            background-repeat: no-repeat;
            opacity: 0.5;
            z-index: -1;
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .detail-row {
            display: flex;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .detail-label {
            width: 120px;
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .detail-value {
            flex: 1;
            font-size: 0.8rem;
            color: #1f2937;
        }
    
        /* ===== Same dashboard/student theme visual enhancement only ===== */
        :root {
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 46%, #456882 100%);
            --dash-blue: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --dash-green: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
            --dash-orange: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            --dash-red: linear-gradient(135deg, #ef4444 0%, #f43f5e 100%);
            --dash-ink: #101827;
        }

        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83, .15), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130, .15), transparent 30%),
                linear-gradient(135deg, #eef4ff 0%, #f7f3ff 48%, #f8fbff 100%) !important;
            color: var(--dash-ink);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(27,60,83, .055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(69,104,130, .045) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.82), transparent 84%);
            z-index: -2;
        }

        .glass-card, .stat-card, .application-card, .filter-card, .info-card-pro {
            background: rgba(255,255,255,.90) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }

        .hero-shell {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: clamp(1.25rem, 2.5vw, 2rem);
            color: white;
            background: var(--dash-main);
            box-shadow: 0 24px 58px rgba(27,60,83,.25);
        }

        .hero-shell::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 430px;
            right: -135px;
            top: -145px;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            filter: blur(2px);
        }

        .hero-shell::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .hero-shell > * { position: relative; z-index: 1; }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .5rem .78rem;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.25);
            color: white;
            font-size: .74rem;
            font-weight: 900;
            letter-spacing: .02em;
            backdrop-filter: blur(12px);
        }

        .hero-mini {
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.24);
            border-radius: 18px;
            padding: 1rem;
            backdrop-filter: blur(14px);
        }

        .feature-shell {
            position: relative;
            overflow: hidden;
            border-radius: 26px !important;
        }

        .feature-shell::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--dash-main));
        }

        .feature-shell::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            right: -55px;
            top: -55px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(8px);
            opacity: .75;
            pointer-events: none;
        }

        .feature-shell > * { position: relative; z-index: 1; }

        .feature-form {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-info {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-list {
            --feature-accent: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(79,70,229,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .36rem .72rem;
            border-radius: 999px;
            margin-bottom: .85rem;
            background: rgba(255,255,255,.84);
            border: 1px solid rgba(226,232,240,.9);
            color: #1B3C53;
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            box-shadow: 0 6px 18px rgba(15,23,42,.05);
        }

        .form-input, .form-select, .form-textarea {
            background: rgba(248,250,252,.92) !important;
            border: 1px solid rgba(148,163,184,.34) !important;
            border-radius: 16px !important;
            min-height: 46px;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 4px rgba(139,92,246,.12) !important;
            background: #fff !important;
        }

        .form-label {
            color: #334155 !important;
            font-weight: 900 !important;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: .75rem !important;
        }

        .btn-primary, .btn-purple-pro {
            background: var(--dash-main) !important;
            color: white !important;
            box-shadow: 0 14px 28px rgba(35,76,106,.20);
            border-radius: 16px !important;
            font-weight: 900 !important;
        }

        .btn-primary:hover, .btn-purple-pro:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(35,76,106,.26);
        }

        .btn-secondary {
            border-radius: 16px !important;
            font-weight: 900 !important;
            background: linear-gradient(135deg, #64748b, #475569) !important;
        }

        .btn-danger {
            border-radius: 16px !important;
            font-weight: 900 !important;
            background: var(--dash-red) !important;
        }

        .application-card {
            position: relative;
            overflow: hidden;
        }

        .application-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--dash-main);
        }

        .application-card::after {
            content: "";
            position: absolute;
            width: 160px;
            height: 160px;
            right: -60px;
            top: -70px;
            border-radius: 999px;
            background: rgba(139,92,246,.08);
        }

        .application-card > * { position: relative; z-index: 1; }

        .stat-card {
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--dash-main);
        }

        .filter-card {
            position: relative;
            overflow: hidden;
            border-radius: 24px !important;
        }

        .filter-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, #234C6A, #234C6A, #456882);
        }

        .empty-state-pro {
            border: 1px dashed rgba(148,163,184,.45);
            background: linear-gradient(135deg, rgba(255,255,255,.9), rgba(248,250,255,.88));
            border-radius: 26px;
        }

        @media (max-width: 768px) {
            .hero-shell { border-radius: 22px; }
            .glass-card, .stat-card, .application-card, .filter-card { border-radius: 20px !important; }
        }

    
/* ===== Brand palette update: #1B3C53, #234C6A, #456882, #D2C1B6 ===== */
:root {
    --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --trainer-primary: #234C6A !important;
    --trainer-violet: #1B3C53 !important;
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
}
.bg-gradient-to-r.from-purple-500.to-pink-500,
.bg-gradient-to-r.from-indigo-500.to-purple-500,
.bg-gradient-to-r.from-indigo-600.to-purple-600,
.bg-gradient-to-r.from-blue-500.to-cyan-500,
.bg-gradient-to-r.from-blue-500.to-indigo-500,
.bg-gradient-to-r.from-purple-600.to-pink-600,
.bg-gradient-to-br.from-purple-500.to-pink-500,
.bg-gradient-to-br.from-blue-500.to-indigo-500,
.bg-gradient-to-br.from-indigo-500.to-purple-500,
.avatar-gradient,.avatar-gradient-2,.avatar-gradient-3,.avatar-gradient-4,.avatar-gradient-5 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.text-purple-500,.text-purple-600,.text-indigo-500,.text-indigo-600,.text-blue-500,.text-blue-600,.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-purple-200,.border-indigo-200,.border-blue-200 {
    border-color: rgba(69,104,130,.25) !important;
}
button[style*="--primary-gradient"],.btn-primary,.tab-button.active,.view-toggle.active,.page-link.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.gradient-text {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    color: transparent !important;
}
.hero-chip,.section-kicker {
    border-color: rgba(210,193,182,.45) !important;
}

    </style>
</head>
<body class="relative overflow-x-hidden">
    <!-- Floating Background Shapes -->
    <div class="floating-shape w-96 h-96 bg-purple-300 top-0 -left-24" style="animation: float 20s ease-in-out infinite;"></div>
    <div class="floating-shape w-80 h-80 bg-blue-300 bottom-0 -right-20" style="animation: float 25s ease-in-out infinite reverse;"></div>
    <div class="wave-animation"></div>
    
    <!-- Sidebar (Desktop) -->
    <?php include '../t_sidebar.php'; ?>
    
    <div class="flex-1 ml-0 lg:ml-64 min-h-screen transition-all duration-300 ease-in-out">
        <!-- Mobile Header -->
        <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 lg:hidden">
            <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
                <div class="bg-indigo-100 p-2 rounded-lg">
                    <i class="fas fa-eye text-indigo-600 text-sm"></i>
                </div>
                <span>My Leaves</span>
            </h1>
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-chalkboard-teacher text-indigo-600"></i>
                </div>
            </div>
        </header>

        <!-- Desktop Header -->
        <header class="hidden lg:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
            <div class="flex-1"></div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <div class="bg-indigo-100 p-2 rounded-lg">
                    <i class="fas fa-eye text-indigo-600 text-xl"></i>
                </div>
                <span>My Leave Applications</span>
            </h1>
            <div class="flex-1 flex justify-end items-center space-x-4">
                <a href="leaves.php" class="btn-purple-pro px-4 py-2 text-white rounded-lg transition-colors flex items-center gap-2">
                    <i class="fas fa-plus"></i>
                    <span>New Application</span>
                </a>
                <div class="animate-pulse bg-indigo-100 rounded-full p-2">
                    <i class="fas fa-chalkboard-teacher text-indigo-600"></i>
                </div>
            </div>
        </header>

        <!-- Mobile Navigation Menu -->
        <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden lg:hidden">
            <div class="absolute left-0 top-0 h-full w-4/5 max-w-xs bg-gradient-to-b from-blue-50 to-indigo-50 shadow-xl transform transition-transform duration-300 -translate-x-full">
                <div class="p-4 border-b border-blue-200 bg-gradient-to-r from-blue-100 to-indigo-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                        </div>
                        <button onclick="toggleMobileMenu()" class="text-gray-500 hover:text-indigo-600 text-xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="mt-4 flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold">
                            <?= strtoupper(substr($trainer['name'] ?? 'T', 0, 1)) ?>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?= htmlspecialchars($trainer['name'] ?? 'Trainer') ?></p>
                            <p class="text-xs text-gray-600">Trainer</p>
                        </div>
                    </div>
                </div>
                <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                    <a href="../dashboard/dashboard.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-tachometer-alt text-gray-500"></i></div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    <a href="../batches/batches.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-users text-gray-500"></i></div>
                        <span class="font-medium">My Batches</span>
                    </a>
                    <a href="../students/students.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-user-graduate text-gray-500"></i></div>
                        <span class="font-medium">My Students</span>
                    </a>
                    <a href="../schedule/schedule.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-calendar-alt text-gray-500"></i></div>
                        <span class="font-medium">Schedule</span>
                    </a>
                    <a href="../attendance/trainer_attendance.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-clipboard-check text-gray-500"></i></div>
                        <span class="font-medium">Attendance</span>
                    </a>
                    <a href="../feedback/weekly_feedback.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-comment-dots text-gray-500"></i></div>
                        <span class="font-medium">Feedback</span>
                    </a>
                    <a href="../exam/trainer_dashboard.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-file-alt text-gray-500"></i></div>
                        <span class="font-medium">Exams</span>
                    </a>
                    <a href="../content/trainer_content.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-tasks text-gray-500"></i></div>
                        <span class="font-medium">Study Materials</span>
                    </a>
                    <a href="leaves.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-calendar-week text-gray-500"></i></div>
                        <span class="font-medium">Apply Leave</span>
                    </a>
                    <a href="view.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 bg-white shadow-md text-purple-600" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-eye text-purple-600"></i></div>
                        <span class="font-medium">My Leaves</span>
                    </a>
                    <a href="../profile.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-white/90 hover:shadow-sm text-gray-700" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-user text-gray-500"></i></div>
                        <span class="font-medium">Profile</span>
                    </a>
                    <a href="../logout.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-red-50 hover:text-red-600 text-gray-700 mt-4 border-t pt-4" onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center"><i class="fas fa-sign-out-alt text-red-500"></i></div>
                        <span class="font-medium">Logout</span>
                    </a>
                </nav>
            </div>
        </div>

        <div class="p-4 lg:p-6 min-h-screen">
            <div class="max-w-6xl mx-auto">
                <!-- Visual Hero -->
                <section class="hero-shell mb-6 animate-fade-in">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-center">
                        <div class="lg:col-span-2">
                            <div class="flex flex-wrap gap-2 mb-4">
                                <span class="hero-pill"><i class="fas fa-eye"></i> Leave Records</span>
                                <span class="hero-pill"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($trainer['name'] ?? 'Trainer') ?></span>
                                <span class="hero-pill"><i class="fas fa-calendar-day"></i> <?= date('l, d M Y') ?></span>
                            </div>
                            <h2 class="text-3xl sm:text-4xl font-black tracking-tight mb-3">My Leave Applications</h2>
                            <p class="text-white/85 text-sm sm:text-base max-w-2xl">
                                Track submitted leave applications, approval status, admin remarks, and cancellation actions from a polished records view.
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="hero-mini">
                                <p class="text-xs uppercase font-black text-white/70">Total</p>
                                <p class="text-3xl font-black mt-1"><?= $stats['total'] ?? 0 ?></p>
                            </div>
                            <div class="hero-mini">
                                <p class="text-xs uppercase font-black text-white/70">Pending</p>
                                <p class="text-3xl font-black mt-1"><?= $stats['pending'] ?? 0 ?></p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                    <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-lg animate-fade-in">
                        <div class="flex items-center"><i class="fas fa-check-circle mr-3 text-xl"></i><span><?= htmlspecialchars($success_message) ?></span></div>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg animate-fade-in">
                        <div class="flex items-center"><i class="fas fa-exclamation-circle mr-3 text-xl"></i><span><?= htmlspecialchars($error_message) ?></span></div>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
                    <div class="section-kicker col-span-2 md:col-span-5"><i class="fas fa-chart-pie"></i> Leave Summary</div>
                    <div class="stat-card p-4 animate-fade-in">
                        <div class="flex justify-between items-start">
                            <div><p class="text-gray-500 text-xs font-medium">Total</p><h3 class="text-2xl font-bold text-gray-800"><?= $stats['total'] ?? 0 ?></h3></div>
                            <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center"><i class="fas fa-file-alt text-blue-600"></i></div>
                        </div>
                    </div>
                    
                    <div class="stat-card p-4 animate-fade-in" style="animation-delay: 0.1s">
                        <div class="flex justify-between items-start">
                            <div><p class="text-gray-500 text-xs font-medium">Pending</p><h3 class="text-2xl font-bold text-yellow-600"><?= $stats['pending'] ?? 0 ?></h3></div>
                            <div class="w-10 h-10 bg-yellow-100 rounded-xl flex items-center justify-center"><i class="fas fa-clock text-yellow-600"></i></div>
                        </div>
                    </div>
                    
                    <div class="stat-card p-4 animate-fade-in" style="animation-delay: 0.2s">
                        <div class="flex justify-between items-start">
                            <div><p class="text-gray-500 text-xs font-medium">Approved</p><h3 class="text-2xl font-bold text-green-600"><?= $stats['approved'] ?? 0 ?></h3></div>
                            <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle text-green-600"></i></div>
                        </div>
                    </div>
                    
                    <div class="stat-card p-4 animate-fade-in" style="animation-delay: 0.3s">
                        <div class="flex justify-between items-start">
                            <div><p class="text-gray-500 text-xs font-medium">Rejected</p><h3 class="text-2xl font-bold text-red-600"><?= $stats['rejected'] ?? 0 ?></h3></div>
                            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center"><i class="fas fa-times-circle text-red-600"></i></div>
                        </div>
                    </div>
                    
                    <div class="stat-card p-4 animate-fade-in" style="animation-delay: 0.4s">
                        <div class="flex justify-between items-start">
                            <div><p class="text-gray-500 text-xs font-medium">Cancelled</p><h3 class="text-2xl font-bold text-gray-600"><?= $stats['cancelled'] ?? 0 ?></h3></div>
                            <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center"><i class="fas fa-ban text-gray-600"></i></div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card feature-shell feature-info p-6 mb-8 animate-fade-in">
                    <div class="section-kicker"><i class="fas fa-filter"></i> Filter Applications</div>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                            <select name="status" class="form-select w-full">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Applications</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Application No. or Reason..." class="form-input w-full">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                            <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input w-full">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                            <div class="flex gap-2">
                                <input type="date" name="date_to" value="<?= $date_to ?>" class="form-input flex-1">
                                <button type="submit" class="btn-primary px-4 py-2 text-white rounded-lg">
                                    <i class="fas fa-search"></i>
                                </button>
                                <a href="view.php" class="btn-secondary px-4 py-2 text-white rounded-lg">
                                    <i class="fas fa-sync-alt"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Applications List -->
                <div class="space-y-4">
                    <div class="section-kicker"><i class="fas fa-list-check"></i> Application Timeline</div>
                    <?php if (empty($applications)): ?>
                        <div class="empty-state-pro bg-white rounded-2xl shadow-lg p-12 text-center">
                            <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-calendar-times text-gray-400 text-4xl"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800 mb-2">No Applications Found</h3>
                            <p class="text-gray-500">You haven't submitted any leave applications yet.</p>
                            <a href="leaves.php" class="btn-purple-pro inline-block mt-4 px-6 py-3 text-white rounded-lg transition-colors">
                                <i class="fas fa-plus mr-2"></i> Apply for Leave
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($applications as $index => $app): ?>
                            <div class="application-card p-6" style="animation: fadeIn 0.5s ease-out forwards; animation-delay: <?= $index * 0.05 ?>s">
                                <div class="flex flex-col lg:flex-row lg:items-start justify-between">
                                    <!-- Application Info -->
                                    <div class="flex-1">
                                        <div class="flex flex-wrap items-center gap-3 mb-4">
                                            <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($app['application_no']) ?></h3>
                                            <span class="status-badge status-<?= $app['status'] ?>">
                                                <i class="fas <?= $app['status'] === 'approved' ? 'fa-check-circle' : ($app['status'] === 'rejected' ? 'fa-times-circle' : ($app['status'] === 'cancelled' ? 'fa-ban' : 'fa-clock')) ?> mr-1"></i>
                                                <?= ucfirst($app['status']) ?>
                                            </span>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <p class="text-xs text-gray-500">Batch</p>
                                                <p class="font-semibold text-gray-800"><?= htmlspecialchars($app['batch_title'] ?? $app['batch_id']) ?></p>
                                            </div>
                                            
                                            <div>
                                                <p class="text-xs text-gray-500">Leave Period</p>
                                                <p class="font-semibold text-gray-800">
                                                    <?= date('d M Y', strtotime($app['start_date'])) ?> - <?= date('d M Y', strtotime($app['end_date'])) ?>
                                                </p>
                                                <p class="text-xs text-gray-500"><?= $app['total_days'] ?> day(s)</p>
                                            </div>
                                            
                                            <div>
                                                <p class="text-xs text-gray-500">Reason Category</p>
                                                <p class="font-semibold text-gray-800"><?= htmlspecialchars($app['reason_category']) ?></p>
                                            </div>
                                            
                                            <div>
                                                <p class="text-xs text-gray-500">Applied On</p>
                                                <p class="font-semibold text-gray-800"><?= date('d M Y', strtotime($app['created_at'])) ?></p>
                                                <p class="text-xs text-gray-500"><?= date('h:i A', strtotime($app['created_at'])) ?></p>
                                            </div>
                                        </div>
                                        
                                        <!-- Detailed Reason Preview -->
                                        <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                                            <p class="text-xs text-gray-500 mb-1">Reason:</p>
                                            <p class="text-sm text-gray-700"><?= htmlspecialchars(substr($app['reason_detail'], 0, 150)) . (strlen($app['reason_detail']) > 150 ? '...' : '') ?></p>
                                        </div>
                                        
                                        <!-- Admin Response (if any) -->
                                        <?php if ($app['status'] === 'rejected' && !empty($app['rejection_reason'])): ?>
                                            <div class="mt-3 p-3 bg-red-50 rounded-lg border-l-4 border-red-500">
                                                <p class="text-xs text-red-800 font-medium">Rejection Reason:</p>
                                                <p class="text-sm text-red-700"><?= htmlspecialchars($app['rejection_reason']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($app['status'] === 'approved' && !empty($app['admin_remarks'])): ?>
                                            <div class="mt-3 p-3 bg-green-50 rounded-lg border-l-4 border-green-500">
                                                <p class="text-xs text-green-800 font-medium">Admin Remarks:</p>
                                                <p class="text-sm text-green-700"><?= htmlspecialchars($app['admin_remarks']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Approval/Rejection Info -->
                                        <?php if ($app['status'] === 'approved' && !empty($app['approved_by_name'])): ?>
                                            <div class="mt-3 text-xs text-green-600">
                                                <i class="fas fa-check-circle mr-1"></i> Approved by <?= htmlspecialchars($app['approved_by_name']) ?> on <?= date('d M Y, h:i A', strtotime($app['approved_at'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($app['status'] === 'rejected' && !empty($app['rejected_by_name'])): ?>
                                            <div class="mt-3 text-xs text-red-600">
                                                <i class="fas fa-times-circle mr-1"></i> Rejected by <?= htmlspecialchars($app['rejected_by_name']) ?> on <?= date('d M Y, h:i A', strtotime($app['rejected_at'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="mt-4 lg:mt-0 lg:ml-6 flex flex-col space-y-2 min-w-[160px]">
                                        <?php if ($app['status'] === 'pending'): ?>
                                            <form method="POST" onsubmit="return confirmCancel()">
                                                <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                                <button type="submit" name="cancel_application" class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all text-center">
                                                    <i class="fas fa-ban mr-2"></i> Cancel Application
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer -->
            <footer class="mt-8 px-6 py-4 border-t border-gray-100 text-center text-sm text-gray-500">
                <p>© <?= date('Y') ?> ASD Academy. All rights reserved.</p>
            </footer>
        </div>
    </div>

    <script>
        function toggleMobileMenu() {
            const mobileMenu = document.getElementById('mobileMenu');
            const mobileMenuContent = mobileMenu.querySelector('div');
            
            if (mobileMenu.classList.contains('hidden')) {
                mobileMenu.classList.remove('hidden');
                setTimeout(() => {
                    mobileMenuContent.classList.remove('-translate-x-full');
                }, 10);
            } else {
                mobileMenuContent.classList.add('-translate-x-full');
                setTimeout(() => {
                    mobileMenu.classList.add('hidden');
                }, 300);
            }
        }

        document.getElementById('mobileMenu').addEventListener('click', function(e) {
            if (e.target.id === 'mobileMenu') {
                toggleMobileMenu();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const mobileMenu = document.getElementById('mobileMenu');
                if (!mobileMenu.classList.contains('hidden')) {
                    toggleMobileMenu();
                }
            }
        });

        function confirmCancel() {
            return confirm('Are you sure you want to cancel this leave application?\n\nThis action cannot be undone.');
        }
    </script>
</body>
</html>