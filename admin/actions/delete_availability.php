<?php
session_start();
include "db_connect.php";

if (!isset($_SESSION['admin'])) {
    header("Location: admin_login.php");
    exit();
}

$id = intval($_GET['id'] ?? 0);

$stmt = $conn->prepare("DELETE FROM availability_schedule WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    header("Location: availability_schedule.php?deleted=1");
    exit();
} else {
    echo "Delete failed.";
}
?>