<?php
session_start();
// === FIX: Use direct path since this file is in the root ===
include 'db_connect.php'; 

header('Content-Type: application/json');

// --- Security Checks ---
// 1. Check if logged in
if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'error' => 'Not authenticated']);
  exit;
}

// 2. Get user ID from SESSION (safer)
$user_id_from_session = $_SESSION['user_id'];

// 3. Get JSON data from the request body
$data = json_decode(file_get_contents('php://input'), true);

// 4. Get the ID sent from JavaScript
$user_id_from_body = $data['user_id'] ?? $data['patient_id'] ?? null; // Accept either key

// 5. Security: Ensure the ID sent matches the logged-in user's ID
if ($user_id_from_session != $user_id_from_body) {
  error_log("Mark Read Mismatch: Session ID=" . $user_id_from_session . ", Body ID=" . $user_id_from_body); // Log for debugging
  echo json_encode(['success' => false, 'error' => 'Unauthorized ID mismatch']);
  exit;
}

// --- Update Notifications ---
// SQL to update all unread notifications for this user (using the verified session ID)
$sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Mark Read Prepare Error: " . $conn->error); // Log error
    echo json_encode(['success' => false, 'error' => 'Database prepare failed']);
    exit;
}

// Bind the ID from the session
$stmt->bind_param("i", $user_id_from_session); 

if ($stmt->execute()) {
  // Check if any rows were actually updated
  $affected_rows = $stmt->affected_rows;
  if ($affected_rows >= 0) { // Execute was successful, even if 0 rows were updated
        echo json_encode(['success' => true, 'updated_count' => $affected_rows]);
  } else {
       error_log("Mark Read Execute Error: " . $stmt->error); // Log error
       echo json_encode(['success' => false, 'error' => 'Failed to update notifications status']);
  }
} else {
  error_log("Mark Read Execute Error: " . $stmt->error); // Log error
  echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();

?>

