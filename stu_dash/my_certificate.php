<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_user_id = $_SESSION['user_id'];

// Get student information
$student_query = $db->prepare("
    SELECT s.*, u.email 
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Certificate - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1B3C53',
                        secondary: '#234C6A',
                        cardColor: '#456882',
                        contentColor: '#D2C1B6',
                        sidebarBg: '#F7F5F3',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
            100% { transform: translateY(0px); }
        }
        
        .float-animation {
            animation: float 3s ease-in-out infinite;
        }
        
        .pulse-glow {
            animation: pulse-glow-anim 2s infinite;
        }
        
        @keyframes pulse-glow-anim {
            0%   { box-shadow: 0 0 0 0 rgba(69, 104, 130, 0.45); }
            70%  { box-shadow: 0 0 0 20px rgba(69, 104, 130, 0); }
            100% { box-shadow: 0 0 0 0 rgba(69, 104, 130, 0); }
        }
    </style>
</head>
<body class="bg-sidebarBg min-h-screen flex flex-col" style="background-color:#F7F5F3;">
    <?php include '../header.php'; ?>
    <?php include '../s_sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out flex flex-col relative overflow-hidden">
        
        <!-- Decorative Background Elements -->
        <div class="absolute top-0 right-0 -mt-20 -mr-20 w-80 h-80 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse" style="background-color:#456882;"></div>
        <div class="absolute bottom-0 left-0 -mb-20 -ml-20 w-72 h-72 rounded-full mix-blend-multiply filter blur-3xl opacity-15 animate-pulse" style="background-color:#D2C1B6; animation-delay:2s;"></div>

        <!-- Mobile Header -->
        <header class="shadow-lg px-4 py-4 flex justify-between items-center sticky top-0 z-30 md:hidden relative" style="background: linear-gradient(to right, #1B3C53, #234C6A);">
            <button class="text-white text-xl" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            
            <h1 class="text-lg font-bold text-white flex items-center space-x-2">
                <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                    <i class="fas fa-certificate text-white text-sm"></i>
                </div>
                <span>My Certificate</span>
            </h1>
            
            <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                <i class="fas fa-user-graduate" style="color:#1B3C53;"></i>
            </div>
        </header>

        <!-- Main Content (Coming Soon) -->
        <div class="flex-1 flex items-center justify-center p-4 relative z-10 mt-10 md:mt-0">
            <div class="text-center max-w-lg mx-auto bg-white/90 backdrop-blur-sm p-10 md:p-12 rounded-3xl shadow-2xl border border-white/50 transform hover:scale-105 transition-all duration-500" style="border-color: rgba(210,193,182,0.4);">
                <div class="w-28 h-28 rounded-full flex items-center justify-center mx-auto mb-8 shadow-lg pulse-glow" style="background: linear-gradient(135deg, #456882, #234C6A);">
                    <i class="fas fa-award text-5xl text-white float-animation drop-shadow-md"></i>
                </div>
                <h2 class="text-4xl font-extrabold mb-4" style="color:#1B3C53;">
                    Coming Soon!
                </h2>
                <div class="w-16 h-1 mx-auto rounded-full mb-6" style="background: linear-gradient(to right, #1B3C53, #456882);"></div>
                <p class="text-gray-600 text-lg mb-8 leading-relaxed">
                    We're building a seamless certificate generation experience. Soon you'll be able to view, download, and proudly share your course completion certificates from right here.
                </p>
                <a href="dashboard.php" class="inline-flex items-center justify-center px-8 py-3.5 text-white rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-300 font-semibold group" style="background:#1B3C53;" onmouseover="this.style.background='#234C6A'" onmouseout="this.style.background='#1B3C53'">
                    <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</body>
</html>
