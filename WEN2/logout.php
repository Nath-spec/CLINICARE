<?php
// FILE: logout.php (Nasa Root Folder)

session_start();

// Kunin ang role bago i-clear ang session
$role = $_SESSION['role'] ?? null;

// I-set 'yung tamang redirect page base sa role
if ($role === 'patient') {
    // Kung patient, dito siya pupunta
    $redirect_page = 'index.html'; // O login.php kung meron kang hiwalay na patient login
} else if ($role === 'doctor' || $role === 'admin') {
    // Kung doctor o admin, dito sila pupunta
    $redirect_page = 'admin_login.php';
} else {
    // Kung walang role o iba pa, default sa index
    $redirect_page = 'index.html';
}

// ===================================
// Standard Logout Procedure
// ===================================

// 1. Unset all session variables
$_SESSION = array();

// 2. Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the session
session_destroy();

// ===================================
// Redirect
// ===================================
header("Location: " . $redirect_page);
exit;
?>