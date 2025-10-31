<?php
// FILE: chat_api.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db_connect.php'; // Adjust path if necessary, assuming db_connect.php is in the same folder or '..'
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

$response = ['success' => false, 'message' => 'Invalid request'];

// Check if a user is logged in
$current_user_id = $_SESSION['user_id'] ?? 0;
if ($current_user_id == 0) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'send_message':
        $recipient_id = (int) ($_POST['recipient_id'] ?? 0);
        $message_text = trim($_POST['message_text'] ?? '');

        if ($recipient_id > 0 && !empty($message_text)) {
            $sql = "INSERT INTO messages (sender_id, recipient_id, message_text) VALUES (?, ?, ?)";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("iis", $current_user_id, $recipient_id, $message_text);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Message sent successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Database error: ' . $stmt->error];
                }
                $stmt->close();
            } else {
                 $response = ['success' => false, 'message' => 'Prepared statement failed: ' . $conn->error];
            }
        } else {
            $response = ['success' => false, 'message' => 'Missing recipient or message text'];
        }
        break;

    case 'fetch_messages':
        $other_user_id = (int) ($_GET['other_user_id'] ?? 0);
        $last_message_id = (int) ($_GET['last_id'] ?? 0); // For real-time updates

        if ($other_user_id > 0) {
            // Select messages between the current user AND the other user
            $sql = "SELECT id, sender_id, recipient_id, message_text, timestamp 
                    FROM messages 
                    WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
                    AND id > ? -- Only fetch newer messages
                    ORDER BY timestamp ASC";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("iiiii", $current_user_id, $other_user_id, $other_user_id, $current_user_id, $last_message_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $messages = [];
                while ($row = $result->fetch_assoc()) {
                    $messages[] = $row;
                }
                $stmt->close();
                $response = ['success' => true, 'messages' => $messages];
            } else {
                 $response = ['success' => false, 'message' => 'Prepared statement failed: ' . $conn->error];
            }
        } else {
            $response = ['success' => false, 'message' => 'Missing other user ID'];
        }
        break;
        
    // You can add 'mark_read' action here later
    
    default:
        $response = ['success' => false, 'message' => 'Unknown action'];
        break;
}

echo json_encode($response);
$conn->close();
?>



    // --- SECURITY CHECK ---
    // Tiyakin na admin lang ang pwedeng mag-access
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../index.html"); // Redirect kung hindi admin
        exit;
    }