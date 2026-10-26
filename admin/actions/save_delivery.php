<?php
session_start();
require_once '../includes/db_connect.php';
require_once __DIR__ . '/../../includes/auth_helpers.php';

require_admin_session('../admin_login.php');

// Validate required fields
$name    = trim($_POST['customer_name']  ?? '');
$product = trim($_POST['product_name']   ?? '');
$date    = trim($_POST['delivery_date']  ?? '');
$address = trim($_POST['address']        ?? '');

if (!$name || !$product || !$date || !$address) {
    header("Location: ../deliveries.php?error=missing_fields");
    exit();
}

$stmt = $conn->prepare(
    "INSERT INTO deliveries (customer_name, product_name, delivery_date, address)
     VALUES (?, ?, ?, ?)"
);
$stmt->bind_param("ssss", $name, $product, $date, $address);
$stmt->execute();
$stmt->close();

header("Location: ../deliveries.php");
exit();
