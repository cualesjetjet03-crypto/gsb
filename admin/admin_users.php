<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: admin_login.php");
    exit();
}
require_once 'includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_helpers.php';

/* 
|--------------------------------------------------------------------------
| HANDLE FORM SUBMISSIONS (EDIT, DELETE)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf(); // ← CSRF check on every POST

    $action = $_POST['action'];

    if ($action === 'edit') {
        $id = (int)$_POST['user_id'];
        $fullname = trim($_POST['fullname']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        
        $stmt = $conn->prepare("UPDATE users SET fullname = ?, username = ?, email = ?, phone = ?, address = ? WHERE id = ?");
        if($stmt){
            $stmt->bind_param("sssssi", $fullname, $username, $email, $phone, $address, $id);
            $stmt->execute();
            $stmt->close();
        }
    } 
    elseif ($action === 'delete') {
        $id = (int)$_POST['user_id'];
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        if($stmt){
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    header("Location: admin_users.php");
    exit();
}

function e($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

// Fetch KPI Metrics
$total_users_query = $conn->query("SELECT COUNT(*) AS total FROM users");
$total_users = $total_users_query->fetch_assoc()['total'] ?? 0;

$verified_query = $conn->query("SELECT COUNT(*) AS total FROM users WHERE is_verified = 1");
$verified_users = $verified_query->fetch_assoc()['total'] ?? 0;

$unverified_users = $total_users - $verified_users;

$recent_query = $conn->query("SELECT COUNT(*) AS total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$recent_users = $recent_query->fetch_assoc()['total'] ?? 0;

// Fetch all users
$users_result = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Customer Management - GSB Admin</title>
    <link rel="stylesheet" href="includes/css/admin_dashboard.css">
    <!-- Link to the new external CSS file -->
    <link rel="stylesheet" href="includes/css/admin_users.css">
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
                    <div class="kpi-icon blue"><i class="fa-solid fa-users"></i></div>
                    <div class="kpi-details">
                        <div class="kpi-value"><?php echo $total_users; ?></div>
                        <div class="kpi-title">Total Customers</div>
                        <div class="kpi-subtitle">Registered accounts</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue" style="color: #16a34a; background: #dcfce7;"><i class="fa-solid fa-user-check"></i></div>
                    <div class="kpi-details">
                        <div class="kpi-value"><?php echo $verified_users; ?></div>
                        <div class="kpi-title">Verified Users</div>
                        <div class="kpi-subtitle">Email verified</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue" style="color: #ca8a04; background: #fef08a;"><i class="fa-solid fa-user-clock"></i></div>
                    <div class="kpi-details">
                        <div class="kpi-value"><?php echo $unverified_users; ?></div>
                        <div class="kpi-title">Unverified Users</div>
                        <div class="kpi-subtitle">Pending verification</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue" style="color: #3b82f6; background: #dbeafe;"><i class="fa-solid fa-user-plus"></i></div>
                    <div class="kpi-details">
                        <div class="kpi-value"><?php echo $recent_users; ?></div>
                        <div class="kpi-title">New Signups</div>
                        <div class="kpi-subtitle">Last 7 days</div>
                    </div>
                </div>
            </div>

            <!-- Customer Table Panel -->
            <div class="panel users-panel">
                <div class="panel-header">
                    <div class="header-left">
                        <i class="fa-solid fa-address-book panel-icon"></i>
                        <div>
                            <h2>Customer Accounts</h2>
                            <p>Manage registered user profiles and contact details.</p>
                        </div>
                    </div>
                </div>

                <div class="users-table-wrapper">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Contact Details</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Date Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if($users_result && $users_result->num_rows > 0) {
                                while($u = $users_result->fetch_assoc()){ 
                                    $img = !empty($u['profile_picture']) ? "../customer/" . $u['profile_picture'] : "";
                            ?>
                            <tr>
                                <td>
                                    <div class="user-profile-cell">
                                        <div class="user-avatar">
                                            <?php if($img && file_exists($img)): ?>
                                                <img src="<?php echo e($img); ?>" alt="Avatar">
                                            <?php else: ?>
                                                <i class="fa-solid fa-user"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="user-info-text">
                                            <strong><?php echo e($u['fullname']); ?></strong>
                                            <span>@<?php echo e($u['username']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="user-info-text">
                                        <strong><?php echo e($u['email']); ?></strong>
                                        <span><?php echo e($u['phone'] ?: 'No phone provided'); ?></span>
                                    </div>
                                </td>
                                <td><?php echo e($u['address'] ?: '<span style="color:#94a3b8; font-style:italic;">Not provided</span>'); ?></td>
                                <td>
                                    <?php if($u['is_verified']): ?>
                                        <span class="status-badge status-verified"><i class="fa-solid fa-check-circle"></i> Verified</span>
                                    <?php else: ?>
                                        <span class="status-badge status-unverified"><i class="fa-solid fa-clock"></i> Unverified</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date("M d, Y", strtotime($u['created_at'])); ?></td>
                                <td>
                                    <div class="table-actions">
                                        <button type="button" class="action-btn edit"
                                            onclick="openEditModal(<?php echo (int)$u['id']; ?>, <?php echo json_encode($u['fullname']); ?>, <?php echo json_encode($u['username']); ?>, <?php echo json_encode($u['email']); ?>, <?php echo json_encode($u['phone']); ?>, <?php echo json_encode($u['address']); ?>)">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button type="button" class="action-btn delete"
                                            onclick="openDeleteModal(<?php echo (int)$u['id']; ?>, <?php echo json_encode($u['fullname']); ?>)">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                } 
                            } else {
                                echo "<tr><td colspan='6' style='text-align:center; padding: 20px; color: #64748b;'>No users found in the database.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </main>
</div>

<!-- ==========================================
     MODALS
=========================================== -->

<!-- Edit User Modal -->
<div class="modal-overlay" id="editModal" onclick="this.style.display='none'">
    <div class="modal-box form-box" onclick="event.stopPropagation()">
        <i class="fa-solid fa-xmark modal-close" onclick="document.getElementById('editModal').style.display='none'"></i>
        <h3 class="modal-form-title">Edit Customer Profile</h3>
        <p class="modal-form-desc">Update user information and contact details.</p>
        <form method="POST" action="admin_users.php">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="fullname" id="edit_fullname" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="edit_username" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" id="edit_email" required>
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" id="edit_phone">
            </div>
            <div class="form-group">
                <label>Address</label>
                <input type="text" name="address" id="edit_address">
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
        <h3 class="modal-form-title">Delete Customer Account</h3>
        <p class="modal-form-desc">Are you sure you want to delete <strong id="delete_user_name"></strong>? This action will permanently remove their account access.</p>
        <form method="POST" action="admin_users.php">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="delete_user_id">
            <?php echo csrf_field(); ?>
            <div class="form-actions" style="margin-top: 25px;">
                <button type="button" class="btn-cancel" onclick="document.getElementById('deleteModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn-submit delete-submit">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, fullname, username, email, phone, address) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_fullname').value = fullname;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_address').value = address;
    document.getElementById('editModal').style.display = 'flex';
}

function openDeleteModal(id, name) {
    document.getElementById('delete_user_id').value = id;
    document.getElementById('delete_user_name').textContent = name;
    document.getElementById('deleteModal').style.display = 'flex';
}
</script>
</body>
</html>