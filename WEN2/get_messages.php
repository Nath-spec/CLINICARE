<?php
// FILE: get_messages.php
// TANGGALIN ang error_reporting para hindi makasira sa JSON output
include 'db_connect.php'; 

if (session_status() === PHP_SESSION_NONE) session_start();

// Check if the current user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); 
    echo json_encode(['error' => 'Not authenticated. Please login.']);
    exit;
}

if (!isset($_GET['other_id'])) {
    http_response_code(400); 
    echo json_encode(['error' => 'Recipient ID is missing (other_id).']);
    exit;
}

$user_id = $_SESSION['user_id'];
$other_id = $_GET['other_id'];

// CRITICAL FIX: I-SELECT ang column na 'message' (hindi message_text)
$stmt = $conn->prepare("
    SELECT sender_id, message, created_at 
    FROM messages 
    WHERE (sender_id = ? AND receiver_id = ?) 
       OR (sender_id = ? AND receiver_id = ?)
    ORDER BY created_at ASC
");

$stmt->bind_param("iiii", $user_id, $other_id, $other_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}

$stmt->close();
header('Content-Type: application/json');
echo json_encode($messages);
?>