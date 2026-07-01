// Get conversation type
$query = "SELECT conversation_type FROM chat_conversations WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$conversation_id]);
$conversation = $stmt->fetch(PDO::FETCH_ASSOC);

$participants = [];

if ($conversation['conversation_type'] === 'trainer_admin') {
    // For individual conversations, get both participants
    $query = "SELECT u.id, u.name, 'Admin' as role
              FROM users u
              JOIN chat_participants p ON u.id = p.user_id
              JOIN chat_conversations c ON p.conversation_id = c.id
              WHERE p.conversation_id = ? AND c.admin_id = u.id
              
              UNION
              
              SELECT u.id, u.name as name, 'Trainer' as role
              FROM users u
              JOIN chat_participants p ON u.id = p.user_id
              WHERE p.conversation_id = ? AND p.user_id != (SELECT admin_id FROM chat_conversations WHERE id = ?)";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id, $conversation_id, $conversation_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as $row) {
        $nameParts = explode(' ', $row['name']);
        $initials = strtoupper(
            substr($nameParts[0], 0, 1) . 
            (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''
        ));
        
        $participants[] = [
            'name' => $row['name'],
            'role' => $row['role'],
            'initials' => $initials
        ];
    }
} else {
    // For batch conversations, get all active participants
    $query = "SELECT u.id, 
                     CASE 
                         WHEN u.id = c.trainer_id THEN u.name
                         WHEN u.role = 'admin' THEN u.name
                         ELSE CONCAT(s.first_name, ' ', s.last_name)
                     END as name,
                     CASE 
                         WHEN u.id = c.trainer_id THEN 'Trainer'
                         WHEN u.role = 'admin' THEN 'Admin'
                         ELSE 'Student'
                     END as role
              FROM users u
              LEFT JOIN students s ON u.id = s.user_id
              JOIN chat_participants p ON u.id = p.user_id
              JOIN chat_conversations c ON p.conversation_id = c.id
              WHERE p.conversation_id = ? AND p.is_active = TRUE
              ORDER BY role DESC, name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as $row) {
        $nameParts = explode(' ', $row['name']);
        $initials = strtoupper(
            substr($nameParts[0], 0, 1) . 
            (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : '')
        );
        
        $participants[] = [
            'name' => $row['name'],
            'role' => $row['role'],
            'initials' => $initials
        ];
    }
}