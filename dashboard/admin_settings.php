<?php
include '../db_connection.php';
session_start();

// First, check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../logout_a.php");
    exit;
}



$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        $login_attempt_limit = (int)$_POST['login_attempt_limit'];
        
        // Validate input
        if ($login_attempt_limit < 1 || $login_attempt_limit > 10) {
            $error_message = "Login attempt limit must be between 1 and 10";
        } else {
            // Update setting for current admin only
            $stmt = $db->prepare("UPDATE users SET login_attempt_limit = ? WHERE id = ?");
            if ($stmt->execute([$login_attempt_limit, $_SESSION['user_id']])) {
                $success_message = "Settings updated successfully!";
            } else {
                $error_message = "Failed to update settings";
            }
        }
    } 
    elseif (isset($_POST['unlock_account'])) {
        $user_id = (int)$_POST['user_id'];
        
        // Unlock the account
        $stmt = $db->prepare("UPDATE users SET account_locked = 0, failed_login_attempts = 0 WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            $success_message = "Account unlocked successfully!";
        } else {
            $error_message = "Failed to unlock account";
        }
    } 
    elseif (isset($_POST['update_admin_credentials'])) {
        $new_username = trim($_POST['new_username']);
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate inputs
        if (empty($new_username)) {
            $error_message = "Username cannot be empty.";
        } elseif (strlen($new_username) < 3) {
            $error_message = "Username must be at least 3 characters long.";
        } elseif (empty($new_password)) {
            $error_message = "Password cannot be empty.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error_message = "Password must be at least 8 characters long.";
        } else {
            // Check if username already exists (excluding current admin)
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE name = ? AND id != ?");
            $stmt->execute([$new_username, $_SESSION['user_id']]);
            $username_exists = $stmt->fetchColumn();
            
            if ($username_exists) {
                $error_message = "Username already exists. Please choose another.";
            } else {
                // Update admin credentials
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET name = ?, password_hash = ? WHERE id = ?");
                if ($stmt->execute([$new_username, $hashed_password, $_SESSION['user_id']])) {
                    // Update session with new username
                    $_SESSION['user_name'] = $new_username;
                    $success_message = "Admin credentials updated successfully!";
                } else {
                    $error_message = "Failed to update credentials";
                }
            }
        }
    }
}

// Get current settings for the admin
$stmt = $db->prepare("SELECT login_attempt_limit FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// Get current admin username
$stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get locked accounts
$locked_accounts = $db->query("SELECT id, name, email, last_failed_login FROM users WHERE account_locked = 1")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings - ASD Academy</title>
    <?php include '../header.php'; ?>
    <style>
        .password-strength {
            height: 5px;
            background: #e2e8f0;
            margin-top: 5px;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease;
        }
        
        .password-strength-weak {
            background: #e53e3e;
        }
        
        .password-strength-medium {
            background: #dd6b20;
        }
        
        .password-strength-strong {
            background: #38a169;
        }
        
        /* Notification styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            transform: translateX(100%);
            transition: transform 0.3s ease;
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success {
            background-color: #38a169;
        }
        
        .notification.error {
            background-color: #e53e3e;
        }
        
        .notification-close {
            margin-left: 15px;
            cursor: pointer;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-cog text-blue-500"></i>
                <span>Admin Settings</span>
            </h1>
        </header>

        <div class="p-4 md:p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Admin Credentials -->
                <div class="bg-white p-5 rounded-xl shadow">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Admin Credentials</h2>
                    <form method="POST">
                        <div class="mb-4">
                            <label for="current_username" class="block text-sm font-medium text-gray-700 mb-1">
                                Current Username
                            </label>
                            <input type="text" id="current_username" value="<?php echo htmlspecialchars($admin_info['name']); ?>" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100" readonly>
                        </div>
                        
                        <div class="mb-4">
                            <label for="new_username" class="block text-sm font-medium text-gray-700 mb-1">
                                New Username
                            </label>
                            <input type="text" id="new_username" name="new_username" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md" 
                                placeholder="Enter new username" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">
                                New Password
                            </label>
                            <div class="relative">
                                <input type="password" id="new_password" name="new_password" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md" 
                                    placeholder="Enter new password" required oninput="checkPasswordStrength()">
                                <button type="button" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500" 
                                        onclick="togglePasswordVisibility('new_password')">
                                    <i class="far fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength">
                                <div class="password-strength-bar" id="password-strength-bar"></div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Password must be at least 8 characters long</p>
                        </div>
                        
                        <div class="mb-4">
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                                Confirm Password
                            </label>
                            <div class="relative">
                                <input type="password" id="confirm_password" name="confirm_password" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md" 
                                    placeholder="Confirm new password" required>
                                <button type="button" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500" 
                                        onclick="togglePasswordVisibility('confirm_password')">
                                    <i class="far fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" name="update_admin_credentials" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                            Update Credentials
                        </button>
                    </form>
                </div>
                
                <!-- Login Settings -->
                <div class="bg-white p-5 rounded-xl shadow">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Login Security Settings</h2>
                    <form method="POST">
                        <div class="mb-4">
                            <label for="login_attempt_limit" class="block text-sm font-medium text-gray-700 mb-1">
                                Maximum Login Attempts
                            </label>
                            <input type="number" id="login_attempt_limit" name="login_attempt_limit" 
                                min="1" max="10" value="<?php echo htmlspecialchars($settings['login_attempt_limit'] ?? 5); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            <p class="text-xs text-gray-500 mt-1">Number of failed attempts before account is locked (1-10)</p>
                        </div>
                        <button type="submit" name="update_settings" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                            Update Settings
                        </button>
                    </form>
                </div>
                
                <!-- Locked Accounts -->
                <div class="bg-white p-5 rounded-xl shadow lg:col-span-2">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Locked Accounts</h2>
                    
                    <?php if (empty($locked_accounts)): ?>
                        <p class="text-gray-500">No accounts are currently locked.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Failed Attempt</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($locked_accounts as $account): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <?php echo htmlspecialchars($account['name']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($account['email']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo $account['last_failed_login'] ? date('M j, Y g:i A', strtotime($account['last_failed_login'])) : 'Never'; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="user_id" value="<?php echo $account['id']; ?>">
                                                    <button type="submit" name="unlock_account" class="text-blue-500 hover:text-blue-700">
                                                        Unlock Account
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../footer.php'; ?>
    
    <script>
        function togglePasswordVisibility(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
            } else {
                passwordInput.type = 'password';
            }
        }
        
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('password-strength-bar');
            let strength = 0;
            
            if (password.length >= 8) strength += 1;
            if (password.match(/[a-z]/)) strength += 1;
            if (password.match(/[A-Z]/)) strength += 1;
            if (password.match(/[0-9]/)) strength += 1;
            if (password.match(/[^a-zA-Z0-9]/)) strength += 1;
            
            let width = strength * 20;
            strengthBar.style.width = width + '%';
            
            // Reset classes
            strengthBar.className = 'password-strength-bar';
            
            if (strength <= 2) {
                strengthBar.classList.add('password-strength-weak');
            } else if (strength <= 4) {
                strengthBar.classList.add('password-strength-medium');
            } else {
                strengthBar.classList.add('password-strength-strong');
            }
        }

        // Show notification if there's a message
        <?php if (!empty($success_message)): ?>
            showNotification('<?php echo addslashes($success_message); ?>', 'success');
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            showNotification('<?php echo addslashes($error_message); ?>', 'error');
        <?php endif; ?>
        
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                ${message}
                <span class="notification-close" onclick="this.parentElement.classList.remove('show')">&times;</span>
            `;
            
            document.body.appendChild(notification);
            
            // Trigger the animation
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 5000);
        }
    </script>
</body>
</html>