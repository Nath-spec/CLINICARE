<?php
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
        error_log("Mark as Read Error (record.php - patient): " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database exception.']);
    }
    
    if (isset($conn)) $conn->close();
    exit; // Itigil ang script
}
// === END API ROUTER ===


// ==========================================================
// 💡 IDINAGDAG: DB Connection Check for page load
// ==========================================================
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed in record.php: " . $conn->connect_error);
}

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../login.php"); 
    exit;
}

$patient_id = $_SESSION['user_id'];
$patient_name = $_SESSION['fullname'] ?? 'Patient User'; // Use session fullname

// --- FETCH PATIENT RECORDS (Tama na 'to) ---
$records = [];
$sql = "SELECT 
            a.id AS appointment_id, 
            a.appointment_date, 
            a.appointment_time, 
            a.status, 
            a.reason, 
            a.diagnosis,
            a.prescription,
            u.fullname AS doctor_name, 
            u.specialty AS doctor_specialty
        FROM appointments a
        JOIN users u ON a.doctor_id = u.id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC";

$stmt = $conn->prepare($sql);
if ($stmt) { // Check prepare
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
      $records[] = $row;
    }
    $stmt->close();
} else {
    error_log("Failed to prepare records statement: " . $conn->error);
}


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
if ($stmt_notif){ // Check prepare
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

// 💡 Close connection bago mag HTML
$conn->close(); 
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Medical Records</title>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>


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
      /* Status Colors */
      --green-bg: #dcfce7;
      --green-text: #166534; 
      --yellow-bg: #fef3c7;
      --yellow-text: #92400e; 
      --red-bg: #fee2e2;
      --red-text: #991b1b; 
      --gray-bg: #f3f4f6; /* Gray for Completed */
      --gray-text: #4b5563;
      --blue-bg: #e0f2fe; /* Light blue for Rescheduled */
      --blue-text: #075985;
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


    /* Main Layout */
    .records-container {
      max-width: var(--container);
      margin: 30px auto;
      padding: 0 18px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .records-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px;
    }
    .records-header h2 {
      color: var(--primary);
      font-weight: 700;
      /* 💡 Added icon alignment */
      display: flex; 
      align-items: center;
      gap: 10px;
    }

    /* Record Card */
    .record-card {
      background: var(--card);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 6px 18px rgba(20,30,50,0.06);
      transition: 0.25s ease;
      border-left: 5px solid var(--accent);
      position: relative; 
    }
    .record-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 10px 24px rgba(20,30,50,0.1);
    }
    .record-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid #e5ebf3;
      padding-bottom: 6px;
      margin-bottom: 10px;
    }
    .record-head h3 { color: var(--primary); margin: 0; font-size: 1.1rem; }
    .record-date { font-size: 0.9rem; color: var(--muted); font-weight: 500; }
    .record-body p { margin: 6px 0; color: var(--muted); font-size: 0.95rem; line-height: 1.5; }
    .record-body .diagnosis-text, 
    .record-body .prescription-text { white-space: pre-wrap; color: #333; }
    .record-body strong { color: var(--primary); }
    
    /* 💡 Status Badge using root variables */
    .status {
      display: inline-block;
      margin-top: 6px;
      padding: 4px 10px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.85rem;
      text-transform: capitalize; 
    }
    .status.completed, .status.accepted, .status.confirmed { background: var(--green-bg); color: var(--green-text); } 
    .status.pending { background: var(--yellow-bg); color: var(--yellow-text); } 
    .status.cancelled, .status.declined { background: var(--red-bg); color: var(--red-text); } 
    .status.rescheduled { background: var(--blue-bg); color: var(--blue-text); } /* Added reschedule */

    /* Download Button */
    .download-btn {
      position: absolute;
      top: 15px;
      right: 15px;
      background: var(--accent);
      color: white;
      border: none;
      padding: 6px 10px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.8rem;
      font-weight: 600;
      transition: background 0.2s, opacity 0.2s; /* Added opacity transition */
      opacity: 0.7; 
    }
    .record-card:hover .download-btn { opacity: 1; }
    .download-btn:hover { background: #0056b3; }
    .download-btn i { margin-right: 5px; }

    /* Empty state */
    .no-records {
      text-align: center;
      color: var(--muted);
      padding: 40px;
      font-size: 1rem;
      background: #fff;
      border-radius: var(--radius);
      box-shadow: 0 8px 20px rgba(20,30,50,0.05);
    }
    .footer { text-align: center; margin-top: 30px; padding: 15px; color: #6b7280; font-size: 13px; }

    /* Responsive */
    @media (max-width: 768px) { .nav-links { display: none; } .brand-text { display: none;} }

  </style>
</head>

<body>
  <header class="topnav">
    <div class="nav-inner">
      <div class="brand">
        <span class="brand-mark">+</span><span class="brand-text">CliniCare</span>
      </div>
      <nav class="nav-links">
        <a href="patient.php" class="nav-link">Home</a>
        <a href="chat.php" class="nav-link">Chat</a>
        <a href="appointment.php" class="nav-link">Book Appointment</a>
        
        <a href="record.php" class="nav-link active">Records</a>
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
          <span class="name"><?php echo htmlspecialchars($patient_name); ?></span>
          <i class="fa-solid fa-chevron-down caret"></i>
          <ul class="profile-dropdown" id="profileDropdown"> <li><a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a></li>
            <li><a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
          </ul>
        </div>
      </div>
      
    </div>
  </header>

  <main class="records-container">
    <div class="records-header">
      <h2><i class="fa-solid fa-notes-medical" style="color: var(--accent);"></i> Your Medical Records</h2>
    </div>

    <?php if (empty($records)): ?>
        <div class="no-records">
            <i class="fa-solid fa-folder-open fa-2x" style="margin-bottom: 10px;"></i><br>
            You have no medical records yet. Completed appointments with details will appear here.
        </div>
    <?php else: ?>
        <?php foreach ($records as $record): 
            $status_class = strtolower(htmlspecialchars($record['status'])); 
        ?>
            <div class="record-card" 
                 data-doctor="Dr. <?= htmlspecialchars($record['doctor_name']) ?>"
                 data-specialty="<?= htmlspecialchars($record['doctor_specialty']) ?>"
                 data-date="<?= date('M d, Y', strtotime($record['appointment_date'])) ?>"
                 data-time="<?= date('h:i A', strtotime($record['appointment_time'])) ?>"
                 data-reason="<?= htmlspecialchars($record['reason']) ?>" 
                 data-status="<?= htmlspecialchars(ucfirst($record['status'])) ?>"
                 data-id="<?= $record['appointment_id'] ?>"
                 data-diagnosis="<?= htmlspecialchars($record['diagnosis'] ?? '') ?>"
                 data-prescription="<?= htmlspecialchars($record['prescription'] ?? '') ?>">
                
                <button class="download-btn" onclick="downloadRecord(this)">
                    <i class="fa-solid fa-download"></i> Download
                </button>
                
                <div class="record-head">
                    <h3>Consultation with Dr. <?= htmlspecialchars($record['doctor_name']) ?></h3>
                    <span class="record-date"><?= date('M d, Y', strtotime($record['appointment_date'])) ?></span>
                </div>
                <div class="record-body">
                    <p><strong>Specialization:</strong> <?= htmlspecialchars($record['doctor_specialty']) ?></p>
                    <p><strong>Diagnosis:</strong> <span class="data-diagnosis diagnosis-text"><?= !empty($record['diagnosis']) ? nl2br(htmlspecialchars($record['diagnosis'])) : '—' ?></span></p> 
                    <p><strong>Prescription:</strong> <span class="data-prescription prescription-text"><?= !empty($record['prescription']) ? nl2br(htmlspecialchars($record['prescription'])) : '—' ?></span></p> 
                    <p><strong>Reason for Visit:</strong> <?= nl2br(htmlspecialchars($record['reason'])) ?></p> 
                    <p><strong>Status:</strong> <span class="status <?= $status_class ?>"><?= htmlspecialchars(ucfirst($record['status'])) ?></span></p>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

  </main>

  <footer class="footer">
    <p>© <?= date('Y') ?> CliniCare. All rights reserved.</p>
  </footer>

  <script>
    document.addEventListener("DOMContentLoaded", () => {
        // --- PART A: HEADER/DROPDOWN LOGIC ---
        const profile = document.getElementById("profileToggle"); 
        const dropdown = document.getElementById("profileDropdown"); 
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
                    
                    // 💡 Tinatawag ang record.php na may API router
                    fetch('record.php?action=mark_read', { 
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
            if (profile && dropdown && !profile.contains(e.target) && dropdown.classList.contains("show")) {
                dropdown.classList.remove("show");
                if (caret) caret.style.transform = "rotate(0deg)";
            }
            if (notifBell && notifDropdown && !notifBell.contains(e.target) && !notifDropdown.contains(e.target) && notifDropdown.classList.contains("show")) {
                notifDropdown.classList.remove("show");
            }
        });

        // --- PART B: PDF DOWNLOAD LOGIC (Existing code mo, walang binago) ---
        // (Wala akong binago dito, kinopya ko lang ulit)
    });

    // === PDF DOWNLOAD FUNCTION (nasa labas ng DOMContentLoaded) ===
    function downloadRecord(button) {
        const card = button.closest('.record-card');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF();

        const doctor = card.dataset.doctor || 'N/A';
        const specialty = card.dataset.specialty || 'N/A';
        const date = card.dataset.date || 'N/A';
        const time = card.dataset.time || 'N/A';
        const reason = card.dataset.reason || 'N/A';
        const status = card.dataset.status || 'N/A';
        const appointmentId = card.dataset.id || 'Unknown';
        const diagnosis = card.dataset.diagnosis || '—'; 
        const prescription = card.dataset.prescription || '—';
        const patientName = "<?= htmlspecialchars($patient_name, ENT_QUOTES) ?>"; 

        let yPos = 20; 
        const lineSpacing = 8;
        const sectionSpacing = 12; 
        const leftMargin = 15;
        const rightMargin = 15;
        const pdfWidth = pdf.internal.pageSize.getWidth();
        const contentWidth = pdfWidth - leftMargin - rightMargin;

        pdf.setFontSize(18);
        pdf.setFont(undefined, 'bold');
        pdf.text('CliniCare Medical Record', pdfWidth / 2, yPos, { align: 'center' });
        yPos += sectionSpacing;

        pdf.setFontSize(12);
        pdf.setFont(undefined, 'normal');
        pdf.text(`Patient Name: ${patientName}`, leftMargin, yPos);
        yPos += lineSpacing;
        
        pdf.setLineWidth(0.5);
        pdf.line(leftMargin, yPos, pdfWidth - rightMargin, yPos);
        yPos += lineSpacing;

        pdf.setFont(undefined, 'bold');
        pdf.text('Appointment Details', leftMargin, yPos);
        yPos += lineSpacing * 0.8;
        
        pdf.setFont(undefined, 'normal');
        pdf.text(`Date: ${date}`, leftMargin, yPos);
        pdf.text(`Time: ${time}`, pdfWidth / 2 + 10, yPos); 
        yPos += lineSpacing;
        pdf.text(`Doctor: ${doctor}`, leftMargin, yPos);
        yPos += lineSpacing;
        pdf.text(`Specialty: ${specialty}`, leftMargin, yPos);
        yPos += sectionSpacing; 

        pdf.setFont(undefined, 'bold');
        pdf.text('Consultation Summary', leftMargin, yPos);
        yPos += lineSpacing * 0.8;
        pdf.setFont(undefined, 'normal');
        
        function addWrappedText(label, text, currentY) {
            pdf.setFont(undefined, 'bold');
            pdf.text(label, leftMargin, currentY);
            pdf.setFont(undefined, 'normal');
            const textToSplit = (text && text.trim() !== '—') ? text : 'Not specified';
            const lines = pdf.splitTextToSize(textToSplit, contentWidth - 10); 
            pdf.text(lines, leftMargin + 5, currentY + lineSpacing * 0.7); 
            return currentY + (lineSpacing * (lines.length + 0.5)); 
        }

        yPos = addWrappedText('Reason for Visit:', reason, yPos);
        yPos = addWrappedText('Diagnosis:', diagnosis, yPos);
        yPos = addWrappedText('Prescription:', prescription, yPos);
        
        pdf.text(`Status: ${status}`, leftMargin, yPos);
        yPos += lineSpacing * 2; 

        pdf.setFontSize(10);
        pdf.setTextColor(150); 
        pdf.text(`Record ID: ${appointmentId} | Generated on: ${new Date().toLocaleDateString()}`, pdfWidth / 2, pdf.internal.pageSize.getHeight() - 10, { align: 'center' });

        pdf.save(`CliniCare_Record_${appointmentId}.pdf`);
    }
  </script>
</body>
</html>