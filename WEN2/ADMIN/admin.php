<?php
// ==========================================================
// === 💡 API ROUTER (Dito lahat ng POST requests)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    
    session_start();
    include '../db_connect.php'; 
    header('Content-Type: application/json');

    // Security Check: Dapat admin
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Authentication failed.']);
        exit;
    }
    
    $admin_id = $_SESSION['user_id'];
    $data = json_decode(file_get_contents('php://input'), true);

    // --- Action: Delete User ---
    if (isset($data['action']) && $data['action'] === 'delete_user' && isset($data['user_id'])) {
        $user_id_to_delete = $data['user_id'];
        $role_to_delete = $data['role'] ?? ''; 

        if ($user_id_to_delete == $admin_id) {
            echo json_encode(['success' => false, 'error' => 'You cannot remove your own account.']);
            exit;
        }
        try {
            if ($role_to_delete === 'doctor' || $role_to_delete === 'patient') {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = ?");
                $stmt->bind_param("is", $user_id_to_delete, $role_to_delete);
            } else {
                 echo json_encode(['success' => false, 'error' => 'Invalid role for deletion.']);
                 exit;
            }
            if ($stmt->execute()) {
                echo json_encode(['success' => $stmt->affected_rows > 0]);
            } else { throw new Exception("Execute failed: " . $stmt->error); }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Delete User Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error.']);
        }
        $conn->close();
        exit; 
    }
    
    // --- 💡 BAGONG Action: Update Appointment Status ---
    if (isset($data['action']) && $data['action'] === 'update_status' && isset($data['appointment_id'])) {
        $appt_id = $data['appointment_id'];
        $new_status = $data['status']; // 'Confirmed' or 'Cancelled'

        // Validation
        if ($new_status !== 'Confirmed' && $new_status !== 'Cancelled') {
             echo json_encode(['success' => false, 'error' => 'Invalid status.']);
             exit;
        }
        
        try {
            $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $appt_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => $stmt->affected_rows > 0, 'new_status' => $new_status]);
                // Dito mo pwedeng idagdag 'yung pag-notify pabalik sa patient
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Update Status Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error.']);
        }
        $conn->close();
        exit;
    }
    
    // --- 💡 BAGONG Action: Mark Notifications as Read ---
    if (isset($data['action']) && $data['action'] === 'mark_read') {
         try {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Mark as Read Error (Admin): " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error.']);
        }
        $conn->close();
        exit;
    }

    // Fallback
    echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
    exit;
}
// === END API ROUTER ===


// ==========================================================
// === NORMAL PAGE LOAD (Mula dito pababa)
// ==========================================================
session_start(); 

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin_login.php?error=" . urlencode("Access Denied. Please login as Admin."));
    exit;
}

include '../db_connect.php'; 

// Check kung gumagana si $conn
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed in admin.php: " . $conn->connect_error);
}

$adminName = $_SESSION['fullname'] ?? 'Admin User';
$adminID = $_SESSION['user_id'];
$adminProfilePicPath = '../PICTURES/default_user.png'; // Default image


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
     error_log("Admin Fetch Notifications Error: " . $e->getMessage());
}
// === END NOTIFICATION FETCH ===


// --- 1. FETCH DASHBOARD STATS ---
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

// --- 2. FETCH RECORDS FOR TABLES ---
$patients_result = $conn->query("SELECT id, fullname, username, email FROM users WHERE role='patient' ORDER BY fullname ASC");
$doctors_result = $conn->query("SELECT id, fullname, specialty, email FROM users WHERE role='doctor' ORDER BY fullname ASC");
$appointments_sql = "
    SELECT 
        a.id, 
        COALESCE(p.fullname, '[Patient Removed]') AS patient_name, 
        COALESCE(d.fullname, '[Doctor Removed]') AS doctor_name, 
        a.appointment_date, 
        a.appointment_time, 
        a.status 
    FROM appointments a
    LEFT JOIN users p ON a.patient_id = p.id
    LEFT JOIN users d ON a.doctor_id = d.id
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT 100";
$appointments_result = $conn->query($appointments_sql);

$conn->close(); // Isara ang connection
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Admin Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  
  <style>
    :root {
        --primary: #1f4e79; 
        --accent: #2a6fb0; 
        --muted: #6b7280;
        --light-bg: #f5f9ff; 
        --card-bg: #ffffff;
        --border-color: #e6eef6;
        --shadow-light: 0 1px 3px rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
        --danger: #e11d48; /* Added for notifs */
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
      color: var(--primary); /* Itim/Blue para sa white background */
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
        border: 2px solid var(--card-bg); /* White background border */
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
    .notif-header {
      padding: 12px 16px;
      font-weight: 600;
      color: var(--primary);
      font-size: 16px;
      border-bottom: 1px solid var(--border-color);
      position: sticky;
      top: 0;
      background: var(--card-bg);
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
    .notif-item:hover { background: var(--light-bg); }
    .notif-item.unread { background: #eef6ff; }
    .notif-item.unread::before { content: ''; position: absolute; left: 6px; top: 50%; transform: translateY(-50%); width: 6px; height: 6px; border-radius: 50%; background: var(--accent); }
    .notif-item p { color: #333; font-size: 14px; font-weight: 500; margin: 0 0 4px 0; white-space: normal; padding-left: 12px; }
    .notif-item span { font-size: 12px; color: var(--accent); font-weight: 500; padding-left: 12px; }
    .notif-empty { padding: 30px 20px; text-align: center; color: var(--muted); }
    .notif-empty i { font-size: 28px; margin-bottom: 10px; color: #a0aec0; }
    /* === END NOTIFICATION CSS === */
    
    .container { padding: 30px; max-width: 1600px; margin: 0 auto; }
    .grid { display: grid; grid-template-columns: 240px 1fr; gap: 30px; }
    .sidebar { position: sticky; top: 102px; height: calc(100vh - 132px); padding: 20px; background: var(--card-bg); border-radius: 15px; box-shadow: var(--shadow-md); }
    .side-item, a.side-item { padding: 14px 20px; margin-bottom: 8px; font-size: 15px; font-weight: 600; color: var(--primary); display: flex; align-items: center; gap: 15px; border-radius: 10px; transition: background 0.2s, color 0.2s, box-shadow 0.2s; text-decoration: none; }
    .side-item i { width: 20px; text-align: center; color: var(--muted); transition: color 0.2s; }
    .side-item:hover, a.side-item:hover { background: var(--light-bg); color: var(--accent); }
    .side-item.active, a.side-item.active { background: linear-gradient(90deg, var(--primary), var(--accent)); color: white; box-shadow: 0 4px 10px rgba(42, 111, 176, 0.3); }
    .side-item.active i, a.side-item.active i { color: white; }
    .card { background: var(--card-bg); padding: 30px; border-radius: 15px; box-shadow: var(--shadow-md); margin-bottom: 30px; }
    .card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
    .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; }
    .stat { background: var(--card-bg); border: 1px solid var(--border-color); padding: 25px; border-radius: 12px; display: flex; align-items: center; gap: 20px; transition: transform 0.2s, box-shadow 0.2s; }
    .stat:hover { transform: translateY(-5px); box-shadow: var(--shadow-md); }
    .stat-icon { font-size: 28px; padding: 18px; border-radius: 50%; color: #fff; }
    .stat-icon.doctors { background: var(--accent); }
    .stat-icon.patients { background: #10b981; }
    .stat-icon.appointments { background: #f59e0b; }
    .stat-info h4 { font-size: 14px; margin-bottom: 6px; color: var(--muted); font-weight: 500; }
    .stat-info p { font-size: 32px; font-weight: 700; margin: 0; color: var(--primary); }
    .table-responsive { overflow-x: auto; }
    .table { width: 100%; border-collapse: separate; border-spacing: 0 5px; font-size: 14px; min-width: 800px; }
    .table th { padding: 15px 20px; text-align: left; background-color: var(--light-bg); color: var(--primary); font-weight: 600; text-transform: uppercase; font-size: 12px; border-bottom: 2px solid var(--border-color); }
    .table td { padding: 18px 20px; text-align: left; background: var(--card-bg); border-bottom: 1px solid var(--border-color); }
    .table tr { transition: transform 0.2s, box-shadow 0.2s; }
    .table tbody tr:hover { transform: scale(1.01); box-shadow: 0 4px 12px rgba(0,0,0,0.05); z-index: 5; position: relative; }
    .table th:first-child { border-radius: 10px 0 0 10px; }
    .table th:last-child { border-radius: 0 10px 10px 0; }
    .table td:first-child { border-radius: 10px 0 0 10px; }
    .table td:last-child { border-radius: 0 10px 10px 0; }
    .status-badge { padding: 6px 12px; border-radius: 99px; font-weight: 600; font-size: 12px; display: inline-block; transition: background 0.3s, color 0.3s; /* Added transition */ }
    .status-badge.pending { background: #fef3c7; color: #92400e; }
    .status-badge.confirmed { background: #dcfce7; color: #166534; }
    .status-badge.cancelled { background: #fee2e2; color: #991b1b; }
    .status-badge.rescheduled { background: #e0f2fe; color: #075985; }
    .status-badge.completed { background: #f3f4f6; color: #4b5563; }
    .btn.secondary { background: var(--light-bg); color: var(--primary); border: 1px solid var(--border-color); padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background 0.2s; }
    .btn.secondary:hover { background: #e6eef6; }
    .action-dropdown { position: relative; display: inline-block; }
    .dropdown-menu { position: absolute; right: 0; top: 100%; z-index: 20; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); min-width: 160px; display: none; padding: 5px 0; margin-top: 5px; }
    .dropdown-menu button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 10px 15px; border: none; background: none; text-align: left; cursor: pointer; font-size: 14px; transition: background 0.2s; color: #374151; }
    .dropdown-menu button:hover { background: var(--light-bg); color: var(--primary); }
    .dropdown-menu button.danger { color: #b91c1c; }
    .dropdown-menu button.danger:hover { background: #fee2e2; }
    .small-link { font-size: 14px; font-weight: 600; color: var(--accent); }
    .small-link:hover { text-decoration: underline; }
    @media (max-width: 1024px) {
        .grid { grid-template-columns: 1fr; gap: 20px; }
        .sidebar { position: static; height: auto; padding: 10px; margin-bottom: 20px; display: flex; overflow-x: auto; white-space: nowrap; border-radius: 12px; }
        .side-item, a.side-item { flex-shrink: 0; border-bottom: 4px solid transparent; padding: 12px 20px; margin: 0 5px; }
        .side-item.active, a.side-item.active { border-bottom: 4px solid var(--accent); background: var(--light-bg); color: var(--primary); box-shadow: none; }
        .side-item.active i, a.side-item.active i { color: var(--primary); }
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
                  $link = !empty($notif['link']) ? $notif['link'] : '#'; 
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
          <img class="avatar" src="<?= $adminProfilePicPath; ?>" alt="<?= htmlspecialchars($adminName); ?>"> 
          <span class="name"><?= htmlspecialchars($adminName); ?></span>
          <i class="fa-solid fa-chevron-down caret"></i>

          <ul class="profile-dropdown" id="profileDropdown">
            <li><a href="add_doctor.php"><i class="fa-solid fa-user-plus"></i> Add Doctor</a></li>
            <li><a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li> 
          </ul>
        </div>
        
      </div> </div>
  </header>

  <div class="container">
    <div class="grid">
      <aside class="sidebar" id="sidebar">
        <div class="side-item active" data-section="dashboard"><i class="fa-solid fa-table-columns"></i> Dashboard</div>
        <a href="analytics.php" class="side-item"><i class="fa-solid fa-chart-line"></i> Analytics</a>
        <div class="side-item" data-section="patients"><i class="fa-solid fa-user-group"></i> Patients</div>
        <div class="side-item" data-section="doctors"><i class="fa-solid fa-user-doctor"></i> Doctors</div>
        <div class="side-item" data-section="appointments"><i class="fa-solid fa-calendar-check"></i> Appointments</div>
      </aside>

      <section id="contentArea">
      
        <div class="card" id="dashboardSection">
          <div class="card-head">
            <h3>Dashboard</h3>
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
        
        <div class="card" id="patientsSection" style="display:none">
          <div class="card-head">
            <h3>Patients Management</h3>
          </div>
          <div class="table-responsive">
            <table class="table" id="patientsTable">
              <thead>
                <tr><th>ID</th><th>Full Name</th><th>Username</th><th>Email</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if ($patients_result && $patients_result->num_rows > 0): ?>
                  <?php while ($row = $patients_result->fetch_assoc()): ?>
                    <tr data-id="<?= $row['id'] ?>"> 
                      <td><?= htmlspecialchars($row['id']); ?></td>
                      <td><?= htmlspecialchars($row['fullname']); ?></td>
                      <td><?= htmlspecialchars($row['username']); ?></td>
                      <td><?= htmlspecialchars($row['email']); ?></td>
                      <td>
                        <div class="action-dropdown">
                          <button class="btn secondary" onclick="toggleDropdown(this)">Actions <i class='fa-solid fa-chevron-down' style='margin-left:8px;font-size:12px;'></i></button>
                          <div class="dropdown-menu">
                            <button onclick="removeUser(<?= $row['id'] ?>, 'patient')" class="danger"><i class="fa-solid fa-trash"></i> Remove</button>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="5" style="text-align:center; padding: 20px;">No patient records found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card" id="doctorsSection" style="display:none">
          <div class="card-head">
            <h3>Doctors Management</h3>
             <div><a class="small-link" href="add_doctor.php">Add New Doctor</a></div>
            </div>
          <div class="table-responsive">
            <table class="table" id="doctorsTable">
              <thead>
                <tr><th>ID</th><th>Doctor</th><th>Specialty</th><th>Email</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if ($doctors_result && $doctors_result->num_rows > 0): ?>
                  <?php while ($row = $doctors_result->fetch_assoc()): ?>
                    <tr data-id="<?= $row['id'] ?>"> 
                      <td><?= htmlspecialchars($row['id']); ?></td>
                      <td><?= htmlspecialchars($row['fullname']); ?></td>
                      <td><?= htmlspecialchars($row['specialty'] ?? 'N/A'); ?></td>
                      <td><?= htmlspecialchars($row['email']); ?></td>
                      <td>
                        <div class="action-dropdown">
                          <button class="btn secondary" onclick="toggleDropdown(this)">Actions <i class='fa-solid fa-chevron-down' style='margin-left:8px;font-size:12px;'></i></button>
                          <div class="dropdown-menu">
                            <button onclick="removeUser(<?= $row['id'] ?>, 'doctor')" class="danger"><i class="fa-solid fa-trash"></i> Remove</button>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="5" style="text-align:center; padding: 20px;">No doctor records found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card" id="appointmentsSection" style="display:none">
          <div class="card-head">
            <h3>All Appointments</h3>
          </div>
          <div class="table-responsive">
            <table class="table" id="appointmentsTable">
              <thead>
                <tr><th>Patient</th><th>Doctor</th><th>Date</th><th>Time</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if ($appointments_result && $appointments_result->num_rows > 0): ?>
                  <?php while ($row = $appointments_result->fetch_assoc()): ?>
                    <tr data-id="<?= $row['id'] ?>">
                      <td><?= htmlspecialchars($row['patient_name']); ?></td>
                      <td><?= htmlspecialchars($row['doctor_name']); ?></td>
                      <td><?= date("M d, Y", strtotime($row['appointment_date'])); ?></td>
                      <td><?= date("h:i A", strtotime($row['appointment_time'])); ?></td>
                      <td>
                        <span class="status-badge <?= strtolower(htmlspecialchars($row['status'])); ?>" 
                              id="status-badge-<?= $row['id'] ?>">
                          <?= ucfirst($row['status']); ?>
                        </span>
                      </td>
                      <td>
                        <div class="action-dropdown">
                          <button class="btn secondary" onclick="toggleDropdown(this)">Manage 
                            <i class='fa-solid fa-chevron-down' style='margin-left:8px;font-size:12px;'></i>
                          </button>
                          <div class="dropdown-menu">
                            <button onclick="updateAppointmentStatus(this, <?= $row['id'] ?>, 'Confirmed')"><i class="fa-solid fa-check"></i> Confirm</button>
                            <button onclick="updateAppointmentStatus(this, <?= $row['id'] ?>, 'Cancelled')" class="danger"><i class="fa-solid fa-times"></i> Cancel</button>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="6" style="text-align:center; padding: 20px;">No appointments found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </section>
    </div>
  </div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- Sidebar Logic (Walang binago) ---
        const sidebarItems = document.querySelectorAll('.sidebar .side-item, .sidebar a.side-item');
        const contentArea = document.getElementById('contentArea');
        const sections = contentArea.querySelectorAll('.card[id$="Section"]'); 
        function switchSection(targetSectionId) {
            sections.forEach(section => { section.style.display = 'none'; });
            const targetSection = document.getElementById(targetSectionId + 'Section');
            if (targetSection) { targetSection.style.display = 'block'; }
            sidebarItems.forEach(item => { item.classList.remove('active'); });
            const activeItem = document.querySelector(`.side-item[data-section="${targetSectionId}"]`);
            if (activeItem) { activeItem.classList.add('active'); }
        }
        sidebarItems.forEach(item => {
            if (item.tagName === 'DIV' && item.hasAttribute('data-section')) {
                item.addEventListener('click', () => {
                    const targetSectionId = item.getAttribute('data-section');
                    window.location.hash = targetSectionId;
                    switchSection(targetSectionId);
                });
            }
        });
        const currentHash = window.location.hash.substring(1);
        if (currentHash) { switchSection(currentHash); } else { switchSection('dashboard'); }

        // =======================================================
        // === 💡 IDINAGDAG: Header Dropdown Logic (Profile + Notif)
        // =======================================================
        const profileMenu = document.getElementById('profileMenu');
        const profileDropdown = document.getElementById('profileDropdown');
        const profileCaret = profileMenu ? profileMenu.querySelector('.caret') : null;
        const notifBell = document.getElementById("notifBell");
        const notifDropdown = document.getElementById("notifDropdown");
        const notifBadge = notifBell ? notifBell.querySelector(".notif-badge") : null;
        const currentUserId = <?= $adminID; ?>; // Para magamit sa fetch

        if (profileMenu && profileDropdown && profileCaret) {
            profileMenu.addEventListener('click', (e) => {
                e.stopPropagation();
                profileDropdown.classList.toggle('show');
                profileCaret.style.transform = profileDropdown.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0deg)';
                if (notifDropdown && notifDropdown.classList.contains('show')) {
                    notifDropdown.classList.remove('show');
                }
            });
        }
        
        if (notifBell && notifDropdown) {
            notifBell.addEventListener("click", (e) => {
                e.stopPropagation();
                notifDropdown.classList.toggle("show");
                if (profileDropdown && profileDropdown.classList.contains("show")) {
                    profileDropdown.classList.remove("show");
                    if (profileCaret) profileCaret.style.transform = "rotate(0deg)";
                }
                
                // === Mark as Read Logic ===
                if (notifDropdown.classList.contains("show") && notifBadge && notifBadge.style.display !== 'none') {
                    fetch('admin.php', { // Tinatawag ang sariling file
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest' 
                         },
                        body: JSON.stringify({ 
                            action: 'mark_read',
                            user_id: currentUserId // Hindi na kailangan, kukunin sa session
                        }) 
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (notifBadge) notifBadge.style.display = 'none'; 
                            document.querySelectorAll('.notif-item.unread').forEach(item => {
                                item.classList.remove('unread');
                            });
                        }
                    })
                    .catch(err => console.error("Error in mark_read request:", err));
                }
            });
        }
        
        // --- General Click Listeners (Walang binago) ---
        window.toggleDropdown = function(button) {
            const dropdown = button.nextElementSibling;
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu !== dropdown && menu.style.display === 'block') {
                    menu.style.display = 'none';
                }
            });
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }
        
        document.addEventListener('click', (e) => {
            // Para sa profile dropdown
            if (profileDropdown && profileDropdown.classList.contains('show')) {
                 if (!profileMenu.contains(e.target)) {
                    profileDropdown.classList.remove('show');
                    if (profileCaret) profileCaret.style.transform = 'rotate(0deg)';
                }
            }
            // Para sa notification dropdown
            if (notifDropdown && notifDropdown.classList.contains('show')) {
                 if (!notifBell.contains(e.target) && !notifDropdown.contains(e.target)) {
                    notifDropdown.classList.remove('show');
                }
            }
            // Para sa action dropdowns
            document.querySelectorAll('.action-dropdown').forEach(dropdownContainer => {
                const menu = dropdownContainer.querySelector('.dropdown-menu');
                const button = dropdownContainer.querySelector('.btn');
                if (menu && menu.style.display === 'block') {
                    if (!button.contains(e.target) && !menu.contains(e.target)) {
                        menu.style.display = 'none';
                    }
                }
            });
        });

        // --- removeUser Function (Walang binago) ---
        window.removeUser = async function(id, role) {
            if (confirm(`Are you sure you want to remove this ${role} (ID: ${id})? This action CANNOT be undone.`)) {
                try {
                    const response = await fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            action: 'delete_user',
                            user_id: id,
                            role: role
                        })
                    });
                    if (!response.ok) throw new Error(`Network error: ${response.statusText}`);
                    const result = await response.json();
                    if (result.success) {
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) {
                            row.style.transition = 'opacity 0.5s ease';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 500);
                        }
                        alert(`${role} (ID: ${id}) has been removed.`);
                        // Dito mo pwedeng i-update 'yung stats
                    } else {
                        alert(`Error: ${result.error}`);
                    }
                } catch (error) {
                    console.error('Fetch Error:', error);
                    alert(`An error occurred: ${error.message}`);
                }
            }
        }
        
        // =======================================================
        // === 💡 AYOS: Pinalitan ang laman ng updateAppointmentStatus
        // =======================================================
        window.updateAppointmentStatus = async function(buttonElement, id, status) {
            // Kunin 'yung dropdown menu
            const dropdownMenu = buttonElement.closest('.dropdown-menu');
            
            if (confirm(`Are you sure you want to ${status} appointment ID: ${id}?`)) {
                try {
                    const response = await fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            action: 'update_status',
                            appointment_id: id,
                            status: status // Ipadala 'yung 'Confirmed' or 'Cancelled'
                        })
                    });
                    
                    if (!response.ok) throw new Error(`Network error: ${response.statusText}`);
                    const result = await response.json();
                    
                    if (result.success) {
                        // Update the badge UI
                        const badge = document.getElementById(`status-badge-${id}`);
                        if(badge) {
                            // Alisin lahat ng lumang status classes
                            badge.classList.remove('pending', 'confirmed', 'cancelled', 'rescheduled', 'completed');
                            // Idagdag 'yung bagong status class
                            badge.classList.add(status.toLowerCase()); // 'confirmed' or 'cancelled'
                            // Palitan 'yung text
                            badge.textContent = status; // 'Confirmed' or 'Cancelled'
                        }
                        alert(`Appointment ${id} has been ${status}.`);
                        // Isara 'yung maliit na dropdown
                        if(dropdownMenu) dropdownMenu.style.display = 'none';
                    } else {
                        alert(`Error: ${result.error}`);
                    }
                    
                } catch (error) {
                    console.error('Fetch Error:', error);
                    alert(`An error occurred: ${error.message}`);
                }
            }
        }
    });
</script>
</body>
</html>