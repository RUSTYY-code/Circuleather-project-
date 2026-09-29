<?php
// Beëindig de sessie en stuur de gebruiker terug naar het loginformulier.
session_start();
session_destroy();
header("Location: login.php");
exit();
?>
