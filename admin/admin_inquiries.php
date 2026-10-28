<?php

session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: admin_login.php");
    exit();
}

include "includes/db_connect.php";

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =========================================================
   AJAX: FETCH AVAILABLE TECHNICIANS FOR A SPECIFIC DATE
========================================================= */
if (isset($_GET['action']) && $_GET['action'] === 'get_technicians') {
    header('Content-Type: application/json');
    $date = trim($_GET['date'] ?? '');
    $inquiry_id = isset($_GET['inquiry_id']) ? (int)$_GET['inquiry_id'] : 0;

    // Checks the global schedule for BOTH orders and regular services
    $stmt = $conn->prepare("
        SELECT t.id, t.technician_name,
               COUNT(ss.id) as is_booked,
               GROUP_CONCAT(CONCAT(TIME_FORMAT(ss.service_time, '%h:%i %p'), ' for ', IFNULL(ss.customer_name, 'Customer')) SEPARATOR ', ') as booked_details
        FROM technicians t
        LEFT JOIN services_schedule ss 
            ON ss.technician_id = t.id 
            AND ss.service_date = ? 
            AND (ss.inquiry_id != ? OR ? = 0 OR ss.inquiry_id IS NULL)
            AND ss.status NOT IN ('Cancelled', 'Declined', 'Rejected', 'Deleted', 'Completed', 'Sold')
        WHERE t.status != 'Inactive'
        GROUP BY t.id
        ORDER BY t.technician_name ASC
    ");
    
    // Notice we bind the inquiry_id twice now
    $stmt->bind_param("sii", $date, $inquiry_id, $inquiry_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $techs = [];
    while ($row = $res->fetch_assoc()) {
        $techs[] = $row;
    }
    
    echo json_encode($techs);
    exit();
}

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function clean($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function statusClass($status)
{
    $status = $status ?: 'Pending';

    return strtolower(
        str_replace(' ', '-', $status)
    );
}

/* =========================================================
   SCHEDULE / RE-SCHEDULE SERVICE
========================================================= */

if (isset($_POST['schedule_service'])) {

    $inquiry_id = intval($_POST['inquiry_id'] ?? 0);
    $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
    $customer_name = trim($_POST['customer_name'] ?? '');
    $service_date = trim($_POST['service_date'] ?? '');
    $service_time = trim($_POST['service_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $technician_id = intval($_POST['technician_id'] ?? 0);

    /* -----------------------------------------------------
        VALIDATION
    ----------------------------------------------------- */

    if ($inquiry_id <= 0) {
        $error = "Invalid order selected.";
    } elseif (
        empty($customer_name) ||
        empty($service_date) ||
        empty($service_time) ||
        empty($location) ||
        $technician_id <= 0
    ) {
        $error = "Please complete all service schedule fields.";
    } else {

        /* -------------------------------------------------
            CHECK TECHNICIAN
        ------------------------------------------------- */

        $tech_check = $conn->prepare("
            SELECT id, technician_name, contact_number, status
            FROM technicians
            WHERE id = ? AND status != 'Inactive'
            LIMIT 1
        ");

        if (!$tech_check) {
            $error = "Failed to check technician: " . $conn->error;
        } else {
            $tech_check->bind_param("i", $technician_id);
            $tech_check->execute();
            $tech_result = $tech_check->get_result();

            if (!$tech_result || $tech_result->num_rows === 0) {
                $error = "Selected technician is not available.";
                $tech_check->close();
            } else {
                $tech = $tech_result->fetch_assoc();
                $tech_check->close();

                /* -------------------------------------------------
                    CHECK IF THIS INQUIRY ALREADY HAS A SCHEDULE
                ------------------------------------------------- */

                $check_stmt = $conn->prepare("
                    SELECT id, technician_id, service_date, service_time
                    FROM services_schedule
                    WHERE inquiry_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");

                if (!$check_stmt) {
                    $error = "Failed to check existing schedule: " . $conn->error;
                } else {
                    $check_stmt->bind_param("i", $inquiry_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                    $existing_schedule = null;
                    if ($check_result && $check_result->num_rows > 0) {
                        $existing_schedule = $check_result->fetch_assoc();
                    }
                    $check_stmt->close();

                    /* =================================================
                        START DATABASE TRANSACTION
                    ================================================= */
                    $conn->begin_transaction();

                    try {
                        /* ---------------------------------------------
                            GET OLD TECHNICIAN & OLD SCHEDULE DATE
                        --------------------------------------------- */
                        $old_technician_id = null;
                        $old_service_date = null;

                        if ($existing_schedule) {
                            $old_technician_id = !empty($existing_schedule['technician_id']) ? (int)$existing_schedule['technician_id'] : null;
                            $old_service_date = $existing_schedule['service_date'];
                        }

                        /* ---------------------------------------------
                            UPDATE INQUIRY WITH TECHNICIAN
                        --------------------------------------------- */
                        $inquiry_stmt = $conn->prepare("
                            UPDATE inquiries SET technician_id = ? WHERE id = ?
                        ");

                        if (!$inquiry_stmt) {
                            throw new Exception("Failed to prepare order update: " . $conn->error);
                        }

                        $inquiry_stmt->bind_param("ii", $technician_id, $inquiry_id);
                        if (!$inquiry_stmt->execute()) {
                            throw new Exception("Failed to assign technician to order: " . $inquiry_stmt->error);
                        }
                        $inquiry_stmt->close();

                        /* ---------------------------------------------
                            UPDATE EXISTING SCHEDULE
                        --------------------------------------------- */
                        if ($existing_schedule) {
                            $schedule_id = (int)$existing_schedule['id'];
                            $stmt = $conn->prepare("
                                UPDATE services_schedule
                                SET user_id = ?, customer_name = ?, service_date = ?, service_time = ?, location = ?, technician_id = ?, status = 'Approved'
                                WHERE id = ?
                            ");

                            if (!$stmt) {
                                throw new Exception("Failed to prepare schedule update: " . $conn->error);
                            }

                            $stmt->bind_param("issssii", $user_id, $customer_name, $service_date, $service_time, $location, $technician_id, $schedule_id);
                            if (!$stmt->execute()) {
                                throw new Exception("Failed to update service schedule: " . $stmt->error);
                            }
                            $stmt->close();

                        } else {
                            /* -----------------------------------------
                               CREATE NEW SERVICE SCHEDULE
                            ----------------------------------------- */
                            $stmt = $conn->prepare("
                                INSERT INTO services_schedule
                                (inquiry_id, user_id, customer_name, service_date, service_time, location, technician_id, status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 'Approved')
                            ");

                            if (!$stmt) {
                                throw new Exception("Failed to prepare schedule insert: " . $conn->error);
                            }

                            $stmt->bind_param("iissssi", $inquiry_id, $user_id, $customer_name, $service_date, $service_time, $location, $technician_id);
                            if (!$stmt->execute()) {
                                throw new Exception("Failed to create service schedule: " . $stmt->error);
                            }
                            $stmt->close();
                        }

                        /* ---------------------------------------------
                            UPDATE INQUIRY STATUS AND DELIVERY DATE
                        --------------------------------------------- */
                        $combined_delivery_date = $service_date . ' ' . $service_time;

                        $update_stmt = $conn->prepare("
                            UPDATE inquiries SET status = 'Approved', delivery_date = ? WHERE id = ?
                        ");

                        if (!$update_stmt) {
                            throw new Exception("Failed to prepare order status update: " . $conn->error);
                        }

                        $update_stmt->bind_param("si", $combined_delivery_date, $inquiry_id);
                        if (!$update_stmt->execute()) {
                            throw new Exception("Failed to update order status: " . $update_stmt->error);
                        }
                        $update_stmt->close();

                        /* =================================================
                            ✅ 3-INQUIRIES PER DAY LIMIT & AUTO-BLOCK LOGIC
                        ================================================= */
                        // 1. Check new date count
                        $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM services_schedule WHERE service_date = ? AND (inquiry_id IS NULL OR inquiry_id = 0) AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                        if ($count_stmt) {
                            $count_stmt->bind_param("s", $service_date);
                            $count_stmt->execute();
                            $total_on_date = intval($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
                            $count_stmt->close();
                            
                            $inquiry_count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM inquiries WHERE DATE(delivery_date) = ? AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                            if ($inquiry_count_stmt) {
                                $inquiry_count_stmt->bind_param("s", $service_date);
                                $inquiry_count_stmt->execute();
                                $total_on_date += intval($inquiry_count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
                                $inquiry_count_stmt->close();
                            }

                            if ($total_on_date >= 3) {
                                // Block the day if not already blocked
                                $block_check = $conn->prepare("SELECT id FROM availability_schedule WHERE date = ? AND (status = 'Unavailable' OR status = 'Blocked') LIMIT 1");
                                if ($block_check) {
                                    $block_check->bind_param("s", $service_date);
                                    $block_check->execute();
                                    if ($block_check->get_result()->num_rows === 0) {
                                        $insert_block = $conn->prepare("INSERT INTO availability_schedule (date, start_time, end_time, status, notes) VALUES (?, '00:00:00', '23:59:59', 'Unavailable', 'Fully booked (3 limit reached)')");
                                        if ($insert_block) {
                                            $insert_block->bind_param("s", $service_date);
                                            $insert_block->execute();
                                            $insert_block->close();
                                        }
                                    }
                                    $block_check->close();
                                }
                            }
                        }

                        // 2. If rescheduled from an old date, check if old date dropped below 3 and unblock if needed
                        if (!empty($old_service_date) && $old_service_date !== $service_date) {
                            $old_count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM services_schedule WHERE service_date = ? AND (inquiry_id IS NULL OR inquiry_id = 0) AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                            if ($old_count_stmt) {
                                $old_count_stmt->bind_param("s", $old_service_date);
                                $old_count_stmt->execute();
                                $old_total = intval($old_count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
                                $old_count_stmt->close();

                                $old_inq_count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM inquiries WHERE DATE(delivery_date) = ? AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                                if ($old_inq_count_stmt) {
                                    $old_inq_count_stmt->bind_param("s", $old_service_date);
                                    $old_inq_count_stmt->execute();
                                    $old_total += intval($old_inq_count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
                                    $old_inq_count_stmt->close();
                                }

                                if ($old_total < 3) {
                                    $remove_block = $conn->prepare("DELETE FROM availability_schedule WHERE date = ? AND (status = 'Unavailable' OR status = 'Blocked')");
                                    if ($remove_block) {
                                        $remove_block->bind_param("s", $old_service_date);
                                        $remove_block->execute();
                                        $remove_block->close();
                                    }
                                }
                            }
                        }

                        /* ---------------------------------------------
                            IF TECHNICIAN WAS CHANGED, RELEASE OLD TECHNICIAN
                        --------------------------------------------- */
                        if ($old_technician_id && $old_technician_id != $technician_id) {
                            $old_tech_stmt = $conn->prepare("UPDATE technicians SET status = 'Available' WHERE id = ?");
                            if ($old_tech_stmt) {
                                $old_tech_stmt->bind_param("i", $old_technician_id);
                                $old_tech_stmt->execute();
                                $old_tech_stmt->close();
                            }
                        }

                        /* ---------------------------------------------
                            MARK NEW TECHNICIAN AS ASSIGNED
                        --------------------------------------------- */
                        $tech_stmt = $conn->prepare("UPDATE technicians SET status = 'Assigned' WHERE id = ?");
                        if (!$tech_stmt) {
                            throw new Exception("Failed to prepare technician update: " . $conn->error);
                        }
                        $tech_stmt->bind_param("i", $technician_id);
                        if (!$tech_stmt->execute()) {
                            throw new Exception("Failed to update technician status: " . $tech_stmt->error);
                        }
                        $tech_stmt->close();

                        /* ---------------------------------------------
                            SAVE EVERYTHING
                        --------------------------------------------- */
                        $conn->commit();
                        header("Location: admin_inquiries.php?scheduled=1");
                        exit();

                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = $e->getMessage();
                    }
                }
            }
        }
    }
}

/* =========================================================
   UPDATE INQUIRY STATUS
========================================================= */

if (isset($_POST['update_status'])) {
    $id = intval($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    // Include Installation in the allowed array
    $allowed_status = ['Pending', 'Approved', 'Out for Delivery', 'Sold', 'Installation', 'Cancelled'];

    if ($id <= 0) {
        $error = "Invalid order selected.";
    } elseif (in_array($status, $allowed_status, true)) {
        
        $stmt = $conn->prepare("UPDATE inquiries SET status = ? WHERE id = ?");
        if (!$stmt) {
            $error = "Prepare failed: " . $conn->error;
        } else {
            $stmt->bind_param("si", $status, $id);
            if ($stmt->execute()) {
                $stmt->close();

                /* =========================================
                   NOTIFY THE CUSTOMER
                ========================================= */
                $notif_user_query = $conn->prepare("SELECT user_id, product_name FROM inquiries WHERE id = ? LIMIT 1");
                $notif_user_query->bind_param("i", $id);
                $notif_user_query->execute();
                $notif_user_res = $notif_user_query->get_result();
                if ($notif_row = $notif_user_res->fetch_assoc()) {
                    if (!empty($notif_row['user_id'])) {
                        $uid = $notif_row['user_id'];
                        $prod_name = $notif_row['product_name'];
                        $title = "Order Status: " . $status;
                        $msg = "Your order for '$prod_name' is now marked as $status.";
                        
                        $insert_notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, ?, ?, 0)");
                        $insert_notif->bind_param("iss", $uid, $title, $msg);
                        $insert_notif->execute();
                        $insert_notif->close();
                    }
                }
                $notif_user_query->close();

                
                /* -----------------------------------------
                   UPDATE RELATED SERVICE SCHEDULE
                ----------------------------------------- */
                $schedule_stmt = $conn->prepare("UPDATE services_schedule SET status = ? WHERE inquiry_id = ?");
                if ($schedule_stmt) {
                    $schedule_stmt->bind_param("si", $status, $id);
                    $schedule_stmt->execute();
                    $schedule_stmt->close();
                }

                /* -----------------------------------------
                   IF CANCELLED OR SOLD, RELEASE ASSIGNED TECHNICIAN & CLEANUP CALENDAR
                ----------------------------------------- */
                if ($status === 'Sold' || $status === 'Cancelled') {
                    $release_stmt = $conn->prepare("
                        UPDATE technicians t
                        INNER JOIN inquiries i ON i.technician_id = t.id
                        SET t.status = 'Available'
                        WHERE i.id = ?
                    ");
                    if ($release_stmt) {
                        $release_stmt->bind_param("i", $id);
                        $release_stmt->execute();
                        $release_stmt->close();
                    }

                    // Calendar Cleanup check
                    $date_lookup = $conn->query("SELECT DATE(delivery_date) as service_date FROM inquiries WHERE id = $id");
                    $service_date = ($date_lookup && $date_lookup->num_rows > 0) ? $date_lookup->fetch_assoc()['service_date'] : null;

                    if ($service_date) {
                        $active_services = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE service_date = '$service_date' AND (inquiry_id IS NULL OR inquiry_id = 0) AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                        $s_count = $active_services ? (int)$active_services->fetch_assoc()['total'] : 0;
                        
                        $active_inquiries = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE DATE(delivery_date) = '$service_date' AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                        $i_count = $active_inquiries ? (int)$active_inquiries->fetch_assoc()['total'] : 0;
                        
                        if (($s_count + $i_count) < 3) {
                            $conn->query("DELETE FROM availability_schedule WHERE date = '$service_date' AND LOWER(status) != 'available'");
                        }
                    }
                }

                header("Location: admin_inquiries.php?success=1");
                exit();
            } else {
                $error = "Failed to update order status: " . $stmt->error;
                $stmt->close();
            }
        }
    } else {
        $error = "Invalid status selected.";
    }
}

/* =========================================================
   DELETE INQUIRY
========================================================= */

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    if ($id > 0) {
        $tech_id = null;
        $sched_date = null;

        $tech_lookup = $conn->prepare("SELECT technician_id, DATE(delivery_date) as sched_date FROM inquiries WHERE id = ? LIMIT 1");
        if ($tech_lookup) {
            $tech_lookup->bind_param("i", $id);
            $tech_lookup->execute();
            $tech_result = $tech_lookup->get_result();
            if ($tech_result && $tech_result->num_rows > 0) {
                $tech_row = $tech_result->fetch_assoc();
                $tech_id = !empty($tech_row['technician_id']) ? (int)$tech_row['technician_id'] : null;
                $sched_date = $tech_row['sched_date'];
            }
            $tech_lookup->close();
        }

        $schedule_delete = $conn->prepare("DELETE FROM services_schedule WHERE inquiry_id = ?");
        if ($schedule_delete) {
            $schedule_delete->bind_param("i", $id);
            $schedule_delete->execute();
            $schedule_delete->close();
        }

        $stmt = $conn->prepare("DELETE FROM inquiries WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $stmt->close();

                if ($tech_id) {
                    $release_stmt = $conn->prepare("UPDATE technicians SET status = 'Available' WHERE id = ?");
                    if ($release_stmt) {
                        $release_stmt->bind_param("i", $tech_id);
                        $release_stmt->execute();
                        $release_stmt->close();
                    }
                }

                /* =========================================
                   CHECK IF DELETION FREED UP THE DAY (< 3)
                ========================================= */
                if (!empty($sched_date)) {
                    $active_services = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE service_date = '$sched_date' AND (inquiry_id IS NULL OR inquiry_id = 0) AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                    $s_count = $active_services ? (int)$active_services->fetch_assoc()['total'] : 0;
                    
                    $active_inquiries = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE DATE(delivery_date) = '$sched_date' AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                    $i_count = $active_inquiries ? (int)$active_inquiries->fetch_assoc()['total'] : 0;
                    
                    if (($s_count + $i_count) < 3) {
                        $conn->query("DELETE FROM availability_schedule WHERE date = '$sched_date' AND LOWER(status) != 'available'");
                    }
                }

                header("Location: admin_inquiries.php?deleted=1");
                exit();
            } else {
                $error = "Failed to delete order: " . $stmt->error;
                $stmt->close();
            }
        }
    }
}

/* =========================================================
   SEARCH & FETCH DATA
========================================================= */

$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// FIFO is preserved inherently by ORDER BY i.date_submitted ASC
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $stmt = $conn->prepare("
        SELECT i.*, p.SRP_PHP as price, p.is_promo, p.discount_percent, p.brand, p.CapacityHP as hp
        FROM inquiries i
        LEFT JOIN products p ON i.product_id = p.id
        WHERE (i.customer_name LIKE ?
        OR i.contact_number LIKE ?
        OR i.email LIKE ?
        OR i.product_name LIKE ?
        OR i.status LIKE ?)
        AND i.status NOT IN ('Sold', 'Cancelled')
        ORDER BY i.date_submitted ASC, i.id ASC
    ");

    if (!$stmt) {
        die("Search prepare failed: " . $conn->error);
    }
    $stmt->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("
        SELECT i.*, p.SRP_PHP as price, p.is_promo, p.discount_percent, p.brand, p.CapacityHP as hp
        FROM inquiries i
        LEFT JOIN products p ON i.product_id = p.id
        WHERE i.status NOT IN ('Sold', 'Cancelled')
        ORDER BY i.date_submitted ASC, i.id ASC
    ");
}

/* =========================================================
   GROUP DATA FOR TABS (PRESERVES FIFO AUTOMATICALLY)
========================================================= */
$grouped_inquiries = [
    'Pending' => [],
    'Approved' => [],
    'Out for Delivery' => [],
    'Installation' => []
];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $status = $row['status'] ?: 'Pending';
        if (array_key_exists($status, $grouped_inquiries)) {
            $grouped_inquiries[$status][] = $row;
        }
    }
}

/* =========================================================
   COUNTS 
========================================================= */

$total_res = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status NOT IN ('Sold', 'Cancelled')");
$total = $total_res ? (int)$total_res->fetch_assoc()['total'] : 0;

$pending_res = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'Pending'");
$pending = $pending_res ? (int)$pending_res->fetch_assoc()['total'] : 0;

$out_of_delivery_res = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'Out for Delivery'");
$out_of_delivery = $out_of_delivery_res ? (int)$out_of_delivery_res->fetch_assoc()['total'] : 0;

$approve_res = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'Approved'");
$approve = $approve_res ? (int)$approve_res->fetch_assoc()['total'] : 0;

$sold_res = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'Sold'");
$sold = $sold_res ? (int)$sold_res->fetch_assoc()['total'] : 0;

/* --- FETCH INSTALLATION COUNT --- */
$installation_res = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'Installation'");
$installation = $installation_res ? (int)$installation_res->fetch_assoc()['total'] : 0;

/* =========================================================
   FETCH DATA FOR CALENDAR MODAL
========================================================= */
$calendarEvents = [];

// 1. Fetch active bookings (services + inquiries)
$serviceBookingsQuery = "
    SELECT date, SUM(total) as combined_total FROM (
        SELECT service_date AS date, COUNT(*) as total 
        FROM services_schedule 
        WHERE (inquiry_id IS NULL OR inquiry_id = 0)
        AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted') 
        GROUP BY service_date
        
        UNION ALL
        
        SELECT DATE(delivery_date) AS date, COUNT(*) as total 
        FROM inquiries 
        WHERE delivery_date IS NOT NULL 
        AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted') 
        GROUP BY DATE(delivery_date)
    ) as combined_bookings
    GROUP BY date
    ";
$serviceBookingsResult = $conn->query($serviceBookingsQuery);
if ($serviceBookingsResult && $serviceBookingsResult->num_rows > 0) {
    while ($row = $serviceBookingsResult->fetch_assoc()) {
        $total_count = (int)$row['combined_total'];
        $backgroundColor = "#16a34a"; // Green (1 booking)
        if ($total_count === 2) $backgroundColor = "#eab308"; // Yellow (2 bookings)
        if ($total_count >= 3) $backgroundColor = "#dc2626"; // Red (3 bookings)

        $calendarEvents[] = [
            "title" => "📅 " . $total_count . " Booking" . ($total_count > 1 ? "s" : ""),
            "start" => $row['date'],
            "allDay" => true,
            "backgroundColor" => $backgroundColor,
            "borderColor" => $backgroundColor,
            "textColor" => "#ffffff",
            "count" => $total_count
        ];
    }
}

// 2. Fetch manual unavailable blocks
$availability = $conn->query("SELECT * FROM availability_schedule WHERE LOWER(status) = 'unavailable' AND (notes IS NULL OR notes NOT LIKE '%Fully booked%')");
if ($availability && $availability->num_rows > 0) {
    while ($row = $availability->fetch_assoc()) {
        $calendarEvents[] = [
            "title" => "🔴 Blocked",
            "start" => $row['date'],
            "allDay" => true,
            "backgroundColor" => "#dc2626",
            "borderColor" => "#dc2626",
            "textColor" => "#ffffff",
            "isBlock" => true
        ];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Orders</title>
    <link rel="stylesheet" href="includes/css/admin_dashboard.css">
    <link rel="stylesheet" href="includes/css/admin_inquiries.css">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- FullCalendar CSS & JS for Modal Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.js"></script>
</head>

<body>
<div class="admin-wrapper">
    <?php include "includes/admin_sidebar.php"; ?>

    <main class="main-content">
        <!-- CENTRALIZED TOPBAR -->
        <?php 
        $pending_count = $pending;
        include 'includes/topbar.php'; 
        ?>

        <!-- PAGE HEADER -->
        <section class="page-header">
            <div class="row-header">
                <div>
                    <h1>Orders</h1>
                    <p>Manage customer orders, update status, and schedule service appointments.</p>
                </div>
                <form method="GET" class="inquiry-search-form">
                    <div class="mini-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" placeholder="Search orders..." value="<?php echo clean($search); ?>">
                    </div>
                    <button type="submit" class="primary-btn">Search</button>
                </form>
            </div>
        </section>

        <!-- ALERTS -->
        <?php if (isset($_GET['success'])) { ?>
            <div class="alert success">Order status updated successfully.</div>
        <?php } ?>
        <?php if (isset($_GET['deleted'])) { ?>
            <div class="alert success">Order deleted successfully.</div>
        <?php } ?>
        <?php if (isset($_GET['scheduled'])) { ?>
            <div class="alert success">Service appointment saved successfully.</div>
        <?php } ?>
        <?php if (isset($error)) { ?>
            <div class="alert error"><?php echo clean($error); ?></div>
        <?php } ?>

        <!-- STATUS CARDS -->
        <section class="summary-grid">
            <div class="summary-card orange">
                <div>
                    <p>Pending</p>
                    <h2><?php echo clean($pending); ?></h2>
                    <small>Waiting for action</small>
                </div>
                <div class="summary-icon"><i class="fa-regular fa-clock"></i></div>
            </div>
            
            <div class="summary-card purple">
                <div>
                    <p>Approved</p>
                    <h2><?php echo clean($approve); ?></h2>
                    <small>Approved orders</small>
                </div>
                <div class="summary-icon"><i class="fa-regular fa-circle-check"></i></div>
            </div>

            <div class="summary-card blue">
                <div>
                    <p>Out for Delivery</p>
                    <h2><?php echo clean($out_of_delivery); ?></h2>
                    <small>Currently being handled</small>
                </div>
                <div class="summary-icon"><i class="fa-solid fa-spinner"></i></div>
            </div>

            <!-- INSTALLATION STATUS CARD -->
            <div class="summary-card" style="border-left-color: #0f766e;">
                <div>
                    <p>Installation</p>
                    <h2><?php echo clean($installation); ?></h2>
                    <small>Active installations</small>
                </div>
                <div class="summary-icon" style="color: #0f766e; background: #ccfbf1;"><i class="fa-solid fa-wrench"></i></div>
            </div>

            <div class="summary-card green">
                <div>
                    <p>Sold</p>
                    <h2><?php echo clean($sold); ?></h2>
                    <small>Finished orders</small>
                </div>
                <div class="summary-icon"><i class="fa-solid fa-check"></i></div>
            </div>
        </section>

        <!-- INQUIRY TABS & TABLES -->
        <section class="panel product-panel">
            <div class="panel-header" style="border-bottom: none; padding-bottom: 0;">
                <div>
                    <h2>Customer Orders</h2>
                    <span>Total Active: <?php echo clean($total); ?></span>
                </div>
            </div>

            <!-- TABS -->
            <div class="tab-navigation" style="padding: 0 20px;">
                <button class="tab-btn tab-pending active" onclick="openTab(event, 'tab-pending')">Pending (<?php echo count($grouped_inquiries['Pending']); ?>)</button>
                <button class="tab-btn tab-approved" onclick="openTab(event, 'tab-approved')">Approved (<?php echo count($grouped_inquiries['Approved']); ?>)</button>
                <button class="tab-btn tab-delivery" onclick="openTab(event, 'tab-delivery')">Out for Delivery (<?php echo count($grouped_inquiries['Out for Delivery']); ?>)</button>
                <button class="tab-btn tab-installation" onclick="openTab(event, 'tab-installation')">Installation (<?php echo count($grouped_inquiries['Installation']); ?>)</button>
            </div>

            <!-- TAB CONTENTS LOOP -->
            <?php 
            $tab_mapping = [
                'Pending' => 'tab-pending',
                'Approved' => 'tab-approved',
                'Out for Delivery' => 'tab-delivery',
                'Installation' => 'tab-installation'
            ];
            $first_tab = true;
            foreach ($tab_mapping as $tab_status => $tab_id):
                $items = $grouped_inquiries[$tab_status] ?? [];
            ?>
            <div id="<?php echo $tab_id; ?>" class="tab-content <?php echo $first_tab ? 'active' : ''; ?>">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Change Status</th>
                                <th>Service Schedule</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($items) > 0) { ?>
                            <?php foreach ($items as $row) { ?>
                                <?php
                                $current_status = $row['status'] ?: 'Pending';
                                $schedule = null;
                                $schedule_stmt = $conn->prepare("SELECT * FROM services_schedule WHERE inquiry_id = ? ORDER BY id DESC LIMIT 1");
                                if ($schedule_stmt) {
                                    $schedule_stmt->bind_param("i", $row['id']);
                                    $schedule_stmt->execute();
                                    $schedule_result = $schedule_stmt->get_result();
                                    if ($schedule_result && $schedule_result->num_rows > 0) {
                                        $schedule = $schedule_result->fetch_assoc();
                                    }
                                    $schedule_stmt->close();
                                }
                                
                                // Fetch the customer's preferred date (either from schedule_date or delivery_date)
                                $inquiry_date = "";
                                $inquiry_time = "";
                                
                                if (!empty($row['schedule_date'])) {
                                    $inquiry_date = date("Y-m-d", strtotime($row['schedule_date']));
                                    if (!empty($row['schedule_time']) && $row['schedule_time'] != "00:00:00") {
                                        $inquiry_time = date("H:i", strtotime($row['schedule_time']));
                                    }
                                } elseif (!empty($row['delivery_date'])) {
                                    $inquiry_date = date("Y-m-d", strtotime($row['delivery_date']));
                                    if (strlen($row['delivery_date']) > 10 && date("H:i", strtotime($row['delivery_date'])) != "00:00") {
                                        $inquiry_time = date("H:i", strtotime($row['delivery_date']));
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?php echo clean($row['id']); ?></td>
                                    <td>
                                        <strong><?php echo clean($row['customer_name']); ?></strong>
                                        <small>
                                            <i class="fa-regular fa-clock" style="margin-right: 3px;"></i>
                                            <?php if (!empty($row['date_submitted'])) {
                                                echo date("M d, Y • h:i A", strtotime($row['date_submitted']));
                                            } else {
                                                echo "N/A";
                                            } ?>
                                        </small>
                                    </td>

                                    <!-- DETAILS MODAL BUTTON (NO EYE ICON) & HIDDEN DATA TEMPLATE -->
                                    <td>
                                        <button type="button" class="view-details-btn" onclick="openDetailsModal(this)">
                                            View Details
                                        </button>

                                        <div class="inquiry-details-template" style="display: none;">
                                            <div class="details-modal-grid">
                                                <!-- Contact & Customer Info -->
                                                <div class="details-section">
                                                    <h4><i class="fa-regular fa-address-card"></i> Contact Information</h4>
                                                    <div class="details-row">
                                                        <span class="details-label">Customer:</span>
                                                        <span class="details-value"><strong><?php echo clean($row['customer_name']); ?></strong></span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Date Submitted:</span>
                                                        <span class="details-value">
                                                            <?php echo !empty($row['date_submitted']) ? date("M d, Y • h:i A", strtotime($row['date_submitted'])) : 'N/A'; ?>
                                                        </span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Contact No:</span>
                                                        <span class="details-value"><?php echo !empty($row['contact_number']) ? clean($row['contact_number']) : 'N/A'; ?></span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Email:</span>
                                                        <span class="details-value">
                                                            <?php if (!empty($row['email'])): ?>
                                                                <a href="mailto:<?php echo clean($row['email']); ?>"><?php echo clean($row['email']); ?></a>
                                                            <?php else: ?>
                                                                N/A
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Address:</span>
                                                        <span class="details-value"><?php echo !empty($row['address'] ?? '') ? clean($row['address']) : 'No address provided'; ?></span>
                                                    </div>
                                                </div>

                                                <!-- Product & Price Info -->
                                                <div class="details-section">
                                                    <h4><i class="fa-solid fa-box-open"></i> Product & Pricing</h4>
                                                    <div class="details-row">
                                                        <span class="details-label">Product:</span>
                                                        <span class="details-value"><strong><?php echo clean($row['product_name']); ?></strong></span>
                                                    </div>
                                                    <?php if (!empty($row['brand'] ?? '')): ?>
                                                    <div class="details-row">
                                                        <span class="details-label">Brand:</span>
                                                        <span class="details-value"><?php echo clean($row['brand']); ?></span>
                                                    </div>
                                                    <?php endif; ?>

                                                    <?php 
                                                    // Safely check if HP exists either in the inquiry row (grabbed by i.*)
                                                    $product_hp = !empty($row['hp']) ? $row['hp'] : (!empty($row['CapacityHP']) ? $row['CapacityHP'] : '');
                                                    if (!empty($product_hp)): 
                                                    ?>
                                                    <div class="details-row">
                                                        <span class="details-label">Horsepower (HP):</span>
                                                        <span class="details-value"><?php echo clean($product_hp); ?></span>
                                                    </div>
                                                    <?php endif; ?>

                                                    <div class="details-row">
                                                        <span class="details-label">Quantity:</span>
                                                        <span class="details-value"><?php echo !empty($row['quantity']) ? clean($row['quantity']) : '1'; ?></span>
                                                    </div>

                                                    <div class="details-row">
                                                        <span class="details-label">Price:</span>
                                                        <span class="details-value">
                                                            <?php 
                                                            if (isset($row['price']) && is_numeric($row['price']) && $row['price'] > 0) { 
                                                                $original_price = (float)$row['price'];
                                                                $is_promo = isset($row['is_promo']) ? (int)$row['is_promo'] : 0;
                                                                $discount_percent = isset($row['discount_percent']) ? (int)$row['discount_percent'] : 0;

                                                                if ($is_promo === 1 && $discount_percent > 0) {
                                                                    $discount_amount = $original_price * ($discount_percent / 100);
                                                                    $final_price = $original_price - $discount_amount;
                                                                    ?>
                                                                    <small style="text-decoration: line-through; color: #9ca3af; font-size: 0.8rem;">₱<?php echo number_format($original_price, 2); ?></small>
                                                                    <small style="color: #ef4444; font-weight: bold; font-size: 0.8rem; margin-left: 4px;">(-<?php echo $discount_percent; ?>%)</small>
                                                                    <br>
                                                                    <span style="color: #16a34a; font-weight: 700; font-size: 1rem;">₱<?php echo number_format($final_price, 2); ?></span>
                                                                <?php } else { ?>
                                                                    <span style="color: #16a34a; font-weight: 700; font-size: 1rem;">₱<?php echo number_format($original_price, 2); ?></span>
                                                                <?php } 
                                                            } else {
                                                                echo '<span style="color: #64748b;">N/A</span>';
                                                            }
                                                            ?>
                                                        </span>
                                                    </div>

                                                    <div class="details-row">
                                                        <span class="details-label">Payment Method:</span>
                                                        <span class="details-value">
                                                            <strong><?php echo !empty($row['payment_method'] ?? '') ? clean($row['payment_method']) : 'COD (Cash on Delivery)'; ?></strong>
                                                        </span>
                                                    </div>

                                                    <?php if (isset($row['free_installation']) && $row['free_installation'] === 'Yes'): ?>
                                                    <div class="details-row" style="margin-top: 8px;">
                                                        <span class="install-badge">
                                                            Free Installation Requested
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Message Info -->
                                            <div class="details-message-section">
                                                <h4><i class="fa-regular fa-comment-dots"></i> Customer Message</h4>
                                                <div class="details-message-box"><?php echo !empty(trim($row['message'] ?? '')) ? clean($row['message']) : 'No additional message provided.'; ?></div>
                                            </div>
                                        </div>
                                    </td>

                                    <td><span class="badge <?php echo statusClass($current_status); ?>"><?php echo clean($current_status); ?></span></td>
                                    <td>
                                        <form method="POST" class="status-update-form" data-current-status="<?php echo clean($current_status); ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            <input type="hidden" name="id" value="<?php echo clean($row['id']); ?>">
                                            <select name="status">
                                                <option value="Pending" <?php if ($current_status === "Pending") echo "selected"; ?>>Pending</option>
                                                <option value="Approved" <?php if ($current_status === "Approved") echo "selected"; ?>>Approved</option>
                                                <option value="Out for Delivery" <?php if ($current_status === "Out for Delivery") echo "selected"; ?>>Out for Delivery</option>
                                                <option value="Sold" <?php if ($current_status === "Sold") echo "selected"; ?>>Sold</option>
                                                
                                                <?php if (isset($row['free_installation']) && $row['free_installation'] === 'Yes'): ?>
                                                    <option value="Installation" <?php if ($current_status === "Installation") echo "selected"; ?>>Installation</option>
                                                <?php endif; ?>

                                                <option value="Cancelled" <?php if ($current_status === "Cancelled") echo "selected"; ?>>Cancelled</option>
                                            </select>
                                            <button type="button" class="primary-btn mini-btn" onclick="openUpdateStatusModal(this)">Update</button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($schedule) { ?>
                                            <div class="schedule-info">
                                                <?php if (!empty($schedule['service_type'] ?? '')) { ?>
                                                    <strong><?php echo clean($schedule['service_type']); ?></strong>
                                                <?php } else { ?>
                                                    <strong>Service Appointment</strong>
                                                <?php } ?>
                                                <small><i class="fa-regular fa-calendar"></i> <?php echo !empty($schedule['service_date']) ? date("M d, Y", strtotime($schedule['service_date'])) : "No date"; ?></small>
                                                
                                                <small><i class="fa-regular fa-clock"></i> 
                                                    <?php 
                                                    $sched_status = $schedule['status'] ?? 'Pending';
                                                    $sched_time = $schedule['service_time'] ?? '';

                                                    if ($sched_status === 'Pending') {
                                                        echo "SET"; 
                                                    } 
                                                    elseif (empty($sched_time) || $sched_time === '00:00' || $sched_time === '00:00:00') {
                                                        echo "TBA"; 
                                                    } 
                                                    else {
                                                        echo date("h:i A", strtotime($sched_time));
                                                    }
                                                    ?>
                                                </small>
                                            </div>
                                            <button type="button" class="outline-btn mini-btn"
                                                onclick='openScheduleModal(
                                                    <?php echo (int)$row['id']; ?>,
                                                    <?php echo json_encode($row['user_id'] ?? ""); ?>,
                                                    <?php echo json_encode($row['customer_name'] ?? ""); ?>,
                                                    <?php echo json_encode($row['address'] ?? ""); ?>,
                                                    <?php echo json_encode($schedule['service_date'] ?? ""); ?>,
                                                    <?php echo json_encode($schedule['service_time'] ?? ""); ?>,
                                                    <?php echo json_encode($schedule['location'] ?? ""); ?>,
                                                    <?php echo json_encode($schedule['technician_id'] ?? ""); ?>
                                                )'>
                                                <i class="fa-regular fa-calendar-pen"></i> Re-Schedule
                                            </button>
                                        <?php } else { ?>
                                            <div class="schedule-info">
                                                <strong>Service Appointment</strong>
                                                
                                                <?php if (!empty($inquiry_date)) { ?>
                                                    <small><i class="fa-regular fa-calendar"></i> <?php echo date("M d, Y", strtotime($inquiry_date)); ?></small>
                                                    <small><i class="fa-regular fa-clock"></i> <?php echo !empty($inquiry_time) ? date("h:i A", strtotime($inquiry_time)) : "SET"; ?></small>
                                                <?php } else { ?>
                                                    <small>Not scheduled yet.</small>
                                                <?php } ?>
                                                
                                            </div>
                                            
                                            <button type="button" class="primary-btn mini-btn" style="margin-top: 5px;"
                                                onclick='openScheduleModal(
                                                    <?php echo (int)$row['id']; ?>,
                                                    <?php echo json_encode($row['user_id'] ?? ""); ?>,
                                                    <?php echo json_encode($row['customer_name'] ?? ""); ?>,
                                                    <?php echo json_encode($row['address'] ?? ""); ?>,
                                                    <?php echo json_encode($inquiry_date); ?>,
                                                    <?php echo json_encode($inquiry_time); ?>,
                                                    <?php echo json_encode($row['address'] ?? ""); ?>,
                                                    ""
                                                )'>
                                                <i class="fa-regular fa-calendar-plus"></i> Schedule
                                            </button>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <a href="admin_inquiries.php?delete=<?php echo clean($row['id']); ?>" class="delete-icon" onclick="event.preventDefault(); customConfirm('Are you sure you want to delete this order?', this.href);" title="Delete order">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="7" class="empty-row">No <?php echo strtolower($tab_status); ?> orders found.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php 
                $first_tab = false;
            endforeach; 
            ?>
        </section>
    </main>
</div>

<!-- =========================================================
     VIEW ORDER DETAILS MODAL
========================================================== -->
<div class="modal" id="viewDetailsModal">
    <div class="modal-card" style="max-width: 650px; padding: 25px 30px;">
        <button type="button" class="modal-close" onclick="closeDetailsModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <h2 style="color: #1e293b; margin-bottom: 18px; font-size: 20px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-circle-info" style="color: #2563eb;"></i> Order Details
        </h2>
        <div id="viewDetailsModalBody"></div>
        <div class="modal-actions" style="border-top: 1px solid #e2e8f0; margin-top: 20px; padding-top: 15px; justify-content: flex-end;">
            <button type="button" class="outline-btn" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- =========================================================
     SERVICE SCHEDULE MODAL
========================================================== -->
<div class="modal" id="scheduleModal">
    <div class="modal-card">
        <button type="button" class="modal-close" onclick="closeScheduleModal()"><i class="fa-solid fa-xmark"></i></button>
        <h2>Schedule Service Appointment</h2>
        <p>Schedule installation, repair, or maintenance for this order.</p>
        <form method="POST">
            <input type="hidden" name="schedule_service" value="1">
            <input type="hidden" name="inquiry_id" id="scheduleInquiryId">
            <input type="hidden" name="user_id" id="scheduleUserId">

            <div class="form-grid">
                <div class="form-group full">
                    <label>Customer Name</label>
                    <input type="text" name="customer_name" id="scheduleCustomerName" required>
                </div>
                
                <div class="form-group">
                    <label>Service Date</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" name="service_date" id="scheduleServiceDate" required readonly placeholder="Click to select date..." onclick="openCalendarModal()" style="cursor: pointer; background-color: #f9fafb;">
                        <button type="button" class="primary-btn mini-btn" onclick="openCalendarModal()" style="padding: 0 15px; border-radius: 6px;"><i class="fa-regular fa-calendar"></i></button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Service Time (8:00 AM - 5:00 PM)</label>
                    <select name="service_time" id="scheduleServiceTime" required>
                        <option value="">Select Time</option>
                        <option value="08:00">08:00 AM</option>
                        <option value="09:00">09:00 AM</option>
                        <option value="10:00">10:00 AM</option>
                        <option value="11:00">11:00 AM</option>
                        <option value="12:00">12:00 PM</option>
                        <option value="13:00">01:00 PM</option>
                        <option value="14:00">02:00 PM</option>
                        <option value="15:00">03:00 PM</option>
                        <option value="16:00">04:00 PM</option>
                        <option value="17:00">05:00 PM</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label>Location</label>
                    <textarea name="location" id="scheduleLocation" rows="3" required></textarea>
                </div>

                <div class="form-group full">
                    <label>Assign Technician</label>
                    <select name="technician_id" id="scheduleTechnicianId" required>
                        <option value="">Select a date first...</option>
                    </select>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="outline-btn" onclick="closeScheduleModal()">Cancel</button>
                <button type="submit" class="primary-btn" id="scheduleSubmitBtn">
                    <i class="fa-solid fa-calendar-check"></i> <span id="scheduleSubmitText">Save Schedule</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     FULLCALENDAR PICKER MODAL
========================================================== -->
<div class="modal" id="calendarPickerModal" style="z-index: 999999;">
    <div class="modal-card">
        <button type="button" class="modal-close" onclick="closeCalendarModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div id="datePickerCalendar"></div>
    </div>
</div>

<!-- INJECT GLOBAL MODALS -->
<?php include 'includes/global_modals.php'; ?>

<script>
/* ==========================================
   TAB SWITCHING LOGIC
========================================== */
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
        tabcontent[i].classList.remove("active");
    }
    
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }
    
    document.getElementById(tabName).style.display = "block";
    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
}

/* ==========================================
   VIEW ORDER DETAILS MODAL LOGIC
========================================== */
function openDetailsModal(buttonElement) {
    const template = buttonElement.parentElement.querySelector('.inquiry-details-template');
    if (template) {
        document.getElementById('viewDetailsModalBody').innerHTML = template.innerHTML;
        document.getElementById('viewDetailsModal').classList.add('show');
    }
}

function closeDetailsModal() {
    document.getElementById('viewDetailsModal').classList.remove('show');
}

/* ==========================================
   AJAX: UPDATE TECHNICIAN DROPDOWN
========================================== */
function updateTechnicianDropdown(dateString, selectedTechId = null, inquiryId = 0) {
    const dropdown = document.getElementById("scheduleTechnicianId");
    
    if (!dateString) {
        dropdown.innerHTML = '<option value="">Select a date first...</option>';
        dropdown.disabled = true;
        return;
    }

    dropdown.innerHTML = '<option value="">Loading technicians...</option>';
    dropdown.disabled = true;

    fetch(`admin_inquiries.php?action=get_technicians&date=${dateString}&inquiry_id=${inquiryId}`)
        .then(response => response.json())
        .then(data => {
            dropdown.innerHTML = '<option value="">Select technician</option>';
            dropdown.disabled = false;

            const dateObj = new Date(dateString);
            const displayDate = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

            data.forEach(tech => {
                const option = document.createElement('option');
                option.value = tech.id;
                
                let text = tech.technician_name;
                
                if (tech.is_booked > 0) {
                    const details = tech.booked_details ? tech.booked_details : 'Time not specified';
                    text += ` (Booked on ${displayDate} at ${details})`;
                    option.disabled = true;
                }

                option.textContent = text;

                if (selectedTechId && parseInt(selectedTechId) === parseInt(tech.id)) {
                    option.selected = true;
                }

                dropdown.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error fetching technicians:', error);
            dropdown.innerHTML = '<option value="">Error loading technicians</option>';
        });
}

/* ==========================================
   FULLCALENDAR INITIALIZATION FOR MODAL
========================================== */
let isCalInit = false;
let calEvents = <?php echo json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

function openCalendarModal() {
    document.getElementById("calendarPickerModal").classList.add("show");
    
    if (!isCalInit) {
        $("#datePickerCalendar").fullCalendar({
            header: { left: "prev,next today", center: "title", right: "" },
            defaultView: "month",
            events: calEvents,
            dayClick: function(date, jsEvent, view) {
                var dateString = date.format("YYYY-MM-DD");
                
                if (date.isBefore(moment(), 'day')) {
                    alert("You cannot schedule a service in the past.");
                    return;
                }

                let isFull = false;
                for (let i = 0; i < calEvents.length; i++) {
                    if (calEvents[i].start === dateString) {
                        if (calEvents[i].isBlock || calEvents[i].count >= 3) {
                            isFull = true;
                            break;
                        }
                    }
                }

                if (isFull) {
                    alert("This date is fully booked or blocked. Please choose another date.");
                    return;
                }

                document.getElementById("scheduleServiceDate").value = dateString;
                
                let currentInquiryId = document.getElementById("scheduleInquiryId").value || 0;
                updateTechnicianDropdown(dateString, document.getElementById("scheduleTechnicianId").value, currentInquiryId);

                closeCalendarModal();
            }
        });
        isCalInit = true;
    } else {
        setTimeout(() => { $("#datePickerCalendar").fullCalendar("render"); }, 150);
    }
}

function closeCalendarModal() {
    document.getElementById("calendarPickerModal").classList.remove("show");
}

/* =========================================================
   SCHEDULE MODAL
========================================================== */
function openScheduleModal(inquiryId, userId, customerName, address, serviceDate, serviceTime, location, technicianId) {
    document.getElementById("scheduleInquiryId").value = inquiryId || "";
    document.getElementById("scheduleUserId").value = userId || "";
    document.getElementById("scheduleCustomerName").value = customerName || "";
    document.getElementById("scheduleLocation").value = location || address || "";
    document.getElementById("scheduleServiceDate").value = serviceDate || "";
    
    let timeSelect = document.getElementById("scheduleServiceTime");
    if (serviceTime) {
        timeSelect.value = String(serviceTime).substring(0, 5);
    } else {
        timeSelect.value = "";
    }

    const submitText = document.getElementById("scheduleSubmitText");
    if (submitText) {
        if (serviceDate) submitText.textContent = "Update Schedule";
        else submitText.textContent = "Save Schedule";
    }
    
    updateTechnicianDropdown(serviceDate, technicianId, inquiryId || 0);
    
    document.getElementById("scheduleModal").classList.add("show");
}

function closeScheduleModal() {
    document.getElementById("scheduleModal").classList.remove("show");
}

/* =========================================================
   UPDATE STATUS MODAL
========================================================== */
let selectedStatusForm = null;

function openUpdateStatusModal(button) {
    selectedStatusForm = button.closest("form");
    if (!selectedStatusForm) return;

    const currentStatus = selectedStatusForm.dataset.currentStatus;
    const statusSelect = selectedStatusForm.querySelector('select[name="status"]');
    if (!statusSelect) return;
    
    const newStatus = statusSelect.value;
    if (currentStatus === newStatus) {
        alert("Please select a different status before updating.");
        selectedStatusForm = null;
        return;
    }
    
    customConfirm(`Are you sure you want to update the status from "${currentStatus}" to "${newStatus}"?`, selectedStatusForm);
}

/* =========================================================
   CLOSE MODALS WHEN CLICKING OUTSIDE & ESCAPE
========================================================== */
document.addEventListener("click", function (e) {
    const scheduleModal = document.getElementById("scheduleModal");
    const calendarModal = document.getElementById("calendarPickerModal");
    const viewDetailsModal = document.getElementById("viewDetailsModal");

    if (scheduleModal && e.target === scheduleModal) closeScheduleModal();
    if (calendarModal && e.target === calendarModal) closeCalendarModal();
    if (viewDetailsModal && e.target === viewDetailsModal) closeDetailsModal();
});

document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
        closeScheduleModal();
        closeCalendarModal();
        closeDetailsModal();
    }
});

</script>
</body>
</html>