<?php
// Database connection
require_once '../db_connection.php';
session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get workshop ID from URL
$workshop_id = $_GET['id'] ?? '';

// Check if workshop exists
$stmt = $db->prepare("SELECT * FROM workshops WHERE workshop_id = ?");
$stmt->execute([$workshop_id]);
$workshop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$workshop) {
    $_SESSION['error_message'] = "Workshop not found!";
    header("Location: workshop_list.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Delete related workshop materials
        $stmt = $db->prepare("DELETE FROM workshop_materials WHERE workshop_id = ?");
        $stmt->execute([$workshop_id]);
        
        // Delete related workshop schedule
        $stmt = $db->prepare("DELETE FROM workshop_schedule WHERE workshop_id = ?");
        $stmt->execute([$workshop_id]);
        
        // Delete related workshop feedback
        $stmt = $db->prepare("DELETE FROM workshop_feedback WHERE workshop_id = ?");
        $stmt->execute([$workshop_id]);
        
        // Delete related workshop registrations
        $stmt = $db->prepare("DELETE FROM workshop_registrations WHERE workshop_id = ?");
        $stmt->execute([$workshop_id]);
        
        // Finally delete the workshop
        $stmt = $db->prepare("DELETE FROM workshops WHERE workshop_id = ?");
        $stmt->execute([$workshop_id]);
        
        $db->commit();
        
        $_SESSION['success_message'] = "Workshop deleted successfully!";
        header("Location: workshop_list.php");
        exit;
    } catch (PDOException $e) {
        $db->rollBack();
        $_SESSION['error_message'] = "Error deleting workshop: " . $e->getMessage();
        header("Location: workshop_list.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Workshop - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #e0e7ff;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fb;
            color: #4a5568;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        .container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .confirmation-card {
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            padding: 2.5rem;
            max-width: 600px;
            width: 100%;
            text-align: center;
            position: relative;
            overflow: hidden;
            transform: translateY(0);
            transition: all 0.3s ease;
        }
        
        .confirmation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }
        
        .confirmation-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--danger) 0%, #ff4d6d 100%);
        }
        
        .warning-icon {
            font-size: 4rem;
            color: var(--danger);
            margin-bottom: 1.5rem;
            animation: pulse 2s infinite;
        }
        
        h1 {
            color: var(--danger);
            font-size: 1.75rem;
            margin-bottom: 1rem;
        }
        
        .workshop-details {
            background-color: var(--primary-light);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin: 1.5rem 0;
            text-align: left;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 0.75rem;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--dark);
            width: 120px;
        }
        
        .detail-value {
            flex: 1;
            color: #4a5568;
        }
        
        .btn-group {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
            border: none;
        }
        
        .btn-danger:hover {
            background-color: #d1145a;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(247, 37, 133, 0.3);
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background-color: var(--primary-light);
            transform: translateY(-2px);
        }
        
        .btn i {
            margin-right: 0.5rem;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        
        .shake {
            animation: shake 0.5s;
        }
        
        .floating-particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }
        
        .particle {
            position: absolute;
            background-color: rgba(247, 37, 133, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }
        
        .footer {
            text-align: center;
            padding: 1.5rem;
            color: #718096;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen p-4 md:p-6">
        <div class="container animate__animated animate__fadeIn">
            <div class="confirmation-card">
                <div class="floating-particles" id="particles"></div>
                
                <div class="warning-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                
                <h1 class="animate__animated animate__fadeInDown">Confirm Deletion</h1>
                <p class="animate__animated animate__fadeIn animate__delay-1s">
                    You are about to permanently delete this workshop. This action cannot be undone.
                </p>
                
                <div class="workshop-details animate__animated animate__fadeIn animate__delay-1s">
                    <div class="detail-row">
                        <div class="detail-label">Workshop ID:</div>
                        <div class="detail-value"><?= htmlspecialchars($workshop['workshop_id']) ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Title:</div>
                        <div class="detail-value"><?= htmlspecialchars($workshop['title']) ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Date:</div>
                        <div class="detail-value">
                            <?= date('M j, Y', strtotime($workshop['start_datetime'])) ?> - 
                            <?= date('M j, Y', strtotime($workshop['end_datetime'])) ?>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Time:</div>
                        <div class="detail-value">
                            <?= date('g:i A', strtotime($workshop['start_datetime'])) ?> - 
                            <?= date('g:i A', strtotime($workshop['end_datetime'])) ?>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Registrations:</div>
                        <div class="detail-value">
                            <?= $workshop['current_registrations'] ?> / <?= $workshop['max_participants'] ?>
                        </div>
                    </div>
                </div>
                
                <form method="post" class="animate__animated animate__fadeIn animate__delay-2s">
                    <div class="btn-group">
                        <a href="workshop_list.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-danger" id="deleteBtn">
                            <i class="fas fa-trash-alt"></i> Confirm Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Create floating particles
            function createParticles() {
                const container = document.getElementById('particles');
                const particleCount = 15;
                
                for (let i = 0; i < particleCount; i++) {
                    const particle = document.createElement('div');
                    particle.classList.add('particle');
                    
                    // Random size between 5 and 15px
                    const size = Math.random() * 10 + 5;
                    particle.style.width = `${size}px`;
                    particle.style.height = `${size}px`;
                    
                    // Random position
                    particle.style.left = `${Math.random() * 100}%`;
                    particle.style.top = `${Math.random() * 100}%`;
                    
                    // Random animation
                    const duration = Math.random() * 20 + 10;
                    const delay = Math.random() * 5;
                    particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                    
                    container.appendChild(particle);
                }
            }
            
            createParticles();
            
            // Add shake animation to delete button on hover
            $('#deleteBtn').hover(
                function() {
                    $(this).addClass('shake');
                },
                function() {
                    $(this).removeClass('shake');
                }
            );
            
            // Add keyframe animation for floating particles
            const style = document.createElement('style');
            style.innerHTML = `
                @keyframes float {
                    0% {
                        transform: translateY(0) translateX(0);
                        opacity: 1;
                    }
                    50% {
                        transform: translateY(-50px) translateX(20px);
                        opacity: 0.7;
                    }
                    100% {
                        transform: translateY(0) translateX(0);
                        opacity: 1;
                    }
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>