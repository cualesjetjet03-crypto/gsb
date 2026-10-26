<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. GUARANTEED DATABASE CONNECTION
if (!isset($conn)) {
    require_once __DIR__ . '/db_connect.php';
}

$topbar_pending_inquiries = 0;
$topbar_pending_services = 0;

// 2. FETCH COUNTS SAFELY
if (isset($conn)) {
    // Inquiries
    $q_inq = "SELECT COUNT(*) as cnt FROM inquiries WHERE status = 'Pending'";
    $res_inq = mysqli_query($conn, $q_inq);
    if ($res_inq) {
        $row_inq = mysqli_fetch_assoc($res_inq);
        $topbar_pending_inquiries = (int)$row_inq['cnt'];
    }

    // Services Schedule
    $q_srv = "SELECT COUNT(*) as cnt FROM services_schedule WHERE status = 'Pending'";
    $res_srv = mysqli_query($conn, $q_srv);
    if ($res_srv) {
        $row_srv = mysqli_fetch_assoc($res_srv);
        $topbar_pending_services = (int)$row_srv['cnt'];
    }
}

// 3. CALCULATE TOTAL
$total_notifications = $topbar_pending_inquiries + $topbar_pending_services;
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="includes/css/topbar.css">

<nav id="gsb-custom-topbar">
    <div class="topbar-left">
        <button id="toggleSidebarBtn">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>

    <div class="topbar-right">
        
        <!-- Notification Dropdown -->
        <div class="notification-dropdown profile-dropdown">
            <button class="profile-btn" id="notificationDropdownBtn" style="background: none; border: none; cursor: pointer; position: relative;">
                <i class="fa-regular fa-bell" style="font-size: 1.2rem;"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="bell-badge" style="position: absolute; top: -5px; right: -5px; background: #dc3545; color: white; border-radius: 50%; padding: 2px 6px; font-size: 0.75rem; font-weight: bold;">
                        <?php echo $total_notifications; ?>
                    </span>
                <?php endif; ?>
            </button>
            
            <div class="dropdown-menu" id="notificationMenu" style="width: 260px; right: 0; left: auto;">
                <div style="padding: 10px 15px; font-weight: bold; border-bottom: 1px solid #eee; background-color: #f8f9fa;">
                    Notifications (<?php echo $total_notifications; ?>)
                </div>
                
                <?php if ($topbar_pending_services > 0): ?>
                <a href="services_schedule.php" style="display: block; padding: 12px 15px; text-decoration: none; color: #333; border-bottom: 1px solid #f1f1f1; transition: background 0.2s;">
                    <i class="fa-regular fa-calendar-check" style="color: #ffc107; width: 20px;"></i> 
                    <strong><?php echo $topbar_pending_services; ?></strong> pending service(s)
                </a>
                <?php endif; ?>

                <?php if ($topbar_pending_inquiries > 0): ?>
                <a href="admin_inquiries.php" style="display: block; padding: 12px 15px; text-decoration: none; color: #333; border-bottom: 1px solid #f1f1f1; transition: background 0.2s;">
                    <i class="fa-solid fa-message" style="color: #0d6efd; width: 20px;"></i> 
                    <strong><?php echo $topbar_pending_inquiries; ?></strong> pending inquiry(s)
                </a>
                <?php endif; ?>

                <?php if ($total_notifications === 0): ?>
                <div style="padding: 15px; text-align: center; color: #777; font-size: 0.9rem;">
                    No new pending items
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Dropdown -->
        <div class="profile-dropdown">
            <button class="profile-btn" id="profileDropdownBtn">
                <div class="avatar-icon"><i class="fa-solid fa-user"></i></div>
                <div class="profile-info">
                    <span class="profile-name">Admin</span>
                </div>
                <i class="fa-solid fa-chevron-down dropdown-icon"></i>
            </button>
            
            <div class="dropdown-menu" id="profileMenu" style="width: 220px; padding: 0;">
                <!-- Admin Details Section -->
                <div class="admin-details" style="padding: 15px; border-bottom: 1px solid #eee; background-color: #f8f9fa; border-radius: 4px 4px 0 0;">
                    <div style="font-weight: bold; color: #333; font-size: 0.95rem;">
                        <?php echo isset($_SESSION['admin_name']) ? htmlspecialchars($_SESSION['admin_name']) : 'Administrator'; ?>
                    </div>
                    <div style="font-size: 0.8rem; color: #6c757d; margin-top: 2px;">
                        <?php echo isset($_SESSION['admin_email']) ? htmlspecialchars($_SESSION['admin_email']) : 'admin@system.com'; ?>
                    </div>
                </div>

                <!-- Action Links -->
                <div style="padding: 5px 0;">
                    <a href="admin_settings.php" style="display: block; padding: 10px 15px; text-decoration: none; color: #333;"><i class="fa-solid fa-gear" style="width: 20px;"></i> Account Settings</a>
                </div>
                
                <div style="border-top: 1px solid #eee;"></div>
                
                <!-- Logout -->
                <div style="padding: 5px 0;">
                    <a href="actions/logout.php" class="logout" style="display: block; padding: 10px 15px; text-decoration: none; color: #dc3545;"><i class="fa-solid fa-right-from-bracket" style="width: 20px;"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        // ── Sidebar toggle ──────────────────────────────────────────────────
        const toggleBtn   = document.getElementById('toggleSidebarBtn');
        const sidebar     = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');

        if (toggleBtn && sidebar) {
            toggleBtn.addEventListener('click', function () {
                sidebar.classList.toggle('collapsed');
                if (mainContent) mainContent.classList.toggle('sidebar-collapsed');
            });
        }

        // ── Profile dropdown ────────────────────────────────────────────────
        const profileBtn  = document.getElementById('profileDropdownBtn');
        const profileMenu = document.getElementById('profileMenu');
        
        const notificationBtn  = document.getElementById('notificationDropdownBtn');
        const notificationMenu = document.getElementById('notificationMenu');

        if (profileBtn) {
            profileBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                profileMenu.classList.toggle('show');
                if(notificationMenu) notificationMenu.classList.remove('show'); 
            });
        }

        if (notificationBtn) {
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationMenu.classList.toggle('show');
                if(profileMenu) profileMenu.classList.remove('show'); 
            });
        }

        document.addEventListener('click', function(e) {
            if (profileMenu && profileBtn && !profileMenu.contains(e.target) && !profileBtn.contains(e.target)) {
                profileMenu.classList.remove('show');
            }
            if (notificationMenu && notificationBtn && !notificationMenu.contains(e.target) && !notificationBtn.contains(e.target)) {
                notificationMenu.classList.remove('show');
            }
        });
    });
</script>