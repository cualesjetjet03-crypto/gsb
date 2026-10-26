<?php
session_start();

require_once 'db_connect.php';
require_once __DIR__ . '/../includes/auth_helpers.php';

// â”€â”€ Already logged in â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!empty($_SESSION['user_id'])) {
    $go = !empty($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
    header('Location: ' . $go);
    exit();
}

$login_error = '';

if (isset($_POST['login'])) {

    // Accept both 'login_id' (new) and legacy 'email' field name
    $login_id = trim($_POST['login_id'] ?? $_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // â”€â”€ Brute-force check â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $attempts = count_recent_attempts($conn, $login_id, $ip);

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $wait        = ceil(lockout_remaining($conn, $login_id, $ip) / 60);
        $login_error = "Too many failed attempts. Please wait {$wait} minute(s) before trying again.";

    } elseif (empty($login_id) || empty($password)) {
        $login_error = 'Email/username and password are required.';

    } else {
        // â”€â”€ 1. Check users table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $stmt = $conn->prepare("
            SELECT u.*, r.role_name
            FROM users u
            INNER JOIN user_roles r ON u.role_id = r.id
            WHERE u.email = ? OR u.username = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $login_id, $login_id);
        $stmt->execute();
        $authenticated_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // â”€â”€ 2. Fallback: check admins table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if (!$authenticated_user) {
            $stmt = $conn->prepare("
                SELECT a.id, a.name AS fullname, a.email, a.username, a.password, a.role_id,
                       1 AS is_verified, r.role_name
                FROM admins a
                INNER JOIN user_roles r ON a.role_id = r.id
                WHERE a.username = ? OR a.email = ?
                LIMIT 1
            ");
            $stmt->bind_param('ss', $login_id, $login_id);
            $stmt->execute();
            $authenticated_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if ($authenticated_user) {
            // â”€â”€ Verify bcrypt hash only (no plain-text fallback) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            $password_valid = password_verify($password, $authenticated_user['password']);

            if ($password_valid) {
                $role = strtolower(trim($authenticated_user['role_name'] ?? ''));

                clear_attempts($conn, $login_id, $ip);
                session_regenerate_id(false); // Prevent session fixation

                // Admin accounts must use admin/admin_login.php
                if ($role === 'admin' || (int)$authenticated_user['role_id'] === 1) {
                    $login_error = 'This login is for customer accounts only. Admins must use the Admin Panel.';
                } elseif (empty($authenticated_user['is_verified'])) {
                    $login_error = 'Please verify your email address before logging in.';
                } else {
                    $_SESSION['role_id']  = (int)$authenticated_user['role_id'];
                    $_SESSION['role']     = 'Customer';
                    $_SESSION['user_id']  = $authenticated_user['id'];
                    $_SESSION['name']     = $authenticated_user['fullname'];
                    $_SESSION['last_activity'] = time();
                    $destination = !empty($_POST['redirect'])
                        ? $_POST['redirect']
                        : (!empty($_GET['redirect']) ? $_GET['redirect'] : 'index.php');
                    header('Location: ' . $destination);
                    exit();
                }

            } else {
                record_failed_attempt($conn, $login_id, $ip);
                $remaining   = MAX_LOGIN_ATTEMPTS - count_recent_attempts($conn, $login_id, $ip);
                $login_error = $remaining > 0
                    ? "Invalid email/username or password. {$remaining} attempt(s) remaining."
                    : 'Account temporarily locked. Please try again in ' . ceil(LOCKOUT_DURATION / 60) . ' minutes.';
            }

        } else {
            // Still record the attempt even for non-existent accounts (prevent enumeration)
            record_failed_attempt($conn, $login_id, $ip);
            $login_error = 'Invalid email/username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSB Airconditioning Services | Login</title>
    
    <!-- Updated to v=1.4 for the back-link CSS -->
    <link rel="stylesheet" href="css/login.css?v=1.4">
    <link rel="icon" type="image/png" href="favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

    <div class="auth-wrapper">
        <div class="auth-card">
            
            <!-- LEFT COLUMN: PHOTO / LOGO -->
            <div class="auth-illustration">
                <img src="favicon.png" alt="GSB Airconditioning Services Logo">
            </div>

            <!-- RIGHT COLUMN: LOGIN FORM -->
            <div class="auth-form-container">
                
                <!-- Back to Home Button -->
                <a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Home</a>

                <div class="form-header">
                    <h2>Please Login to GSB Airconditioning Services</h2>
                    <p>Please enter your details!</p>
                </div>

                <!-- SHOW ERROR -->
                <?php if (!empty($login_error)): ?>
                    <div class="error-alert">
                        <?= htmlspecialchars($login_error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?php if (!empty($_GET['redirect'])): ?>
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect']) ?>">
                    <?php endif; ?>
                    
                    <div class="form-group full-width">
                        <label>Email or Username</label>
                        <div class="input-icon-wrapper">
                            <i class="fa-regular fa-user input-icon"></i>
                            <input type="text" name="login_id" value="<?= isset($_POST['login_id']) ? htmlspecialchars($_POST['login_id']) : '' ?>" placeholder="Email or Username" required>
                        </div>
                    </div>

                    <div class="form-group full-width" style="margin-bottom: 8px;">
                        <label>Password</label>
                        <div class="input-icon-wrapper">
                            <i class="fa-solid fa-lock input-icon"></i>
                            <input type="password" id="loginPass" name="password" required>
                            <i class="fa-regular fa-eye eye-btn" onclick="toggleLoginPass()"></i>
                        </div>
                    </div>

                    <!-- Forgot Password Link -->
                    <div style="text-align: right; margin-bottom: 25px;">
                        <a href="forgot_password.php" style="font-size: 12px; color: var(--primary); font-weight: 500; text-decoration: underline;">Forgot Password?</a>
                    </div>

                    <button type="submit" name="login" class="auth-btn">
                        Login <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>

                <div class="auth-footer">
                    Don't have an account? <a href="register.php">Register</a>
                </div>
            </div>

        </div>
    </div>

    <script>
        function toggleLoginPass() {
            const input = document.getElementById('loginPass');
            const icon = document.querySelector('.eye-btn');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
