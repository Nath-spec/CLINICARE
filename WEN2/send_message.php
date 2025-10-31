<?php
// FILE: send_message.php
// Ilagay ito sa root folder (kasama ng db_connect.php)
error_reporting(0); // Itago ang errors sa production
ini_set('display_errors', 0);

include 'db_connect.php'; 

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

// Kunin ang pangalan ng sender galing sa session
$user_id = (int)$_SESSION['user_id'];
$sender_name = $_SESSION['fullname'] ?? 'Someone'; 

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (empty($data['receiver_id']) || !isset($data['message'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing message or receiver ID.']);
    exit;
}

$receiver_id = (int)$data['receiver_id']; 
$message_text = trim($data['message']);

if (empty($message_text)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
    exit;
}

// Kunin ang role ng receiver para sa tamang link
$receiver_role = '';
try {
    $stmt_role = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt_role->bind_param("i", $receiver_id);
    $stmt_role->execute();
    $role_result = $stmt_role->get_result();
    if ($role_row = $role_result->fetch_assoc()) {
        $receiver_role = $role_row['role'];
    }
    $stmt_role->close();
} catch (Exception $e) {
    // Ituloy lang, baka hindi gumana ang role-based link
    error_log("Error fetching receiver role: " . $e->getMessage()); // Log error
}


// Gumamit ng transaction para sigurado
try {
    $conn->begin_transaction();

    // 1. I-insert ang message sa 'messages' table
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
    if(!$stmt) throw new Exception("Prepare (message) failed: " . $conn->error);
    
    $stmt->bind_param("iis", $user_id, $receiver_id, $message_text); 
    $stmt->execute();

    // Check kung pumasok bago mag-proceed
    if ($stmt->affected_rows > 0) {
        $stmt->close();

        // 2. I-insert ang notification sa 'notifications' table
        $notif_message = "You have a new message from " . $sender_name;
        
        // Ilagay ang tamang link base sa role ng receiver
        $notif_link = '#'; // Default link
        if ($receiver_role === 'patient') {
            // Link para kay patient: chat.php?doctor_id=<sender_id> (doctor ang sender)
            $notif_link = 'chat.php?doctor_id=' . $user_id; 
        } else if ($receiver_role === 'doctor') {
             // Link para kay doctor: doctor_chat.php?patient_id=<sender_id> (patient ang sender)
            $notif_link = 'doctor_chat.php?patient_id=' . $user_id;
        }

        $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        if(!$stmt_notif) throw new Exception("Prepare (notification) failed: " . $conn->error);

        $stmt_notif->bind_param("iss", $receiver_id, $notif_message, $notif_link);
        $stmt_notif->execute();
        $stmt_notif->close();

        // I-commit lahat ng changes kung okay
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Message sent.']);

    } else {
        throw new Exception("Message insert failed (affected_rows = 0).");
    }

} catch (Exception $e) {
     $conn->rollback(); // I-undo ang lahat kung may error
     error_log("Send message transaction failed: " . $e->getMessage()); // Log the error
     http_response_code(500);
     echo json_encode(['success' => false, 'error' => 'Failed to send message or notification.']); // Generic error message
}

$conn->close();
?>

