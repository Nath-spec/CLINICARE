<?php
session_start();
include '../db_connect.php'; // Tiyakin na tama ang path papunta sa db_connect.php

$message = '';
$message_type = ''; // 'success' or 'error'

// --- FORM PROCESSING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Kunin ang data mula sa form
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $specialty = trim($_POST['specialty'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = 'doctor'; // Hard-coded dahil "Add Doctor" form ito

    // 2. Validation
    if (empty($fullname) || empty($username) || empty($email) || empty($specialty) || empty($password)) {
        $message = 'Error: Please fill in all required fields.';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Error: Invalid email format.';
        $message_type = 'error';
    } 
    // --- BAGONG VALIDATION CHECK ---
    elseif (strlen($password) !== 8) { 
        $message = 'Error: Password must be exactly 8 characters long.';
        $message_type = 'error';
    } 
    // --- END NG BAGONG VALIDATION ---
    else {
        try {
            // 3. Check kung may existing user na (username or email)
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check_stmt->bind_param("ss", $username, $email);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $message = 'Error: Username or Email already exists.';
                $message_type = 'error';
            } else {
                // 4. Securely hash the password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // 5. Insert sa database
                $insert_stmt = $conn->prepare("INSERT INTO users (fullname, username, email, specialty, password, role) VALUES (?, ?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("ssssss", $fullname, $username, $email, $specialty, $hashed_password, $role);

                if ($insert_stmt->execute()) {
                    $message = "Success! Doctor {$fullname} ({$specialty}) account has been created.";
                    $message_type = 'success';
                } else {
                    $message = 'Error: Could not create account. Please try again.';
                    $message_type = 'error';
                }
                $insert_stmt->close();
            }
            $check_stmt->close();

        } catch (Exception $e) {
            $message = 'Database Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Create Doctor Account</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
    /* --- Add Doctor CSS (UPDATED) --- */
/* Root Variables */
:root {
    --primary: #1f4e79; /* Dark Blue */
    --secondary: #2a6fb0; /* Lighter Blue */
    --accent: #00b4d8; /* Teal/Sky Blue */
    --background: #f5f8ff; /* Light Blue/Gray Background */
    --card-bg: white;
    --text-color: #333;
    --muted: #6b7280;
    --border: #e5e7eb;
    --shadow-md: 0 10px 25px rgba(0,0,0,0.1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

body {
    background: var(--background);
    color: var(--text-color);
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 20px;
}

/* Form Container */
.form-container {
    background: var(--card-bg);
    padding: 40px;
    border-radius: 15px; /* Mas rounded */
    width: 100%;
    max-width: 480px; /* Nilakihan ko konti */
    box-shadow: var(--shadow-md);
    text-align: center;
}

h2 {
    color: var(--primary);
    margin-bottom: 30px; /* Mas malaking margin */
    font-weight: 700;
    font-size: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

.form-group {
    text-align: left;
    margin-bottom: 20px; /* Mas malaking margin */
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--primary);
    font-size: 1rem;
}

.form-group input {
    width: 100%;
    padding: 14px; /* Mas malaking padding */
    border: 1px solid var(--border);
    border-radius: 10px; /* Mas rounded */
    font-size: 1rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group input:focus {
    border-color: var(--secondary);
    box-shadow: 0 0 0 3px rgba(42, 111, 176, 0.2);
    outline: none;
}

/* Buttons */
.btn {
    width: 100%;
    padding: 14px;
    font-size: 1.05rem;
    font-weight: 700;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    margin-top: 15px; /* Mas malaking space sa taas */
    transition: background-color 0.3s, transform 0.1s;
    display: inline-block;
    text-align: center;
    text-decoration: none;
}

.btn.primary {
    background: var(--primary);
    color: white;
}

.btn.primary:hover {
    background: var(--secondary);
    transform: translateY(-1px); /* Little lift effect */
}
.btn.primary:active {
    transform: translateY(0);
}

.btn.secondary {
    background: var(--background);
    color: var(--text-color);
    border: 1px solid var(--border);
}

.btn.secondary:hover {
    background: #e9efff;
}

/* Alert Messages (Cleaned up feedback) */
.alert-message {
    margin-bottom: 20px; /* Mas malaking space sa ibaba */
    padding: 15px;
    border-radius: 8px;
    font-weight: 600;
    text-align: left;
    display: flex; /* Para mas madaling i-align ang icon */
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: #dcfce7; /* Light Green */
    color: #15803d; /* Dark Green */
    border: 1px solid #4ade80;
}

.alert-error {
    background: #fee2e2; /* Light Red */
    color: #b91c1c; /* Dark Red */
    border: 1px solid #f87171;
}
</style>
</head>
<body>

<div class="form-container">
    <h2><i class="fa-solid fa-user-doctor" style="color:var(--accent)"></i> Create Doctor Account</h2>
    
    <form id="addDoctorForm" method="POST" action="add_doctor.php">
        
        <div class="form-group">
            <label for="docFullname">Full Name</label>
            <input type="text" name="fullname" id="docFullname" placeholder="Dr. John Smith" required>
        </div>

        <div class="form-group">
            <label for="docUsername">Username (Login)</label>
            <input type="text" name="username" id="docUsername" placeholder="john.smith" required>
        </div>

        <div class="form-group">
            <label for="docEmail">Email Address</label>
            <input type="email" name="email" id="docEmail" placeholder="john.smith@clinic.com" required>
        </div>

        <div class="form-group">
            <label for="docSpecialty">Specialty</label>
            <input type="text" name="specialty" id="docSpecialty" placeholder="Cardiology / Pediatrics" required>
        </div>

        <div class="form-group">
            <label for="docPassword">Initial Password</label>
            <input type="password" name="password" id="docPassword" placeholder="Set default password (exactly 8 chars)" required> </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert-message <?php echo ($message_type === 'success') ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn primary">
            <i class="fa-solid fa-plus-circle" style="margin-right:8px;"></i> Create Doctor
        </button>
        
    </form>
    
    <a href="admin.php" class="btn secondary" style="margin-top: 10px;">
        Cancel
    </a>
</div>

</body>
</html>