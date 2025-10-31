<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include '../db_connect.php';

// ==========================================================
// 💡 IDINAGDAG: API ROUTER PARA SA MARK AS READ
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'mark_read') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        exit;
    }
    
    $patient_id_api = $_SESSION['user_id']; 

    try {
        // Check connection specifically for API
        if (!isset($conn) || $conn->ping() === false) {
             // Reconnect if necessary or handle error
             include '../db_connect.php'; // Try including again
             if (!isset($conn) || $conn->ping() === false) {
                 throw new Exception("DB connection failed in API router.");
             }
        }
        $stmt_mark = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        if (!$stmt_mark) throw new Exception("Prepare failed: " . $conn->error);
        $stmt_mark->bind_param("i", $patient_id_api);
        if ($stmt_mark->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database execution error.']);
        }
        $stmt_mark->close();
    } catch (Exception $e) {
        error_log("Mark as Read Error (appointment.php - patient): " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database exception.']);
    }
    
    // Make sure connection is closed if opened here
    if (isset($conn)) $conn->close();
    exit; // Itigil ang script
}
// === END API ROUTER ===


// ==========================================================
// 💡 IDINAGDAG: DB Connection Check for page load
// ==========================================================
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed in appointment.php: " . $conn->connect_error);
}

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../login.php"); 
    exit;
}

// 🔹 Get patient info
$patient_id = $_SESSION['user_id'];
$patient_name = $_SESSION['fullname'] ?? 'Patient User';


// === FETCH NOTIFICATIONS (Tama na 'to) ===
$notifications = [];
$unread_count = 0;
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
if ($stmt_notif){ // Check if prepare succeeded
    $stmt_notif->bind_param("ii", $patient_id, $patient_id);
    $stmt_notif->execute();
    $result_notif = $stmt_notif->get_result();
    while ($row_notif = $result_notif->fetch_assoc()) {
      $notifications[] = $row_notif;
      if ($row_notif['is_read'] == 0) {
        $unread_count++;
      }
    }
    $stmt_notif->close();
} else {
    error_log("Failed to prepare notification statement: " . $conn->error);
}
// === END NOTIFICATION FETCH ===


// === FETCH DOCTORS (Tama na 'to) ===
$doctors = [];
$doctor_sql = "SELECT id, fullname, specialty FROM users 
               WHERE role = 'doctor'
               AND specialty IS NOT NULL
               AND specialty != ''
               ORDER BY fullname ASC";
$doctor_result = $conn->query($doctor_sql);

if ($doctor_result && $doctor_result->num_rows > 0) {
    while ($row = $doctor_result->fetch_assoc()) {
        $doctors[] = $row;
    }
}
// === END DOCTOR FETCH ===


// === HANDLE FORM SUBMISSION (Tama na 'to) ===
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $doctor_id = $_POST['doctor_id'] ?? '';
  $appointment_date = $_POST['appointment_date'] ?? '';
  $appointment_time = $_POST['appointment_time'] ?? '';
  $reason = trim($_POST['reason'] ?? ''); // Trim reason

  if (empty($doctor_id) || empty($appointment_date) || empty($appointment_time) || empty($reason)) {
    header("Location: appointment.php?error=" . urlencode("Please fill out all fields."));
    exit;
  } else {
    // Check patient ID validity (good practice)
    $check = $conn->prepare("SELECT id FROM users WHERE id = ?");
    if (!$check) { // Handle prepare error
         error_log("Prepare failed (check user): " . $conn->error);
         header("Location: appointment.php?error=" . urlencode("Database error. Please try again."));
         exit;
    }
    $check->bind_param("i", $patient_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
      $check->close(); // Close the check statement here

      // Insert appointment
      $stmt = $conn->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, reason, status, created_at)
                              VALUES (?, ?, ?, ?, ?, 'Pending', NOW())"); // Changed 'pending' to 'Pending'
       if (!$stmt) { // Handle prepare error
         error_log("Prepare failed (insert appt): " . $conn->error);
         header("Location: appointment.php?error=" . urlencode("Database error. Please try again."));
         exit;
       }
      $stmt->bind_param("iisss", $patient_id, $doctor_id, $appointment_date, $appointment_time, $reason);

      if ($stmt->execute()) {
        $stmt->close(); // Close insert statement here

        // === Notify Doctor ===
        $patient_fullname = $_SESSION['fullname'] ?? 'A patient';
        $notif_message = "New appointment request from " . $patient_fullname;
        $notif_link = "../DOCTOR/doctor_appointments.php"; // 💡 Adjusted path for doctor

        $stmt_notif_doc = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        if ($stmt_notif_doc){ // Check prepare
            $stmt_notif_doc->bind_param("iss", $doctor_id, $notif_message, $notif_link);
            $stmt_notif_doc->execute();
            $stmt_notif_doc->close();
        } else {
             error_log("Prepare failed (notify doctor): " . $conn->error);
             // Continue redirect even if notification fails for now
        }
        // === END NOTIFY DOCTOR ===

        header("Location: appointment.php?success=1");
        exit;
      } else {
        $db_error = $stmt->error; 
        $stmt->close(); // Close statement even on error
        error_log("Appointment booking error: " . $db_error); 
        header("Location: appointment.php?error=" . urlencode("Error: Could not book appointment. ".$db_error)); // Show more specific error if needed
        exit;
      }
    } else {
      $check->close(); // Close check statement
      header("Location: appointment.php?error=" . urlencode("Error: Invalid patient ID. Please re-login."));
      exit;
    }
  }
}

// 💡 Close connection before HTML
$conn->close(); 
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Book Appointment</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  
  <style>
     /* Root Variables */
    :root {
      --bg: #f6f9fc;
      --card: #ffffff;
      --primary: #1c3d5a;
      --accent: #0078FF; 
      --muted: #6b7280;
      --radius: 12px;
      --container: 1100px;
      --border-color: #e6eef6; 
      --danger: #e11d48; /* Added */
       /* Status Colors for Alerts */
      --green-bg: #d1fae5;
      --green-text: #065f46; 
      --red-bg: #fee2e2;
      --red-text: #b91c1c; 
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; }
    body {
      font-family: 'Poppins', sans-serif;
      background: var(--bg);
      color: #1f2937;
      min-height: 100vh;
      padding-top: 66px; 
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
    .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; color: #fff; }
    .brand-mark { width: 30px; height: 30px; background: #fff; color: var(--primary); border-radius: 6px; display: flex; align-items: center; justify-content: center; }
    .brand-text { font-size: 18px; color: #fff; } 
    .nav-links { display: flex; align-items: center; gap: 12px; }
    .nav-link { color: rgba(255,255,255,0.9); text-decoration: none; padding: 8px 10px; border-radius: 8px; transition: 0.2s; font-weight: 500; }
    .nav-link:hover { background: rgba(255,255,255,0.12); }
    .nav-link.active { background: rgba(255,255,255,0.25); font-weight: 600; }
    .nav-right { position: relative; display: flex; align-items: center; gap: 12px; }
    
    /* === PROFILE DROPDOWN === */
    .profile { position: relative; display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 5px; border-radius: 8px; transition: background 0.2s; }
    .profile:hover { background: rgba(255,255,255,0.1); }
    .avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.9); }
    .name { color: #fff; font-weight: 600; }
    .caret { color: #fff; font-size: 12px; transition: transform 0.25s ease; margin-left: 5px; }
    .profile-dropdown { position: absolute; right: 0; top: 52px; width: 220px; background: var(--card); border-radius: 10px; box-shadow: 0 12px 30px rgba(20,30,50,0.15); display: none; flex-direction: column; opacity: 0; transform: translateY(-8px); transition: all 0.25s ease; z-index: 99; list-style: none; padding: 5px 0; }
    .profile-dropdown.show { display: flex; opacity: 1; transform: translateY(0); }
    .profile-dropdown li { list-style: none; } 
    .profile-dropdown a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #17202a; text-decoration: none; border-radius: 8px; font-weight: 500; transition: 0.2s; }
    .profile-dropdown a:hover { background: #f3f6fb; }
    .profile-dropdown a.logout { color: var(--danger); font-weight: 600; }
    .profile-dropdown a.logout:hover { background: #fee2e2; color: #b91c1c; }
    
    /* === NOTIFICATION BELL === */
    .notif-bell { position: relative; color: #fff; font-size: 20px; padding: 10px 12px; border-radius: 8px; cursor: pointer; transition: background 0.2s; }
    .notif-bell:hover { background: rgba(255,255,255,0.1); }
    .notif-badge {
        position: absolute; top: 6px; right: 8px;
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
    
    /* Appointment Form Styles */
     .container { 
        max-width: 600px; 
        margin: 30px auto; 
        padding: 0 15px;
    }
    .appointment-form {
        background: var(--card);
        padding: 30px;
        border-radius: var(--radius);
        box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    }
    .appointment-form h2 {
        text-align: center;
        margin-top: 0;
        color: var(--primary);
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    .form-group {
        text-align: left;
        margin-bottom: 18px;
        position: relative;
    }
    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 6px; 
        color: var(--primary);
        font-size: 0.95rem;
    }
    .form-group input[type="date"],
    .form-group input[type="time"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 1rem;
        transition: border-color 0.2s, box-shadow 0.2s;
        background: #fff; 
        font-family: 'Poppins', sans-serif; 
        appearance: none; 
    }
    .form-group select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%236b7280'%3E%3Cpath fill-rule='evenodd' d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z' clip-rule='evenodd' /%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.7rem center;
        background-size: 1.2em 1.2em;
        padding-right: 2.5rem; 
    }
    .form-group textarea{min-height: 100px;} 
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(0, 120, 255, 0.15); 
        outline: none;
    }
    .form-btn {
        background: var(--primary);
        color: #fff;
        padding: 12px 24px;
        border: none;
        border-radius: 8px; 
        cursor: pointer;
        transition: background-color 0.2s, transform 0.2s;
        font-weight: 600; 
        width: 100%; 
        margin-top: 10px;
        font-size: 1rem;
        display: inline-flex; 
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .form-btn:hover {
        background: var(--accent);
        transform: translateY(-2px); 
    }
    
    /* Messages */
    .success-message, .error-message {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px; 
        font-weight: 500;
        display: flex; 
        align-items: center;
        gap: 8px;
    }
    .success-message {
        background: var(--green-bg); 
        color: var(--green-text); 
        border: 1px solid #a7f3d0; 
    }
    .error-message {
        background: var(--red-bg); 
        color: var(--red-text); 
        border: 1px solid #fecaca; 
    }

    .footer { text-align: center; margin-top: 30px; padding: 15px; color: var(--muted); font-size: 13px; }

    /* Responsive */
     @media (max-width: 768px) { .nav-links { display: none; } .brand-text { display: none; } }
  </style>
</head>
<body>
  <header class="topnav">
    <div class="nav-inner">
      <div class="brand"><span class="brand-mark">+</span><span class="brand-text">CliniCare</span></div>
      <nav class="nav-links">
        <a href="patient.php" class="nav-link">Home</a>
        <a href="chat.php" class="nav-link">Chat</a>
        
        <a href="appointment.php" class="nav-link active">Book Appointment</a>
        
        <a href="record.php" class="nav-link">Records</a>
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
                  $link = !empty($notif['link']) ? $notif['link'] : 'appointment.php'; // Default link
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
        
        <div class="profile" id="profileToggle"> <img src="../PICTURES/uf.jpg" alt="User avatar" class="avatar">
          <span class="name"><?= htmlspecialchars($patient_name); ?></span>
          <i class="fa-solid fa-chevron-down caret"></i>
          <ul class="profile-dropdown" id="profileDropdown"> <li><a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a></li>
            <li><a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </header>

  <main class="container"> 
    <div class="appointment-form"> 
        <h2 > 
            <i class="fa-solid fa-calendar-check" style="color:var(--accent);"></i>
            Book an Appointment
        </h2>
        
        <form id="bookAppointmentForm" method="POST" action="appointment.php">
            
            <?php if (isset($_GET['success'])): ?>
                <div class="success-message">
                <i class="fa-solid fa-circle-check"></i> Appointment successfully booked! Waiting for confirmation.
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="error-message">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars(urldecode($_GET['error'])); ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="doctor">Choose a Doctor</label>
                <select name="doctor_id" id="doctor" required>
                    <option value="">-- Select a Doctor --</option>
                    
                    <?php
                    if (empty($doctors)) {
                        echo "<option value='' disabled>No doctors available currently.</option>";
                    } else {
                        foreach ($doctors as $doc) {
                            echo "<option value='" . $doc['id'] . "'>" 
                               . htmlspecialchars($doc['fullname']) 
                               . " (" . htmlspecialchars($doc['specialty']) . ")"
                               . "</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label for="appointment_date">Select Date</label>
                <input type="date" name="appointment_date" id="appointment_date" required 
                       min="<?php echo date('Y-m-d'); ?>">
            </div>

            <div class="form-group">
                <label for="appointment_time">Select Time</label>
                <input type="time" name="appointment_time" id="appointment_time" required>
            </div>
            
            <div class="form-group">
                <label for="reason">Reason / Concern</label>
                <textarea name="reason" id="reason" placeholder="Briefly describe your reason for the visit..." required></textarea>
            </div>
            
            <button type="submit" class="form-btn"> 
                <i class="fa-solid fa-paper-plane"></i> Submit Request
            </button>
            
        </form>
    </div>
  </main>

  <footer class="footer">
    <p>© <?= date('Y') ?> CliniCare | Book Appointment</p> 
  </footer>

  <script>
    document.addEventListener("DOMContentLoaded", () => {
        // --- PART A: HEADER/DROPDOWN LOGIC ---
        const profile = document.getElementById("profileToggle"); // Ginamit ang tamang ID
        const dropdown = document.getElementById("profileDropdown"); // Ginamit ang tamang ID
        const caret = profile ? profile.querySelector(".caret") : null;
        const notifBell = document.getElementById("notifBell");
        const notifDropdown = document.getElementById("notifDropdown");
        const notifBadge = notifBell ? notifBell.querySelector(".notif-badge") : null;
        const currentUserId = <?= $patient_id; ?>; // Para magamit sa fetch

        if (profile && dropdown && caret) {
            profile.addEventListener("click", (e) => {
                e.stopPropagation();
                dropdown.classList.toggle("show");
                caret.style.transform = dropdown.classList.contains("show") ? "rotate(180deg)" : "rotate(0deg)";
                if (notifDropdown && notifDropdown.classList.contains("show")) {
                    notifDropdown.classList.remove("show");
                }
            });
        }

        if (notifBell && notifDropdown) {
            notifBell.addEventListener("click", (e) => {
                e.stopPropagation();
                notifDropdown.classList.toggle("show");
                if (dropdown && dropdown.classList.contains("show")) {
                    dropdown.classList.remove("show");
                    if (caret) caret.style.transform = "rotate(0deg)";
                }
                
                // === Mark as Read Logic ===
                if (notifDropdown.classList.contains("show") && notifBadge && notifBadge.style.display !== 'none') {
                    
                    // 💡 Tinatawag ang appointment.php na may API router
                    fetch('appointment.php?action=mark_read', { 
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest' 
                         },
                        body: JSON.stringify({ user_id: currentUserId }) // Use user_id for consistency maybe?
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
            if (profile && dropdown && !profile.contains(e.target) && dropdown.classList.contains("show")) {
                dropdown.classList.remove("show");
                if (caret) caret.style.transform = "rotate(0deg)";
            }
            if (notifBell && notifDropdown && !notifBell.contains(e.target) && !notifDropdown.contains(e.target) && notifDropdown.classList.contains("show")) {
                notifDropdown.classList.remove("show");
            }
        });

        // --- PART B: APPOINTMENT PAGE SPECIFIC JS (Clear URL Params) ---
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            // Check kung may success or error message na na-display
            const successMsg = document.querySelector('.success-message');
            const errorMsg = document.querySelector('.error-message');
            if (successMsg || errorMsg) {
                 // Remove GET parameters if message was shown
                 window.history.replaceState({ path: url.pathname }, '', url.pathname);
            }
        }
        
        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const alertMsg = document.querySelector('.success-message, .error-message');
            if (alertMsg) {
                alertMsg.style.transition = 'opacity 0.5s ease';
                alertMsg.style.opacity = '0';
                setTimeout(() => alertMsg.style.display = 'none', 500);
            }
        }, 5000); // 5 seconds
    });
  </script>
</body>
</html>