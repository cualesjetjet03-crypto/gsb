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
    $schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;

    // Checks the global schedule for BOTH orders and regular services
    $stmt = $conn->prepare("
        SELECT t.id, t.technician_name,
               COUNT(ss.id) as is_booked,
               GROUP_CONCAT(CONCAT(TIME_FORMAT(ss.service_time, '%h:%i %p'), ' for ', IFNULL(ss.customer_name, 'Customer')) SEPARATOR ', ') as booked_details
        FROM technicians t
        LEFT JOIN services_schedule ss 
            ON ss.technician_id = t.id 
            AND ss.service_date = ? 
            AND (ss.id != ? OR ? = 0) 
            AND ss.status NOT IN ('Cancelled', 'Declined', 'Rejected', 'Deleted', 'Completed', 'Sold')
        WHERE t.status != 'Inactive'
        GROUP BY t.id
        ORDER BY t.technician_name ASC
    ");
    
    // Notice we bind the schedule_id twice now
    $stmt->bind_param("sii", $date, $schedule_id, $schedule_id);
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
   UPDATE SCHEDULE SERVICE (RE-SCHEDULE)
========================================================= */

if (isset($_POST['schedule_service'])) {

    $schedule_id = intval($_POST['schedule_id'] ?? 0);

    $customer_name  = trim($_POST['customer_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $message        = trim($_POST['message'] ?? '');
    $service_type   = trim($_POST['service_type'] ?? '');
    $service_date   = trim($_POST['service_date'] ?? '');
    $service_time   = trim($_POST['service_time'] ?? '');
    $location       = trim($_POST['location'] ?? '');
    $technician_id  = intval($_POST['technician_id'] ?? 0);

    if ($schedule_id <= 0) {
        $error = "Invalid service request selected.";
    } elseif (
        empty($customer_name) ||
        empty($service_type) ||
        empty($service_date) ||
        empty($service_time) ||
        empty($location) ||
        $technician_id <= 0
    ) {
        $error = "Please complete all required service request fields.";
    } else {

        // 1. Fetch the OLD date and time so we know if the admin shifted the day
        $old_date = null;
        $old_technician_id = null;
        $fetch_old = $conn->prepare("SELECT service_date, technician_id FROM services_schedule WHERE id = ?");
        if ($fetch_old) {
            $fetch_old->bind_param("i", $schedule_id);
            $fetch_old->execute();
            $res = $fetch_old->get_result();
            if ($res && $res->num_rows > 0) {
                $old_row = $res->fetch_assoc();
                $old_date = $old_row['service_date'];
                $old_technician_id = $old_row['technician_id'];
            }
            $fetch_old->close();
        }

        // 2. CAPACITY CHECK: If moving to a NEW date, make sure it isn't already full (3 limit)
        $is_over_limit = false;
        if ($old_date !== $service_date) {
            $s_count = 0;
            $i_count = 0;

            $stmt1 = $conn->prepare("SELECT COUNT(*) FROM services_schedule WHERE service_date = ? AND IFNULL(status, 'Pending') NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
            if ($stmt1) {
                $stmt1->bind_param("s", $service_date);
                $stmt1->execute();
                $stmt1->bind_result($s_count);
                $stmt1->fetch();
                $stmt1->close();
            }

            $stmt2 = $conn->prepare("SELECT COUNT(*) FROM inquiries WHERE DATE(delivery_date) = ? AND IFNULL(status, 'Pending') NOT IN ('Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
            if ($stmt2) {
                $stmt2->bind_param("s", $service_date);
                $stmt2->execute();
                $stmt2->bind_result($i_count);
                $stmt2->fetch();
                $stmt2->close();
            }

            if (($s_count + $i_count) >= 3) {
                $is_over_limit = true;
            }
        }

        // If the date is full, block the update. Otherwise, proceed.
        if ($is_over_limit) {
            $error = "Cannot update request: The selected date (" . date("M d, Y", strtotime($service_date)) . ") is already fully booked (3 active appointments).";
        } else {
            // Check technician
            $tech_check = $conn->prepare("
                SELECT id, technician_name, status
                FROM technicians
                WHERE id = ? AND status != 'Inactive'
                LIMIT 1
            ");

            if ($tech_check) {
                $tech_check->bind_param("i", $technician_id);
                $tech_check->execute();
                $tech_result = $tech_check->get_result();

                if ($tech_result && $tech_result->num_rows > 0) {
                    $tech_check->close();
                    $conn->begin_transaction();

                    try {
                        // 3. Update the service schedule and automatically set to Approved
                        $stmt = $conn->prepare("
                            UPDATE services_schedule
                            SET customer_name = ?,
                                contact_number = ?,
                                email = ?,
                                message = ?,
                                service_type = ?,
                                service_date = ?,
                                service_time = ?,
                                location = ?,
                                technician_id = ?,
                                status = 'Approved'
                            WHERE id = ?
                        ");
                        $stmt->bind_param("ssssssssii", $customer_name, $contact_number, $email, $message, $service_type, $service_date, $service_time, $location, $technician_id, $schedule_id);
                        
                        if (!$stmt->execute()) {
                             throw new Exception("Failed to update service request: " . $stmt->error);
                        }
                        $stmt->close();

                        // 3.5 RELEASE OLD TECHNICIAN IF CHANGED
                        if ($old_technician_id && $old_technician_id != $technician_id) {
                            $old_tech_stmt = $conn->prepare("UPDATE technicians SET status = 'Available' WHERE id = ?");
                            if ($old_tech_stmt) {
                                $old_tech_stmt->bind_param("i", $old_technician_id);
                                $old_tech_stmt->execute();
                                $old_tech_stmt->close();
                            }
                        }

                        // 4. Update the new technician
                        $tech_stmt = $conn->prepare("
                            UPDATE technicians
                            SET status = 'Assigned'
                            WHERE id = ?
                        ");
                        $tech_stmt->bind_param("i", $technician_id);
                        $tech_stmt->execute();
                        $tech_stmt->close();

                        // 5. INSTANT CALENDAR SYNC (Lock new date if full, unlock old date if below 3)
                        if ($old_date !== $service_date) {
                            // Lock new date if this update maxed it out
                            $s_new = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE service_date = '$service_date' AND IFNULL(status, 'Pending') NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted')")->fetch_assoc()['total'];
                            $i_new = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE DATE(delivery_date) = '$service_date' AND IFNULL(status, 'Pending') NOT IN ('Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')")->fetch_assoc()['total'];
                            
                            if (($s_new + $i_new) >= 3) {
                                // Check if already blocked
                                $block_check = $conn->query("SELECT id FROM availability_schedule WHERE date = '$service_date' AND (status = 'Unavailable' OR status = 'Blocked') LIMIT 1");
                                if ($block_check->num_rows === 0) {
                                    $conn->query("INSERT INTO availability_schedule (date, start_time, end_time, status, notes) VALUES ('$service_date', '00:00:00', '23:59:59', 'Unavailable', 'Fully booked (3 limit reached)')");
                                }
                            }

                            // Unlock old date
                            if ($old_date) {
                                $s_old = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE service_date = '$old_date' AND IFNULL(status, 'Pending') NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted')")->fetch_assoc()['total'];
                                $i_old = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE DATE(delivery_date) = '$old_date' AND IFNULL(status, 'Pending') NOT IN ('Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')")->fetch_assoc()['total'];
                                
                                if (($s_old + $i_old) < 3) {
                                    $conn->query("DELETE FROM availability_schedule WHERE date = '$old_date' AND LOWER(status) != 'available'");
                                }
                            }
                        }

                        $conn->commit();
                        header("Location: services_schedule.php?scheduled=1");
                        exit();

                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = $e->getMessage();
                    }
                } else {
                    $error = "Selected technician is not available.";
                    $tech_check->close();
                }
            }
        }
    }
}

/* =========================================================
   UPDATE STATUS
========================================================= */

if (isset($_POST['update_status'])) {

    $schedule_id = intval($_POST['schedule_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    // UPDATED STATUS ARRAY
    $allowed_status = [
        'Pending',
        'Approved',
        'Ongoing',
        'Completed',
        'Cancelled'
    ];

    if ($schedule_id <= 0) {
        $error = "Invalid service request selected.";
    } elseif (in_array($status, $allowed_status, true)) {

        // Get the date BEFORE updating so we know which date to check for cleanup
        $date_query = $conn->query("SELECT service_date, technician_id FROM services_schedule WHERE id = $schedule_id");
        $service_row = ($date_query && $date_query->num_rows > 0) ? $date_query->fetch_assoc() : null;
        $service_date = $service_row ? $service_row['service_date'] : null;
        $tech_id = $service_row ? $service_row['technician_id'] : null;

        $stmt = $conn->prepare("
            UPDATE services_schedule
            SET status = ?
            WHERE id = ?
        ");

        if ($stmt) {
            $stmt->bind_param("si", $status, $schedule_id);

            if ($stmt->execute()) {
                $stmt->close();
                
                /* =========================================
                   NOTIFY THE CUSTOMER
                ========================================= */
                $notif_user_query = $conn->prepare("SELECT user_id, service_type FROM services_schedule WHERE id = ? LIMIT 1");
                $notif_user_query->bind_param("i", $schedule_id);
                $notif_user_query->execute();
                $notif_user_res = $notif_user_query->get_result();
                if ($notif_row = $notif_user_res->fetch_assoc()) {
                    if (!empty($notif_row['user_id'])) {
                        $uid = $notif_row['user_id'];
                        $srv_type = !empty($notif_row['service_type']) ? $notif_row['service_type'] : 'Service Request';
                        $title = "Service Status: " . $status;
                        $msg = "Your $srv_type is now marked as $status.";
                        
                        $insert_notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, ?, ?, 0)");
                        $insert_notif->bind_param("iss", $uid, $title, $msg);
                        $insert_notif->execute();
                        $insert_notif->close();
                    }
                }
                $notif_user_query->close();

                
                // INSTANT CLEANUP: If status is Cancelled or Completed, check if we need to unblock the date
                if ($service_date && in_array($status, ['Completed', 'Cancelled'])) {
                    $active_services = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE service_date = '$service_date' AND status NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                    $s_count = $active_services ? (int)$active_services->fetch_assoc()['total'] : 0;
                    
                    $active_inquiries = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE DATE(delivery_date) = '$service_date' AND status NOT IN ('Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                    $i_count = $active_inquiries ? (int)$active_inquiries->fetch_assoc()['total'] : 0;
                    
                    if (($s_count + $i_count) < 3) {
                        // Immediately delete the lock from availability_schedule so customers can book
                        $conn->query("DELETE FROM availability_schedule WHERE date = '$service_date' AND LOWER(status) != 'available'");
                    }
                    
                    // Release Technician
                    if ($tech_id) {
                        $conn->query("UPDATE technicians SET status = 'Available' WHERE id = $tech_id");
                    }
                }

                header("Location: services_schedule.php?success=1");
                exit();
            } else {
                $error = "Failed to update request status: " . $stmt->error;
                $stmt->close();
            }
        }
    } else {
        $error = "Invalid status selected.";
    }
}

/* =========================================================
   DELETE SERVICE SCHEDULE
========================================================= */

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    if ($id > 0) {
        // Get the date BEFORE deleting so we can clean up the calendar
        $date_query = $conn->query("SELECT service_date, technician_id FROM services_schedule WHERE id = $id");
        $service_row = ($date_query && $date_query->num_rows > 0) ? $date_query->fetch_assoc() : null;
        $service_date = $service_row ? $service_row['service_date'] : null;
        $tech_id = $service_row ? $service_row['technician_id'] : null;

        $stmt = $conn->prepare("
            DELETE FROM services_schedule
            WHERE id = ?
        ");

        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $stmt->close();
                
                // INSTANT CLEANUP: Check if deleting this freed up a slot
                if ($service_date) {
                    $active_services = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE service_date = '$service_date' AND status NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                    $s_count = $active_services ? (int)$active_services->fetch_assoc()['total'] : 0;
                    
                    $active_inquiries = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE DATE(delivery_date) = '$service_date' AND status NOT IN ('Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')");
                    $i_count = $active_inquiries ? (int)$active_inquiries->fetch_assoc()['total'] : 0;
                    
                    if (($s_count + $i_count) < 3) {
                        // Unblock the date
                        $conn->query("DELETE FROM availability_schedule WHERE date = '$service_date' AND LOWER(status) != 'available'");
                    }
                }
                
                // Release Technician
                if ($tech_id) {
                    $conn->query("UPDATE technicians SET status = 'Available' WHERE id = $tech_id");
                }

                header("Location: services_schedule.php?deleted=1");
                exit();
            } else {
                $error = "Failed to delete request: " . $stmt->error;
                $stmt->close();
            }
        }
    }
}

/* =========================================================
   SEARCH & FETCH STRICTLY NON-INQUIRY SCHEDULES
   (FIFO ORDER)
========================================================= */

$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// FIFO is preserved inherently by ORDER BY id ASC
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $stmt = $conn->prepare("
        SELECT *
        FROM services_schedule
        WHERE (inquiry_id IS NULL OR inquiry_id = 0)
        AND status NOT IN ('Completed', 'Cancelled')
        AND (customer_name LIKE ? OR service_type LIKE ? OR status LIKE ?)
        ORDER BY id ASC
    ");
    $stmt->bind_param("sss", $search_param, $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("
        SELECT *
        FROM services_schedule
        WHERE (inquiry_id IS NULL OR inquiry_id = 0)
        AND status NOT IN ('Completed', 'Cancelled')
        ORDER BY id ASC
    ");
}

/* =========================================================
   GROUP DATA FOR TABS (PRESERVES FIFO AUTOMATICALLY)
========================================================= */
$grouped_services = [
    'Pending' => [],
    'Approved' => [],
    'Ongoing' => []
];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $status = $row['status'] ?: 'Pending';
        if (array_key_exists($status, $grouped_services)) {
            $grouped_services[$status][] = $row;
        }
    }
}

/* =========================================================
   ISOLATED COUNTS 
========================================================= */
$total_schedule = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE (inquiry_id IS NULL OR inquiry_id = 0) AND status NOT IN ('Completed', 'Cancelled')")->fetch_assoc();
$sched_pending_count = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE status = 'Pending' AND (inquiry_id IS NULL OR inquiry_id = 0)")->fetch_assoc();
$ongoing_count = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE status = 'Ongoing' AND (inquiry_id IS NULL OR inquiry_id = 0)")->fetch_assoc();
$approve_count = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE status = 'Approved' AND (inquiry_id IS NULL OR inquiry_id = 0)")->fetch_assoc();
$completed_count = $conn->query("SELECT COUNT(*) AS total FROM services_schedule WHERE status = 'Completed' AND (inquiry_id IS NULL OR inquiry_id = 0)")->fetch_assoc();

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
        AND status NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted') 
        GROUP BY service_date
        
        UNION ALL
        
        SELECT DATE(delivery_date) AS date, COUNT(*) as total 
        FROM inquiries 
        WHERE delivery_date IS NOT NULL 
        AND status NOT IN ('Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted') 
        GROUP BY DATE(delivery_date)
    ) as combined_bookings
    GROUP BY date
";
$serviceBookingsResult = $conn->query($serviceBookingsQuery);
if ($serviceBookingsResult && $serviceBookingsResult->num_rows > 0) {
    while ($row = $serviceBookingsResult->fetch_assoc()) {
        $total = (int)$row['combined_total'];
        $backgroundColor = "#16a34a"; // Green (1 booking)
        if ($total === 2) $backgroundColor = "#eab308"; // Yellow (2 bookings)
        if ($total >= 3) $backgroundColor = "#dc2626"; // Red (3 bookings)

        $calendarEvents[] = [
            "title" => "📅 " . $total . " Booking" . ($total > 1 ? "s" : ""),
            "start" => $row['date'],
            "allDay" => true,
            "backgroundColor" => $backgroundColor,
            "borderColor" => $backgroundColor,
            "textColor" => "#ffffff",
            "count" => $total
        ];
    }
}

// 2. Fetch manual unavailable blocks (exclude auto-generated 'Fully booked')
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
    <title>Service Requests | GSB Admin</title>

    <link rel="stylesheet" href="includes/css/admin_dashboard.css">
    <link rel="stylesheet" href="includes/css/admin_inquiries.css"> 
    <link rel="stylesheet" href="services_schedule.css">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- FullCalendar CSS & JS for Modal Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.js"></script>

    <style>
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 10px 20px;
            font-size: 15px;
            color: #64748b;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s ease;
            font-weight: 500;
        }
        .tab-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        
        .tab-btn.tab-pending.active { background: #fef3c7; color: #b45309; font-weight: 700; }
        .tab-btn.tab-approved.active { background: #e0e7ff; color: #4338ca; font-weight: 700; }
        .tab-btn.tab-ongoing.active { background: #dbeafe; color: #1d4ed8; font-weight: 700; }
        
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>

<body>
<div class="admin-wrapper">
    <?php include "includes/admin_sidebar.php"; ?>

    <main class="main-content">
        
        <?php 
        $pending_inquiries_result = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status='Pending'");
        $pending_count = $pending_inquiries_result ? (int)$pending_inquiries_result->fetch_assoc()['total'] : 0;
        include 'includes/topbar.php'; 
        ?>

        <section class="page-header">
            <div class="row-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>Service Requests</h1>
                    <p>Manage customer installation, cleaning, repair, and check-up requests.</p>
                </div>
                
                <div style="display: flex; gap: 10px; align-items: center;">
                    <form method="GET" class="inquiry-search-form">
                        <div class="mini-search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" name="search" placeholder="Search requests..." value="<?php echo clean($search); ?>">
                        </div>
                        <button type="submit" class="primary-btn">Search</button>
                    </form>
                </div>
            </div>
        </section>

        <?php if (isset($_GET['success'])) { ?>
            <div class="alert success">Service request status updated successfully.</div>
        <?php } ?>
        <?php if (isset($_GET['deleted'])) { ?>
            <div class="alert success">Service request deleted successfully.</div>
        <?php } ?>
        <?php if (isset($_GET['scheduled'])) { ?>
            <div class="alert success">Service request updated successfully.</div>
        <?php } ?>
        <?php if (isset($error)) { ?>
            <div class="alert error"><?php echo clean($error); ?></div>
        <?php } ?>

        <section class="summary-grid">
            <div class="summary-card orange">
                <div>
                    <p>Pending</p>
                    <h2><?php echo clean($sched_pending_count['total']); ?></h2>
                    <small>Waiting for action</small>
                </div>
                <div class="summary-icon">
                    <i class="fa-regular fa-clock"></i>
                </div>
            </div>
            
            <div class="summary-card purple">
                <div>
                    <p>Approved</p>
                    <h2><?php echo clean($approve_count['total']); ?></h2>
                    <small>Approved services</small>
                </div>
                <div class="summary-icon">
                    <i class="fa-regular fa-circle-check"></i>
                </div>
            </div>

            <div class="summary-card blue">
                <div>
                    <p>Ongoing</p>
                    <h2><?php echo clean($ongoing_count['total']); ?></h2>
                    <small>Currently being handled</small>
                </div>
                <div class="summary-icon">
                    <i class="fa-solid fa-spinner"></i>
                </div>
            </div>

            <div class="summary-card green">
                <div>
                    <p>Completed</p>
                    <h2><?php echo clean($completed_count['total']); ?></h2>
                    <small>View in History Page</small>
                </div>
                <div class="summary-icon">
                    <i class="fa-solid fa-check"></i>
                </div>
            </div>
        </section>

        <section class="panel product-panel">
            <div class="panel-header" style="border-bottom: none; padding-bottom: 0;">
                <div>
                    <h2>Active Service Requests</h2>
                    <span>Total Active: <?php echo clean($total_schedule['total']); ?></span>
                </div>
            </div>

            <div class="tab-navigation" style="padding: 0 20px;">
                <button class="tab-btn tab-pending active" onclick="openTab(event, 'tab-pending')">Pending (<?php echo count($grouped_services['Pending']); ?>)</button>
                <button class="tab-btn tab-approved" onclick="openTab(event, 'tab-approved')">Approved (<?php echo count($grouped_services['Approved']); ?>)</button>
                <button class="tab-btn tab-ongoing" onclick="openTab(event, 'tab-ongoing')">Ongoing (<?php echo count($grouped_services['Ongoing']); ?>)</button>
            </div>

            <?php 
            $tab_mapping = [
                'Pending' => 'tab-pending',
                'Approved' => 'tab-approved',
                'Ongoing' => 'tab-ongoing'
            ];
            $first_tab = true;
            foreach ($tab_mapping as $tab_status => $tab_id):
                $items = $grouped_services[$tab_status] ?? [];
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
                                <th>Service Request</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($items) > 0) { ?>
                            <?php foreach ($items as $row) { ?>
                                <?php 
                                $current_status = $row['status'] ?: 'Pending'; 
                                
                                $submitted_display = "N/A";
                                if (!empty($row['date_submitted'])) {
                                    $submitted_display = date("M d, Y • h:i A", strtotime($row['date_submitted']));
                                } elseif (!empty($row['created_at'])) {
                                    $submitted_display = date("M d, Y • h:i A", strtotime($row['created_at']));
                                }
                                ?>
                                <tr>
                                    <td><?php echo clean($row['id']); ?></td>
                                    
                                    <td>
                                        <strong><?php echo clean($row['customer_name']); ?></strong>
                                        <small>
                                            <i class="fa-regular fa-clock" style="margin-right: 3px;"></i>
                                            <?php echo clean($submitted_display); ?>
                                        </small>
                                    </td>

                                    <td>
                                        <button type="button" class="view-details-btn" onclick="openDetailsModal(this)">
                                            View Details
                                        </button>

                                        <div class="inquiry-details-template" style="display: none;">
                                            <div class="details-modal-grid">
                                                <div class="details-section">
                                                    <h4><i class="fa-regular fa-address-card"></i> Contact Information</h4>
                                                    <div class="details-row">
                                                        <span class="details-label">Customer:</span>
                                                        <span class="details-value"><strong><?php echo clean($row['customer_name']); ?></strong></span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Date Submitted:</span>
                                                        <span class="details-value"><?php echo clean($submitted_display); ?></span>
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
                                                        <span class="details-label">Location / Address:</span>
                                                        <span class="details-value"><?php echo !empty($row['location']) ? clean($row['location']) : 'No address provided'; ?></span>
                                                    </div>
                                                </div>

                                                <div class="details-section">
                                                    <h4><i class="fa-solid fa-screwdriver-wrench"></i> Service Details</h4>
                                                    <div class="details-row">
                                                        <span class="details-label">Service Type:</span>
                                                        <span class="details-value"><strong><?php echo clean($row['service_type'] ?? 'AC Installation'); ?></strong></span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Scheduled Date:</span>
                                                        <span class="details-value">
                                                            <?php echo !empty($row['service_date']) ? date("M d, Y", strtotime($row['service_date'])) : 'No date'; ?>
                                                        </span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Scheduled Time:</span>
                                                        <span class="details-value">
                                                            <?php 
                                                            $sched_time = $row['service_time'] ?? '';
                                                            if ($current_status === 'Pending') {
                                                                echo "SET"; 
                                                            } elseif (empty($sched_time) || $sched_time === '00:00' || $sched_time === '00:00:00') {
                                                                echo "TBA"; 
                                                            } else {
                                                                echo date("h:i A", strtotime($sched_time));
                                                            }
                                                            ?>
                                                        </span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Current Status:</span>
                                                        <span class="details-value">
                                                            <span class="badge <?php echo statusClass($current_status); ?>"><?php echo clean($current_status); ?></span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="details-message-section">
                                                <h4><i class="fa-regular fa-comment-dots"></i> Customer Message / Notes</h4>
                                                <div class="details-message-box"><?php echo !empty(trim($row['message'] ?? '')) ? clean($row['message']) : 'No additional message provided.'; ?></div>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="badge <?php echo statusClass($current_status); ?>">
                                            <?php echo clean($current_status); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <form method="POST" class="status-update-form" data-current-status="<?php echo clean($current_status); ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            <input type="hidden" name="schedule_id" value="<?php echo clean($row['id']); ?>">
                                            
                                            <select name="status">
                                                <option value="Pending" <?php echo ($current_status == "Pending") ? "selected" : ""; ?>>Pending</option>
                                                <option value="Approved" <?php echo ($current_status == "Approved" || $current_status == "Approve") ? "selected" : ""; ?>>Approved</option>
                                                <option value="Ongoing" <?php echo ($current_status == "Ongoing") ? "selected" : ""; ?>>Ongoing</option>
                                                <option value="Completed" <?php echo ($current_status == "Completed") ? "selected" : ""; ?>>Completed</option>
                                                <option value="Cancelled" <?php echo ($current_status == "Cancelled") ? "selected" : ""; ?>>Cancelled</option>
                                            </select>
                                            
                                            <button type="button" class="primary-btn mini-btn" onclick="openUpdateStatusModal(this)">Update</button>
                                        </form>
                                    </td>

                                    <td>
                                        <div class="schedule-info">
                                            <strong><?php echo clean($row['service_type'] ?? 'AC Installation'); ?></strong>
                                            <small><i class="fa-regular fa-calendar"></i> <?php echo !empty($row['service_date']) ? date("M d, Y", strtotime($row['service_date'])) : "No date"; ?></small>
                                            
                                            <small><i class="fa-regular fa-clock"></i> 
                                                <?php 
                                                $sched_time = $row['service_time'] ?? '';
                                                if ($current_status === 'Pending') {
                                                    echo "SET"; 
                                                } elseif (empty($sched_time) || $sched_time === '00:00' || $sched_time === '00:00:00') {
                                                    echo "TBA"; 
                                                } else {
                                                    echo date("h:i A", strtotime($sched_time));
                                                }
                                                ?>
                                            </small>
                                        </div>
                                        
                                        <button type="button" class="outline-btn mini-btn"
                                                onclick='openScheduleModal(
                                                    <?php echo (int)($row['id']); ?>,
                                                    <?php echo json_encode($row['customer_name'] ?? ""); ?>,
                                                    <?php echo json_encode($row['contact_number'] ?? ""); ?>,
                                                    <?php echo json_encode($row['email'] ?? ""); ?>,
                                                    <?php echo json_encode($row['message'] ?? ""); ?>,
                                                    <?php echo json_encode($row['service_type'] ?? ""); ?>,
                                                    <?php echo json_encode($row['service_date'] ?? ""); ?>,
                                                    <?php echo json_encode($row['service_time'] ?? ""); ?>,
                                                    <?php echo json_encode($row['location'] ?? ""); ?>,
                                                    <?php echo json_encode($row['technician_id'] ?? ""); ?>
                                                )'>
                                            <i class="fa-regular fa-calendar-pen"></i> Update Request
                                        </button>
                                    </td>

                                    <td>
                                        <!-- USE GLOBAL CUSTOM CONFIRM -->
                                        <a href="services_schedule.php?delete=<?php echo clean($row['id']); ?>"
                                           class="delete-icon" onclick="event.preventDefault(); customConfirm('Are you sure you want to delete this service request?', this.href);" title="Delete Request">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="7" class="empty-row">No active <?php echo strtolower($tab_status); ?> requests found.</td></tr>
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
     VIEW SERVICE REQUEST DETAILS MODAL
========================================================== -->
<div class="modal" id="viewDetailsModal">
    <div class="modal-card" style="max-width: 650px; padding: 25px 30px;">
        <button type="button" class="modal-close" onclick="closeDetailsModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <h2 style="color: #1e293b; margin-bottom: 18px; font-size: 20px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-circle-info" style="color: #2563eb;"></i> Service Request Details
        </h2>
        <div id="viewDetailsModalBody"></div>
        <div class="modal-actions" style="border-top: 1px solid #e2e8f0; margin-top: 20px; padding-top: 15px; justify-content: flex-end;">
            <button type="button" class="outline-btn" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- SERVICE SCHEDULE MODAL -->
<div class="modal" id="scheduleModal">
    <div class="modal-card">
        <button type="button" class="modal-close" onclick="closeScheduleModal()"><i class="fa-solid fa-xmark"></i></button>
        
        <h2>Update Service Request</h2>
        <p>Update or reschedule this service request.</p>
        
        <form method="POST">
            <input type="hidden" name="schedule_id" id="scheduleUpdateId">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" name="customer_name" id="scheduleCustomerName" required>
                </div>

                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" id="scheduleContactNumber" placeholder="e.g. 09123456789">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" id="scheduleEmail" placeholder="e.g. email@example.com">
                </div>

                <div class="form-group">
                    <label>Service Type</label>
                    <select name="service_type" id="scheduleServiceType" required>
                        <option value="">Select service type</option>
                        <option value="AC Installation">AC Installation</option>
                        <option value="AC Repair">AC Repair</option>
                        <option value="AC Cleaning">AC Cleaning</option>
                        <option value="AC Check up">AC Check up</option>
                    </select>
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
                    <textarea name="location" id="scheduleLocation" rows="2" required></textarea>
                </div>
                
                <div class="form-group full">
                    <label>Customer Message / Notes</label>
                    <textarea name="message" id="scheduleMessage" rows="2" placeholder="Any additional details or issues mentioned by the customer..."></textarea>
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
                <button type="submit" name="schedule_service" class="primary-btn">
                    <i class="fa-solid fa-calendar-check"></i> <span id="scheduleSubmitText">Save Request</span>
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
   VIEW DETAILS MODAL LOGIC
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
function updateTechnicianDropdown(dateString, selectedTechId = null, scheduleId = 0) {
    const dropdown = document.getElementById("scheduleTechnicianId");
    
    if (!dateString) {
        dropdown.innerHTML = '<option value="">Select a date first...</option>';
        dropdown.disabled = true;
        return;
    }

    dropdown.innerHTML = '<option value="">Loading technicians...</option>';
    dropdown.disabled = true;

    fetch(`services_schedule.php?action=get_technicians&date=${dateString}&schedule_id=${scheduleId}`)
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
                
                // 1. Check if past date
                if (date.isBefore(moment(), 'day')) {
                    alert("You cannot schedule a service in the past.");
                    return;
                }

                // 2. Check if fully booked or blocked
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

                // 3. Apply selected date to the input field
                document.getElementById("scheduleServiceDate").value = dateString;
                
                // Trigger AJAX to load available technicians
                let currentScheduleId = document.getElementById("scheduleUpdateId").value || 0;
                updateTechnicianDropdown(dateString, document.getElementById("scheduleTechnicianId").value, currentScheduleId);

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

/* ==========================================
   SCHEDULE MODAL
========================================== */
function openScheduleModal(scheduleId, customerName, contactNumber, email, message, serviceType, serviceDate, serviceTime, location, technicianId) {
    document.getElementById("scheduleUpdateId").value = scheduleId || "";
    document.getElementById("scheduleCustomerName").value = customerName || "";
    document.getElementById("scheduleContactNumber").value = contactNumber || "";
    document.getElementById("scheduleEmail").value = email || "";
    document.getElementById("scheduleMessage").value = message || "";
    
    const serviceSelect = document.getElementById("scheduleServiceType");
    let optionExists = false;
    for (let i = 0; i < serviceSelect.options.length; i++) {
        if (serviceSelect.options[i].value === serviceType) {
            optionExists = true;
            break;
        }
    }
    if (!optionExists && serviceType) {
        serviceSelect.add(new Option(serviceType, serviceType));
    }
    serviceSelect.value = serviceType || "";

    document.getElementById("scheduleServiceDate").value = serviceDate || "";
    
    let timeSelect = document.getElementById("scheduleServiceTime");
    if (serviceTime) {
        timeSelect.value = String(serviceTime).substring(0, 5);
    } else {
        timeSelect.value = "";
    }
    
    document.getElementById("scheduleLocation").value = location || "";
    
    updateTechnicianDropdown(serviceDate, technicianId, scheduleId || 0);

    const submitText = document.getElementById("scheduleSubmitText");
    if (submitText) {
        if (serviceDate) submitText.textContent = "Update Request";
        else submitText.textContent = "Save Request";
    }
    
    document.getElementById("scheduleModal").classList.add("show");
}

function closeScheduleModal() {
    document.getElementById("scheduleModal").classList.remove("show");
}

/* ==========================================
   UPDATE STATUS MODAL
========================================== */
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

    customConfirm(`Are you sure you want to update the request status from "${currentStatus}" to "${newStatus}"?`, selectedStatusForm);
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

setTimeout(function() {
    const alertBox = document.querySelector('.alert');
    if (alertBox) {
        alertBox.style.opacity = '0';
        setTimeout(() => alertBox.remove(), 300);
    }
}, 4500);
</script>
</body>
</html>