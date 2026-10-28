<?php
require_once __DIR__ . '/../../config.php';

date_default_timezone_set('Asia/Manila');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_errno) {
    if (APP_ENV === 'production') {
        error_log('DB Connection failed: ' . $conn->connect_error);
        http_response_code(503);
        die('Service temporarily unavailable. Please try again later.');
    } else {
        die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
    }
}

$conn->set_charset('utf8mb4');
// Sync MySQL session timezone to Asia/Manila (+08:00)
$conn->query("SET time_zone = '+08:00'");
