<?php
include 'db_connect.php';
session_start();

function clean($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// 1. Get user email and fullname for tracking queries
$user_email = '';
$user_fullname = '';
$user_stmt = $conn->prepare("SELECT email, fullname FROM users WHERE id = ? LIMIT 1");
if ($user_stmt) {
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $res = $user_stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $user_email = trim($row['email']);
        $user_fullname = trim($row['fullname']);
    }
    $user_stmt->close();
}

// 2. Get Inquiries (Orders)
$inquiries = [];
$inquiry_stmt = $conn->prepare("
    SELECT * FROM inquiries 
    WHERE (user_id = ? OR (email IS NOT NULL AND email <> '' AND email = ?))
    AND LOWER(status) NOT IN ('completed', 'cancelled', 'sold')
    ORDER BY date_submitted DESC, id DESC
");

if ($inquiry_stmt) {
    $inquiry_stmt->bind_param("is", $user_id, $user_email);
    $inquiry_stmt->execute();
    $inquiry_result = $inquiry_stmt->get_result();
    if ($inquiry_result) {
        while ($inquiry = $inquiry_result->fetch_assoc()) {
            $inquiries[] = $inquiry;
        }
    }
    $inquiry_stmt->close();
}

$inquiry_trackers = [];
foreach ($inquiries as $inquiry) {
    $schedule = null;
    
    // Fetch tech id, image, and ratings
    $schedule_stmt = $conn->prepare("
        SELECT ss.*, 
               t.id AS tech_id, t.technician_name, t.contact_number AS technician_contact, t.image AS tech_image,
               (SELECT AVG(tech_rating) FROM technician_ratings WHERE technician_id = t.id) AS avg_rating,
               (SELECT AVG(tech_percentage) FROM technician_ratings WHERE technician_id = t.id) AS avg_pct,
               (SELECT COUNT(*) FROM technician_ratings WHERE technician_id = t.id) AS review_count
        FROM services_schedule ss
        LEFT JOIN inquiries i ON ss.inquiry_id = i.id
        LEFT JOIN technicians t ON t.id = COALESCE(ss.technician_id, i.technician_id, i.schedule_technician_id)
        WHERE ss.inquiry_id = ? ORDER BY ss.id DESC LIMIT 1
    ");
    
    if ($schedule_stmt) {
        $inquiry_id = (int) $inquiry['id'];
        $schedule_stmt->bind_param("i", $inquiry_id);
        $schedule_stmt->execute();
        $schedule_result = $schedule_stmt->get_result();
        if ($schedule_result && $schedule_result->num_rows > 0) {
            $schedule = $schedule_result->fetch_assoc();
        }
        $schedule_stmt->close();
    }

    if (!$schedule && (!empty($inquiry['schedule_date']) || !empty($inquiry['schedule_technician_id']))) {
        $tech_info = null;
        if (!empty($inquiry['schedule_technician_id'])) {
            $t_stmt = $conn->prepare("
                SELECT id AS tech_id, technician_name, contact_number AS technician_contact, image AS tech_image,
                       (SELECT AVG(tech_rating) FROM technician_ratings WHERE technician_id = technicians.id) AS avg_rating,
                       (SELECT AVG(tech_percentage) FROM technician_ratings WHERE technician_id = technicians.id) AS avg_pct,
                       (SELECT COUNT(*) FROM technician_ratings WHERE technician_id = technicians.id) AS review_count
                FROM technicians WHERE id = ?
            ");
            if ($t_stmt) {
                $t_id = (int)$inquiry['schedule_technician_id'];
                $t_stmt->bind_param("i", $t_id);
                $t_stmt->execute();
                $t_res = $t_stmt->get_result();
                if ($t_res && $t_res->num_rows > 0) {
                    $tech_info = $t_res->fetch_assoc();
                }
                $t_stmt->close();
            }
        }
        $schedule = [
            'service_type' => 'Inquiry / Product Order Service',
            'service_date' => $inquiry['schedule_date'] ?? null,
            'service_time' => $inquiry['schedule_time'] ?? null,
            'location'     => $inquiry['schedule_location'] ?? $inquiry['address'] ?? null,
            'technician_name' => $tech_info['technician_name'] ?? null,
            'technician_contact' => $tech_info['technician_contact'] ?? null,
            'tech_id'      => $tech_info['tech_id'] ?? null,
            'tech_image'   => $tech_info['tech_image'] ?? null,
            'avg_rating'   => $tech_info['avg_rating'] ?? 5.0,
            'avg_pct'      => $tech_info['avg_pct'] ?? 100.0,
            'status'       => $inquiry['status'] ?? 'Pending'
        ];
    }
    $inquiry_trackers[] = ['inquiry' => $inquiry, 'schedule' => $schedule];
}
$total_inquiries = count($inquiry_trackers);

// 3. Get Direct Services
$direct_services = [];
$service_stmt =$conn->prepare("
    SELECT ss.*, 
           t.id AS tech_id, t.technician_name, t.contact_number AS technician_contact, t.image AS tech_image,
           (SELECT AVG(tech_rating) FROM technician_ratings WHERE technician_id = t.id) AS avg_rating,
           (SELECT AVG(tech_percentage) FROM technician_ratings WHERE technician_id = t.id) AS avg_pct,
           (SELECT COUNT(*) FROM technician_ratings WHERE technician_id = t.id) AS review_count
    FROM services_schedule ss
    LEFT JOIN technicians t ON ss.technician_id = t.id
    WHERE (ss.inquiry_id IS NULL OR ss.inquiry_id = 0)
      AND (ss.user_id = ? OR (ss.customer_name IS NOT NULL AND ss.customer_name <> '' AND ss.customer_name = ?))
      AND LOWER(ss.status) NOT IN ('completed', 'cancelled', 'sold')
    ORDER BY ss.id DESC
");

if ($service_stmt) {
    $service_stmt->bind_param("is", $user_id, $user_fullname);$service_stmt->execute();
    $service_result =$service_stmt->get_result();
    if ($service_result) {
        while ($row =$service_result->fetch_assoc()) {
            $direct_services[] =$row;
        }
    }
    $service_stmt->close();
}
$total_direct_services = count($direct_services);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Trackers - GSB Airconditioning Services</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/GSB-homepage.css">
    <link rel="stylesheet" href="css/profile.css">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/trackers.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="profile-container">
    <div class="back-btn-wrapper">
        <a href="profile.php" class="back-btn">← Back to Profile</a>
    </div>
    
    <div class="order-tracker">
        <!-- Switch Buttons -->
        <div class="tracker-switch-wrapper">
            <button class="switch-btn active" id="btn-orders" onclick="toggleView('orders')">My Orders</button>
            <button class="switch-btn" id="btn-services" onclick="toggleView('services')">My Services</button>
        </div>

        <!-- ==================== ORDERS SECTION ==================== -->
        <div id="view-orders" class="view-section active-view">
            <div class="tracker-section-title">
                <h2>My Orders</h2>
                <p>Track each of your product inquiries.</p>
            </div>

            <?php if ($total_inquiries > 0): ?>
                <div class="service-trackers-wrapper">
                    <?php foreach ($inquiry_trackers as$tracker): ?>
                        <?php
                        $inquiry =$tracker['inquiry'];
                        $schedule =$tracker['schedule'];
                        $status = strtolower(trim($inquiry['status'] ?? 'Pending'));
                        
                        // Check if customer requested free installation
                        $has_installation = (isset($inquiry['free_installation']) && $inquiry['free_installation'] === 'Yes');$step = 1;
                        if ($status === 'approved')$step = 2;
                        // Map both "ongoing" and "out for delivery" to step 3 for orders
                        elseif (in_array($status, ['ongoing', 'out for delivery']))$step = 3;
                        
                        // Dynamically adjust the final steps based on installation request
                        if ($has_installation) {
                            if ($status === 'installation')$step = 4;
                            elseif (in_array($status, ['completed', 'sold']))$step = 5;
                        } else {
                            if (in_array($status, ['completed', 'sold']))$step = 4;
                        }
                        
                        $status_class = str_replace(' ', '-',$status);
                        ?>
                        <div class="service-tracker-card">
                            <div class="tracker-card-header">
                                <div>
                                    <h3>Order Tracking <span class="inquiry-number">#<?php echo clean($inquiry['id']); ?></span></h3>
                                    <p><strong>Product:</strong> <?php echo clean($inquiry['product_name'] ?? 'Service Request'); ?></p>
                                    <p><strong>Submitted:</strong> <?php echo (!empty($inquiry['date_submitted'])) ? date("F d, Y h:i A", strtotime($inquiry['date_submitted'])) : "No date"; ?></p>
                                </div>
                                <div class="tracker-current-status <?php echo clean($status_class); ?>">
                                    <?php echo clean($inquiry['status'] ?? 'Pending'); ?>
                                </div>
                            </div>

                            <div class="tracker-progress">
                                <div class="tracker-step <?php echo $step >= 1 ? 'active' : ''; ?>"><div class="circle">1</div><span>Pending</span></div>
                                <div class="line <?php echo $step >= 2 ? 'active' : ''; ?>"></div>
                                <div class="tracker-step <?php echo $step >= 2 ? 'active' : ''; ?>"><div class="circle">2</div><span>Approved</span></div>
                                <div class="line <?php echo $step >= 3 ? 'active' : ''; ?>"></div>
                                <div class="tracker-step <?php echo $step >= 3 ? 'active' : ''; ?>"><div class="circle">3</div><span>Out for Delivery</span></div>
                                
                                <?php if ($has_installation): ?>
                                    <div class="line <?php echo $step >= 4 ? 'active' : ''; ?>"></div>
                                    <div class="tracker-step <?php echo $step >= 4 ? 'active' : ''; ?>"><div class="circle">4</div><span>Installation</span></div>
                                    
                                    <div class="line <?php echo $step >= 5 ? 'active' : ''; ?>"></div>
                                    <div class="tracker-step <?php echo $step >= 5 ? 'active' : ''; ?>"><div class="circle">5</div><span>Completed</span></div>
                                <?php else: ?>
                                    <div class="line <?php echo $step >= 4 ? 'active' : ''; ?>"></div>
                                    <div class="tracker-step <?php echo $step >= 4 ? 'active' : ''; ?>"><div class="circle">4</div><span>Completed</span></div>
                                <?php endif; ?>
                            </div>

                            <div class="tracker-message">
                                <?php
                                if ($step === 1) echo "Your order request has been received and is waiting for approval.";
                                elseif ($step === 2) echo "Your order has been approved and is now being prepared.";
                                elseif ($step === 3) echo "Your product is currently out for delivery.";
                                elseif ($step === 4 &&$has_installation) echo "Your product has been delivered and is currently undergoing installation.";
                                elseif (($step === 4 && !$has_installation) ||$step === 5) echo "Your order has been completed successfully.";
                                ?>
                            </div>

                            <?php if ($schedule): ?>
                                <div class="tracker-service-info">
                                    <h4>Service Details</h4>
                                    <table class="service-details-table">
                                        <tr>
                                            <td><strong>Service Type</strong><span><?php echo clean($schedule['service_type'] ?? 'Not provided'); ?></span></td>
                                            <td><strong>Scheduled Date</strong><span><?php echo (!empty($schedule['service_date'])) ? date("F d, Y", strtotime($schedule['service_date'])) : "Not scheduled"; ?></span></td>
                                            <td><strong>Scheduled Time</strong><span><?php echo (!empty($schedule['service_time'])) ? date("h:i A", strtotime($schedule['service_time'])) : "Not scheduled"; ?></span></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Location</strong><span><?php echo clean($schedule['location'] ?? 'Not provided'); ?></span></td>
                                            
                                            <?php 
                                            $techName =$schedule['technician_name'] ?? '';
                                            if (!empty($techName)):$t_id_str = sprintf('%03d', $schedule['tech_id'] ?? 0);
                                            $t_reviews = (int)($schedule['review_count'] ?? 0);
                                            $t_pct = $t_reviews > 0 ? round($schedule['avg_pct'] ?? 0) : 0;
                                            $t_rating = $t_reviews > 0 ? round($schedule['avg_rating'] ?? 0, 1) : 0;
                                            $t_img = !empty($schedule['tech_image']) ? '../images/technicians/' .$schedule['tech_image'] : ''; 
                                            ?>
                            <td class="clickable-tech" onclick="openProfileModal('<?php echo addslashes($techName); ?>', '<?php echo $t_id_str; ?>', '<?php echo$t_pct; ?>', '<?php echo $t_rating; ?>', '<?php echo addslashes($t_img); ?>', <?php echo $t_reviews; ?>)">
                                                <strong>Technician</strong>
                                                <span class="tech-link"><?php echo clean($techName); ?></span>
                                            </td>
                                            <?php else: ?>
                                            <td><strong>Technician</strong><span>Not yet assigned</span></td>
                                            <?php endif; ?>
                                            
                                            <td><strong>Technician Contact</strong><span><?php echo clean($schedule['technician_contact'] ?? '—'); ?></span></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Schedule Status</strong><span><?php echo clean($schedule['status'] ?? 'Pending'); ?></span></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="tracker-no-schedule">
                                    <strong>Service schedule:</strong> Not scheduled yet. Your appointment details will appear here once the admin schedules your service.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="service-tracker-card tracker-empty">
                    <h3>No product orders yet.</h3>
                    <p>Once you submit a product inquiry, its tracker will appear here.</p>
                    <a href="products.php" class="btn">Browse Products</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- ==================== SERVICES SECTION ==================== -->
        <div id="view-services" class="view-section">
            <div class="tracker-section-title">
                <h2>My Services</h2>
                <p>Track your direct installation, repair, or maintenance appointments.</p>
            </div>

            <?php if ($total_direct_services > 0): ?>
                <div class="service-trackers-wrapper">
                    <?php foreach ($direct_services as$service): ?>
                        <?php
                        $status = trim($service['status'] ?? 'Pending');$status_lower = strtolower($status);$step = 1;
                        if ($status_lower === 'approved')$step = 2;
                        elseif ($status_lower === 'ongoing')$step = 3;
                        elseif ($status_lower === 'completed')$step = 4;
                        
                        $status_class = str_replace(' ', '-',$status_lower);
                        ?>
                        <div class="service-tracker-card">
                            <div class="tracker-card-header">
                                <div>
                                    <h3>Service Tracking <span class="inquiry-number">#<?php echo clean($service['id']); ?></span></h3>
                                    <p><strong>Service Type:</strong> <?php echo clean($service['service_type'] ?? 'N/A'); ?></p>
                                    <p><strong>Scheduled Date:</strong> <?php echo (!empty($service['service_date'])) ? date("F d, Y", strtotime($service['service_date'])) : "Pending Schedule"; ?></p>
                                </div>
                                <div class="tracker-current-status <?php echo clean($status_class); ?>">
                                    <?php echo clean($status); ?>
                                </div>
                            </div>

                            <div class="tracker-progress">
                                <div class="tracker-step <?php echo $step >= 1 ? 'active' : ''; ?>"><div class="circle">1</div><span>Pending</span></div>
                                <div class="line <?php echo $step >= 2 ? 'active' : ''; ?>"></div>
                                <div class="tracker-step <?php echo $step >= 2 ? 'active' : ''; ?>"><div class="circle">2</div><span>Approved</span></div>
                                <div class="line <?php echo $step >= 3 ? 'active' : ''; ?>"></div>
                                <div class="tracker-step <?php echo $step >= 3 ? 'active' : ''; ?>"><div class="circle">3</div><span>Ongoing</span></div>
                                <div class="line <?php echo $step >= 4 ? 'active' : ''; ?>"></div>
                                <div class="tracker-step <?php echo $step >= 4 ? 'active' : ''; ?>"><div class="circle">4</div><span>Completed</span></div>
                            </div>

                            <div class="tracker-message">
                                <?php
                                if ($step === 1) echo "Your direct service request has been received and is waiting for approval.";
                                elseif ($step === 2) echo "Your service schedule has been approved and a technician has been assigned.";
                                elseif ($step === 3) echo "Your requested service is currently ongoing.";
                                elseif ($step === 4) echo "This service appointment has been successfully completed.";
                                ?>
                            </div>

                            <div class="tracker-service-info">
                                <h4>Service Details</h4>
                                <table class="service-details-table">
                                    <tr>
                                        <td><strong>Service Type</strong><span><?php echo clean($service['service_type'] ?? 'Not provided'); ?></span></td>
                                        <td><strong>Scheduled Date</strong><span><?php echo (!empty($service['service_date'])) ? date("F d, Y", strtotime($service['service_date'])) : "Not scheduled"; ?></span></td>
                                        <td><strong>Scheduled Time</strong><span><?php echo (!empty($service['service_time'])) ? date("h:i A", strtotime($service['service_time'])) : "Not scheduled"; ?></span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Location</strong><span><?php echo clean($service['location'] ?? 'Not provided'); ?></span></td>
                                        
                                        <?php 
                                        $techName =$service['technician_name'] ?? '';
                                        if (!empty($techName)):$t_id_str = sprintf('%03d', $service['tech_id'] ?? 0);
                                        $t_reviews = (int)($service['review_count'] ?? 0);
                                        $t_pct = $t_reviews > 0 ? round($service['avg_pct'] ?? 0) : 0;
                                        $t_rating = $t_reviews > 0 ? round($service['avg_rating'] ?? 0, 1) : 0;
                                        $t_img = !empty($service['tech_image']) ? '../images/technicians/' .$service['tech_image'] : ''; 
                                        ?>
                                        <td class="clickable-tech" onclick="openProfileModal('<?php echo addslashes($techName); ?>', '<?php echo $t_id_str; ?>', '<?php echo$t_pct; ?>', '<?php echo $t_rating; ?>', '<?php echo addslashes($t_img); ?>', <?php echo $t_reviews; ?>)">
                                            <strong>Technician</strong>
                                            <span class="tech-link"><?php echo clean($techName); ?></span>
                                        </td>
                                        <?php else: ?>
                                        <td><strong>Technician</strong><span>Not yet assigned</span></td>
                                        <?php endif; ?>
                                        
                                        <td><strong>Technician Contact</strong><span><?php echo clean($service['technician_contact'] ?? '—'); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Schedule Status</strong><span><?php echo clean($service['status'] ?? 'Pending'); ?></span></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="service-tracker-card tracker-empty">
                    <h3>No direct services scheduled.</h3>
                    <p>Once you book an installation, cleaning, or repair service, its tracker will appear here.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ==========================================
     TECHNICIAN PROFILE MODAL (NO CONTACT)
=========================================== -->
<div class="modal-overlay" id="profileModal" onclick="this.style.display='none'">
    <div class="modal-box" onclick="event.stopPropagation()">
        <i class="fa-solid fa-xmark modal-close" onclick="document.getElementById('profileModal').style.display='none'"></i>
        <div class="modal-header-split">
            <div class="modal-img" id="pAvatarContainer">
                <i class="fa-solid fa-user-tie"></i>
            </div>
            <div class="modal-info">
                <div>Name: <span id="pName">--</span></div>
            </div>
        </div>
        <div class="modal-pct" id="pPct">100%</div>
        <div class="modal-stars" id="pStars"></div>
    </div>
</div>

<?php include 'footer.php'; ?>

<!-- JavaScript for Switch Toggle & Modal -->
<script>
function toggleView(viewName) {
    document.getElementById('view-orders').classList.remove('active-view');
    document.getElementById('view-services').classList.remove('active-view');
    
    document.getElementById('btn-orders').classList.remove('active');
    document.getElementById('btn-services').classList.remove('active');
    
    if (viewName === 'orders') {
        document.getElementById('view-orders').classList.add('active-view');
        document.getElementById('btn-orders').classList.add('active');
    } else {
        document.getElementById('view-services').classList.add('active-view');
        document.getElementById('btn-services').classList.add('active');
    }
}

// Automatically switch tabs based on the URL parameter
document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab');
    
    // If the URL contains ?tab=services, switch to it. Otherwise, default to orders.
    if (tab === 'services') {
        toggleView('services');
    }
});

function openProfileModal(name, idNo, pct, rating, imagePath, reviews) {
    document.getElementById('pName').textContent = name;

    const avatarContainer = document.getElementById('pAvatarContainer');
    if (imagePath && imagePath.trim() !== '') {
        avatarContainer.innerHTML = `<img src="${imagePath}" alt="${name}">`;
    } else {
        avatarContainer.innerHTML = `<i class="fa-solid fa-user-tie"></i>`;
    }

    const hasRatings = reviews > 0;

    if (!hasRatings) {
        document.getElementById('pPct').textContent = '';
        document.getElementById('pStars').innerHTML =
            '<span style="color:#94a3b8; font-size:14px; font-style:italic;">No ratings yet</span>';
    } else {
        document.getElementById('pPct').textContent = pct + '%';
        let starHtml = '';
        let fullStars = Math.floor(rating);
        let hasHalf = (rating - fullStars) >= 0.5;
        for(let i=1; i<=5; i++) {
            if(i <= fullStars) starHtml += '<i class="fa-solid fa-star"></i>';
            else if(i === fullStars + 1 && hasHalf) starHtml += '<i class="fa-solid fa-star-half-stroke"></i>';
            else starHtml += '<i class="fa-regular fa-star"></i>';
        }
        document.getElementById('pStars').innerHTML = starHtml;
    }

    document.getElementById('profileModal').style.display = 'flex';
}
</script>

</body>
</html>