<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include '../db_connect.php';


$doctor_id = $_SESSION['user_id'];
$doctor_name = $_SESSION['fullname'] ?? 'Doctor';
$message = ''; // For general messages
$message_type = ''; // success or error

// =======================================================
// === 💡 IDINAGDAG: API ROUTER para sa Mark as Read
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'mark_read') {
    header('Content-Type: application/json');
    
    // I-check ulit kung naka-login
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        exit;
    }
    
    try {
        $stmt_mark = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt_mark->bind_param("i", $doctor_id);
        if ($stmt_mark->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database execution error.']);
        }
        $stmt_mark->close();
    } catch (Exception $e) {
        error_log("Mark as Read Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database exception.']);
    }
    
    $conn->close();
    exit; // Itigil ang script
}
// === END API ROUTER ===


// === 🔴 BAGONG CODE: Fetch Notifications (for header) ===
$notifications = [];
$unread_count = 0;

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
$stmt_notif->bind_param("ii", $doctor_id, $doctor_id); 
$stmt_notif->execute();
$result_notif = $stmt_notif->get_result();

while ($row_notif = $result_notif->fetch_assoc()) {
  $notifications[] = $row_notif;
  if ($row_notif['is_read'] == 0) {
    $unread_count++;
  }
}
$stmt_notif->close();
// === END NOTIFICATION FETCH ===


// --- Handle Confirm / Cancel Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['appointment_id']) && ($_POST['action'] === 'confirm' || $_POST['action'] === 'cancel')) {
  $appt_id = $_POST['appointment_id'];
  $action = $_POST['action'];
  $status = ($action === 'confirm') ? 'Confirmed' : 'Cancelled';

  try {
      $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ? AND doctor_id = ?");
      $stmt->bind_param("sii", $status, $appt_id, $doctor_id);
      
      if($stmt->execute()){
          
          // --- 🔴 BAGONG CODE: Notify Patient ---
          $stmt_patient = $conn->prepare("SELECT patient_id FROM appointments WHERE id = ?");
          $stmt_patient->bind_param("i", $appt_id);
          $stmt_patient->execute();
          $result_patient = $stmt_patient->get_result();
          if ($result_patient->num_rows > 0) {
              $appt_data = $result_patient->fetch_assoc();
              $patient_id_to_notify = $appt_data['patient_id'];
              
              $notif_message = "Your appointment with Dr. $doctor_name has been $status.";
              $notif_link = "appointment.php"; // Patient's appointment page
              
              $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
              $stmt_notify->bind_param("iss", $patient_id_to_notify, $notif_message, $notif_link);
              $stmt_notify->execute();
              $stmt_notify->close();
          }
          $stmt_patient->close();
          // --- 🔴 END NOTIFICATION ---

          header("Location: doctor_appointments.php?status_updated=1"); // Redirect
          exit; // Exit after redirect
      } else {
          $message = "Error updating status.";
          $message_type = "error";
      }
      $stmt->close();
  } catch (Exception $e) {
      $message = "Database Error: " . $e->getMessage();
      $message_type = "error";
  }
}

// --- Handle Reschedule Action ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['reschedule_appointment_id'], $_POST['new_date'], $_POST['new_time']) && $_POST['action'] === 'reschedule_submit') {
    $appt_id = $_POST['reschedule_appointment_id'];
    $new_date = $_POST['new_date'];
    $new_time = $_POST['new_time'];
    $status = 'Rescheduled'; 

    if(empty($new_date) || empty($new_time)){
        $message = "Error: Please select both new date and time for rescheduling.";
        $message_type = "error";
    } else {
        try {
            $stmt = $conn->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = ? WHERE id = ? AND doctor_id = ?");
            $stmt->bind_param("sssii", $new_date, $new_time, $status, $appt_id, $doctor_id);
             if($stmt->execute()){

                // --- 🔴 BAGONG CODE: Notify Patient ---
                $stmt_patient = $conn->prepare("SELECT patient_id FROM appointments WHERE id = ?");
                $stmt_patient->bind_param("i", $appt_id);
                $stmt_patient->execute();
                $result_patient = $stmt_patient->get_result();
                if ($result_patient->num_rows > 0) {
                    $appt_data = $result_patient->fetch_assoc();
                    $patient_id_to_notify = $appt_data['patient_id'];
                    
                    $formatted_date = date('M d, Y', strtotime($new_date));
                    $formatted_time = date('h:i A', strtotime($new_time));
                    $notif_message = "Dr. $doctor_name rescheduled your appointment to $formatted_date at $formatted_time.";
                    $notif_link = "appointment.php";
                    
                    $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                    $stmt_notify->bind_param("iss", $patient_id_to_notify, $notif_message, $notif_link);
                    $stmt_notify->execute();
                    $stmt_notify->close();
                }
                $stmt_patient->close();
                // --- 🔴 END NOTIFICATION ---

                header("Location: doctor_appointments.php?rescheduled=1"); // Redirect
                exit; // Exit after redirect
            } else {
                $message = "Error rescheduling appointment.";
                $message_type = "error";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Database Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// --- Handle Complete Action ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['complete_appointment_id'], $_POST['diagnosis'], $_POST['prescription']) && $_POST['action'] === 'complete_submit') {
    $appt_id = $_POST['complete_appointment_id'];
    $diagnosis = trim($_POST['diagnosis']);
    $prescription = trim($_POST['prescription']);
    $status = 'Completed'; 

    try {
        $stmt = $conn->prepare("UPDATE appointments SET diagnosis = ?, prescription = ?, status = ? WHERE id = ? AND doctor_id = ?");
        $stmt->bind_param("sssii", $diagnosis, $prescription, $status, $appt_id, $doctor_id);
         if($stmt->execute()){
            
            // --- 🔴 BAGONG CODE: Notify Patient ---
            $stmt_patient = $conn->prepare("SELECT patient_id FROM appointments WHERE id = ?");
            $stmt_patient->bind_param("i", $appt_id);
            $stmt_patient->execute();
            $result_patient = $stmt_patient->get_result();
            if ($result_patient->num_rows > 0) {
                $appt_data = $result_patient->fetch_assoc();
                $patient_id_to_notify = $appt_data['patient_id'];
                
                $notif_message = "Your appointment with Dr. $doctor_name is complete. Check your records for new details.";
                $notif_link = "record.php"; // Send patient to records
                
                $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                $stmt_notify->bind_param("iss", $patient_id_to_notify, $notif_message, $notif_link);
                $stmt_notify->execute();
                $stmt_notify->close();
            }
            $stmt_patient->close();
            // --- 🔴 END NOTIFICATION ---
            
            header("Location: doctor_appointments.php?completed=1"); // Redirect
            exit; // Exit after redirect
        } else {
            $message = "Error completing appointment.";
            $message_type = "error";
        }
        $stmt->close();
    } catch (Exception $e) {
        $message = "Database Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// --- Fetch All Appointments for Display ---
$appointments = []; // Initialize
try {
    $stmt = $conn->prepare("
      SELECT a.id, a.appointment_date, a.appointment_time, a.reason, a.status, 
             u.fullname AS patient_name
      FROM appointments a
      JOIN users u ON a.patient_id = u.id
      WHERE a.doctor_id = ?
      ORDER BY 
          CASE a.status
              WHEN 'Pending' THEN 1
              WHEN 'Confirmed' THEN 2
              WHEN 'Rescheduled' THEN 3
              WHEN 'Completed' THEN 4
              WHEN 'Cancelled' THEN 5
              ELSE 6
          END,
          a.appointment_date ASC, 
          a.appointment_time ASC
    ");
    $stmt->bind_param("i", $doctor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result) {
        $appointments = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        throw new Exception("Could not fetch appointments.");
    }
    $stmt->close();
} catch (Exception $e) {
     if (empty($message)) {
        $message = "Database Error fetching appointments: " . $e->getMessage();
        $message_type = "error";
     }
}

// --- Set messages based on GET parameters (after redirect) ---
if (isset($_GET['status_updated'])) {
    $message = "Appointment status updated successfully.";
    $message_type = "success";
}
if (isset($_GET['rescheduled'])) {
    $message = "Appointment rescheduled successfully.";
    $message_type = "success";
}
if (isset($_GET['completed'])) {
    $message = "Appointment marked as completed and details saved.";
    $message_type = "success";
}

?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — Doctor Appointments</title>
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
    
    /* === 🔴 BAGONG CSS: Notification Bell Styles === */
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
    
    /* 💡 INAYOS KO 'YUNG CSS NG BADGE DITO 💡 */
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
    
    .container { max-width: var(--container); margin: 20px auto; padding: 0 18px; }
    .card { background: var(--card); border-radius: var(--radius); padding: 20px; margin-bottom: 20px; box-shadow: 0 8px 24px rgba(20,30,50,0.06); }
    .card h2 { color: var(--primary); margin-top: 0; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; /* Added flex for icon */} 
    .btn { display: inline-flex; align-items: center; gap: 6px; border: none; padding: 8px 12px; /* Adjusted padding */ border-radius: 8px; cursor: pointer; font-weight: 600; transition: 0.2s; text-decoration: none; font-size: 13px; /* Slightly smaller */ }
    .btn.primary { background: var(--primary); color: #fff; }
    .btn.primary:hover { background: var(--accent); }
    .btn.outline { border: 1.5px solid var(--primary); color: var(--primary); background: transparent; }
    .btn.outline:hover { background: #e9f2ff; }
    .btn.danger { background: var(--red-text); color: #fff; } /* Added danger button */
    .btn.danger:hover { background: #b91c1c; }
    .btn.success { background: var(--green-text); color: #fff;} /* Added success button */
    .btn.success:hover { background: #15803d; }
    .btn.info { background: var(--blue-text); color: #fff; } /* Added info button (for reschedule) */
    .btn.info:hover { background: #0c4a6e; }
    .btn.small { padding: 6px 10px; font-size: 12px; } /* Smaller button variant */
    .alert-message { padding: 12px; margin-bottom: 20px; border-radius: 8px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
    .alert-success { background-color: var(--green-bg); color: var(--green-text); border: 1px solid #a7f3d0; }
    .alert-error { background-color: var(--red-bg); color: var(--red-text); border: 1px solid #fecaca; }
     .table-responsive { overflow-x: auto; } /* Added for smaller screens */
    .appt-table { width: 100%; border-collapse: collapse; }
    .appt-table th, .appt-table td { padding: 12px 10px; /* Adjusted padding */ border-bottom: 1px solid #eaeff5; text-align: left; font-size: 14px; vertical-align: middle; /* Align content vertically */}
    .appt-table th { background: #f9fbff; color: var(--primary); font-weight: 600; text-transform: uppercase; font-size: 12px; }
    .appt-table td:last-child { white-space: nowrap; width: auto; /* Allow buttons to wrap if needed */ text-align: right; /* Align buttons to right */} 
    .appt-table form, .appt-table button { display: inline-block; margin: 2px 4px 2px 0; } /* Spacing for buttons */
    .badge { padding: 5px 10px; border-radius: 999px; font-weight: 600; font-size: 12px; text-transform: capitalize; }
    .badge.confirmed, .badge.accepted, .badge.completed { background: var(--green-bg); color: var(--green-text); } 
    .badge.pending { background: var(--yellow-bg); color: var(--yellow-text); }
    .badge.cancelled, .badge.declined { background: var(--red-bg); color: var(--red-text); }
    .badge.rescheduled { background: var(--blue-bg); color: var(--blue-text); } /* Rescheduled color */

    .modal {
        display: none; 
        position: fixed; 
        z-index: 1000; /* Ensure modal is on top */
        left: 0;
        top: 0;
        width: 100%; 
        height: 100%; 
        overflow: auto; 
        background-color: rgba(0,0,0,0.6); /* Darker overlay */
        /* 💡 AYOS: Pinalitan ng flex para sa vertical centering */
        display: none;
        justify-content: center;
        align-items: center;
        padding: 20px; /* Add padding for smaller screens */
    }
    .modal-content {
        background-color: #fff;
        margin: auto;
        padding: 30px; /* Increased padding */
        border: none; /* Removed border */
        width: 95%; /* Responsive width */
        max-width: 550px; /* Increased max width */
        border-radius: var(--radius);
        position: relative;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2); /* Stronger shadow */
        animation: fadeInModal 0.3s ease-out; /* Add animation */
    }
    @keyframes fadeInModal {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .modal-close {
        color: #aaa;
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        line-height: 1;
    }
    .modal-close:hover,
    .modal-close:focus { color: #555; }
    
    .modal-header { /* New class for header */
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border-color);
    }
    .modal-header i { /* Style for icon */
        color: var(--accent);
        font-size: 1.3em;
    }
    .modal-header h3 { 
        color: var(--primary); 
        margin: 0; /* Remove default margin */
        font-size: 1.25rem;
    }

    .modal .modal-patient-info { /* Style for patient info */
        font-size: 0.9rem;
        color: var(--muted);
        margin-bottom: 20px;
        background-color: var(--bg);
        padding: 8px 12px;
        border-radius: 6px;
    }
    .modal .modal-patient-info strong {
        color: var(--primary); /* Make patient name stand out */
    }

    .modal .form-group { margin-bottom: 15px; }
    .modal .form-group label { 
        font-size: 0.9rem; /* Slightly smaller label */
        margin-bottom: 5px; 
        color: var(--primary);
        font-weight: 600;
        display: block; /* 💡 AYOS: Ensure label is block */
    }
    .modal .form-group input[type="date"], 
    .modal .form-group input[type="time"], 
    .modal .form-group textarea { 
        font-size: 0.95rem; 
        padding: 10px; 
        border-radius: 8px; /* Consistent radius */
        border: 1px solid var(--border-color);
        width: 100%; /* Ensure full width */
        font-family: 'Poppins', sans-serif; /* Ensure font */
    }
     .modal .form-group input:focus,
     .modal .form-group textarea:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(0, 120, 255, 0.15); /* Blue glow */
        outline: none;
     }
    .modal textarea { min-height: 100px; resize: vertical; }
    .modal .button-group { display: flex; gap: 10px; justify-content: flex-end; margin-top: 25px; }
    /* Use standard button classes in modal */
    .modal .button-group .btn { 
        padding: 10px 16px; /* Slightly larger padding */
        font-size: 0.9rem; 
    }

    .footer { text-align: center; margin-top: 30px; padding: 15px; color: var(--muted); font-size: 13px; }

    @media (max-width: 768px) { 
        .nav-links { display: none; } 
        .brand-text { display: none; } 
        .appt-table th:nth-child(4), .appt-table td:nth-child(4) { display: none; } /* Hide Reason */
        .appt-table th:nth-child(2), .appt-table td:nth-child(2), /* Hide Date */
        .appt-table th:nth-child(3), .appt-table td:nth-child(3) { /* Hide Time */
        }
        .appt-table td:last-child { text-align: left; } /* Align actions left on mobile */
    }
     @media (max-width: 480px) {
         .modal-content { padding: 20px; } /* Less padding on very small screens */
         .modal-header h3 { font-size: 1.1rem; }
     }

  </style>
</head>
<body>

  <header class="topnav">
    <div class="nav-inner">
      <div class="brand"><span class="brand-mark">+</span><span class="brand-text">CliniCare</span></div>
      <nav class="nav-links">
        <a href="doctor.php" class="nav-link">Dashboard</a> <a href="doctor_appointments.php" class="nav-link active">Appointments</a>
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
                  // Simple time ago function
                  $time_ago = strtotime($notif['created_at']);
                  $time_diff = time() - $time_ago;
                  $time_unit = '';
                  if ($time_diff < 60) { $time_unit = 'just now'; }
                  elseif ($time_diff < 3600) { $time_unit = floor($time_diff / 60) . 'm ago'; }
                  elseif ($time_diff < 86400) { $time_unit = floor($time_diff / 3600) . 'h ago'; }
                  else { $time_unit = floor($time_diff / 86400) . 'd ago'; }
                  
                  $link = $notif['link'] ?? 'doctor_appointments.php'; // Default link
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
          <span class="name">Dr. <?= htmlspecialchars($doctor_name) ?></span>
          <i class="fa-solid fa-chevron-down caret"></i>
          <ul class="profile-dropdown" id="profileDropdown">
            <li><a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a></li>
            <li><a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li> </ul>
        </div>
      </div>
    </div>
  </header>

  <main class="container">
    <section class="card">
      <h2 style="margin-bottom: 15px;"><i class="fa-solid fa-calendar-check" style="margin-right: 10px; color: var(--accent);"></i>Manage Appointments</h2>

      <?php if (!empty($message)): ?>
            <div class="alert-message <?php echo ($message_type === 'success') ? 'alert-success' : 'alert-error'; ?>">
                <i class="fa-solid <?= ($message_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="margin-right: 8px;"></i>
                <?= htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

      <?php if(count($appointments) > 0): ?>
        <div class="table-responsive"> 
            <table class="appt-table">
            <thead>
                <tr>
                <th>Patient</th>
                <th>Date</th>
                <th>Time</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($appointments as $appt): 
                    $status_lower = strtolower($appt['status']);
                ?>
                <tr>
                    <td><?= htmlspecialchars($appt['patient_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($appt['appointment_date'])) // Formatted date ?></td>
                    <td><?= date('h:i A', strtotime($appt['appointment_time'])) // Formatted time ?></td>
                    <td><?= htmlspecialchars($appt['reason']) ?></td>
                    <td><span class="badge <?= $status_lower ?>"><?= htmlspecialchars($appt['status']) ?></span></td>
                    <td>
                        <?php if ($status_lower === 'pending'): ?>
                            <form method="POST" action="doctor_appointments.php" style="display:inline-block;">
                                <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
                                <button type="submit" name="action" value="confirm" class="btn success small" title="Confirm Appointment">
                                    <i class="fa-solid fa-check"></i> Confirm
                                </button>
                            </form>
                            <form method="POST" action="doctor_appointments.php" style="display:inline-block;">
                                <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
                                <button type="submit" name="action" value="cancel" class="btn danger small" title="Cancel Appointment">
                                     <i class="fa-solid fa-times"></i> Cancel
                                </button>
                            </form>
                            <button onclick="openRescheduleModal(<?= $appt['id'] ?>, '<?= htmlspecialchars($appt['patient_name'], ENT_QUOTES) ?>')" class="btn info small" title="Reschedule Appointment">
                                <i class="fa-solid fa-calendar-days"></i> Reschedule
                            </button>
                        <?php elseif ($status_lower === 'confirmed'): ?>
                            <button onclick="openCompleteModal(<?= $appt['id'] ?>, '<?= htmlspecialchars($appt['patient_name'], ENT_QUOTES) ?>')" class="btn primary small" title="Mark as Completed and Add Details">
                                <i class="fa-solid fa-notes-medical"></i> Complete & Add Details 
                            </button>
                             <button onclick="openRescheduleModal(<?= $appt['id'] ?>, '<?= htmlspecialchars($appt['patient_name'], ENT_QUOTES) ?>')" class="btn info small" title="Reschedule Appointment">
                                <i class="fa-solid fa-calendar-days"></i> Reschedule
                            </button>
                            <form method="POST" action="doctor_appointments.php" style="display:inline-block;">
                                <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
                                <button type="submit" name="action" value="cancel" class="btn danger small" title="Cancel Appointment">
                                     <i class="fa-solid fa-times"></i> Cancel
                                </button>
                            </form>
                        <?php elseif ($status_lower === 'rescheduled'): ?>
                             <form method="POST" action="doctor_appointments.php" style="display:inline-block;">
                                <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
                                <button type="submit" name="action" value="confirm" class="btn success small" title="Confirm Rescheduled Time">
                                    <i class="fa-solid fa-check"></i> Confirm New
                                </button>
                            </form>
                            
    <form method="POST" action="doctor_appointments.php" style="display:inline-block;">
                                <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
                                <button type="submit" name="action" value="cancel" class="btn danger small" title="Cancel Rescheduled Appointment">
                                     <i class="fa-solid fa-times"></i> Cancel
                                </button>
                            </form>
                        
                        <?php elseif ($status_lower === 'completed'): ?>
                            <span class="badge gray">Completed</span>
                            <?php elseif ($status_lower === 'cancelled'): ?>
                            <span class="badge cancelled">Cancelled</span>
                        
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        </div> <?php else: ?>
        <p style="text-align: center; color: var(--muted); padding: 30px 0;">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 24px; margin-bottom: 10px;"></i><br>
            You have no appointments scheduled.
        </p>
      <?php endif; ?>
    </section>
  </main>
  
  
  <div id="rescheduleModal" class="modal">
    <div class="modal-content">
      <span class="modal-close" onclick="closeModal('rescheduleModal')">&times;</span>
      <div class="modal-header">
        <i class="fa-solid fa-calendar-days"></i>
        <h3>Reschedule Appointment</h3>
      </div>
      <p class="modal-patient-info">Rescheduling for: <strong id="reschedulePatientName"></strong></p>
      
      <form method="POST" action="doctor_appointments.php">
        <input type="hidden" name="action" value="reschedule_submit">
        <input type="hidden" name="reschedule_appointment_id" id="rescheduleApptId">
        
        <div class="form-group">
          <label for="new_date">New Date</label>
          <input type="date" id="new_date" name="new_date" required min="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label for="new_time">New Time</label>
          <input type="time" id="new_time" name="new_time" required>
        </div>
        
        <div class="button-group">
          <button type="button" class="btn outline" onclick="closeModal('rescheduleModal')">Cancel</button>
          <button type="submit" class="btn primary">Confirm Reschedule</button>
        </div>
      </form>
    </div>
  </div>

  <div id="completeModal" class="modal">
    <div class="modal-content">
      <span class="modal-close" onclick="closeModal('completeModal')">&times;</span>
      <div class="modal-header">
        <i class="fa-solid fa-notes-medical"></i>
        <h3>Complete Appointment</h3>
      </div>
      <p class="modal-patient-info">Completing for: <strong id="completePatientName"></strong></p>
      
      <form method="POST" action="doctor_appointments.php">
        <input type="hidden" name="action" value="complete_submit">
        <input type="hidden" name="complete_appointment_id" id="completeApptId">
        
        <div class="form-group">
          <label for="diagnosis">Diagnosis / Clinical Notes</label>
          <textarea id="diagnosis" name="diagnosis" rows="4" placeholder="Enter clinical findings..."></textarea>
        </div>
        <div class="form-group">
          <label for="prescription">Prescription / Medication</label>
          <textarea id="prescription" name="prescription" rows="4" placeholder="Enter prescribed medication..."></textarea>
        </div>
        
        <div class="button-group">
          <button type="button" class="btn outline" onclick="closeModal('completeModal')">Cancel</button>
          <button type="submit" class="btn success">Mark as Completed</button>
        </div>
      </form>
    </div>
  </div>

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
          // Isara ang notif kung bukas
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
          // Isara ang profile kung bukas
          if (dropdown && dropdown.classList.contains("show")) {
            dropdown.classList.remove("show");
            if (caret) caret.style.transform = "rotate(0deg)";
          }
          
          // === Mark as Read Logic ===
          if (notifDropdown.classList.contains("show") && notifBadge && notifBadge.style.display !== 'none') {
            
            // Tatawagin 'yung sariling file, na may PHP handler sa taas
            fetch('doctor_appointments.php?action=mark_read', { 
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
              }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
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
                console.error("Failed to mark read:", data.error);
              }
            })
            .catch(err => console.error("Error marking read:", err));
          }
        });
      }

      // --- Close dropdowns on outside click ---
      document.addEventListener("click", (e) => {
        if (profile && dropdown && !profile.contains(e.target) && dropdown.classList.contains("show")) {
          dropdown.classList.remove("show");
          if (caret) caret.style.transform = "rotate(0deg)";
        }
        if (notifBell && notifDropdown && !notifBell.contains(e.target) && !notifDropdown.contains(e.target) && notifDropdown.classList.contains("show")) {
          notifDropdown.classList.remove("show");
        }
      });
    });

    // === Modal Functions ===
    function openModal(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.style.display = 'flex'; // Use flex to center
      }
    }
    
    function closeModal(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.style.display = 'none';
      }
    }

    // --- Functions to pass data to modals ---
    function openRescheduleModal(apptId, patientName) {
      document.getElementById('rescheduleApptId').value = apptId;
      document.getElementById('reschedulePatientName').textContent = patientName;
      openModal('rescheduleModal');
    }
    
    function openCompleteModal(apptId, patientName) {
      document.getElementById('completeApptId').value = apptId;
      document.getElementById('completePatientName').textContent = patientName;
      openModal('completeModal');
    }

    // Close modal if user clicks outside of it
    window.onclick = function(event) {
      if (event.target.classList.contains('modal')) {
        closeModal(event.target.id);
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
    }, 5000);

  </script>
</body>
</html>