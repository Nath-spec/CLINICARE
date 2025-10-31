<?php
session_start();

$patient_name = $_SESSION['fullname'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CliniCare — View Record</title>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="patient.css" />

  <style>
    /* back button floating style */
    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #e9f3ff;
      color: var(--primary);
      border: 1px solid #cfe4ff;
      padding: 8px 14px;
      border-radius: 50px;
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
      box-shadow: 0 3px 8px rgba(31, 78, 121, 0.1);
      transition: all 0.2s ease;
      position: relative;
      top: 10px;
      left: 10px;
    }
    .back-btn:hover {
      background: var(--primary);
      color: #fff;
      transform: translateX(-2px);
    }
    .record-details p {
      margin: 10px 0;
      font-size: 15px;
      color: #374151;
    }
  </style>
</head>
<body>

  <header class="topnav">
    <div class="nav-inner">
      <div class="brand">
        <span class="brand-mark">+</span><span class="brand-text">CliniCare</span>
      </div>

      <nav class="nav-links">
        <a href="patient.php" class="nav-link active">Home</a>
        <a href="chat.php" class="nav-link">Chat</a>
        <a href="appointment.php" class="nav-link">Book Appointment</a>
        <a href="record.php" class="nav-link">Records</a>
      </nav>

      <div class="nav-right">
  <div class="profile" id="profileMenu">
    <img src="../PICTURES/doc1.jpg" alt="User avatar" class="avatar">
    <span class="name"><?php echo htmlspecialchars($patient_name); ?></span>
    <i class="fa-solid fa-chevron-down caret"></i>
    <ul class="profile-dropdown" id="profileDropdown">
      <li><a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a></li>
      <li><a href="../logout.php" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
    </ul>
  </div>
</div>
  <!-- MAIN CONTENT -->
  <main class="container">
    <!-- 🔵 Floating back button -->
    <a href="patient.html" class="back-btn"><i class="fa-solid fa-arrow-left"></i>Back</a>

    <section class="card" style="max-width:800px;margin:20px auto;">
      <div class="card-head">
        <h2>Consultation Record</h2>
      </div>

      <div class="record-details">
        <p><strong>Doctor:</strong> Dr. Maria Santos</p>
        <p><strong>Specialization:</strong> Family Medicine</p>
        <p><strong>Date:</strong> October 10, 2025</p>
        <p><strong>Diagnosis:</strong> Mild Flu and Dehydration</p>
        <p><strong>Prescription:</strong> Paracetamol 500mg (3x daily), Hydration & Rest</p>
        <p><strong>Doctor’s Notes:</strong> Patient advised to rest and maintain hydration. Follow-up in 1 week if symptoms persist.</p>
      </div>

      <div class="actions" style="margin-top:20px;">
        <button class="btn primary" id="downloadBtn"><i class="fa-solid fa-download"></i> Download PDF</button>
      </div>
    </section>
  </main>

  <!-- FOOTER -->
  <footer class="footer">
    <p>© 2025 CliniCare. All rights reserved.</p>
  </footer>

  <script src="patient.js"></script>
  <script>
    // logout
    document.getElementById("logoutBtn").addEventListener("click", () => {
      alert("Logging out...");
      window.location.href = "../index.html";
    });

    // fake download
    document.getElementById("downloadBtn").addEventListener("click", () => {
      alert("Downloading report (frontend only)...");
    });
  </script>
</body>
</html>
