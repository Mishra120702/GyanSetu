<?php
require_once 'db_config.php';
header('Content-Type: application/json');

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
    
    $sql = "SELECT 
                b.batch_id, 
                b.course_name, 
                b.start_date, 
                b.end_date, 
                b.num_students, 
                b.mode, 
                b.status,
                u.name as mentor_name
            FROM batches b
            LEFT JOIN users u ON b.batch_mentor_id = u.id
            WHERE b.batch_id LIKE :search OR b.course_name LIKE :search
            ORDER BY b.start_date DESC";
    
    $stmt = $conn->prepare($sql);
    $searchParam = "%$searchTerm%";
    $stmt->bindParam(':search', $searchParam);
    $stmt->execute();
    
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($batches);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?><?php
require_once 'db_connection.php';
header('Content-Type: application/json');

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
    
    $sql = "SELECT 
                b.batch_id, 
                b.course_name, 
                b.start_date, 
                b.end_date, 
                b.num_students, 
                b.mode, 
                b.status,
                u.name as mentor_name
            FROM batches b
            LEFT JOIN users u ON b.batch_mentor_id = u.id
            WHERE b.batch_id LIKE :search OR b.course_name LIKE :search
            ORDER BY b.start_date DESC";
    
    $stmt = $conn->prepare($sql);
    $searchParam = "%$searchTerm%";
    $stmt->bindParam(':search', $searchParam);
    $stmt->execute();
    
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($batches);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>