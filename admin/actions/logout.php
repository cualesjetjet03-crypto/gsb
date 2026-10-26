<?php
session_start();

// Clear all session variables and destroy the session
$_SESSION = [];
session_unset();
session_destroy();

// Redirect to the unified login page in the customer folder
header("Location: ../../customer/login.php");
exit();
?>