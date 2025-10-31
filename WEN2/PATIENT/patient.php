<?php
session_start();
include '../db_connect.php';


$patient_name = $_SESSION['fullname'] ?? 'User';
$patient_id = $_SESSION['user_id'];

// Fetch latest 2 appointments
$appointments = [];
$sql = "SELECT a.appointment_date, a.appointment_time, a.status,
               u.fullname AS doctor_name, u.specialty
        FROM appointments a
        JOIN users u ON a.doctor_id = u.id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 2"; // Added time order
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
  $appointments[] = $row;
}
$stmt->close();

// === 🔴 BAGONG CODE: Fetch Notifications ===
$notifications = [];
$unread_count = 0;

// Query to get *all* notifications, with unread first, limited to 7
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
$stmt_notif->bind_param("ii", $patient_id, $patient_id);
$stmt_notif->execute();
$result_notif = $stmt_notif->get_result();

while ($row_notif = $result_notif->fetch_assoc()) {
  $notifications[] = $row_notif;
  if ($row_notif['is_read'] == 0) {
    $unread_count++; // Count only the unread ones
  }
}
$stmt_notif->close();
// $conn->close(); // Keep connection open
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Patient Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  
  <style>
      /* Root Variables */
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

    /* === HEADER STYLES (Blue Gradient) === */
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
    /* === END OF HEADER STYLES === */
    
    /* === 🔴 BAGONG CSS: NOTIFICATION BELL STYLES === */
    .notif-bell {
      position: relative;
      color: #fff;
      font-size: 20px; /* Bigger bell */
      padding: 10px 12px;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s;
    }
    .notif-bell:hover { background: rgba(255,255,255,0.1); }
    /* === 💡 INAYOS NA CSS NG BADGE 💡 === */
    .notif-badge {
        position: absolute;
        top: 6px;        /* Konting adjust */
        right: 8px;      /* Konting adjust */
        min-width: 16px; /* Mas maliit */
        height: 16px;    /* Mas maliit */
        background: var(--danger); /* Red pa rin */
        color: #fff;
        border-radius: 8px; /* Kalahati ng height */
        padding: 0;         /* Alis padding para bilog */
        font-size: 10px;    /* Mas maliit na text */
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #fff; /* White border */
        line-height: 1;
    }
    .notif-dropdown {
      position: absolute;
      right: 0;
      top: 52px; 
      width: 360px; /* Wider for notifications */
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
      z-index: 99;
      padding: 0; /* Remove padding */
    }
    .notif-dropdown.show { display: flex; opacity: 1; transform: translateY(0); }
    .notif-header {
      padding: 12px 16px;
      font-weight: 600;
      color: var(--primary);
      font-size: 16px;
      border-bottom: 1px solid var(--border-color);
      position: sticky; /* Stick to top */
      top: 0;
      background: var(--card);
      z-index: 1;
    }
    .notif-list {
      padding: 8px; /* Add padding here for the list */
    }
    .notif-item {
      display: block;
      padding: 12px;
      border-radius: 8px;
      transition: background 0.2s;
      text-decoration: none;
      color: var(--muted);
      margin-bottom: 5px;
      border-bottom: 1px solid #f0f4f9;
      position: relative; /* For the unread dot */
    }
    .notif-item:last-child { border-bottom: none; margin-bottom: 0; }
    .notif-item:hover { background: #f3f6fb; }
    .notif-item.unread {
      background: #eef6ff; /* Light blue for unread */
    }
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
      padding-left: 12px; /* Space for the dot */
    }
    .notif-item span {
      font-size: 12px;
      color: var(--accent);
      font-weight: 500;
      padding-left: 12px; /* Space for the dot */
    }
    .notif-empty {
      padding: 30px 20px;
      text-align: center;
      color: var(--muted);
    }
    .notif-empty i {
      font-size: 28px;
      margin-bottom: 10px;
      color: #a0aec0;
    }
    /* === END OF NOTIFICATION STYLES === */

    /* General Components */
    .container { max-width: var(--container); margin: 20px auto; padding: 0 18px; }
    .card { background: var(--card); border-radius: var(--radius); padding: 20px; margin-bottom: 20px; box-shadow: 0 8px 24px rgba(20,30,50,0.06); transition: 0.2s; }
    .card:hover { transform: translateY(-3px); }
    .card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .card-head h2 { color: var(--primary); margin: 0; }
    .small-link { color: var(--accent); font-weight: 600; text-decoration: none; font-size: 14px; }
    .btn { display: inline-flex; align-items: center; gap: 8px; border: none; padding: 10px 16px; border-radius: 10px; cursor: pointer; font-weight: 600; transition: 0.2s; text-decoration: none; }
    .btn.primary { background: var(--primary); color: #fff; }
    .btn.primary:hover { background: var(--accent); transform: scale(1.03); }
    .btn.outline { border: 1.5px solid var(--primary); color: var(--primary); background: transparent; } /* Adjusted border */
    .btn.outline:hover { background: #e9f2ff; }

    /* Welcome Section */
    .welcome { display: grid; grid-template-columns: 1fr auto; gap: 18px; align-items: center; }
    .welcome h1 { font-size: 26px; color: var(--primary); margin: 0;}
    .welcome p { color: var(--muted); margin-top: 6px; }
    .actions { margin-top: 15px; display: flex; gap: 10px; }
    .hero-img { width: 300px; height: auto; max-height: 200px; object-fit: contain; border-radius: 10px; }

    /* Appointments List */
    .appt-list { display: flex; flex-direction: column; gap: 12px; }
    .appt { display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f9fcff; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.04); transition: 0.2s; border-left: 4px solid var(--accent); }
    .appt:hover { transform: scale(1.01); box-shadow: 0 6px 16px rgba(0,0,0,0.07); }
    .appt-info h3 { font-size: 1rem; color: var(--primary); margin: 0 0 4px 0; }
    .appt-info p { font-size: 0.9rem; color: var(--muted); margin: 0; line-height: 1.4; }
    .badge { padding: 5px 10px; border-radius: 999px; font-weight: 600; font-size: 12px; text-transform: capitalize; }
    .badge.confirmed, .badge.accepted, .badge.completed { background: var(--green-bg); color: var(--green-text); } 
    .badge.pending { background: var(--yellow-bg); color: var(--yellow-text); }
    .badge.cancelled, .badge.declined { background: var(--red-bg); color: var(--red-text); } 

    .footer { text-align: center; margin-top: 30px; padding: 15px; color: var(--muted); font-size: 13px; }

    /* Responsive */
    @media (max-width: 900px) {
      .welcome { grid-template-columns: 1fr; }
      .hero-img { display: none; } /* Hide image on smaller screens */
    }
    @media (max-width: 768px) { 
      .nav-links { display: none; } 
      .brand-text { display: none; }
      .container { padding: 0 10px; }
      .welcome h1 { font-size: 22px; }
    }
  </style>
  <script defer>
    // Moved patient.js content here
    document.addEventListener("DOMContentLoaded", () => {
      const profile = document.getElementById("profileMenu");
      const dropdown = document.getElementById("profileDropdown");
      const caret = profile ? profile.querySelector(".caret") : null;

      // === 🔴 BAGONG CODE: Notification Bell Elements ===
      const notifBell = document.getElementById("notifBell");
      const notifDropdown = document.getElementById("notifDropdown");
      const notifBadge = notifBell ? notifBell.querySelector(".notif-badge") : null;
      // === END NEW ===

      if (profile && dropdown && caret) {
        profile.addEventListener("click", (e) => {
          e.stopPropagation();
          dropdown.classList.toggle("show");
          caret.style.transform = dropdown.classList.contains("show")
            ? "rotate(180deg)"
            : "rotate(0deg)";
          
          // --- NEW: Close notif dropdown if open ---
          if (notifDropdown && notifDropdown.classList.contains("show")) {
            notifDropdown.classList.remove("show");
          }
          // --- END NEW ---
        });
      }

      // === 🔴 BAGONG CODE: Notification Bell Click Listener ===
      if (notifBell && notifDropdown) {
        notifBell.addEventListener("click", (e) => {
          e.stopPropagation();
          notifDropdown.classList.toggle("show");

          // --- NEW: Close profile dropdown if open ---
          if (dropdown && dropdown.classList.contains("show")) {
            dropdown.classList.remove("show");
            if (caret) caret.style.transform = "rotate(0deg)";
          }
          // --- END NEW ---

          // --- NEW: Logic to mark notifications as read ---
          if (notifDropdown.classList.contains("show") && notifBadge) {
            
            // 1. Send request to server to mark as read
            fetch('mark_read.php', { // Tiyaking tama ang path
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ patient_id: <?php echo $patient_id; ?> }) 
            })
            .then(response => response.json())
            .then(data => {
              if (data.success) {
                console.log("Notifications marked as read.");
                // 2. Remove the badge visually
                if (notifBadge) {
                  notifBadge.style.display = 'none';
                }
                // 3. Remove 'unread' style from items
                document.querySelectorAll('.notif-item.unread').forEach(item => {
                  item.classList.remove('unread');
                });
              } else {
                console.error("Failed to mark notifications as read.");
              }
            })
            .catch(err => {
              console.error("Error in mark_read request:", err);
            });
          }
          // --- END NEW ---
        });
      }
      // === END NEW ===

      // === MODIFIED: General Click Listener ===
      document.addEventListener("click", (e) => {
        // Close profile dropdown
        if (profile && dropdown) {
          if (!profile.contains(e.target) && dropdown.classList.contains("show")) {
            dropdown.classList.remove("show");
            if (caret) caret.style.transform = "rotate(0deg)";
          }
        }

        // --- NEW: Close notification dropdown ---
        if (notifBell && notifDropdown) {
          if (!notifBell.contains(e.target) && !notifDropdown.contains(e.target) && notifDropdown.classList.contains("show")) {
            notifDropdown.classList.remove("show");
          }
        }
        // --- END NEW ---
      });
    });
  </script>
</head>
<body>
  <!-- === UPDATED HEADER === -->
  <header class="topnav">
    <div class="nav-inner">
      <!-- Brand Logo -->
      <div class="brand">
        <span class="brand-mark">+</span><span class="brand-text">CliniCare</span>
      </div>

      <!-- Navigation Links -->
      <nav class="nav-links">
        <a href="patient.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'patient.php' ? 'active' : '' ?>">Home</a>
        <a href="chat.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'chat.php' ? 'active' : '' ?>">Chat</a>
        <a href="appointment.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'appointment.php' ? 'active' : '' ?>">Book Appointment</a>
        <a href="record.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'record.php' ? 'active' : '' ?>">Records</a>
      </nav>

      <!-- Profile Dropdown -->
      <div class="nav-right">

        <!-- === 🔴 BAGONG HTML: NOTIFICATION BELL === -->
        <div class="notif-bell" id="notifBell">
          <i class="fa-solid fa-bell"></i>
          <?php if ($unread_count > 0): ?>
            <span class="notif-badge"><?= $unread_count; ?></span>
          <?php endif; ?>

          <!-- Notification Dropdown Panel -->
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
                  // Simple time ago function
                  $time_ago = strtotime($notif['created_at']);
                  $time_diff = time() - $time_ago;
                  $time_unit = '';
                  if ($time_diff < 60) {
                    $time_unit = 'just now';
                  } elseif ($time_diff < 3600) {
                    $time_unit = floor($time_diff / 60) . 'm ago';
                  } elseif ($time_diff < 86400) {
                    $time_unit = floor($time_diff / 3600) . 'h ago';
                  } else {
                    $time_unit = floor($time_diff / 86400) . 'd ago';
                  }
                  
                  $link = $notif['link'] ?? 'appointment.php'; // Default link
                  $is_unread_class = $notif['is_read'] == 0 ? 'unread' : '';
                ?>
                  <a href="<?= htmlspecialchars($link); ?>" class="notif-item <?= $is_unread_class; ?>">
                    <p><?= htmlspecialchars($notif['message']); ?></p>
                    <span><i class="fa-solid fa-clock" style="margin-right: 4px;"></i><?= $time_unit; ?></span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div> <!-- .notif-list -->
          </div>
        </div>
        <!-- === END NOTIFICATION BELL === -->

        <div class="profile" id="profileMenu"> <!-- Consistent ID -->
          <img src="../PICTURES/uf.jpg" alt="User avatar" class="avatar">
          <span class="name"><?php echo htmlspecialchars($patient_name); ?></span>
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
        <h1>Welcome back, <?php echo htmlspecialchars($patient_name); ?>! 👋</h1>
        <p>Manage appointments, chat with your doctor, and view your medical records — all in one place.</p>
        <div class="actions">
          <a href="appointment.php" class="btn primary"><i class="fa-solid fa-calendar-plus" style="margin-right: 5px;"></i>Book Appointment</a>
          <a href="record.php" class="btn outline"><i class="fa-solid fa-file-medical" style="margin-right: 5px;"></i>View Records</a>
        </div>
      </div>
      <img src="../PICTURES/bg.png" alt="Clinic illustration" class="hero-img"> <!-- Better alt text -->
    </section>

    <section class="card">
      <div class="card-head">
        <h2><i class="fa-solid fa-clock" style="margin-right: 8px; color: var(--accent);"></i>Your Upcoming Appointments</h2>
        <a href="appointment.php" class="small-link">Manage All</a> <!-- Updated link text -->
      </div>

      <div class="appt-list">
        <?php if (empty($appointments)): ?>
          <p style="text-align: center; color: var(--muted); padding: 15px 0;">You have no upcoming appointments.</p>
        <?php else: ?>
          <?php foreach ($appointments as $a): 
                $status_class = strtolower(htmlspecialchars($a['status']));
          ?>
            <div class="appt">
              <div class="appt-info">
                <h3>Dr. <?= htmlspecialchars($a['doctor_name']); ?></h3>
                <p><?= htmlspecialchars($a['specialty']); ?></p>
                <p><i class="fa-solid fa-calendar-days" style="margin-right: 5px;"></i><?= date('M d, Y', strtotime($a['appointment_date'])); ?> - <i class="fa-solid fa-clock" style="margin-right: 5px; margin-left: 5px;"></i><?= date('h:i A', strtotime($a['appointment_time'])); ?></p>
              </div>
              <span class="badge <?= $status_class; ?>"><?= htmlspecialchars($a['status']); ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <footer class="footer">
    <p>© <?= date('Y') ?> CliniCare. All rights reserved.</p> <!-- Dynamic year -->
  </footer>
</body>
</html>

