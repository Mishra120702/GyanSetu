<?php
session_start();
require_once '../db_connection.php';

function checkWeeklyFeedbackStatus($db, $student_id) {
    // Get student information
    $student_query = $db->prepare("
        SELECT s.student_id, s.batch_name 
        FROM students s 
        WHERE s.user_id = :user_id
    ");
    $student_query->execute([':user_id' => $student_id]);
    $student = $student_query->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        return ['required' => false, 'message' => 'Student not found'];
    }
    
    // Get current week's start date (Saturday)
    $today = new DateTime();
    $dayOfWeek = $today->format('w'); // 0=Sunday, 6=Saturday
    
    // If today is Saturday, use today. Otherwise, get last Saturday
    if ($dayOfWeek == 6) {
        $weekStartDate = $today->format('Y-m-d');
    } else {
        $daysSinceSaturday = ($dayOfWeek + 1) % 7;
        $weekStartDate = $today->modify("-$daysSinceSaturday days")->format('Y-m-d');
    }
    
    // Check if feedback already submitted for this week
    $feedback_check = $db->prepare("
        SELECT id FROM weekly_feedback 
        WHERE student_id = :student_id AND week_start_date = :week_start_date
    ");
    $feedback_check->execute([
        ':student_id' => $student['student_id'],
        ':week_start_date' => $weekStartDate
    ]);
    
    $existing_feedback = $feedback_check->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_feedback) {
        return ['required' => false, 'message' => 'Feedback already submitted this week'];
    }
    
    // Check if it's Saturday or if student hasn't submitted for previous weeks
    if ($dayOfWeek == 6) {
        // It's Saturday - compulsory to fill
        return [
            'required' => true,
            'message' => 'Weekly feedback is required every Saturday. Please submit your feedback.',
            'week_start_date' => $weekStartDate
        ];
    } else {
        // Check if there's any pending feedback from previous weeks
        $pending_check = $db->prepare("
            SELECT week_start_date FROM weekly_feedback 
            WHERE student_id = :student_id 
            ORDER BY week_start_date DESC 
            LIMIT 1
        ");
        $pending_check->execute([':student_id' => $student['student_id']]);
        $last_feedback = $pending_check->fetch(PDO::FETCH_ASSOC);
        
        if ($last_feedback) {
            $last_feedback_date = new DateTime($last_feedback['week_start_date']);
            $last_feedback_date->modify('+7 days'); // Add 7 days to get next expected Saturday
            
            if ($last_feedback_date->format('Y-m-d') <= $weekStartDate) {
                // There's a pending weekly feedback
                return [
                    'required' => true,
                    'message' => 'You have pending weekly feedback. Please submit it to continue.',
                    'week_start_date' => $weekStartDate
                ];
            }
        } else {
            // No feedback ever submitted - require for current week
            return [
                'required' => true,
                'message' => 'Weekly feedback is required. Please submit your feedback.',
                'week_start_date' => $weekStartDate
            ];
        }
    }
    
    return ['required' => false, 'message' => 'No feedback required at this time'];
}

// If called directly, return JSON response
if (isset($_SESSION['user_id'])) {
    $result = checkWeeklyFeedbackStatus($db, $_SESSION['user_id']);
    header('Content-Type: application/json');
    echo json_encode($result);
}
?>