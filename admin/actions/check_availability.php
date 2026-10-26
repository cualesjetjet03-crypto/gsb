<?php
include "db_connect.php";

// Get the selected date from the GET request
$date = $_GET['date'];

// Query to check the number of bookings for the selected date
$query = "SELECT COUNT(*) as booked_slots FROM inquiries WHERE delivery_date = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $date);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

// Return the number of booked slots for the selected date
echo json_encode([
    'availableSlots' => $row['booked_slots']
]);
?>