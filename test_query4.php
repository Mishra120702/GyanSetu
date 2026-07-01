<?php
require_once "db_connection.php";

echo "Trainer 2 courses via bc.trainer_id:\n";
$stmt = $db->prepare("SELECT DISTINCT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id WHERE bc.trainer_id = 2");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "Trainer 2 courses via b.batch_mentor_id:\n";
$stmt = $db->prepare("SELECT DISTINCT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id JOIN batches b ON bc.batch_id = b.batch_id WHERE b.batch_mentor_id = 2");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// Find Aryan's ID
$stmt = $db->query("SELECT * FROM trainers WHERE name LIKE '%Aryan%'");
$aryan = $stmt->fetch(PDO::FETCH_ASSOC);
if ($aryan) {
    echo "Aryan ID is: " . $aryan['id'] . "\n";
    echo "Aryan courses via bc.trainer_id:\n";
    $stmt = $db->prepare("SELECT DISTINCT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id WHERE bc.trainer_id = ?");
    $stmt->execute([$aryan['id']]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    echo "Aryan courses via b.batch_mentor_id:\n";
    $stmt = $db->prepare("SELECT DISTINCT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id JOIN batches b ON bc.batch_id = b.batch_id WHERE b.batch_mentor_id = ?");
    $stmt->execute([$aryan['id']]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
}
?>
