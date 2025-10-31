<?php
/*
  CREATE HASH UTILITY
  1. Ilagay ang password na gusto mong gamitin sa $password_to_hash.
  2. Buksan ito sa browser (e.g., localhost/WEN2/create_hash.php).
  3. Kopyahin ang HASH na lalabas.
  4. Gamitin ang hash na 'yan sa iyong SQL INSERT statement.
*/

// Ilagay ang password na gusto mong gamitin dito
$password_to_hash = 'admin123';

// I-e-echo nito ang saktong hash para sa system mo
echo "Ang hash para sa password na <b>'" . $password_to_hash . "'</b> ay:<br><br>";
echo "<b>" . password_hash($password_to_hash, PASSWORD_DEFAULT) . "</b>";
?>