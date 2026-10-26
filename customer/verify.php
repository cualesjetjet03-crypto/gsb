<?php
include "db_connect.php";

$modal_type = "error"; // 'success' or 'error'
$modal_title = "Verification Error";
$modal_message = "No verification token provided.";
$redirect_url = "register.php";

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);
    
    // Find the user with this token
    $query = "SELECT id, is_verified FROM users WHERE verification_code = '$token' LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        
        if ($user['is_verified'] == 0) {
            // Update user to verified and clear the token
            $update_query = "UPDATE users SET is_verified = 1, verification_code = NULL WHERE verification_code = '$token'";
            
            if (mysqli_query($conn, $update_query)) {
                $modal_type = "success";
                $modal_title = "Account Verified!";
                $modal_message = "Your email has been verified successfully. You can now log in to your account.";
                $redirect_url = "login.php";
            } else {
                $modal_message = "Error updating record: " . mysqli_error($conn);
            }
        } else {
            $modal_type = "success";
            $modal_title = "Already Verified";
            $modal_message = "This account is already verified. Please proceed to login.";
            $redirect_url = "login.php";
        }
    } else {
        $modal_message = "Invalid or expired verification link.";
        $redirect_url = "register.php";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSB Airconditioning Services | Verify Account</title>
    <link rel="stylesheet" href="css/login.css?v=1.3">
    <link rel="icon" type="image/png" href="favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="custom-modal-overlay">
        <div class="custom-modal <?= $modal_type === 'error' ? 'error-modal' : '' ?>">
            <div class="modal-icon">
                <?php if ($modal_type === 'success'): ?>
                    <i class="fa-solid fa-circle-check"></i>
                <?php else: ?>
                    <i class="fa-solid fa-circle-exclamation"></i>
                <?php endif; ?>
            </div>
            <h3><?= htmlspecialchars($modal_title) ?></h3>
            <p><?= htmlspecialchars($modal_message) ?></p>
            <button onclick="window.location.href='<?= $redirect_url ?>'" class="modal-btn">
                <?= $redirect_url === 'login.php' ? 'Go to Login' : 'Back to Register' ?>
            </button>
        </div>
    </div>

</body>
</html>