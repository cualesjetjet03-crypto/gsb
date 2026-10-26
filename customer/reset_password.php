<?php
include "db_connect.php";

$error_msg = "";
$show_success_modal = false;
$valid_token = false;
$token = "";

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);$query = "SELECT id FROM users WHERE reset_token = '$token' LIMIT 1";
    $result = mysqli_query($conn,$query);

    if ($result && mysqli_num_rows($result) > 0) {$valid_token = true;
    } else {
        $error_msg = "Invalid or expired password reset link.";
    }
} else {
    $error_msg = "No reset token provided.";
}

if (isset($_POST['reset_password']) &&$valid_token) {
    $new_password =$_POST['new_password'];
    $confirm_password =$_POST['confirm_password'];

    if (strlen($new_password) < 8) {$error_msg = "Password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {$error_msg = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // Update password, clear reset_token, and ensure account is marked verified
        $update_query = "UPDATE users SET password = '$hashed_password', reset_token = NULL, is_verified = 1 WHERE reset_token = '$token'";

        if (mysqli_query($conn, $update_query)) {$show_success_modal = true;
        } else {
            $error_msg = "Failed to update password: " . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSB Airconditioning Services | Reset Password</title>
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
                    <h2>Reset Password</h2>
                    <p>Create a strong new password for your account.</p>
                </div>

                <?php if (!empty($error_msg)): ?>
                    <div class="error-alert">
                        <?= htmlspecialchars($error_msg) ?>
                    </div>
                <?php endif; ?>

                <?php if ($valid_token && !$show_success_modal): ?>
                <form method="post" onsubmit="return validateResetForm()">
                    
                    <div class="form-group full-width">
                        <label>New Password <span class="req">*</span></label>
                        <div class="input-icon-wrapper">
                            <i class="fa-solid fa-lock input-icon"></i>
                            <input type="password" id="newPass" name="new_password" onkeyup="checkPasswordStrength(); checkPasswordMatch();" required>
                            <i class="fa-regular fa-eye eye-btn" onclick="togglePass('newPass', this)"></i>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>Confirm New Password <span class="req">*</span></label>
                        <div class="input-icon-wrapper">
                            <i class="fa-solid fa-shield-halved input-icon"></i>
                            <input type="password" id="confirmPass" name="confirm_password" onkeyup="checkPasswordMatch()" required>
                            <i class="fa-regular fa-eye eye-btn" onclick="togglePass('confirmPass', this)"></i>
                        </div>
                        <p id="password-match-msg" style="font-size: 12px; margin-top: 6px; font-weight: 500;"></p>
                    </div>

                    <div class="password-rules">
                        <p id="rule-length">❌ At least 8 characters</p>
                        <p id="rule-upper">❌ At least 1 uppercase letter (A-Z)</p>
                        <p id="rule-lower">❌ At least 1 lowercase letter (a-z)</p>
                        <p id="rule-number">❌ At least 1 number (0-9)</p>
                        <p id="rule-special">❌ At least 1 special character (!@#$%)</p>
                    </div>

                    <button type="submit" name="reset_password" class="auth-btn">
                        Update Password <i class="fa-solid fa-check"></i>
                    </button>
                </form>
                <?php else: ?>
                    <a href="forgot_password.php" class="auth-btn" style="text-decoration: none;">
                        Request a New Reset Link <i class="fa-solid fa-rotate-right"></i>
                    </a>
                <?php endif; ?>

                <div class="auth-footer">
                    <a href="login.php">Back to Sign In</a>
                </div>
            </div>
        </div>
    </div>

    <!-- SUCCESS MODAL -->
    <?php if ($show_success_modal): ?>
    <div class="custom-modal-overlay">
        <div class="custom-modal">
            <div class="modal-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h3>Password Updated!</h3>
            <p>Your password has been reset successfully. You can now sign in with your new password.</p>
            <button onclick="window.location.href='login.php'" class="modal-btn">Sign In Now</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ERROR MODAL -->
    <div id="errorModalOverlay" class="custom-modal-overlay">
        <div class="custom-modal error-modal">
            <div class="modal-icon">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <h3>Oops!</h3>
            <p id="errorModalMessage">Error message goes here.</p>
            <button type="button" onclick="closeErrorModal()" class="modal-btn">Try Again</button>
        </div>
    </div>

    <script>
        function showErrorModal(message) {
            document.getElementById('errorModalMessage').innerText = message;
            document.getElementById('errorModalOverlay').style.display = 'flex';
        }

        function closeErrorModal() {
            document.getElementById('errorModalOverlay').style.display = 'none';
        }

        function togglePass(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function checkPasswordStrength() {
            const pass = document.getElementById("newPass").value;

            const rules = {
                length: pass.length >= 8,
                upper: /[A-Z]/.test(pass),
                lower: /[a-z]/.test(pass),
                number: /[0-9]/.test(pass),
                special: /[!@#$%^&*(),.?":{}|<>]/.test(pass)
            };

            const updateRule = (id, isValid, text) => {
                const element = document.getElementById(id);
                element.innerHTML = isValid ? `✔️ ${text}` : `❌ ${text}`;
                element.className = isValid ? 'valid-rule' : 'invalid-rule';
            };

            updateRule("rule-length", rules.length, "At least 8 characters");
            updateRule("rule-upper", rules.upper, "At least 1 uppercase letter (A-Z)");
            updateRule("rule-lower", rules.lower, "At least 1 lowercase letter (a-z)");
            updateRule("rule-number", rules.number, "At least 1 number (0-9)");
            updateRule("rule-special", rules.special, "At least 1 special character (!@#$%)");
        }

        function checkPasswordMatch() {
            const pass = document.getElementById("newPass").value;
            const confirmPass = document.getElementById("confirmPass").value;
            const matchMsg = document.getElementById("password-match-msg");

            if (confirmPass.length === 0) {
                matchMsg.innerHTML = "";
                return;
            }

            if (pass === confirmPass) {
                matchMsg.innerHTML = "✔️ Passwords match";
                matchMsg.className = "valid-rule";
            } else if (pass.startsWith(confirmPass)) {
                matchMsg.innerHTML = "";
                matchMsg.className = "";
            } else {
                matchMsg.innerHTML = "❌ Passwords do not match";
                matchMsg.className = "invalid-rule";
            }
        }

        function validateResetForm() {
            const pass = document.getElementById("newPass").value;
            const confirmPass = document.getElementById("confirmPass").value;

            const isValid = 
                pass.length >= 8 && 
                /[A-Z]/.test(pass) && 
                /[a-z]/.test(pass) && 
                /[0-9]/.test(pass) && 
                /[!@#$%^&*(),.?":{}|<>]/.test(pass);

            if (!isValid) {
                showErrorModal("Please meet all password requirements before updating.");
                return false;
            }

            if (pass !== confirmPass) {
                showErrorModal("Passwords do not match!");
                return false;
            }

            return true;
        }
    </script>
</body>
</html>