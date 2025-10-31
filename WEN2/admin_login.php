<?php
session_start();
// === UPDATED REDIRECT FOR BOTH ROLES ===
$error_message = $_GET['error'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>CliniCare — Login</title> <!-- Changed title -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        :root {
            --primary: #1c3d5a; /* Dark Blue from admin */
            --accent: #0078FF; /* Bright Blue */
            --light-bg: #f0f4f9; /* Lighter gray-blue */
            --card-bg: #ffffff;
            --border-color: #d1d9e6; /* Slightly darker border */
            --shadow-color: rgba(0, 0, 0, 0.1);
            --danger: #dc2626; /* Brighter Red */
            --danger-light: #fee2e2;
            --muted: #6b7280; /* Added muted color */
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--light-bg);
            /* === PROFESSIONAL BACKGROUND === */
            /* === UPDATED BACKGROUND TEXT === */
            background-image: linear-gradient(rgba(28, 61, 90, 0.7), rgba(28, 61, 90, 0.7)), url('https://placehold.co/1920x1080/e0e0e0/ffffff?text=Clinic+Care');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px; /* Add padding for smaller screens */
        }
        .login-container {
            width: 100%;
            /* === INCREASED SIZE === */
            max-width: 450px; 
            padding: 40px; /* Increased padding */
            background: var(--card-bg);
            border-radius: 12px; /* Standard radius */
            box-shadow: 0 8px 25px var(--shadow-color);
            text-align: center;
            animation: fadeIn 0.5s ease-out;
            border: 1px solid var(--border-color); /* Added subtle border */
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-header {
            margin-bottom: 25px;
        }
        .login-header .brand {
            display: flex; /* Use flex to center */
            align-items: center;
            justify-content: center; /* Center horizontally */
            gap: 8px; /* Space between mark and text */
            font-size: 26px;
            font-weight: 700; /* Slightly less bold */
            color: var(--primary);
            margin-bottom: 15px; /* Increased margin */
        }
         .login-header .brand-mark {
             width: 30px; height: 30px; background: var(--primary); color: #fff; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 18px;
         }
        .login-header h2 {
            font-size: 1.4rem;
            color: var(--primary);
            margin-bottom: 8px;
            font-weight: 600;
        }
         .login-header p {
            color: var(--muted);
            font-size: 0.9rem; /* Smaller text */
         }

        .form-group {
            margin-bottom: 18px; /* Adjusted margin */
            text-align: left;
            position: relative; /* Needed for absolute icon positioning */
        }
        .form-group label {
            display: block;
            font-weight: 500; /* Regular weight */
            margin-bottom: 6px;
            color: var(--primary);
            font-size: 0.85rem; /* Smaller label */
        }
        .form-group .input-icon {
            position: absolute;
            left: 14px; /* Slightly adjusted position */
            /* === ADJUSTED ICON ALIGNMENT === */
            top: 50%; 
            transform: translateY(-50%);
            margin-top: 15px; /* Adjust this value to fine-tune vertical alignment relative to the input box border */
            color: #9ca3af; /* Lighter icon */
            font-size: 0.9rem; /* Smaller icon */
            pointer-events: none;
            z-index: 1; /* Ensure icon is above input background */
        }
        .form-group input {
            width: 100%;
            /* === INCREASED PADDING FOR ICON === */
            padding: 12px 12px 12px 40px; /* Increased left padding */
            border: 1px solid var(--border-color);
            border-radius: 8px; /* Less rounded */
            font-size: 0.95rem; /* Slightly smaller font */
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #f9fafb; /* Very light input background */
            position: relative; /* Needed for z-index context */
        }
        .form-group input::placeholder { /* Style placeholder */
            color: #9ca3af;
        }
        .form-group input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0, 120, 255, 0.1);
            outline: none;
            background: #fff; /* White background on focus */
        }
        .login-btn {
            background: var(--primary);
            color: #fff;
            padding: 12px 20px; /* Adjusted padding */
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.2s;
            font-weight: 600;
            width: 100%;
            font-size: 0.95rem; /* Adjusted font size */
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .login-btn:hover {
            background: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); /* Add shadow on hover */
        }
        .login-btn:active {
             transform: translateY(0); /* Remove lift on click */
        }
        .error-message {
            background-color: var(--danger-light);
            color: var(--danger);
            border: 1px solid var(--danger); /* Stronger border */
            padding: 10px; /* Adjusted padding */
            border-radius: 8px;
            margin-bottom: 15px; /* Adjusted margin */
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.85rem; /* Smaller error text */
        }
        .error-message i { font-size: 1rem; }

        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                padding: 25px; /* Less padding on small screens */
            }
            .login-header h2 { font-size: 1.3rem; }
            .login-header .brand { font-size: 24px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
             <div class="brand">
                <span class="brand-mark">+</span>CliniCare
            </div>
            <!-- === UPDATED HEADER TEXT === -->
            <h2>System Login</h2>
            <p>Admin & Doctor Access Portal.</p>
        </div>

        <?php if (!empty($error_message)): // Check if error message is not empty ?>
            <div class="error-message">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars(urldecode($error_message)); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="admin_process_login.php">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <i class="fa-solid fa-user input-icon"></i>
                <input type="text" id="username" name="username" required placeholder="Enter username or email">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                 <i class="fa-solid fa-lock input-icon"></i>
                <input type="password" id="password" name="password" required placeholder="Enter password">
            </div>
            <button type="submit" class="login-btn">
                <i class="fa-solid fa-right-to-bracket"></i> Secure Login
            </button>
        </form>
    </div>

    <script>
        // Remove error message from URL if present
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            if (url.searchParams.get('error')) {
                window.history.replaceState({ path: url.pathname }, '', url.pathname);
            }
        }
    </script>
</body>
</html>