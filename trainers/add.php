<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_trainer'])) {
    error_log("Experience Submitted: " . $_POST['experience']);
    // Prepare trainer data
    $trainerData = [
        'name' => $_POST['name'],
        'email' => $_POST['email'],
        'specialization' => $_POST['specialization'] ?? null,
        'years_of_experience' => (int)$_POST['experience'],
        
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
            header("Location: index.php?" . http_build_query($redirectParams));
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
    <title>Add Trainer — ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════════════════════
           DESIGN SYSTEM — Navy/Sand Theme (matches index.php)
           ═══════════════════════════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy-deep:   #1B3C53;
            --navy-mid:    #234C6A;
            --navy-light:  #456882;
            --sand:        #D2C1B6;
            --sand-light:  #e8ddd8;
            --sand-faint:  #f5f0ee;
            --white:       #ffffff;
            --text-primary: #1B3C53;
            --text-secondary: #456882;
            --text-muted:  #7a9ab0;
            --border-light: rgba(69,104,130,0.18);
            --border-medium: rgba(69,104,130,0.30);
            --shadow-sm: 0 2px 8px rgba(27,60,83,0.06);
            --shadow-md: 0 4px 20px rgba(27,60,83,0.10);
            --shadow-lg: 0 12px 36px rgba(27,60,83,0.14);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 18px;
            --radius-xl: 24px;
            --sidebar-w: 260px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(160deg, var(--sand-faint) 0%, var(--sand-light) 100%);
            color: var(--text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        .layout { display: flex; min-height: 100vh; }
        .main-area { flex: 1; margin-left: var(--sidebar-w); display: flex; flex-direction: column; }

        /* ── Page Content ── */
        .page-content { padding: 28px 32px; flex: 1; max-width: 900px; margin: 0 auto; width: 100%; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 13.5px; font-weight: 500; font-family: 'Inter', sans-serif;
            padding: 0 18px; height: 38px; border-radius: var(--radius-sm);
            cursor: pointer; transition: all 0.18s ease;
            text-decoration: none; border: none; white-space: nowrap;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
            color: #fff;
            box-shadow: 0 2px 10px rgba(27,60,83,0.25);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
            box-shadow: 0 6px 20px rgba(27,60,83,0.35);
            transform: translateY(-1px);
            color: #fff;
        }
        .btn-ghost {
            background: rgba(255,255,255,0.7);
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
            backdrop-filter: blur(4px);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.9);
            color: var(--navy-deep);
            border-color: var(--border-medium);
        }

        /* ── Form Card ── */
        .form-card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }
        .form-card-header {
            background: linear-gradient(135deg, var(--navy-deep) 0%, var(--navy-mid) 60%, var(--navy-light) 100%);
            padding: 28px 32px 24px;
            border-bottom: none;
        }
        .form-card-header-row {
            display: flex; align-items: center; justify-content: space-between;
        }
        .form-card-title {
            font-size: 20px; font-weight: 800; color: #fff;
            letter-spacing: -0.02em; margin-bottom: 4px;
        }
        .form-card-sub {
            font-size: 13.5px; color: rgba(255,255,255,0.85);
        }
        .trainer-id-badge {
            font-size: 12px; color: rgba(255,255,255,0.7);
            background: rgba(255,255,255,0.12);
            padding: 4px 12px; border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(4px);
        }
        .trainer-id-badge strong {
            color: #fff;
            font-family: monospace;
            font-weight: 600;
        }

        .form-card-body { padding: 32px; }

        /* ── Photo Upload ── */
        .photo-zone {
            border: 2px dashed var(--border-medium);
            border-radius: var(--radius-lg);
            padding: 28px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s;
            background: var(--sand-faint);
            margin-bottom: 32px;
            position: relative;
        }
        .photo-zone:hover,
        .photo-zone.dragging {
            border-color: var(--navy-light);
            background: rgba(69,104,130,0.06);
        }
        .photo-zone input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }
        .photo-preview-img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border-light);
            display: none;
            margin: 0 auto 12px;
        }
        .photo-icon {
            font-size: 28px;
            color: var(--navy-light);
            margin-bottom: 10px;
        }
        .photo-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .photo-sub {
            font-size: 12.5px;
            color: var(--text-muted);
        }

        /* ── Form Sections ── */
        .form-section {
            margin-bottom: 32px;
        }
        .form-section-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-section-label span {
            background: var(--sand);
            color: var(--navy-deep);
            padding: 1px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
        }

        /* ── Form Grid ── */
        .form-grid {
            display: grid;
            gap: 20px;
        }
        .form-grid-2 { grid-template-columns: 1fr 1fr; }
        .form-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
        .col-span-2 { grid-column: 1 / -1; }
        @media (max-width: 640px) {
            .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }
            .col-span-2 { grid-column: 1; }
        }

        /* ── Fields ── */
        .field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .field-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .field-label span {
            color: #b91c1c;
            margin-left: 2px;
        }
        .field-input {
            height: 40px;
            padding: 0 13px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .field-input:focus {
            outline: none;
            border-color: var(--navy-light);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
        }
        .field-input.readonly {
            background: var(--sand-faint);
            color: var(--text-muted);
            cursor: default;
        }
        .field-textarea {
            padding: 10px 13px;
            min-height: 96px;
            resize: vertical;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .field-textarea:focus {
            outline: none;
            border-color: var(--navy-light);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
        }
        .field-hint {
            font-size: 12px;
            color: var(--text-muted);
        }
        .field-error {
            font-size: 12px;
            color: #b91c1c;
        }

        /* Password toggle */
        .field-pw {
            position: relative;
        }
        .field-pw .field-input {
            padding-right: 42px;
        }
        .pw-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-muted);
            font-size: 14px;
            transition: color 0.15s;
        }
        .pw-toggle:hover {
            color: var(--text-secondary);
        }

        /* ── Toggle Switch ── */
        .toggle-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
        }
        .toggle-switch {
            position: relative;
            width: 42px;
            height: 24px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--border-medium);
            border-radius: 24px;
            transition: all 0.2s;
        }
        .toggle-slider:before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: 0.2s;
        }
        .toggle-switch input:checked + .toggle-slider {
            background: var(--navy-mid);
        }
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(18px);
        }
        .toggle-label {
            font-size: 13.5px;
            font-weight: 500;
            color: var(--text-secondary);
        }
        .toggle-hint {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* ── Error Alert ── */
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: var(--radius);
            padding: 14px 18px;
            margin-bottom: 28px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .alert-error i {
            color: #b91c1c;
            font-size: 16px;
            margin-top: 1px;
            flex-shrink: 0;
        }
        .alert-error-title {
            font-size: 13.5px;
            font-weight: 600;
            color: #991B1B;
            margin-bottom: 4px;
        }
        .alert-error ul {
            padding-left: 16px;
        }
        .alert-error li {
            font-size: 13px;
            color: #991B1B;
            margin-bottom: 2px;
        }

        /* ── Form Footer ── */
        .form-footer {
            padding: 20px 32px;
            border-top: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--sand-faint);
        }
        .form-footer-info {
            font-size: 12.5px;
            color: var(--text-muted);
        }
        .form-footer-info span {
            color: #b91c1c;
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .main-area { margin-left: 0; }
            .page-content { padding: 16px; }
            .form-card-header { padding: 20px; }
            .form-card-body { padding: 20px; }
            .form-footer { padding: 16px 20px; flex-direction: column; gap: 12px; align-items: stretch; }
            .form-footer-info { text-align: center; }
        }
    </style>
</head>
<body>
<?php include '../header.php'; ?>

<div class="layout">
    <?php include '../sidebar.php'; ?>

    <div class="main-area">
        <div class="page-content">

            <?php if (!empty($errors)): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <div class="alert-error-title">Please fix the following errors:</div>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-row">
                        <div>
                            <div class="form-card-title">New Trainer</div>
                            <div class="form-card-sub">Complete the form to onboard a new trainer to ASD Academy.</div>
                        </div>
                        <div class="trainer-id-badge">ID: <strong><?= htmlspecialchars($nextTrainerId) ?></strong></div>
                    </div>
                </div>

                <form id="trainerForm" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="add_trainer" value="1">
                    <input type="hidden" name="trainer_id" value="<?= htmlspecialchars($nextTrainerId) ?>">

                    <div class="form-card-body">

                        <!-- Profile Photo Upload -->
                        <div class="photo-zone" id="photoZone">
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/*" onchange="previewImage(this)">
                            <img id="profilePreview" class="photo-preview-img" src="" alt="Preview">
                            <div id="photoPlaceholder">
                                <div class="photo-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                <div class="photo-title">Upload profile photo</div>
                                <div class="photo-sub">JPG or PNG, max 5 MB. Click or drag to upload.</div>
                            </div>
                        </div>

                        <!-- Personal Information -->
                        <div class="form-section">
                            <div class="form-section-label"><span>01</span> Personal Information</div>
                            <div class="form-grid form-grid-2">
                                <div class="field">
                                    <label class="field-label" for="name">Full Name <span>*</span></label>
                                    <input type="text" class="field-input" id="name" name="name"
                                           value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>"
                                           placeholder="e.g. Dr. Sarah Johnson" required>
                                </div>
                                <div class="field">
                                    <label class="field-label" for="email">Email Address <span>*</span></label>
                                    <input type="email" class="field-input" id="email" name="email"
                                           value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                                           placeholder="trainer@example.com" required>
                                </div>
                            </div>
                        </div>

                        <!-- Professional Information -->
                        <div class="form-section">
                            <div class="form-section-label"><span>02</span> Professional Information</div>
                            <div class="form-grid form-grid-2">
                                <div class="field">
                                    <label class="field-label" for="specialization">Specialization</label>
                                    <input type="text" class="field-input" id="specialization" name="specialization"
                                           value="<?= isset($_POST['specialization']) ? htmlspecialchars($_POST['specialization']) : '' ?>"
                                           placeholder="e.g. Data Science, Web Development">
                                </div>
                                <div class="field">
                                    <label class="field-label" for="experience">Years of Experience</label>
                                    <input type="number" class="field-input" id="experience" name="experience"
                                           value="<?= isset($_POST['experience']) ? htmlspecialchars($_POST['experience']) : '0' ?>"
                                           min="0" max="60" placeholder="0">
                                </div>
                                <div class="field col-span-2">
                                    <label class="field-label" for="bio">Bio / Description</label>
                                    <textarea class="field-textarea" id="bio" name="bio" placeholder="Brief introduction about the trainer's background, expertise, and teaching style…"><?= isset($_POST['bio']) ? htmlspecialchars($_POST['bio']) : '' ?></textarea>
                                    <div class="field-hint">Optional. Displayed on the trainer's public profile.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Account Information -->
                        <div class="form-section">
                            <div class="form-section-label"><span>03</span> Account Setup</div>
                            <div class="form-grid form-grid-2">
                                <div class="field">
                                    <label class="field-label">Trainer ID</label>
                                    <input type="text" class="field-input readonly" value="<?= htmlspecialchars($nextTrainerId) ?>" readonly>
                                    <div class="field-hint">Auto-generated. Cannot be changed.</div>
                                </div>
                                <div class="field">
                                    <label class="field-label" for="password">Password <span>*</span></label>
                                    <div class="field-pw">
                                        <input type="password" class="field-input" id="password" name="password"
                                               placeholder="Min. 8 characters" required>
                                        <span class="pw-toggle" id="togglePassword"><i class="far fa-eye-slash"></i></span>
                                    </div>
                                    <div class="field-hint" id="pwHint">At least 8 characters required.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="toggle-row">
                            <label class="toggle-switch">
                                <input type="checkbox" id="is_active" name="is_active" <?= (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            <div>
                                <div class="toggle-label">Activate trainer immediately</div>
                                <div class="toggle-hint">Inactive trainers cannot be assigned to batches.</div>
                            </div>
                        </div>

                    </div><!-- /form-card-body -->

                    <div class="form-footer">
                        <div class="form-footer-info">Fields marked with <span>*</span> are required.</div>
                        <div style="display:flex; gap:10px;">
                            <a href="index.php" class="btn btn-ghost">Cancel</a>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-user-plus"></i> Create Trainer
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            $('#profilePreview').attr('src', e.target.result).show();
            $('#photoPlaceholder').hide();
        };
        reader.readAsDataURL(input.files[0]);
    }
}

$('#togglePassword').on('click', function() {
    const field = $('#password');
    const type = field.attr('type') === 'password' ? 'text' : 'password';
    field.attr('type', type);
    $(this).find('i').toggleClass('fa-eye-slash fa-eye');
});

$('#password').on('input', function() {
    const val = $(this).val();
    const hint = $('#pwHint');
    if (val.length === 0) {
        hint.text('At least 8 characters required.').css('color', 'var(--text-muted)');
    } else if (val.length < 8) {
        hint.text(`${val.length}/8 characters — too short.`).css('color', '#b91c1c');
    } else {
        hint.text('Password length looks good.').css('color', '#059669');
    }
});

$('#trainerForm').on('submit', function() {
    $('#submitBtn').html('<i class="fas fa-spinner fa-spin"></i> Creating…').prop('disabled', true);
});

// Drag & drop on photo zone
const zone = document.getElementById('photoZone');
['dragenter','dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('dragging'); }));
['dragleave','drop'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('dragging'); }));
zone.addEventListener('drop', e => {
    const files = e.dataTransfer.files;
    if (files.length) { document.getElementById('profile_picture').files = files; previewImage(document.getElementById('profile_picture')); }
});
</script>
</body>
</html>
