<?php
/**
 * GSB — Auth Helpers
 * Brute-force protection and session utilities.
 * Requires $conn (mysqli) to already be available.
 */

/**
 * Count recent failed login attempts for an identifier + IP within the lockout window.
 */
function count_recent_attempts(mysqli $conn, string $identifier, string $ip): int
{
    $window = date('Y-m-d H:i:s', time() - LOCKOUT_DURATION);
    $stmt   = $conn->prepare(
        "SELECT COUNT(*) AS total FROM login_attempts
         WHERE identifier = ? AND ip_address = ? AND attempted_at >= ?"
    );
    $stmt->bind_param('sss', $identifier, $ip, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

/**
 * Record a failed attempt.
 */
function record_failed_attempt(mysqli $conn, string $identifier, string $ip): void
{
    $stmt = $conn->prepare(
        "INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)"
    );
    $stmt->bind_param('ss', $identifier, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clear attempts after a successful login (so users aren't locked out next time).
 */
function clear_attempts(mysqli $conn, string $identifier, string $ip): void
{
    $stmt = $conn->prepare(
        "DELETE FROM login_attempts WHERE identifier = ? AND ip_address = ?"
    );
    $stmt->bind_param('ss', $identifier, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * Return remaining lockout seconds (0 if not locked).
 */
function lockout_remaining(mysqli $conn, string $identifier, string $ip): int
{
    $window = date('Y-m-d H:i:s', time() - LOCKOUT_DURATION);
    $stmt   = $conn->prepare(
        "SELECT MAX(attempted_at) AS last_attempt FROM login_attempts
         WHERE identifier = ? AND ip_address = ? AND attempted_at >= ?"
    );
    $stmt->bind_param('sss', $identifier, $ip, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['last_attempt'])) return 0;

    $elapsed = time() - strtotime($row['last_attempt']);
    return max(0, LOCKOUT_DURATION - $elapsed);
}

/**
 * Enforce the session idle timeout.
 * Call at the top of every protected page.
 * @param string $redirect  Where to send expired sessions (defaults to customer login)
 */
function enforce_session_timeout(string $redirect = ''): void
{
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            $url = $redirect ?: APP_BASE_URL . '/customer/login.php?timeout=1';
            header('Location: ' . $url);
            exit();
        }
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Require an admin session. Redirects to admin login if absent or timed out.
 * Pass the depth from the calling file's directory to admin_login.php.
 */
function require_admin_session(string $login_path = 'admin_login.php'): void
{
    enforce_session_timeout($login_path . '?timeout=1');
    if (empty($_SESSION['admin'])) {
        header('Location: ' . $login_path);
        exit();
    }
}

/**
 * Generate (or retrieve) the CSRF token for the current session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Emit a hidden CSRF input field.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Validate the submitted CSRF token. Dies with 403 on failure.
 */
function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        die('Invalid or missing security token. Please go back and try again.');
    }
}
