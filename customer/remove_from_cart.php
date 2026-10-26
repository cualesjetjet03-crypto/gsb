<?php
session_start();
include "db_connect.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['cart_id'])) {
    echo json_encode(["success" => false, "error" => "No cart_id"]);
    exit();
}

$cart_id = intval($data['cart_id']);
$user_id = $_SESSION['user_id'];

// DEBUG
error_log("Deleting cart_id: $cart_id for user: $user_id");

$stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $cart_id, $user_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "error" => $stmt->error]);
}