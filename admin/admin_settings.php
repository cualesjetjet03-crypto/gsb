<?php
session_start();
require_once 'includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_helpers.php';

require_admin_session('admin_login.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings</title>
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- IMPORTANT: Check that this file exists in exactly this folder -->
    <link rel="stylesheet" href="includes/css/admin_settings.css">
</head>
<body>
    
    <!-- Assuming you have topbar and sidebar includes here -->
    <?php 
        // include 'includes/admin_sidebar.php'; 
        // include 'includes/topbar.php'; 
    ?>

    <div class="main-content">
        <div class="settings-wrapper">
            <div class="settings-header">
                <h2>Account Settings</h2>
                <a href="admin_dashboard.php" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fa-solid fa-circle-exclamation"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="settings-grid">
                
                <!-- Profile Details Card -->
                <div class="settings-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-user-pen"></i> Update Profile Details</h3>
                    </div>
                    <div class="card-body">
                        <form action="actions/update_profile.php" method="POST">
                            <div class="form-group">
                                <label>Name</label>
                                <input type="text" name="admin_name" class="form-control" value="<?php echo isset($_SESSION['admin_name']) ? htmlspecialchars($_SESSION['admin_name']) : ''; ?>" placeholder="Enter full name">
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="admin_email" class="form-control" value="<?php echo isset($_SESSION['admin_email']) ? htmlspecialchars($_SESSION['admin_email']) : ''; ?>" placeholder="Enter email address">
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save Profile</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Change Password Card -->
                <div class="settings-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-lock"></i> Change Password</h3>
                    </div>
                    <div class="card-body">
                        <form action="actions/update_password.php" method="POST">
                            <div class="form-group">
                                <label>Current Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="current_password" name="current_password" class="form-control" required placeholder="Enter current password">
                                    <i class="fa-regular fa-eye-slash toggle-password" data-target="current_password"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="new_password" name="new_password" class="form-control" required placeholder="Enter new password">
                                    <i class="fa-regular fa-eye-slash toggle-password" data-target="new_password"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required placeholder="Confirm new password">
                                    <i class="fa-regular fa-eye-slash toggle-password" data-target="confirm_password"></i>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-danger">Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- DISCOUNT PASSCODE CARD (UPDATED) -->
                <div class="settings-card">
                    <div class="card-header">
                        <h3><i class="fa-solid fa-tags"></i> Discount Passcode</h3>
                    </div>
                    <div class="card-body">
                        <form action="actions/update_discount_passcode.php" method="POST">
                            <p style="font-size: 0.9rem; color: #6c757d; margin-bottom: 15px;">Set the secret authorization passcode required to apply promo discounts to products.</p>
                            
                            <div class="form-group">
                                <label>Current Authorization Passcode</label>
                                <div class="password-wrapper">
                                    <input type="password" id="current_discount_passcode" name="current_discount_passcode" class="form-control" required placeholder="Enter current passcode">
                                    <i class="fa-regular fa-eye-slash toggle-password" data-target="current_discount_passcode"></i>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>New Authorization Passcode</label>
                                <div class="password-wrapper">
                                    <input type="password" id="discount_passcode" name="discount_passcode" class="form-control" required placeholder="Enter new passcode">
                                    <i class="fa-regular fa-eye-slash toggle-password" data-target="discount_passcode"></i>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" style="background-color: #d31b27; border-color: #d31b27;">Save Passcode</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Toggle Password Visibility Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggleIcons = document.querySelectorAll('.toggle-password');
            
            toggleIcons.forEach(icon => {
                icon.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const inputField = document.getElementById(targetId);
                    
                    if (inputField.type === 'password') {
                        inputField.type = 'text';
                        this.classList.remove('fa-eye-slash');
                        this.classList.add('fa-eye');
                    } else {
                        inputField.type = 'password';
                        this.classList.remove('fa-eye');
                        this.classList.add('fa-eye-slash');
                    }
                });
            });
        });
    </script>
</body>
</html>