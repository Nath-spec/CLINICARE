<?php
session_start();
include '../db_connect.php'; // Make sure this path is correct

// Set content type to JSON
header('Content-Type: application/json');

// Get the POST data sent from JavaScript
$data = json_decode(file_get_contents('php://input'), true);

// Basic security check
// Check if user is logged in AND the patient_id from the session
// matches the one sent in the request.
if (!isset($_SESSION['user_id']) || 
    $_SESSION['role'] !== 'patient' || 
    !isset($data['patient_id']) ||
    $_SESSION['user_id'] != $data['patient_id']) {
  
  // Send error response
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}

$patient_id = $_SESSION['user_id'];

// SQL to update all unread notifications for this patient
$sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patient_id);

if ($stmt->execute()) {
  // Send success response
  echo json_encode(['success' => true]);
} else {
  // Send error response
  echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();

?>
