<?php
include "db_connect.php";
require_once __DIR__ . '/../includes/mailer.php';

$register_error = "";
$show_success_modal = false;
// Carry the redirect param through the entire registration flow
$reg_redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');

if(isset($_POST['register'])){
    $fullname =$_POST['reg_fullname'];
    $phone =$_POST['reg_phone'];
    $email =$_POST['reg_email'];
    $username =$_POST['reg_username'];
    $password =$_POST['reg_password'];

    // Combine the dropdowns and street input into a single address string
    $street = trim($_POST['reg_street']);$barangay = $_POST['reg_barangay'];$city = $_POST['reg_city'];$province = $_POST['reg_province'];$address = "$street,$barangay, $city,$province";

    // Backend check: Phone number format (Exactly 11 digits, starts with 09)
    if(!preg_match('/^09[0-9]{9}$/', $phone)) {$register_error = "Phone number must be exactly 11 digits and start with 09.";
    }
    // Backend check: Enforce @gmail.com only
    elseif(!preg_match('/@gmail\.com$/i', $email)) {$register_error = "Only @gmail.com email addresses are allowed.";
    } 
    // Backend check: Username length (more than 5 characters)
    elseif(strlen($username) <= 5) {$register_error = "Username must be more than 5 characters long.";
    } 
    // Backend check: Password length
    elseif(strlen($password) < 8) {$register_error = "Password must be at least 8 characters long.";
    }
    else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Check if username or email is already taken — prepared statement
        $check_stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ? OR username = ? LIMIT 1");
        $check_stmt->bind_param("ss", $email, $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();
            $register_error = ($row['username'] === $username)
                ? "Username already exists. Please choose another one."
                : "Email already exists. Please use another one or sign in.";
            $check_stmt->close();
        } else {
            $check_stmt->close();
            // Generate a secure, unique verification token
            $verification_code = bin2hex(random_bytes(16));

            // Insert user with is_verified = 0 — prepared statement
            $ins = $conn->prepare("INSERT INTO users (fullname, phone, address, email, username, password, verification_code, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            $ins->bind_param("sssssss", $fullname, $phone, $address, $email, $username, $hashed_password, $verification_code);

            if ($ins->execute()) {
                $ins->close();
                $new_user_id = $conn->insert_id;

                // Automatically create default saved address from registration fields
                if (!empty($street) || !empty($barangay) || !empty($city) || !empty($province)) {
                    $addr_ins = $conn->prepare(
                        "INSERT INTO user_addresses (user_id, label, address, province, city, barangay, street_address, is_default)
                         VALUES (?, 'Home', ?, ?, ?, ?, ?, 1)"
                    );
                    if ($addr_ins) {
                        $addr_ins->bind_param("isssss",
                            $new_user_id, $address, $province, $city, $barangay, $street
                        );
                        $addr_ins->execute();
                        $addr_ins->close();
                    }
                }

                $verify_link = rtrim(APP_BASE_URL, '/') . '/verify.php?token=' . urlencode($verification_code);

                $body = "Hi <b>" . htmlspecialchars($fullname) . "</b>,<br><br>"
                    . "Thank you for registering at GSB Airconditioning Services. "
                    . "Please click the link below to verify your email address:<br><br>"
                    . "<a href='" . htmlspecialchars($verify_link) . "'>Verify My Account</a><br><br>"
                    . "If you did not request this, please ignore this email.";

                $result = gsb_send_email($email, $fullname, 'Verify Your GSB Account', $body);

                if ($result === true) {
                    $show_success_modal = true;
                    $_POST = [];
                } else {
                    $register_error = "Registration saved, but verification email could not be sent. Please contact support.";
                    error_log("gsb_send_email error (register): " . $result);
                }
            } else {
                $ins->close();
                $register_error = "A database error occurred. Please try again.";
                error_log("Register INSERT error: " . $conn->error);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GSB Airconditioning Services | Register</title>
    <!-- Updated to v=1.4 for the back-link CSS -->
    <link rel="stylesheet" href="css/login.css?v=1.4">
    <link rel="icon" type="image/png" href="favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="auth-wrapper">
        <div class="auth-card">
            
            <div class="auth-illustration">
                <img src="favicon.png" alt="Registration Illustration">
            </div>

            <div class="auth-form-container">
                
                <!-- NEW: Back to Home Button -->
                <a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Home</a>

                <div class="form-header">
                    <h2>Create Account</h2>
                    <p>Fill out the details below to set up your profile.</p>
                </div>

                <?php if (!empty($register_error)): ?>
                    <div class="error-alert">
                        <?= htmlspecialchars($register_error) ?>
                    </div>
                <?php endif; ?>

                <form method="post" onsubmit="return validateForm()">
                    
                    <div class="form-group full-width">
                        <label>Full Name <span class="req">*</span></label>
                        <div class="input-icon-wrapper">
                            <i class="fa-regular fa-user input-icon"></i>
                            <input type="text" name="reg_fullname" value="<?= isset($_POST['reg_fullname']) ? htmlspecialchars($_POST['reg_fullname']) : '' ?>" placeholder="" required>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>Phone Number <span class="req">*</span></label>
                        <div class="input-icon-wrapper">
                            <i class="fa-solid fa-phone input-icon"></i>
                            <input type="tel" id="regPhone" name="reg_phone" value="<?= isset($_POST['reg_phone']) ? htmlspecialchars($_POST['reg_phone']) : '' ?>" placeholder="e.g. 09123456789" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
                        </div>
                    </div>

                    <!-- DYNAMIC ADDRESS DROPDOWNS -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Province / Region <span class="req">*</span></label>
                            <select name="reg_province" id="regProvince" class="address-select" onchange="fetchCities()" required>
                                <option value="" disabled selected>Select Province/Region</option>
                                <option value="Metro Manila (NCR)" data-code="130000000" data-type="region">Metro Manila (NCR)</option>
                                <option value="Batangas" data-code="041000000" data-type="province">Batangas</option>
                                <option value="Cavite" data-code="042100000" data-type="province">Cavite</option>
                                <option value="Laguna" data-code="043400000" data-type="province">Laguna</option>
                                <option value="Quezon" data-code="045600000" data-type="province">Quezon</option>
                                <option value="Rizal" data-code="045800000" data-type="province">Rizal</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>City / Municipality <span class="req">*</span></label>
                            <select name="reg_city" id="regCity" class="address-select" onchange="fetchBarangays()" required disabled>
                                <option value="" disabled selected>Select City first</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>Barangay <span class="req">*</span></label>
                        <select name="reg_barangay" id="regBarangay" class="address-select" required disabled>
                            <option value="" disabled selected>Select Barangay</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Street / House Number <span class="req">*</span></label>
                        <div class="input-icon-wrapper">
                            <i class="fa-solid fa-location-dot input-icon"></i>
                            <input type="text" name="reg_street" value="<?= isset($_POST['reg_street']) ? htmlspecialchars($_POST['reg_street']) : '' ?>" placeholder="House No., Street name, Subdivision" required>
                        </div>
                    </div>
                    <!-- END ADDRESS -->

                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address <span class="req">*</span></label>
                            <div class="input-icon-wrapper">
                                <i class="fa-regular fa-envelope input-icon"></i>
                                <input type="email" id="regEmail" name="reg_email" value="<?= isset($_POST['reg_email']) ? htmlspecialchars($_POST['reg_email']) : '' ?>" placeholder="example@gmail.com" pattern="^[a-zA-Z0-9._\%+\-]+@gmail\.com$" title="Only @gmail.com addresses are allowed" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Username <span class="req">*</span></label>
                            <div class="input-icon-wrapper">
                                <i class="fa-regular fa-id-badge input-icon"></i>
                                <input type="text" id="regUsername" name="reg_username" value="<?= isset($_POST['reg_username']) ? htmlspecialchars($_POST['reg_username']) : '' ?>" placeholder="Choose a username" minlength="6" required>
                            </div>
                            <p style="font-size: 11px; color: #718096; margin-top: 4px;">Must be more than 5 characters.</p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Password <span class="req">*</span></label>
                            <div class="input-icon-wrapper">
                                <i class="fa-solid fa-lock input-icon"></i>
                                <input type="password" id="regPass" name="reg_password" onkeyup="checkPasswordStrength(); checkPasswordMatch();" required>
                                <i class="fa-regular fa-eye eye-btn" onclick="toggleRegPass('regPass', this)"></i>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Confirm Password <span class="req">*</span></label>
                            <div class="input-icon-wrapper">
                                <i class="fa-solid fa-shield-halved input-icon"></i>
                                <input type="password" id="regConfirmPass" onkeyup="checkPasswordMatch()" required>
                                <i class="fa-regular fa-eye eye-btn" onclick="toggleRegPass('regConfirmPass', this)"></i>
                            </div>
                            <p id="password-match-msg" style="font-size: 12px; margin-top: 6px; font-weight: 500;"></p>
                        </div>
                    </div>

                    <div class="password-rules">
                        <p id="rule-length">❌ At least 8 characters</p>
                        <p id="rule-upper">❌ At least 1 uppercase letter (A-Z)</p>
                        <p id="rule-lower">❌ At least 1 lowercase letter (a-z)</p>
                        <p id="rule-number">❌ At least 1 number (0-9)</p>
                        <p id="rule-special">❌ At least 1 special character (!@#$%)</p>
                    </div>

                    <div style="background-color: #e8f4fd; color: #0056b3; padding: 12px; border-radius: 8px; font-size: 12px; margin-bottom: 20px; text-align: center; border: 1px solid #b8daff;">
                        <i class="fa-solid fa-envelope-circle-check"></i> <b>Account Verification Required:</b><br> 
                        After submitting, a verification link will be sent to your email. You cannot log in until you click that link.
                    </div>

                    <button type="submit" name="register" class="auth-btn">
                        Create Account & Send Email <i class="fa-solid fa-paper-plane"></i>
                    </button>
                    <?php if (!empty($reg_redirect)): ?>
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($reg_redirect) ?>">
                    <?php endif; ?>
                </form>

                <div class="auth-footer">
                    Already have an account? <a href="login.php<?= !empty($reg_redirect) ? '?redirect=' . urlencode($reg_redirect) : '' ?>">Sign In</a>
                </div>
            </div>
        </div>
    </div>

    <!-- SUCCESS MODAL (Only renders if $show_success_modal is true) -->
    <?php if ($show_success_modal): ?>
    <div class="custom-modal-overlay">
        <div class="custom-modal">
            <div class="modal-icon">
                <i class="fa-regular fa-envelope"></i>
            </div>
            <h3>Check Your Inbox!</h3>
            <p>Registration successful! We've sent a verification link to your email. Please verify your account to log in.</p>
            <button onclick="window.location.href='login.php<?= !empty($reg_redirect) ? '?redirect=' . urlencode($reg_redirect) : '' ?>'" class="modal-btn">Got it, thanks!</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- JAVASCRIPT ERROR MODAL (Hidden by default) -->
    <div id="errorModalOverlay" class="custom-modal-overlay">
        <div class="custom-modal error-modal">
            <div class="modal-icon">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <h3>Oops!</h3>
            <p id="errorModalMessage">Error message goes here.</p>
            <button type="button" onclick="closeErrorModal()" class="modal-btn">Try Again</button>
        </div>
    </div>

    <script>
        // --- MODAL CONTROLS --- //
        function showErrorModal(message) {
            document.getElementById('errorModalMessage').innerText = message;
            document.getElementById('errorModalOverlay').style.display = 'flex';
        }

        function closeErrorModal() {
            document.getElementById('errorModalOverlay').style.display = 'none';
        }

        // --- STICKY DROPDOWN RESTORATION LOGIC --- //
        const savedProvince = "<?= isset($_POST['reg_province']) ? htmlspecialchars($_POST['reg_province']) : '' ?>";
        const savedCity = "<?= isset($_POST['reg_city']) ? htmlspecialchars($_POST['reg_city']) : '' ?>";
        const savedBarangay = "<?= isset($_POST['reg_barangay']) ? htmlspecialchars($_POST['reg_barangay']) : '' ?>";

        document.addEventListener("DOMContentLoaded", async () => {
            if (savedProvince) {
                const provSelect = document.getElementById('regProvince');
                provSelect.value = savedProvince;
                
                await fetchCities();
                
                if (savedCity) {
                    const citySelect = document.getElementById('regCity');
                    citySelect.value = savedCity;
                    
                    await fetchBarangays();
                    
                    if (savedBarangay) {
                        const brgySelect = document.getElementById('regBarangay');
                        brgySelect.value = savedBarangay;
                    }
                }
            }
        });

        // --- PSGC ADDRESS API LOGIC --- //
        async function fetchCities() {
            const provSelect = document.getElementById('regProvince');
            const citySelect = document.getElementById('regCity');
            const brgySelect = document.getElementById('regBarangay');

            citySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
            citySelect.disabled = true;
            brgySelect.innerHTML = '<option value="" disabled selected>Select Barangay</option>';
            brgySelect.disabled = true;

            const selectedOption = provSelect.options[provSelect.selectedIndex];
            if (!selectedOption) return;

            const code = selectedOption.getAttribute('data-code');
            const type = selectedOption.getAttribute('data-type');

            if (!code) return;

            const url = type === 'region' 
                ? `https://psgc.gitlab.io/api/regions/${code}/cities-municipalities/`
                : `https://psgc.gitlab.io/api/provinces/${code}/cities-municipalities/`;

            try {
                const response = await fetch(url);
                const data = await response.json();
                
                data.sort((a, b) => a.name.localeCompare(b.name));

                citySelect.innerHTML = '<option value="" disabled selected>Select City/Municipality</option>';
                data.forEach(city => {
                    const option = document.createElement('option');
                    option.value = city.name;
                    option.setAttribute('data-code', city.code);
                    option.textContent = city.name;
                    citySelect.appendChild(option);
                });
                citySelect.disabled = false;
            } catch (error) {
                console.error("Error fetching cities:", error);
                citySelect.innerHTML = '<option value="" disabled selected>Error loading cities</option>';
            }
        }

        async function fetchBarangays() {
            const citySelect = document.getElementById('regCity');
            const brgySelect = document.getElementById('regBarangay');

            brgySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
            brgySelect.disabled = true;

            const selectedOption = citySelect.options[citySelect.selectedIndex];
            if (!selectedOption) return;

            const code = selectedOption.getAttribute('data-code');

            if (!code) return;

            const url = `https://psgc.gitlab.io/api/cities-municipalities/${code}/barangays/`;

            try {
                const response = await fetch(url);
                const data = await response.json();
                
                data.sort((a, b) => a.name.localeCompare(b.name));

                brgySelect.innerHTML = '<option value="" disabled selected>Select Barangay</option>';
                data.forEach(brgy => {
                    const option = document.createElement('option');
                    option.value = brgy.name;
                    option.textContent = brgy.name;
                    brgySelect.appendChild(option);
                });
                brgySelect.disabled = false;
            } catch (error) {
                console.error("Error fetching barangays:", error);
                brgySelect.innerHTML = '<option value="" disabled selected>Error loading barangays</option>';
            }
        }

        // --- PASSWORD & VALIDATION LOGIC --- //
        function toggleRegPass(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function checkPasswordStrength() {
            const pass = document.getElementById("regPass").value;

            const rules = {
                length: pass.length >= 8,
                upper: /[A-Z]/.test(pass),
                lower: /[a-z]/.test(pass),
                number: /[0-9]/.test(pass),
                special: /[!@#$%^&*(),.?":{}|<>]/.test(pass)
            };

            const updateRule = (id, isValid, text) => {
                const element = document.getElementById(id);
                element.innerHTML = isValid ? `✔️ ${text}` : `❌ ${text}`;
                element.className = isValid ? 'valid-rule' : 'invalid-rule';
            };

            updateRule("rule-length", rules.length, "At least 8 characters");
            updateRule("rule-upper", rules.upper, "At least 1 uppercase letter (A-Z)");
            updateRule("rule-lower", rules.lower, "At least 1 lowercase letter (a-z)");
            updateRule("rule-number", rules.number, "At least 1 number (0-9)");
            updateRule("rule-special", rules.special, "At least 1 special character (!@#$%)");
        }

        function checkPasswordMatch() {
            const pass = document.getElementById("regPass").value;
            const confirmPass = document.getElementById("regConfirmPass").value;
            const matchMsg = document.getElementById("password-match-msg");

            if (confirmPass.length === 0) {
                matchMsg.innerHTML = ""; 
                return;
            }

            if (pass === confirmPass) {
                matchMsg.innerHTML = "✔️ Passwords match";
                matchMsg.className = "valid-rule";
            } else if (pass.startsWith(confirmPass)) {
                matchMsg.innerHTML = "";
                matchMsg.className = "";
            } else {
                matchMsg.innerHTML = "❌ Passwords do not match";
                matchMsg.className = "invalid-rule";
            }
        }

        function validateForm() {
            const phone = document.getElementById("regPhone").value;
            const email = document.getElementById("regEmail").value;
            const username = document.getElementById("regUsername").value;
            const pass = document.getElementById("regPass").value;
            const confirmPass = document.getElementById("regConfirmPass").value;

            if (!/^09\d{9}$/.test(phone)) {
                showErrorModal("Phone number must be exactly 11 digits and start with 09.");
                return false;
            }

            if (!email.toLowerCase().endsWith("@gmail.com")) {
                showErrorModal("Only @gmail.com email addresses are allowed.");
                return false;
            }

            if (username.length <= 5) {
                showErrorModal("Username must be more than 5 characters.");
                return false;
            }

            const isValid = 
                pass.length >= 8 && 
                /[A-Z]/.test(pass) && 
                /[a-z]/.test(pass) && 
                /[0-9]/.test(pass) && 
                /[!@#$%^&*(),.?":{}|<>]/.test(pass);

            if (!isValid) {
                showErrorModal("Please meet all password requirements before registering.");
                return false;
            }

            if (pass !== confirmPass) {
                showErrorModal("Passwords do not match!");
                return false;
            }

            return true;
        }
    </script>
</body>
</html>