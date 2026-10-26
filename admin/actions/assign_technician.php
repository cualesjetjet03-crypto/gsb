<?php
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: admin_login.php");
    exit();
}

include "db_connect.php";

/* ASSIGN TECHNICIAN */
if (isset($_POST['assign_technician'])) {

    $inquiry_id = intval($_POST['inquiry_id']);
    $technician_id = !empty($_POST['technician_id'])
        ? intval($_POST['technician_id'])
        : null;

    if ($technician_id) {

        // Assign technician to inquiry
        $stmt = $conn->prepare("
            UPDATE inquiries
            SET technician_id = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ii",
            $technician_id,
            $inquiry_id
        );

        if ($stmt->execute()) {

            // Also update technician for related service schedule
            $schedule_stmt = $conn->prepare("
                UPDATE services_schedule
                SET technician_id = ?
                WHERE inquiry_id = ?
            ");

            $schedule_stmt->bind_param(
                "ii",
                $technician_id,
                $inquiry_id
            );

            $schedule_stmt->execute();

            // Mark technician as assigned
            $tech_stmt = $conn->prepare("
                UPDATE technicians
                SET status = 'Assigned'
                WHERE id = ?
            ");

            $tech_stmt->bind_param(
                "i",
                $technician_id
            );

            $tech_stmt->execute();

            header("Location: admin_inquiries.php?technician_assigned=1");
            exit();

        } else {

            $error = "Failed to assign technician.";
        }

    } else {

        $error = "Please select a technician.";
    }
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* SCHEDULE SERVICE FROM INQUIRY */
if (isset($_POST['schedule_service'])) {
    $inquiry_id = intval($_POST['inquiry_id']);
    $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
    $customer_name = trim($_POST['customer_name']);
    $service_type = trim($_POST['service_type']);
    $service_date = trim($_POST['service_date']);
    $service_time = trim($_POST['service_time']);
    $location = trim($_POST['location']);

    $allowed_service_types = ['Installation', 'Repair', 'Maintenance'];

    if (
        empty($customer_name) ||
        empty($service_type) ||
        empty($service_date) ||
        empty($service_time) ||
        empty($location)
    ) {
        $error = "Please complete all service schedule fields.";
    } elseif (!in_array($service_type, $allowed_service_types)) {
        $error = "Invalid service type selected.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO services_schedule 
            (inquiry_id, user_id, customer_name, service_type, service_date, service_time, location, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')
        ");

        $stmt->bind_param(
            "iisssss",
            $inquiry_id,
            $user_id,
            $customer_name,
            $service_type,
            $service_date,
            $service_time,
            $location
        );

        if ($stmt->execute()) {
            $update_stmt = $conn->prepare("
                UPDATE inquiries 
                SET status = 'In Progress' 
                WHERE id = ?
            ");
            $update_stmt->bind_param("i", $inquiry_id);
            $update_stmt->execute();

            header("Location: admin_inquiries.php?scheduled=1");
            exit();
        } else {
            $error = "Failed to schedule service appointment.";
        }
    }
}

/* UPDATE INQUIRY STATUS */
if (isset($_POST['update_status'])) {
    $id = intval($_POST['id']);
    $status = $_POST['status'];

    $allowed_status = ['Pending', 'In Progress', 'Approved', 'Completed'];

    if (in_array($status, $allowed_status)) {
        $stmt = $conn->prepare("UPDATE inquiries SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);

        if ($stmt->execute()) {

            $schedule_stmt = $conn->prepare("
                UPDATE services_schedule 
                SET status = ? 
                WHERE inquiry_id = ?
            ");
            $schedule_stmt->bind_param("si", $status, $id);
            $schedule_stmt->execute();

            header("Location: admin_inquiries.php?success=1");
            exit();
        }else {
            $error = "Failed to update inquiry status.";
        }
    } else {
        $error = "Invalid status selected.";
    }
}

/* DELETE INQUIRY */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $stmt = $conn->prepare("DELETE FROM inquiries WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        header("Location: admin_inquiries.php?deleted=1");
        exit();
    }
}

/* SEARCH */
$search = "";

if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

if (!empty($search)) {
    $search_param = "%" . $search . "%";

$stmt = $conn->prepare("
    SELECT 
        inquiries.*,
        technicians.technician_name,
        technicians.contact_number
    FROM inquiries
    LEFT JOIN technicians
        ON inquiries.technician_id = technicians.id
    WHERE customer_name LIKE ?
    OR contact_number LIKE ?
    OR email LIKE ?
    OR product_name LIKE ?
    OR inquiries.status LIKE ?
    ORDER BY date_submitted ASC
");

    $stmt->bind_param(
        "sssss",
        $search_param,
        $search_param,
        $search_param,
        $search_param,
        $search_param
    );

    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM inquiries ORDER BY date_submitted ASC, id ASC");
}

/* COUNTS */
$total = $conn->query("SELECT COUNT(*) AS total FROM inquiries")->fetch_assoc();
$pending = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'Pending'")->fetch_assoc();
$progress = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'In Progress'")->fetch_assoc();
$approved = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'Approved'")->fetch_assoc();
$completed = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status = 'Completed'")->fetch_assoc();

/* HELPERS */
function clean($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function statusClass($status) {
    $status = $status ?: 'Pending';
    return strtolower(str_replace(' ', '-', $status));
}

/* FETCH TECHNICIANS */
$technicians = [];

$tech_result = $conn->query("
    SELECT id, technician_name, contact_number, status
    FROM technicians
    WHERE status != 'Inactive'
    ORDER BY technician_name ASC
");

if ($tech_result) {
    while ($tech = $tech_result->fetch_assoc()) {
        $technicians[] = $tech;
    }
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Inquiries</title>

    <link rel="stylesheet" href="css/admin_dashboard.css">
    <link rel="stylesheet" href="css/admin_inquiries.css">

    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>

<div class="admin-wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="brand-box">
            <div class="brand-icon">
                <i class="fa-solid fa-snowflake"></i>
            </div>

            <div>
                <h2>GSB Admin</h2>
                <p>Admin Panel</p>
            </div>
        </div>

        <nav class="side-nav">
            <a href="admin_dashboard.php">
                <i class="fa-solid fa-table-cells-large"></i>
                <span>Dashboard</span>
            </a>

            <a href="admin_products.php">
                <i class="fa-solid fa-box-open"></i>
                <span>Products</span>
            </a>

            <a href="admin_inquiries.php" class="active">
                <i class="fa-regular fa-message"></i>
                <span>Inquiries</span>
                <span class="nav-badge"><?php echo clean($pending['total']); ?></span>
            </a>

            <a href="services_schedule.php">
                <i class="fa-regular fa-calendar-days"></i>
                <span>Service Schedule</span>
            </a>


            <a href="availability_schedule.php"><i class="fa-regular fa-calendar-days"></i> Availability Schedule</a>
            
            <a href="#">
                <i class="fa-solid fa-chart-column"></i>
                <span>Reports</span>
            </a>

            <a href="#">
                <i class="fa-solid fa-gear"></i>
                <span>Settings</span>
            </a>
        </nav>

        <a href="logout.php" class="logout-link">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- TOPBAR -->
        <div class="topbar">
            <button class="menu-btn">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="top-actions only-profile">
                <div class="bell">
                    <i class="fa-regular fa-bell"></i>
                    <span><?php echo clean($pending['total']); ?></span>
                </div>

                <div class="admin-profile">
                    <div class="avatar">
                        <i class="fa-solid fa-user"></i>
                    </div>

                    <div>
                        <strong>Admin</strong>
                        <small>Super Admin</small>
                    </div>

                    <i class="fa-solid fa-chevron-down"></i>
                </div>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <section class="page-header">
            <div class="row-header">
                <div>
                    <h1>Inquiries</h1>
                    <p>Manage customer inquiries, update status, and schedule service appointments.</p>
                </div>

                <form method="GET" class="inquiry-search-form">
                    <div class="mini-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search inquiries..." 
                            value="<?php echo clean($search); ?>"
                        >
                    </div>

                    <button type="submit" class="primary-btn">
                        Search
                    </button>
                </form>
            </div>
        </section>

        <!-- ALERTS -->
        <?php if (isset($_GET['success'])) { ?>
            <div class="alert success">
                Inquiry status updated successfully.
            </div>
        <?php } ?>

        <?php if (isset($_GET['deleted'])) { ?>
            <div class="alert success">
                Inquiry deleted successfully.
            </div>
        <?php } ?>

        <?php if (isset($_GET['scheduled'])) { ?>
            <div class="alert success">
                Service appointment scheduled successfully.
            </div>
        <?php } ?>

        <?php if (isset($error)) { ?>
            <div class="alert error">
                <?php echo clean($error); ?>
            </div>
        <?php } ?>

        <!-- STATUS CARDS -->
        <section class="summary-grid">

            <div class="summary-card orange">
                <div>
                    <p>Pending</p>
                    <h2><?php echo clean($pending['total']); ?></h2>
                    <small>Waiting for action</small>
                </div>

                <div class="summary-icon">
                    <i class="fa-regular fa-clock"></i>
                </div>
            </div>

            <div class="summary-card blue">
                <div>
                    <p>In Progress</p>
                    <h2><?php echo clean($progress['total']); ?></h2>
                    <small>Currently being handled</small>
                </div>

                <div class="summary-icon">
                    <i class="fa-solid fa-spinner"></i>
                </div>
            </div>

            <div class="summary-card purple">
                <div>
                    <p>Approved</p>
                    <h2><?php echo clean($approved['total']); ?></h2>
                    <small>Approved inquiries</small>
                </div>

                <div class="summary-icon">
                    <i class="fa-regular fa-circle-check"></i>
                </div>
            </div>

            <div class="summary-card green">
                <div>
                    <p>Completed</p>
                    <h2><?php echo clean($completed['total']); ?></h2>
                    <small>Finished inquiries</small>
                </div>

                <div class="summary-icon">
                    <i class="fa-solid fa-check"></i>
                </div>
            </div>

        </section>

        <!-- TABLE PANEL -->
        <section class="panel product-panel">

            <div class="panel-header">
                <div>
                    <h2>Customer Inquiries</h2>
                    <span>Total: <?php echo clean($total['total']); ?></span>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Product</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Technician</th>
                            <th>Change Status</th>
                            <th>Date</th>
                            <th>Re-Schedule</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($result && $result->num_rows > 0) { ?>

                        <?php while ($row = $result->fetch_assoc()) { ?>
                            <?php $current_status = $row['status'] ?: 'Pending'; ?>

                            <tr>
                                <td><?php echo clean($row['id']); ?></td>

                                <td>
                                    <strong><?php echo clean($row['customer_name']); ?></strong>
                                    <small>
                                        <?php echo !empty($row['address']) ? clean($row['address']) : 'No address provided'; ?>
                                    </small>
                                </td>

                                <td><?php echo clean($row['contact_number']); ?></td>

                                <td>
                                    <a href="mailto:<?php echo clean($row['email']); ?>">
                                        <?php echo clean($row['email']); ?>
                                    </a>
                                </td>

                                <td>
                                    <strong><?php echo clean($row['product_name']); ?></strong>
                                    <small><?php echo clean($row['brand']); ?></small>
                                </td>

                                <td class="message-cell">
                                    <?php echo clean($row['message']); ?>
                                </td>

                                <td>
                                    <span class="badge <?php echo statusClass($current_status); ?>">
                                        <?php echo clean($current_status); ?>
                                    </span>
                                </td>

                                <td>
                                    <form 
                                        method="POST" 
                                        class="status-update-form"
                                        data-current-status="<?php echo clean($current_status); ?>"
                                    >
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="id" value="<?php echo clean($row['id']); ?>">

                                        <select name="status">
                                            <option value="Pending" <?php if ($current_status == "Pending") echo "selected"; ?>>
                                                Pending
                                            </option>

                                            <option value="In Progress" <?php if ($current_status == "In Progress") echo "selected"; ?>>
                                                In Progress
                                            </option>

                                            <option value="Approved" <?php if ($current_status == "Approved") echo "selected"; ?>>
                                                Approved
                                            </option>

                                            <option value="Completed" <?php if ($current_status == "Completed") echo "selected"; ?>>
                                                Completed
                                            </option>
                                        </select>

                                        <button 
                                            type="button" 
                                            class="primary-btn mini-btn"
                                            onclick="openUpdateStatusModal(this)"
                                        >
                                            Update
                                        </button>
                                    </form>
                                </td>

                                <td>
                                    <?php echo date("M d, Y", strtotime($row['date_submitted'])); ?>
                                    <small><?php echo date("h:i A", strtotime($row['date_submitted'])); ?></small>
                                </td>

                                <td>
                                    <button 
                                        type="button"
                                        class="outline-btn mini-btn"
                                        onclick='openScheduleModal(
                                            <?php echo (int)$row['id']; ?>,
                                            <?php echo json_encode($row['user_id'] ?? ""); ?>,
                                            <?php echo json_encode($row['customer_name'] ?? ""); ?>,
                                            <?php echo json_encode($row['address'] ?? ""); ?>
                                        )'
                                    >
                                        <i class="fa-regular fa-calendar-plus"></i>
                                        Schedule
                                    </button>
                                </td>

                                <td>
                                    <a 
                                        href="admin_inquiries.php?delete=<?php echo clean($row['id']); ?>" 
                                        class="delete-icon"
                                        onclick="return confirm('Delete this inquiry?');"
                                        title="Delete inquiry"
                                    >
                                        <i class="fa-regular fa-trash-can"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>

                    <?php } else { ?>

                        <tr>
                            <td colspan="11" class="empty-row">
                                No inquiries found.
                            </td>
                        </tr>

                    <?php } ?>
                    </tbody>
                </table>
            </div>

        </section>

    </main>

</div>

<!-- SERVICE SCHEDULE MODAL -->
<div class="modal" id="scheduleModal">
    <div class="modal-card">

        <button class="modal-close" onclick="closeScheduleModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <h2>Schedule Service Appointment</h2>
        <p>Schedule installation, repair, or maintenance for this inquiry.</p>

        <form method="POST">

            <input type="hidden" name="inquiry_id" id="scheduleInquiryId">
            <input type="hidden" name="user_id" id="scheduleUserId">

            <div class="form-grid">

                <div class="form-group">
                    <label>Customer Name</label>
                    <input 
                        type="text" 
                        name="customer_name" 
                        id="scheduleCustomerName" 
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Service Type</label>
                    <select name="service_type" required>
                        <option value="">Select service type</option>
                        <option value="Installation">Installation</option>
                        <option value="Repair">Repair</option>
                        <option value="Maintenance">Maintenance</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Service Date</label>
                    <input 
                        type="date" 
                        name="service_date" 
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Service Time</label>
                    <input 
                        type="time" 
                        name="service_time" 
                        required
                    >
                </div>

                <div class="form-group full">
                    <label>Location</label>
                    <textarea 
                        name="location" 
                        id="scheduleLocation" 
                        rows="3" 
                        required
                    ></textarea>
                </div>

            </div>

            <div class="modal-actions">
                <button type="button" class="outline-btn" onclick="closeScheduleModal()">
                    Cancel
                </button>

                <button type="submit" name="schedule_service" class="primary-btn">
                    Save Schedule
                </button>
            </div>

        </form>
    </div>
</div>

<!-- UPDATE STATUS CONFIRMATION MODAL -->
<div class="modal" id="updateStatusModal">
    <div class="modal-card confirm-modal-card">

        <button class="modal-close" onclick="closeUpdateStatusModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <h2>Confirm Status Update</h2>

        <p>
            Are you sure you want to update this inquiry status?
        </p>

        <div class="status-confirm-box">
            <p>
                <strong>Current Status:</strong>
                <span id="currentStatusText"></span>
            </p>

            <p>
                <strong>New Status:</strong>
                <span id="newStatusText"></span>
            </p>
        </div>

        <div class="modal-actions">
            <button type="button" class="outline-btn" onclick="closeUpdateStatusModal()">
                Cancel
            </button>

            <button type="button" class="primary-btn" id="confirmUpdateStatusBtn">
                Yes, Update
            </button>
        </div>

    </div>
</div>

<script>
let selectedStatusForm = null;

function openScheduleModal(inquiryId, userId, customerName, address) {
    document.getElementById("scheduleInquiryId").value = inquiryId;
    document.getElementById("scheduleUserId").value = userId;
    document.getElementById("scheduleCustomerName").value = customerName;
    document.getElementById("scheduleLocation").value = address;

    document.getElementById("scheduleModal").classList.add("show");
}

function closeScheduleModal() {
    document.getElementById("scheduleModal").classList.remove("show");
}

function openUpdateStatusModal(button) {
    selectedStatusForm = button.closest("form");

    const currentStatus = selectedStatusForm.dataset.currentStatus;
    const newStatus = selectedStatusForm.querySelector('select[name="status"]').value;

    if (currentStatus === newStatus) {
        alert("Please select a different status before updating.");
        selectedStatusForm = null;
        return;
    }

    document.getElementById("currentStatusText").textContent = currentStatus;
    document.getElementById("newStatusText").textContent = newStatus;

    document.getElementById("updateStatusModal").classList.add("show");
}

function closeUpdateStatusModal() {
    document.getElementById("updateStatusModal").classList.remove("show");
    selectedStatusForm = null;
}

document.getElementById("confirmUpdateStatusBtn").addEventListener("click", function() {
    if (selectedStatusForm) {
        selectedStatusForm.submit();
    }
});

document.addEventListener("click", function(e) {
    const scheduleModal = document.getElementById("scheduleModal");
    const updateStatusModal = document.getElementById("updateStatusModal");

    if (e.target === scheduleModal) {
        closeScheduleModal();
    }

    if (e.target === updateStatusModal) {
        closeUpdateStatusModal();
    }
});
</script>

</body>
</html>