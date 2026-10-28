<?php

/* =========================================================
   GET CURRENT PAGE
========================================================= */

$current_page = basename($_SERVER['PHP_SELF']);


/* =========================================================
   GET PENDING ORDERS COUNT (INDEPENDENT QUERY)
========================================================= */

$sidebar_pending_inquiries_count = 0;

$inquiry_count_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM inquiries
    WHERE status = 'Pending'
");

if ($inquiry_count_result) {
    $inquiry_data = $inquiry_count_result->fetch_assoc();
    $sidebar_pending_inquiries_count = (int)(
        $inquiry_data['total'] ?? 0
    );
}


/* =========================================================
   GET PENDING SERVICE REQUESTS COUNT (INDEPENDENT QUERY)
========================================================= */

$sidebar_pending_services_count = 0;

$services_count_result = $conn->query("
    SELECT COUNT(*) AS total 
    FROM services_schedule 
    WHERE status = 'Pending'
");

if ($services_count_result) {
    $services_data = $services_count_result->fetch_assoc();
    $sidebar_pending_services_count = (int)(
        $services_data['total'] ?? 0
    );
}

?>

<aside class="sidebar" id="adminSidebar">

    <!-- =====================================================
         BRAND
    ====================================================== -->

    <div class="brand-box">
        <div class="brand-icon">
            <i class="fa-solid fa-snowflake"></i>
        </div>
        <div>
            <h2>GSB Admin</h2>
            <p>Admin Panel</p>
        </div>
    </div>


    <!-- =====================================================
         NAVIGATION
    ====================================================== -->

    <nav class="side-nav">

        <!-- DASHBOARD -->
        <a
            href="admin_dashboard.php"
            class="<?php echo ($current_page === 'admin_dashboard.php') ? 'active' : ''; ?>"
        >
            <i class="fa-solid fa-table-cells-large"></i>
            <span>Dashboard</span>
        </a>

        <!-- PRODUCTS -->
        <a
            href="admin_products.php"
            class="<?php echo ($current_page === 'admin_products.php') ? 'active' : ''; ?>"
        >
            <i class="fa-solid fa-box-open"></i>
            <span>Products</span>
        </a>

        <!-- ORDERS -->
        <a
            href="admin_inquiries.php"
            class="<?php echo ($current_page === 'admin_inquiries.php') ? 'active' : ''; ?>"
        >
            <i class="fa-regular fa-message"></i>
            <span>Orders</span>

            <?php if ($sidebar_pending_inquiries_count > 0): ?>
                <span class="nav-badge">
                    <?php echo $sidebar_pending_inquiries_count; ?>
                </span>
            <?php endif; ?>
        </a>

        <!-- SERVICE REQUESTS -->
        <a
            href="services_schedule.php"
            class="<?php echo ($current_page === 'services_schedule.php') ? 'active' : ''; ?>"
        >
            <i class="fa-solid fa-wrench"></i>
            <span>Service Requests</span>

            <?php if ($sidebar_pending_services_count > 0): ?>
                <span class="nav-badge">
                    <?php echo $sidebar_pending_services_count; ?>
                </span>
            <?php endif; ?>
        </a>

        <!-- AVAILABILITY SCHEDULE -->
        <a
            href="availability_schedule.php"
            class="<?php echo ($current_page === 'availability_schedule.php') ? 'active' : ''; ?>"
        >
            <i class="fa-regular fa-calendar-days"></i>
            <span>Availability Schedule</span>
        </a>

        <!-- HISTORY (NEW) -->
        <a
            href="admin_history.php"
            class="<?php echo ($current_page === 'admin_history.php') ? 'active' : ''; ?>"
        >
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>History</span>
        </a>

        <!-- RATINGS -->
        <a
            href="admin_ratings.php"
            class="<?php echo ($current_page === 'admin_ratings.php') ? 'active' : ''; ?>"
        >
            <i class="fa-solid fa-star"></i>
            <span>Ratings</span>
        </a>

        <!-- CUSTOMERS / USERS (NEW) -->
        <a
            href="admin_users.php"
            class="<?php echo ($current_page === 'admin_users.php') ? 'active' : ''; ?>"
        >
            <i class="fa-solid fa-users"></i>
            <span>Customers</span>
        </a>

    </nav>
</aside>

<!-- Mobile: overlay backdrop -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Mobile: Bottom Navigation Bar -->
<nav class="admin-bottom-nav">
    <a href="admin_dashboard.php" class="<?php echo ($current_page === 'admin_dashboard.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-table-cells-large"></i>
        <span>Home</span>
    </a>
    <a href="admin_products.php" class="<?php echo ($current_page === 'admin_products.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-box-open"></i>
        <span>Products</span>
    </a>
    <a href="admin_inquiries.php" class="<?php echo ($current_page === 'admin_inquiries.php') ? 'active' : ''; ?>">
        <i class="fa-regular fa-message"></i>
        <span>Orders</span>
        <?php if ($sidebar_pending_inquiries_count > 0): ?>
            <span class="bnav-badge"><?php echo min($sidebar_pending_inquiries_count, 99); ?></span>
        <?php endif; ?>
    </a>
    <a href="services_schedule.php" class="<?php echo ($current_page === 'services_schedule.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-wrench"></i>
        <span>Service</span>
        <?php if ($sidebar_pending_services_count > 0): ?>
            <span class="bnav-badge"><?php echo min($sidebar_pending_services_count, 99); ?></span>
        <?php endif; ?>
    </a>
    <a href="availability_schedule.php" class="<?php echo ($current_page === 'availability_schedule.php') ? 'active' : ''; ?>">
        <i class="fa-regular fa-calendar-days"></i>
        <span>Availability</span>
    </a>
    <a href="admin_history.php" class="<?php echo ($current_page === 'admin_history.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>History</span>
    </a>
    <a href="admin_ratings.php" class="<?php echo ($current_page === 'admin_ratings.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-star"></i>
        <span>Ratings</span>
    </a>
    <a href="admin_users.php" class="<?php echo ($current_page === 'admin_users.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-users"></i>
        <span>Users</span>
    </a>
</nav>

<script>
(function() {
    var sidebar  = document.getElementById('adminSidebar');
    var overlay  = document.getElementById('sidebarOverlay');
    var toggleBtn = document.getElementById('toggleSidebarBtn');

    function openSidebar() {
        if (sidebar)  sidebar.classList.add('mobile-open');
        if (overlay)  overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        if (sidebar)  sidebar.classList.remove('mobile-open');
        if (overlay)  overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }
    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });
})();
</script>