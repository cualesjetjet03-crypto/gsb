<?php
session_start();
if(!isset($_SESSION['admin'])){
    header("Location: admin_login.php");
    exit();
}
include "includes/db_connect.php";
require_once __DIR__ . '/../includes/auth_helpers.php';

/**
 * Validates and moves a technician image upload.
 * Returns the stored filename on success, or dies with a safe error message.
 */
function upload_tech_image(array $file, string $dest_dir): string
{
    $allowed_exts  = ['jpg', 'jpeg', 'png', 'webp'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
    $max_bytes     = 3 * 1024 * 1024; // 3 MB

    if ($file['size'] > $max_bytes) {
        die("Error: Technician image exceeds the 3 MB size limit.");
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        die("Error: Only JPG, JPEG, PNG, or WEBP images are allowed for technician photos.");
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) {
        die("Error: Technician image failed MIME validation.");
    }
    if (@getimagesize($file['tmp_name']) === false) {
        die("Error: The uploaded technician image is not a valid image file.");
    }
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest_dir . $filename)) {
        die("Error: Could not save technician image. Check folder permissions.");
    }
    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    // Define upload directory for technicians
    $upload_dir = "../images/technicians/";
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    if ($action === 'add') {
        $name  = trim($_POST['tech_name']);
        $phone = trim($_POST['tech_phone']);
        $image_filename = null;

        if (isset($_FILES['tech_image']) && $_FILES['tech_image']['error'] === UPLOAD_ERR_OK) {
            $image_filename = upload_tech_image($_FILES['tech_image'], $upload_dir);
        }
        
        $stmt = $conn->prepare("INSERT INTO technicians (technician_name, contact_number, image) VALUES (?, ?, ?)");
        if($stmt){
            $stmt->bind_param("sss", $name, $phone, $image_filename);
            $stmt->execute();
            $stmt->close();
        }
        
    } elseif ($action === 'edit') {
        $id    = (int)$_POST['tech_id'];
        $name  = trim($_POST['tech_name']);
        $phone = trim($_POST['tech_phone']);
        
        if (isset($_FILES['tech_image']) && $_FILES['tech_image']['error'] === UPLOAD_ERR_OK) {
            $image_filename = upload_tech_image($_FILES['tech_image'], $upload_dir);
            
            $stmt = $conn->prepare("UPDATE technicians SET technician_name = ?, contact_number = ?, image = ? WHERE id = ?");
            if($stmt){
                $stmt->bind_param("sssi", $name, $phone, $image_filename, $id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("UPDATE technicians SET technician_name = ?, contact_number = ? WHERE id = ?");
            if($stmt){
                $stmt->bind_param("ssi", $name, $phone, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
    } elseif ($action === 'delete') {
        $id = (int)$_POST['tech_id'];
        
        $img_check = $conn->prepare("SELECT image FROM technicians WHERE id = ?");
        $img_check->bind_param("i", $id);
        $img_check->execute();
        $res = $img_check->get_result();
        if($row = $res->fetch_assoc()) {
            if(!empty($row['image']) && file_exists($upload_dir . $row['image'])) {
                unlink($upload_dir . $row['image']); 
            }
        }
        $img_check->close();

        $stmt = $conn->prepare("DELETE FROM technicians WHERE id = ?");
        if($stmt){
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
        
        $stmt2 = $conn->prepare("DELETE FROM technician_ratings WHERE technician_id = ?");
        if($stmt2){
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $stmt2->close();
        }
    } 
    /* --- Review Moderation Actions --- */
    elseif ($action === 'approve_review') {
        $rev_id = (int)$_POST['review_id'];
        $stmt = $conn->prepare("UPDATE technician_ratings SET is_approved = 1 WHERE id = ?");
        $stmt->bind_param("i", $rev_id);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'hide_review') {
        $rev_id = (int)$_POST['review_id'];
        $stmt = $conn->prepare("UPDATE technician_ratings SET is_approved = 0 WHERE id = ?");
        $stmt->bind_param("i", $rev_id);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'delete_review') {
        $rev_id = (int)$_POST['review_id'];
        
        // Fetch files from ../images/ratings/ to delete them from the server
        $check = $conn->prepare("SELECT image_1, image_2, image_3, video_url FROM technician_ratings WHERE id = ?");
        $check->bind_param("i", $rev_id);
        $check->execute();
        $res = $check->get_result();
        if ($row = $res->fetch_assoc()) {
            if(!empty($row['image_1']) && file_exists($row['image_1'])) unlink($row['image_1']);
            if(!empty($row['image_2']) && file_exists($row['image_2'])) unlink($row['image_2']);
            if(!empty($row['image_3']) && file_exists($row['image_3'])) unlink($row['image_3']);
            if(!empty($row['video_url']) && file_exists($row['video_url'])) unlink($row['video_url']);
        }
        $check->close();
        
        $del = $conn->prepare("DELETE FROM technician_ratings WHERE id = ?");
        $del->bind_param("i", $rev_id);
        $del->execute();
        $del->close();
    }

    header("Location: admin_ratings.php");
    exit();
}

function e($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

// Topbar notifications
$result = $conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status='Pending'");
$pending_count = 0;
if ($result) {
    $row = $result->fetch_assoc();
    if ($row && isset($row['total'])) {
        $pending_count = (int)$row['total'];
    }
}

// Fetch Technicians
$techs = $conn->query("
    SELECT t.*, 
           AVG(r.tech_rating) as avg_rating, 
           AVG(r.tech_percentage) as avg_pct,
           COUNT(r.id) as review_count
    FROM technicians t
    LEFT JOIN technician_ratings r ON t.id = r.technician_id
    GROUP BY t.id
");
$total_technicians = $techs->num_rows;

// Fetch GSB Ratings for Chart & KPIs
$gsb_res = $conn->query("SELECT gsb_rating FROM technician_ratings");
$total_gsb = $gsb_res->num_rows;
$g_counts = [5=>0, 4=>0, 3=>0, 2=>0, 1=>0];
$gsb_sum = 0;
$positive_reviews = 0;

if($total_gsb > 0){
    while($row = $gsb_res->fetch_assoc()){
        $val = round($row['gsb_rating']);
        if($val < 1) $val = 1;
        $g_counts[$val]++;
        $gsb_sum += $row['gsb_rating'];
        if($val >= 4) $positive_reviews++;
    }
}

$gsb_avg = $total_gsb > 0 ? number_format($gsb_sum / $total_gsb, 1) : "0.0";
$satisfaction_rate = $total_gsb > 0 ? round(($positive_reviews / $total_gsb) * 100) : 0;

// Calculate Donut Chart Degrees
$deg5 = $total_gsb ? ($g_counts[5] / $total_gsb) * 360 : 0;
$deg4 = $total_gsb ? ($g_counts[4] / $total_gsb) * 360 : 0;
$deg3 = $total_gsb ? ($g_counts[3] / $total_gsb) * 360 : 0;

$end5 = $deg5;
$end4 = $end5 + $deg4;
$end3 = $end4 + $deg3;

if($total_gsb > 0) {
    $conic_gradient = "conic-gradient(#3b82f6 0deg {$end5}deg, #8b5cf6 {$end5}deg {$end4}deg, #f59e0b {$end4}deg {$end3}deg, #ef4444 {$end3}deg 360deg)";
} else {
    $conic_gradient = "conic-gradient(#e2e8f0 0deg 360deg)";
}

// Fetch 10 most recent reviews for admin moderation
$comments = $conn->query("SELECT id, customer_name, comment, created_at, tech_rating, image_1, image_2, image_3, video_url, is_approved FROM technician_ratings ORDER BY created_at DESC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ratings Dashboard</title>
    <link rel="stylesheet" href="includes/css/admin_dashboard.css">
    <link rel="stylesheet" href="includes/css/admin_ratings.css">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
    <!-- Sidebar -->
    <?php include "includes/admin_sidebar.php"; ?>

    <main class="main-content">
        
        <?php include 'includes/topbar.php'; ?>

        <div class="dashboard-container">
            
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fa-solid fa-star"></i></div>
                    <div class="kpi-details">
                        <div class="kpi-value"><?php echo $gsb_avg; ?></div>
                        <div class="kpi-title">Average Rating</div>
                        <div class="kpi-subtitle">Based on <?php echo $total_gsb; ?> reviews</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fa-solid fa-users"></i></div>
                    <div class="kpi-details">
                        <div class="kpi-value"><?php echo $total_technicians; ?></div>
                        <div class="kpi-title">Total Technicians</div>
                        <div class="kpi-subtitle">Active in the system</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fa-solid fa-comment"></i></div>
                    <div class="kpi-details">
                        <div class="kpi-value"><?php echo $total_gsb; ?></div>
                        <div class="kpi-title">Total Reviews</div>
                        <div class="kpi-subtitle">In the system</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fa-solid fa-thumbs-up"></i></div>
                    <div class="kpi-details">
                        <div class="kpi-value"><?php echo $satisfaction_rate; ?>%</div>
                        <div class="kpi-title">Customer Satisfaction</div>
                        <div class="kpi-subtitle">Positive feedback</div>
                    </div>
                </div>
            </div>

            <div class="main-split">
                <!-- Left Column: Technicians -->
                <div class="panel tech-panel">
                    <div class="panel-header">
                        <div class="header-left">
                            <i class="fa-solid fa-user-gear panel-icon"></i>
                            <div>
                                <h2>Technicians</h2>
                                <p>Manage your technicians and their ratings</p>
                            </div>
                        </div>
                        <button class="btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Technician</button>
                    </div>

                    <div class="tech-grid">
                        <?php 
                        if($techs &&$techs->num_rows > 0) {
                            while($t = $techs->fetch_assoc()){
                                $reviews = (int)$t['review_count'];
                                $rating  = $reviews > 0 ? number_format($t['avg_rating'], 1) : null;
                                $pct     = $reviews > 0 ? round($t['avg_pct'], 1) : 0;
                                $role = "Technician";
                                
                                $phone = isset($t['contact_number']) ? $t['contact_number'] : '';$maskedPhone = (strlen($phone) >= 11) ? substr($phone, 0, 2) . '****' . substr($phone, -2) :$phone;
                                $idNo = sprintf('%03d', $t['id']);$displayImage = "";
                                $dbImage = isset($t['image']) ? trim($t['image']) : "";
                                
                                if($dbImage !== "" && file_exists("../images/technicians/" . $dbImage)) {
                                    $displayImage = "../images/technicians/" . $dbImage;
                                }
                        ?>
                        <div class="tech-card" data-name='<?php echo e($t['technician_name']); ?>' data-phone='<?php echo e($maskedPhone); ?>' data-id='<?php echo e($idNo); ?>' data-pct='<?php echo e($pct); ?>' data-rating='<?php echo e($rating ?? 0); ?>' data-img='<?php echo e($displayImage); ?>' data-reviews='<?php echo $reviews; ?>'>
                            <i class="fa-solid fa-ellipsis-vertical options-icon"></i>
                            
                            <div class="tech-avatar">
                                <?php if($displayImage !== ""): ?>
                                    <img src="<?php echo e($displayImage); ?>" alt="<?php echo e($t['technician_name']); ?>">
                                <?php else: ?>
                                    <i class="fa-solid fa-user-tie"></i>
                                <?php endif; ?>
                            </div>
                            
                            <h3 class="tech-name"><?php echo e($t['technician_name']); ?></h3>
                            <div class="tech-rating">
                                <?php if ($reviews > 0): ?>
                                    <i class="fa-solid fa-star" style="color:#f59e0b;"></i>
                                    <?php echo $rating; ?>
                                    <span>(<?php echo $reviews; ?> review<?php echo $reviews !== 1 ? 's' : ''; ?>)</span>
                                <?php else: ?>
                                    <span class="no-ratings-yet">No ratings yet</span>
                                <?php endif; ?>
                            </div>
                            <div class="tech-role"><?php echo $role; ?></div>
                            <div class="tech-status">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </div>
                            
                            <div class="tech-actions">
                                <button type="button" class="action-btn edit"
                                    data-id="<?php echo (int)$t['id']; ?>"
                                    data-name="<?php echo e($t['technician_name']); ?>"
                                    data-phone="<?php echo e($phone); ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button type="button" class="action-btn delete"
                                    data-id="<?php echo (int)$t['id']; ?>"
                                    data-name="<?php echo e($t['technician_name']); ?>">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                        <?php } } ?>
                    </div>
                </div>

                <!-- Right Column: Charts & Feedback -->
                <div class="right-column">
                    <div class="panel chart-panel">
                        <div class="panel-header">
                            <div class="header-left">
                                <i class="fa-solid fa-chart-pie panel-icon"></i>
                                <h2>GSB Ratings Overview</h2>
                            </div>
                        </div>

                        <div class="chart-content">
                            <div class="donut-wrapper" style="background: <?php echo $conic_gradient; ?>;">
                                <div class="donut-hole">
                                    <span class="d-val"><?php echo $total_gsb; ?></span>
                                    <span class="d-lbl">Total Reviews</span>
                                </div>
                            </div>

                            <div class="chart-legend">
                                <div class="legend-row">
                                    <div class="l-left"><div class="dot blue"></div> 5 Stars</div>
                                    <div class="l-right"><?php echo $total_gsb ? round(($g_counts[5]/$total_gsb)*100) : 0; ?>% <span>(<?php echo$g_counts[5]; ?>)</span></div>
                                </div>
                                <div class="legend-row">
                                    <div class="l-left"><div class="dot purple"></div> 4 Stars</div>
                                    <div class="l-right"><?php echo $total_gsb ? round(($g_counts[4]/$total_gsb)*100) : 0; ?>% <span>(<?php echo$g_counts[4]; ?>)</span></div>
                                </div>
                                <div class="legend-row">
                                    <div class="l-left"><div class="dot yellow"></div> 3 Stars</div>
                                    <div class="l-right"><?php echo $total_gsb ? round(($g_counts[3]/$total_gsb)*100) : 0; ?>% <span>(<?php echo$g_counts[3]; ?>)</span></div>
                                </div>
                                <div class="legend-row">
                                    <div class="l-left"><div class="dot red"></div> 1-2 Stars</div>
                                    <div class="l-right"><?php echo $total_gsb ? round((($g_counts[2]+$g_counts[1])/$total_gsb)*100) : 0; ?>% <span>(<?php echo $g_counts[2]+$g_counts[1]; ?>)</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel feedback-panel">
                        <div class="panel-header">
                            <div class="header-left">
                                <i class="fa-solid fa-shield-halved panel-icon"></i>
                                <h2>Review Moderation</h2>
                            </div>
                        </div>

                        <div class="feedback-list">
                            <?php 
                            if($comments &&$comments->num_rows > 0) {
                                while($c =$comments->fetch_assoc()) {
                                    $date = isset($c['created_at']) ? date('M d, Y', strtotime($c['created_at'])) : date('M d, Y');$stars = isset($c['tech_rating']) ? (int)$c['tech_rating'] : 5;
                            ?>
                            <div class="feedback-item <?php echo $c['is_approved'] ? 'approved-item' : 'pending-item'; ?>">
                                <div class="fb-avatar"><i class="fa-solid fa-user"></i></div>
                                <div class="fb-content">
                                    <div class="fb-top">
                                        <span class="fb-name"><?php echo e($c['customer_name']); ?></span>
                                        <div class="fb-meta">
                                            <div class="fb-stars">
                                                <?php for($i=0; $i<$stars; $i++) echo '<i class="fa-solid fa-star"></i>'; ?>
                                            </div>
                                            <span class="fb-date"><?php echo $date; ?></span>
                                        </div>
                                    </div>
                                    <p class="fb-text"><?php echo e($c['comment']); ?></p>

                                    <!-- Media Preview (Loads directly from ../images/ratings/) -->
                                    <?php if (!empty($c['image_1']) || !empty($c['image_2']) || !empty($c['image_3']) || !empty($c['video_url'])): ?>
                                        <div class="fb-media">
                                            <?php if (!empty($c['image_1'])): ?>
                                                <a href="<?php echo e($c['image_1']); ?>" target="_blank"><img src="<?php echo e($c['image_1']); ?>" class="fb-media-img" alt="Review Media"></a>
                                            <?php endif; ?>
                                            <?php if (!empty($c['image_2'])): ?>
                                                <a href="<?php echo e($c['image_2']); ?>" target="_blank"><img src="<?php echo e($c['image_2']); ?>" class="fb-media-img" alt="Review Media"></a>
                                            <?php endif; ?>
                                            <?php if (!empty($c['image_3'])): ?>
                                                <a href="<?php echo e($c['image_3']); ?>" target="_blank"><img src="<?php echo e($c['image_3']); ?>" class="fb-media-img" alt="Review Media"></a>
                                            <?php endif; ?>
                                            <?php if (!empty($c['video_url'])): ?>
                                                <video src="<?php echo e($c['video_url']); ?>" controls class="fb-media-video"></video>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Moderation Actions -->
                                    <div class="fb-moderation">
                                        <?php if($c['is_approved']): ?>
                                            <span class="status-badge status-approved"><i class="fa-solid fa-check"></i> Published</span>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="hide_review">
                                                <?php echo csrf_field(); ?>                                                <input type="hidden" name="review_id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" class="mod-btn btn-hide">Hide</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="status-badge status-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="approve_review">
                                                <?php echo csrf_field(); ?>                                                <input type="hidden" name="review_id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" class="mod-btn btn-approve">Approve</button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_review">
                                            <?php echo csrf_field(); ?>                                            <input type="hidden" name="review_id" value="<?php echo $c['id']; ?>">
                                            <button type="button" class="mod-btn btn-delete" onclick="customConfirm('Delete this review permanently? This cannot be undone.', this.closest('form'));"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                    
                                </div>
                            </div>
                            <?php } } else { echo "<p style='color:#64748b; font-size:14px;'>No recent feedback.</p>"; } ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </main>
</div>

<!-- ==========================================
     MODALS
=========================================== -->
<!-- Profile Modal (View Only) -->
<div class="modal-overlay" id="profileModal" onclick="this.style.display='none'">
    <div class="modal-box" onclick="event.stopPropagation()">
        <i class="fa-solid fa-xmark modal-close" onclick="document.getElementById('profileModal').style.display='none'"></i>
        <div class="modal-header-split">
            <div class="modal-img" id="pAvatarContainer">
                <i class="fa-solid fa-user-tie"></i>
            </div>
            <div class="modal-info">
                <div>Name: <span id="pName">--</span></div>
                <div>Contact: <span id="pPhone">--</span></div>
            </div>
        </div>
        <div class="modal-pct" id="pPct">100%</div>
        <div class="modal-stars" id="pStars"></div>
    </div>
</div>

<!-- Add Technician Modal -->
<div class="modal-overlay" id="addModal" onclick="this.style.display='none'">
    <div class="modal-box form-box" onclick="event.stopPropagation()">
        <i class="fa-solid fa-xmark modal-close" onclick="document.getElementById('addModal').style.display='none'"></i>
        <h3 class="modal-form-title">Add New Technician</h3>
        <p class="modal-form-desc">Register a new technician into the GSB system.</p>
        <form method="POST" action="admin_ratings.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">
            <?php echo csrf_field(); ?>            <div class="form-group">
                <label>Profile Picture</label>
                <input type="file" name="tech_image" accept="image/*" class="file-input">
            </div>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="tech_name" required placeholder="e.g. Juan Dela Cruz">
            </div>
            <div class="form-group">
                <label>Contact Number</label>
                <input type="text" name="tech_phone" required placeholder="09123456789">
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="document.getElementById('addModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn-submit">Add Technician</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Technician Modal -->
<div class="modal-overlay" id="editModal" onclick="this.style.display='none'">
    <div class="modal-box form-box" onclick="event.stopPropagation()">
        <i class="fa-solid fa-xmark modal-close" onclick="document.getElementById('editModal').style.display='none'"></i>
        <h3 class="modal-form-title">Edit Technician</h3>
        <p class="modal-form-desc">Update the selected technician's information and photo.</p>
        <form method="POST" action="admin_ratings.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit">
            <?php echo csrf_field(); ?>            <input type="hidden" name="tech_id" id="edit_tech_id">
            <div class="form-group">
                <label>Update Profile Picture (Optional)</label>
                <input type="file" name="tech_image" accept="image/*" class="file-input">
            </div>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="tech_name" id="edit_tech_name" required>
            </div>
            <div class="form-group">
                <label>Contact Number</label>
                <input type="text" name="tech_phone" id="edit_tech_phone" required>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn-submit edit-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal" onclick="this.style.display='none'">
    <div class="modal-box form-box text-center" onclick="event.stopPropagation()">
        <i class="fa-solid fa-xmark modal-close" onclick="document.getElementById('deleteModal').style.display='none'"></i>
        <div class="delete-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h3 class="modal-form-title">Delete Technician</h3>
        <p class="modal-form-desc">Are you sure you want to delete <strong id="delete_tech_name"></strong>? This action cannot be undone and will remove their data from the system.</p>
        <form method="POST" action="admin_ratings.php">
            <input type="hidden" name="action" value="delete">
            <?php echo csrf_field(); ?>            <input type="hidden" name="tech_id" id="delete_tech_id">
            <div class="form-actions" style="margin-top: 25px;">
                <button type="button" class="btn-cancel" onclick="document.getElementById('deleteModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn-submit delete-submit">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- INJECT GLOBAL MODALS -->
<?php include 'includes/global_modals.php'; ?>

<script>
// Event delegation — single listener handles all card clicks, edit, and delete
document.addEventListener('click', function(e) {
    // Edit button
    const editBtn = e.target.closest('.action-btn.edit');
    if (editBtn) {
        e.stopPropagation();
        const card = editBtn.closest('.tech-card');
        const id   = editBtn.getAttribute('data-id');
        const name = editBtn.getAttribute('data-name');
        const phone = editBtn.getAttribute('data-phone');
        document.getElementById('edit_tech_id').value  = id;
        document.getElementById('edit_tech_name').value = name;
        document.getElementById('edit_tech_phone').value = phone;
        document.getElementById('editModal').style.display = 'flex';
        return;
    }

    // Delete button
    const delBtn = e.target.closest('.action-btn.delete');
    if (delBtn) {
        e.stopPropagation();
        const id   = delBtn.getAttribute('data-id');
        const name = delBtn.getAttribute('data-name');
        document.getElementById('delete_tech_id').value = id;
        document.getElementById('delete_tech_name').textContent = name;
        document.getElementById('deleteModal').style.display = 'flex';
        return;
    }

    // Options icon — ignore
    if (e.target.closest('.options-icon')) return;

    // Tech card body click → open profile modal
    const card = e.target.closest('.tech-card');
    if (card) {
        openProfileModal(
            card.dataset.name,
            card.dataset.phone,
            card.dataset.id,
            parseFloat(card.dataset.pct),
            parseFloat(card.dataset.rating),
            card.dataset.img,
            parseInt(card.dataset.reviews)
        );
    }
});

function openProfileModal(name, phone, idNo, pct, rating, imagePath, reviews) {
    document.getElementById('pName').textContent = name;
    document.getElementById('pPhone').textContent = phone;

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
            if(i <= fullStars) starHtml += '<i class="fa-solid fa-star" style="color:#f59e0b;"></i>';
            else if(i === fullStars + 1 && hasHalf) starHtml += '<i class="fa-solid fa-star-half-stroke" style="color:#f59e0b;"></i>';
            else starHtml += '<i class="fa-regular fa-star" style="color:#d1d5db;"></i>';
        }
        document.getElementById('pStars').innerHTML = starHtml;
    }
    document.getElementById('profileModal').style.display = 'flex';
}
function openAddModal() { document.getElementById('addModal').style.display = 'flex'; }
function openEditModal(event, id, name, phone) {
    event.stopPropagation();
    document.getElementById('edit_tech_id').value = id;
    document.getElementById('edit_tech_name').value = name;
    document.getElementById('edit_tech_phone').value = phone;
    document.getElementById('editModal').style.display = 'flex';
}
function openDeleteModal(event, id, name) {
    event.stopPropagation();
    document.getElementById('delete_tech_id').value = id;
    document.getElementById('delete_tech_name').textContent = name;
    document.getElementById('deleteModal').style.display = 'flex';
}
</script>
</body>
</html>