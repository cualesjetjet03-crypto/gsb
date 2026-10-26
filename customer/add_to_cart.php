<?php
session_start();
header("Content-Type: application/json");

include "db_connect.php"; // Database connection

if (!isset($_SESSION["user_id"])) {
    echo json_encode([
        "success" => false,
        "message" => "Please log in first."
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$user_id    = (int)$_SESSION["user_id"];
$product_id = isset($data["product_id"]) ? (int)$data["product_id"] : 0;
$quantity   = isset($data["quantity"])   ? max(1, (int)$data["quantity"]) : 1;

if ($product_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid product."]);
    exit;
}

// Enforce max quantity per add (prevent abuse)
if ($quantity > 99) {
    echo json_encode(["success" => false, "message" => "Quantity exceeds the maximum allowed per request."]);
    exit;
}

// Verify the product actually exists and is not archived
$prod_check = $conn->prepare("SELECT id, stock_status FROM products WHERE id = ? AND is_archived = 0 LIMIT 1");
$prod_check->bind_param("i", $product_id);
$prod_check->execute();
$prod_row = $prod_check->get_result()->fetch_assoc();
$prod_check->close();

if (!$prod_row) {
    echo json_encode(["success" => false, "message" => "Product not found or unavailable."]);
    exit;
}

if ($prod_row['stock_status'] === 'Out of Stock') {
    echo json_encode(["success" => false, "message" => "This product is currently out of stock."]);
    exit;
}

// Check if the product already exists in the cart
$check = $conn->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
$check->bind_param("ii", $user_id, $product_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $newQuantity = $row["quantity"] + $quantity;

    // Update the cart if the product already exists
    $update = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
    $update->bind_param("ii", $newQuantity, $row["id"]);
    $update->execute();
} else {
    // Insert into the cart if it's a new product
    $insert = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
    $insert->bind_param("iii", $user_id, $product_id, $quantity);
    $insert->execute();
}

echo json_encode([
    "success" => true,
    "message" => "Product added to cart."
]);