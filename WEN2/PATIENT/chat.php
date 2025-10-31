<?php
// FILE: patient/chat.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// -----------------------------
// 🔹 SESSION START (DAPAT NASA PINAKATAAS)
// -----------------------------
if (session_status() === PHP_SESSION_NONE) session_start();

include '../db_connect.php'; // Correct path

// ==========================================================
// 💡 IDINAGDAG: API ROUTER PARA SA MARK AS READ
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'mark_read') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') { // 💡 Dapat Patient
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        exit;
    }
    
    $patient_id_api = $_SESSION['user_id']; 

    try {
        // Check connection
        if (!isset($conn) || $conn->connect_error) {
             throw new Exception("DB connection failed in API router.");
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
        error_log("Mark as Read Error (chat.php - patient): " . $e->getMessage());
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
    die("Database connection failed in chat.php: " . $conn->connect_error);
}

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') { // 💡 Dapat Patient
    header("Location: ../login.php"); // 💡 Redirect sa patient login
    exit;
}

$patient_id = $_SESSION['user_id'];
$patient_name = $_SESSION['fullname'] ?? 'User';


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
    $stmt_notif->bind_param("ii", $patient_id, $patient_id); 
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
     error_log("Error fetching notifications for patient chat: " . $e->getMessage());
}
// === END NOTIFICATION FETCH ===


// --- Get doctors linked to patient ---
$doctors = [];
$sql_doctors = "SELECT DISTINCT u.id AS doctor_id, u.fullname AS doctor_name, u.specialty 
                FROM appointments a 
                JOIN users u ON a.doctor_id = u.id 
                WHERE a.patient_id = ? 
                  AND u.specialty IS NOT NULL 
                  AND u.specialty != ''
                ORDER BY u.fullname ASC";

$stmt_doctors = $conn->prepare($sql_doctors);
if ($stmt_doctors) {
    $stmt_doctors->bind_param("i", $patient_id);
    $stmt_doctors->execute();
    $result_doctors = $stmt_doctors->get_result();
    while ($row = $result_doctors->fetch_assoc()) {
        $row['profile_picture_path'] = '../PICTURES/doc1.jpg'; // Placeholder Path
        $doctors[] = $row;
    }
    $stmt_doctors->close();
} else {
    error_log("Prepare failed (fetch doctors): (" . $conn->errno . ") " . $conn->error);
}

// --- Logic para sa default selected doctor ---
$selected_doctor_id = null; // Initialize
if (isset($_GET['doctor_id']) && filter_var($_GET['doctor_id'], FILTER_VALIDATE_INT)) {
    $selected_doctor_id_get = (int)$_GET['doctor_id'];
    // Check if the selected doctor_id is valid for this patient
    foreach ($doctors as $doc) {
        if ($doc['doctor_id'] == $selected_doctor_id_get) {
            $selected_doctor_id = $selected_doctor_id_get;
            break;
        }
    }
}
// If still null (or invalid ID was passed), select the first doctor if available
if ($selected_doctor_id === null && !empty($doctors)) {
    $selected_doctor_id = $doctors[0]['doctor_id'];
}

$selected_doctor_name = 'Select a Doctor';
if ($selected_doctor_id !== null) {
    foreach ($doctors as $doc) {
        if ($doc['doctor_id'] == $selected_doctor_id) {
            $selected_doctor_name = $doc['doctor_name'];
            break;
        }
    }
}

// 💡 Close connection bago mag HTML
$conn->close(); 
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Chat</title>
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
      --light-blue-bg: #f0f2f5; /* Messenger background color */
      --danger: #e11d48; /* Added */
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; }
    body {
      font-family: 'Poppins', sans-serif;
      background: var(--bg);
      color: #1f2937;
      min-height: 100vh;
      padding-top: 66px; 
      overflow: hidden; 
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
    
    /* === PROFILE DROPDOWN === */
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
    
    /* CHAT LAYOUT */
    .chat-container {
      max-width: var(--container);
      margin: 20px auto;
      padding: 0 18px; 
      display: grid;
      grid-template-columns: 300px 1fr; 
      height: calc(100vh - 66px - 40px); 
      gap: 20px;
    }
    .chat-list {
      background: var(--card);
      border-radius: var(--radius);
      box-shadow: 0 4px 24px rgba(0,0,0,0.05);
      overflow-y: auto;
      padding: 10px;
      display: flex;
      flex-direction: column;
      gap: 8px; 
    }
    .chat-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 12px;
      border-radius: 10px; 
      cursor: pointer;
      transition: 0.2s background-color;
      border: 1px solid transparent; 
      /* 💡 IDINAGDAG: text-decoration none para sa <a> */
      text-decoration: none; 
    }
    .chat-item:hover { 
        background: #f2f6ff; 
        border-color: #dbeaff; 
    }
    .chat-item.active {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }
    .chat-item.active .chat-info h4 { color: #fff; }
    .chat-item.active .chat-info p { color: rgba(255,255,255,0.8); }
    .chat-item img {
      width: 44px; height: 44px;
      border-radius: 50%;
      object-fit: cover;
      flex-shrink: 0; 
    }
    .chat-info h4 {
      margin: 0;
      font-size: 15px;
      font-weight: 600; 
      color: var(--primary);
      white-space: nowrap; 
      overflow: hidden;
      text-overflow: ellipsis; 
    }
    .chat-info p {
      font-size: 13px;
      color: var(--muted);
      margin: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .chat-list .no-doctors { /* 💡 IDINAGDAG: Style para sa "No doctors" */
        text-align: center; 
        color: var(--muted); 
        padding: 20px;
        font-size: 0.9rem;
    }

    /* MAIN CHAT AREA */
    .chat-area {
      background: var(--card);
      border-radius: var(--radius);
      display: flex;
      flex-direction: column;
      overflow: hidden; 
      box-shadow: 0 4px 24px rgba(0,0,0,0.05);
    }
    .chat-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 20px;
      border-bottom: 1px solid var(--border-color);
      background: var(--bg); 
      flex-shrink: 0; 
    }
    .chat-top h3 {
      margin: 0;
      color: var(--primary);
      font-size: 1.1rem; 
    }
    .chat-box {
      flex-grow: 1; 
      padding: 20px 24px;
      overflow-y: auto; 
      display: flex;
      flex-direction: column; 
      gap: 10px; 
      background: var(--light-blue-bg); 
      scroll-behavior: smooth;
    }
    .chat-box .no-selection, /* 💡 IDINAGDAG: Class para sa initial message */
    .chat-box .loading-msg { /* 💡 IDINAGDAG: Class para sa loading */
        text-align: center; 
        color: var(--muted); 
        margin: auto; /* Center vertically and horizontally */
        padding: 50px 20px;
    }

    /* MESSAGE BUBBLES */
    .msg {
      max-width: 70%; 
      padding: 10px 14px;
      border-radius: 18px; 
      font-size: 14px;
      line-height: 1.4; 
      word-wrap: break-word; 
      box-shadow: 0 1px 1px rgba(0,0,0,0.08); 
      margin-bottom: 2px; 
    }
    .msg.outgoing {
      align-self: flex-end; 
      background: var(--accent); 
      color: #fff;
      border-bottom-right-radius: 4px; 
    }
    .msg.incoming {
      align-self: flex-start; 
      background: #e4e6eb; 
      color: #050505; 
      border-bottom-left-radius: 4px; 
    }
    .msg small {
      display: block;
      font-size: 10px; 
      margin-top: 4px; 
      text-align: right; 
      opacity: 0.6; 
    }
    .msg.outgoing small { color: rgba(255, 255, 255, 0.7); }
    .msg.incoming small { color: var(--muted); }

    /* INPUT AREA */
    .chat-input {
      display: flex;
      gap: 10px;
      border-top: 1px solid var(--border-color);
      padding: 12px 16px;
      background: #fff; 
      flex-shrink: 0; 
    }
    .chat-input input[type="text"] { 
      flex-grow: 1;
      padding: 10px 16px; 
      border-radius: 20px; 
      border: 1px solid #ccd0d5; 
      outline: none;
      font-size: 14px;
    }
    .chat-input input[type="text"]:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 2px rgba(0, 120, 255, 0.1); 
    }
    .chat-input button {
      background: var(--accent);
      border: none;
      color: white;
      width: 40px; 
      height: 40px;
      border-radius: 50%;
      cursor: pointer;
      font-size: 16px;
      transition: background 0.2s;
      flex-shrink: 0;
      display: flex; 
      align-items: center; 
      justify-content: center; 
    }
    .chat-input button:hover {
      background: #0063CC; 
    }
    .chat-input button:disabled { /* 💡 IDINAGDAG: Disabled state */
        background-color: #aeb8c2;
        cursor: not-allowed;
     }
     .chat-input input[type="text"]:disabled { /* 💡 IDINAGDAG: Disabled state */
         background-color: #f0f2f5;
     }

    /* Responsive */
    @media (max-width: 900px) {
      .chat-container {
        grid-template-columns: 1fr; 
        height: calc(100vh - 66px - 20px); 
        padding: 0 10px; 
        margin: 10px auto;
      }
      .chat-list {
        max-height: 150px; 
        border-right: none;
        margin-bottom: 10px;
        /* 💡 IDINAGDAG: Horizontal scroll for list */
        flex-direction: row; 
        overflow-x: auto;
        padding-bottom: 15px; /* Space for scrollbar */
        gap: 10px;
      }
      .chat-item {
          flex-shrink: 0; /* Prevent items from shrinking */
          width: 200px; /* Set a fixed width */
      }
      .chat-area { height: calc(100% - 160px); }
    }
     @media (max-width: 768px) { 
         .nav-links { display: none; } 
         .brand-text {display:none;} 
     }

  </style>
  </head>
<body>
<header class="topnav">
  <div class="nav-inner">
    <div class="brand"><span class="brand-mark">+</span><span class="brand-text">CliniCare</span></div>
    <nav class="nav-links">
      <a href="patient.php" class="nav-link">Home</a>
      
      <a href="chat.php" class="nav-link active">Chat</a>
      
      <a href="appointment.php" class="nav-link">Book Appointment</a>
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
      
      <div class="profile" id="profileMenu"> <img src="../PICTURES/uf.jpg" alt="User avatar" class="avatar">
        <span class="name"><?= htmlspecialchars($patient_name ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
        <i class="fa-solid fa-chevron-down caret"></i>
        <ul class="profile-dropdown" id="profileDropdown"> <li><a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a></li>
          <li><a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</header>

<main class="container chat-container">
  <aside class="chat-list">
    <?php if (empty($doctors)): ?>
        <p class="no-doctors">No doctors found. Book an appointment first.</p> <?php else: ?>
        <?php foreach ($doctors as $doc): 
          $is_active = ($doc['doctor_id'] == $selected_doctor_id) ? 'active' : ''; ?>
          <a href="chat.php?doctor_id=<?= $doc['doctor_id']; ?>" 
             class="chat-item <?= $is_active; ?>" 
             onclick="selectDoctor(event, <?= $doc['doctor_id']; ?>, '<?= htmlspecialchars(addslashes($doc['doctor_name']) ?? '', ENT_QUOTES, 'UTF-8'); ?>')">
            <img src="<?= htmlspecialchars($doc['profile_picture_path'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" alt="Dr. <?= htmlspecialchars($doc['doctor_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="chat-info">
              <h4>Dr. <?= htmlspecialchars($doc['doctor_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h4>
              <p><?= htmlspecialchars($doc['specialty'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
          </a>
        <?php endforeach; ?>
    <?php endif; ?>
  </aside>

  <section class="chat-area">
    <div class="chat-top">
      <h3 id="chatWith"> <?= $selected_doctor_id ? 'Dr. ' . htmlspecialchars($selected_doctor_name ?? '', ENT_QUOTES, 'UTF-8') : 'Select a Doctor'; ?></h3>
    </div>
    <div class="chat-box" id="chatBox">
       <?php if (!$selected_doctor_id): ?>
            <div class="no-selection">Select a doctor from the list to start chatting.</div>
       <?php else: ?>
            <div class="loading-msg" id="loadingMessages"> 
                <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i>
                <p>Loading messages...</p>
            </div>
       <?php endif; ?>
    </div>

    <form class="chat-input" id="chatForm" <?= !$selected_doctor_id ? 'style="display:none;"' : ''; ?>>
      <input type="hidden" id="recipientId" value="<?= htmlspecialchars($selected_doctor_id ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
      <input type="text" id="chatInput" placeholder="Type a message..." autocomplete="off" required <?= !$selected_doctor_id ? 'disabled' : ''; /* 💡 Added disabled */ ?> />
      <button type="submit" aria-label="Send Message" <?= !$selected_doctor_id ? 'disabled' : ''; /* 💡 Added disabled */ ?>><i class="fa-solid fa-paper-plane"></i></button>
    </form>
  </section>
</main>

<script>
  // === 1. PHP Variables ===
  const CURRENT_USER_ID = <?= $patient_id; ?>; 
  const SELECTED_DOCTOR_ID = <?= $selected_doctor_id ? $selected_doctor_id : 'null'; ?>; 

  // === 2. Global Chat Variables & Functions ===
  let chatBox;
  let chatInput;
  let chatForm;
  let recipientInput; // Pinalitan ang pangalan para mas generic
  let messagePolling = null; 
  let currentlyLoading = false; 

  window.loadMessages = async function() {
    if (currentlyLoading || typeof CURRENT_USER_ID === 'undefined' || !recipientInput || !recipientInput.value || recipientInput.value === 'null' || recipientInput.value === '') {
        return; 
    }
    currentlyLoading = true;
    const other_id = recipientInput.value; // Gamitin ang generic name
    
    try {
      // 💡 Siguraduhing tama ang path ng get_messages.php mo
      const res = await fetch(`../get_messages.php?other_id=${other_id}`); 
      if (!res.ok) throw new Error(`Failed to fetch messages. Status: ${res.status} ${res.statusText}`);
      
      const messages = await res.json();
      const isScrolledToBottom = chatBox.scrollHeight - chatBox.clientHeight <= chatBox.scrollTop + 50;
      
      chatBox.innerHTML = ""; 

      if (messages.length === 0) {
        chatBox.innerHTML = `<div class="no-selection">No messages yet. Start the conversation!</div>`; // Ginamit ang class
      } else {
        messages.forEach(msg => {
          const div = document.createElement("div");
          const senderId = Number(msg.sender_id); 
          // 💡 AYOS: Patient ang outgoing, Doctor ang incoming
          div.className = senderId === CURRENT_USER_ID ? "msg outgoing" : "msg incoming"; 
          
          let timeString = '--:-- --';
          try {
              const timestampValue = msg.timestamp || msg.created_at; 
              if (timestampValue) {
                  const time = new Date(timestampValue.replace(' ', 'T') + 'Z'); 
                   if (!isNaN(time.getTime())) { 
                      timeString = time.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
                   }
              }
          } catch(dateError) { /* Keep default timeString */ }

          const msgTextDiv = document.createElement("div");
          msgTextDiv.textContent = msg.message; 

          const msgTimeDiv = document.createElement("small"); 
          msgTimeDiv.textContent = timeString;

          div.appendChild(msgTextDiv);
          div.appendChild(msgTimeDiv);
          chatBox.appendChild(div);
        });
        
        if (isScrolledToBottom) {
             // Delay scroll slightly to allow rendering
             setTimeout(() => { chatBox.scrollTop = chatBox.scrollHeight; }, 50); 
        }
      }
    } catch (err) {
      console.error("Error loading messages:", err);
      chatBox.innerHTML = `<div class="no-selection" style="color:red;">Error loading messages. Please try again later.</div>`; // Ginamit ang class
    } finally {
        currentlyLoading = false; 
    }
  }

  // 💡 AYOS: Ginawang generic ang function name
  window.selectDoctor = (event, doctorId, doctorName) => {
    event.preventDefault(); // Prevent page reload for <a> tag

    document.querySelectorAll(".chat-list .chat-item").forEach(item => item.classList.remove("active"));
    
    const clickedElement = event.currentTarget; 
    clickedElement.classList.add("active");
    
    document.getElementById("chatWith").textContent = "Dr. " + doctorName; // Add "Dr." prefix
    recipientInput.value = doctorId; // Set the hidden input
    
    // Enable form
    chatForm.style.display = 'flex';
    chatInput.disabled = false;
    chatForm.querySelector('button').disabled = false;

    if (messagePolling) {
        clearInterval(messagePolling); // Stop previous polling
    }
    
    // Show loading message
    chatBox.innerHTML = `<div class="loading-msg" id="loadingMessages">
                           <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i>
                           <p>Loading messages...</p>
                         </div>`; 
                         
    // Load messages then start polling
    window.loadMessages().then(() => {
        // Start polling only after initial load is complete
        messagePolling = setInterval(window.loadMessages, 3000); // Poll every 3 seconds
    });
    
    // Update URL without reloading
    const url = new URL(window.location);
    url.searchParams.set('doctor_id', doctorId); // Use doctor_id for consistency
    window.history.pushState({}, '', url);
  }

  // === 3. DOMContentLoaded ===
  document.addEventListener("DOMContentLoaded", () => {
      
      // --- PART A: HEADER/DROPDOWN LOGIC ---
      const profile = document.getElementById("profileMenu"); // Ginamit ang ID mula sa HTML
      const dropdown = document.getElementById("profileDropdown"); // Ginamit ang ID mula sa HTML
      const caret = profile ? profile.querySelector(".caret") : null;
      const notifBell = document.getElementById("notifBell");
      const notifDropdown = document.getElementById("notifDropdown");
      const notifBadge = notifBell ? notifBell.querySelector(".notif-badge") : null;
      // const currentUserId = CURRENT_USER_ID; // Defined na sa taas

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
                  
                  // 💡 Tinatawag ang chat.php na may API router
                  fetch('chat.php?action=mark_read', { 
                      method: 'POST',
                      headers: { 
                          'Content-Type': 'application/json',
                          'X-Requested-With': 'XMLHttpRequest' // Helps PHP distinguish AJAX
                       },
                      // Send the current user ID (patient ID)
                      body: JSON.stringify({ user_id: CURRENT_USER_ID }) 
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

      // --- PART B: CHAT LOGIC ---
      chatBox = document.getElementById("chatBox");
      chatInput = document.getElementById("chatInput");
      chatForm = document.getElementById("chatForm");
      recipientInput = document.getElementById("recipientId"); // Pinalitan ang pangalan

      if (typeof CURRENT_USER_ID === 'undefined' || !chatBox || !chatForm || !recipientInput) {
          console.error("Critical chat elements not found.");
          if(chatBox) chatBox.innerHTML = `<div class="no-selection" style="color:red;">Chat initialization failed.</div>`; // Ginamit ang class
          return;
      }
      
      chatForm.addEventListener("submit", async e => {
          e.preventDefault();
          const receiver_id = recipientInput.value; // Gamitin ang generic name
          const message = chatInput.value.trim();
          
          if (!message || !receiver_id || receiver_id === 'null' || receiver_id === '') {
              return;
          }

          const now = new Date();
          const timeString = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
          const originalMessage = message; 
          
          // --- Optimistic UI Update ---
          const tempDiv = document.createElement("div");
          tempDiv.className = "msg outgoing"; // Patient is always outgoing here
          const msgTextDiv = document.createElement("div");
          msgTextDiv.textContent = originalMessage; 
          const msgTimeDiv = document.createElement("small"); 
          msgTimeDiv.textContent = timeString + " (Sending...)"; 
          tempDiv.appendChild(msgTextDiv);
          tempDiv.appendChild(msgTimeDiv);

          const placeholder = chatBox.querySelector('.no-selection, .loading-msg'); // Hanapin ang placeholder
          if (placeholder) {
              chatBox.innerHTML = ''; // Alisin kung meron
          }
          
          chatBox.appendChild(tempDiv);
          chatBox.scrollTop = chatBox.scrollHeight;
          chatInput.value = ""; 
          // --- End Optimistic Update ---

          try {
            // 💡 Siguraduhing tama ang path ng send_message.php
            const res = await fetch("../send_message.php", { 
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ 
                  // 💡 Siguraduhing tama ang parameter names na inaasahan ng send_message.php
                  receiver_id: receiver_id, 
                  message: originalMessage 
              })
            });
            
            if (!res.ok) {
                throw new Error(`Server responded with status: ${res.status}`);
            }

            const data = await res.json(); 

            if (data.success) { 
               msgTimeDiv.textContent = timeString; // Remove "(Sending...)"
               // Trigger immediate reload after sending
               setTimeout(window.loadMessages, 100); 
            } else {
              console.error("Failed to send message:", data.error);
              msgTimeDiv.textContent = timeString + " (Failed)";
              msgTimeDiv.style.color = "red";
            }
          } catch (error) {
            console.error("Connection Error:", error);
            msgTimeDiv.textContent = timeString + " (Connection Error)"; 
            msgTimeDiv.style.color = "orange";
          }
      });

      // === Initial Load & Polling ===
      const initialRecipientId = recipientInput.value;
      if (initialRecipientId && initialRecipientId !== 'null' && initialRecipientId !== '') {
        window.loadMessages(); // Load initial messages
        messagePolling = setInterval(window.loadMessages, 3000); // Start polling
      } else {
        // No need to set innerHTML here, handled by PHP already
        // Just ensure form is disabled
        chatForm.style.display = 'none';
        chatInput.disabled = true;
        chatForm.querySelector('button').disabled = true;
      }
  });
</script>   
</body>
</html>