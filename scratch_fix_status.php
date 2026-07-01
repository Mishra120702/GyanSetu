<?php
require_once 'db_connection.php';
try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->query("SELECT id, status FROM batch_courses WHERE batch_id = 'DUMMY_7895' AND course_id = 18");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($row);

    if ($row) {
        $update = $db->prepare("UPDATE batch_courses SET status = 'pending' WHERE id = ?");
        $update->execute([$row['id']]);
        echo "Rows updated: " . $update->rowCount() . "\n";
        
        $stmt2 = $db->query("SELECT id, status FROM batch_courses WHERE id = " . $row['id']);
        print_r($stmt2->fetch(PDO::FETCH_ASSOC));
    }

} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
