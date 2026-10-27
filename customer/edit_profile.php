<?php
session_start();
include 'db_connect.php';

/* Check if user is logged in */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/* Fetch user data */
$stmt =$conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();$result = $stmt->get_result();$user = $result->fetch_assoc();$stmt->close();

if (!$user) {
    die("User not found.");
}

/* Fetch saved addresses for the Saved Addresses section */
$saved_addresses = [];
$addr_stmt = $conn->prepare(
    "SELECT id, label, address, province, city, barangay, street_address, is_default
     FROM user_addresses WHERE user_id = ?
     ORDER BY is_default DESC, id ASC"
);
if ($addr_stmt) {
    $addr_stmt->bind_param('i', $user_id);
    $addr_stmt->execute();
    $saved_addresses = $addr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $addr_stmt->close();
}

/* Update Profile */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $phone = trim($_POST['phone']);
    $gender = trim($_POST['gender']);
    $birthdate = trim($_POST['birthdate']);
    
    // Backend Phone Validation (11 digits starting with 09)
    if (!empty($phone) && !preg_match('/^09\d{9}$/',$phone)) {
        die("<script>alert('Phone number must be exactly 11 digits and start with 09.'); window.history.back();</script>");
    }

    // Password update logic
    $current_password = $_POST['current_password'] ?? '';$new_password = $_POST['new_password'] ?? '';$confirm_password = $_POST['confirm_password'] ?? '';$update_password = false;
    
    if (!empty($new_password) or !empty($confirm_password)) {
        
        // 1. Verify they provided their current password
        if (empty($current_password)) {
            die("<script>alert('You must enter your Current Password to change it!'); window.history.back();</script>");
        }
        
        // 2. Verify the current password matches the database
        if (!password_verify($current_password,$user['password'])) {
            die("<script>alert('Incorrect Current Password! Please try again.'); window.history.back();</script>");
        }

        // 3. Verify new passwords match
        if ($new_password !==$confirm_password) {
            die("<script>alert('New Passwords do not match!'); window.history.back();</script>");
        }
        
        // 4. Verify new password strength
        $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%])[A-Za-z\d!@#$\%]{8,}$/';
        if (!preg_match($pattern,$new_password)) {
            die("<script>alert('Password does not meet the security requirements!'); window.history.back();</script>");
        }
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);$update_password = true;
    }
    
    // Default to existing picture
    $profile_picture = !empty($user['profile_picture']) ? $user['profile_picture'] : 'images/users.png';

    // ── Profile picture upload with full server-side validation ──────────────
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $max_bytes     = 3 * 1024 * 1024; // 3 MB

        $up_size = $_FILES['profile_picture']['size'];
        $up_tmp  = $_FILES['profile_picture']['tmp_name'];
        $up_ext  = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));

        // Use getimagesize for MIME detection (more portable than finfo)
        $img_info = @getimagesize($up_tmp);
        $up_mime  = $img_info ? $img_info['mime'] : '';
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        if ($up_size > $max_bytes) {
            echo "<script>alert('Profile picture must be under 3 MB.'); window.history.back();</script>";
            exit();
        } elseif (!in_array($up_ext, $allowed_exts, true)) {
            echo "<script>alert('Only JPG, PNG, WEBP, or GIF images are allowed.'); window.history.back();</script>";
            exit();
        } elseif (!in_array($up_mime, $allowed_mimes, true)) {
            echo "<script>alert('Invalid image file.'); window.history.back();</script>";
            exit();
        } else {
            $target_dir = __DIR__ . '/images/';
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
            }
            $new_filename = bin2hex(random_bytes(12)) . '.' . $up_ext;
            $full_path    = $target_dir . $new_filename;
            if (move_uploaded_file($up_tmp, $full_path)) {
                $profile_picture = 'images/' . $new_filename;
            } else {
                error_log("edit_profile: move_uploaded_file failed. target=$full_path");
                // Keep existing picture — don't crash
            }
        }
    }

    // ── Uniqueness check: prevent stealing another user's email or username ──
    $uniq = $conn->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ? LIMIT 1");
    $uniq->bind_param("ssi", $email, $username, $user_id);
    $uniq->execute();
    if ($uniq->get_result()->num_rows > 0) {
        $uniq->close();
        die("<script>alert('That email or username is already taken by another account.'); window.history.back();</script>");
    }
    $uniq->close();

    // Update Database — address is managed separately via Saved Addresses (manage_addresses.php)
    if ($update_password) {
        $update_stmt = $conn->prepare("
            UPDATE users 
            SET fullname = ?, email = ?, username = ?, phone = ?, gender = ?, birthdate = ?, profile_picture = ?, password = ? 
            WHERE id = ?
        ");
        $update_stmt->bind_param("ssssssssi", $fullname, $email, $username, $phone, $gender, $birthdate, $profile_picture, $hashed_password, $user_id);
    } else {
        $update_stmt = $conn->prepare("
            UPDATE users 
            SET fullname = ?, email = ?, username = ?, phone = ?, gender = ?, birthdate = ?, profile_picture = ? 
            WHERE id = ?
        ");
        $update_stmt->bind_param("sssssssi", $fullname, $email, $username, $phone, $gender, $birthdate, $profile_picture, $user_id);
    }

    if ($update_stmt->execute()) {
        echo "<script>
                alert('Profile updated successfully!');
                window.location.href='profile.php';
              </script>";
        exit();
    } else {
        echo "<script>alert('Update failed: " . addslashes($conn->error) . "'); window.history.back();</script>";
        exit();
    }
    $update_stmt->close();

    } catch (Throwable $e) {
        error_log("edit_profile POST error: " . $e->getMessage());
        echo "<script>alert('An error occurred. Please try again.'); window.history.back();</script>";
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - GSB Airconditioning Services</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/GSB-homepage.css">
    <link rel="stylesheet" href="css/edit-profile.css">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="edit-profile-container">
    <div class="edit-profile-header">
        <a href="profile.php" class="back-btn">&larr; Back to Profile</a>
        <div class="header-titles">
            <h1>Edit Profile</h1>
            <p>Manage and update your personal information</p>
        </div>
    </div>

    <div class="edit-profile-card">
        <form method="POST" enctype="multipart/form-data" class="edit-profile-form" autocomplete="off" onsubmit="return validateProfileForm()">
            
            <!-- LEFT SIDE -->
            <div class="profile-picture-section">
                <?php $display_pic = !empty($user['profile_picture']) ?$user['profile_picture'] : 'images/users.png'; ?>
                <img src="<?php echo htmlspecialchars($display_pic); ?>" class="edit-profile-img" alt="Profile">
                <h3>Profile Picture</h3>
                <input type="file" name="profile_picture" accept="image/*" class="profile-file-upload">
            </div>

            <!-- RIGHT SIDE -->
            <div class="profile-form-section">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" id="edit_phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                </div>

                <!-- GENDER & BIRTH DATE SECTION -->
                <div class="address-grid">
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo (isset($user['gender']) &&$user['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo (isset($user['gender']) &&$user['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo (isset($user['gender']) &&$user['gender'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Birth Date</label>
                        <input type="date" name="birthdate" value="<?php echo htmlspecialchars($user['birthdate'] ?? ''); ?>">
                    </div>
                </div>

                <!-- ═══════════════════════════════════════
                     SAVED ADDRESSES SECTION
                ═══════════════════════════════════════ -->
                <div class="addr-edit-section">
                    <div class="addr-section-header">
                        <h3 class="section-title" style="margin:0;">
                            <i class="fa-solid fa-location-dot" style="color:#2563eb; margin-right:6px;"></i>
                            Saved Addresses
                        </h3>
                        <button type="button" class="addr-add-btn" onclick="openAddAddrModal()">
                            <i class="fa-solid fa-plus"></i> Add Address
                        </button>
                    </div>
                    <p class="section-subtitle" style="margin-top:4px;">
                        The <strong>Default</strong> address is auto-filled in your order form.
                    </p>

                    <?php if (empty($saved_addresses)): ?>
                    <p class="addr-empty">No saved addresses yet. Add one below.</p>
                    <?php else: ?>
                    <div class="addr-list">
                        <?php foreach ($saved_addresses as $addr): ?>
                        <div class="addr-card <?php echo $addr['is_default'] ? 'addr-default' : ''; ?>"
                             id="addr-<?php echo (int)$addr['id']; ?>">
                            <div class="addr-card-body">
                                <div class="addr-label-row">
                                    <span class="addr-label"><?php echo htmlspecialchars($addr['label']); ?></span>
                                    <?php if ($addr['is_default']): ?>
                                    <span class="addr-default-pill">
                                        <i class="fa-solid fa-check-circle"></i> Default
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <p class="addr-text"><?php echo htmlspecialchars($addr['address']); ?></p>
                            </div>
                            <div class="addr-card-actions">
                                <?php if (!$addr['is_default']): ?>
                                <button type="button" class="addr-btn addr-set-default"
                                        onclick="setDefault(<?php echo (int)$addr['id']; ?>)">
                                    <i class="fa-solid fa-star"></i> Set as Default
                                </button>
                                <?php endif; ?>
                                <button type="button" class="addr-btn addr-delete"
                                        onclick="deleteAddr(<?php echo (int)$addr['id']; ?>)">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- PASSWORD SECTION -->
                <h3 class="section-title">Change Password?</h3>

                <!-- BROWSER AUTOFILL BLOCKER (Hidden from user, catches browser autofill) -->
                <input type="text" name="fakeusernameremembered" style="display:none;" aria-hidden="true">
                <input type="password" name="fakepasswordremembered" style="display:none;" aria-hidden="true">
                
                <!-- CURRENT PASSWORD -->
                <div class="form-group full-width" style="margin-bottom: 22px;">
                    <label>Current Password <span class="optional-text">(Required to set a new password)</span></label>
                    <div class="password-wrapper">
                        <!-- Changed autocomplete to new-password to stop forceful inserts -->
                        <input type="password" name="current_password" id="current_password" placeholder="••••••••" autocomplete="new-password">
                        <button type="button" class="toggle-btn" onclick="togglePasswordVisibility('current_password', this)" title="Show Password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="password-row">
                    <div class="form-group">
                        <label>New Password <span class="optional-text">(leave blank to keep current)</span></label>
                        <div class="password-wrapper">
                            <input type="password" name="new_password" id="new_password" placeholder="••••••••" autocomplete="new-password">
                            <button type="button" class="toggle-btn" onclick="togglePasswordVisibility('new_password', this)" title="Show Password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" placeholder="••••••••" autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <div class="password-requirements" id="password_requirements">
                    <p id="req_length"><span class="icon">❌</span> At least 8 characters</p>
                    <p id="req_upper"><span class="icon">❌</span> At least 1 uppercase letter (A-Z)</p>
                    <p id="req_lower"><span class="icon">❌</span> At least 1 lowercase letter (a-z)</p>
                    <p id="req_num"><span class="icon">❌</span> At least 1 number (0-9)</p>
                    <p id="req_special"><span class="icon">❌</span> At least 1 special character (!@#$%)</p>
                </div>

                <div class="button-group">
                    <a href="profile.php" class="cancel-btn">Cancel</a>
                    <button type="submit" class="save-btn">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>

<!-- Scripts -->
<script>
    // --- TOGGLE PASSWORD VISIBILITY ---
    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i'); 
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            btn.title = "Hide Password";
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye'); 
            btn.title = "Show Password";
        }
    }

    // --- PASSWORD VALIDATION LOGIC ---
    const newPasswordInput = document.getElementById('new_password');
    const requirementsBox = document.getElementById('password_requirements');
    
    const reqLength = document.getElementById('req_length');
    const reqUpper = document.getElementById('req_upper');
    const reqLower = document.getElementById('req_lower');
    const reqNum = document.getElementById('req_num');
    const reqSpecial = document.getElementById('req_special');

    const patterns = {
        length: /.{8,}/,
        upper: /[A-Z]/,
        lower: /[a-z]/,
        num: /[0-9]/,
        special: /[!@#$%]/
    };

    function updateRequirement(element, isValid) {
        if (isValid) {
            element.classList.add('valid');
            element.querySelector('.icon').textContent = '✅';
        } else {
            element.classList.remove('valid');
            element.querySelector('.icon').textContent = '❌';
        }
    }

    newPasswordInput.addEventListener('input', function() {
        const val = this.value;
        if (val.length > 0) {
            requirementsBox.style.display = 'block';
        } else {
            requirementsBox.style.display = 'none';
        }

        updateRequirement(reqLength, patterns.length.test(val));
        updateRequirement(reqUpper, patterns.upper.test(val));
        updateRequirement(reqLower, patterns.lower.test(val));
        updateRequirement(reqNum, patterns.num.test(val));
        updateRequirement(reqSpecial, patterns.special.test(val));
    });

    // --- COMBINED FORM VALIDATION ---
    function validateProfileForm() {
        // 1. Check Phone Number
        const phone = document.getElementById('edit_phone').value;
        if (phone !== "" && !/^09\d{9}$/.test(phone)) {
            alert("Phone number must be exactly 11 digits and start with 09.");
            return false;
        }

        // 2. Check Password
        const currentPwd = document.getElementById('current_password').value;
        const newPwd = document.getElementById('new_password').value;
        const confirmPwd = document.getElementById('confirm_password').value;
        
        if ((newPwd + confirmPwd).length > 0) {
            if (currentPwd === "") {
                alert("You must enter your Current Password to change it.");
                return false;
            }
            if (newPwd !== confirmPwd) {
                alert("New Passwords do not match.");
                return false;
            }
            const allMet = patterns.length.test(newPwd) && 
                           patterns.upper.test(newPwd) && 
                           patterns.lower.test(newPwd) && 
                           patterns.num.test(newPwd) && 
                           patterns.special.test(newPwd);
                           
            if (!allMet) {
                alert("Please ensure your new password meets all security requirements.");
                return false;
            }
        }
        return true;
    }
</script>

<!-- ═══════════════════════════════
     SAVED ADDRESSES MODAL
═══════════════════════════════ -->
<div class="addr-modal-overlay" id="addAddrModal" onclick="if(event.target===this)closeAddAddrModal()">
    <div class="addr-modal">
        <button class="addr-modal-close" type="button" onclick="closeAddAddrModal()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <h3>Add New Address</h3>
        <p>You can save up to 5 addresses. The first one becomes the default.</p>

        <div class="addr-form-group">
            <label>Label <span class="addr-req">(e.g. Home, Office, Province)</span></label>
            <input type="text" id="newAddrLabel" placeholder="Home" maxlength="60">
        </div>
        <div class="addr-form-group">
            <label>Province / Region <span class="addr-req">*</span></label>
            <select id="addrProvince" onchange="addrFetchCities()" class="addr-select">
                <option value="" disabled selected>Select Province/Region</option>
                <option value="Metro Manila (NCR)" data-code="130000000" data-type="regions">Metro Manila (NCR)</option>
                <option value="Batangas"           data-code="041000000" data-type="provinces">Batangas</option>
                <option value="Cavite"             data-code="042100000" data-type="provinces">Cavite</option>
                <option value="Laguna"             data-code="043400000" data-type="provinces">Laguna</option>
                <option value="Quezon"             data-code="045600000" data-type="provinces">Quezon</option>
                <option value="Rizal"              data-code="045800000" data-type="provinces">Rizal</option>
            </select>
        </div>
        <div class="addr-form-group">
            <label>City / Municipality <span class="addr-req">*</span></label>
            <select id="addrCity" disabled onchange="addrFetchBarangays()" class="addr-select">
                <option value="" disabled selected>Select City first</option>
            </select>
        </div>
        <div class="addr-form-group">
            <label>Barangay <span class="addr-req">*</span></label>
            <select id="addrBarangay" disabled class="addr-select">
                <option value="" disabled selected>Select Barangay first</option>
            </select>
        </div>
        <div class="addr-form-group">
            <label>Street Address <span class="addr-req">*</span></label>
            <input type="text" id="addrStreet" placeholder="House no., Street name, Subdivision">
        </div>
        <div id="addrError" class="addr-error" style="display:none;"></div>
        <div class="addr-modal-actions">
            <button type="button" class="addr-cancel-btn" onclick="closeAddAddrModal()">Cancel</button>
            <button type="button" class="addr-save-btn" onclick="saveNewAddr()">
                <i class="fa-solid fa-floppy-disk"></i> Save Address
            </button>
        </div>
    </div>
</div>

<style>
/* ── Saved Addresses section inside edit_profile ── */
.addr-edit-section {
    margin-bottom: 28px;
    padding: 20px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
}
.addr-section-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 8px; flex-wrap: wrap; gap: 10px;
}
.addr-add-btn {
    font-size: 13px; padding: 8px 16px;
    background: #2563eb; color: #fff; border: none;
    border-radius: 8px; cursor: pointer; font-weight: 600;
    display: inline-flex; align-items: center; gap: 6px;
    font-family: inherit; transition: background 0.2s;
}
.addr-add-btn:hover { background: #1d4ed8; }
.addr-empty { color: #94a3b8; font-size: 13px; font-style: italic; margin: 8px 0 0; }
.addr-req   { font-size: 11px; color: #94a3b8; font-weight: 400; }

.addr-list { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
.addr-card {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px; background: #fff;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 14px 16px; transition: box-shadow 0.2s, border-color 0.2s;
}
.addr-card:hover { box-shadow: 0 3px 10px rgba(0,0,0,0.06); }
.addr-card.addr-default { border-color: #2563eb; background: #eff6ff; }
.addr-card-body { flex: 1; min-width: 0; }
.addr-label-row { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.addr-label { font-size: 13px; font-weight: 700; color: #1e293b; }
.addr-default-pill {
    font-size: 11px; font-weight: 700; color: #2563eb;
    background: #dbeafe; border-radius: 20px; padding: 2px 9px;
    display: inline-flex; align-items: center; gap: 3px;
}
.addr-text { margin: 0; font-size: 13px; color: #334155; line-height: 1.5; word-break: break-word; }
.addr-card-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }
.addr-btn {
    font-size: 12px; font-weight: 600; padding: 5px 11px;
    border-radius: 7px; border: 1px solid #e2e8f0;
    background: #fff; color: #475569; cursor: pointer;
    transition: all 0.2s; white-space: nowrap; font-family: inherit;
    display: inline-flex; align-items: center; gap: 4px;
}
.addr-btn:hover         { background: #f8fafc; }
.addr-set-default:hover { border-color: #2563eb; color: #2563eb; background: #eff6ff; }
.addr-delete:hover      { border-color: #ef4444; color: #ef4444; background: #fef2f2; }

/* ── Modal ── */
.addr-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(10,20,80,0.48); backdrop-filter: blur(3px);
    z-index: 99999; align-items: center; justify-content: center; padding: 20px;
}
.addr-modal-overlay.open { display: flex; }
.addr-modal {
    background: #fff; border-radius: 16px; padding: 28px;
    width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2); position: relative;
    animation: addrSlide 0.28s cubic-bezier(0.34,1.2,0.64,1);
}
.addr-modal::-webkit-scrollbar { width: 4px; }
.addr-modal::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
@keyframes addrSlide { from { transform: translateY(22px); opacity: 0; } to { transform: none; opacity: 1; } }
.addr-modal h3 { margin: 0 0 4px; font-size: 18px; font-weight: 700; color: #1e293b; }
.addr-modal > p { margin: 0 0 18px; font-size: 13px; color: #64748b; }
.addr-modal-close {
    position: absolute; top: 14px; right: 16px;
    background: none; border: none; font-size: 18px;
    color: #94a3b8; cursor: pointer; transition: color 0.2s;
}
.addr-modal-close:hover { color: #ef4444; }
.addr-form-group { margin-bottom: 13px; }
.addr-form-group label { display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px; }
.addr-form-group input, .addr-select {
    width: 100%; padding: 10px 12px; border: 1px solid #dbe2ea; border-radius: 8px;
    font-size: 14px; font-family: inherit; color: #0f172a; outline: none;
    box-sizing: border-box; transition: border-color 0.2s; background: #fff;
    appearance: none;
}
.addr-form-group input:focus, .addr-select:focus {
    border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
}
.addr-select:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
.addr-select {
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22 width%3D%22292%22 height%3D%22292%22%3E%3Cpath fill%3D%22%2364748b%22 d%3D%22M287 69.4a17.6 17.6 0 0 0-13-5.4H18.4c-5 0-9.3 1.8-12.9 5.4A17.6 17.6 0 0 0 0 82.2c0 5 1.8 9.3 5.4 12.9l128 127.9c3.6 3.6 7.8 5.4 12.8 5.4s9.2-1.8 12.8-5.4L287 95c3.5-3.5 5.4-7.8 5.4-12.8 0-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat; background-position: right 11px top 55%; background-size: 10px; padding-right: 30px;
}
.addr-error { background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; border-radius: 8px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; }
.addr-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
.addr-cancel-btn {
    padding: 10px 18px; border: 1px solid #e2e8f0; background: #f8fafc;
    color: #475569; border-radius: 8px; font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: background 0.2s;
}
.addr-cancel-btn:hover { background: #f1f5f9; }
.addr-save-btn {
    padding: 10px 20px; background: #1e40af; color: #fff;
    border: none; border-radius: 8px; font-size: 14px; font-weight: 700;
    cursor: pointer; font-family: inherit; transition: background 0.2s;
    display: inline-flex; align-items: center; gap: 6px;
}
.addr-save-btn:hover { background: #1d4ed8; }
.addr-select.loading { color: #94a3b8; }
@media (max-width: 600px) {
    .addr-card { flex-direction: column; }
    .addr-card-actions { width: 100%; justify-content: flex-end; }
    .addr-modal { padding: 20px 16px; }
    .addr-modal-actions { flex-direction: column-reverse; }
    .addr-cancel-btn, .addr-save-btn { width: 100%; justify-content: center; }
}
</style>

<script>
/* ── Address Modal ── */
function openAddAddrModal()  { document.getElementById('addAddrModal').classList.add('open'); document.getElementById('newAddrLabel').focus(); }
function closeAddAddrModal() {
    document.getElementById('addAddrModal').classList.remove('open');
    document.getElementById('addrError').style.display = 'none';
    document.getElementById('newAddrLabel').value = '';
    document.getElementById('addrStreet').value   = '';
    const p = document.getElementById('addrProvince');
    const c = document.getElementById('addrCity');
    const b = document.getElementById('addrBarangay');
    p.selectedIndex = 0;
    c.innerHTML = '<option value="" disabled selected>Select City first</option>'; c.disabled = true;
    b.innerHTML = '<option value="" disabled selected>Select Barangay first</option>'; b.disabled = true;
}
async function addrFetchCities() {
    const p = document.getElementById('addrProvince');
    const c = document.getElementById('addrCity');
    const b = document.getElementById('addrBarangay');
    c.innerHTML = '<option value="" disabled selected>Loading…</option>'; c.disabled = true;
    b.innerHTML = '<option value="" disabled selected>Select Barangay first</option>'; b.disabled = true;
    const opt = p.options[p.selectedIndex];
    if (!opt.dataset.code) return;
    try {
        const data = await (await fetch(`https://psgc.gitlab.io/api/${opt.dataset.type}/${opt.dataset.code}/cities-municipalities/`)).json();
        data.sort((a,b) => a.name.localeCompare(b.name));
        c.innerHTML = '<option value="" disabled selected>Select City/Municipality</option>';
        data.forEach(x => { const o=document.createElement('option'); o.value=x.name; o.dataset.code=x.code; o.textContent=x.name; c.appendChild(o); });
        c.disabled = false;
    } catch { c.innerHTML = '<option value="" disabled selected>Error loading</option>'; }
}
async function addrFetchBarangays() {
    const c = document.getElementById('addrCity');
    const b = document.getElementById('addrBarangay');
    b.innerHTML = '<option value="" disabled selected>Loading…</option>'; b.disabled = true;
    const opt = c.options[c.selectedIndex];
    if (!opt.dataset.code) return;
    try {
        const data = await (await fetch(`https://psgc.gitlab.io/api/cities-municipalities/${opt.dataset.code}/barangays/`)).json();
        data.sort((a,b) => a.name.localeCompare(b.name));
        b.innerHTML = '<option value="" disabled selected>Select Barangay</option>';
        data.forEach(x => { const o=document.createElement('option'); o.value=x.name; o.textContent=x.name; b.appendChild(o); });
        b.disabled = false;
    } catch { b.innerHTML = '<option value="" disabled selected>Error loading</option>'; }
}
function showAddrError(msg) { const el=document.getElementById('addrError'); el.textContent=msg; el.style.display='block'; el.scrollIntoView({behavior:'smooth',block:'nearest'}); }
function saveNewAddr() {
    document.getElementById('addrError').style.display = 'none';
    const label=document.getElementById('newAddrLabel').value.trim()||'Home';
    const province=document.getElementById('addrProvince').value;
    const city=document.getElementById('addrCity').value;
    const barangay=document.getElementById('addrBarangay').value;
    const street=document.getElementById('addrStreet').value.trim();
    if (!province) return showAddrError('Please select a Province / Region.');
    if (!city)     return showAddrError('Please select a City / Municipality.');
    if (!barangay) return showAddrError('Please select a Barangay.');
    if (street.length < 3) return showAddrError('Please enter a Street Address.');
    const btn = document.querySelector('.addr-save-btn');
    btn.disabled = true; btn.textContent = 'Saving…';
    const fd = new FormData();
    fd.append('action','add'); fd.append('label',label);
    fd.append('province',province); fd.append('city',city);
    fd.append('barangay',barangay); fd.append('street_address',street);
    fetch('manage_addresses.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(data=>{
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Address';
            if (!data.ok) return showAddrError(data.msg);
            closeAddAddrModal(); location.reload();
        }).catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Address'; showAddrError('Network error.'); });
}
function setDefault(id) {
    const fd=new FormData(); fd.append('action','set_default'); fd.append('addr_id',id);
    fetch('manage_addresses.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(!d.ok) return alert(d.msg); location.reload(); });
}
function deleteAddr(id) {
    if (!confirm('Remove this address?')) return;
    const fd=new FormData(); fd.append('action','delete'); fd.append('addr_id',id);
    fetch('manage_addresses.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(!d.ok) return alert(d.msg); location.reload(); });
}
</script>
</body>
</html>