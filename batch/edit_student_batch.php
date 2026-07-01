<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$student_id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$student_id) {
    header("Location: batch_list.php");
    exit();
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get student details
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        header("Location: batch_list.php");
        exit();
    }
    
    // Get all batches for dropdown
    $stmt = $conn->prepare("SELECT batch_id, batch_name FROM batches ORDER BY start_date DESC");
    $stmt->execute();
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $email = $_POST['email'];
        $phone_number = $_POST['phone_number'];
        $date_of_birth = $_POST['date_of_birth'];
        $current_status = $_POST['current_status'];
        $batch_name = $_POST['batch_name'];
        $father_name = $_POST['father_name'];
        $father_phone = $_POST['father_phone'];
        $father_email = $_POST['father_email'];
        
        // Handle dropout fields if status is dropped
        $dropout_date = null;
        $dropout_reason = null;
        
        if ($current_status === 'dropped') {
            $dropout_date = $_POST['dropout_date'] ?? date('Y-m-d');
            $dropout_reason = $_POST['dropout_reason'] ?? '';
        }
        
        // Update student record
        $stmt = $conn->prepare("UPDATE students SET 
                              first_name = ?, 
                              last_name = ?, 
                              email = ?, 
                              phone_number = ?, 
                              date_of_birth = ?, 
                              current_status = ?, 
                              batch_name = ?, 
                              father_name = ?, 
                              father_phone_number = ?, 
                              father_email = ?,
                              dropout_date = ?,
                              dropout_reason = ?
                              WHERE student_id = ?");
        
        $stmt->execute([
            $first_name,
            $last_name,
            $email,
            $phone_number,
            $date_of_birth,
            $current_status,
            $batch_name,
            $father_name,
            $father_phone,
            $father_email,
            $dropout_date,
            $dropout_reason,
            $student_id
        ]);
        
        // Update attendance records if batch changed
        if ($batch_name !== $student['batch_name']) {
            $stmt = $conn->prepare("UPDATE attendance SET batch_id = ? WHERE student_name = ? AND batch_id = ?");
            $stmt->execute([$batch_name, $student['first_name'] . ' ' . $student['last_name'], $student['batch_name']]);
        }
        
        // Redirect to student view
        header("Location: student_view.php?id=$student_id");
        exit();
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student | ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <style>
        /* =========================================================
           DESIGN TOKENS
           ========================================================= */
        :root {
            --navy-900:   #1B3C53;
            --navy-700:   #234C6A;
            --navy-500:   #456882;
            --sand-300:   #D2C1B6;
            --surface:    #F5F7FA;

            --navy-900-10: rgba(27,60,83,0.10);
            --navy-900-06: rgba(27,60,83,0.06);
            --navy-700-15: rgba(35,76,106,0.15);
            --sand-300-40: rgba(210,193,182,0.40);

            --amber:       #b45309;
            --amber-bg:    rgba(180,83,9,0.08);
            --amber-border:rgba(180,83,9,0.20);

            --radius-sm:  8px;
            --radius-md:  12px;
            --radius-lg:  16px;
            --radius-xl:  20px;
            --radius-pill:999px;

            --shadow-card: 0 2px 16px rgba(27,60,83,0.10), 0 1px 4px rgba(27,60,83,0.06);
            --shadow-btn:  0 2px 8px rgba(35,76,106,0.22);
            --shadow-btn-h:0 5px 16px rgba(35,76,106,0.30);

            --font-body:    'Inter', sans-serif;
            --font-display: 'Plus Jakarta Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background-color: var(--surface);
            font-family: var(--font-body);
            color: var(--navy-900);
            min-height: 100vh;
            padding: 32px 16px 56px;
        }

        /* =========================================================
           PAGE WRAPPER
           ========================================================= */
        .page-wrap {
            max-width: 860px;
            margin: 0 auto;
        }

        /* =========================================================
           CARD
           ========================================================= */
        .form-card {
            background: #fff;
            border: 1px solid var(--sand-300-40);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }

        /* =========================================================
           CARD HEADER
           ========================================================= */
        .card-header {
            background: var(--navy-900);
            padding: 20px 28px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-link {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-decoration: none;
            font-size: 0.95rem;
            flex-shrink: 0;
            transition: background 0.2s ease;
        }

        .back-link:hover { background: rgba(255,255,255,0.22); }

        .card-header-text { flex: 1; }

        .card-header-title {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 1.15rem;
            color: #fff;
            letter-spacing: -0.2px;
        }

        .card-header-sub {
            font-size: 0.78rem;
            color: rgba(210,193,182,0.85);
            margin-top: 2px;
        }

        /* =========================================================
           FORM BODY
           ========================================================= */
        .form-body {
            padding: 32px 28px;
        }

        /* =========================================================
           SECTION HEADINGS
           ========================================================= */
        .form-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: var(--font-display);
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--sand-300-40);
        }

        .form-section-title::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 16px;
            background: var(--navy-700);
            border-radius: var(--radius-pill);
            flex-shrink: 0;
        }

        /* =========================================================
           GRID
           ========================================================= */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 28px;
        }

        @media (min-width: 640px) {
            .grid-2 { grid-template-columns: 1fr 1fr; }
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        @media (min-width: 640px) { .grid-3 { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 860px) { .grid-3 { grid-template-columns: 1fr 1fr 1fr; } }

        .full-width { grid-column: 1 / -1; }

        /* =========================================================
           FORM GROUPS
           ========================================================= */
        .form-group { display: flex; flex-direction: column; gap: 6px; }

        .form-group + .form-group { margin-top: 0; }

        .fields-col { display: flex; flex-direction: column; gap: 18px; }

        /* =========================================================
           LABELS
           ========================================================= */
        label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--navy-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* =========================================================
           INPUTS, SELECTS, TEXTAREAS
           ========================================================= */
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            background: var(--surface);
            border: 1.5px solid var(--sand-300);
            border-radius: var(--radius-md);
            padding: 10px 14px;
            font-family: var(--font-body);
            font-size: 0.875rem;
            color: var(--navy-900);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--navy-700);
            box-shadow: 0 0 0 3px var(--navy-700-15);
            background: #fff;
        }

        input::placeholder,
        textarea::placeholder { color: var(--navy-500); opacity: 0.6; }

        select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23456882' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
        }

        textarea { resize: vertical; min-height: 90px; }

        /* =========================================================
           DROPOUT CONDITIONAL BLOCK
           ========================================================= */
        #dropoutFields {
            background: var(--amber-bg);
            border: 1.5px solid var(--amber-border);
            border-radius: var(--radius-md);
            padding: 18px 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        #dropoutFields label { color: var(--amber); }

        #dropoutFields input,
        #dropoutFields textarea {
            border-color: var(--amber-border);
            background: rgba(255,255,255,0.7);
        }

        #dropoutFields input:focus,
        #dropoutFields textarea:focus {
            border-color: var(--amber);
            box-shadow: 0 0 0 3px rgba(180,83,9,0.12);
        }

        /* =========================================================
           SECTION DIVIDER
           ========================================================= */
        .section-divider {
            height: 1px;
            background: var(--sand-300-40);
            margin: 32px 0;
        }

        /* =========================================================
           FORM ACTIONS
           ========================================================= */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 36px;
            padding-top: 24px;
            border-top: 1px solid var(--sand-300-40);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: var(--font-body);
            font-size: 0.8375rem;
            font-weight: 600;
            border-radius: var(--radius-md);
            padding: 10px 22px;
            cursor: pointer;
            transition: all 0.22s ease;
            text-decoration: none;
            border: none;
            line-height: 1.4;
        }

        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        .btn-cancel {
            background: transparent;
            color: var(--navy-700);
            border: 1.5px solid var(--sand-300);
            box-shadow: none;
        }

        .btn-cancel:hover {
            background: var(--navy-900-06);
            border-color: var(--navy-500);
            color: var(--navy-900);
        }

        .btn-save {
            background: var(--navy-900);
            color: #fff;
            box-shadow: var(--shadow-btn);
        }

        .btn-save:hover {
            background: var(--navy-700);
            box-shadow: var(--shadow-btn-h);
        }
    </style>
</head>
<body>
    <div class="page-wrap">
        <div class="form-card">

            <!-- Header -->
            <div class="card-header">
                <a href="student_view.php?id=<?= $student_id ?>" class="back-link" title="Back to student profile">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div class="card-header-text">
                    <div class="card-header-title">Edit Student</div>
                    <div class="card-header-sub">
                        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?> &mdash; ID: <?= htmlspecialchars($student_id) ?>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <form method="POST" class="form-body">

                <!-- Two-column section: Personal + Academic -->
                <div class="grid-2">

                    <!-- ── Personal Information ── -->
                    <div class="fields-col">
                        <div class="form-section-title">Personal Information</div>

                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name"
                                   value="<?= htmlspecialchars($student['first_name']) ?>"
                                   placeholder="Enter first name">
                        </div>

                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name"
                                   value="<?= htmlspecialchars($student['last_name']) ?>"
                                   placeholder="Enter last name">
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email"
                                   value="<?= htmlspecialchars($student['email']) ?>"
                                   placeholder="student@example.com">
                        </div>

                        <div class="form-group">
                            <label for="phone_number">Phone Number</label>
                            <input type="tel" id="phone_number" name="phone_number"
                                   value="<?= htmlspecialchars($student['phone_number']) ?>"
                                   placeholder="+91 XXXXX XXXXX">
                        </div>

                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth"
                                   value="<?= htmlspecialchars($student['date_of_birth']) ?>">
                        </div>
                    </div>

                    <!-- ── Academic Information ── -->
                    <div class="fields-col">
                        <div class="form-section-title">Academic Information</div>

                        <div class="form-group">
                            <label for="current_status">Enrollment Status</label>
                            <select id="current_status" name="current_status">
                                <option value="active"   <?= $student['current_status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="dropped"  <?= $student['current_status'] === 'dropped'  ? 'selected' : '' ?>>Dropped</option>
                                <option value="on_hold"  <?= $student['current_status'] === 'on_hold'  ? 'selected' : '' ?>>On Hold</option>
                            </select>
                        </div>

                        <!-- Dropout conditional block -->
                        <div id="dropoutFields"
                             style="<?= $student['current_status'] !== 'dropped' ? 'display:none;' : '' ?>">
                            <div class="form-group">
                                <label for="dropout_date">Dropout Date</label>
                                <input type="date" id="dropout_date" name="dropout_date"
                                       value="<?= $student['dropout_date'] ? htmlspecialchars($student['dropout_date']) : date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label for="dropout_reason">Dropout Reason</label>
                                <textarea id="dropout_reason" name="dropout_reason"
                                          placeholder="Briefly describe the reason..."><?= htmlspecialchars($student['dropout_reason'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="batch_name">Assigned Batch</label>
                            <select id="batch_name" name="batch_name">
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>"
                                            <?= $batch['batch_id'] === $student['batch_name'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id']) ?> — <?= htmlspecialchars($batch['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                </div><!-- /.grid-2 -->

                <!-- ── Parent / Guardian Information ── -->
                <div class="section-divider"></div>

                <div class="form-section-title">Parent / Guardian Information</div>

                <div class="grid-3">
                    <div class="form-group">
                        <label for="father_name">Guardian Name</label>
                        <input type="text" id="father_name" name="father_name"
                               value="<?= htmlspecialchars($student['father_name'] ?? '') ?>"
                               placeholder="Full name">
                    </div>

                    <div class="form-group">
                        <label for="father_phone">Phone Number</label>
                        <input type="tel" id="father_phone" name="father_phone"
                               value="<?= htmlspecialchars($student['father_phone_number'] ?? '') ?>"
                               placeholder="+91 XXXXX XXXXX">
                    </div>

                    <div class="form-group">
                        <label for="father_email">Email Address</label>
                        <input type="email" id="father_email" name="father_email"
                               value="<?= htmlspecialchars($student['father_email'] ?? '') ?>"
                               placeholder="parent@example.com">
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="student_view.php?id=<?= $student_id ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-save">
                        <i class="fas fa-check"></i> Save Changes
                    </button>
                </div>

            </form><!-- /form -->
        </div><!-- /.form-card -->
    </div><!-- /.page-wrap -->

    <script>
        // Show/hide dropout fields based on status
        document.getElementById('current_status').addEventListener('change', function() {
            const dropoutFields = document.getElementById('dropoutFields');
            if (this.value === 'dropped') {
                dropoutFields.style.display = 'block';
            } else {
                dropoutFields.style.display = 'none';
            }
        });
    </script>
</body>
</html>