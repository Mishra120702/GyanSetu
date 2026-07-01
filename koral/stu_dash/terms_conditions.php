<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: login.php");
    exit();
}

// Get student information
$student_user_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT s.*, u.email as user_email, s.batch_name_2 as current_batch
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: ../logout.php");
    exit();
}

// Check if batch requires terms acceptance
$batch_id = $student['current_batch'];
if ($batch_id) {
    $batch_terms_query = $db->prepare("
        SELECT require_terms_acceptance 
        FROM batch_terms_settings 
        WHERE batch_id = :batch_id
    ");
    $batch_terms_query->execute([':batch_id' => $batch_id]);
    $batch_terms = $batch_terms_query->fetch(PDO::FETCH_ASSOC);
    
    // Default to requiring terms if no setting exists
    $require_terms = $batch_terms ? $batch_terms['require_terms_acceptance'] : 1;
} else {
    // If no batch assigned, require terms by default
    $require_terms = 1;
}

// Check if already accepted terms
if ($student['terms_accepted'] == 1) {
    header("Location: ../stu_dash/dashboard.php");
    exit();
}

// If batch doesn't require terms, redirect to dashboard and mark as accepted
if (!$require_terms) {
    $update_stmt = $db->prepare("
        UPDATE students 
        SET terms_accepted = 1, 
            terms_accepted_date = :accepted_date,
            terms_accepted_ip = :ip_address
        WHERE user_id = :user_id
    ");
    
    $update_stmt->execute([
        ':accepted_date' => date('Y-m-d H:i:s'),
        ':ip_address' => $_SERVER['REMOTE_ADDR'],
        ':user_id' => $student_user_id
    ]);
    
    header("Location: ../stu_dash/dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_terms'])) {
    if (isset($_POST['agree']) && $_POST['agree'] === '1') {
        $current_time = date('Y-m-d H:i:s');
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        $update_stmt = $db->prepare("
            UPDATE students 
            SET terms_accepted = 1, 
                terms_accepted_date = :accepted_date,
                terms_accepted_ip = :ip_address
            WHERE user_id = :user_id
        ");
        
        $update_stmt->execute([
            ':accepted_date' => $current_time,
            ':ip_address' => $ip_address,
            ':user_id' => $student_user_id
        ]);
        
        // Clear terms verification session data if exists
        if (isset($_SESSION['terms_verification_data'])) {
            unset($_SESSION['terms_verification_data']);
        }
        
        // Redirect to dashboard
        $_SESSION['terms_accepted'] = true;
        $_SESSION['success_message'] = "Terms & Conditions accepted successfully!";
        header("Location: ../stu_dash/dashboard.php");
        exit();
    } else {
        $error_message = "You must agree to the Terms & Conditions to continue.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms & Conditions - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .terms-container {
            max-height: 70vh;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .terms-section {
            margin-bottom: 1.5rem;
        }
        
        .terms-section h3 {
            color: #3b82f6;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .highlight-box {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .checkbox-container {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem;
            background-color: #f9fafb;
            border-radius: 0.5rem;
            border: 2px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        
        .checkbox-container:has(input:checked) {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }
        
        .checkbox-container input[type="checkbox"] {
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.25rem;
            accent-color: #3b82f6;
        }
        
        .accept-btn {
            transition: all 0.3s ease;
            font-weight: 600;
            padding: 0.75rem 2rem;
        }
        
        .accept-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Custom scrollbar */
        .terms-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .terms-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .terms-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .terms-container::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
        
        /* Print styles */
        @media print {
            .no-print {
                display: none;
            }
            
            .terms-container {
                max-height: none;
                overflow: visible;
            }
        }
        
        /* Mobile optimizations */
        @media (max-width: 640px) {
            .terms-container {
                padding: 1rem;
                font-size: 0.9rem;
            }
            
            .checkbox-container {
                padding: 0.75rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-4xl w-full">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="flex justify-center mb-4">
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-3 rounded-xl inline-block">
                    <i class="fas fa-file-contract text-white text-3xl"></i>
                </div>
            </div>
            <h1 class="text-3xl md:text-4xl font-bold text-gray-800 mb-2">ASD Academy</h1>
            <h2 class="text-xl md:text-2xl text-blue-600 font-semibold mb-4">Terms & Conditions</h2>
            <p class="text-gray-600">Please read and accept the terms before continuing to your dashboard</p>
            <div class="mt-4 bg-amber-50 border-l-4 border-amber-500 p-4 rounded">
                <p class="text-amber-700 flex items-center justify-center gap-2">
                    <i class="fas fa-exclamation-circle"></i>
                    You must accept these terms before accessing your student dashboard
                </p>
            </div>
        </div>

        <!-- Error Message -->
        <?php if (isset($error_message)): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg animate-fade-in">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                <span class="text-red-700"><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Terms & Conditions Content -->
        <form method="POST" id="termsForm">
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                <!-- Progress Indicator -->
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-4">
                    <div class="flex items-center justify-between text-white">
                        <div class="flex items-center space-x-3">
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <div>
                                <p class="font-medium"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
                                <p class="text-sm opacity-90">Student ID: <?php echo htmlspecialchars($student['student_id']); ?></p>
                                <p class="text-sm opacity-90">Batch: <?php echo htmlspecialchars($batch_id); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm opacity-90">Step 1 of 1</p>
                            <p class="font-medium">Terms Acceptance</p>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="terms-container" id="termsContent">
                        <!-- Class Schedule Section -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-calendar-alt"></i>
                                ASD Academy Class Time Schedule
                            </h3>
                            <div class="space-y-4">
                                <div class="highlight-box">
                                    <p class="font-semibold text-amber-700 mb-2">Weekly Classes Running Time Schedule:</p>
                                    <ul class="list-disc pl-5 space-y-1">
                                        <li>Monday to Friday</li>
                                        <li>Between 07:00 PM to 10:00 PM</li>
                                    </ul>
                                </div>
                                <p class="text-gray-700">
                                    <strong>Note:</strong> Some course streams are advanced and will run on an alternate day basis.
                                </p>
                            </div>
                        </div>

                        <!-- Assignment Schedule -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-tasks"></i>
                                Assignment Schedule
                            </h3>
                            <div class="bg-blue-50 p-4 rounded-lg">
                                <p class="text-gray-700">
                                    Every week you will get an assignment on Wednesday & Friday which you will have to submit by Monday on GyanSetu.
                                </p>
                            </div>
                        </div>

                        <!-- Test Schedule -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-file-alt"></i>
                                Test Schedule
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <div class="bg-blue-100 p-2 rounded-lg">
                                        <i class="fas fa-calendar-week text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-semibold">Weekly Tests:</p>
                                        <p class="text-gray-700">Will be conducted every Saturday on the GyanSetu.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <div class="bg-indigo-100 p-2 rounded-lg">
                                        <i class="fas fa-calendar-check text-indigo-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-semibold">Monthly Tests:</p>
                                        <p class="text-gray-700">Will be held at the end of the batch on the Proctor Panel.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Holiday Schedule -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-umbrella-beach"></i>
                                Holiday Schedule
                            </h3>
                            <div class="bg-green-50 p-4 rounded-lg">
                                <p class="text-gray-700">
                                    Sunday is a weekly holiday at ASD Academy. Please utilize this day to revise and refresh.
                                </p>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-phone-alt"></i>
                                Class-Related Issues or Enquiries
                            </h3>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <p class="text-gray-700 mb-3">If you face any issues related to classes, please contact:</p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div class="bg-white p-3 rounded-lg border">
                                        <p class="font-semibold text-blue-600">Training Coordinator</p>
                                        <p class="text-gray-700"><i class="fas fa-phone mr-2 text-green-600"></i>+91-73571-85080</p>
                                    </div>
                                    <div class="bg-white p-3 rounded-lg border">
                                        <p class="font-semibold text-blue-600">Training Manager</p>
                                        <p class="text-gray-700"><i class="fas fa-phone mr-2 text-green-600"></i>+91-75973-89626</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recording Access -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-video"></i>
                                Recording Access Details & Instructions
                            </h3>
                            <div class="space-y-4">
                                <div class="bg-purple-50 p-4 rounded-lg">
                                    <p class="font-semibold text-purple-700 mb-2">Course Recording Access:</p>
                                    <ul class="list-disc pl-5 space-y-2">
                                        <li>Download the application from Play Store/App Store</li>
                                        <li>Web portal link: <a href="https://web.classplusapp.com/login" class="text-blue-600 hover:underline" target="_blank">https://web.classplusapp.com/login</a></li>
                                        <li>ORG CODE FOR IOS: <code class="bg-gray-100 px-2 py-1 rounded">YXBZG</code></li>
                                    </ul>
                                </div>
                                <div class="bg-blue-50 p-4 rounded-lg">
                                    <p class="font-semibold text-blue-700">Daily class recordings:</p>
                                    <p class="text-gray-700">Will be uploaded between 12:00 PM to 2:00 PM on the ASD Academy App.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Important Instructions -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-exclamation-triangle"></i>
                                Important Instructions
                            </h3>
                            <div class="highlight-box">
                                <ul class="list-disc pl-5 space-y-3">
                                    <li class="font-semibold">Camera must be on during the class.</li>
                                    <li>It is mandatory that the student joins the class from the Gmail of his own name. If he joins from any other Gmail, he will not be allowed to join the class.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Drop-Out Rules & Regulations -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-red-800 flex items-center gap-2">
                                <i class="fas fa-ban"></i>
                                Drop-Out Rules & Regulations
                            </h3>
                            <div class="space-y-4">
                                <div class="bg-red-50 p-4 rounded-lg border border-red-200">
                                    <h4 class="font-bold text-red-700 mb-2">1. Student Exit Process</h4>
                                    <ul class="list-decimal pl-5 space-y-2">
                                        <li>If a student remains regularly absent or discontinues the course (with or without prior intimation), our Academy Team will identify the case.</li>
                                        <li>Once identified, the student will be contacted, and the reason for discontinuation will be asked. If the issue can be resolved, the team will provide necessary support.</li>
                                        <li>If no solution is possible, the student will receive an Official Drop-Out Mail.</li>
                                        <li>The student must respond within 3 working days of receiving this mail.</li>
                                        <li>If no response is received within 3 working days, the student will be declared as dropped from the Academy.</li>
                                    </ul>
                                </div>

                                <div class="bg-red-50 p-4 rounded-lg border border-red-200">
                                    <h4 class="font-bold text-red-700 mb-2">2. Post-Drop Rules (Applicable After Student is Declared Dropped)</h4>
                                    <ul class="list-disc pl-5 space-y-2">
                                        <li><strong>Access Termination</strong> – The student's LMS / Online Portal / Class Recordings / Study Material access will be immediately revoked.</li>
                                        <li><strong>Certificate Eligibility</strong> – After being dropped, no Certificate of Completion or Participation will be issued.</li>
                                        <li><strong>Refund Policy</strong> – Once dropped, no pending refund claim will be entertained.</li>
                                        <li><strong>Re-Enrollment</strong> – To rejoin after being dropped, the student must send a re-joining request mail and go through fresh admission with full fees.</li>
                                        <li><strong>Confidentiality & Conduct</strong> – All Academy materials are strictly for personal use. Sharing or distributing them is prohibited.</li>
                                        <li><strong>Communication</strong> – After being dropped, the Academy will not continue academic communication with the student.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Attendance & Leave Policy -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-user-check"></i>
                                Attendance & Leave Policy
                            </h3>
                            <div class="bg-blue-50 p-4 rounded-lg">
                                <ul class="list-disc pl-5 space-y-2">
                                    <li>90% attendance is mandatory for certification and internship eligibility.</li>
                                    <li>If absent, email a valid reason to <a href="mailto:training@asdacademy.in" class="text-blue-600 hover:underline">training@asdacademy.in</a> with proper justification.</li>
                                    <li>Absent for 7 consecutive days? Your access to batch & recordings will be temporarily revoked.</li>
                                    <li>No rejoining will be allowed unless valid reason is discussed within 11:00 AM to 5:00 PM.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Class Policies & Decorum -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-users"></i>
                                Class Policies & Decorum
                            </h3>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <ul class="list-disc pl-5 space-y-2">
                                    <li>Be punctual — late entries will not be entertained.</li>
                                    <li>Actively participate in class discussions and activities.</li>
                                    <li>Maintain silence & discipline inside class.</li>
                                    <li>Show respect to trainers, coordinators & fellow students.</li>
                                    <li>Misbehaviour = immediate disciplinary action and removal from course.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Webcam & Viva Rules -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-camera"></i>
                                Webcam & Viva Rules
                            </h3>
                            <div class="highlight-box">
                                <p class="font-bold text-amber-700">CAMERA must be On throughout the class</p>
                                <p class="text-gray-700 mt-2">Failure to turn on the webcam = removal from live class.</p>
                            </div>
                        </div>

                        <!-- Assignment & Exam Policy -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-graduation-cap"></i>
                                Assignment & Exam Policy
                            </h3>
                            <div class="bg-green-50 p-4 rounded-lg">
                                <ul class="list-disc pl-5 space-y-2">
                                    <li>Submit assignments weekly – Minimum 80% accuracy is required.</li>
                                    <li>Must complete at least 10 out of 12 assignments to get the final certificate.</li>
                                    <li>Weekly tests and final exam performance should be above 75% to qualify.</li>
                                    <li>Submit everything on or before deadlines — no late submissions accepted.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Placement Policy -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-briefcase"></i>
                                Placement Policy
                            </h3>
                            <div class="bg-indigo-50 p-4 rounded-lg">
                                <ul class="list-disc pl-5 space-y-2">
                                    <li>90% attendance, 80% accuracy in assignments, and 80% marks in tests required for placement eligibility.</li>
                                    <li>Minimum 3 interviews must be attended; maximum 3 attempts allowed.</li>
                                    <li>Failing to attend any scheduled interview = debarment from future placement rounds.</li>
                                    <li>If a student rejects a job after being selected, ASD Academy will not forward their resume for further opportunities.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Trainer & Course Instructions -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-chalkboard-teacher"></i>
                                Trainer & Course Instructions
                            </h3>
                            <div class="bg-purple-50 p-4 rounded-lg">
                                <ul class="list-disc pl-5 space-y-2">
                                    <li>Once assigned, trainers will not be changed mid-course.</li>
                                    <li>No course restart allowed under any condition.</li>
                                    <li>We're committed to continuous improvement — use feedback forms constructively.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Parent-Teacher Meetings (PTM) -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-handshake"></i>
                                Parent-Teacher Meetings (PTM)
                            </h3>
                            <div class="bg-amber-50 p-4 rounded-lg">
                                <ul class="list-disc pl-5 space-y-2">
                                    <li>PTM attendance is mandatory along with parents/guardians.</li>
                                    <li>Important discussions related to academic progress, performance & behavior.</li>
                                    <li>Fill feedback forms and submit weekly assessments regularly.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Recordings & Support -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-life-ring"></i>
                                Recordings & Support
                            </h3>
                            <div class="bg-blue-50 p-4 rounded-lg">
                                <ul class="list-disc pl-5 space-y-2">
                                    <li>Class recordings are uploaded between 12:00 PM to 2:00 PM on next day.</li>
                                    <li>Any issue/query should be raised between 11:00 AM to 5:00 PM only.</li>
                                    <li>Do not raise batch/technical issues outside this time frame.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- General Reminders -->
                        <div class="terms-section">
                            <h3 class="text-xl font-bold text-blue-800 flex items-center gap-2">
                                <i class="fas fa-bell"></i>
                                General Reminders
                            </h3>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <ul class="list-disc pl-5 space-y-3">
                                    <li>If you miss 3 or more classes, you must ensure that all backlogs are completed on time.</li>
                                    <li>If you attend 3 or more classes in any batch, fees will be non-refundable under any circumstances.</li>
                                    <li>Students of Super 30, Let's Win, and all other batches must strictly follow all policies with full determination and ensure that classes run smoothly and flawlessly.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Agreement Checkbox -->
                    <div class="mt-8">
                        <label class="checkbox-container">
                            <input type="checkbox" name="agree" value="1" id="agreeCheckbox" onchange="toggleSubmitButton()">
                            <div>
                                <p class="font-semibold text-gray-800">I agree to all Terms & Conditions</p>
                                <p class="text-sm text-gray-600 mt-1">
                                    By checking this box, I acknowledge that I have read, understood, and agree to abide by all the rules, regulations, and policies mentioned above.
                                    <br><strong>Note:</strong> You only need to accept these terms once.
                                </p>
                            </div>
                        </label>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4 mt-8 pt-6 border-t">
                        <a href="logout.php" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </a>
                        <button type="button" onclick="printTerms()" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-print"></i>
                            Print Terms
                        </button>
                        <button type="submit" name="accept_terms" id="submitBtn" disabled class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-colors flex items-center justify-center gap-2 accept-btn">
                            <i class="fas fa-check-circle"></i>
                            Accept & Continue to Dashboard
                        </button>
                    </div>

                    <!-- Footer Note -->
                    <div class="mt-6 text-center text-sm text-gray-500">
                        <p>Last updated: <?php echo date('F j, Y'); ?></p>
                        <p class="mt-1">If you have any questions about these terms, please contact <a href="mailto:support@asdacademy.in" class="text-blue-600 hover:underline">support@asdacademy.in</a></p>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Toggle submit button based on checkbox
        function toggleSubmitButton() {
            const checkbox = document.getElementById('agreeCheckbox');
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = !checkbox.checked;
        }

        // Print function
        function printTerms() {
            const printContent = document.getElementById('termsContent').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>ASD Academy - Terms & Conditions</title>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
                        h1 { color: #1e40af; border-bottom: 2px solid #1e40af; padding-bottom: 10px; }
                        h2 { color: #1e40af; margin-top: 20px; }
                        h3 { color: #1e40af; margin-top: 15px; }
                        .highlight-box { background: #fef3c7; border: 1px solid #f59e0b; padding: 10px; margin: 10px 0; border-radius: 5px; }
                        .section { margin-bottom: 20px; }
                        ul { padding-left: 20px; }
                        @media print {
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h1>ASD Academy - Terms & Conditions</h1>
                    <div>${printContent}</div>
                    <div class="section" style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ccc;">
                        <p><strong>Student Name:</strong> <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
                        <p><strong>Student ID:</strong> <?php echo htmlspecialchars($student['student_id']); ?></p>
                        <p><strong>Batch:</strong> <?php echo htmlspecialchars($batch_id); ?></p>
                        <p><strong>Date Accepted:</strong> <?php echo date('F j, Y, g:i a'); ?></p>
                        <p><strong>Signature:</strong> _________________________________</p>
                    </div>
                </body>
                </html>
            `;
            
            window.print();
            document.body.innerHTML = originalContent;
        }

        // Auto-scroll to bottom after reading time
        setTimeout(() => {
            const termsContainer = document.getElementById('termsContent');
            const scrollHeight = termsContainer.scrollHeight;
            const clientHeight = termsContainer.clientHeight;
            
            // Only auto-scroll if user hasn't scrolled much
            if (termsContainer.scrollTop < 100) {
                termsContainer.scrollTo({
                    top: scrollHeight - clientHeight,
                    behavior: 'smooth'
                });
            }
        }, 5000);

        // Add scroll indicator
        document.getElementById('termsContent').addEventListener('scroll', function() {
            const container = this;
            const scrollPercentage = (container.scrollTop / (container.scrollHeight - container.clientHeight)) * 100;
            
            // Optional: Show a progress indicator
            console.log(`Read: ${Math.round(scrollPercentage)}%`);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + P to print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printTerms();
            }
            
            // Space to quickly scroll
            if (e.key === ' ' && e.target === document.getElementById('termsContent')) {
                e.preventDefault();
                const container = document.getElementById('termsContent');
                container.scrollBy({
                    top: container.clientHeight * 0.8,
                    behavior: 'smooth'
                });
            }
        });

        // Form validation on submit
        document.getElementById('termsForm').addEventListener('submit', function(e) {
            const checkbox = document.getElementById('agreeCheckbox');
            if (!checkbox.checked) {
                e.preventDefault();
                alert('Please agree to the Terms & Conditions before continuing.');
                checkbox.focus();
                checkbox.parentElement.style.borderColor = '#ef4444';
                checkbox.parentElement.style.backgroundColor = '#fef2f2';
                
                // Reset style after 2 seconds
                setTimeout(() => {
                    checkbox.parentElement.style.borderColor = '';
                    checkbox.parentElement.style.backgroundColor = '';
                }, 2000);
            }
        });

        // Auto-check scroll to bottom to encourage reading
        let hasScrolledToBottom = false;
        document.getElementById('termsContent').addEventListener('scroll', function() {
            const container = this;
            const scrollPercentage = (container.scrollTop / (container.scrollHeight - container.clientHeight)) * 100;
            
            if (scrollPercentage > 90 && !hasScrolledToBottom) {
                hasScrolledToBottom = true;
                // Optional: Enable checkbox or show message
                console.log('User has read through terms');
            }
        });
    </script>
</body>
</html>