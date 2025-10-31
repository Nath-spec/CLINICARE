<?php
// FILE: login.php
session_start();
include 'db_connect.php'; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// 🔹 1. Get Inputs
$input_user = $conn->real_escape_string($_POST['username'] ?? '');
$password   = $_POST['password'] ?? '';

if (empty($input_user) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Please enter your username and password.']);
    exit;
}

// 🔹 2. Find User by username or email
$stmt = $conn->prepare("SELECT id, fullname, username, email, password, role FROM users WHERE email = ? OR username = ?");
$stmt->bind_param("ss", $input_user, $input_user);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    $hashed_input = hash('sha256', $password);

    // ✅ 3. Check password (works for SHA-256 or password_hash)
    $is_valid_password = false;

    // Case 1: password_hash()
    if (password_verify($password, $user['password'])) {
        $is_valid_password = true;
    }
    // Case 2: SHA256 hashed
    elseif ($hashed_input === $user['password']) {
        $is_valid_password = true;
    }
    // Case 3: Plain text (temporary)
    elseif ($password === $user['password']) {
        $is_valid_password = true;
    }

    if ($is_valid_password) {
        // ✅ Store session data
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];

        echo json_encode([
            'success'  => true,
            'role'     => $user['role'],
            'message'  => 'Login successful!',
            'fullname' => $user['fullname']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
}

$stmt->close();
$conn->close();
?>
