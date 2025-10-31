<?php
// ----------------------------
// 🔹 SESSION START (DAPAT NASA PINAKATAAS)
// ----------------------------
session_start();
include '../db_connect.php'; // Ilagay ang connection dito

// ========================================================================
// === 💡 AYOS #1: Idinagdag ang DB Connection Check (Error 500 protection)
// ========================================================================
if (!isset($conn) || $conn->connect_error) {
    $error_msg = "Database connection failed in doctor.php.";
    if (isset($conn)) $error_msg .= " Error: " . $conn->connect_error;
    die($error_msg); 
}

// ========================================================================
// === 🚨 Security Guard para sa Doctor Page (Tama na 'to)
// ========================================================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    // I-redirect pabalik sa admin_login.php, hindi sa login.php ng patient
    header("Location: ../admin_login.php?error=" . urlencode("Access Denied. Please login as Doctor."));
    exit;
}

// -----------------------------------------------------------------
// 🔹 "API ROUTER" (para sa notifications)
// -----------------------------------------------------------------
// Tinitingnan natin kung ang request ay POST at may "?action=mark_read"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'mark_read') {
    
    // (Database connection ay na-check na sa taas)
    
    header('Content-Type: application/json');
    $doctorID_api = $_SESSION['user_id'] ?? 0;

    if ($doctorID_api === 0) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        exit;
    }
    
    // 💡 Gumawa tayo ng simpleng update query dito
    try {
        $stmt_update = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt_update->bind_param("i", $doctorID_api);
        $stmt_update->execute();
        $stmt_update->close();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("Mark as Read Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
    
    $conn->close();
    exit; // Itigil ang script pagkatapos ng API request
}

// -----------------------------------------------------------------
// 🔹 KUNIN ANG DATA PARA SA PAGE LOAD (Nasa baba ng API router)
// -----------------------------------------------------------------

$doctorName = $_SESSION['fullname'] ?? 'Doctor';
$doctorID = $_SESSION['user_id'];

// =======================================================
// === 💡 AYOS: Inalis ang session logic at nag-set ng default path
// =======================================================
$doctorProfilePicPath = '../PICTURES/doctor-avatar.png'; // 👈 Ito 'yung ginamit mo sa HTML


// =======================================================
// === 💡 AYOS: Idinagdag ang queries para sa dashboard
// =======================================================

// --- Kunin ang Notifications ---
$unread_count = 0;
$notifications = [];
try {
    // (Ang SQL query mo para sa notifications ay okay na)
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
    $stmt_notif->bind_param("ii", $doctorID, $doctorID); 
    $stmt_notif->execute();
    $result_notif = $stmt_notif->get_result();

    while ($row_notif = $result_notif->fetch_assoc()) {
      $notifications[] = $row_notif;
      if ($row_notif['is_read'] == 0) {
        $unread_count++;
      }
    }
    $stmt_notif->close();
} catch (Exception $e) { /*...*/ }

// --- Kunin ang Appointments (Display max 5) ---
$appointments = [];
try {
    $stmt_appt = $conn->prepare("SELECT a.*, p.fullname as patient_name 
                               FROM appointments a 
                               LEFT JOIN users p ON a.patient_id = p.id 
                               WHERE a.doctor_id = ? AND a.status IN ('Confirmed', 'Pending')
                               ORDER BY a.appointment_date, a.appointment_time LIMIT 5");
    if($stmt_appt) {
        $stmt_appt->bind_param("i", $doctorID);
        $stmt_appt->execute();
        $result_appt = $stmt_appt->get_result();
        while ($row = $result_appt->fetch_assoc()) {
            $appointments[] = $row;
        }
        $stmt_appt->close();
    }
} catch (Exception $e) { /*...*/ }

?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Doctor Dashboard</title>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  
  <style>
    :root {
      --bg: #f6f9fc;
      --card: #ffffff;
      --primary: #1c3d5a;
      --accent: #0078FF; /* Messenger Blue */
      --muted: #6b7280;
      --radius: 12px;
      --container: 1100px;
      --border-color: #e6eef6; 
      /* Status Colors */
      --green-bg: #dcfce7;
      --green-text: #166534; 
      --yellow-bg: #fef3c7;
      --yellow-text: #92400e; 
      --red-bg: #fee2e2;
      --red-text: #991b1b; 
      --blue-bg: #e0f2fe; /* Light blue for Rescheduled */
      --blue-text: #075985;
      --gray-bg: #f3f4f6; /* Gray for Completed */
      --gray-text: #4b5563;
      --danger: #e11d48; /* Added danger */
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; }
    body {
      font-family: 'Poppins', sans-serif;
      background: var(--bg);
      color: #1f2937;
      min-height: 100vh;
      padding-top: 66px; /* Space for fixed navbar */
    }
    .topnav {
      background: linear-gradient(90deg, var(--primary), var(--accent)); /* BLUE GRADIENT */
      color: #fff;
      padding: 12px 18px;
      box-shadow: 0 6px 18px rgba(31, 78, 121, 0.12);
      position: fixed; top: 0; left: 0; width: 100%; z-index: 50; 
    }
    .nav-inner {
      max-width: var(--container); /* Use container width */
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
      color: #fff; /* White text for brand */
    }
    .brand-mark {
      width: 30px; height: 30px;
      background: #fff;
      color: var(--primary); /* Blue '+' sign */
      border-radius: 6px;
      display: flex; align-items: center; justify-content: center;
    }
    .brand-text { font-size: 18px; color: #fff; } 
    .nav-links {
      display: flex; align-items: center; gap: 12px;
    }
    .nav-link {
      color: rgba(255,255,255,0.9); /* Lighter white for links */
      text-decoration: none;
      padding: 8px 10px;
      border-radius: 8px;
      transition: 0.2s;
      font-weight: 500; 
    }
    .nav-link:hover { background: rgba(255,255,255,0.12); }
    .nav-link.active { background: rgba(255,255,255,0.25); font-weight: 600; }
    .nav-right { position: relative; display: flex; align-items: center; gap: 12px; }
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
      background: var(--card); /* White background */
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
      display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #17202a; /* Dark text */ text-decoration: none; border-radius: 8px; font-weight: 500; transition: 0.2s;
    }
    .profile-dropdown a:hover { background: #f3f6fb; }
    .profile-dropdown a.active { background: #e9f2ff; font-weight: 600; color: var(--primary);} /* Active state in dropdown */
    .profile-dropdown a.logout { color: #e11d48; font-weight: 600; }
    .profile-dropdown a.logout:hover { background: #fee2e2; color: #b91c1c; }
    
    /* === 💡 NOTIFICATION BELL STYLES (FROM APPOINTMENTS) === */
    .notif-bell {
      position: relative;
      color: #fff;
      font-size: 20px;
      padding: 10px 12px;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s;
    }
    .notif-bell:hover { background: rgba(255,255,255,0.1); }
    
    /* === 💡 ITO YUNG INAYOS NA CSS PARA SA BADGE 💡 === */
    .notif-badge {
        position: absolute;
        top: 6px;
        right: 8px;
        min-width: 16px; /* Mas maliit */
        height: 16px;    /* Mas maliit */
        background: var(--danger);
        color: #fff;
        border-radius: 8px; /* Kalahati ng height */
        padding: 0;         /* Alis padding para bilog */
        font-size: 10px;    /* Mas maliit na text */
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #fff; /* Pinalitan ng puti */
        line-height: 1;
    }
    .notif-dropdown {
      position: absolute;
      right: 0;
      top: 52px; 
      width: 360px;
      max-height: 420px;
      overflow-y: auto;
      background: var(--card);
      border-radius: 10px;
      box-shadow: 0 12px 30px rgba(20,30,50,0.15);
      display: none;
      flex-direction: column;
      opacity: 0;
      transform: translateY(-8px);
      transition: all 0.25s ease;
      z-index: 100; /* Mas mataas sa profile dropdown */
      padding: 0;
    }
    .notif-dropdown.show { display: flex; opacity: 1; transform: translateY(0); }
    .notif-header {
      padding: 12px 16px;
      font-weight: 600;
      color: var(--primary);
      font-size: 16px;
      border-bottom: 1px solid var(--border-color);
      position: sticky;
      top: 0;
      background: var(--card);
      z-index: 1;
    }
    .notif-list { padding: 8px; }
    .notif-item {
      display: block;
      padding: 12px;
      border-radius: 8px;
      transition: background 0.2s;
      text-decoration: none;
      color: var(--muted);
      margin-bottom: 5px;
      border-bottom: 1px solid #f0f4f9;
      position: relative;
    }
    .notif-item:last-child { border-bottom: none; margin-bottom: 0; }
    .notif-item:hover { background: #f3f6fb; }
    .notif-item.unread { background: #eef6ff; }
    .notif-item.unread::before {
      content: '';
      position: absolute;
      left: 6px;
      top: 50%;
      transform: translateY(-50%);
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--accent);
    }
    .notif-item p {
      color: #333;
      font-size: 14px;
      font-weight: 500;
      margin: 0 0 4px 0;
      white-space: normal;
      padding-left: 12px;
    }
    .notif-item span {
      font-size: 12px;
      color: var(--accent);
      font-weight: 500;
      padding-left: 12px;
    }
    .notif-empty {
      padding: 30px 20px;
      text-align: center;
      color: var(--muted);
    }
    .notif-empty i { font-size: 28px; margin-bottom: 10px; color: #a0aec0; }
    /* === END NOTIFICATION CSS === */
    
    /* === Styles para sa Dashboard Content === */
    .container {
        max-width: var(--container);
        margin: 20px auto;
        padding: 0 18px;
    }
     .card {
        background: var(--card); 
        border-radius: var(--radius); 
        padding: 20px; 
        margin-bottom: 20px; 
        box-shadow: 0 8px 24px rgba(20,30,50,0.06); 
    }
    .card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
    .card-head h2 { color: var(--primary); margin: 0; font-size: 1.2rem; }
    .small-link { color: var(--accent); font-weight: 600; text-decoration: none; font-size: 14px; }
    
    .btn { display: inline-flex; align-items: center; gap: 8px; border: none; padding: 10px 16px; border-radius: 10px; cursor: pointer; font-weight: 600; transition: 0.2s; text-decoration: none; }
    .btn.primary { background: var(--primary); color: #fff; }
    .btn.primary:hover { background: var(--accent); }
    .btn.outline { border: 1.5px solid var(--primary); color: var(--primary); background: transparent; } 
    .btn.outline:hover { background: #e9f2ff; }

     .welcome { display: grid; grid-template-columns: 1fr auto; gap: 18px; align-items: center; }
     .welcome h1 { font-size: 26px; color: var(--primary); margin: 0;}
     .welcome p { color: var(--muted); margin-top: 6px; }
     .actions { margin-top: 15px; display: flex; gap: 10px; }
     .hero-img { width: 300px; height: auto; max-height: 200px; object-fit: contain; border-radius: 10px; }


    .appt-list { display: flex; flex-direction: column; gap: 12px; }
    .appt { display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f9fcff; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.04); transition: 0.2s; border-left: 4px solid var(--accent); }
    .appt:hover { transform: scale(1.01); box-shadow: 0 6px 16px rgba(0,0,0,0.07); }
    .appt-info h3 { font-size: 1rem; color: var(--primary); margin: 0 0 4px 0; }
    .appt-info p.muted.small { font-size: 0.85rem; color: var(--muted); margin: 0; line-height: 1.4; }
    .badge { padding: 5px 10px; border-radius: 999px; font-weight: 600; font-size: 12px; text-transform: capitalize; }
    .badge.confirmed, .badge.accepted, .badge.completed { background: var(--green-bg); color: var(--green-text); } 
    .badge.pending { background: var(--yellow-bg); color: var(--yellow-text); }
    .badge.cancelled, .badge.declined { background: var(--red-bg); color: var(--red-text); } 
    .badge.rescheduled { background: var(--blue-bg); color: var(--blue-text); }

     @media (max-width: 900px) {
      .welcome { grid-template-columns: 1fr; }
      .hero-img { display: none; } 
    }
     @media (max-width: 768px) { 
      .nav-links { display: none; } 
      .brand-text { display: none; }
      .container { padding: 0 10px; }
      .welcome h1 { font-size: 22px; }
    }
  </style>
</head>
<body>
  
  <header class="topnav">
    <div class="nav-inner">
      <div class="brand"><span class="brand-mark">+</span><span class="brand-text">CliniCare</span></div>
      <nav class="nav-links">
        
        <a href="doctor.php" class="nav-link active">Dashboard</a> 
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
            </div> </div>
        </div>
        <div class="profile" id="profileToggle">
          <img src="<?= $doctorProfilePicPath; ?>" alt="Doctor" class="avatar"> 
          <span class="name">Dr. <?= htmlspecialchars($doctorName) ?></span> 
          <i class="fa-solid fa-chevron-down caret"></i>
          <ul class="profile-dropdown" id="profileDropdown">
            <li><a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a></li>
            <li><a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </header>

  <main class="container">
    <section class="card welcome">
      <div>
        <h1>Welcome back, <span style="color:var(--accent)">Dr. <?= htmlspecialchars($doctorName) ?></span> 👋</h1>
        <p>Here’s a quick overview of your day — upcoming appointments and patient messages at a glance.</p>
        <div class="actions">
          <a href="doctor_appointments.php" class="btn primary"><i class="fa-solid fa-calendar"></i> View Appointments</a>
          <a href="doctor_chat.php" class="btn outline"><i class="fa-solid fa-comments"></i> Chat with Patients</a>
        </div>
      </div>
      <img src="../PICTURES/bg.png" alt="Clinic Illustration" class="hero-img">
    </section>

    <section class="card">
      <div class="card-head"><h2>Appointments</h2><a href="doctor_appointments.php" class="small-link">View All</a></div>
      <div class="appt-list">
        <?php if(count($appointments) > 0): ?>
          <?php foreach($appointments as $appt): 
                $status_lower = strtolower(htmlspecialchars($appt['status']));
          ?>
            <div class="appt"> 
              <div class="appt-info">
                <h3><?= htmlspecialchars($appt['patient_name']) ?></h3>
                <p class="muted small">
                  <?= date('M d, Y', strtotime($appt['appointment_date'])) ?> | 
                  <?= date('h:i A', strtotime($appt['appointment_time'])) ?><br>
                  Reason: <?= !empty($appt['reason']) ? htmlspecialchars($appt['reason']) : 'N/A' ?>
                </p>
              </div>
              <span class="badge <?= $status_lower ?>"><?= htmlspecialchars($appt['status']) ?></span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p style="text-align: center; color: var(--muted); padding: 15px 0;">No new appointments.</p>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <script>
    document.addEventListener("DOMContentLoaded", () => {
      // --- Profile Dropdown Logic ---
      const profile = document.getElementById("profileToggle");
      const dropdown = document.getElementById("profileDropdown");
      const caret = profile ? profile.querySelector(".caret") : null;

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

      // --- Notification Bell Logic ---
      const notifBell = document.getElementById("notifBell");
      const notifDropdown = document.getElementById("notifDropdown");
      const notifBadge = notifBell ? notifBell.querySelector(".notif-badge") : null;

      if (notifBell && notifDropdown) {
        notifBell.addEventListener("click", (e) => {
          e.stopPropagation();
          notifDropdown.classList.toggle("show");

          if (dropdown && dropdown.classList.contains("show")) {
            dropdown.classList.remove("show");
            if (caret) caret.style.transform = "rotate(0deg)";
          }

          if (notifDropdown.classList.contains("show") && notifBadge && notifBadge.style.display !== 'none') {
            
            // === 💡 INAYOS ANG FETCH URL: Dapat `doctor.php` ang tinatawag ===
            fetch('doctor.php?action=mark_read', { 
              method: 'POST',
              headers: { 
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' // Idinagdag para mas sigurado
              },
              body: JSON.stringify({ user_id: <?php echo $doctorID; ?> }) 
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Network response was not ok: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
              if (data.success) {
                console.log("Notifications marked as read.");
                if (notifBadge) {
                  notifBadge.style.display = 'none'; 
                }
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

      // --- Close dropdowns on outside click ---
      document.addEventListener("click", (e) => {
        if (profile && dropdown && !profile.contains(e.target) && dropdown.classList.contains("show")) {
          dropdown.classList.remove('show');
          if (caret) caret.style.transform = "rotate(0deg)";
        }
        if (notifBell && notifDropdown && !notifBell.contains(e.target) && !notifDropdown.contains(e.target) && notifDropdown.classList.contains("show")) {
          notifDropdown.classList.remove("show");
        }
      });
    });
  </script>
</body>
</html>