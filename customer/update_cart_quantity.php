<?php
session_start();
include "db_connect.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "error" => "Not logged in"]);
    exit;
}

if (!isset($data['cart_id']) || !isset($data['change'])) {
    echo json_encode(["success" => false, "error" => "Missing data"]);
    exit;
}

$cart_id = intval($data['cart_id']);
$change = intval($data['change']);

// Get current quantity
$stmt = $conn->prepare("SELECT quantity FROM cart WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $cart_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $newQty = $row['quantity'] + $change;

    // prevent 0 or negative
    if ($newQty < 1) {
        $newQty = 1;
    }

    $update = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?");
    $update->bind_param("iii", $newQty, $cart_id, $_SESSION['user_id']);

    if ($update->execute()) {
        echo json_encode(["success" => true, "quantity" => $newQty]);
    } else {
        echo json_encode(["success" => false, "error" => "Update failed"]);
    }

} else {
    echo json_encode(["success" => false, "error" => "Item not found"]);
}
?>