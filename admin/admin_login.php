<?php
session_start();

require_once 'includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_helpers.php';

// ── Already logged in ─────────────────────────────────────────────────────────
if (!empty($_SESSION['admin'])) {
    header('Location: admin_dashboard.php');
    exit();
}

$error = '';

if (isset($_POST['login'])) {

    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password']      ?? '';
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // ── Brute-force check ─────────────────────────────────────────────────────
    $attempts = count_recent_attempts($conn, $username, $ip);

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $wait = ceil(lockout_remaining($conn, $username, $ip) / 60);
        $error = "Too many failed attempts. Please wait {$wait} minute(s) before trying again.";

    } elseif (empty($username) || empty($password)) {
        $error = 'Username and password are required.';

    } else {
        // ── Look up admin ─────────────────────────────────────────────────────
        $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $valid = $row && password_verify($password, $row['password']);

        if ($valid) {
            // Successful login — clear old attempts and build session
            clear_attempts($conn, $username, $ip);

            session_regenerate_id(false); // Regenerate ID while keeping session data

            $_SESSION['admin']       = $row['username'];
            $_SESSION['admin_id']    = $row['id'];
            $_SESSION['admin_name']  = $row['name']  ?? $row['username'];
            $_SESSION['admin_email'] = $row['email'] ?? '';
            $_SESSION['last_activity'] = time();

            header('Location: admin_dashboard.php');
            exit();

        } else {
            // Record failure whether username exists or not (no enumeration)
            record_failed_attempt($conn, $username, $ip);

            $remaining = MAX_LOGIN_ATTEMPTS - count_recent_attempts($conn, $username, $ip);
            if ($remaining > 0) {
                $error = "Invalid username or password. {$remaining} attempt(s) remaining.";
            } else {
                $wait  = ceil(LOCKOUT_DURATION / 60);
                $error = "Account locked. Please wait {$wait} minute(s) and try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSB Admin — Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="includes/css/admin_login.css">
    <link rel="icon"       type="image/png" href="assets/favicon.png">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h2>Admin Login</h2>

            <?php if ($error): ?>
                <div class="login-error-alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="Enter username" required autofocus>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="login_password" name="password"
                               placeholder="Enter password" required>
                        <i class="fa-regular fa-eye-slash toggle-password"
                           data-target="login_password"></i>
                    </div>
                </div>
                <button type="submit" name="login" class="login-btn">
                    Login <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>
            </form>
        </div>
    </div>

    <script>
        document.querySelectorAll('.toggle-password').forEach(function (icon) {
            icon.addEventListener('click', function () {
                var input = document.getElementById(this.dataset.target);
                if (input.type === 'password') {
                    input.type = 'text';
                    this.classList.replace('fa-eye-slash', 'fa-eye');
                } else {
                    input.type = 'password';
                    this.classList.replace('fa-eye', 'fa-eye-slash');
                }
            });
        });
    </script>
</body>
</html>
