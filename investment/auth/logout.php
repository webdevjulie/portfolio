<?php
session_start(); // Start or resume the session

// Destroy all session variables
$_SESSION = [];
session_unset();
session_destroy();

// Redirect to login page
header("Location: login.php"); // or login.php if that's your login file
exit();
?>
