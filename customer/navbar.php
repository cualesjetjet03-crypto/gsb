<?php

// We only check the session, we don't start it here because
// session_start() must be at the very top of your main pages.
$is_logged_in = isset($_SESSION['user_id']);

// Get the current file name (e.g., 'products.php' or 'index.php')
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch user profile picture and unread notifications for the navbar
$nav_profile_pic = 'images/users.png'; // Default fallback
$unread_count = 0; // Default notification count

if ($is_logged_in) {
    global $conn; // Access the database connection from the main page
    if (isset($conn)) {
        // 1. Fetch Profile Picture
        $nav_stmt = $conn->prepare("SELECT profile_picture FROM users WHERE id = ? LIMIT 1");
        $nav_stmt->bind_param("i", $_SESSION['user_id']);
        $nav_stmt->execute();
        $nav_res = $nav_stmt->get_result();
        if ($nav_row = $nav_res->fetch_assoc()) {
            if (!empty($nav_row['profile_picture'])) {
                $nav_profile_pic = $nav_row['profile_picture'];
            }
        }
        $nav_stmt->close();

        // 2. Fetch Unread Notifications Count (Assuming you have a 'notifications' table)
        $notif_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        if ($notif_stmt) {
            $notif_stmt->bind_param("i", $_SESSION['user_id']);
            $notif_stmt->execute();
            $notif_stmt->bind_result($unread_count);
            $notif_stmt->fetch();
            $notif_stmt->close();
        }
    }
}

// Helper function to build the navigation links with the wrench icon if active
if (!function_exists('nav_link')) {
    function nav_link($url, $label, $current_page) {
        $is_active = ($current_page == $url) ? 'active' : '';
        $wrench_svg = '';
        
        // If this is the active page, insert the mini wrench SVG
        if ($is_active) {
            $wrench_svg = '<svg class="nav-wrench" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>';
        }
        
        return '<a href="' . $url . '" class="' . $is_active . '">' . $wrench_svg . $label . '</a>';
    }
}

?>

<nav class="navbar">
    
    <!-- LOGO IMAGE + TEXT SIDE-BY-SIDE -->
    <div class="logo">
        <a href="index.php" class="brand-wrapper">
            <img src="./GSB homepage Image/GSB Logo.png" alt="GSB Logo" class="brand-img">
            <div class="brand-text">
                <span class="brand-top">GSB AIRCONDITIONING</span>
                <div class="brand-bottom">
                    <span class="line"></span>
                    <span class="text">SERVICES</span>
                    <span class="line"></span>
                </div>
            </div>
        </a>
    </div>

    <!-- GLOBALLY PLACED SEARCH BAR -->
    <div class="nav-search-container">
        <!-- Sends the search text to our smart router -->
        <form action="search_router.php" method="GET" class="nav-search-form">
            
            <!-- Red SVG Magnifying Glass Icon -->
            <svg class="nav-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#c90000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" onclick="this.closest('form').submit();" style="cursor: pointer;">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            
            <input type="text" name="search" id="navSearchInput" placeholder="Search products or services..." class="nav-search-input" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            
            <!-- NEW: Quick Delete 'X' Icon -->
            <svg class="nav-search-clear" id="navSearchClear" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#f87171" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" onclick="clearNavSearch()" style="display: <?php echo isset($_GET['search']) && $_GET['search'] !== '' ? 'block' : 'none'; ?>;">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </form>
    </div>

    <!-- Script for the Quick Delete functionality -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.getElementById('navSearchInput');
            const clearBtn = document.getElementById('navSearchClear');

            // Show the 'X' only when there is text inside the input
            if (searchInput && clearBtn) {
                searchInput.addEventListener('input', function() {
                    clearBtn.style.display = this.value.length > 0 ? 'block' : 'none';
                });
            }
        });

        // Function triggered when the 'X' is clicked
        function clearNavSearch() {
            const searchInput = document.getElementById('navSearchInput');
            if (searchInput) {
                searchInput.value = ''; // Empty the text box
                document.getElementById('navSearchClear').style.display = 'none'; // Hide the 'X'
                
                // If there's an active search in the URL, reload the page cleanly without it
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('search')) {
                    window.location.href = window.location.pathname;
                } else {
                    searchInput.focus(); // Otherwise, just put the blinking cursor back in the box
                }
            }
        }
    </script>

    <!-- MAIN MOBILE HAMBURGER ICON (Hidden on Desktop) -->
    <div class="mobile-menu-toggle" id="mobileToggle" onclick="toggleMobileNav()">
        <span></span>
        <span></span>
        <span></span>
    </div>

    <!-- WRAPPER FOR RIGHT SIDE LINKS -->
    <div class="nav-right" id="navLinks">
        
        <!-- NAVIGATION LINKS -->
        <div class="nav-links">
            
            <!-- Generate the links with the active state checking -->
            <?php echo nav_link('index.php', 'Home', $current_page); ?>
            <?php echo nav_link('about.php', 'About Us', $current_page); ?>
            <?php echo nav_link('services.php', 'Services', $current_page); ?>
            <?php echo nav_link('products.php', 'Products', $current_page); ?>

            <!-- USER PROFILE & AUTH BUTTONS -->
            <div class="menu-container">
                <?php if($is_logged_in): ?>
                    
                    <!-- Added a wrapper to align the profile pill and the bell horizontally -->
                    <div class="profile-nav-wrapper">
                        
                        <!-- PROFILE HAMBURGER & AVATAR BUTTON -->
                        <div class="profile-menu-btn" id="profilePic" onclick="toggleProfileMenu()">
                            
                            <!-- DYNAMIC PROFILE PICTURE HERE -->
                            <img src="<?php echo htmlspecialchars($nav_profile_pic); ?>" class="profile-avatar" alt="Profile" style="border-radius: 50%; object-fit: cover; width: 35px; height: 35px;">

                            <!-- Profile Hamburger SVG (Right) -->
                            <svg class="profile-hamburger" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="4" y1="6" x2="20" y2="6" class="line-top"></line>
                                <line x1="4" y1="12" x2="20" y2="12" class="line-mid"></line>
                                <line x1="4" y1="18" x2="20" y2="18" class="line-bot"></line>
                            </svg>
                        </div>

                        <!-- USER DROPDOWN MENU -->
                        <div class="dropdown-menu" id="dropdownMenu">
                            <a href="profile.php">Profile Info</a>
                            <a href="trackers.php?tab=orders">My Orders</a>
                            <a href="trackers.php?tab=services">My Services</a>
                            <a href="order_history.php">History</a>
                            <a href="logout.php">Logout</a>
                        </div>
                        
                        <!-- PROFESSIONAL NOTIFICATION BELL -->
                        <a href="notifications.php" class="notification-wrapper" style="position: relative; display: inline-flex; align-items: center; text-decoration: none; cursor: pointer;">
                            
                            <!-- Clean Minimalist Bell SVG -->
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 24px; height: 24px; transition: opacity 0.2s;">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            
                            <!-- Red Notification Badge (Only shows if unread count > 0) -->
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge" style="position: absolute; top: -4px; right: -4px; background-color: #ef4444; color: white; font-size: 10px; font-weight: bold; padding: 2px 5px; border-radius: 12px; border: 2px solid #001f7a; line-height: 1;">
                                    <?php echo $unread_count > 99 ? '99+' : $unread_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        
                    </div>
                    
                <?php else: ?>
                    <!-- SHOW LOGIN AND REGISTER BUTTONS TO GUESTS -->
                    <a href="login.php" class="auth-nav-btn login-nav">Login</a>
                    <a href="register.php" class="auth-nav-btn register-nav">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<script>
    // 1. Toggles the entire navigation menu on mobile devices
    function toggleMobileNav() {
        document.getElementById("navLinks").classList.toggle("active");
        document.getElementById("mobileToggle").classList.toggle("open");
    }

    // 2. Toggles the specific user profile dropdown AND the X animation
    function toggleProfileMenu() {
        document.getElementById("dropdownMenu").classList.toggle("show");
        document.getElementById("profilePic").classList.toggle("open"); // Toggles the X
    }

    // 3. Closes the profile dropdown & resets the X if the user clicks anywhere outside
    document.addEventListener("click", function(event) {
        let dropMenu = document.getElementById("dropdownMenu");
        let profilePic = document.getElementById("profilePic");
        
        if (dropMenu && profilePic && !profilePic.contains(event.target) && !dropMenu.contains(event.target)) {
            dropMenu.classList.remove("show");
            profilePic.classList.remove("open"); // Resets the X back to 3 lines
        }
    });
</script>