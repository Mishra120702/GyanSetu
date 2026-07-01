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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .form-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .file-upload {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .file-upload:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .file-upload i {
            font-size: 48px;
            color: #6c757d;
            margin-bottom: 15px;
        }
        .file-input {
            display: none;
        }
        .file-name {
            margin-top: 10px;
            font-size: 14px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0"><i class="fas fa-upload me-2"></i>Upload Batch Data</h4>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($message)): ?>
                                <div class="alert alert-<?php echo strpos($message, 'Error') !== false ? 'danger' : 'success'; ?>">
                                    <?php echo htmlspecialchars($message); ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" enctype="multipart/form-data" class="form-container">
                                <div class="mb-4">
                                    <label class="form-label">Excel File Format</label>
                                    <div class="alert alert-info">
                                        <p class="mb-2">Your Excel file should have the following columns in order:</p>
                                        <ol class="mb-0">
                                            <li>Batch ID (e.g. B001)</li>
                                            <li>Course Name</li>
                                            <li>Start Date (YYYY-MM-DD)</li>
                                            <li>End Date (YYYY-MM-DD)</li>
                                            <li>Time Slot (e.g. 18:00-20:00)</li>
                                            <li>Platform (Google Meet/Zoom/Microsoft Teams)</li>
                                            <li>Meeting Link (URL)</li>
                                            <li>Max Students (number)</li>
                                            <li>Mode (online/offline)</li>
                                            <li>Status (upcoming/ongoing/completed/cancelled)</li>
                                        </ol>
                                        <p class="mt-2 mb-0">First row should be headers.</p>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="batch_excel" class="form-label">Select Excel File*</label>
                                    <div class="file-upload" onclick="document.getElementById('batch_excel').click()">
                                        <i class="fas fa-file-excel"></i>
                                        <p>Click to upload or drag and drop</p>
                                        <p class="file-name" id="file-name">No file selected</p>
                                        <input type="file" id="batch_excel" name="batch_excel" accept=".xlsx,.xls" class="file-input" required>
                                    </div>
                                    <div class="form-text">Only .xlsx or .xls files are accepted</div>
                                </div>

                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="batch_list.php" class="btn btn-secondary me-md-2">
                                        <i class="fas fa-arrow-left me-1"></i> Back to Batches
                                    </a>
                                    <button type="submit" name="upload_batch" class="btn btn-primary">
                                        <i class="fas fa-upload me-1"></i> Upload & Import
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-download me-2"></i>Download Template</h5>
                        </div>
                        <div class="card-body">
                            <p>Download our Excel template to ensure your file has the correct format:</p>
                            <a href="../uploads/batch_template.xlsx" class="btn btn-success" download>
                                <i class="fas fa-file-download me-1"></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show selected file name
        document.getElementById('batch_excel').addEventListener('change', function(e) {
            const fileName = e.target.files[0] ? e.target.files[0].name : 'No file selected';
            document.getElementById('file-name').textContent = fileName;
        });

        // Drag and drop functionality
        const fileUpload = document.querySelector('.file-upload');
        
        fileUpload.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileUpload.classList.add('border-primary');
            fileUpload.style.backgroundColor = '#f8f9fa';
        });

        fileUpload.addEventListener('dragleave', () => {
            fileUpload.classList.remove('border-primary');
            fileUpload.style.backgroundColor = '';
        });

        fileUpload.addEventListener('drop', (e) => {
            e.preventDefault();
            fileUpload.classList.remove('border-primary');
            fileUpload.style.backgroundColor = '';
            
            if (e.dataTransfer.files.length) {
                const fileInput = document.getElementById('batch_excel');
                fileInput.files = e.dataTransfer.files;
                document.getElementById('file-name').textContent = e.dataTransfer.files[0].name;
            }
        });
    </script>
</body>
</html>