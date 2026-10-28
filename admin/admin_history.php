<?php

session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: admin_login.php");
    exit();
}

include "includes/db_connect.php";
require_once __DIR__ . '/../includes/auth_helpers.php';

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
    return strtolower(str_replace(' ', '-', $status));
}

// Refactored Helper to securely bind both search strings AND date filters
function fetchRecords($conn, $sql, $params = [], $types = "") {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Query prepare failed: " . $conn->error);
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    $stmt->close();
    return $data;
}

/* =========================================================
   DELETE ACTIONS — POST only, CSRF verified
========================================================= */

// Delete Inquiry (Order)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inquiry'])) {
    verify_csrf();
    $id = (int)$_POST['delete_inquiry'];
    if ($id > 0) {
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
                header("Location: admin_history.php?deleted=1");
                exit();
            } else {
                $error = "Failed to delete inquiry: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Delete Direct Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_service'])) {
    verify_csrf();
    $id = (int)$_POST['delete_service'];
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM services_schedule WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                header("Location: admin_history.php?deleted=1");
                exit();
            } else {
                $error = "Failed to delete service: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

/* =========================================================
   DATA RETRIEVAL WITH SEARCH & DATE FILTERS
========================================================= */

$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$search_param = !empty($search) ? "%" . $search . "%" : null;

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : "";
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : "";

// Smart Query Builder to handle combinations of Search text + Date Ranges
function buildQueryParams($base_sql, $search_param, $search_cols, $start_date, $end_date, $date_col) {
    $sql = $base_sql;
    $params = [];
    $types = "";

    if ($search_param) {
        $col_conditions = [];
        foreach ($search_cols as $col) {
            $col_conditions[] = "$col LIKE ?";
            $params[] = $search_param;
            $types .= "s";
        }
        $sql .= " AND (" . implode(" OR ", $col_conditions) . ")";
    }

    if (!empty($start_date)) {
        $sql .= " AND DATE($date_col) >= ?";
        $params[] = $start_date;
        $types .= "s";
    }

    if (!empty($end_date)) {
        $sql .= " AND DATE($date_col) <= ?";
        $params[] = $end_date;
        $types .= "s";
    }

    return ['sql' => $sql, 'params' => $params, 'types' => $types];
}

// 1. SOLD ORDERS (INQUIRIES) - Added p.brand and p.CapacityHP
$orders_base = "
    SELECT i.*, p.SRP_PHP as price, p.is_promo, p.discount_percent, p.brand, p.CapacityHP as hp
    FROM inquiries i
    LEFT JOIN products p ON i.product_id = p.id
    WHERE i.status = 'Sold'
";
$q1 = buildQueryParams($orders_base, $search_param, ['i.customer_name', 'i.contact_number', 'i.email', 'i.product_name'], $start_date, $end_date, 'i.date_submitted');
$q1['sql'] .= " ORDER BY i.date_submitted DESC, i.id DESC";
$completed_orders = fetchRecords($conn, $q1['sql'], $q1['params'], $q1['types']);


// 2. COMPLETED SERVICES (DIRECT)
$services_base = "
    SELECT ss.*, t.technician_name 
    FROM services_schedule ss
    LEFT JOIN technicians t ON ss.technician_id = t.id
    WHERE ss.status = 'Completed' AND (ss.inquiry_id IS NULL OR ss.inquiry_id = 0)
";
$q2 = buildQueryParams($services_base, $search_param, ['ss.customer_name', 'ss.service_type', 'ss.location'], $start_date, $end_date, 'ss.service_date');
$q2['sql'] .= " ORDER BY ss.service_date DESC, ss.id DESC";
$completed_services = fetchRecords($conn, $q2['sql'], $q2['params'], $q2['types']);


// 3. CANCELLED ORDERS (INQUIRIES) - Added p.brand and p.CapacityHP
$canc_orders_base = "
    SELECT i.*, p.SRP_PHP as price, p.is_promo, p.discount_percent, p.brand, p.CapacityHP as hp
    FROM inquiries i
    LEFT JOIN products p ON i.product_id = p.id
    WHERE i.status = 'Cancelled'
";
$q3 = buildQueryParams($canc_orders_base, $search_param, ['i.customer_name', 'i.contact_number', 'i.email', 'i.product_name'], $start_date, $end_date, 'i.date_submitted');
$q3['sql'] .= " ORDER BY i.date_submitted DESC, i.id DESC";
$cancelled_orders = fetchRecords($conn, $q3['sql'], $q3['params'], $q3['types']);


// 4. CANCELLED SERVICES (DIRECT)
$canc_services_base = "
    SELECT ss.*, t.technician_name 
    FROM services_schedule ss
    LEFT JOIN technicians t ON ss.technician_id = t.id
    WHERE ss.status = 'Cancelled' AND (ss.inquiry_id IS NULL OR ss.inquiry_id = 0)
";
$q4 = buildQueryParams($canc_services_base, $search_param, ['ss.customer_name', 'ss.service_type', 'ss.location'], $start_date, $end_date, 'ss.service_date');
$q4['sql'] .= " ORDER BY ss.service_date DESC, ss.id DESC";
$cancelled_services = fetchRecords($conn, $q4['sql'], $q4['params'], $q4['types']);


// Fetch pending count for the notification bell
$pending_count_result = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status='Pending'");
$pending_count = $pending_count_result ? (int)$pending_count_result->fetch_assoc()['total'] : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin History</title>
    <link rel="stylesheet" href="includes/css/admin_dashboard.css">
    <link rel="stylesheet" href="includes/css/admin_inquiries.css">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        /* Tracker-style Toggle Switch */
        .tracker-switch-wrapper {
            background-color: #f1f5f9;
            display: inline-flex;
            padding: 4px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .switch-btn {
            background: transparent;
            border: none;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 0.95rem;
            color: #475569;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        .switch-btn.active {
            background-color: #0022a1; 
            color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .view-section {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        .view-section.active-view {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Professional Typed Date Filter Styling */
        .date-filter-group {
            display: flex;
            align-items: center;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0 14px;
            height: 42px; 
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .date-filter-group:focus-within {
            border-color: #0020C2;
            box-shadow: 0 0 0 3px rgba(0, 32, 194, 0.1);
        }
        .date-filter-group i {
            color: #94a3b8;
            margin-right: 8px;
            font-size: 14px;
        }
        .date-filter-group input {
            border: none;
            outline: none;
            color: #334155;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            background: transparent;
            width: 95px;
            text-align: center;
        }
        .date-filter-group input::placeholder {
            color: #cbd5e1;
            font-weight: 400;
        }
        .date-filter-group span {
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.85rem;
            margin: 0 10px;
        }
    </style>
</head>

<body>
<div class="admin-wrapper">
    <?php include "includes/admin_sidebar.php"; ?>

    <main class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <section class="page-header">
            <div class="row-header" style="flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>Archive & History</h1>
                    <p>View sold and cancelled records for orders and services.</p>
                </div>

                <!-- Integrated Search & Date Filter Form -->
                <form method="GET" class="inquiry-search-form" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    
                    <div class="mini-search" style="margin: 0;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" placeholder="Search history..." value="<?php echo clean($search); ?>">
                    </div>
                    
                    <!-- Text-Based Auto-Formatting Date Range Input -->
                    <div class="date-filter-group">
                        <i class="fa-regular fa-calendar-days"></i>
                        <input type="text" class="date-input-auto" name="start_date" value="<?php echo clean($start_date); ?>" placeholder="YYYY-MM-DD" maxlength="10">
                        <span>to</span>
                        <input type="text" class="date-input-auto" name="end_date" value="<?php echo clean($end_date); ?>" placeholder="YYYY-MM-DD" maxlength="10">
                    </div>

                    <button type="submit" class="primary-btn">Filter</button>
                    
                    <!-- Show 'Clear' button only if a filter is active -->
                    <?php if($search || $start_date || $end_date): ?>
                        <a href="admin_history.php" class="outline-btn" style="text-decoration: none; padding: 10px 16px;">Clear</a>
                    <?php endif; ?>
                </form>

            </div>
        </section>

        <?php if (isset($_GET['deleted'])) { ?>
            <div class="alert success">Record deleted permanently.</div>
        <?php } ?>
        <?php if (isset($error)) { ?>
            <div class="alert error"><?php echo clean($error); ?></div>
        <?php } ?>

        <!-- TOGGLE BUTTONS -->
        <div class="tracker-switch-wrapper">
            <button class="switch-btn active" id="btn-orders" onclick="toggleView('orders')">Sold Orders</button>
            <button class="switch-btn" id="btn-services" onclick="toggleView('services')">Completed Services</button>
            <button class="switch-btn" id="btn-cancelled" onclick="toggleView('cancelled')">Cancelled Records</button>
        </div>

        <!-- ==================== SOLD ORDERS VIEW ==================== -->
        <div id="view-orders" class="view-section active-view">
            <section class="panel product-panel">
                <div class="panel-header">
                    <div>
                        <h2>Sold Orders</h2>
                        <span>Total: <?php echo count($completed_orders); ?></span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($completed_orders) > 0) { ?>
                            <?php foreach ($completed_orders as $row) { ?>
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

                                    <!-- VIEW DETAILS BUTTON -->
                                    <td>
                                        <button type="button" class="view-details-btn" onclick="openDetailsModal(this)">
                                            View Details
                                        </button>

                                        <!-- MODAL DATA TEMPLATE -->
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

                                                    <?php if (!empty($row['hp'])): ?>
                                                    <div class="details-row">
                                                        <span class="details-label">Horsepower (HP):</span>
                                                        <span class="details-value"><?php echo clean($row['hp']); ?></span>
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

                                    <td><span class="badge sold">Sold</span></td>
                                    <td>
                                        <form method="POST" action="admin_history.php" style="display:inline;" onsubmit="return confirm('Delete this sold order permanently?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="delete_inquiry" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="delete-icon" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="5" class="empty-row">No sold orders found.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- ==================== COMPLETED SERVICES VIEW ==================== -->
        <div id="view-services" class="view-section">
            <section class="panel product-panel">
                <div class="panel-header">
                    <div>
                        <h2>Completed Services</h2>
                        <span>Total: <?php echo count($completed_services); ?></span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($completed_services) > 0) { ?>
                            <?php foreach ($completed_services as $row) { ?>
                                <?php 
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
                                        <strong><?php echo clean($row['customer_name'] ?? 'N/A'); ?></strong>
                                        <small>
                                            <i class="fa-regular fa-clock" style="margin-right: 3px;"></i>
                                            <?php echo clean($submitted_display); ?>
                                        </small>
                                    </td>

                                    <!-- VIEW DETAILS BUTTON -->
                                    <td>
                                        <button type="button" class="view-details-btn" onclick="openDetailsModal(this)">
                                            View Details
                                        </button>

                                        <!-- MODAL DATA TEMPLATE -->
                                        <div class="inquiry-details-template" style="display: none;">
                                            <div class="details-modal-grid">
                                                <div class="details-section">
                                                    <h4><i class="fa-regular fa-address-card"></i> Contact Information</h4>
                                                    <div class="details-row">
                                                        <span class="details-label">Customer:</span>
                                                        <span class="details-value"><strong><?php echo clean($row['customer_name'] ?? 'N/A'); ?></strong></span>
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
                                                        <span class="details-value"><strong><?php echo clean($row['service_type'] ?? 'N/A'); ?></strong></span>
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
                                                            echo (!empty($sched_time) && $sched_time !== '00:00' && $sched_time !== '00:00:00') ? date("h:i A", strtotime($sched_time)) : "TBA";
                                                            ?>
                                                        </span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Assigned Technician:</span>
                                                        <span class="details-value"><strong><?php echo clean($row['technician_name'] ?? 'Unassigned'); ?></strong></span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Current Status:</span>
                                                        <span class="details-value">
                                                            <span class="badge" style="background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 20px; font-weight: 600; font-size: 0.85rem;">Completed</span>
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

                                    <td><span class="badge" style="background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 20px; font-weight: 600; font-size: 0.85rem;">Completed</span></td>
                                    <td>
                                        <form method="POST" action="admin_history.php" style="display:inline;" onsubmit="return confirm('Delete this completed service permanently?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="delete_service" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="delete-icon" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="5" class="empty-row">No completed services found.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- ==================== CANCELLED RECORDS VIEW ==================== -->
        <div id="view-cancelled" class="view-section">
            
            <!-- Cancelled Orders -->
            <section class="panel product-panel" style="margin-bottom: 2rem;">
                <div class="panel-header">
                    <div>
                        <h2>Cancelled Orders</h2>
                        <span>Total: <?php echo count($cancelled_orders); ?></span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($cancelled_orders) > 0) { ?>
                            <?php foreach ($cancelled_orders as $row) { ?>
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

                                    <!-- VIEW DETAILS BUTTON -->
                                    <td>
                                        <button type="button" class="view-details-btn" onclick="openDetailsModal(this)">
                                            View Details
                                        </button>

                                        <!-- MODAL DATA TEMPLATE -->
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

                                                    <?php if (!empty($row['hp'])): ?>
                                                    <div class="details-row">
                                                        <span class="details-label">Horsepower (HP):</span>
                                                        <span class="details-value"><?php echo clean($row['hp']); ?></span>
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

                                    <td><span class="badge cancelled">Cancelled</span></td>
                                    <td>
                                        <form method="POST" action="admin_history.php" style="display:inline;" onsubmit="return confirm('Delete this cancelled order permanently?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="delete_inquiry" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="delete-icon" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="5" class="empty-row">No cancelled orders found.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Cancelled Services -->
            <section class="panel product-panel">
                <div class="panel-header">
                    <div>
                        <h2>Cancelled Services</h2>
                        <span>Total: <?php echo count($cancelled_services); ?></span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($cancelled_services) > 0) { ?>
                            <?php foreach ($cancelled_services as $row) { ?>
                                <?php 
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
                                        <strong><?php echo clean($row['customer_name'] ?? 'N/A'); ?></strong>
                                        <small>
                                            <i class="fa-regular fa-clock" style="margin-right: 3px;"></i>
                                            <?php echo clean($submitted_display); ?>
                                        </small>
                                    </td>
                                    
                                    <!-- VIEW DETAILS BUTTON -->
                                    <td>
                                        <button type="button" class="view-details-btn" onclick="openDetailsModal(this)">
                                            View Details
                                        </button>

                                        <!-- MODAL DATA TEMPLATE -->
                                        <div class="inquiry-details-template" style="display: none;">
                                            <div class="details-modal-grid">
                                                <div class="details-section">
                                                    <h4><i class="fa-regular fa-address-card"></i> Contact Information</h4>
                                                    <div class="details-row">
                                                        <span class="details-label">Customer:</span>
                                                        <span class="details-value"><strong><?php echo clean($row['customer_name'] ?? 'N/A'); ?></strong></span>
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
                                                        <span class="details-value"><strong><?php echo clean($row['service_type'] ?? 'N/A'); ?></strong></span>
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
                                                            echo (!empty($sched_time) && $sched_time !== '00:00' && $sched_time !== '00:00:00') ? date("h:i A", strtotime($sched_time)) : "TBA";
                                                            ?>
                                                        </span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Assigned Technician:</span>
                                                        <span class="details-value"><strong><?php echo clean($row['technician_name'] ?? 'Unassigned'); ?></strong></span>
                                                    </div>
                                                    <div class="details-row">
                                                        <span class="details-label">Current Status:</span>
                                                        <span class="details-value">
                                                            <span class="badge cancelled">Cancelled</span>
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
                                    
                                    <td><span class="badge cancelled">Cancelled</span></td>
                                    <td>
                                        <form method="POST" action="admin_history.php" style="display:inline;" onsubmit="return confirm('Delete this cancelled service permanently?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="delete_service" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="delete-icon" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="5" class="empty-row">No cancelled services found.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

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
            <i class="fa-solid fa-circle-info" style="color: #2563eb;"></i> Record Details
        </h2>
        <div id="viewDetailsModalBody"></div>
        <div class="modal-actions" style="border-top: 1px solid #e2e8f0; margin-top: 20px; padding-top: 15px; justify-content: flex-end;">
            <button type="button" class="outline-btn" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- INJECT GLOBAL MODALS -->
<?php include 'includes/global_modals.php'; ?>

<script>
// Toggle Tabs
function toggleView(viewName) {
    document.querySelectorAll('.view-section').forEach(function(el) {
        el.classList.remove('active-view');
    });
    
    document.querySelectorAll('.switch-btn').forEach(function(el) {
        el.classList.remove('active');
    });
    
    document.getElementById('view-' + viewName).classList.add('active-view');
    document.getElementById('btn-' + viewName).classList.add('active');
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

// Close Modals when clicking outside
document.addEventListener("click", function (e) {
    const viewDetailsModal = document.getElementById("viewDetailsModal");
    if (viewDetailsModal && e.target === viewDetailsModal) closeDetailsModal();
});

// Smart Auto-Formatting for Date Inputs (Formats to YYYY-MM-DD as you type)
document.querySelectorAll('.date-input-auto').forEach(input => {
    input.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, ''); // Strip non-numeric chars
        
        if (value.length > 8) {
            value = value.substring(0, 8); // Limit to 8 digits
        }

        if (value.length > 4) {
            value = value.substring(0, 4) + '-' + value.substring(4);
        }
        if (value.length > 7) {
            value = value.substring(0, 7) + '-' + value.substring(7);
        }
        
        e.target.value = value;
    });
});
</script>

</body>
</html>