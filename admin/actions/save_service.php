<?php
session_start();
require_once '../includes/db_connect.php';
require_once __DIR__ . '/../../includes/auth_helpers.php';

require_admin_session('../admin_login.php');

// Validate required fields
$name     = trim($_POST['customer_name'] ?? '');
$type     = trim($_POST['service_type']  ?? '');
$date     = trim($_POST['service_date']  ?? '');
$time     = trim($_POST['service_time']  ?? '');
$location = trim($_POST['location']      ?? '');

if (!$name || !$type || !$date || !$time || !$location) {
    header("Location: ../services_schedule.php?error=missing_fields");
    exit();
}

$stmt = $conn->prepare(
    "INSERT INTO services_schedule (customer_name, service_type, service_date, service_time, location)
     VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param("sssss", $name, $type, $date, $time, $location);
$stmt->execute();
$stmt->close();

header("Location: ../services_schedule.php");
exit();
