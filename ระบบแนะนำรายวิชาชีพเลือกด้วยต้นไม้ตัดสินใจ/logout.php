<?php
session_start();
session_unset();   // Unset all session variables
session_destroy(); // Destroy the session
session_start(); $_SESSION=[]; session_destroy(); header('Location: index.php'); exit;
header("Location: index.php"); // Redirect to the homepage
exit();
?>