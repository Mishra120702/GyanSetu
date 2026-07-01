<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_trainer'])) {
    // Prepare trainer data
    $trainerData = [
        'name' => $_POST['name'],
        'email' => $_POST['email'],
        'specialization' => $_POST['specialization'] ?? null,
        'years_of_experience' => $_POST['experience'] ?? 0,
        'bio' => $_POST['bio'] ?? null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
        'profile_picture' => null
    ];

    // Handle profile picture upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "../uploads/profile_pictures/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
        $new_filename = "trainer_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        // Check if image file is a actual image
        $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
        if ($check !== false) {
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                $trainerData['profile_picture'] = $target_file;
            }
        }
    }
    
    // Validate data
    $errors = validateTrainerData($trainerData);
    
    if (empty($errors)) {
        if (createTrainer($db, $trainerData)) {
            // Send welcome email to trainer
            $emailSent = sendWelcomeEmail($trainerData);
            
            // Redirect to dashboard with success message
            $redirectParams = ['success' => 'trainer_created'];
            if (!$emailSent) {
                $redirectParams['email_status'] = 'failed';
            }
            header("Location: ../dashboard/dashboard.php?" . http_build_query($redirectParams));
            exit();
        } else {
            $errors[] = 'Failed to create trainer. Please try again.';
        }
    }
}

function sendWelcomeEmail(array $trainerData): bool {
    $to = $trainerData['email'];
    $subject = 'Welcome to Our Institution as a Trainer';
    
    $message = "
    <html>
    <head>
        <title>Welcome to Our Institution</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .header { background-color: #3b82f6; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .footer { background-color: #f3f4f6; padding: 10px; text-align: center; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>Welcome to Our Institution</h1>
        </div>
        <div class='content'>
            <p>Dear {$trainerData['name']},</p>
            <p>We are pleased to inform you that your trainer account has been successfully created.</p>
            <p>Here are your login details:</p>
            <ul>
                <li><strong>Email:</strong> {$trainerData['email']}</li>
                <li><strong>Password:</strong> The password you provided during registration</li>
            </ul>
            <p>Please keep this information secure and do not share it with anyone.</p>
            <p>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
            <p>Best regards,<br>The Administration Team</p>
        </div>
        <div class='footer'>
            <p>This is an automated message. Please do not reply directly to this email.</p>
        </div>
    </body>
    </html>
    ";
    
    // Always set content-type when sending HTML email
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    
    // Additional headers
    $headers .= "From: Your Institution <noreply@yourinstitution.com>\r\n";
    $headers .= "Reply-To: support@yourinstitution.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    try {
        return mail($to, $subject, $message, $headers);
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

function generateTrainerId(PDO $db): string {
    $lastTrainer = $db->query("SELECT id FROM trainers ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $nextTrainerId = 'TRN001'; // Default if no trainers exist

    if ($lastTrainer) {
        // Extract the numeric part and increment
        $lastNumber = (int) $lastTrainer['id'];
        $nextNumber = $lastNumber + 1;
        $nextTrainerId = 'TRN' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }

    return $nextTrainerId;
}

function createTrainer(PDO $db, array $trainerData): bool {
    try {
        // Start transaction
        $db->beginTransaction();
        
        // First create user
        $userStmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'mentor')");
        $userStmt->execute([
            $trainerData['name'],
            $trainerData['email'],
            $trainerData['password']
        ]);
        
        $userId = $db->lastInsertId();
        
        // Create trainer record
        $trainerStmt = $db->prepare("INSERT INTO trainers (
            user_id, name, email, specialization, 
            years_of_experience, bio, is_active, profile_picture
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $result = $trainerStmt->execute([
            $userId,
            $trainerData['name'],
            $trainerData['email'],
            $trainerData['specialization'],
            $trainerData['years_of_experience'],
            $trainerData['bio'],
            $trainerData['is_active'],
            $trainerData['profile_picture']
        ]);
        
        $db->commit();
        return $result;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error creating trainer: " . $e->getMessage());
        return false;
    }
}

function validateTrainerData(array $data): array {
    $errors = [];
    
    if (empty($data['name'])) {
        $errors[] = 'Name is required';
    }
    
    if (empty($data['email'])) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($_POST['password'])) {
        $errors[] = 'Password is required';
    } elseif (strlen($_POST['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }
    
    return $errors;
}

$nextTrainerId = generateTrainerId($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Trainer</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
        }
        
        .form-container {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
        }
        
        .form-container:hover {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .profile-avatar {
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .profile-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        
        .input-field {
            transition: all 0.3s ease;
        }
        
        .input-field:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary {
            transition: all 0.3s ease;
            background-image: linear-gradient(to right, #3b82f6, #6366f1);
            background-size: 200% auto;
        }
        
        .btn-primary:hover {
            background-position: right center;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary {
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .floating-label {
            position: absolute;
            top: 0;
            left: 0;
            pointer-events: none;
            transition: all 0.2s ease;
            transform-origin: left top;
            color: #6b7280;
        }
        
        .input-container {
            position: relative;
            padding-top: 1.5rem;
        }
        
        .input-container input:focus + .floating-label,
        .input-container input:not(:placeholder-shown) + .floating-label,
        .input-container textarea:focus + .floating-label,
        .input-container textarea:not(:placeholder-shown) + .floating-label {
            transform: translateY(-0.5rem) scale(0.85);
            color: #3b82f6;
        }
        
        .section-title {
            position: relative;
            padding-bottom: 0.5rem;
        }
        
        .section-title:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, #3b82f6, #6366f1);
            border-radius: 3px;
        }
        
        .success-message {
            animation: fadeInUp 0.5s ease;
        }
        
        .error-message {
            animation: shake 0.5s ease;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="ml-64 p-6">
        <div class="container mx-auto max-w-5xl animate__animated animate__fadeIn">
            <div class="bg-white rounded-xl form-container overflow-hidden p-8">
                <!-- Header with gradient background -->
                <div class="bg-gradient-to-r from-blue-500 to-indigo-600 text-white p-6 rounded-t-xl -mx-8 -mt-8 mb-8">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-2xl font-bold">Add New Trainer</h2>
                            <p class="text-blue-100">Fill in the trainer details below</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="index.php" class="bg-white text-blue-600 hover:bg-blue-50 px-4 py-2 rounded-lg flex items-center transition-all duration-300 transform hover:scale-105">
                                <i class="fas fa-arrow-left mr-2"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded error-message" role="alert">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium">There were errors with your submission</h3>
                                <ul class="mt-2 text-sm list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form id="trainerForm" method="post" enctype="multipart/form-data" class="space-y-8">
                    <input type="hidden" name="add_trainer" value="1">
                    
                    <!-- Profile Picture Section -->
                    <div class="flex items-center space-x-6">
                        <div class="relative group">
                            <div class="w-32 h-32 rounded-full bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center overflow-hidden profile-avatar">
                                <img id="profilePreview" class="hidden w-full h-full object-cover" src="" alt="Profile Preview">
                                <i class="fas fa-user-tie text-5xl text-blue-500" id="defaultAvatar"></i>
                            </div>
                            <label for="profile_picture" class="absolute bottom-0 right-0 bg-blue-600 text-white rounded-full p-3 cursor-pointer hover:bg-blue-700 transition-all duration-300 transform hover:scale-110 shadow-lg group-hover:shadow-xl">
                                <i class="fas fa-camera"></i>
                                <input type="file" id="profile_picture" name="profile_picture" class="hidden" accept="image/*" onchange="previewImage(this)">
                            </label>
                        </div>
                        <div>
                            <h3 class="text-lg font-medium text-gray-800">Profile Photo</h3>
                            <p class="text-sm text-gray-500 mt-1">Upload a clear photo of the trainer (JPEG, PNG)</p>
                            <p class="text-xs text-gray-400 mt-2">Max. file size: 5MB</p>
                        </div>
                    </div>
                    
                    <!-- Trainer Information Section -->
                    <div class="space-y-6">
                        <h3 class="text-xl font-semibold text-gray-800 section-title">Trainer Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Trainer ID -->
                            <div class="input-container">
                                <input type="text" id="trainer_id" name="trainer_id" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg input-field bg-gray-50"
                                       value="<?= htmlspecialchars($nextTrainerId) ?>" readonly
                                       placeholder=" ">
                                <label for="trainer_id" class="floating-label">Trainer ID</label>
                            </div>
                            
                            <!-- Name -->
                            <div class="input-container">
                                <input type="text" id="name" name="name" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg input-field"
                                       value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>"
                                       required placeholder=" ">
                                <label for="name" class="floating-label">Full Name*</label>
                            </div>
                            
                            <!-- Email -->
                            <div class="input-container">
                                <input type="email" id="email" name="email" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg input-field"
                                       value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                                       required placeholder=" ">
                                <label for="email" class="floating-label">Email*</label>
                            </div>
                            
                            <!-- Specialization -->
                            <div class="input-container">
                                <input type="text" id="specialization" name="specialization" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg input-field"
                                       value="<?= isset($_POST['specialization']) ? htmlspecialchars($_POST['specialization']) : '' ?>"
                                       placeholder=" ">
                                <label for="specialization" class="floating-label">Specialization</label>
                            </div>
                            
                            <!-- Experience -->
                            <div class="input-container">
                                <input type="number" id="experience" name="experience" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg input-field"
                                       value="<?= isset($_POST['experience']) ? htmlspecialchars($_POST['experience']) : 0 ?>"
                                       min="0" placeholder=" ">
                                <label for="experience" class="floating-label">Years of Experience</label>
                            </div>
                            
                            <!-- Password -->
                            <div class="input-container">
                                <input type="password" id="password" name="password" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg input-field"
                                       required placeholder=" ">
                                <label for="password" class="floating-label">Password*</label>
                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                    <i class="far fa-eye-slash text-gray-400 cursor-pointer" id="togglePassword"></i>
                                </div>
                            </div>
                            
                            <!-- Bio -->
                            <div class="input-container md:col-span-2">
                                <textarea id="bio" name="bio" rows="3"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg input-field"
                                       placeholder=" "><?= isset($_POST['bio']) ? htmlspecialchars($_POST['bio']) : '' ?></textarea>
                                <label for="bio" class="floating-label">Bio/Description</label>
                            </div>
                            
                            <!-- Active Status -->
                            <div class="input-container flex items-center">
                                <input type="checkbox" id="is_active" name="is_active" 
                                       class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500"
                                       <?= (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : '' ?>>
                                <label for="is_active" class="ml-2 text-sm font-medium text-gray-900">Active Trainer</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-4 pt-8 border-t border-gray-200">
                        <a href="index.php" class="px-6 py-3 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 btn-secondary">
                            <i class="fas fa-times mr-2"></i> Cancel
                        </a>
                        <button type="submit" 
                                class="px-6 py-3 rounded-lg text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 btn-primary pulse-animation">
                            <i class="fas fa-user-plus mr-2"></i> Create Trainer
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Floating notification for success -->
            <div id="successNotification" class="fixed bottom-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg hidden transform transition-all duration-300 translate-y-4 opacity-0">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Trainer created successfully!</span>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        // Profile picture preview
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#profilePreview').attr('src', e.target.result).removeClass('hidden');
                    $('#defaultAvatar').addClass('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Toggle password visibility
        $('#togglePassword').click(function() {
            const passwordField = $('#password');
            const type = passwordField.attr('type') === 'password' ? 'text' : 'password';
            passwordField.attr('type', type);
            $(this).toggleClass('fa-eye-slash fa-eye');
        });
        
        // Form submission animation
        $('#trainerForm').submit(function(e) {
            const form = $(this);
            if (form[0].checkValidity()) {
                $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i> Processing...').prop('disabled', true);
                
                // Simulate success notification (in a real app, this would be after AJAX success)
                setTimeout(() => {
                    $('#successNotification').removeClass('hidden').removeClass('translate-y-4').removeClass('opacity-0').addClass('translate-y-0').addClass('opacity-100');
                    
                    setTimeout(() => {
                        $('#successNotification').addClass('translate-y-4').addClass('opacity-0');
                    }, 3000);
                }, 1500);
            }
        });
        
        // Floating label functionality
        $('.input-container input, .input-container textarea').each(function() {
            if ($(this).val() !== '') {
                $(this).next('.floating-label').addClass('transformed');
            }
        });
        
        $('.input-container input, .input-container textarea').on('input change', function() {
            if ($(this).val() !== '') {
                $(this).next('.floating-label').addClass('transformed');
            } else {
                $(this).next('.floating-label').removeClass('transformed');
            }
        });
        
        // Animate form elements on load
        $(document).ready(function() {
            $('.input-container').each(function(index) {
                $(this).delay(100 * index).queue(function() {
                    $(this).addClass('animate__animated animate__fadeInUp').dequeue();
                });
            });
        });
    </script>
</body>
</html>