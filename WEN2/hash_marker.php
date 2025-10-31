<?php
// Ilagay mo rito 'yung password na gusto mong gamitin
$passwordToHash = 'admin123'; 

$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);

echo "Kopyahin mo ito at i-paste sa database:<br><br>";
echo $hashedPassword;
?>  