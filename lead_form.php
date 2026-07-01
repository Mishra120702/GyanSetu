<?php
// ==============================================
// SECURE LEAD FORM - ASD ACADEMY
// With Country Code Support & Enhanced Security
// ==============================================

// Security Headers (must be sent before any output)
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; img-src 'self' data: https://via.placeholder.com; font-src 'self' https://cdnjs.cloudflare.com; frame-ancestors 'none';");
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

// Disable session cookie exposure
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true
    ]);
}

require_once 'db_connection.php';

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting configuration
$rate_limit_file = sys_get_temp_dir() . '/lead_form_rate_' . md5($_SERVER['REMOTE_ADDR']);
$rate_limit_window = 3600; // 1 hour
$rate_limit_max = 5; // Max 5 submissions per hour

// Country codes list (ISO 3166-1)
$country_codes = [
    '+91' => '🇮🇳 India (+91)',
    '+1' => '🇺🇸 USA/Canada (+1)',
    '+44' => '🇬🇧 United Kingdom (+44)',
    '+61' => '🇦🇺 Australia (+61)',
    '+971' => '🇦🇪 UAE (+971)',
    '+966' => '🇸🇦 Saudi Arabia (+966)',
    '+965' => '🇰🇼 Kuwait (+965)',
    '+974' => '🇶🇦 Qatar (+974)',
    '+968' => '🇴🇲 Oman (+968)',
    '+973' => '🇧🇭 Bahrain (+973)',
    '+20' => '🇪🇬 Egypt (+20)',
    '+27' => '🇿🇦 South Africa (+27)',
    '+33' => '🇫🇷 France (+33)',
    '+49' => '🇩🇪 Germany (+49)',
    '+81' => '🇯🇵 Japan (+81)',
    '+86' => '🇨🇳 China (+86)',
    '+65' => '🇸🇬 Singapore (+65)',
    '+60' => '🇲🇾 Malaysia (+60)',
    '+64' => '🇳🇿 New Zealand (+64)',
];

$lead_sources = [
    'apkaapnakumawat_ instagram',
    'Hackervlog official Instagram',
    'Hacker vlog Youtube Shorts',
    'Cyber Kaksha Youtube Short',
    'Hacker vlog Youtube Channel',
    'Cyber Kaksha Youtube Channel',
    'Hackervlog { Instagram }',
    'Cyberexpert Riddhi Soral { Instagram }',
    'Whatsapp Broadcast Channel { Hackervlogofficial }',
    'Website',
    'Whatsapp',
    'Advertisement'     // New option added here
];

$success_message = '';
$error_message = '';
$form_data = [];

// Rate limiting check
function checkRateLimit($file, $window, $max) {
    $current = time();
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['count']) && isset($data['start_time'])) {
            if ($current - $data['start_time'] < $window) {
                if ($data['count'] >= $max) {
                    return false;
                }
                $data['count']++;
            } else {
                $data['count'] = 1;
                $data['start_time'] = $current;
            }
        } else {
            $data = ['count' => 1, 'start_time' => $current];
        }
    } else {
        $data = ['count' => 1, 'start_time' => $current];
    }
    file_put_contents($file, json_encode($data));
    return true;
}



// Validation functions
function validateName($name) {
    return preg_match('/^[a-zA-Z\s\'\-\p{L}]{2,100}$/u', $name);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 150;
}

function validateContactWithCode($contact, $countryCode) {
    // Remove all non-digit characters
    $contact = preg_replace('/\D/', '', $contact);
    // Length validation based on common patterns
    $length = strlen($contact);
    return $length >= 7 && $length <= 15;
}

function validateCountryCode($code) {
    global $country_codes;
    return array_key_exists($code, $country_codes);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "Security validation failed. Please refresh the page and try again.";
    } elseif (!checkRateLimit($rate_limit_file, $rate_limit_window, $rate_limit_max)) {
        $error_message = "Too many submissions. Please try again after an hour.";
    } else {
        // Collect and sanitize inputs
        $form_data['name'] = sanitizeInput($_POST['name'] ?? '');
        $form_data['email'] = sanitizeInput($_POST['email'] ?? '');
        $form_data['contact'] = sanitizeInput($_POST['contact'] ?? '');
        $form_data['country_code'] = sanitizeInput($_POST['country_code'] ?? '');
        $form_data['qualification'] = sanitizeInput($_POST['qualification'] ?? '');
        $form_data['address'] = sanitizeInput($_POST['address'] ?? '');
        $form_data['city'] = sanitizeInput($_POST['city'] ?? '');
        $form_data['lead_source'] = sanitizeInput($_POST['lead_source'] ?? '');
        $form_data['notes'] = sanitizeInput($_POST['notes'] ?? '');
        $form_data['enquiry_date'] = $_POST['enquiry_date'] ?? date('Y-m-d');
        
        $errors = [];
        
        // Name validation
        if (empty($form_data['name'])) {
            $errors[] = 'Full name is required';
        } elseif (!validateName($form_data['name'])) {
            $errors[] = 'Please enter a valid name (only letters, spaces, hyphens, and apostrophes allowed)';
        }
        
        // Email validation
        if (empty($form_data['email'])) {
            $errors[] = 'Email address is required';
        } elseif (!validateEmail($form_data['email'])) {
            $errors[] = 'Please enter a valid email address';
        }
        
        // Contact with country code validation
        if (empty($form_data['country_code'])) {
            $errors[] = 'Please select your country code';
        } elseif (!validateCountryCode($form_data['country_code'])) {
            $errors[] = 'Invalid country code selected';
        }
        
        if (empty($form_data['contact'])) {
            $errors[] = 'Contact number is required';
        } elseif (!validateContactWithCode($form_data['contact'], $form_data['country_code'])) {
            $errors[] = 'Please enter a valid contact number (7-15 digits)';
        }
        
        // Lead source validation
        if (empty($form_data['lead_source'])) {
            $errors[] = 'Please select how you found us';
        } elseif (!in_array($form_data['lead_source'], $lead_sources)) {
            $errors[] = 'Invalid lead source selected';
        }
        
        // Date validation
        if (!empty($form_data['enquiry_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_data['enquiry_date'])) {
            $form_data['enquiry_date'] = date('Y-m-d');
        }
        
        // Additional input length validations
        if (strlen($form_data['qualification']) > 100) {
            $errors[] = 'Qualification is too long';
        }
        if (strlen($form_data['city']) > 100) {
            $errors[] = 'City name is too long';
        }
        if (strlen($form_data['address']) > 500) {
            $errors[] = 'Address is too long';
        }
        if (strlen($form_data['notes']) > 1000) {
            $errors[] = 'Notes are too long';
        }
        
        if (empty($errors)) {
            try {
                // Prepare full contact number
                $full_contact = $form_data['country_code'] . $form_data['contact'];
                
                // Check if lead already exists (using parameterized query)
                $stmt = $db->prepare("SELECT id FROM leads WHERE email = ? OR contact = ?");
                $stmt->execute([$form_data['email'], $full_contact]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Update existing lead
                    $stmt = $db->prepare("UPDATE leads SET 
                        name = ?, contact = ?, qualification = ?, address = ?, 
                        city = ?, lead_source = ?, enquiry_date = ?, notes = ?,
                        status = 'new', updated_at = NOW()
                        WHERE id = ?");
                    
                    $stmt->execute([
                        $form_data['name'],
                        $full_contact,
                        $form_data['qualification'],
                        $form_data['address'],
                        $form_data['city'],
                        $form_data['lead_source'],
                        $form_data['enquiry_date'],
                        $form_data['notes'],
                        $existing['id']
                    ]);
                    
                    $success_message = "Thank you for your interest! We have updated your information. Our team will contact you soon.";
                } else {
                    // Insert new lead
                    $stmt = $db->prepare("INSERT INTO leads (
                        name, email, contact, qualification, address, city, 
                        lead_source, enquiry_date, notes, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())");
                    
                    $stmt->execute([
                        $form_data['name'],
                        $form_data['email'],
                        $full_contact,
                        $form_data['qualification'],
                        $form_data['address'],
                        $form_data['city'],
                        $form_data['lead_source'],
                        $form_data['enquiry_date'],
                        $form_data['notes']
                    ]);
                    
                    $success_message = "Thank you for your interest! Your query has been submitted successfully. Our team will contact you within 24 hours.";
                }
                
                // Clear form data after successful submission
                $form_data = [];
                
                // Regenerate CSRF token to prevent replay
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
            } catch (PDOException $e) {
                error_log("Lead submission error: " . $e->getMessage());
                $error_message = "Something went wrong. Please try again later.";
            }
        } else {
            $error_message = implode('<br>', $errors);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Enroll for Diploma in Cyber Security with AI at ASD Academy. Limited seats available. Fill the enquiry form to get a call back.">
    <meta name="robots" content="index, follow">
    <title>Enquiry Form - ASD Academy | Diploma in Cyber Security with AI</title>
    <link rel="icon" href="../assets/images/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            min-height: 100vh;
            padding: clamp(16px, 4vw, 40px);
            position: relative;
            background: #f5f7fa;
        }
        
        .background-image {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }
        
        .background-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .container {
            max-width: min(950px, 100%);
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        
        .admission-banner {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: clamp(12px, 3vw, 20px) clamp(16px, 4vw, 25px);
            text-align: center;
        }
        
        .admission-banner h2 {
            font-size: clamp(1.1rem, 4vw, 1.5rem);
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        
        .admission-banner h3 {
            font-size: clamp(0.85rem, 3.5vw, 1.1rem);
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .course-highlight, .features-list {
            display: flex;
            justify-content: center;
            gap: clamp(8px, 2vw, 20px);
            flex-wrap: wrap;
            margin-top: 8px;
            font-size: clamp(0.7rem, 2.5vw, 0.85rem);
        }
        
        .course-highlight span, .features-list span {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 50px;
        }
        
        .header {
            background: linear-gradient(135deg, #0a0f2c 0%, #0a1a3a 100%);
            color: white;
            padding: clamp(20px, 5vw, 30px) clamp(20px, 5vw, 40px);
            text-align: center;
        }
        
        .header h1 {
            font-size: clamp(1.3rem, 5vw, 1.8rem);
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: clamp(0.85rem, 3vw, 1rem);
            opacity: 0.9;
        }
        
        .form-content {
            padding: clamp(20px, 5vw, 40px);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
        }
        
        .required::after {
            content: '*';
            color: #e74c3c;
            margin-left: 5px;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            font-family: inherit;
            background: #fafafa;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #f7931e;
            box-shadow: 0 0 0 3px rgba(247, 147, 30, 0.1);
            background: white;
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .phone-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .phone-group select {
            width: auto;
            min-width: 110px;
            flex-shrink: 0;
        }
        
        .phone-group input {
            flex: 1;
            min-width: 150px;
        }
        
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -10px rgba(247, 147, 30, 0.4);
        }
        
        .btn-submit:disabled {
            opacity: 0.7;
            transform: none;
            cursor: not-allowed;
        }
        
        .success-message, .error-message {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .info-text {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
        
        .logo {
            width: clamp(50px, 15vw, 70px);
            height: clamp(50px, 15vw, 70px);
            margin: 0 auto 15px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .logo img {
            width: 70%;
            height: 70%;
            object-fit: contain;
        }
        
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 0.85rem;
            border-top: 1px solid #e0e0e0;
        }
        
        select {
            appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%23333" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            cursor: pointer;
        }
        
        .radio-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
            margin-top: 8px;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .radio-option:hover {
            border-color: #f7931e;
            background: #fff5e8;
        }
        
        .radio-option input[type="radio"] {
            width: auto;
            margin-right: 12px;
            cursor: pointer;
            accent-color: #f7931e;
        }
        
        .radio-option label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
            flex: 1;
        }
        
        .radio-option.selected {
            border-color: #f7931e;
            background: #fff0e0;
        }
        
        .footer .contact-info {
            margin-top: 10px;
            font-size: 0.9rem;
            color: #f7931e;
            font-weight: 500;
        }
        
        .footer .contact-info i {
            margin: 0 5px;
        }
        
        @media (max-width: 640px) {
            .phone-group {
                flex-direction: column;
            }
            
            .phone-group select {
                width: 100%;
            }
            
            .radio-group {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="background-image">
        <img src="../lead.jpg" alt="Background">
    </div>
    
    <div class="container">
        <div class="admission-banner">
            <h2>🚀 ADMISSION OPEN 2026</h2>
            <h3>🎓 DIPLOMA IN CYBER SECURITY WITH AI</h3>
            <div class="course-highlight">
                <span>📚 12 Professional Courses Included</span>
                <span>💼 100% Placement Assistance</span>
            </div>
            <div class="features-list">
                <span>✓ 10+2 Students</span>
                <span>✓ College Students</span>
                <span>✓ IT / Non-IT Beginners</span>
            </div>
            <div style="margin-top: 10px; font-size: clamp(0.7rem, 3vw, 0.85rem);">
                📍 JOIN OUR OFFLINE NEW BATCH STARTED IN KOTA, RAJ.
            </div>
        </div>
        
        <div class="header">
            <div class="logo">
                <img src="../assets/images/logo.png" alt="ASD Academy" onerror="this.src='https://via.placeholder.com/60x60/ffffff/1a1a2e?text=ASD'">
            </div>
            <h1>Enquiry Form</h1>
            <p>Start Your Career in Cyber Security With AI | Limited Seats Available</p>
        </div>
        
        <div class="form-content">
            <?php if ($success_message): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i>
                    <span><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle" style="font-size: 1.2rem;"></i>
                    <span><?= $error_message ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="leadForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                
                <div class="row">
                    <div class="form-group">
                        <label class="required">Full Name</label>
                        <input type="text" name="name" required 
                               value="<?= htmlspecialchars($form_data['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Enter your full name"
                               maxlength="100"
                               pattern="[A-Za-z\s\'\-\p{L}]{2,100}"
                               title="Only letters, spaces, hyphens, and apostrophes allowed">
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Email Address</label>
                        <input type="email" name="email" required 
                               value="<?= htmlspecialchars($form_data['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Enter your email"
                               maxlength="150">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="required">Contact Number</label>
                    <div class="phone-group">
                        <select name="country_code" required>
                            <option value="">Select Code</option>
                            <?php foreach ($country_codes as $code => $label): ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" 
                                    <?= (isset($form_data['country_code']) && $form_data['country_code'] === $code) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="tel" name="contact" required 
                               value="<?= htmlspecialchars($form_data['contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Phone number"
                               maxlength="15"
                               pattern="\d{7,15}"
                               title="Please enter 7-15 digits">
                    </div>
                    <div class="info-text"><i class="fas fa-phone-alt"></i> We'll contact you on this number</div>
                </div>
                
                <div class="row">
                    <div class="form-group">
                        <label>Qualification</label>
                        <input type="text" name="qualification" 
                               value="<?= htmlspecialchars($form_data['qualification'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="e.g., 12th Pass, B.Sc, MCA"
                               maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" 
                               value="<?= htmlspecialchars($form_data['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Your city"
                               maxlength="100">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Address (Optional)</label>
                    <input type="text" name="address" 
                           value="<?= htmlspecialchars($form_data['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Your address"
                           maxlength="500">
                </div>
                
                <div class="form-group">
                    <div class="lead-source-title">
                        <span class="required">Where did you find us from?</span>
                    </div>
                    <div class="radio-group" id="leadSourceGroup">
                        <?php foreach ($lead_sources as $index => $source): ?>
                            <div class="radio-option" data-value="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="radio" name="lead_source" value="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>" 
                                       id="source_<?= $index ?>"
                                       <?= (isset($form_data['lead_source']) && $form_data['lead_source'] === $source) ? 'checked' : '' ?>
                                       required>
                                <label for="source_<?= $index ?>"><?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Additional Notes / Questions</label>
                    <textarea name="notes" placeholder="Any specific questions about the course, fees, or admission process?" maxlength="1000"><?= htmlspecialchars($form_data['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                
                <input type="hidden" name="enquiry_date" value="<?= date('Y-m-d') ?>">
                
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-paper-plane" style="margin-right: 8px;"></i>
                    Submit Enquiry & Get Call Back
                </button>
            </form>
        </div>
        
        <div class="footer">
            <p><strong>ASD Academy</strong> - Diploma in Cyber Security with AI</p>
            <div class="contact-info">
                <i class="fas fa-phone-alt"></i> +91-9680100687 
                <i class="fas fa-globe"></i> www.asdacademy.in
            </div>
            <p>&copy; <?= date('Y') ?> ASD Academy. All rights reserved.</p>
            <p>📞 We'll get back to you within 24 hours</p>
        </div>
    </div>
    
    <script>
        (function() {
            // Radio selection handler
            function selectRadioOption(element) {
                const allOptions = document.querySelectorAll('.radio-option');
                allOptions.forEach(opt => opt.classList.remove('selected'));
                element.classList.add('selected');
                const radio = element.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
            }
            
            // Initialize radio selections
            document.querySelectorAll('.radio-option').forEach(option => {
                const radio = option.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    option.classList.add('selected');
                }
                option.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'INPUT') {
                        selectRadioOption(this);
                    }
                });
            });
            
            // Auto-hide messages after 5 seconds
            setTimeout(() => {
                const messages = document.querySelectorAll('.success-message, .error-message');
                messages.forEach(msg => {
                    msg.style.transition = 'opacity 0.5s';
                    msg.style.opacity = '0';
                    setTimeout(() => msg.remove(), 500);
                });
            }, 5000);
            
            // Form validation
            const form = document.getElementById('leadForm');
            const submitBtn = document.getElementById('submitBtn');
            
            form.addEventListener('submit', function(e) {
                let isValid = true;
                let errorMsg = '';
                
                const name = form.querySelector('[name="name"]').value.trim();
                const email = form.querySelector('[name="email"]').value.trim();
                const countryCode = form.querySelector('[name="country_code"]').value;
                const contact = form.querySelector('[name="contact"]').value.trim();
                const leadSource = form.querySelector('input[name="lead_source"]:checked');
                
                // Name validation
                if (!name) {
                    errorMsg = 'Please enter your name';
                    isValid = false;
                } else if (!/^[A-Za-z\s\'\-\u{0080}-\u{FFFF}]{2,100}$/u.test(name)) {
                    errorMsg = 'Please enter a valid name (only letters, spaces, hyphens, and apostrophes allowed)';
                    isValid = false;
                }
                
                // Email validation
                if (isValid && !email) {
                    errorMsg = 'Please enter your email';
                    isValid = false;
                } else if (isValid && !/^[^\s@]+@([^\s@.,]+\.)+[^\s@.,]{2,}$/.test(email)) {
                    errorMsg = 'Please enter a valid email address';
                    isValid = false;
                }
                
                // Country code validation
                if (isValid && !countryCode) {
                    errorMsg = 'Please select your country code';
                    isValid = false;
                }
                
                // Contact validation
                if (isValid && !contact) {
                    errorMsg = 'Please enter your contact number';
                    isValid = false;
                } else if (isValid && !/^\d{7,15}$/.test(contact.replace(/\D/g, ''))) {
                    errorMsg = 'Please enter a valid contact number (7-15 digits)';
                    isValid = false;
                }
                
                // Lead source validation
                if (isValid && !leadSource) {
                    errorMsg = 'Please select how you found us';
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                    alert(errorMsg);
                    return false;
                }
                
                // Show loading state
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                submitBtn.disabled = true;
                
                // Re-enable after timeout (fallback)
                setTimeout(() => {
                    if (submitBtn.disabled) {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                }, 10000);
                
                return true;
            });
        })();
    </script>
</body>
</html>