
<?php
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'ASD Academy';
if (isset($_SESSION['user_role'])) {
    if ($_SESSION['user_role'] === 'admin') $page_title = 'Admin Dashboard - ASD Academy';
    elseif ($_SESSION['user_role'] === 'student') $page_title = 'Student Dashboard - ASD Academy';
    elseif ($_SESSION['user_role'] === 'trainer') $page_title = 'Trainer Dashboard - ASD Academy';
}
?>

<!-- header.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <!-- Primary Tailwind CDN with fallback -->
    <link rel="stylesheet" href="assets/css/tailwind.min.css">
    <!-- Add this before your custom script -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        window.Tailwind || document.write('<script src="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.3/dist/tailwind.min.js"><\/script>')
    </script>
    <!-- Font Awesome from jsDelivr -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
        .sidebar-link:hover {
            background-color: #f0f7ff;
        }
        .sidebar-link.active {
            background-color: #e1f0ff;
            border-left: 4px solid #3b82f6;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .info-card {
            transition: all 0.2s ease;
        }
        .info-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
    </style>

</head>
<body class="bg-gray-50 text-gray-800">