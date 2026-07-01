<?php
require 'db_connection.php';

try {
    $db->beginTransaction();

    $stmt = $db->query("SELECT student_id, first_name, last_name FROM students WHERE first_name LIKE '%dixant%' OR first_name LIKE '%dikshant%'");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($students)) {
        echo "No student named Dixant found.";
        $db->rollBack();
        exit;
    }

    foreach ($students as $student) {
        $sid = $student['student_id'];
        $full_name = trim($student['first_name']) . ' ' . trim($student['last_name']);
        echo "Deleting records for: $full_name ($sid)<br>";

        try {
            $stmt = $db->prepare("DELETE FROM exam_results WHERE student_id = ?");
            $stmt->execute([$sid]);
            echo "Deleted " . $stmt->rowCount() . " rows from exam_results.<br>";
        } catch (Exception $e) {}

        try {
            $stmt = $db->prepare("DELETE FROM exam_students WHERE student_name LIKE ?");
            $stmt->execute(['%' . trim($student['first_name']) . '%']);
            echo "Deleted " . $stmt->rowCount() . " rows from exam_students.<br>";
        } catch (Exception $e) {}

        try {
            $stmt = $db->prepare("DELETE FROM attendance WHERE student_name LIKE ?");
            $stmt->execute(['%' . trim($student['first_name']) . '%']);
            echo "Deleted " . $stmt->rowCount() . " rows from attendance.<br>";
        } catch (Exception $e) {}

        try {
            $stmt = $db->prepare("DELETE FROM student_batch_history WHERE student_id = ?");
            $stmt->execute([$sid]);
            echo "Deleted " . $stmt->rowCount() . " rows from student_batch_history.<br>";
        } catch (Exception $e) {
            try {
                $stmt = $db->prepare("DELETE FROM student_batch_history WHERE student_name LIKE ?");
                $stmt->execute(['%' . trim($student['first_name']) . '%']);
                echo "Deleted " . $stmt->rowCount() . " rows from student_batch_history.<br>";
            } catch (Exception $e) {}
        }

        try {
            $stmt = $db->prepare("DELETE FROM students WHERE student_id = ?");
            $stmt->execute([$sid]);
            echo "Deleted " . $stmt->rowCount() . " rows from students.<br>";
        } catch (Exception $e) {}
    }

    $db->commit();
    echo "<b>Dixant has been completely removed from the database!</b>";

} catch (Exception $e) {
    $db->rollBack();
    echo "Error: " . $e->getMessage();
}
?>
