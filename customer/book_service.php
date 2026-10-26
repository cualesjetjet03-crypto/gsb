<?php
session_start();
include "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    // Preserve the service they wanted so login can redirect back
    $redirect = 'book_service.php';
    if (!empty($_GET['service'])) {
        $redirect .= '?service=' . urlencode($_GET['service']);
    }
    header("Location: login.php?redirect=" . urlencode($redirect));
    exit();
}

$user_id = intval($_SESSION['user_id']);
$user = null;

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result && $user_result->num_rows > 0) {
    $user = $user_result->fetch_assoc();
}

$fullname = $user['fullname'] ?? "";
$email = $user['email'] ?? "";
$phone = $user['phone'] ?? "";
$address = $user['address'] ?? "";

$name_parts = explode(" ", trim($fullname), 2);
$first_name = $name_parts[0] ?? "";
$last_name = $name_parts[1] ?? "";

function clean($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Fetch fully booked dates — counts ONLY active service_schedule entries (not product inquiries)
// The 3-slot cap applies to service visits only. Product delivery dates from inquiries
// are NOT counted toward the service capacity limit.
$fullDates = [];

$fullDatesQuery = "
    SELECT service_date AS date, COUNT(*) as total
    FROM services_schedule
    WHERE (inquiry_id IS NULL OR inquiry_id = 0)
    AND status NOT IN ('Completed', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
    GROUP BY service_date
    HAVING total >= 3
";

$fullDatesResult = $conn->query($fullDatesQuery);
if ($fullDatesResult && $fullDatesResult->num_rows > 0) {
    while ($row = $fullDatesResult->fetch_assoc()) {
        $fullDates[] = $row['date'];
    }
}

// Fetch admin unavailable slots
$unavailableSlots = [];
$availabilityQuery = "SELECT * FROM availability_schedule WHERE LOWER(status) = 'unavailable' AND (notes IS NULL OR notes NOT LIKE 'Booked Service:%') ORDER BY date, start_time";
$availabilityResult = $conn->query($availabilityQuery);
if ($availabilityResult && $availabilityResult->num_rows > 0) {
    while ($row = $availabilityResult->fetch_assoc()) {
        $startLabel = date("h:i A", strtotime($row['start_time']));
        $endLabel = date("h:i A", strtotime($row['end_time']));
        $notes = trim($row['notes'] ?? '');
        $title = "Unavailable | " . $startLabel . " - " . $endLabel . (!empty($notes) ? " | " . $notes : "");

        $unavailableSlots[] = [
            "title" => $title,
            "start" => $row['date'] . "T" . $row['start_time'],
            "end" => $row['date'] . "T" . $row['end_time'],
            "dateOnly" => $row['date'],
            "timeLabel" => $startLabel . " - " . $endLabel,
            "notes" => $notes,
            "backgroundColor" => "#dc2626",
            "borderColor" => "#dc2626",
            "textColor" => "#ffffff",
            "className" => "unavailable-event"
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Service - GSB Airconditioning</title>

    <link rel="stylesheet" href="css/GSB-homepage.css"> 
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/wizard.css"> 
    <link rel="stylesheet" href="css/footer.css"> 
    <link rel="icon" type="image/png" href="favicon.png">

    <!-- FullCalendar CSS & JS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.22.2/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.js"></script>
</head>

<body>
    <?php include 'navbar.php'; ?>

    <div class="wizard-wrapper">
        <!-- ALERTS BLOCK -->
        <?php if (isset($_GET['error']) && $_GET['error'] === 'unavailable') { ?>
            <div class="alert-error">The selected date is unavailable. Please choose another date.</div>
        <?php } ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'failed') { ?>
            <div class="alert-error">Something went wrong while saving your request. Please try again.</div>
        <?php } ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'full') { ?>
            <div class="alert-error">This date is already fully booked. Please choose another date.</div>
        <?php } ?>
        <!-- END ALERTS -->

        <?php if (isset($_GET['success'])) { ?>
            <!-- ==========================================
                 SUCCESS SCREEN (Hides the form completely)
                 ========================================== -->
            <div class="success-screen" style="text-align: center; padding: 60px 20px; background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; max-width: 600px; margin: 40px auto; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                <h2 style="color: #1e3a8a; font-size: 28px; margin-bottom: 15px;">Booking Submitted!</h2>
                <p style="color: #475569; font-size: 16px; margin-bottom: 35px; line-height: 1.6;">
                    Your service schedule request has been successfully submitted. Our team will review your booking and contact you shortly to confirm the appointment.
                </p>
                <div style="display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">
                    <a href="services.php" style="background-color: #1d4ed8; color: white; padding: 12px 28px; border-radius: 50px; text-decoration: none; font-weight: 600; font-size: 15px; transition: 0.3s;">Book Another Service</a>
                    <a href="index.php" style="background-color: #f1f5f9; color: #334155; padding: 12px 28px; border-radius: 50px; text-decoration: none; font-weight: 600; font-size: 15px; border: 1px solid #cbd5e1; transition: 0.3s;">Go to Homepage</a>
                </div>
            </div>
        <?php } else { ?>
            <!-- ==========================================
                 BOOKING FORM
                 ========================================== -->
            <form action="process_schedule.php" method="POST" id="bookingWizardForm">
                
                <!-- Service type pre-filled from services.php ?service= param -->
                <input type="hidden" id="service_type" name="service_type" 
                       value="<?php echo clean($_GET['service'] ?? ''); ?>" required>

                <!-- STEP 1: Contact Details (was Step 2) -->
                <div class="wizard-step active" id="step1">
                    <div class="step-titles">
                        <span class="sub-icon"> Contact Details</span>
                        <h2>Can you provide more details about yourself?</h2>
                        <p>You may still update or edit your prefilled personal details.</p>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>First Name <span class="req">*</span></label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo clean($first_name); ?>" required>
                            <span id="fname_error" style="color: #dc2626; font-size: 13px; margin-top: 5px; display: none; font-weight: 500;">First name is required.</span>
                        </div>
                        <div class="form-group">
                            <label>Last Name <span class="req">*</span></label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo clean($last_name); ?>" required>
                            <span id="lname_error" style="color: #dc2626; font-size: 13px; margin-top: 5px; display: none; font-weight: 500;">Last name is required.</span>
                        </div>
                        <div class="form-group full">
                            <label>Email Address <span class="req">*</span></label>
                            <input type="email" id="email" name="email" value="<?php echo clean($email); ?>" pattern=".+@gmail\.com" title="Please provide a valid @gmail.com address" required>
                            <span id="email_error" style="color: #dc2626; font-size: 13px; margin-top: 5px; display: none; font-weight: 500;">Please enter a valid @gmail.com address.</span>
                        </div>
                        <div class="form-group full">
                            <label>Phone Number (11 digits, 09XXXXXXXXX) <span class="req">*</span></label>
                            <input type="tel" id="phone" name="phone" value="<?php echo clean($phone); ?>" pattern="09[0-9]{9}" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
                            <span id="phone_error" style="color: #dc2626; font-size: 13px; margin-top: 5px; display: none; font-weight: 500;">Please enter a valid 11-digit number starting with 09.</span>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: Unit Details -->
                <div class="wizard-step" id="step2">
                    <div class="step-titles">
                        <span class="sub-icon" id="dynamicServiceIcon1"> Service</span>
                        <h2>Add a unit you want to have serviced:</h2>
                    </div>
                    <div class="form-grid" style="max-width: 480px; margin: 0 auto;">
                        <div class="form-group full">
                            <label>Aircon Type <span class="req">*</span></label>
                            <select id="ac_type" name="ac_type" required>
                                <option value="" disabled selected>Select Aircon Type...</option>
                                <option value="Split Type">Split Type</option>
                                <option value="Window Type">Window Type</option>
                                <option value="Portable Type">Portable Type</option>
                            </select>
                        </div>

                        <!-- Dynamic Image Preview for Service Request & Aircon Type -->
                        <div class="form-group full">
                            <div class="selection-preview-container" id="unitSelectionPreview">
                                <!-- Selected Service Preview -->
                                <div class="preview-card active-preview" id="servicePreviewCard">
                                    <span class="preview-badge-label">Selected Service</span>
                                    <img id="previewServiceImg" class="zoomable-img" src="" alt="Selected Service" title="Click to zoom in" style="display: none;" onclick="openImageZoom(this.src, document.getElementById('previewServiceText').innerText)">
                                    <span class="preview-title" id="previewServiceText">None selected</span>
                                    <span class="zoom-hint" id="serviceZoomHint" style="display: none;" onclick="openImageZoom(document.getElementById('previewServiceImg').src, document.getElementById('previewServiceText').innerText)"></span>
                                </div>

                                <!-- Selected Aircon Type Preview -->
                                <div class="preview-card" id="acTypePreviewCard">
                                    <span class="preview-badge-label">Aircon Unit Type</span>
                                    <div id="acTypePlaceholder" class="preview-placeholder">
                                        Choose an Aircon Type above to view unit image
                                    </div>
                                    <img id="previewAcTypeImg" class="zoomable-img" src="" alt="Selected Aircon Type" title="Click to zoom in" style="display: none;" onclick="openImageZoom(this.src, document.getElementById('previewAcTypeText').innerText)">
                                    <span class="preview-title" id="previewAcTypeText" style="display: none;"></span>
                                    <span class="zoom-hint" id="acTypeZoomHint" style="display: none;" onclick="openImageZoom(document.getElementById('previewAcTypeImg').src, document.getElementById('previewAcTypeText').innerText)"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group full">
                            <label>Additional Notes or Concerns</label>
                            <textarea id="notes" name="notes" rows="4" maxlength="200" placeholder="E.g., Brand of AC, specific issues..."></textarea>
                            <div style="text-align: right; font-size: 12px; color: #64748b; margin-top: 4px;">
                                <span id="charCount">0</span>/200 characters
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 4: Location -->
                <div class="wizard-step" id="step3">
                    <div class="step-titles">
                        <span class="sub-icon" id="dynamicServiceIcon2">Service</span>
                        <h2>Where is your unit located?</h2>
                    </div>
                    
                    <input type="hidden" name="address" id="full_address">

                    <div class="form-grid" style="max-width: 800px; margin: 0 auto;">
                        <div class="form-group full">
                            <label>Street / Unit / House Number <span class="req">*</span></label>
                            <input type="text" id="street" placeholder="House no., Street name, Subdivision" value="<?php echo clean($address); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Province / Region <span class="req">*</span></label>
                            <select id="province" required>
                                <option value="" disabled selected>Select Province/Region</option>
                                <option value="NCR" data-name="Metro Manila (NCR)">Metro Manila (NCR)</option>
                                <option value="0410" data-name="Batangas">Batangas</option>
                                <option value="0421" data-name="Cavite">Cavite</option>
                                <option value="0434" data-name="Laguna">Laguna</option>
                                <option value="0456" data-name="Quezon">Quezon</option>
                                <option value="0458" data-name="Rizal">Rizal</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>City / Municipality <span class="req">*</span></label>
                            <select id="city" disabled required>
                                <option value="" disabled selected>Select City first</option>
                            </select>
                        </div>
                        <div class="form-group full">
                            <label>Barangay <span class="req">*</span></label>
                            <select id="barangay" disabled required>
                                <option value="" disabled selected>Select Barangay first</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- STEP 5: Schedule -->
                <div class="wizard-step" id="step4">
                    <div class="step-titles">
                        <span class="sub-icon" id="dynamicServiceIcon3">Service</span>
                        <h2>Select your preferred schedule for our visit</h2>
                        <p>Select a date below. A date marked in red is unavailable or full.</p>
                    </div>
                    
                    <input type="hidden" id="schedule_date" name="schedule_date" required>
                    <div class="selected-date-display" id="dateDisplay" style="display: none;">
                        Selected Date: <strong><span id="displayDateText"></span></strong>
                    </div>
                    
                    <div id="calendar-container" style="max-width: 700px; margin: 0 auto; background: #fff; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <div id="calendar"></div>
                    </div>
                </div>

                <!-- STEP 6: Booking Summary -->
                <div class="wizard-step" id="step5">
                    <div class="step-titles">
                        <h2>You're almost done!</h2>
                        <p>Kindly review your booking details before continuing.</p>
                    </div>

                    <div class="summary-container">
                        <div class="summary-section">
                            <div class="summary-header">
                                <span class="badge">1</span> <h3>Service</h3>
                                <!-- Service is pre-selected from the Services page — no edit needed -->
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Service</span>
                                <span class="summary-value summary-with-img">
                                    <img id="sum-service-img" class="summary-thumb zoomable-img" src="" alt="" title="Click to zoom in" style="display: none;" onclick="openImageZoom(this.src, document.getElementById('sum-service').innerText)">
                                    <span id="sum-service"></span>
                                </span>
                            </div>
                        </div>

                        <div class="summary-section">
                            <div class="summary-header">
                                <span class="badge">2</span> <h3>Contact Details</h3>
                                <span class="edit-link" onclick="goToStep(1)">Edit ✎</span>
                            </div>
                            <div class="summary-row"><span class="summary-label">Name</span> <span class="summary-value" id="sum-name"></span></div>
                            <div class="summary-row"><span class="summary-label">Phone</span> <span class="summary-value" id="sum-phone"></span></div>
                            <div class="summary-row"><span class="summary-label">Email</span> <span class="summary-value" id="sum-email"></span></div>
                        </div>

                        <div class="summary-section">
                            <div class="summary-header">
                                <span class="badge">3</span> <h3>Unit Details</h3>
                                <span class="edit-link" onclick="goToStep(2)">Edit ✎</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Aircon Type</span>
                                <span class="summary-value summary-with-img">
                                    <img id="sum-actype-img" class="summary-thumb zoomable-img" src="" alt="" title="Click to zoom in" style="display: none;" onclick="openImageZoom(this.src, document.getElementById('sum-actype').innerText)">
                                    <span id="sum-actype"></span>
                                </span>
                            </div>
                            <div class="summary-row"><span class="summary-label">Notes</span> <span class="summary-value" id="sum-notes"></span></div>
                        </div>

                        <div class="summary-section">
                            <div class="summary-header">
                                <span class="badge">4</span> <h3>Location & Schedule</h3>
                                <span class="edit-link" onclick="goToStep(3)">Edit ✎</span>
                            </div>
                            <div class="summary-row"><span class="summary-label">Address</span> <span class="summary-value" id="sum-address"></span></div>
                            <div class="summary-row"><span class="summary-label">Date</span> <span class="summary-value" id="sum-date"></span></div>
                        </div>
                    </div>
                </div>

                <!-- Global Action Buttons -->
                <div class="wizard-actions">
                    <button type="button" class="btn-back" id="btnBack" style="display: none;">&larr; Back</button>
                    <button type="button" class="btn-next" id="btnNext" disabled>Continue &rarr;</button>
                    <button type="submit" class="btn-submit" id="btnSubmit" style="display: none;">Submit Service Request</button>
                </div>
            </form>
        <?php } ?>
    </div>

    <!-- =========================================================
         CUSTOM ALERT MODAL
    ========================================================= -->
    <div id="customAlertModal" class="modal-overlay" style="display: none;">
        <div class="modal-box">
            <h3 id="modalTitle">Notice</h3>
            <p id="modalMessage"></p>
            <button type="button" class="btn-modal-ok" onclick="closeModal()">OK</button>
        </div>
    </div>

    <!-- =========================================================
         IMAGE ZOOM LIGHTBOX MODAL
    ========================================================= -->
    <div id="imageZoomModal" class="image-zoom-overlay" style="display: none;" onclick="closeImageZoom(event)">
        <div class="image-zoom-box" onclick="event.stopPropagation()">
            <button type="button" class="image-zoom-close" onclick="closeImageZoom()" title="Close">&times;</button>
            <img id="zoomedModalImg" src="" alt="Zoomed Preview">
            <div id="zoomedModalCaption" class="image-zoom-caption"></div>
        </div>
    </div>

    <!-- =========================================================
         FOOTER
    ========================================================= -->
    <?php include 'footer.php'; ?>

    <script>
        // Custom Modal Functions
        function showModal(title, message) {
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalMessage').innerText = message;
            document.getElementById('customAlertModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('customAlertModal').style.display = 'none';
        }

        // Image Zoom Lightbox Functions
        function openImageZoom(imgSrc, captionText) {
            if (!imgSrc) return;
            document.getElementById('zoomedModalImg').src = imgSrc;
            document.getElementById('zoomedModalCaption').innerText = captionText || '';
            document.getElementById('imageZoomModal').style.display = 'flex';
        }

        function closeImageZoom() {
            document.getElementById('imageZoomModal').style.display = 'none';
        }

        // Close Image Zoom on Escape key press
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageZoom();
            }
        });

        // Only run form scripts if the form exists (prevents JS errors on the success page)
        if (document.getElementById('bookingWizardForm')) {
            /* =======================================================
               IMAGE MAPS FOR SERVICES & AIRCON TYPES
               ======================================================= */
            const serviceImages = {
                "AC Installation": "./GSB homepage Image/services/AC Installation.png",
                "AC Cleaning": "./GSB homepage Image/services/AC Cleaning.png",
                "AC Repair": "./GSB homepage Image/services/AC Repair.png",
                "AC Check Up": "./GSB homepage Image/services/AC Check Up.png"
            };

            const acTypeImages = {
                "Split Type": "./GSB homepage Image/services/Split Type.png",
                "Window Type": "./GSB homepage Image/services/Window Type.png",
                "Portable Type": "./GSB homepage Image/services/Portable Type.png"
            };

            /* =======================================================
               WIZARD STATE MANAGEMENT
               ======================================================= */
            let currentStep = 1;
            const totalSteps = 5;

            function validateStep(step) {
                if (step === 1) {
                    // Contact Details
                    const fname = document.getElementById('first_name').value;
                    const lname = document.getElementById('last_name').value;
                    const email = document.getElementById('email').value;
                    const phone = document.getElementById('phone').value;
                    if (!fname || !lname || !email || !phone) return false;
                    if (!/^09\d{9}$/.test(phone)) return false;
                    if (!email.toLowerCase().endsWith('@gmail.com')) return false;
                    return true;
                }
                if (step === 2) {
                    return document.getElementById('ac_type').value !== "";
                }
                if (step === 3) {
                    const street = document.getElementById('street').value;
                    const prov = document.getElementById('province').value;
                    const city = document.getElementById('city').value;
                    const brgy = document.getElementById('barangay').value;
                    return (street && prov && city && brgy);
                }
                if (step === 4) {
                    return document.getElementById('schedule_date').value !== "";
                }
                return true;
            }

            function checkNextButton() {
                document.getElementById('btnNext').disabled = !validateStep(currentStep);
            }

            document.querySelectorAll('input, select, textarea').forEach(el => {
                el.addEventListener('input', checkNextButton);
                el.addEventListener('change', checkNextButton);
            });

            /* =======================================================
               FIELD ERROR MESSAGE LOGIC
               ======================================================= */
            const fnameInput = document.getElementById('first_name');
            const fnameError = document.getElementById('fname_error');
            fnameInput.addEventListener('blur', function() { fnameError.style.display = this.value.trim() === '' ? 'block' : 'none'; });
            fnameInput.addEventListener('input', function() { if (this.value.trim() !== '') fnameError.style.display = 'none'; });

            const lnameInput = document.getElementById('last_name');
            const lnameError = document.getElementById('lname_error');
            lnameInput.addEventListener('blur', function() { lnameError.style.display = this.value.trim() === '' ? 'block' : 'none'; });
            lnameInput.addEventListener('input', function() { if (this.value.trim() !== '') lnameError.style.display = 'none'; });

            const emailInput = document.getElementById('email');
            const emailError = document.getElementById('email_error');
            emailInput.addEventListener('blur', function() {
                const val = this.value.toLowerCase().trim();
                emailError.style.display = (val === '' || !val.endsWith('@gmail.com')) ? 'block' : 'none';
            });
            emailInput.addEventListener('input', function() {
                if (this.value.toLowerCase().trim().endsWith('@gmail.com')) emailError.style.display = 'none';
            });

            const phoneInput = document.getElementById('phone');
            const phoneError = document.getElementById('phone_error');
            phoneInput.addEventListener('blur', function() {
                const val = this.value.trim();
                phoneError.style.display = (val === '' || !/^09\d{9}$/.test(val)) ? 'block' : 'none';
            });
            phoneInput.addEventListener('input', function() {
                if (/^09\d{9}$/.test(this.value.trim())) phoneError.style.display = 'none';
            });

            /* =======================================================
               CHARACTER COUNTER FOR NOTES
               ======================================================= */
            const notesInput = document.getElementById('notes');
            const charCount = document.getElementById('charCount');
            if (notesInput && charCount) {
                notesInput.addEventListener('input', function() {
                    charCount.textContent = this.value.length;
                });
            }

            /* =======================================================
               SUMMARY & NAVIGATION
               ======================================================= */
            function populateSummary() {
                const selectedService = document.getElementById('service_type').value;
                const selectedAcType = document.getElementById('ac_type').value;

                document.getElementById('sum-service').innerText = selectedService;
                document.getElementById('sum-name').innerText = document.getElementById('first_name').value + " " + document.getElementById('last_name').value;
                document.getElementById('sum-phone').innerText = document.getElementById('phone').value;
                document.getElementById('sum-email').innerText = document.getElementById('email').value;
                document.getElementById('sum-actype').innerText = selectedAcType;
                document.getElementById('sum-notes').innerText = document.getElementById('notes').value || "N/A";

                // Update Service Image in Summary
                const sumServiceImg = document.getElementById('sum-service-img');
                if (serviceImages[selectedService]) {
                    sumServiceImg.src = serviceImages[selectedService];
                    sumServiceImg.alt = selectedService;
                    sumServiceImg.style.display = 'inline-block';
                } else {
                    sumServiceImg.style.display = 'none';
                }

                // Update Aircon Type Image in Summary
                const sumAcTypeImg = document.getElementById('sum-actype-img');
                if (acTypeImages[selectedAcType]) {
                    sumAcTypeImg.src = acTypeImages[selectedAcType];
                    sumAcTypeImg.alt = selectedAcType;
                    sumAcTypeImg.style.display = 'inline-block';
                } else {
                    sumAcTypeImg.style.display = 'none';
                }
                
                const provSelect = document.getElementById('province');
                const citySelect = document.getElementById('city');
                const brgySelect = document.getElementById('barangay');
                
                const prov = provSelect.options[provSelect.selectedIndex]?.dataset.name || '';
                const city = citySelect.options[citySelect.selectedIndex]?.dataset.name || '';
                const brgy = brgySelect.options[brgySelect.selectedIndex]?.dataset.name || '';
                
                const fullAddress = `${document.getElementById('street').value}, ${brgy}, ${city}, ${prov}`;
                
                document.getElementById('full_address').value = fullAddress;
                document.getElementById('sum-address').innerText = fullAddress;
                document.getElementById('sum-date').innerText = document.getElementById('schedule_date').value;
            }

            function goToStep(step) {
                document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
                document.getElementById('step' + step).classList.add('active');
                
                currentStep = step;
                
                document.getElementById('btnBack').style.display = (step === 1) ? 'none' : 'inline-block';
                
                if (step === totalSteps) {
                    document.getElementById('btnNext').style.display = 'none';
                    document.getElementById('btnSubmit').style.display = 'inline-block';
                    populateSummary();
                } else {
                    document.getElementById('btnNext').style.display = 'inline-block';
                    document.getElementById('btnSubmit').style.display = 'none';
                    checkNextButton();
                }

                if (step === 5) {
                    setTimeout(() => { $('#calendar').fullCalendar('render'); }, 100);
                }
                
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            document.getElementById('btnNext').addEventListener('click', () => {
                if (validateStep(currentStep) && currentStep < totalSteps) goToStep(currentStep + 1);
            });

            document.getElementById('btnBack').addEventListener('click', () => {
                if (currentStep > 1) goToStep(currentStep - 1);
            });

            /* =======================================================
               SERVICE PRE-SELECTION FROM URL PARAM
               (Step 1 card selection removed — service comes from services.php)
               ======================================================= */
            (function initServiceFromParam() {
                const selectedService = document.getElementById('service_type').value;
                if (!selectedService) return;

                document.getElementById('dynamicServiceIcon1').innerText = selectedService;
                document.getElementById('dynamicServiceIcon2').innerText = selectedService;
                document.getElementById('dynamicServiceIcon3').innerText = selectedService;

                // Pre-fill Step 2 (unit details) service preview card
                const previewServiceImg  = document.getElementById('previewServiceImg');
                const previewServiceText = document.getElementById('previewServiceText');
                const serviceZoomHint    = document.getElementById('serviceZoomHint');
                if (serviceImages[selectedService]) {
                    previewServiceImg.src   = serviceImages[selectedService];
                    previewServiceImg.alt   = selectedService;
                    previewServiceImg.style.display = 'block';
                    serviceZoomHint.style.display   = 'inline-block';
                }
                previewServiceText.innerText = selectedService;

                checkNextButton();
            })();

            // Update Step 3 Aircon Type Preview when dropdown changes
            const acTypeSelect = document.getElementById('ac_type');
            acTypeSelect.addEventListener('change', function() {
                const selectedAc = this.value;
                const acPreviewCard = document.getElementById('acTypePreviewCard');
                const acPlaceholder = document.getElementById('acTypePlaceholder');
                const previewAcImg = document.getElementById('previewAcTypeImg');
                const previewAcText = document.getElementById('previewAcTypeText');
                const acTypeZoomHint = document.getElementById('acTypeZoomHint');

                if (selectedAc && acTypeImages[selectedAc]) {
                    acPlaceholder.style.display = 'none';
                    previewAcImg.src = acTypeImages[selectedAc];
                    previewAcImg.alt = selectedAc;
                    previewAcImg.style.display = 'block';
                    previewAcText.innerText = selectedAc;
                    previewAcText.style.display = 'block';
                    acTypeZoomHint.style.display = 'inline-block';
                    acPreviewCard.classList.add('active-preview');
                } else {
                    acPlaceholder.style.display = 'block';
                    previewAcImg.style.display = 'none';
                    previewAcText.style.display = 'none';
                    acTypeZoomHint.style.display = 'none';
                    acPreviewCard.classList.remove('active-preview');
                }
            });

            /* =======================================================
               STEP 4: PSGC API LOGIC
               ======================================================= */
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const barangaySelect = document.getElementById('barangay');

            function populateCities(data) {
                citySelect.innerHTML = '<option value="" disabled selected>Select City / Municipality</option>';
                data.sort((a, b) => a.name.localeCompare(b.name));
                data.forEach(city => {
                    let option = document.createElement('option');
                    option.value = city.code; option.text = city.name; option.dataset.name = city.name;
                    citySelect.appendChild(option);
                });
                citySelect.disabled = false;
            }

            provinceSelect.addEventListener('change', function() {
                citySelect.innerHTML = '<option value="" disabled selected>Loading Cities...</option>';
                barangaySelect.innerHTML = '<option value="" disabled selected>Select Barangay first</option>';
                citySelect.disabled = true; barangaySelect.disabled = true;
                const val = this.value;
                if (val === 'NCR') {
                    fetch(`https://psgc.gitlab.io/api/regions/130000000/cities-municipalities/`)
                        .then(res => res.json()).then(data => populateCities(data));
                } else {
                    fetch(`https://psgc.gitlab.io/api/regions/040000000/cities-municipalities/`)
                        .then(res => res.json()).then(data => populateCities(data.filter(city => city.code.startsWith(val))));
                }
            });

            citySelect.addEventListener('change', function() {
                barangaySelect.innerHTML = '<option value="" disabled selected>Loading Barangays...</option>';
                barangaySelect.disabled = true;
                fetch(`https://psgc.gitlab.io/api/cities-municipalities/${this.value}/barangays/`)
                    .then(res => res.json())
                    .then(data => {
                        barangaySelect.innerHTML = '<option value="" disabled selected>Select Barangay</option>';
                        data.sort((a, b) => a.name.localeCompare(b.name));
                        data.forEach(brgy => {
                            let option = document.createElement('option');
                            option.value = brgy.code; option.text = brgy.name; option.dataset.name = brgy.name;
                            barangaySelect.appendChild(option);
                        });
                        barangaySelect.disabled = false;
                    });
            });

            /* =======================================================
               STEP 5: FULLCALENDAR INITIALIZATION (UPDATED)
               ======================================================= */
            $(document).ready(function() {
                var unavailableSlots = <?php echo json_encode($unavailableSlots, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
                var fullDates = <?php echo json_encode($fullDates); ?>;

                function getUnavailableInfo(dateString) {
                    for (var i = 0; i < unavailableSlots.length; i++) {
                        if (unavailableSlots[i].dateOnly === dateString) return unavailableSlots[i];
                    }
                    if (fullDates.indexOf(dateString) !== -1) {
                        return { dateOnly: dateString, timeLabel: "Maximum daily limit reached (3/3)" };
                    }
                    return false;
                }

                $("#calendar").fullCalendar({
                    header: { left: "prev,next today", center: "title", right: "" },
                    defaultView: "month",
                    selectable: true,
                    selectHelper: true,
                    
                    dayRender: function(date, cell) {
                        var todayString = moment().format('YYYY-MM-DD');
                        var minAllowedDate = moment().add(1, 'days').format('YYYY-MM-DD');
                        var dateString = date.format('YYYY-MM-DD');

                        if (dateString < todayString) {
                            cell.addClass('fc-past-day');
                        } else if (dateString < minAllowedDate) {
                            if (dateString !== todayString) {
                                cell.addClass('fc-buffer-day');
                            }
                            cell.attr('title', 'Please select a date at least 1 day from today.');
                        } else {
                            var unavailableInfo = getUnavailableInfo(dateString);
                            if (unavailableInfo) {
                                cell.addClass('fc-unavailable-day');
                                var tooltip = 'Unavailable';
                                if (unavailableInfo.timeLabel) tooltip += '\nDetails: ' + unavailableInfo.timeLabel;
                                if (unavailableInfo.notes) tooltip += '\nNotes: ' + unavailableInfo.notes;
                                cell.attr('title', tooltip);
                            }
                        }
                    },
                    dayClick: function(date, jsEvent, view) {
                        var minAllowedDate = moment().add(1, 'days').format('YYYY-MM-DD');
                        var selectedDate = date.format('YYYY-MM-DD');

                        if (selectedDate < minAllowedDate) {
                            showModal("Invalid Date", "Same-day booking is not allowed. Please select a date starting tomorrow.");
                            return false;
                        }

                        var unavailableInfo = getUnavailableInfo(selectedDate);
                        if (unavailableInfo) {
                            var msg = "This date is unavailable.\n";
                            if (unavailableInfo.timeLabel) msg += "Details: " + unavailableInfo.timeLabel + "\n";
                            if (unavailableInfo.notes) msg += "Notes: " + unavailableInfo.notes + "\n";
                            showModal("Date Unavailable", msg);
                            return false;
                        }

                        $('.fc-day').css('background-color', '');$(this).css('background-color', '#dbeafe'); 
                        
                        $("#schedule_date").val(selectedDate);
                        $("#displayDateText").text(selectedDate);
                        $("#dateDisplay").show();
                        
                        checkNextButton();
                    },
                    select: function(start) {
                        var minAllowedDate = moment().add(1, 'days').format('YYYY-MM-DD');
                        var selectedDate = start.format('YYYY-MM-DD');

                        if (selectedDate < minAllowedDate || getUnavailableInfo(selectedDate)) {
                            $('#calendar').fullCalendar('unselect');
                            
                            if (selectedDate < minAllowedDate) {
                                showModal("Invalid Date", "Same-day booking is not allowed. Please select a date starting tomorrow.");
                            } else {
                                var unavailableInfo = getUnavailableInfo(selectedDate);
                                var msg = "This date is unavailable.\n";
                                if (unavailableInfo && unavailableInfo.timeLabel) msg += "Details: " + unavailableInfo.timeLabel + "\n";
                                if (unavailableInfo && unavailableInfo.notes) msg += "Notes: " + unavailableInfo.notes + "\n";
                                showModal("Date Unavailable", msg);
                            }
                            return false;
                        }

                        $('.fc-day').css('background-color', '');$('.fc-day[data-date="' + selectedDate + '"]').css('background-color', '#dbeafe');

                        $("#schedule_date").val(selectedDate);
                        $("#displayDateText").text(selectedDate);
                        $("#dateDisplay").show();

                        checkNextButton();
                    }
                });
            });
        }
    </script>
</body>
</html>