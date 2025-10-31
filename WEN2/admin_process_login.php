<?php
session_start();
include 'db_connect.php'; // 👈 Siguraduhin na tama ang path na 'to

// ==========================================================
// === 🚨 BAGONG CHECK: Titingnan natin kung gumagana si $conn ===
// ==========================================================
if (!isset($conn) || $conn->connect_error) {
    // Kung $conn is not set OR may connection error
    $error_msg = "Database connection failed. Please check 'db_connect.php'.";
    if (isset($conn)) {
        $error_msg .= " Error: " . $conn->connect_error;
    }
    error_log($error_msg); // I-log ang error para makita mo
    header("Location: admin_login.php?error=" . urlencode("A critical server error occurred."));
    exit;
}

// Kung naka-login na, i-redirect na base sa role
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    
    // ===========================================
    // === 💡 AYOS #1: Pinalitan ng MALAKING titik ===
    // ===========================================
    if ($_SESSION['role'] === 'admin') {
        header("Location: ADMIN/admin.php");
        exit;
    } elseif ($_SESSION['role'] === 'doctor') {
        header("Location: DOCTOR/doctor.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        header("Location: admin_login.php?error=" . urlencode("Username and password are required."));
        exit;
    }

    try {
        // ========================================
        // === 💡 AYOS #2: Tinanggal 'yung comma (,) ===
        // ========================================
        $sql = "SELECT id, fullname, password, role 
                FROM users 
                WHERE (username = ? OR email = ?) 
                AND (role = 'admin' OR role = 'doctor')";
        
        $stmt = $conn->prepare($sql);

        // ===============================================================
        // === 🚨 BAGONG CHECK: Dito natin sasaluhin 'yung error mo
        // ===============================================================
        if ($stmt === false) {
            // Kung pumalya ang prepare(), itapon ang error
            throw new Exception("Database prepare() failed: " . $conn->error);
        }

        // Dito na 'yung line 42 mo (o malapit diyan)
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                // Password is correct
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['role'] = $user['role'];
                // Inalis na natin 'yung profile_picture session, tama na 'to
                session_regenerate_id(true);

                if ($user['role'] === 'admin') {
                    header("Location: ADMIN/admin.php");
                } elseif ($user['role'] === 'doctor') {
                    header("Location: DOCTOR/doctor.php");
                } else {
                    header("Location: admin_login.php?error=" . urlencode("Invalid user role."));
                }
                exit;

            } else {
                // Incorrect password
                header("Location: admin_login.php?error=" . urlencode("Invalid username or password."));
                exit;
            }
        } else {
            // User not found
            header("Location: admin_login.php?error=" . urlencode("Invalid username or password."));
            exit;
        }
        $stmt->close();

    } catch (Exception $e) {
        // Log the actual error sa server
        error_log("Admin/Doctor Login Error: " . $e->getMessage());
        // Ipakita sa user ang generic message
        header("Location: admin_login.php?error=" . urlencode("An error occurred. Please try again."));
        exit;
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }

} else {
    // Redirect back if accessed directly
    header("Location: admin_login.php");
    exit;
}
?>