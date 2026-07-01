<?php
/**
 * admin_credentials.php
 * Admin Credentials Management
 */

include '../db_connection.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Check if user is admin
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: ../dashboard/dashboard.php");
    exit;
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_username = trim($_POST['current_username']);
    $current_password = $_POST['current_password'];
    $new_username = trim($_POST['new_username']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Get current admin info
    $stmt = $db->prepare("SELECT name, password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Validate current credentials
    if ($current_username !== $admin_info['name']) {
        $error_message = "Current username is incorrect.";
    } elseif (!password_verify($current_password, $admin_info['password_hash'])) {
        $error_message = "Current password is incorrect.";
    } elseif (empty($new_username)) {
        $error_message = "New username cannot be empty.";
    } elseif (strlen($new_username) < 3) {
        $error_message = "New username must be at least 3 characters long.";
    } elseif (empty($new_password)) {
        $error_message = "New password cannot be empty.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $error_message = "New password must be at least 8 characters long.";
    } else {
        // Check if new username already exists (excluding current admin)
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE name = ? AND id != ?");
        $stmt->execute([$new_username, $_SESSION['user_id']]);
        $username_exists = $stmt->fetchColumn();
        
        if ($username_exists) {
            $error_message = "Username already exists. Please choose another.";
        } else {
            // Check password strength
            $uppercase = preg_match('@[A-Z]@', $new_password);
            $lowercase = preg_match('@[a-z]@', $new_password);
            $number = preg_match('@[0-9]@', $new_password);
            $specialChars = preg_match('@[^\w]@', $new_password);
            
            if (!$uppercase || !$lowercase || !$number || !$specialChars) {
                $error_message = "Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.";
            } else {
                // Update admin credentials
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET name = ?, password_hash = ? WHERE id = ?");
                if ($stmt->execute([$new_username, $hashed_password, $_SESSION['user_id']])) {
                    // Update session with new username
                    $_SESSION['user_name'] = $new_username;
                    
                    // Log the action
                    $stmt = $db->prepare("INSERT INTO user_lock_logs (user_id, action, reason, performed_by) VALUES (?, 'password_change', 'Admin credentials updated', ?)");
                    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
                    
                    $success_message = "Admin credentials updated successfully! Please log in again with your new credentials.";
                    
                    // Clear session (force re-login)
                    session_destroy();
                    
                    // Redirect to login after 3 seconds
                    header("Refresh: 3; url=../login.php");
                } else {
                    $error_message = "Failed to update credentials. Please try again.";
                }
            }
        }
    }
}

// Get current admin info
$stmt = $db->prepare("SELECT name, email, created_at, last_login FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin_info = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Credentials - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        /* Brand Colour Theme: #1B3C53 · #234C6A · #456882 · #D2C1B6 */
        :root {
            --brand-darkest:  #1B3C53;
            --brand-dark:     #234C6A;
            --brand-mid:      #456882;
            --brand-light:    #A4C4D4;
            --brand-sand:     #D2C1B6;
            --primary-gradient: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
            --accent-gradient:  linear-gradient(135deg, #456882 0%, #234C6A 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(145deg, #e8eef3 0%, #d6e4ed 30%, #e4edf4 60%, #dce8f0 100%) !important;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Soft decorative blobs matching dashboard */
        body::before {
            content: '';
            position: fixed;
            top: -120px; left: -120px;
            width: 420px; height: 420px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(27,60,83,0.1) 0%, transparent 70%);
            animation: driftOrb1 20s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -100px; right: -100px;
            width: 380px; height: 380px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(69,104,130,0.09) 0%, transparent 70%);
            animation: driftOrb2 25s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        @keyframes driftOrb1 {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(60px,50px) scale(1.1); }
        }
        @keyframes driftOrb2 {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(-50px,-60px) scale(1.12); }
        }
        
        .container-fluid {
            position: relative;
            z-index: 1;
        }

        h1, h2, h3, h4, h5, h6, strong {
            color: #1B3C53 !important;
        }

        .card {
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            border: 1px solid rgba(27, 60, 83, 0.10) !important;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
            border-radius: 20px !important;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(27, 60, 83, 0.12) !important;
        }
        
        .form-control {
            border: 1px solid rgba(27, 60, 83, 0.15) !important;
            border-radius: 8px !important;
            transition: all 0.3s ease !important;
        }
        
        .form-control:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 0.25rem rgba(35, 76, 106, 0.25) !important;
        }
        
        .password-strength {
            height: 6px;
            background: #e2e8f0;
            margin-top: 8px;
            border-radius: 3px;
            overflow: hidden;
            position: relative;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease;
            border-radius: 3px;
        }
        
        .password-weak { background: #c0392b !important; }
        .password-fair { background: #b6876a !important; }
        .password-good { background: #456882 !important; }
        .password-strong { background: #2d7a8a !important; }
        .password-very-strong { background: #1B3C53 !important; }
        
        .requirement-list {
            list-style: none;
            padding-left: 0;
        }
        
        .requirement-list li {
            padding: 5px 0;
            color: #6c757d;
            transition: all 0.3s ease;
        }
        
        .requirement-list li.valid {
            color: #2d7a8a;
        }
        
        .requirement-list li i {
            width: 20px;
            text-align: center;
        }
        
        .input-group-text {
            background: var(--accent-gradient) !important;
            color: white !important;
            border: none !important;
            border-top-left-radius: 8px !important;
            border-bottom-left-radius: 8px !important;
        }
        
        .btn-gradient {
            background: var(--primary-gradient) !important;
            color: white !important;
            border: none !important;
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 10px !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.2) !important;
        }
        
        .btn-gradient:hover:not(:disabled) {
            background: var(--accent-gradient) !important;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(27, 60, 83, 0.3) !important;
        }

        .btn-gradient:disabled {
            opacity: 0.65;
            box-shadow: none !important;
            cursor: not-allowed;
        }

        .btn-outline-secondary {
            border: 1px solid rgba(27, 60, 83, 0.2) !important;
            color: #234C6A !important;
            border-radius: 10px !important;
            transition: all 0.3s ease !important;
        }

        .btn-outline-secondary:hover {
            background-color: rgba(35, 76, 106, 0.08) !important;
            color: #1B3C53 !important;
            border-color: #234C6A !important;
        }
        
        .admin-info-card {
            background: var(--primary-gradient) !important;
            color: white !important;
            border-radius: 20px !important;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.15) !important;
        }

        .admin-info-card * {
            color: white !important;
        }
        
        .alert-custom {
            border-radius: 15px !important;
            border: none !important;
            box-shadow: 0 5px 15px rgba(27, 60, 83, 0.08) !important;
        }

        .alert-success {
            background: linear-gradient(135deg, #eaf2f1 0%, #cce0dd 100%) !important;
            color: #2d7a8a !important;
        }

        .alert-danger {
            background: linear-gradient(135deg, #fdf3f2 0%, #f9d5d2 100%) !important;
            color: #c0392b !important;
        }

        .alert-warning {
            background: linear-gradient(135deg, #faf3ec 0%, #eed6c5 100%) !important;
            color: #b6876a !important;
        }
        
        .toggle-password {
            cursor: pointer;
            transition: all 0.3s ease;
            border-top-right-radius: 8px !important;
            border-bottom-right-radius: 8px !important;
        }
        
        .toggle-password:hover {
            transform: scale(1.05);
        }

        .breadcrumb-item a {
            color: #456882 !important;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb-item a:hover {
            color: #1B3C53 !important;
        }

        .breadcrumb-item.active {
            color: #1B3C53 !important;
        }
    </style>
</head>
<body><?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="container-fluid" style="margin-left: 250px; padding: 30px;">
        <div class="row">
            <div class="col-12">
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="admin_dashboard.php"><i class="fas fa-home"></i> Admin Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-key"></i> Admin Credentials</li>
                    </ol>
                </nav>
                
                <h1 class="mb-4 fw-bold text-primary">
                    <i class="fas fa-user-shield me-2"></i>Admin Credentials Management
                </h1>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-custom animate__animated animate__fadeIn">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                        <p class="mb-0 mt-2"><small>You will be redirected to login page in 3 seconds...</small></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-custom animate__animated animate__fadeIn">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="row">
            <!-- Admin Information -->
            <div class="col-lg-4 mb-4">
                <div class="admin-info-card">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-lg bg-white rounded-circle p-3 me-3">
                            <i class="fas fa-user-shield text-primary fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-1"><?php echo htmlspecialchars($admin_info['name']); ?></h3>
                            <p class="mb-0 opacity-75">Administrator</p>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span><i class="fas fa-envelope me-2"></i>Email</span>
                            <span class="fw-medium"><?php echo htmlspecialchars($admin_info['email']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span><i class="fas fa-calendar-alt me-2"></i>Account Created</span>
                            <span class="fw-medium"><?php echo date('M d, Y', strtotime($admin_info['created_at'])); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span><i class="fas fa-sign-in-alt me-2"></i>Last Login</span>
                            <span class="fw-medium">
                                <?php echo $admin_info['last_login'] ? date('M d, Y H:i', strtotime($admin_info['last_login'])) : 'Never'; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-3 border-top border-white border-opacity-25">
                        <small class="opacity-75">
                            <i class="fas fa-info-circle me-1"></i>
                            Changing credentials will log you out automatically.
                        </small>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><i class="fas fa-shield-alt text-primary me-2"></i>Security Tips</h5>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Use strong, unique passwords</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Enable 2FA if available</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Never share your credentials</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Change password regularly</li>
                            <li><i class="fas fa-check-circle text-success me-2"></i>Log out when not using</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Change Credentials Form -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body p-4">
                        <h4 class="card-title mb-4 fw-bold">
                            <i class="fas fa-key me-2"></i>Change Admin Credentials
                        </h4>
                        
                        <form method="POST" id="changeCredentialsForm">
                            <!-- Current Credentials -->
                            <div class="mb-4">
                                <h5 class="mb-3 text-muted"><i class="fas fa-lock me-2"></i>Current Credentials</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Current Username</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="current_username" 
                                                   value="<?php echo htmlspecialchars($admin_info['name']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Current Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                                            <input type="password" class="form-control" id="current_password" 
                                                   name="current_password" required>
                                            <span class="input-group-text toggle-password" 
                                                  onclick="togglePassword('current_password', 'current_password_icon')">
                                                <i class="fas fa-eye" id="current_password_icon"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- New Credentials -->
                            <div class="mb-4">
                                <h5 class="mb-3 text-muted"><i class="fas fa-lock-open me-2"></i>New Credentials</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">New Username</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user-plus"></i></span>
                                            <input type="text" class="form-control" name="new_username" 
                                                   id="new_username" required oninput="checkUsernameAvailability()">
                                        </div>
                                        <small class="text-muted mt-1 d-block" id="usernameFeedback"></small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">New Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                                            <input type="password" class="form-control" id="new_password" 
                                                   name="new_password" required oninput="checkPasswordStrength()">
                                            <span class="input-group-text toggle-password" 
                                                  onclick="togglePassword('new_password', 'new_password_icon')">
                                                <i class="fas fa-eye" id="new_password_icon"></i>
                                            </span>
                                        </div>
                                        <div class="password-strength mt-2">
                                            <div class="password-strength-bar" id="password-strength-bar"></div>
                                        </div>
                                        <small class="text-muted mt-1" id="passwordStrengthText"></small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Confirm New Password -->
                            <div class="mb-4">
                                <label class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    <input type="password" class="form-control" id="confirm_password" 
                                           name="confirm_password" required oninput="checkPasswordMatch()">
                                    <span class="input-group-text toggle-password" 
                                          onclick="togglePassword('confirm_password', 'confirm_password_icon')">
                                        <i class="fas fa-eye" id="confirm_password_icon"></i>
                                    </span>
                                </div>
                                <small class="text-muted mt-1" id="passwordMatchText"></small>
                            </div>
                            
                            <!-- Password Requirements -->
                            <div class="mb-4">
                                <h6 class="mb-2"><i class="fas fa-list-check me-2"></i>Password Requirements</h6>
                                <ul class="requirement-list" id="passwordRequirements">
                                    <li id="reqLength"><i class="fas fa-circle me-2"></i>At least 8 characters</li>
                                    <li id="reqUppercase"><i class="fas fa-circle me-2"></i>At least one uppercase letter</li>
                                    <li id="reqLowercase"><i class="fas fa-circle me-2"></i>At least one lowercase letter</li>
                                    <li id="reqNumber"><i class="fas fa-circle me-2"></i>At least one number</li>
                                    <li id="reqSpecial"><i class="fas fa-circle me-2"></i>At least one special character</li>
                                </ul>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="admin_dashboard.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                </a>
                                <button type="submit" class="btn-gradient" id="submitBtn" disabled>
                                    <i class="fas fa-save me-2"></i>Update Credentials
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Security Warning -->
                <div class="alert alert-warning alert-custom mt-4">
                    <div class="d-flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle fa-2x"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="alert-heading">Security Notice</h5>
                            <p class="mb-1">Changing your credentials will:</p>
                            <ul class="mb-0">
                                <li>Log you out from all devices immediately</li>
                                <li>Require you to log in again with new credentials</li>
                                <li>Invalidate any active sessions</li>
                                <li>Be logged in the security audit trail</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(fieldId, iconId) {
            const passwordInput = document.getElementById(fieldId);
            const icon = document.getElementById(iconId);
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function checkUsernameAvailability() {
            const username = document.getElementById('new_username').value;
            const feedback = document.getElementById('usernameFeedback');
            const currentUsername = '<?php echo $admin_info['name']; ?>';
            
            if (username === currentUsername) {
                feedback.innerHTML = '<span class="text-info"><i class="fas fa-info-circle me-1"></i>Same as current username</span>';
                return true;
            }
            
            if (username.length < 3) {
                feedback.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Must be at least 3 characters</span>';
                return false;
            }
            
            // Simulate AJAX check (in real implementation, make an AJAX call)
            feedback.innerHTML = '<span class="text-warning"><i class="fas fa-spinner fa-spin me-1"></i>Checking availability...</span>';
            
            setTimeout(() => {
                // This would be replaced with actual AJAX response
                const isAvailable = username !== 'admin' && username !== 'administrator' && !username.includes(' ');
                if (isAvailable) {
                    feedback.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Username available</span>';
                } else {
                    feedback.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Username not available</span>';
                }
                validateForm();
            }, 500);
            
            return false;
        }
        
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('password-strength-bar');
            const strengthText = document.getElementById('passwordStrengthText');
            
            // Reset requirements
            document.getElementById('reqLength').classList.remove('valid');
            document.getElementById('reqUppercase').classList.remove('valid');
            document.getElementById('reqLowercase').classList.remove('valid');
            document.getElementById('reqNumber').classList.remove('valid');
            document.getElementById('reqSpecial').classList.remove('valid');
            
            let strength = 0;
            let requirementsMet = 0;
            const totalRequirements = 5;
            
            // Check length
            if (password.length >= 8) {
                strength += 20;
                requirementsMet++;
                document.getElementById('reqLength').classList.add('valid');
                document.getElementById('reqLength').innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>At least 8 characters';
            } else {
                document.getElementById('reqLength').innerHTML = '<i class="fas fa-circle me-2"></i>At least 8 characters';
            }
            
            // Check uppercase
            if (password.match(/[A-Z]/)) {
                strength += 20;
                requirementsMet++;
                document.getElementById('reqUppercase').classList.add('valid');
                document.getElementById('reqUppercase').innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>At least one uppercase letter';
            } else {
                document.getElementById('reqUppercase').innerHTML = '<i class="fas fa-circle me-2"></i>At least one uppercase letter';
            }
            
            // Check lowercase
            if (password.match(/[a-z]/)) {
                strength += 20;
                requirementsMet++;
                document.getElementById('reqLowercase').classList.add('valid');
                document.getElementById('reqLowercase').innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>At least one lowercase letter';
            } else {
                document.getElementById('reqLowercase').innerHTML = '<i class="fas fa-circle me-2"></i>At least one lowercase letter';
            }
            
            // Check number
            if (password.match(/[0-9]/)) {
                strength += 20;
                requirementsMet++;
                document.getElementById('reqNumber').classList.add('valid');
                document.getElementById('reqNumber').innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>At least one number';
            } else {
                document.getElementById('reqNumber').innerHTML = '<i class="fas fa-circle me-2"></i>At least one number';
            }
            
            // Check special character
            if (password.match(/[^a-zA-Z0-9]/)) {
                strength += 20;
                requirementsMet++;
                document.getElementById('reqSpecial').classList.add('valid');
                document.getElementById('reqSpecial').innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>At least one special character';
            } else {
                document.getElementById('reqSpecial').innerHTML = '<i class="fas fa-circle me-2"></i>At least one special character';
            }
            
            // Update strength bar
            strengthBar.style.width = strength + '%';
            strengthBar.className = 'password-strength-bar';
            
            if (strength <= 20) {
                strengthBar.classList.add('password-weak');
                strengthText.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Very Weak</span>';
            } else if (strength <= 40) {
                strengthBar.classList.add('password-fair');
                strengthText.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>Weak</span>';
            } else if (strength <= 60) {
                strengthBar.classList.add('password-good');
                strengthText.innerHTML = '<span class="text-warning"><i class="fas fa-check-circle me-1"></i>Good</span>';
            } else if (strength <= 80) {
                strengthBar.classList.add('password-strong');
                strengthText.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Strong</span>';
            } else {
                strengthBar.classList.add('password-very-strong');
                strengthText.innerHTML = '<span class="text-success"><i class="fas fa-shield-alt me-1"></i>Very Strong</span>';
            }
            
            checkPasswordMatch();
            validateForm();
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('passwordMatchText');
            
            if (!confirm) {
                matchText.innerHTML = '';
                return;
            }
            
            if (password === confirm) {
                matchText.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Passwords match</span>';
            } else {
                matchText.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Passwords do not match</span>';
            }
            
            validateForm();
        }
        
        function validateForm() {
            const username = document.getElementById('new_username').value;
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const currentPassword = document.getElementById('current_password').value;
            const submitBtn = document.getElementById('submitBtn');
            
            // Check all requirements
            const requirements = document.querySelectorAll('.requirement-list li.valid');
            const allRequirementsMet = requirements.length === 5;
            
            const passwordsMatch = password === confirm && password.length > 0;
            const usernameValid = username.length >= 3;
            const currentPasswordValid = currentPassword.length > 0;
            
            // Enable button only if all conditions are met
            if (allRequirementsMet && passwordsMatch && usernameValid && currentPasswordValid) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Update Credentials';
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-lock me-2"></i>Complete All Requirements';
            }
        }
        
        // Initialize form validation
        document.addEventListener('DOMContentLoaded', function() {
            validateForm();
            
            // Add real-time validation
            document.getElementById('current_password').addEventListener('input', validateForm);
            document.getElementById('new_username').addEventListener('input', validateForm);
        });
        
        // Form submission confirmation
        document.getElementById('changeCredentialsForm').addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to change your credentials? You will be logged out immediately.')) {
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
            
            return true;
        });
    </script>
</body>
</html>