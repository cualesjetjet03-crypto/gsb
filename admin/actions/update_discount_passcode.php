<?php
session_start();
require_once '../includes/db_connect.php';
require_once __DIR__ . '/../../includes/auth_helpers.php';

require_admin_session('../admin_login.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['discount_passcode'], $_POST['current_discount_passcode'])) {

    $current_input = $_POST['current_discount_passcode'];
    $new_passcode  = trim($_POST['discount_passcode']);

    if (strlen($new_passcode) < 6) {
        $_SESSION['error'] = "New passcode must be at least 6 characters.";
        header("Location: ../admin_settings.php");
        exit();
    }

    // Fetch the stored passcode
    $pass_query    = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key = 'owner_passcode' LIMIT 1");
    $stored        = ($pass_query && $pass_query->num_rows > 0)
                     ? $pass_query->fetch_assoc()['setting_value']
                     : '';

    // Verify current passcode — accept bcrypt hash OR plain-text (legacy)
    $current_valid = !empty($stored) && (
        password_verify($current_input, $stored) ||
        hash_equals($stored, $current_input)
    );

    if (!$current_valid) {
        $_SESSION['error'] = "Incorrect current passcode. Change not saved.";
        header("Location: ../admin_settings.php");
        exit();
    }

    // Store the new passcode as a bcrypt hash (never plain-text)
    $hashed_new = password_hash($new_passcode, PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO site_settings (setting_key, setting_value)
         VALUES ('owner_passcode', ?)
         ON DUPLICATE KEY UPDATE setting_value = ?"
    );
    $stmt->bind_param("ss", $hashed_new, $hashed_new);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Discount passcode updated successfully.";
    } else {
        $_SESSION['error'] = "Database error — passcode not updated.";
        error_log("update_discount_passcode error: " . $conn->error);
    }
    $stmt->close();
}

header("Location: ../admin_settings.php");
exit();
