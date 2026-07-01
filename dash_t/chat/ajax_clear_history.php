// Verify user is trainer of this conversation
$query = "SELECT 1 FROM chat_conversations 
          WHERE id = ? AND trainer_id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$conversation_id, $user_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    echo json_encode(['success' => false, 'error' => 'Only the conversation trainer can clear history']);
    exit();
}