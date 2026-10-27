<?php
session_start();
include "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = intval($_SESSION['user_id']);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    // Redirect to the new wizard
    header("Location: book_service.php");
    exit();
}

// ==========================
// GET FORM DATA
// ==========================
$first_name   = trim($_POST['first_name'] ?? '');
$last_name    = trim($_POST['last_name'] ?? '');
$email        = trim($_POST['email'] ?? '');
$phone        = trim($_POST['phone'] ?? '');
$service_type = trim($_POST['service_type'] ?? '');
$service_date = trim($_POST['schedule_date'] ?? '');
$address      = trim($_POST['address'] ?? '');
$notes        = trim($_POST['notes'] ?? '');
$ac_type      = trim($_POST['ac_type'] ?? ''); // Capture from Step 3

$customer_name = trim($first_name . " " . $last_name);

// Append AC Type to notes smoothly
if (!empty($ac_type)) {
    $notes = "AC Type: " . $ac_type . ($notes ? " | Details: " . $notes : "");
}

// ==========================
// ALLOWED SERVICES
// ==========================
$allowed_services = [
    'AC Installation',
    'AC Cleaning',
    'AC Repair',
    'AC Check Up'
];

// ==========================
// REQUIRED FIELD VALIDATION
// ==========================
if (
    empty($customer_name) ||
    empty($email) ||
    empty($phone) ||
    empty($service_type) ||
    empty($service_date)
) {
    error_log("process_schedule.php: Missing required fields. name=$customer_name email=$email phone=$phone service=$service_type date=$service_date address=$address");
    header("Location: book_service.php?error=failed");
    exit();
}

// Address fallback — if full_address is empty, use POST street directly
if (empty($address)) {
    $address = trim($_POST['street'] ?? '');
}
if (empty($address)) {
    error_log("process_schedule.php: Address is empty after fallback.");
    header("Location: book_service.php?error=failed");
    exit();
}

// ==========================
// VALIDATE SERVICE & PHONE
// ==========================
if (!in_array($service_type, $allowed_services)) {
    header("Location: book_service.php?error=failed");
    exit();
}

if (!preg_match('/^09\d{9}$/', $phone)) {
    header("Location: book_service.php?error=failed");
    exit();
}

// ==========================
// CHECK IF DATE IS UNAVAILABLE OR FULLY BOOKED
// ==========================

// 1. Check if the date is manually blocked by an admin
$block_count = 0;
$stmt_block = $conn->prepare("SELECT COUNT(*) FROM availability_schedule WHERE date = ? AND LOWER(status) = 'unavailable' AND (notes IS NULL OR notes NOT LIKE 'Fully booked%')");
if ($stmt_block) {
    $stmt_block->bind_param("s", $service_date);
    $stmt_block->execute();
    $stmt_block->bind_result($block_count);
    $stmt_block->fetch();
    $stmt_block->close();
}

if ($block_count > 0) {
    header("Location: book_service.php?error=unavailable");
    exit();
}

$s_count = 0;
$i_count = 0;

// 2. Count active services (Excluding inquiries to prevent double-counting)
$stmt1 = $conn->prepare("SELECT COUNT(*) FROM services_schedule WHERE service_date = ? AND (inquiry_id IS NULL OR inquiry_id = 0) AND IFNULL(status, 'Pending') NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
if ($stmt1) {
    $stmt1->bind_param("s", $service_date);
    $stmt1->execute();
    $stmt1->bind_result($s_count);
    $stmt1->fetch();
    $stmt1->close();
}

// 3. Count active inquiries/deliveries
$stmt2 = $conn->prepare("SELECT COUNT(*) FROM inquiries WHERE delivery_date = ? AND IFNULL(status, 'Pending') NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
if ($stmt2) {
    $stmt2->bind_param("s", $service_date);
    $stmt2->execute();
    $stmt2->bind_result($i_count);
    $stmt2->fetch();
    $stmt2->close();
}

$current_active_bookings = $s_count + $i_count;

// Block submission if there are already 3 or more bookings
if ($current_active_bookings >= 3) {
    header("Location: book_service.php?error=full");
    exit();
}

// ==========================
// FULL-DAY SCHEDULE
// ==========================
$service_start_time = "00:00:00";
$service_end_time   = "23:59:59";

// ==========================
// START TRANSACTION
// ==========================
$conn->begin_transaction();

try {

    // ==========================
    // SAVE SERVICE SCHEDULE
    // ==========================
    $stmt = $conn->prepare("
        INSERT INTO services_schedule
        (
            user_id,
            customer_name,
            contact_number,
            email,
            message,
            service_type,
            service_date,
            service_time,
            location,
            status
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
    ");

    if (!$stmt) {
        throw new Exception("Prepare service schedule failed: " . $conn->error);
    }

    // Bind parameters: 1 integer (user_id) + 8 strings
    $stmt->bind_param(
        "issssssss",
        $user_id,
        $customer_name,
        $phone,
        $email,
        $notes,
        $service_type,
        $service_date,
        $service_start_time,
        $address
    );

    if (!$stmt->execute()) {
        throw new Exception("Saving service schedule failed: " . $stmt->error);
    }

    $stmt->close();

    // ==========================
    // ADD UNAVAILABLE LOCK
    // ==========================
    if ($current_active_bookings == 2) {
        $availabilityNotes = "Fully booked (3 limit reached)";

        $availabilityStmt = $conn->prepare("
            INSERT INTO availability_schedule
            (date, start_time, end_time, status, notes)
            VALUES (?, ?, ?, 'Unavailable', ?)
        ");

        if (!$availabilityStmt) {
            throw new Exception("Prepare availability schedule failed: " . $conn->error);
        }

        $availabilityStmt->bind_param(
            "ssss",
            $service_date,
            $service_start_time,
            $service_end_time,
            $availabilityNotes
        );

        if (!$availabilityStmt->execute()) {
            throw new Exception("Saving availability schedule failed: " . $availabilityStmt->error);
        }

        $availabilityStmt->close();
    }

    // ==========================
    // COMMIT
    // ==========================
    $conn->commit();

    // Redirect on success
    header("Location: book_service.php?success=1");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    header("Location: book_service.php?error=failed");
    exit();
}
?>