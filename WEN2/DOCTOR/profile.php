<?php
session_start();
include '../db_connect.php'; 

// ==========================================================
// 💡 IDINAGDAG: API ROUTER PARA SA MARK AS READ
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'mark_read') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        exit;
    }
    
    $doctor_id_api = $_SESSION['user_id']; // Gumamit ng ibang variable name para iwas conflict

    try {
        $stmt_mark = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        if (!$stmt_mark) throw new Exception("Prepare failed: " . $conn->error);
        $stmt_mark->bind_param("i", $doctor_id_api);
        if ($stmt_mark->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database execution error.']);
        }
        $stmt_mark->close();
    } catch (Exception $e) {
        error_log("Mark as Read Error (profile.php): " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database exception.']);
    }
    
    $conn->close();
    exit; // Itigil ang script
}
// === END API ROUTER ===


// ==========================================================
// 💡 IDINAGDAG: DB Connection Check
// ==========================================================
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed in profile.php: " . $conn->connect_error);
}

// Security Check (Dapat doctor ang naka-login)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../admin_login.php?error=" . urlencode("Access Denied. Please login as Doctor."));
    exit;
}

$doctor_id = $_SESSION['user_id'];
$doctor_name_header = $_SESSION['fullname'] ?? 'Doctor User'; // Pangalan para sa header
$doctor_data = null; 
$message = ''; 
$message_type = ''; 


// ==========================================================
// 💡 IDINAGDAG: FETCH NOTIFICATIONS PARA SA HEADER
// ==========================================================
$notifications = [];
$unread_count = 0;
try {
    $sql_notif = "(SELECT id, message, created_at, link, is_read
                  FROM notifications
                  WHERE user_id = ? AND is_read = 0
                  ORDER BY created_at DESC)
                 UNION
                 (SELECT id, message, created_at, link, is_read
                  FROM notifications
                  WHERE user_id = ? AND is_read = 1
                  ORDER BY created_at DESC)
                 LIMIT 7";
                 
    $stmt_notif = $conn->prepare($sql_notif);
    if (!$stmt_notif) throw new Exception("Prepare failed (notifications): " . $conn->error);
    $stmt_notif->bind_param("ii", $doctor_id, $doctor_id); 
    $stmt_notif->execute();
    $result_notif = $stmt_notif->get_result();

    if($result_notif) {
        while ($row_notif = $result_notif->fetch_assoc()) {
          $notifications[] = $row_notif;
          if ($row_notif['is_read'] == 0) $unread_count++;
        }
    }
    $stmt_notif->close();
} catch (Exception $e) {
     error_log("Error fetching notifications for doctor profile: " . $e->getMessage());
}
// === END NOTIFICATION FETCH ===


// ==========================================================
// 1. HANDLE FORM SUBMISSIONS FIRST
// ==========================================================
$fullname_val = ''; // Initialize para sure na defined
$username_val = '';
$email_val = '';
$specialty_val = '';

// HANDLE PERSONAL INFORMATION UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_info') {
    $new_fullname = trim($_POST['fullName'] ?? ''); 
    $new_specialty = trim($_POST['specialization'] ?? ''); 
    $new_email = trim($_POST['email'] ?? '');
    $new_username = trim($_POST['username'] ?? ''); 
    
    if (empty($new_fullname) || empty($new_username) || empty($new_email) || empty($new_specialty) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Error: Please fill in all fields correctly.';
        $message_type = 'error';
    } else {
        try {
            $check_stmt = $conn->prepare("SELECT id, email, username FROM users WHERE (email = ? OR username = ?) AND id != ?");
            if (!$check_stmt) throw new Exception("Prepare failed (check): " . $conn->error);
            $check_stmt->bind_param("ssi", $new_email, $new_username, $doctor_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                 $existing_user = $check_result->fetch_assoc();
                 if ($existing_user['email'] === $new_email) {
                    $message = 'Error: Email already in use by another account.';
                 } else {
                    $message = 'Error: Username already in use by another account.';
                 }
                $message_type = 'error';
            } else {
                $update_stmt = $conn->prepare("UPDATE users SET fullname = ?, username = ?, email = ?, specialty = ? WHERE id = ? AND role = 'doctor'");
                if (!$update_stmt) throw new Exception("Prepare failed (update): " . $conn->error);
                $update_stmt->bind_param("ssssi", $new_fullname, $new_username, $new_email, $new_specialty, $doctor_id);

                if ($update_stmt->execute()) {
                    $_SESSION['fullname'] = $new_fullname; 
                    $message = 'Success! Profile information updated successfully.';
                    $message_type = 'success';
                    // Re-assign data para ma-update agad ang display
                    $doctor_name_header = $new_fullname; // Update name sa header agad
                    $fullname_val = $new_fullname;
                    $username_val = $new_username;
                    $email_val = $new_email;
                    $specialty_val = $new_specialty;
                } else {
                    $message = 'Error: Could not update profile.';
                    $message_type = 'error';
                }
                $update_stmt->close();
            }
            $check_stmt->close();
        } catch (Exception $e) {
            $message = 'Database Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
    // Set values para ma-retain sa form kahit may error
    if($message_type === 'error'){
        $fullname_val = htmlspecialchars($new_fullname);
        $username_val = htmlspecialchars($new_username);
        $email_val = htmlspecialchars($new_email);
        $specialty_val = htmlspecialchars($new_specialty);
    }
}

// HANDLE PASSWORD CHANGE UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $old_pass = $_POST['oldPass'] ?? ''; 
    $new_pass = $_POST['newPass'] ?? '';
    $confirm_pass = $_POST['confirmPass'] ?? '';

    if (empty($old_pass) || empty($new_pass) || empty($confirm_pass)) {
        $message = 'Error: Please fill in all password fields.';
        $message_type = 'error';
    } elseif ($new_pass !== $confirm_pass) {
        $message = 'Error: New passwords do not match!';
        $message_type = 'error';
    } elseif (strlen($new_pass) < 8) { 
        $message = 'Error: New password must be at least 8 characters.';
        $message_type = 'error';
    } else {
        try {
            $select_pass_stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role = 'doctor'");
            if (!$select_pass_stmt) throw new Exception("Prepare failed (select pass): " . $conn->error);
            $select_pass_stmt->bind_param("i", $doctor_id);
            $select_pass_stmt->execute();
            $result = $select_pass_stmt->get_result();
            $user = $result->fetch_assoc();
            $select_pass_stmt->close();

            if ($user && password_verify($old_pass, $user['password'])) {
                $hashed_new_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                $update_pass_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'doctor'");
                if (!$update_pass_stmt) throw new Exception("Prepare failed (update pass): " . $conn->error);
                $update_pass_stmt->bind_param("si", $hashed_new_pass, $doctor_id);

                if ($update_pass_stmt->execute()) {
                    $message = 'Success! Password updated successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'Error: Could not update password.';
                    $message_type = 'error';
                }
                $update_pass_stmt->close();
            } else {
                $message = 'Error: Incorrect old password.';
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $message = 'Database Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// ==========================================================
// 2. FETCH DOCTOR DATA FOR DISPLAY (if not updated successfully)
// ==========================================================
if (!($message_type === 'success' && isset($_POST['action']) && $_POST['action'] === 'update_info')) {
    try {
        $stmt = $conn->prepare("SELECT fullname, username, email, specialty FROM users WHERE id = ? AND role = 'doctor'");
        if (!$stmt) throw new Exception("Prepare failed (fetch data): " . $conn->error);
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $doctor_data = $result->fetch_assoc();
            $doctor_name_header = $doctor_data['fullname'] ?? $doctor_name_header; // Update header name
            // Assign data for HTML forms only if not set by POST error handling
            if (empty($fullname_val)) $fullname_val = htmlspecialchars($doctor_data['fullname'] ?? '');
            if (empty($email_val)) $email_val = htmlspecialchars($doctor_data['email'] ?? '');
            if (empty($username_val)) $username_val = htmlspecialchars($doctor_data['username'] ?? ''); 
            if (empty($specialty_val)) $specialty_val = htmlspecialchars($doctor_data['specialty'] ?? ''); 
        } else {
            if (empty($message)) {
                 $message = 'Error: Doctor data not found. Please relog.';
                 $message_type = 'error';
            }
        }
        $stmt->close();
    } catch (Exception $e) {
        if (empty($message)) {
            $message = 'Database Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

$conn->close(); // 💡 Close connection dito
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Doctor Profile</title>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body>
  <style>
    :root {
        --primary: #1f4e79; 
        --accent: #0078FF; 
        --muted: #6b7280;
        --border-color: #e6eef6; 
        --bg: #f6f9fc; 
        --card: #ffffff; 
        --radius: 12px; 
        --container: 1100px; 
        --danger: #e11d48; /* Added for badge/logout */
        /* Status Colors for Alerts */
        --green-bg: #d1fae5;
        --green-text: #065f46; 
        --red-bg: #fee2e2;
        --red-text: #b91c1c; 
    }
    /* Global Reset & Base */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; }
    body { 
      font-family: 'Poppins', sans-serif; 
      background-color: var(--bg); 
      color: #1f2937; 
      padding-top: 66px; 
      min-height: 100vh;
    }
    
    /* === HEADER STYLES === */
    .topnav {
      background: linear-gradient(90deg, var(--primary), var(--accent));
      color: #fff;
      padding: 12px 18px;
      box-shadow: 0 6px 18px rgba(31, 78, 121, 0.12);
      position: fixed; top: 0; left: 0; width: 100%; z-index: 50; 
    }
    .nav-inner {
      max-width: var(--container);
      margin: auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 700;
      color: #fff;
    }
    .brand-mark {
      width: 30px; height: 30px;
      background: #fff;
      color: var(--primary);
      border-radius: 6px;
      display: flex; align-items: center; justify-content: center;
    }
    .brand-text { font-size: 18px; color: #fff; } 
    .nav-links {
      display: flex; align-items: center; gap: 12px;
    }
    .nav-link {
      color: rgba(255,255,255,0.9);
      text-decoration: none;
      padding: 8px 10px;
      border-radius: 8px;
      transition: 0.2s;
      font-weight: 500; 
    }
    .nav-link:hover { background: rgba(255,255,255,0.12); }
    .nav-link.active { background: rgba(255,255,255,0.25); font-weight: 600; }
    .nav-right { position: relative; display: flex; align-items: center; gap: 12px; }
    
    /* === PROFILE DROPDOWN STYLES === */
    .profile {
      position: relative;
      display: flex; align-items: center; gap: 8px;
      cursor: pointer;
      padding: 5px; 
      border-radius: 8px;
      transition: background 0.2s;
    }
    .profile:hover { background: rgba(255,255,255,0.1); }
    .avatar {
      width: 38px; height: 38px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid rgba(255,255,255,0.9);
    }
    .name { color: #fff; font-weight: 600; }
    .caret { color: #fff; font-size: 12px; transition: transform 0.25s ease; margin-left: 5px; }
    .profile-dropdown {
      position: absolute;
      right: 0;
      top: 52px; 
      width: 220px;
      background: var(--card);
      border-radius: 10px;
      box-shadow: 0 12px 30px rgba(20,30,50,0.15);
      display: none;
      flex-direction: column;
      opacity: 0;
      transform: translateY(-8px);
      transition: all 0.25s ease;
      z-index: 99;
      list-style: none; 
      padding: 5px 0; 
    }
    .profile-dropdown.show { display: flex; opacity: 1; transform: translateY(0); }
    .profile-dropdown li { list-style: none; } 
    .profile-dropdown a {
      display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #17202a; text-decoration: none; border-radius: 8px; font-weight: 500; transition: 0.2s;
    }
    .profile-dropdown a:hover { background: #f3f6fb; }
    .profile-dropdown a.active { background: #e9f2ff; font-weight: 600; color: var(--primary);} 
    .profile-dropdown a.logout { color: var(--danger); font-weight: 600; }
    .profile-dropdown a.logout:hover { background: #fee2e2; color: #b91c1c; }

    /* === 💡 NOTIFICATION BELL STYLES 💡 === */
    .notif-bell { position: relative; color: #fff; font-size: 20px; padding: 10px 12px; border-radius: 8px; cursor: pointer; transition: background 0.2s; }
    .notif-bell:hover { background: rgba(255,255,255,0.1); }
    .notif-badge {
        position: absolute;
        top: 6px; right: 8px;
        min-width: 16px; height: 16px;
        background: var(--danger); color: #fff;
        border-radius: 8px; padding: 0;
        font-size: 10px; font-weight: 600;
        display: flex; align-items: center; justify-content: center;
        border: 2px solid #fff; line-height: 1;
    }
    .notif-dropdown { position: absolute; right: 0; top: 52px; width: 360px; max-height: 420px; overflow-y: auto; background: var(--card); border-radius: 10px; box-shadow: 0 12px 30px rgba(20,30,50,0.15); display: none; flex-direction: column; opacity: 0; transform: translateY(-8px); transition: all 0.25s ease; z-index: 100; padding: 0; }
    .notif-dropdown.show { display: flex; opacity: 1; transform: translateY(0); }
    .notif-header { padding: 12px 16px; font-weight: 600; color: var(--primary); font-size: 16px; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; background: var(--card); z-index: 1; }
    .notif-list { padding: 8px; }
    .notif-item { display: block; padding: 12px; border-radius: 8px; transition: background 0.2s; text-decoration: none; color: #6b7280; margin-bottom: 5px; border-bottom: 1px solid #f0f4f9; position: relative; }
    .notif-item:last-child { border-bottom: none; margin-bottom: 0; }
    .notif-item:hover { background: #f3f6fb; }
    .notif-item.unread { background: #eef6ff; }
    .notif-item.unread::before { content: ''; position: absolute; left: 6px; top: 50%; transform: translateY(-50%); width: 6px; height: 6px; border-radius: 50%; background: var(--accent); }
    .notif-item p { color: #333; font-size: 14px; font-weight: 500; margin: 0 0 4px 0; white-space: normal; padding-left: 12px; }
    .notif-item span { font-size: 12px; color: var(--accent); font-weight: 500; padding-left: 12px; }
    .notif-empty { padding: 30px 20px; text-align: center; color: var(--muted); }
    .notif-empty i { font-size: 28px; margin-bottom: 10px; color: #a0aec0; }
    
    /* === PROFILE PAGE SPECIFIC STYLES === */
    .container { max-width: 900px; margin: 20px auto; padding: 0 15px; }
    .card { background: var(--card); border-radius: var(--radius); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .alert-message { padding: 12px; margin-bottom: 20px; border-radius: 8px; font-weight: 600; display: flex; align-items: center; gap: 8px;} /* Added display flex */
    .alert-success { background-color: var(--green-bg); color: var(--green-text); border: 1px solid #a7f3d0; }
    .alert-error { background-color: var(--red-bg); color: var(--red-text); border: 1px solid #fecaca; }
    .profile-section { margin-bottom: 28px; padding: 25px; }
    .profile-section h2 { color: var(--primary); font-size: 20px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
    .form-grid { 
        display: grid; 
        grid-template-columns: repeat(2, 1fr); 
        gap: 16px; 
        margin-bottom: 18px; 
    }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group label { font-weight: 600; color: var(--primary); font-size: 14px; }
    .form-group input[type="text"], 
    .form-group input[type="email"], 
    .form-group input[type="password"] {
      padding: 12px; border-radius: 10px; border: 1px solid var(--border-color); background: #fff; font-size: 14px; transition: 0.2s;
    }
    .form-group input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(0, 120, 255, 0.15); }
    .btn { padding: 10px 18px; border-radius: 10px; font-weight: 600; cursor: pointer; border: 0; transition: all 0.2s ease; display: inline-flex; width: auto; }
    .btn.primary { background: var(--primary); color: #fff; } 
    .btn.primary:hover { background: var(--accent); transform: scale(1.03); }
    .btn.outline { background: transparent; border: 1.5px solid var(--accent); color: var(--primary); }
    .btn.outline:hover { background: var(--accent); color: #fff; transform: scale(1.03); }
    .footer { text-align: center; margin-top: 30px; padding: 15px; color: #6b7280; font-size: 13px; }

    /* Responsive adjustments */
    @media (max-width: 900px) { 
      .form-grid { grid-template-columns: 1fr; } 
      .nav-inner { padding: 10px 15px; } 
      .container { padding: 0 10px; }
    }
    @media (max-width: 768px) { 
      .nav-links { display: none; } 
      .brand-text { display: none; } 
    }
    
  </style>

  <header class="topnav">
    <div class="nav-inner">
      <div class="brand"><span class="brand-mark">+</span><span class="brand-text">CliniCare</span></div>
      <nav class="nav-links">
        <a href="doctor.php" class="nav-link">Dashboard</a> 
        <a href="doctor_appointments.php" class="nav-link">Appointments</a> 
        <a href="doctor_chat.php" class="nav-link">Chats</a> 
      </nav>
      <div class="nav-right">
        
        <div class="notif-bell" id="notifBell">
          <i class="fa-solid fa-bell"></i>
          <?php if ($unread_count > 0): ?>
            <span class="notif-badge"><?= $unread_count; ?></span>
          <?php endif; ?>
          <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-header">Notifications</div>
            <div class="notif-list">
              <?php if (empty($notifications)): ?>
                <div class="notif-empty">
                  <i class="fa-solid fa-check-circle"></i>
                  <p>You're all caught up!</p>
                </div>
              <?php else: ?>
                <?php foreach ($notifications as $notif): 
                  $time_ago = strtotime($notif['created_at']);
                  $time_diff = time() - $time_ago;
                  $time_unit = '';
                  if ($time_diff < 60) { $time_unit = 'just now'; }
                  elseif ($time_diff < 3600) { $time_unit = floor($time_diff / 60) . 'm ago'; }
                  elseif ($time_diff < 86400) { $time_unit = floor($time_diff / 3600) . 'h ago'; }
                  else { $time_unit = floor($time_diff / 86400) . 'd ago'; }
                  $link = !empty($notif['link']) ? $notif['link'] : 'doctor_appointments.php'; 
                  $is_unread_class = $notif['is_read'] == 0 ? 'unread' : '';
                ?>
                  <a href="<?= htmlspecialchars($link); ?>" class="notif-item <?= $is_unread_class; ?>">
                    <p><?= htmlspecialchars($notif['message']); ?></p>
                    <span><i class="fa-solid fa-clock" style="margin-right: 4px;"></i><?= $time_unit; ?></span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
        
        <div class="profile" id="profileToggle"> 
          <img src="../PICTURES/uf.jpg" alt="Doctor" class="avatar"> 
          <span class="name">Dr. <?= htmlspecialchars($doctor_name_header) ?></span> <i class="fa-solid fa-chevron-down caret"></i>
          <ul class="profile-dropdown" id="profileDropdown"> 
            
            <li><a href="profile.php" class="active"><i class="fa-solid fa-user-pen"></i> Edit Profile</a></li> 
            
            <li><a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li> 
          </ul>
        </div>  
      </div>
    </div>
  </header>

  <main class="container">
    
    <?php if (!empty($message)): ?>
        <div class="alert-message <?php echo ($message_type === 'success') ? 'alert-success' : 'alert-error'; ?>">
            <?php 
              $icon = ($message_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation';
              echo "<i class='fa-solid " . $icon . "' style='margin-right: 8px;'></i>";
              echo htmlspecialchars($message); 
            ?>
        </div>
    <?php endif; ?>


    <section class="card profile-section">
      <h2><i class="fa-solid fa-user-pen"></i> Edit Personal Information</h2>

      <form id="infoForm" method="POST" action="profile.php"> 
        <input type="hidden" name="action" value="update_info">
        
        <div class="form-grid"> 
            <div class="form-group">
                <label for="fullName">Full Name</label>
                <input type="text" id="fullName" name="fullName" value="<?= $fullname_val ?>" required>
            </div>
            <div class="form-group">
                <label for="specialization">Specialization</label>
                <input type="text" id="specialization" name="specialization" value="<?= $specialty_val ?>" placeholder="e.g., Cardiology" required> 
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= $email_val ?>" required>
            </div>
            <div class="form-group">
                <label for="username">Username (for login)</label> 
                <input type="text" id="username" name="username" value="<?= $username_val ?>" placeholder="Used for login" required>
            </div>
        </div>

        <button type="submit" class="btn primary">Save Changes</button>
      </form>
    </section>

    <section class="card profile-section">
      <h2><i class="fa-solid fa-lock"></i> Change Password</h2>

      <form id="passwordForm" method="POST" action="profile.php"> 
        <input type="hidden" name="action" value="change_password">
        
        <div class="form-grid">
            <div class="form-group">
                <label for="oldPass">Old Password</label>
                <input type="password" id="oldPass" name="oldPass" placeholder="Enter current password" required>
            </div>
            <div class="form-group">
                <label for="newPass">New Password</label>
                <input type="password" id="newPass" name="newPass" placeholder="Enter new password (min. 8 chars)" required>
            </div>
            <div class="form-group">
                <label for="confirmPass">Confirm New Password</label>
                <input type="password" id="confirmPass" name="confirmPass" placeholder="Confirm new password" required>
            </div>
        </div>

        <button type="submit" class="btn outline">Update Password</button>
      </form>
    </section>
  </main>

  <footer class="footer">
    <p>© <?= date('Y') ?> CliniCare | Doctor Profile</p>
  </footer>

  <script> 
    // === 💡 IDINAGDAG: Notification Bell Logic & Combined Dropdown Logic 💡 ===
    document.addEventListener("DOMContentLoaded", () => {
        
        // --- PART A: HEADER/DROPDOWN LOGIC ---
        const profile = document.getElementById("profileToggle");
        const dropdown = document.getElementById("profileDropdown");
        const caret = profile ? profile.querySelector(".caret") : null;
        const notifBell = document.getElementById("notifBell");
        const notifDropdown = document.getElementById("notifDropdown");
        const notifBadge = notifBell ? notifBell.querySelector(".notif-badge") : null;
        const currentUserId = <?= $doctor_id; ?>; // Para magamit sa fetch

        if (profile && dropdown && caret) {
            profile.addEventListener("click", (e) => {
                e.stopPropagation();
                dropdown.classList.toggle("show");
                caret.style.transform = dropdown.classList.contains("show") ? "rotate(180deg)" : "rotate(0deg)";
                // Isara ang notif kung bukas
                if (notifDropdown && notifDropdown.classList.contains("show")) {
                    notifDropdown.classList.remove("show");
                }
            });
        }

        if (notifBell && notifDropdown) {
            notifBell.addEventListener("click", (e) => {
                e.stopPropagation();
                notifDropdown.classList.toggle("show");
                // Isara ang profile kung bukas
                if (dropdown && dropdown.classList.contains("show")) {
                    dropdown.classList.remove("show");
                    if (caret) caret.style.transform = "rotate(0deg)";
                }
                
                // === Mark as Read Logic ===
                if (notifDropdown.classList.contains("show") && notifBadge && notifBadge.style.display !== 'none') {
                    
                    fetch('profile.php?action=mark_read', { // 💡 Target ay profile.php na
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                         },
                        body: JSON.stringify({ user_id: currentUserId }) 
                    })
                    .then(response => {
                        if (!response.ok) throw new Error(`Network response error: ${response.statusText}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            console.log("Notifications marked as read.");
                            if (notifBadge) notifBadge.style.display = 'none'; 
                            document.querySelectorAll('.notif-item.unread').forEach(item => {
                                item.classList.remove('unread');
                            });
                        } else {
                            console.error("Failed to mark notifications as read:", data.error || 'Unknown error');
                        }
                    })
                    .catch(err => {
                        console.error("Error in mark_read request:", err);
                    });
                }
            });
        }

        document.addEventListener("click", (e) => {
            // Close profile
            if (profile && dropdown && !profile.contains(e.target) && dropdown.classList.contains("show")) {
                dropdown.classList.remove("show");
                if (caret) caret.style.transform = "rotate(0deg)";
            }
            // Close notification
            if (notifBell && notifDropdown && !notifBell.contains(e.target) && !notifDropdown.contains(e.target) && notifDropdown.classList.contains("show")) {
                notifDropdown.classList.remove("show");
            }
        });

        // --- PART B: PROFILE PAGE SPECIFIC JS (Password Validation, Error Display, etc.) ---
        const passwordForm = document.getElementById('passwordForm');
        if (passwordForm) {
            passwordForm.addEventListener('submit', e => {
              const newPass = document.getElementById('newPass').value;
              const confirmPass = document.getElementById('confirmPass').value;
              clearFormError('passwordForm'); 

              if (newPass !== confirmPass) {
                e.preventDefault(); 
                displayFormError('New passwords do not match!', 'passwordForm');
                return;
              }
              if (newPass.length < 8) { 
                e.preventDefault();
                displayFormError('New password must be at least 8 characters.', 'passwordForm');
                return;
              }
            });
        }

        // --- Clear URL parameters after showing messages ---
       if (window.history.replaceState) {
            const url = new URL(window.location.href);
            // 💡 AYOS: Check kung may message div na may laman
            const alertMsg = document.querySelector('.alert-message');
            if (alertMsg && alertMsg.textContent.trim() !== '') {
                 // Remove GET parameters if message was shown
                 window.history.replaceState({ path: url.pathname }, '', url.pathname);
            }
       }
       
        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const alertMsg = document.querySelector('.alert-message');
            if (alertMsg) {
                alertMsg.style.transition = 'opacity 0.5s ease';
                alertMsg.style.opacity = '0';
                setTimeout(() => alertMsg.style.display = 'none', 500);
            }
        }, 5000); // 5 seconds
    });

    // --- Helper Functions for Form Errors (Galing sa luma mong script) ---
    function displayFormError(message, formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        let errorDiv = form.querySelector('.alert-message.alert-error');
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.className = 'alert-message alert-error'; 
            const firstFormElement = form.querySelector('.form-grid') || form.firstElementChild; 
             if (firstFormElement) {
                form.insertBefore(errorDiv, firstFormElement);
             } else {
                 form.prepend(errorDiv);
             }
        }
        errorDiv.innerHTML = `<i class='fa-solid fa-circle-exclamation' style='margin-right: 8px;'></i> ${message}`;
        errorDiv.style.display = 'flex'; // Use flex para sa icon alignment
        errorDiv.style.opacity = '1'; // Ensure visible if hidden before
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function clearFormError(formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        const errorDiv = form.querySelector('.alert-message.alert-error');
        if (errorDiv) {
            errorDiv.style.display = 'none'; 
            errorDiv.innerHTML = ''; 
        }
    }
  </script>
</body>
</html>