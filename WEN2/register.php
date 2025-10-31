<?php
// FILE: register.php
include 'db_connect.php'; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$fullname = $conn->real_escape_string($_POST['fullname'] ?? '');
$username = $conn->real_escape_string($_POST['username'] ?? '');
$email = $conn->real_escape_string($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($fullname) || empty($username) || empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Please fill all required fields.']);
    $conn->close();
    exit;
}

// Hashing the password (CRITICAL SECURITY STEP)
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
$role = 'patient';

// Check if username or email already exists
$check_sql = "SELECT id FROM users WHERE username = ? OR email = ?";
$stmt_check = $conn->prepare($check_sql);
$stmt_check->bind_param("ss", $username, $email);
$stmt_check->execute();
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Username or Email already taken.']);
    $stmt_check->close();
    $conn->close();
    exit;
}
$stmt_check->close();

// Insert the new patient
$insert_sql = "INSERT INTO users (fullname, username, email, password, role) VALUES (?, ?, ?, ?, ?)";
$stmt_insert = $conn->prepare($insert_sql);
$stmt_insert->bind_param("sssss", $fullname, $username, $email, $hashed_password, $role);

if ($stmt_insert->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful! You can now log in.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: Could not register user.']);
}

$stmt_insert->close();
$conn->close();
?>