<?php
session_start();
include "db_connect.php";

header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "cartItems" => []
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];

$sql = "
    SELECT 
        c.id AS cart_id,
        c.quantity,
        p.id,
        p.brand,
        p.SeriesName,
        p.IndoorUnitModel,
        p.CapacityHP,
        p.is_non_inverter,
        p.SRP_PHP,
        p.image,
        p.stock_status
    FROM cart c
    INNER JOIN products p ON c.product_id = p.id
    WHERE c.user_id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

$cartItems = [];

while ($row = $result->fetch_assoc()) {
    $cartItems[] = $row;
}

echo json_encode([
    "cartItems" => $cartItems
]);
?>