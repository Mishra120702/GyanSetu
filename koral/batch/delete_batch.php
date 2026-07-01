<?php
// delete_batch.php

// Database connection
require_once '../db_connection.php'; // Assuming you have a separate file for DB connection
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
// Check if batch ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Batch ID is required for deletion.";
    header("Location: ../batch/batch_list.php");
    exit();
}

$batch_id = $_GET['id'];

try {
    // Begin transaction
    $db->beginTransaction();

    // Check if there are students enrolled in this batch
    $stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE batch_name = ?");
    $stmt->execute([$batch_id]);
    $student_count = $stmt->fetchColumn();

    if ($student_count > 0) {
        throw new Exception("Cannot delete batch - there are students enrolled in this batch.");
    }

    // Check if there are attendance records for this batch
    $stmt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $attendance_count = $stmt->fetchColumn();

    if ($attendance_count > 0) {
        throw new Exception("Cannot delete batch - there are attendance records for this batch.");
    }

    // Check if there are scheduled classes for this batch
    $stmt = $db->prepare("SELECT COUNT(*) FROM schedule WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $schedule_count = $stmt->fetchColumn();

    if ($schedule_count > 0) {
        throw new Exception("Cannot delete batch - there are scheduled classes for this batch.");
    }

    // If no dependencies, proceed with deletion
    $stmt = $db->prepare("DELETE FROM batches WHERE batch_id = ?");
    $stmt->execute([$batch_id]);

    // Commit transaction
    $db->commit();

    $_SESSION['success'] = "Batch deleted successfully.";
    
} catch (Exception $e) {
    // Roll back transaction if something failed
    $db->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

// Redirect back to batch list
header("Location: ../batch/batch_list.php");
exit();