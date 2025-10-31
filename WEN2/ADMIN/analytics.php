<?php
// ==========================================================
// === 💡 IDINAGDAG: API ROUTER PARA SA MARK AS READ
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    
    session_start();
    include '../db_connect.php'; 
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Authentication failed.']);
        exit;
    }
    
    $admin_id = $_SESSION['user_id'];
    $data = json_decode(file_get_contents('php://input'), true);

    // --- Action: Mark Notifications as Read ---
    if (isset($data['action']) && $data['action'] === 'mark_read') {
         try {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Mark as Read Error (Admin Analytics): " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error.']);
        }
        $conn->close();
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}
// === END API ROUTER ===


// ==========================================================
// === NORMAL PAGE LOAD
// ==========================================================
session_start();
include '../db_connect.php'; 

// 💡 IDINAGDAG: DB Connection Check
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed in analytics.php: " . $conn->connect_error);
}

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin_login.php?error=" . urlencode("Access Denied. Please login as Admin."));
    exit;
}

$adminID = $_SESSION['user_id']; // 💡 Ginamit ang $adminID
$adminName = $_SESSION['fullname'] ?? 'Admin User';
$adminProfilePicPath = '../PICTURES/default_user.png'; // 💡 Ginamit ang default path


// ==========================================================
// === 💡 IDINAGDAG: FETCH NOTIFICATIONS PARA SA HEADER
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
    if ($stmt_notif) {
        $stmt_notif->bind_param("ii", $adminID, $adminID); 
        $stmt_notif->execute();
        $result_notif = $stmt_notif->get_result();
        while ($row_notif = $result_notif->fetch_assoc()) {
          $notifications[] = $row_notif;
          if ($row_notif['is_read'] == 0) $unread_count++;
        }
        $stmt_notif->close();
    }
} catch (Exception $e) {
     error_log("Admin Fetch Notifications Error (Analytics): " . $e->getMessage());
}
// === END NOTIFICATION FETCH ===


// --- 1. FETCH DASHBOARD STATS (Existing code) ---
$stats = [
    'total_doctors' => 0,
    'total_patients' => 0,
    'appointments_today' => 0
];
try {
    $result_doc = $conn->query("SELECT COUNT(id) as count FROM users WHERE role='doctor'");
    $stats['total_doctors'] = $result_doc->fetch_assoc()['count'] ?? 0;
    $result_pat = $conn->query("SELECT COUNT(id) as count FROM users WHERE role='patient'");
    $stats['total_patients'] = $result_pat->fetch_assoc()['count'] ?? 0;
    $today = date('Y-m-d');
    $result_app = $conn->prepare("SELECT COUNT(id) as count FROM appointments WHERE appointment_date = ?");
    if ($result_app) {
        $result_app->bind_param("s", $today);
        $result_app->execute();
        $stats['appointments_today'] = $result_app->get_result()->fetch_assoc()['count'] ?? 0;
        $result_app->close();
    }
} catch (Exception $e) { /*...*/ }

// --- 2. FETCH REALTIME CHART DATA (Existing code) ---
// == Monthly Appointments (Current Year) ==
$monthly_data = array_fill(0, 12, 0); 
$monthly_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$monthly_sql = "SELECT MONTH(appointment_date) as month_num, COUNT(id) as count 
                FROM appointments 
                WHERE YEAR(appointment_date) = YEAR(CURDATE()) 
                GROUP BY month_num";
$monthly_result = $conn->query($monthly_sql);
if ($monthly_result) {
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_data[$row['month_num'] - 1] = $row['count']; 
    }
}
// == Weekly Appointments (Last 7 Days) ==
$weekly_labels = [];
$weekly_data = [];
$day_counts = []; 
$weekly_sql = "SELECT DATE(appointment_date) as day, COUNT(id) as count 
               FROM appointments 
               WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND appointment_date <= CURDATE()
               GROUP BY day ORDER BY day ASC";
$weekly_result = $conn->query($weekly_sql);
if ($weekly_result) {
    while ($row = $weekly_result->fetch_assoc()) {
        $day_counts[$row['day']] = $row['count']; 
    }
}
for ($i = 6; $i >= 0; $i--) {
    $date_key = date('Y-m-d', strtotime("-$i days"));
    $day_label = date('D', strtotime("-$i days")); 
    $weekly_labels[] = $day_label;
    $weekly_data[] = $day_counts[$date_key] ?? 0; 
}

// --- 3. PREPARE DATA FOR JAVASCRIPT (Existing code) ---
$chart_data_js = json_encode([
    'monthly_labels' => $monthly_labels,
    'monthly_data' => $monthly_data,
    'weekly_labels' => $weekly_labels,
    'weekly_data' => $weekly_data
]);

$conn->close(); // 💡 Isara ang connection
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Analytics</title>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>


  <style>
    /* --- ADMIN CSS (FINAL UI OVERHAUL) --- */
:root {
    --primary: #1f4e79; 
    --accent: #2a6fb0; 
    --muted: #6b7280;
    --light-bg: #f5f9ff; 
    --card-bg: #ffffff;
    --border-color: #e6eef6;
    --shadow-light: 0 1px 3px rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
    --danger: #e11d48; /* Added */
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Poppins', sans-serif;
    background-color: var(--light-bg);
    margin: 0;
    padding: 0;
    color: #374151;
}
a { text-decoration: none; color: var(--accent); transition: color 0.2s; }
h3 { font-size: 24px; font-weight: 700; margin: 0; color: var(--primary); }
h4 { font-size: 16px; font-weight: 600; margin: 0; color: var(--primary); }

/* --- TOP NAV --- */
.topnav {
    background: var(--card-bg);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    position: sticky;
    top: 0;
    z-index: 1000;
}
.nav-inner {
    max-width: 1600px; 
    margin: 0 auto;
    padding: 12px 30px; 
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 24px;
    font-weight: 800;
    color: var(--primary);
}
.brand-mark {
    width: 30px; height: 30px;
    background: var(--primary);
    color: #fff;
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
}

/* 💡 IDINAGDAG: nav-right wrapper */
.nav-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Profile Dropdown */
.profile {
    display: flex;
    align-items: center;
    cursor: pointer;
    position: relative;
    padding: 8px 12px;
    border-radius: 10px;
    transition: background 0.2s;
}
.profile:hover { background: var(--light-bg); }
.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 12px;
    border: 3px solid var(--accent);
}
.name { font-weight: 600; color: var(--primary); margin-right: 10px; }
.caret { font-size: 14px; color: var(--muted); transition: transform 0.2s; }

.profile-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    list-style: none;
    padding: 5px 0;
    min-width: 220px;
    margin-top: 10px;
    z-index: 10;
    display: none; 
    opacity: 0;
    transform: translateY(-10px);
    transition: opacity 0.2s ease-out, transform 0.2s ease-out;
}
.profile-dropdown.show {
    display: block;
    opacity: 1;
    transform: translateY(0);
}
.profile-dropdown li { list-style: none; }
.profile-dropdown li a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 15px;
    color: var(--primary);
    font-weight: 500;
    transition: background 0.2s;
}
.profile-dropdown li a:hover { background: var(--light-bg); }
.profile-dropdown a.logout { color: var(--danger); font-weight: 600; }
.profile-dropdown a.logout:hover { background: #fee2e2; color: #b91c1c; }


/* === 💡 IDINAGDAG: NOTIFICATION BELL CSS === */
.notif-bell {
  position: relative;
  color: var(--primary); 
  font-size: 20px;
  padding: 10px 12px;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.2s;
}
.notif-bell:hover { background: var(--light-bg); }
.notif-badge {
    position: absolute;
    top: 6px; right: 8px;
    min-width: 16px; height: 16px;
    background: var(--danger); color: #fff;
    border-radius: 8px; padding: 0;
    font-size: 10px; font-weight: 600;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--card-bg); 
    line-height: 1;
}
.notif-dropdown {
  position: absolute;
  right: 0;
  top: 100%; 
  margin-top: 10px;
  width: 360px;
  max-height: 420px;
  overflow-y: auto;
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: 10px;
  box-shadow: 0 12px 30px rgba(20,30,50,0.15);
  display: none;
  flex-direction: column;
  opacity: 0;
  transform: translateY(-10px);
  transition: all 0.25s ease;
  z-index: 100; 
  padding: 0;
}
.notif-dropdown.show { display: flex; opacity: 1; transform: translateY(0); }
.notif-header { padding: 12px 16px; font-weight: 600; color: var(--primary); font-size: 16px; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; background: var(--card-bg); z-index: 1; }
.notif-list { padding: 8px; }
.notif-item { display: block; padding: 12px; border-radius: 8px; transition: background 0.2s; text-decoration: none; color: var(--muted); margin-bottom: 5px; border-bottom: 1px solid #f0f4f9; position: relative; }
.notif-item:last-child { border-bottom: none; margin-bottom: 0; }
.notif-item:hover { background: var(--light-bg); }
.notif-item.unread { background: #eef6ff; }
.notif-item.unread::before { content: ''; position: absolute; left: 6px; top: 50%; transform: translateY(-50%); width: 6px; height: 6px; border-radius: 50%; background: var(--accent); }
.notif-item p { color: #333; font-size: 14px; font-weight: 500; margin: 0 0 4px 0; white-space: normal; padding-left: 12px; }
.notif-item span { font-size: 12px; color: var(--accent); font-weight: 500; padding-left: 12px; }
.notif-empty { padding: 30px 20px; text-align: center; color: var(--muted); }
.notif-empty i { font-size: 28px; margin-bottom: 10px; color: #a0aec0; }
/* === END NOTIFICATION CSS === */


/* --- GRID LAYOUT --- */
.container { padding: 30px; max-width: 1600px; margin: 0 auto; }
.grid { display: grid; grid-template-columns: 240px 1fr; gap: 30px; }

/* --- SIDEBAR --- */
.sidebar { position: sticky; top: 102px; height: calc(100vh - 132px); padding: 20px; background: var(--card-bg); border-radius: 15px; box-shadow: var(--shadow-md); }
.side-item, a.side-item { padding: 14px 20px; margin-bottom: 8px; font-size: 15px; font-weight: 600; color: var(--primary); display: flex; align-items: center; gap: 15px; border-radius: 10px; transition: background 0.2s, color 0.2s, box-shadow 0.2s; text-decoration: none; }
.side-item i { width: 20px; text-align: center; color: var(--muted); transition: color 0.2s; }
.side-item:hover, a.side-item:hover { background: var(--light-bg); color: var(--accent); }
.side-item.active, a.side-item.active { background: linear-gradient(90deg, var(--primary), var(--accent)); color: white; box-shadow: 0 4px 10px rgba(42, 111, 176, 0.3); }
.side-item.active i, a.side-item.active i { color: white; }

/* --- CARDS AND STATS --- */
.card { background: var(--card-bg); padding: 30px; border-radius: 15px; box-shadow: var(--shadow-md); margin-bottom: 30px; }
.card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }

/* == CSS PARA SA ANALYTICS PAGE == */
.small-link { font-size: 14px; font-weight: 700; color: var(--accent); display: flex; align-items: center; gap: 5px; transition: opacity 0.2s; background: var(--light-bg); padding: 8px 12px; border-radius: 8px; }
.small-link:hover { background: #e6eef6; }
.charts { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
.chart-card { background: var(--card-bg); padding: 20px; border-radius: 10px; border: 1px solid var(--border-color); }
.stats { display: grid; grid-template-columns: 1fr; gap: 15px; }
.stat { background: var(--card-bg); border: 1px solid var(--border-color); padding: 20px; border-radius: 12px; display: flex; align-items: center; gap: 15px; transition: transform 0.2s, box-shadow 0.2s; }
.stat:hover { transform: translateY(-3px); box-shadow: var(--shadow-light); }
.stat-icon { font-size: 24px; padding: 15px; border-radius: 50%; color: #fff; }
.stat-icon.doctors { background: var(--accent); }
.stat-icon.patients { background: #10b981; } 
.stat-icon.appointments { background: #f59e0b; } 
.stat-info h4 { font-size: 14px; margin-bottom: 4px; color: var(--muted); font-weight: 500; }
.stat-info p { font-size: 28px; font-weight: 700; margin: 0; color: var(--primary); }

/* --- MOBILE RESPONSIVENESS --- */
@media (max-width: 1200px) {
    .grid { grid-template-columns: 1fr; }
    .sidebar { position: static; height: auto; padding: 10px; margin-bottom: 20px; display: flex; overflow-x: auto; white-space: nowrap; border-radius: 12px; }
    .side-item, a.side-item { flex-shrink: 0; border-bottom: 4px solid transparent; padding: 12px 20px; margin: 0 5px; }
    .side-item.active, a.side-item.active { border-bottom: 4px solid var(--accent); background: var(--light-bg); color: var(--primary); box-shadow: none; }
    .side-item.active i, a.side-item.active i { color: var(--primary); }
    .charts { grid-template-columns: 1fr; gap: 20px; }
}
@media (max-width: 768px) {
    .nav-inner { padding: 12px 15px; }
    .container { padding: 15px; }
    .stats { grid-template-columns: 1fr; gap: 15px; }
    .stat { padding: 15px; }
    .stat-info p { font-size: 28px; }
    .card { padding: 20px; }
    .brand-text { display: none; } 
    .brand-mark { margin-right: 0; }
    .name { display: none; } 
    .charts { grid-template-columns: 1fr; }
}
  </style>
</head>
<body>

  <header class="topnav">
    <div class="nav-inner">
      <div class="brand">
        <span class="brand-mark">+</span>
        <span class="brand-text">CliniCare</span>
      </div>

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
                  
                  // 💡 Link papunta sa admin dashboard appointments tab
                  $link = !empty($notif['link']) ? $notif['link'] : 'admin.php#appointments'; 
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

        <div class="profile" id="profileMenu">
          <img class="avatar" src="<?= htmlspecialchars($adminProfilePicPath); ?>" alt="Admin"> <span class="name"><?= htmlspecialchars($adminName); ?></span>
          <i class="fa-solid fa-chevron-down caret"></i>
          <ul class="profile-dropdown" id="profileDropdown">
            <li><a href="add_doctor.php"><i class="fa-solid fa-user-plus"></i> Add Doctor</a></li>
            <li><a href="../admin_login.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
          </ul>
        </div>
        
      </div> </div>
  </header>

  <div class="container">
    <div class="grid">
      <aside class="sidebar" id="sidebar">
        <a href="admin.php" class="side-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
        <a href="analytics.php" class="side-item active"><i class="fa-solid fa-chart-line"></i> Analytics</a>
        <a href="admin.php#patients" class="side-item"><i class="fa-solid fa-user-group"></i> Patients</a>
        <a href="admin.php#doctors" class="side-item"><i class="fa-solid fa-user-doctor"></i> Doctors</a>
        <a href="admin.php#appointments" class="side-item"><i class="fa-solid fa-calendar-check"></i> Appointments</a>
      </aside>

      <section id="contentArea">
        <div class="card" id="analyticsSection">
          <div class="card-head">
            <h3>Analytics Dashboard</h3>
            <div>
              <a href="#" id="exportButton" class="small-link">
                <i class="fa-solid fa-file-export"></i> Export Report
              </a>
            </div>
          </div>

          <div class="charts">
            
            <div class="chart-card">
              <h4 style="margin:0 0 15px;">Monthly Appointment Trends</h4>
              <div style="height: 300px;"> 
                   <canvas id="lineChart"></canvas>
              </div>
            </div>
            
            <div style="display:flex;flex-direction:column;gap: 20px;">
              <div class="chart-card">
                  <h4 style="margin:0 0 15px;">Doctor vs Patient Ratio</h4>
                  <div style="height: 150px;"> 
                      <canvas id="pieChart"></canvas>
                  </div>
              </div>
              
              <div class="stats">
                <div class="stat">
                  <i class="fa-solid fa-user-doctor stat-icon doctors"></i>
                  <div class="stat-info"><h4>Total Doctors</h4><p id="statDoctors"><?= $stats['total_doctors']; ?></p></div>
                </div>
                <div class="stat">
                  <i class="fa-solid fa-user-group stat-icon patients"></i>
                  <div class="stat-info"><h4>Total Patients</h4><p id="statPatients"><?= $stats['total_patients']; ?></p></div>
                </div>
                <div class="stat">
                  <i class="fa-solid fa-calendar-day stat-icon appointments"></i>
                  <div class="stat-info"><h4>Appointments Today</h4><p id="statToday"><?= $stats['appointments_today']; ?></p></div>
                </div>
              </div>
            </div>

            <div class="chart-card" style="grid-column: 1 / span 2;">
              <h4 style="margin:0 0 15px;">Appointments (Last 7 Days)</h4>
              <div style="height: 300px;"> 
                   <canvas id="barChart"></canvas>
              </div>
            </div>
            
          </div>
        </div>
      </section>
      </div>
  </div>

<script>
    // --- JAVASCRIPT FOR ANALYTICS PAGE (PINAGSAMA) ---
    document.addEventListener('DOMContentLoaded', () => {
        
        // =======================================================
        // === 💡 IDINAGDAG: HEADER DROPDOWN LOGIC (Profile + Notif)
        // =======================================================
        const profileMenu = document.getElementById('profileMenu');
        const profileDropdown = document.getElementById('profileDropdown');
        const profileCaret = profileMenu ? profileMenu.querySelector('.caret') : null; // 💡 Pinalitan ang 'caret'
        const notifBell = document.getElementById("notifBell");
        const notifDropdown = document.getElementById("notifDropdown");
        const notifBadge = notifBell ? notifBell.querySelector(".notif-badge") : null;
        const currentUserId = <?= $adminID; ?>; // 💡 Ginamit ang $adminID

        if (profileMenu && profileDropdown && profileCaret) {
            profileMenu.addEventListener('click', (e) => {
                e.stopPropagation();
                profileDropdown.classList.toggle('show');
                profileCaret.style.transform = profileDropdown.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0deg)';
                // Isara ang notif kung bukas
                if (notifDropdown && notifDropdown.classList.contains('show')) {
                    notifDropdown.classList.remove('show');
                }
            });
        }
        
        if (notifBell && notifDropdown) {
            notifBell.addEventListener("click", (e) => {
                e.stopPropagation();
                notifDropdown.classList.toggle("show");
                // Isara ang profile kung bukas
                if (profileDropdown && profileDropdown.classList.contains("show")) {
                    profileDropdown.classList.remove("show");
                    if (profileCaret) profileCaret.style.transform = "rotate(0deg)";
                }
                
                // === Mark as Read Logic ===
                if (notifDropdown.classList.contains("show") && notifBadge && notifBadge.style.display !== 'none') {
                    
                    fetch('analytics.php?action=mark_read', { // 💡 Tinatawag ang analytics.php
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest' 
                         },
                        body: JSON.stringify({ action: 'mark_read' }) // 'action' lang sapat na
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (notifBadge) notifBadge.style.display = 'none'; 
                            document.querySelectorAll('.notif-item.unread').forEach(item => {
                                item.classList.remove('unread');
                            });
                        } else {
                            console.error("Failed to mark read:", data.error);
                        }
                    })
                    .catch(err => console.error("Error in mark_read request:", err));
                }
            });
        }
        
        // --- 2. Global Click Listener (Pinagsama) ---
        document.addEventListener('click', (e) => {
            // Close profile dropdown
            if (profileDropdown && profileDropdown.classList.contains('show')) {
                 if (!profileMenu.contains(e.target)) {
                    profileDropdown.classList.remove('show');
                    if (profileCaret) profileCaret.style.transform = 'rotate(0deg)';
                }
            }
            // Close notification dropdown
            if (notifBell && notifDropdown && !notifBell.contains(e.target) && !notifDropdown.contains(e.target) && notifDropdown.classList.contains("show")) {
                notifDropdown.classList.remove("show");
            }
        });


        // --- 3. Chart.js Initialization (Existing code mo) ---
        const chartData = <?= $chart_data_js ?>; 
        const totalDoctors = <?= $stats['total_doctors']; ?>;
        const totalPatients = <?= $stats['total_patients']; ?>;

        function initCharts() {
            // --- PIE CHART (Doctor vs Patient) ---
            new Chart(document.getElementById('pieChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Doctors', 'Patients'],
                    datasets: [{
                        data: [totalDoctors, totalPatients],
                        backgroundColor: ['#2a6fb0', '#1f4e79'],
                        hoverBackgroundColor: ['#4a8ecf', '#3a6e9a']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });

            // --- LINE CHART (Monthly Trend) ---
            new Chart(document.getElementById('lineChart'), {
                type: 'line',
                data: {
                    labels: chartData.monthly_labels,
                    datasets: [{
                        label: 'Appointments',
                        data: chartData.monthly_data,
                        borderColor: '#1f4e79',
                        tension: 0.3,
                        fill: true,
                        backgroundColor: 'rgba(31, 78, 121, 0.1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });

            // --- BAR CHART (Weekly Trend) ---
            new Chart(document.getElementById('barChart'), {
                type: 'bar',
                data: {
                    labels: chartData.weekly_labels,
                    datasets: [{
                        label: 'Appointments',
                        data: chartData.weekly_data,
                        backgroundColor: '#2a6fb0',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        }
        
        initCharts();

        // --- 4. PDF EXPORT FUNCTION (Existing code mo) ---
        const exportBtn = document.getElementById('exportButton');
        exportBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const reportElement = document.getElementById('analyticsSection');
            const originalButtonText = exportBtn.innerHTML;
            exportBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Exporting...';
            
            html2canvas(reportElement, {
                scale: 2,
                useCORS: true,
                onclone: (document) => {
                    document.getElementById('exportButton').style.display = 'none';
                }
            }).then(canvas => {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4'); 
                const margin = 10;
                const pdfWidth = pdf.internal.pageSize.getWidth() - (margin * 2);
                const pdfHeight = pdf.internal.pageSize.getHeight() - (margin * 2);
                const imgProps = pdf.getImageProperties(canvas);
                const imgWidth = imgProps.width;
                const imgHeight = imgProps.height;
                const ratio = Math.min(pdfWidth / imgWidth, pdfHeight / imgHeight);
                const finalWidth = imgWidth * ratio;
                const finalHeight = imgHeight * ratio;
                pdf.addImage(canvas.toDataURL('image/png'), 'PNG', margin, margin, finalWidth, finalHeight);
                pdf.save('CliniCare_Analytics_Report.pdf');
                exportBtn.innerHTML = originalButtonText;
            }).catch(err => {
                console.error("Error exporting PDF: ", err);
                alert("Error: Could not export PDF.");
                exportBtn.innerHTML = originalButtonText;
            });
        });

    });
</script>
</body>
</html>