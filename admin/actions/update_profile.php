<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../admin_login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = trim($_POST['admin_name'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');

    if (empty($admin_name) || empty($admin_email)) {
        $_SESSION['error'] = "Name and Email cannot be empty.";
        header("Location: ../admin_settings.php");
        exit();
    }

    // IMPORTANT: Change 'admins', 'name', and 'email' to match your actual database schema
    $stmt = $conn->prepare("UPDATE admins SET name = ?, email = ? WHERE id = ?");
    $stmt->bind_param("ssi", $admin_name, $admin_email, $admin_id);

    if ($stmt->execute()) {
        // Update session variables to immediately reflect changes on the UI
        $_SESSION['admin_name'] = $admin_name;
        $_SESSION['admin_email'] = $admin_email;
        
        $_SESSION['success'] = "Profile details updated successfully.";
    } else {
        $_SESSION['error'] = "Failed to update profile.";
    }
    
    $stmt->close();
    header("Location: ../admin_settings.php");
    exit();
} else {
    header("Location: ../admin_settings.php");
    exit();
}
?>