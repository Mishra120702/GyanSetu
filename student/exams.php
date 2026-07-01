<?php
// Database connection directly in this file
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
$host = 'localhost';
$dbname = 'u621399201_koral';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ========== AUTHENTICATION CHECK (Integrated) ==========
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check user role (admin or mentor only)
if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'mentor')) {
    header("Location: login.php");
    exit();
}
// ========== END AUTHENTICATION CHECK ==========

$exam_id = isset($_GET['exam_id']) ? $_GET['exam_id'] : '';
$message = '';
$error = '';

// Get exam details
$exam = null;
if (!empty($exam_id)) {
    try {
        $stmt = $conn->prepare("SELECT e.*, b.batch_name FROM exams e JOIN batches b ON e.batch_id = b.batch_id WHERE e.exam_id = ?");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

if (!$exam) {
    header("Location: exams.php");
    exit();
}

// Get students in this batch
try {
    $students = $conn->prepare("SELECT student_id, first_name, last_name FROM students WHERE batch_name = ? ORDER BY first_name, last_name");
    $students->execute([$exam['batch_id']]);
    $students = $students->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $students = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['upload_single'])) {
        $student_id = $_POST['student_id'];
        $obtained_marks = floatval($_POST['obtained_marks']);
        $remarks = trim($_POST['remarks']);
        
        // Validate marks
        if ($obtained_marks < 0 || $obtained_marks > $exam['total_marks']) {
            $error = "Invalid marks. Marks should be between 0 and " . $exam['total_marks'];
        } else {
            // Calculate grade
            $grade = calculateGrade($obtained_marks, $exam['total_marks']);
            
            try {
                // Check if result already exists
                $check_stmt = $conn->prepare("SELECT result_id FROM exam_results WHERE exam_id = ? AND student_id = ?");
                $check_stmt->execute([$exam_id, $student_id]);
                $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Update existing result
                    $stmt = $conn->prepare("UPDATE exam_results SET obtained_marks = ?, grade = ?, remarks = ?, uploaded_by = ? WHERE exam_id = ? AND student_id = ?");
                    if ($stmt->execute([$obtained_marks, $grade, $remarks, $_SESSION['user_id'], $exam_id, $student_id])) {
                        $message = "Results updated successfully for student ID: $student_id";
                    } else {
                        $error = "Failed to update results";
                    }
                } else {
                    // Insert new result
                    $stmt = $conn->prepare("INSERT INTO exam_results (exam_id, student_id, obtained_marks, grade, remarks, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt->execute([$exam_id, $student_id, $obtained_marks, $grade, $remarks, $_SESSION['user_id']])) {
                        $message = "Results uploaded successfully for student ID: $student_id";
                    } else {
                        $error = "Failed to upload results";
                    }
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        // Handle CSV upload
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        if ($handle === false) {
            $error = "Failed to open CSV file";
        } else {
            // Skip header
            fgetcsv($handle);
            
            $success_count = 0;
            $error_count = 0;
            $errors = [];
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Skip empty rows
                if (empty($data[0]) || empty($data[1])) {
                    continue;
                }
                
                $student_id = trim($data[0]);
                $obtained_marks = floatval($data[1]);
                $remarks = isset($data[2]) ? trim($data[2]) : '';
                
                // Validate marks
                if ($obtained_marks < 0 || $obtained_marks > $exam['total_marks']) {
                    $error_count++;
                    $errors[] = "Invalid marks for student $student_id: $obtained_marks";
                    continue;
                }
                
                $grade = calculateGrade($obtained_marks, $exam['total_marks']);
                
                try {
                    // Check if result already exists
                    $check_stmt = $conn->prepare("SELECT result_id FROM exam_results WHERE exam_id = ? AND student_id = ?");
                    $check_stmt->execute([$exam_id, $student_id]);
                    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        // Update existing result
                        $stmt = $conn->prepare("UPDATE exam_results SET obtained_marks = ?, grade = ?, remarks = ?, uploaded_by = ? WHERE exam_id = ? AND student_id = ?");
                        if ($stmt->execute([$obtained_marks, $grade, $remarks, $_SESSION['user_id'], $exam_id, $student_id])) {
                            $success_count++;
                        } else {
                            $error_count++;
                            $errors[] = "Failed to update result for student $student_id";
                        }
                    } else {
                        // Insert new result
                        $stmt = $conn->prepare("INSERT INTO exam_results (exam_id, student_id, obtained_marks, grade, remarks, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                        if ($stmt->execute([$exam_id, $student_id, $obtained_marks, $grade, $remarks, $_SESSION['user_id']])) {
                            $success_count++;
                        } else {
                            $error_count++;
                            $errors[] = "Failed to insert result for student $student_id";
                        }
                    }
                } catch (PDOException $e) {
                    $error_count++;
                    $errors[] = "Database error for student $student_id";
                }
            }
            
            fclose($handle);
            
            $message = "Bulk upload completed: $success_count records inserted/updated, $error_count errors.";
            if (!empty($errors)) {
                $error = "Errors encountered:<br>" . implode("<br>", array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $error .= "<br>... and " . (count($errors) - 5) . " more errors";
                }
            }
        }
    }
}

function calculateGrade($obtained, $total) {
    if ($total == 0) return 'N/A';
    
    $percentage = ($obtained / $total) * 100;
    
    if ($percentage >= 90) return 'A+';
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C';
    if ($percentage >= 40) return 'D';
    return 'F';
}

// Get existing results for display
$existing_results = [];
try {
    $stmt = $conn->prepare("SELECT r.*, s.first_name, s.last_name FROM exam_results r 
                            JOIN students s ON r.student_id = s.student_id 
                            WHERE r.exam_id = ? ORDER BY s.first_name, s.last_name");
    $stmt->execute([$exam_id]);
    $existing_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Ignore - results may not exist yet
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Results - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }
        .nav-link {
            color: #495057;
        }
        .nav-link.active {
            font-weight: bold;
            color: #0d6efd;
        }
        .main-content {
            padding: 20px;
        }
        .card {
            margin-bottom: 20px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .grade-badge {
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
        }
        .grade-Aplus { background-color: #28a745; color: white; }
        .grade-A { background-color: #20c997; color: white; }
        .grade-Bplus { background-color: #17a2b8; color: white; }
        .grade-B { background-color: #007bff; color: white; }
        .grade-C { background-color: #fd7e14; color: white; }
        .grade-D { background-color: #ffc107; color: black; }
        .grade-F { background-color: #dc3545; color: white; }
        .grade-N\A { background-color: #6c757d; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar py-4">
                <h4 class="text-center mb-4">ASD Academy</h4>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="batches.php">
                            <i class="fas fa-users me-2"></i> Batches
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="students.php">
                            <i class="fas fa-user-graduate me-2"></i> Students
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="exams.php">
                            <i class="fas fa-file-alt me-2"></i> Exams
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="attendance.php">
                            <i class="fas fa-calendar-check me-2"></i> Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-2"></i> Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main content -->
            <div class="col-md-9 col-lg-10 main-content">
                <div class="d-flex justify-content-between align-items-center py-3 mb-4">
                    <h2><i class="fas fa-upload me-2"></i>Upload Exam Results</h2>
                    <a href="exams.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Exams
                    </a>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Exam Information -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Exam Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <p><strong>Exam ID:</strong> <?php echo htmlspecialchars($exam['exam_id']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <p><strong>Exam Name:</strong> <?php echo htmlspecialchars($exam['exam_name']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <p><strong>Batch:</strong> <?php echo htmlspecialchars($exam['batch_name']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <p><strong>Subject:</strong> <?php echo htmlspecialchars($exam['subject']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <p><strong>Date:</strong> <?php echo date('d M Y', strtotime($exam['exam_date'])); ?></p>
                            </div>
                            <div class="col-md-3">
                                <p><strong>Total Marks:</strong> <?php echo $exam['total_marks']; ?></p>
                            </div>
                            <div class="col-md-3">
                                <p><strong>Passing Marks:</strong> <?php echo $exam['passing_marks']; ?></p>
                            </div>
                            <div class="col-md-3">
                                <p><strong>Type:</strong> <?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Existing Results -->
                <?php if (!empty($existing_results)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Existing Results</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th>Obtained Marks</th>
                                        <th>Grade</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($existing_results as $result): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($result['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></td>
                                        <td><?php echo number_format($result['obtained_marks'], 2); ?> / <?php echo $exam['total_marks']; ?></td>
                                        <td>
                                            <span class="grade-badge grade-<?php 
                                                $gradeClass = str_replace('+', 'plus', $result['grade']);
                                                $gradeClass = str_replace('/', '\\', $gradeClass);
                                                echo $gradeClass;
                                            ?>">
                                                <?php echo htmlspecialchars($result['grade']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($result['remarks'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Single Result Upload -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Upload Single Result</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-5 mb-3">
                                    <label for="student_id" class="form-label">Student <span class="text-danger">*</span></label>
                                    <select class="form-select" id="student_id" name="student_id" required>
                                        <option value="">Select Student</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['student_id']; ?>">
                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['student_id'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($students)): ?>
                                        <small class="text-muted">No students found in this batch</small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="obtained_marks" class="form-label">Obtained Marks <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="obtained_marks" name="obtained_marks" 
                                           step="0.01" min="0" max="<?php echo $exam['total_marks']; ?>" required>
                                    <small class="text-muted">Max: <?php echo $exam['total_marks']; ?></small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="remarks" class="form-label">Remarks</label>
                                    <input type="text" class="form-control" id="remarks" name="remarks" 
                                           placeholder="Optional remarks" maxlength="255">
                                </div>
                            </div>
                            <button type="submit" name="upload_single" class="btn btn-primary" <?php echo empty($students) ? 'disabled' : ''; ?>>
                                <i class="fas fa-upload me-1"></i> Upload Result
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Bulk Upload -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-file-upload me-2"></i>Bulk Upload via CSV</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>CSV Format:</strong> The CSV file should have the following columns in order:<br>
                            <code>student_id, obtained_marks, remarks</code><br><br>
                            <strong>Example:</strong><br>
                            <code>STD001,85.5,Excellent performance</code><br>
                            <code>STD002,72.0,Good effort</code><br>
                            <code>STD003,68.5,Needs improvement</code>
                        </div>
                        
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="csv_file" class="form-label">CSV File <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                                <small class="text-muted">Maximum file size: 2MB</small>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-file-csv me-1"></i> Upload CSV
                            </button>
                            <a href="#" class="btn btn-outline-secondary ms-2" onclick="downloadTemplate(event)">
                                <i class="fas fa-download me-1"></i> Download Template
                            </a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function downloadTemplate(event) {
            event.preventDefault();
            
            // Create CSV template
            const headers = ['student_id', 'obtained_marks', 'remarks'];
            const sampleData = [
                ['STD001', '85.5', 'Excellent performance'],
                ['STD002', '72.0', 'Good effort'],
                ['STD003', '68.5', 'Needs improvement']
            ];
            
            let csvContent = headers.join(',') + '\n';
            sampleData.forEach(row => {
                csvContent += row.join(',') + '\n';
            });
            
            // Create download link
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'exam_results_template.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
        
        // Real-time validation for marks input
        document.addEventListener('DOMContentLoaded', function() {
            const marksInput = document.getElementById('obtained_marks');
            if (marksInput) {
                const maxMarks = <?php echo $exam['total_marks']; ?>;
                marksInput.addEventListener('change', function() {
                    if (this.value > maxMarks) {
                        this.value = maxMarks;
                        alert('Marks cannot exceed ' + maxMarks);
                    }
                    if (this.value < 0) {
                        this.value = 0;
                        alert('Marks cannot be negative');
                    }
                });
                
                // Also validate on input
                marksInput.addEventListener('input', function() {
                    if (this.value > maxMarks) {
                        this.value = maxMarks;
                    }
                    if (this.value < 0) {
                        this.value = 0;
                    }
                });
            }
        });
    </script>
</body>
</html>