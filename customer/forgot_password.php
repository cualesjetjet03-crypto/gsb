<?php
include "db_connect.php";
require_once __DIR__ . '/../includes/mailer.php';

$error_msg        = "";
$show_success_modal = false;

// Token expires in 1 hour
define('RESET_TOKEN_TTL', 3600);

if (isset($_POST['forgot_password'])) {
    $email = trim($_POST['email'] ?? '');

    if (!preg_match('/@gmail\.com$/i', $email)) {
        $error_msg = "Please enter a valid @gmail.com email address.";
    } else {
        // Prepared statement — no raw interpolation
        $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user     = $result->fetch_assoc();
            $fullname = $user['fullname'];
            $stmt->close();

            $reset_token   = bin2hex(random_bytes(32));          // 64-char hex token
            $expires_at    = date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL);

            $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE email = ?");
            $upd->bind_param("sss", $reset_token, $expires_at, $email);

            if ($upd->execute()) {
                $upd->close();

                $reset_link = rtrim(APP_BASE_URL, '/') . '/reset_password.php?token=' . urlencode($reset_token);

                $body = "Hi <b>" . htmlspecialchars($fullname) . "</b>,<br><br>"
                    . "We received a request to reset your password. "
                    . "Click the link below — it expires in 1 hour:<br><br>"
                    . "<a href='" . htmlspecialchars($reset_link) . "'>Reset My Password</a><br><br>"
                    . "If you did not request this, you can safely ignore this email.";

                $result = gsb_send_email($email, $fullname, 'Reset Your GSB Account Password', $body);

                if ($result === true) {
                    $show_success_modal = true;
                    $_POST = [];
                } else {
                    $error_msg = "Could not send reset email. Please try again later.";
                    error_log("gsb_send_email error (forgot_password): " . $result);
                }
            } else {
                $upd->close();
                $error_msg = "A database error occurred. Please try again.";
                error_log("forgot_password UPDATE error: " . $conn->error);
            }
        } else {
            $stmt->close();
            // Generic message — don't reveal whether the email exists
            $show_success_modal = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSB Airconditioning Services | Forgot Password</title>
    <link rel="stylesheet" href="css/login.css?v=1.3">
    <link rel="icon" type="image/png" href="favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="auth-wrapper">
        <div class="auth-card">
            
            <div class="auth-illustration">
                <img src="favicon.png" alt="GSB Logo">
            </div>

            <div class="auth-form-container">
                <div class="form-header">
                    <h2>Forgot Password?</h2>
                    <p>Enter your registered Gmail address and we'll send you a password reset link.</p>
                </div>

                <?php if (!empty($error_msg)): ?>
                    <div class="error-alert">
                        <?= htmlspecialchars($error_msg) ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <div class="form-group full-width">
                        <label>Email Address <span class="req">*</span></label>
                        <div class="input-icon-wrapper">
                            <i class="fa-regular fa-envelope input-icon"></i>
                            <input type="email" name="email" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" placeholder="example@gmail.com" required>
                        </div>
                    </div>

                    <button type="submit" name="forgot_password" class="auth-btn">
                        Send Reset Link <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </form>

                <div class="auth-footer">
                    Remember your password? <a href="login.php">Back to Sign In</a>
                </div>
            </div>
        </div>
    </div>

    <!-- SUCCESS MODAL -->
    <?php if ($show_success_modal): ?>
    <div class="custom-modal-overlay">
        <div class="custom-modal">
            <div class="modal-icon">
                <i class="fa-regular fa-envelope"></i>
            </div>
            <h3>Check Your Inbox!</h3>
            <p>We've sent a password reset link to your Gmail. Please check your inbox and click the link to create a new password.</p>
            <button onclick="window.location.href='login.php'" class="modal-btn">Back to Login</button>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>