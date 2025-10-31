<?php
// === MAS MATIBAY NA ERROR REPORTING ===
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$user = "root";
$pass = ""; // Ilagay ang password mo kung meron
$db = "clinicare";

try {
    $conn = new mysqli($host, $user, $pass, $db);
    
    // === GOOD PRACTICE: Set character set ===
    $conn->set_charset("utf8mb4");
    
} catch (mysqli_sql_exception $e) {
    // Huwag ipakita ang specific error sa user
    error_log("Database Connection Error: " . $e->getMessage()); // Para makita mo sa logs
    // Palitan mo 'yung luma, gawin mong ito:
die("ANG TOTOONG ERROR AY: " . $e->getMessage());
}
?>