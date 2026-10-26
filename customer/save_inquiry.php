<?php

session_start();
include "db_connect.php";

/* =====================================================
   CHECK IF CUSTOMER IS LOGGED IN
===================================================== */

if (!isset($_SESSION['user_id'])) {

    echo "<script>
        alert('Please login first.');
        window.location='login.php';
    </script>";

    exit();
}

$user_id = (int) $_SESSION['user_id'];


/* =====================================================
   GET CUSTOMER ACCOUNT INFORMATION
===================================================== */

$user_stmt = $conn->prepare("
    SELECT
        fullname,
        email,
        phone,
        address
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$user_stmt) {
    die("Prepare failed: " . $conn->error);
}

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$user_result = $user_stmt->get_result();

if (!$user_result || $user_result->num_rows === 0) {

    $user_stmt->close();

    echo "<script>
        alert('Customer account could not be found.');
        window.location='login.php';
    </script>";

    exit();
}

$user = $user_result->fetch_assoc();

$user_stmt->close();


/* =====================================================
   GET FORM DATA
===================================================== */

$product = trim(
    $_POST['product']
    ?? $_POST['product_name']
    ?? ''
);

$brand = trim(
    $_POST['brand']
    ?? ''
);

// Capture the quantity from the form
$quantity_raw = trim(
    $_POST['quantity'] 
    ?? '1'
);

$name = trim(
    $_POST['customer_name']
    ?? $_POST['name']
    ?? $_POST['fullname']
    ?? $user['fullname']
    ?? ''
);

$contact = trim(
    $_POST['phone']
    ?? $_POST['contact']
    ?? $_POST['contact_number']
    ?? $user['phone']
    ?? ''
);

$email = trim(
    $_POST['email']
    ?? $_POST['email_address']
    ?? $user['email']
    ?? ''
);

$message = trim(
    $_POST['message']
    ?? $_POST['question']
    ?? $_POST['message_question']
    ?? ''
);

$address = trim(
    $_POST['address']
    ?? $user['address']
    ?? ''
);

$deliveryDate = trim(
    $_POST['deliveryDate']
    ?? $_POST['delivery_date']
    ?? ''
);

// Capture Free Installation choice from the frontend form
$free_installation = trim($_POST['free_installation'] ?? 'No');

/* =====================================================
   VALIDATION
===================================================== */

if ($product === '') {
    echo "<script>
        alert('Please select a product before submitting your inquiry.');
        window.history.back();
    </script>";
    exit();
}

if ($name === '') {
    echo "<script>
        alert('Please enter your full name.');
        window.history.back();
    </script>";
    exit();
}

if ($contact === '') {
    echo "<script>
        alert('Please enter your phone number.');
        window.history.back();
    </script>";
    exit();
}

if ($email === '') {
    echo "<script>
        alert('Please enter your email address.');
        window.history.back();
    </script>";
    exit();
}

if ($address === '') {
    echo "<script>
        alert('Please enter your address.');
        window.history.back();
    </script>";
    exit();
}

if ($deliveryDate === '') {
    echo "<script>
        alert('Please select a delivery schedule.');
        window.history.back();
    </script>";
    exit();
}


/* =====================================================
   FIND PRODUCT ID
===================================================== */

$product_id = null;

// Only fetches the first ID if multiple products are passed, 
// to satisfy the inquiries table foreign key requirement.
$first_product_model = explode(',', $product)[0]; 
$first_brand = explode(',', $brand)[0];

$product_stmt = $conn->prepare("
    SELECT id
    FROM products
    WHERE IndoorUnitModel = ?
    AND brand = ?
    LIMIT 1
");

if ($product_stmt) {

    $product_stmt->bind_param(
        "ss",
        $first_product_model,
        $first_brand
    );

    $product_stmt->execute();

    $product_result = $product_stmt->get_result();

    if ($product_result && $product_result->num_rows > 0) {
        $product_row = $product_result->fetch_assoc();
        $product_id = (int) $product_row['id'];
    }

    $product_stmt->close();
}


/* =====================================================
   INSERT INQUIRY
===================================================== */

$stmt = $conn->prepare("
    INSERT INTO inquiries
    (
        user_id,
        product_id,
        customer_name,
        contact_number,
        email,
        product_name,
        brand,
        message,
        address,
        delivery_date,
        free_installation,
        status,
        date_submitted
    )
    VALUES
    (
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        'Pending',
        NOW()
    )
");

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

/* =====================================================
   SAVE INQUIRY
===================================================== */

$stmt->bind_param(
    "iisssssssss",
    $user_id,
    $product_id,
    $name,
    $contact,
    $email,
    $product,
    $brand,
    $message,
    $address,
    $deliveryDate,
    $free_installation
);


if ($stmt->execute()) {

    $stmt->close();

    /* =====================================================
       AUTOMATIC STOCK DEDUCTION & STATUS UPDATE
    ===================================================== */
    // Split the comma-separated strings into arrays in case of bulk orders
    $modelList = array_map('trim', explode(',', $product));
    $qtyList = array_map('trim', explode(',', $quantity_raw));

    foreach ($modelList as $index => $model) {
        if (empty($model)) continue;

        // Match the quantity to the specific product, default to 1 if missing
        $qty_to_deduct = isset($qtyList[$index]) ? (int)$qtyList[$index] : 1;

        // Fetch current stock info for this specific product
        $stock_stmt = $conn->prepare("SELECT id, stock_quantity FROM products WHERE IndoorUnitModel = ? LIMIT 1");
        if ($stock_stmt) {
            $stock_stmt->bind_param("s", $model);
            $stock_stmt->execute();
            $stock_res = $stock_stmt->get_result();

            if ($stock_res && $stock_res->num_rows > 0) {
                $pRow = $stock_res->fetch_assoc();
                $pId = $pRow['id'];
                $currentStock = (int)$pRow['stock_quantity'];

                // Calculate new stock ensuring it doesn't go below 0
                $newStock = max(0, $currentStock - $qty_to_deduct);
                
                // SMART THRESHOLD LOGIC
                if ($newStock === 0) {
                    $newStatus = 'Out of Stock';
                } elseif ($newStock <= 3) {
                    $newStatus = 'Limited Stock';
                } else {
                    $newStatus = 'Available';
                }

                // Update the database with the new deducted values and smart status
                $update_stmt = $conn->prepare("UPDATE products SET stock_quantity = ?, stock_status = ? WHERE id = ?");
                if ($update_stmt) {
                    $update_stmt->bind_param("isi", $newStock, $newStatus, $pId);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
            $stock_stmt->close();
        }
    }
    /* ===================================================== */

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Submission Successful</title>
    <link rel="icon" type="image/png" href="favicon.png">
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    background-color: #f8fafc; /* Light gray background to replace the blank screen */
                    margin: 0; 
                }
                .modal { 
                    display: flex; 
                    position: fixed; 
                    z-index: 10000; 
                    left: 0; 
                    top: 0; 
                    width: 100%; 
                    height: 100%; 
                    align-items: center; 
                    justify-content: center; 
                    background: rgba(0,0,0,0.6); 
                }
                .modal-content { 
                    background: #fff; 
                    max-width: 400px; 
                    width: 90%; 
                    text-align: center; 
                    padding: 30px 20px; 
                    border-radius: 8px; 
                    box-shadow: 0 4px 6px rgba(0,0,0,0.1); 
                }
                h3 { 
                    margin-top: 0; 
                    color: #1e293b; 
                    margin-bottom: 15px; 
                    font-size: 1.25rem; 
                }
                p { 
                    color: #475569; 
                    margin-bottom: 25px; 
                    line-height: 1.5; 
                    font-size: 0.95rem; 
                }
                button { 
                    background: #1e3a8a; 
                    color: white; 
                    border: none; 
                    padding: 10px 25px; 
                    border-radius: 5px; 
                    cursor: pointer; 
                    font-weight: 600; 
                    font-size: 1rem; 
                    width: 100%; 
                }
                button:hover {
                    background: #172554;
                }
            </style>
        </head>
        <body>

        <div class="modal">
            <div class="modal-content">
                <h3>Success</h3>
                <p>Order Sent Successfully!</p>
                <button id="okBtn">OK</button>
            </div>
        </div>

        <script>
            document.getElementById('okBtn').onclick = function() {
                // Change 'index.php' to wherever you want the user to go after clicking OK
                window.location.href = 'index.php'; 
            };
        </script>
        </body>
        </html>
        <?php
        exit(); // Ensures no further PHP processing happens after the modal is displayed

} else {

    echo "Error: " . $stmt->error;

    $stmt->close();
}

?>