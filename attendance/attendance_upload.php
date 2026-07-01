<?php
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = 'Unauthorized access';
    exit;
}

if (isset($_FILES['excel_file'])) {
    require_once '../vendor/autoload.php'; // For PhpSpreadsheet
    
    try {
        $file = $_FILES['excel_file']['tmp_name'];
        
        if (!file_exists($file)) {
            throw new Exception('File not found');
        }
        
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        if (count($rows) <= 1) {
            throw new Exception('No data found in Excel file');
        }
        
        $headers = array_map('strtolower', $rows[0]);
        $studentIdIndex = array_search('student_id', $headers);
        $dateIndex = array_search('date', $headers);
        $statusIndex = array_search('status', $headers);
        $batchIdIndex = array_search('batch_id', $headers);
        $studentNameIndex = array_search('student_name', $headers);
        $cameraStatusIndex = array_search('camera_status', $headers);
        $remarksIndex = array_search('remarks', $headers);
        
        if ($studentIdIndex === false || $dateIndex === false || $statusIndex === false) {
            throw new Exception('Required columns (student_id, date, status) not found');
        }
        
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        // Start transaction
        $db->beginTransaction();
        
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            if (empty($row[$studentIdIndex]) || empty($row[$dateIndex]) || empty($row[$statusIndex])) {
                $errorCount++;
                $errors[] = "Row $i: Missing required data";
                continue;
            }
            
            $studentId = trim($row[$studentIdIndex]);
            $date = trim($row[$dateIndex]);
            $status = trim($row[$statusIndex]);
            $batchId = ($batchIdIndex !== false && isset($row[$batchIdIndex])) ? trim($row[$batchIdIndex]) : '';
            $studentName = ($studentNameIndex !== false && isset($row[$studentNameIndex])) ? trim($row[$studentNameIndex]) : '';
            $cameraStatus = ($cameraStatusIndex !== false && isset($row[$cameraStatusIndex])) ? trim($row[$cameraStatusIndex]) : 'Off';
            $remarks = ($remarksIndex !== false && isset($row[$remarksIndex])) ? trim($row[$remarksIndex]) : '';
            
            // Validate status
            if (!in_array($status, ['Present', 'Absent'])) {
                $errorCount++;
                $errors[] = "Row $i: Invalid status '$status'. Must be 'Present' or 'Absent'";
                continue;
            }
            
            // Auto-set camera_status to 'Off' if status is not 'Present'
            if ($status !== 'Present') {
                $cameraStatus = 'Off';
            }
            
            // If student name is not provided, try to get it from students table
            if (empty($studentName)) {
                $stmt = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) as student_name FROM students WHERE student_id = ?");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                $studentName = $student ? $student['student_name'] : 'Unknown Student';
            }
            
            // If batch_id is not provided, try to get current batch from students table
            if (empty($batchId)) {
                $stmt = $db->prepare("SELECT batch_name FROM students WHERE student_id = ?");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                $batchId = $student ? $student['batch_name'] : 'UNKNOWN';
            }
            
            // Check if attendance record already exists
            $stmt = $db->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
            $stmt->execute([$studentId, $date]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing record
                $stmt = $db->prepare("UPDATE attendance 
                                     SET batch_id = ?, student_name = ?, status = ?, camera_status = ?, remarks = ?
                                     WHERE student_id = ? AND date = ?");
                $result = $stmt->execute([
                    $batchId, $studentName, $status, $cameraStatus, $remarks,
                    $studentId, $date
                ]);
            } else {
                // Insert new record
                $stmt = $db->prepare("INSERT INTO attendance 
                                     (date, batch_id, student_id, student_name, status, camera_status, remarks)
                                     VALUES (?, ?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([
                    $date, $batchId, $studentId, $studentName, $status, $cameraStatus, $remarks
                ]);
            }
            
            if ($result) {
                $successCount++;
            } else {
                $errorCount++;
                $errors[] = "Row $i: Failed to save record";
            }
        }
        
        $db->commit();
        
        if ($errorCount > 0) {
            $_SESSION['error_message'] = "Upload completed with $successCount successful records and $errorCount errors. " . implode('; ', array_slice($errors, 0, 5));
        } else {
            $_SESSION['success_message'] = "Successfully uploaded $successCount attendance records";
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Excel upload error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error uploading file: " . $e->getMessage();
    }
}
?>