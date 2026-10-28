<?php
session_start();

require_once 'includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_helpers.php';

require_admin_session('admin_login.php');

function e($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function badgeClass($value){
    return strtolower(str_replace(' ', '-', trim((string)$value)));
}

function countQuery($conn, $sql){
    $result = $conn->query($sql);
    if(!$result){ return 0; }
    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

$today = date("Y-m-d");

// ==========================================
// CORRECTED QUERIES (Separating Installations & Services)
// ==========================================
$total_inquiries = countQuery($conn, "SELECT COUNT(*) AS total FROM inquiries");
$recent_inquiries = countQuery($conn, "SELECT COUNT(*) AS total FROM inquiries WHERE date_submitted >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$pending_count = countQuery($conn, "SELECT COUNT(*) AS total FROM inquiries WHERE status='Pending'");

// OUT OF STOCK QUERY
$out_of_stock = countQuery($conn, "SELECT COUNT(*) AS total FROM products WHERE stock_quantity <= 0 OR stock_status = 'Out of Stock'");

// ADDED: (inquiry_id IS NULL OR inquiry_id = 0) to separate pure services from inquiry installations
$today_services = countQuery($conn, "SELECT COUNT(*) AS total FROM services_schedule WHERE service_date='$today' AND (inquiry_id IS NULL OR inquiry_id = 0) AND status NOT IN ('Cancelled', 'Deleted', 'Declined')");
$today_installations = countQuery($conn, "SELECT COUNT(*) AS total FROM inquiries WHERE delivery_date='$today' AND status NOT IN ('Cancelled', 'Deleted', 'Declined')");

$inquiries = $conn->query("SELECT * FROM inquiries ORDER BY date_submitted DESC LIMIT 5");
$products = $conn->query("SELECT * FROM products ORDER BY id DESC LIMIT 5");

// ADDED: (inquiry_id IS NULL OR inquiry_id = 0)
$services = $conn->query("SELECT * FROM services_schedule WHERE service_date='$today' AND (inquiry_id IS NULL OR inquiry_id = 0) AND status NOT IN ('Cancelled', 'Deleted', 'Declined') ORDER BY service_time ASC LIMIT 5");
$installations = $conn->query("SELECT * FROM inquiries WHERE delivery_date='$today' AND status NOT IN ('Cancelled', 'Deleted', 'Declined') ORDER BY id DESC LIMIT 5");

// Status chart calculations
$status_counts = [
    'Pending' => 0,
    'In Progress' => 0,
    'Approved' => 0,
    'Completed' => 0
];

$status_result = $conn->query("SELECT status, COUNT(*) AS total FROM inquiries GROUP BY status");
if($status_result){
    while($row = $status_result->fetch_assoc()){
        if(isset($status_counts[$row['status']])){
            $status_counts[$row['status']] = (int)$row['total'];
        }
    }
}

$status_total = array_sum($status_counts);
$pending_pct = $status_total ? round(($status_counts['Pending'] / $status_total) * 100) : 0;
$progress_pct = $status_total ? round(($status_counts['In Progress'] / $status_total) * 100) : 0;
$approved_pct = $status_total ? round(($status_counts['Approved'] / $status_total) * 100) : 0;
$completed_pct = max(0, 100 - $pending_pct - $progress_pct - $approved_pct);

$pending_end = $pending_pct;
$progress_end = $pending_end + $progress_pct;
$approved_end = $progress_end + $approved_pct;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>GSB Admin Dashboard</title>
<link rel="stylesheet" href="includes/css/admin_dashboard.css">
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>
<div class="admin-wrapper">

   <?php include 'includes/admin_sidebar.php'; ?>

    <main class="main-content">
        
        <?php include 'includes/topbar.php'; ?>

        <section class="page-header">
            <h1>Welcome back, Admin!</h1>
            <p>Here's what's happening with your business today.</p>
        </section>

        <!-- SUMMARY CARDS -->
        <section class="summary-grid">
            <div class="summary-card blue">
                <div><p>Total Inquiries</p><h2><?php echo $total_inquiries; ?></h2><small>All time</small></div>
                <div class="summary-icon"><i class="fa-regular fa-comment"></i></div>
            </div>
            <div class="summary-card green">
                <div><p>Installations Today</p><h2><?php echo $today_installations; ?></h2><small>Scheduled today</small></div>
                <div class="summary-icon"><i class="fa-solid fa-wrench"></i></div>
            </div>
            <div class="summary-card orange">
                <div><p>Today's Services</p><h2><?php echo $today_services; ?></h2><small>Scheduled today</small></div>
                <div class="summary-icon"><i class="fa-regular fa-calendar"></i></div>
            </div>
            
            <div class="summary-card purple" style="border-left-color: #ef4444;">
                <div><p>Out of Stock</p><h2><?php echo $out_of_stock; ?></h2><small>Products needing restock</small></div>
                <div class="summary-icon" style="background: #fee2e2; color: #ef4444;"><i class="fa-solid fa-box-open"></i></div>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="panel wide-panel">
                <div class="panel-header">
                    <h2>Recent Inquiries</h2>
                    <a href="admin_inquiries.php" class="outline-btn">View All</a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Concern</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if($inquiries && $inquiries->num_rows > 0){ ?>
                            <?php while($row = $inquiries->fetch_assoc()){ ?>
                            <tr>
                                <td><?php echo e($row['customer_name']); ?></td>
                                <td><?php echo e($row['contact_number']); ?></td>
                                <td><?php echo e(!empty($row['message']) ? $row['message'] : $row['product_name']); ?></td>
                                <td><span class="badge <?php echo badgeClass($row['status']); ?>"><?php echo e($row['status']); ?></span></td>
                                <td><?php echo date("M d, Y", strtotime($row['date_submitted'])); ?></td>
                            </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="5" class="empty-row">No inquiries found</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel schedule-panel">
                <div class="panel-header">
                    <h2><i class="fa-regular fa-calendar-days"></i> Today's Schedule</h2>
                    <span><?php echo date("M d, Y"); ?></span>
                </div>

                <div class="schedule-box">
                    <div class="schedule-title"><strong>Installations (<?php echo $today_installations; ?>)</strong><a href="admin_inquiries.php">View All</a></div>
                    <?php if($installations && $installations->num_rows > 0){ ?>
                        <?php while($row = $installations->fetch_assoc()){ ?>
                        <div class="schedule-item">
                            <div><strong><?php echo e($row['customer_name']); ?></strong><small><i class="fa-solid fa-location-dot"></i> <?php echo e($row['address']); ?></small></div>
                            <span><?php echo e($row['product_name']); ?></span>
                            <em class="badge scheduled">Scheduled</em>
                        </div>
                        <?php } ?>
                    <?php } else { ?>
                        <p class="empty-note">No installations scheduled today.</p>
                    <?php } ?>
                </div>

                <div class="schedule-box">
                    <div class="schedule-title"><strong>Services (<?php echo $today_services; ?>)</strong><a href="services_schedule.php">View All</a></div>
                    <?php if($services && $services->num_rows > 0){ ?>
                        <?php while($row = $services->fetch_assoc()){ ?>
                        <div class="schedule-item">
                            <div><strong><?php echo e($row['customer_name']); ?></strong><small><i class="fa-solid fa-location-dot"></i> <?php echo e($row['location']); ?></small></div>
                            <span><?php echo !empty($row['service_time']) ? date("h:i A", strtotime($row['service_time'])) : 'Time TBD'; ?></span>
                            <em class="badge scheduled">Scheduled</em>
                        </div>
                        <?php } ?>
                    <?php } else { ?>
                        <p class="empty-note">No services scheduled today.</p>
                    <?php } ?>
                </div>
            </div>

            <div class="panel wide-panel">
                <div class="panel-header">
                    <h2>Products Overview</h2>
                    <a href="admin_products.php" class="outline-btn">View All</a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if($products && $products->num_rows > 0){ ?>
                            <?php while($row = $products->fetch_assoc()){ ?>
                            <tr>
                                <td><?php echo e($row['IndoorUnitModel'] ?: $row['SeriesName']); ?></td>
                                <td><?php echo e(($row['brand'] ?? '') . ' ' . ($row['midea_type'] ?? '')); ?></td>
                                <td><?php echo isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0; ?></td>
                                <td><span class="badge <?php echo badgeClass($row['stock_status'] ?? 'Available'); ?>"><?php echo e($row['stock_status'] ?? 'Available'); ?></span></td>
                                <td class="actions">
                                    <a href="admin_products.php?edit=<?php echo (int)$row['id']; ?>"><i class="fa-regular fa-pen-to-square"></i></a>
                                    <a href="admin_products.php" onclick="event.preventDefault(); window.location.href='admin_products.php';" title="Manage in Products page"><i class="fa-regular fa-trash-can"></i></a>
                                </td>
                            </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="5" class="empty-row">No products found</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel chart-panel">
                <div class="panel-header"><h2>Inquiry Status Overview</h2></div>
                <div class="chart-content">
                    <div class="donut" style="--pending: <?php echo $pending_end; ?>%; --progress: <?php echo $progress_end; ?>%; --approved: <?php echo $approved_end; ?>%;"></div>
                    <div class="legend">
                        <?php foreach($status_counts as $label => $count){
                            $percent = $status_total ? round(($count / $status_total) * 100) : 0;
                        ?>
                        <div><span class="dot <?php echo badgeClass($label); ?>"></span><?php echo $label; ?><strong><?php echo $count; ?> (<?php echo $percent; ?>%)</strong></div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<!-- INJECT GLOBAL MODALS -->
<?php include 'includes/global_modals.php'; ?>

</body>
</html>