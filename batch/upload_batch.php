<?php
// upload_batch.php
require_once '../db_connection.php';
session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$title = "Upload Batch Data";
$message = '';

// Handle Excel upload
if (isset($_POST['upload_batch'])) {
    require '../vendor/autoload.php'; // PhpSpreadsheet autoloader
    
    if (isset($_FILES['batch_excel']) && $_FILES['batch_excel']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['batch_excel']['tmp_name'];
        $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($fileTmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        $skipped = [];
        $successCount = 0;

        // Start from row 1 (assuming first row is headers)
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];

            // Validate row has enough columns
            if (count($row) < 10) {
                $skipped[] = "Row " . ($i + 1) . ": Insufficient data columns";
                continue;
            }

            $batch_id = $row[0] ?? '';
            $batch_name = $row[1] ?? '';
            $start_date = date('Y-m-d', strtotime($row[2] ?? ''));
            $end_date = date('Y-m-d', strtotime($row[3] ?? ''));
            $time_slot = $row[4] ?? '';
            $platform = $row[5] ?? '';
            $meeting_link = $row[6] ?? '';
            $max_students = $row[7] ?? 0;
            $mode = $row[8] ?? 'online';
            $status = $row[9] ?? 'upcoming';

            // Validate required fields
            if (empty($batch_id) || empty($batch_name) || empty($start_date) || empty($end_date)) {
                $skipped[] = "Row " . ($i + 1) . ": Missing required fields";
                continue;
            }

            // Validate mode
            if (!in_array($mode, ['online', 'offline'])) {
                $mode = 'online';
            }

            // Validate status
            if (!in_array($status, ['upcoming', 'ongoing', 'completed', 'cancelled'])) {
                $status = 'upcoming';
            }

            try {
                // Check if batch already exists
                $check_batch = $db->prepare("SELECT batch_id FROM batches WHERE batch_id = :batch_id");
                $check_batch->bindParam(':batch_id', $batch_id);
                $check_batch->execute();

                if ($check_batch->rowCount() > 0) {
                    $skipped[] = "Row " . ($i + 1) . ": Batch ID $batch_id already exists";
                    continue;
                }

                // Insert into batches table
                $stmt = $db->prepare("INSERT INTO batches (
                    batch_id, batch_name, start_date, end_date, time_slot, platform, 
                    meeting_link, max_students, current_enrollment, academic_year,
                    batch_mentor_id, num_students, mode, status, created_by, created_at
                ) VALUES (
                    :batch_id, :batch_name, :start_date, :end_date, :time_slot, :platform, 
                    :meeting_link, :max_students, 0, :academic_year,
                    NULL, 0, :mode, :status, :created_by, NOW()
                )");
                
                $academic_year = date('Y', strtotime($start_date)) . '-' . (date('Y', strtotime($start_date)) + 1);
                
                $stmt->bindParam(':batch_id', $batch_id);
                $stmt->bindParam(':batch_name', $batch_name);
                $stmt->bindParam(':start_date', $start_date);
                $stmt->bindParam(':end_date', $end_date);
                $stmt->bindParam(':time_slot', $time_slot);
                $stmt->bindParam(':platform', $platform);
                $stmt->bindParam(':meeting_link', $meeting_link);
                $stmt->bindParam(':max_students', $max_students, PDO::PARAM_INT);
                $stmt->bindParam(':academic_year', $academic_year);
                $stmt->bindParam(':mode', $mode);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    $successCount++;
                } else {
                    $skipped[] = "Row " . ($i + 1) . ": " . implode(" ", $stmt->errorInfo());
                }
            } catch (PDOException $e) {
                $skipped[] = "Row " . ($i + 1) . ": " . $e->getMessage();
            }
        }

        // Prepare result message
        $message = "Batch data imported successfully. $successCount records added.";
        if (!empty($skipped)) {
            $message .= " Skipped rows: " . implode(', ', $skipped);
        }

        $_SESSION['import_message'] = $message;
        header("Location: batch_list.php");
        exit;
    } else {
        $message = "Error uploading file. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: '#4F46E5', dark: '#4338CA', light: '#EEF2FF' },
                        success: { DEFAULT: '#10B981', light: '#ECFDF5' },
                        warning: { DEFAULT: '#F59E0B', light: '#FFFBEB' },
                        danger:  { DEFAULT: '#EF4444', light: '#FEF2F2' },
                        ink:     '#0F172A',
                    },
                    boxShadow: {
                        soft: '0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -8px rgba(15,23,42,0.08)',
                        lift: '0 12px 32px -10px rgba(79,70,229,0.25)',
                    },
                    borderRadius: { '2xl': '20px' }
                }
            }
        }
    </script>
        <style>
        /* ═══ INDIGO + PURPLE + CYAN DESIGN SYSTEM ═══ */
        :root {
            --indigo:#1B3C53; --indigo-dk:#234C6A; --indigo-lt:#D2C1B6;
            --purple:#456882; --purple-dk:#6D28D9; --purple-lt:#F5F3FF;
            --cyan:#06B6D4;   --cyan-dk:#0891B2;   --cyan-lt:#F8F5F2;
            --success:#10B981; --success-lt:#ECFDF5;
            --warning:#F59E0B; --warning-lt:#FFFBEB;
            --danger:#EF4444;  --danger-lt:#FEF2F2;
            --ink:#0F172A; --slate:#475569; --muted:#94A3B8;
        }
        body {
            font-family:'Inter',system-ui,sans-serif;
            background:linear-gradient(
180deg,
#D2C1B6 0%,
#456882 50%,
#1B3C53 100%
) fixed !important;
            min-height:100vh; color:var(--ink);
            -webkit-font-smoothing:antialiased;
        }
        /* Glass base */
        .glass {
            background:rgba(255,255,255,0.75);
            backdrop-filter:blur(14px);
            border:1px solid rgba(79,70,229,0.13);
            box-shadow:0 4px 24px -6px rgba(79,70,229,0.12),0 1px 3px rgba(0,0,0,0.04);
        }
        /* Hero bar */
        .hero-bar {
            background:linear-gradient(
135deg,
rgba(27,60,83,.08),
rgba(35,76,106,.06),
rgba(69,104,130,.05)
);
            border:1px solid rgba(79,70,229,.14);
            border-radius:18px;
            padding:20px 24px;
        }
        /* Main upload card */
        .upload-card {
            background:rgba(255,255,255,0.8);
            backdrop-filter:blur(14px);
            border:1px solid rgba(79,70,229,0.14);
            border-top:3px solid var(--indigo);
            border-radius:20px;
            overflow:hidden;
            box-shadow:0 4px 24px -6px rgba(79,70,229,0.14);
        }
        .upload-card-header {
            background:linear-gradient(90deg,rgba(79,70,229,.06),rgba(124,58,237,.04));
            border-bottom:1px solid rgba(79,70,229,.1);
        }
        /* Side cards */
        .side-card {
            background:rgba(255,255,255,0.78);
            backdrop-filter:blur(12px);
            border:1px solid rgba(79,70,229,0.13);
            border-radius:20px;
            overflow:hidden;
            box-shadow:0 4px 20px -6px rgba(79,70,229,0.1);
        }
        .side-card-header {
            background:linear-gradient(90deg,rgba(79,70,229,.06),rgba(124,58,237,.04));
            border-bottom:1px solid rgba(79,70,229,.1);
        }
        /* Dropzone */
        #dropzone {
            border:2px dashed rgba(79,70,229,0.3);
            border-radius:18px;
            background:linear-gradient(135deg,rgba(238,242,255,.6),rgba(245,243,255,.4));
            transition:all .25s ease;
            cursor:pointer;
        }
        #dropzone:hover {
            border-color:var(--indigo);
            background:rgba(238,242,255,.8);
            transform:translateY(-2px);
            box-shadow:0 8px 24px -6px rgba(79,70,229,.2);
        }
        .dropzone-active {
            border-color:var(--indigo) !important;
            background:rgba(238,242,255,.95) !important;
            box-shadow:0 8px 24px -6px rgba(79,70,229,.25) !important;
        }
        /* Upload icon */
        .upload-icon-wrap {
            width:64px;height:64px;margin:0 auto 16px;border-radius:18px;
            background:linear-gradient(
135deg,
#1B3C53,
#456882
);
            display:flex;align-items:center;justify-content:center;
            color:#fff;font-size:1.5rem;
            box-shadow:0 8px 20px -6px rgba(79,70,229,.45);
        }
        /* Number circles */
        .num-circle {
            width:22px;height:22px;border-radius:50%;flex-shrink:0;
            background:linear-gradient(135deg,var(--indigo),var(--purple));
            color:#fff;font-size:.7rem;font-weight:700;
            display:flex;align-items:center;justify-content:center;
        }
        /* Buttons */
        .btn-upload {
            display:inline-flex;align-items:center;gap:.5rem;
            background:linear-gradient(
135deg,
#1B3C53,
#234C6A
);
            color:#fff;border:none;border-radius:12px;
            padding:.65rem 1.5rem;font-weight:700;font-size:.875rem;
            box-shadow:0 4px 14px -4px rgba(79,70,229,.5);
            transition:all .2s ease;cursor:pointer;
        }
        .btn-upload:hover { transform:translateY(-2px);box-shadow:0 8px 24px -6px rgba(124,58,237,.55); }
        .btn-upload:disabled { opacity:.6;cursor:not-allowed;transform:none; }
        .btn-cancel {
            display:inline-flex;align-items:center;gap:.5rem;
            background:rgba(238,242,255,.8);
            color:var(--indigo);
            border:1px solid rgba(79,70,229,.25);
            border-radius:12px;padding:.65rem 1.2rem;font-weight:600;font-size:.875rem;
            transition:all .2s ease;text-decoration:none;
        }
        .btn-cancel:hover { background:var(--indigo-lt);border-color:var(--indigo);color:var(--indigo); }
        .btn-back {
            width:40px;height:40px;
            display:flex;align-items:center;justify-content:center;
            background:rgba(255,255,255,.85);
            border:1px solid rgba(79,70,229,.2);
            border-radius:12px;color:var(--indigo);
            backdrop-filter:blur(8px);
            box-shadow:0 2px 8px -2px rgba(79,70,229,.12);
            transition:all .2s ease;text-decoration:none;
        }
        .btn-back:hover { background:var(--indigo-lt);border-color:var(--indigo);transform:translateY(-1px); }
        /* Download template button */
        .btn-template {
            width:100%;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;
            background:linear-gradient(135deg,var(--success),var(--cyan));
            color:#fff;border:none;border-radius:12px;
            padding:.6rem 1rem;font-weight:700;font-size:.875rem;
            box-shadow:0 4px 14px -4px rgba(16,185,129,.4);
            transition:all .2s;text-decoration:none;
        }
        .btn-template:hover { transform:translateY(-2px);box-shadow:0 8px 20px -6px rgba(16,185,129,.5);color:#fff; }
        /* Processing bar */
        #processingBar {
            background:linear-gradient(90deg,rgba(79,70,229,.08),rgba(124,58,237,.06));
            border:1px solid rgba(79,70,229,.2);
            border-radius:14px;
        }
        /* Back to batches btn in header */
        .back-to-batches {
            display:inline-flex;align-items:center;gap:.5rem;
            background:rgba(238,242,255,.8);
            color:var(--indigo);
            border:1px solid rgba(79,70,229,.2);
            border-radius:12px;padding:.45rem 1rem;font-weight:600;font-size:.8125rem;
            backdrop-filter:blur(8px);
            text-decoration:none;transition:all .2s;
        }
        .back-to-batches:hover { background:var(--indigo-lt);border-color:var(--indigo);color:var(--indigo);transform:translateY(-1px); }
        /* Info icon tile */
        .info-tile { width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.85rem; }
        /* Animations */
        .fade-up { animation:fadeUp .4s ease both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
        .pop-in { animation:pop .35s ease both; }
        @keyframes pop { 0%{transform:scale(.6);opacity:0} 60%{transform:scale(1.08);opacity:1} 100%{transform:scale(1)} }
        /* Scrollbar */
        ::-webkit-scrollbar{width:7px} ::-webkit-scrollbar-track{background:rgba(238,242,255,.5);border-radius:10px}
        ::-webkit-scrollbar-thumb{background:rgba(79,70,229,.3);border-radius:10px}
    </style>
</head>
<body style="background:linear-gradient(180deg,#D2C1B6 0%,#EEF2FF 40%,#F8F5F2 100%);background-attachment:fixed;min-height:100vh;">

<div class="flex">
    <?php include '../sidebar.php'; ?>

    <div class="flex-1 ml-64">

    <div class="w-full px-6 py-8">

        <!-- Hero header -->
        <div class="hero-bar flex items-center justify-between mb-8 fade-up">
            <div class="flex items-center gap-4">
                <a href="batch_list.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-ink tracking-tight">Upload Batch Data</h1>
                    <p class="text-sm text-slate-500 mt-0.5">Bulk import batches from an Excel spreadsheet.</p>
                </div>
            </div>
            <a href="batch_list.php" class="back-to-batches hidden sm:inline-flex">
                <i class="fas fa-list"></i> Back to Batches
            </a>
        </div>

        <?php if (!empty($message)): ?>
            <?php $isError = strpos($message, 'Error') !== false; ?>
            <div class="fade-up mb-6 rounded-2xl p-4 flex items-start gap-3" style="<?= $isError ? 'background:var(--danger-lt);border:1px solid rgba(239,68,68,.2);' : 'background:var(--success-lt);border:1px solid rgba(16,185,129,.2);' ?>">
                <div class="w-8 h-8 rounded-full flex items-center justify-center pop-in" style="<?= $isError ? 'background:var(--danger);' : 'background:var(--success);' ?> color:#fff; flex-shrink:0;">
                    <i class="fas <?= $isError ? 'fa-exclamation' : 'fa-check' ?> text-sm"></i>
                </div>
                <p class="text-sm <?= $isError ? 'text-red-700' : 'text-emerald-700' ?> leading-relaxed pt-1">
                    <?php echo htmlspecialchars($message); ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">

            <!-- Upload card -->
            <div class="xl:col-span-3 fade-up">
                <div class="upload-card">
                    <div class="upload-card-header px-6 py-5 flex items-center gap-3">
                        <div class="info-tile" style="background:linear-gradient(135deg,var(--indigo-lt),#DDD6FE);color:var(--indigo);">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <h2 class="font-semibold text-ink">Excel File</h2>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="p-6" id="uploadForm">

                        <div
                            id="dropzone"
                            class="relative border-2 border-dashed border-slate-200 rounded-2xl p-10 text-center cursor-pointer transition-all duration-300 hover:border-primary/40 hover:bg-primary-light/40 hover:-translate-y-0.5"
                            onclick="document.getElementById('batch_excel').click()"
                        >
                            <div class="upload-icon-wrap">
                                <i class="fas fa-cloud-arrow-up"></i>
                            </div>
                            <p class="font-semibold text-slate-700 mt-2">Click to upload or drag and drop</p>
                            <p class="text-xs text-slate-400 mt-1">.xlsx or .xls files only</p>
                            <p class="mt-3 text-sm font-semibold" style="color:var(--indigo);" id="file-name">No file selected</p>
                            <input type="file" id="batch_excel" name="batch_excel" accept=".xlsx,.xls" class="hidden" required>
                        </div>

                        <!-- Processing indicator (shown on submit) -->
                        <div id="processingBar" class="hidden mt-4 rounded-xl bg-indigo-50 border border-indigo-200 px-4 py-3 items-center gap-3 flex">
                            <i class="fas fa-spinner fa-spin text-indigo-600"></i>
                            <span class="text-sm text-indigo-600 font-medium">Processing your file, please wait…</span>
                        </div>

                        <div class="mt-6 flex justify-end gap-3">
                            <a href="batch_list.php" class="btn-cancel">
                                <i class="fas fa-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" name="upload_batch" id="submitBtn" class="btn-upload">
                                <i class="fas fa-upload"></i> Upload &amp; Import
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Side info -->
            <div class="space-y-6 fade-up">

                <!-- Column format -->
                <div class="upload-card">
                    <div class="side-card-header px-5 py-4 flex items-center gap-3">
                        <div class="info-tile" style="background:linear-gradient(135deg,var(--warning-lt),#FDE68A);color:var(--warning);">
                            <i class="fas fa-circle-info"></i>
                        </div>
                        <h3 class="font-semibold text-ink text-sm">Required Column Order</h3>
                    </div>
                    <ol class="px-5 py-4 space-y-2.5 text-sm text-slate-600">
                        <li class="flex gap-3"><span class="num-circle">1</span>Batch ID <span class="text-slate-400">(e.g. B001)</span></li>
                        <li class="flex gap-3"><span class="num-circle">2</span>Course Name</li>
                        <li class="flex gap-3"><span class="num-circle">3</span>Start Date <span class="text-slate-400">(YYYY-MM-DD)</span></li>
                        <li class="flex gap-3"><span class="num-circle">4</span>End Date <span class="text-slate-400">(YYYY-MM-DD)</span></li>
                        <li class="flex gap-3"><span class="num-circle">5</span>Time Slot <span class="text-slate-400">(18:00-20:00)</span></li>
                        <li class="flex gap-3"><span class="num-circle">6</span>Platform</li>
                        <li class="flex gap-3"><span class="num-circle">7</span>Meeting Link</li>
                        <li class="flex gap-3"><span class="num-circle">8</span>Max Students</li>
                        <li class="flex gap-3"><span class="num-circle">9</span>Mode <span class="text-slate-400">(online/offline)</span></li>
                        <li class="flex gap-3"><span class="num-circle">10</span>Status</li>
                    </ol>
                    <p class="px-5 pb-4 text-xs text-slate-400">First row must be headers — it will be skipped automatically.</p>
                </div>

                <!-- Template -->
                <div class="side-card p-5">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="info-tile" style="background:linear-gradient(135deg,var(--success-lt),#A7F3D0);color:var(--success);">
                            <i class="fas fa-download"></i>
                        </div>
                        <h3 class="font-semibold text-ink text-sm">Need a Template?</h3>
                    </div>
                    <p class="text-sm text-slate-500 mb-4">Download a ready-made spreadsheet with the correct columns.</p>
                    <a href="../uploads/batch_template.xlsx" download class="btn-template">
                        <i class="fas fa-file-arrow-down"></i> Download Template
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('batch_excel');
        const fileNameEl = document.getElementById('file-name');

        fileInput.addEventListener('change', function(e) {
            fileNameEl.textContent = e.target.files[0] ? e.target.files[0].name : 'No file selected';
        });

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dropzone-active');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dropzone-active');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dropzone-active');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                fileNameEl.textContent = e.dataTransfer.files[0].name;
            }
        });

        // Visual-only processing indicator while the form posts/imports
        document.getElementById('uploadForm').addEventListener('submit', function() {
            document.getElementById('processingBar').classList.remove('hidden');
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.classList.add('opacity-60', 'cursor-not-allowed');
        });
    </script>
        </div>
</div>
</body>
</html>
