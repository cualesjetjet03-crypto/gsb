<?php
session_start();
require_once '../includes/db_connect.php'; 

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../admin_login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_id = $_SESSION['admin_id'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $_SESSION['error'] = "All password fields are required.";
        header("Location: ../admin_settings.php");
        exit();
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "New passwords do not match.";
        header("Location: ../admin_settings.php");
        exit();
    }

    $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $db_password = $row['password'];

        // Verify current password (supports both old plain text and new hashes)
        if (password_verify($current_password, $db_password) || $current_password === $db_password) {
            
            // Hash the NEW password securely
            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $update_stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_new_password, $admin_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['success'] = "Password updated successfully.";
            } else {
                $_SESSION['error'] = "Failed to update password in the database.";
            }
            $update_stmt->close();
            
        } else {
            $_SESSION['error'] = "Incorrect current password.";
        }
    } else {
        $_SESSION['error'] = "Admin account not found.";
    }
    
    $stmt->close();
    header("Location: ../admin_settings.php");
    exit();
} else {
    header("Location: ../admin_settings.php");
    exit();
}
?>