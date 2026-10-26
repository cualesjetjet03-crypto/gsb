<?php
// get_cart_count.php
require_once 'db_connect.php';

session_start();
$userId = $_SESSION['user_id'];

if (!$userId) {
    echo json_encode(['cartCount' => 0]);
    exit;
}

$sql = "SELECT COUNT(*) AS cartCount FROM cart WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

echo json_encode(['cartCount' => $row['cartCount']]);

$stmt->close();
$conn->close();
?>